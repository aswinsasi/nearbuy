<?php

declare(strict_types=1);

namespace App\Services\Fish;

use App\Enums\FishCatchStatus;
use App\Enums\FishQuantityRange;
use App\Models\FishCatch;
use App\Models\FishSeller;
use App\Models\FishType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing fish catches.
 *
 * Optimized for SPEED - fishermen post at 5AM.
 *
 * @srs-ref PM-005 to PM-010 Catch posting requirements
 * @srs-ref PM-020 to PM-024 Stock management
 */
class FishCatchService
{
    /**
     * Default expiry hours.
     * @srs-ref PM-024 Auto-expire after 6 hours
     */
    public const DEFAULT_EXPIRY_HOURS = 6;

    /**
     * Max catches per day per seller.
     */
    public const MAX_CATCHES_PER_DAY = 20;

    /**
     * Create a new catch posting.
     *
     * @srs-ref PM-005 to PM-010 Catch posting flow
     *
     * @param FishSeller $seller
     * @param array $data {
     *     @type int $fish_type_id Required
     *     @type string $quantity_range FishQuantityRange value (required)
     *     @type float $price_per_kg Required
     *     @type string|null $photo_url
     *     @type string|null $photo_media_id
     * }
     * @return FishCatch
     */
    public function createCatch(FishSeller $seller, array $data): FishCatch
    {
        $this->validateCatchData($data);

        // Check daily limit
        if ($this->hasReachedDailyLimit($seller)) {
            throw new \InvalidArgumentException('Daily limit reached (max ' . self::MAX_CATCHES_PER_DAY . ')');
        }

        // Validate fish type
        $fishType = FishType::find($data['fish_type_id']);
        if (!$fishType) {
            throw new \InvalidArgumentException('Invalid fish type');
        }

        // Parse quantity range
        $quantityRange = $data['quantity_range'] instanceof FishQuantityRange
            ? $data['quantity_range']
            : FishQuantityRange::from($data['quantity_range']);

        $catch = FishCatch::create([
            'seller_id' => $seller->id,
            'fish_type_id' => $fishType->id,
            'quantity_range' => $quantityRange,
            'price_per_kg' => (float) $data['price_per_kg'],
            'photo_url' => $data['photo_url'] ?? null,
            'photo_media_id' => $data['photo_media_id'] ?? null,
            'status' => FishCatchStatus::AVAILABLE,
            'customers_coming' => 0,
            'alerts_sent' => 0,
            'view_count' => 0,
            'arrived_at' => now(),
            'expires_at' => now()->addHours(self::DEFAULT_EXPIRY_HOURS),
        ]);

        Log::info('Fish catch created', [
            'catch_id' => $catch->id,
            'catch_number' => $catch->catch_number,
            'seller_id' => $seller->id,
            'fish_type' => $fishType->name_en,
            'price' => $catch->price_per_kg,
        ]);

        return $catch->load(['seller', 'fishType']);
    }

    /**
     * Update catch photo (for when uploaded after creation).
     */
    public function updatePhoto(FishCatch $catch, string $photoUrl, ?string $mediaId = null): FishCatch
    {
        $catch->update([
            'photo_url' => $photoUrl,
            'photo_media_id' => $mediaId,
        ]);

        return $catch->fresh();
    }

    /**
     * Update stock status.
     * @srs-ref PM-022 Update stock status
     */
    public function updateStatus(FishCatch $catch, FishCatchStatus $newStatus): FishCatch
    {
        $updateData = ['status' => $newStatus];

        if ($newStatus === FishCatchStatus::SOLD_OUT) {
            $updateData['sold_out_at'] = now();
            $catch->seller?->incrementSales();
        }

        $catch->update($updateData);

        Log::info('Catch status updated', [
            'catch_id' => $catch->id,
            'status' => $newStatus->value,
        ]);

        return $catch->fresh();
    }

    /**
     * Mark as low stock.
     */
    public function markLowStock(FishCatch $catch): FishCatch
    {
        return $this->updateStatus($catch, FishCatchStatus::LOW_STOCK);
    }

    /**
     * Mark as sold out.
     */
    public function markSoldOut(FishCatch $catch): FishCatch
    {
        return $this->updateStatus($catch, FishCatchStatus::SOLD_OUT);
    }

    /**
     * Expire stale catches.
     * @srs-ref PM-024 Auto-expire after 6 hours
     */
    public function expireStale(): int
    {
        $count = FishCatch::whereIn('status', [
            FishCatchStatus::AVAILABLE,
            FishCatchStatus::LOW_STOCK,
        ])
            ->where('expires_at', '<=', now())
            ->update(['status' => FishCatchStatus::EXPIRED]);

        if ($count > 0) {
            Log::info('Expired stale catches', ['count' => $count]);
        }

        return $count;
    }

    /**
     * Find by ID.
     */
    public function findById(int $id): ?FishCatch
    {
        return FishCatch::with(['seller', 'fishType'])->find($id);
    }

    /**
     * Find by catch number.
     */
    public function findByCatchNumber(string $number): ?FishCatch
    {
        return FishCatch::with(['seller', 'fishType'])
            ->where('catch_number', $number)
            ->first();
    }

    /**
     * Get seller's active catches.
     */
    public function getSellerActiveCatches(FishSeller $seller): Collection
    {
        return FishCatch::with('fishType')
            ->ofSeller($seller->id)
            ->active()
            ->freshestFirst()
            ->get();
    }

    /**
     * Get seller's today catches.
     */
    public function getSellerTodayCatches(FishSeller $seller): Collection
    {
        return FishCatch::with('fishType')
            ->ofSeller($seller->id)
            ->whereDate('created_at', today())
            ->freshestFirst()
            ->get();
    }

    /**
     * Browse catches near location.
     */
    public function browseNearby(
        float $lat,
        float $lng,
        float $radiusKm = 5,
        ?int $fishTypeId = null,
        int $limit = 20
    ): Collection {
        $query = FishCatch::with(['seller', 'fishType'])
            ->active()
            ->withDistanceFrom($lat, $lng)
            ->nearLocation($lat, $lng, $radiusKm);

        if ($fishTypeId) {
            $query->ofFishType($fishTypeId);
        }

        return $query->orderBy('distance_km')
            ->limit($limit)
            ->get();
    }

    /**
     * Record "I'm Coming" response.
     * @srs-ref PM-019 Live claim count
     */
    public function recordComingResponse(FishCatch $catch, int $userId): void
    {
        $catch->incrementComing();

        // Notify seller when threshold reached
        if ($catch->customers_coming === 10) {
            // PM-021: Notify seller when claim count exceeds 10
            Log::info('Catch has 10+ customers coming', [
                'catch_id' => $catch->id,
                'count' => $catch->customers_coming,
            ]);
        }
    }

    /**
     * Get catch stats.
     */
    public function getCatchStats(FishCatch $catch): array
    {
        return [
            'customers_coming' => $catch->customers_coming,
            'alerts_sent' => $catch->alerts_sent,
            'view_count' => $catch->view_count,
            'freshness' => $catch->freshness_display,
            'time_remaining' => $catch->time_remaining,
            'status' => $catch->status->value,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    protected function validateCatchData(array $data): void
    {
        $required = ['fish_type_id', 'quantity_range', 'price_per_kg'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing: {$field}");
            }
        }

        if (!is_numeric($data['price_per_kg']) || $data['price_per_kg'] <= 0) {
            throw new \InvalidArgumentException('Invalid price');
        }

        if ($data['price_per_kg'] > 10000) {
            throw new \InvalidArgumentException('Price too high');
        }

        // Validate quantity range
        $qtyRange = $data['quantity_range'];
        if (!$qtyRange instanceof FishQuantityRange && FishQuantityRange::tryFrom($qtyRange) === null) {
            throw new \InvalidArgumentException('Invalid quantity');
        }
    }

    protected function hasReachedDailyLimit(FishSeller $seller): bool
    {
        return FishCatch::ofSeller($seller->id)
            ->whereDate('created_at', today())
            ->count() >= self::MAX_CATCHES_PER_DAY;
    }

    /**
     * Find alternative catches of the same fish type nearby.
     * @srs-ref PM-023
     */
    public function findAlternatives(
        int $fishTypeId,
        float $latitude,
        float $longitude,
        int $excludeCatchId = null,
        int $limit = 3,
        float $radiusKm = 10
    ): Collection {
        return FishCatch::query()
            ->where('fish_type_id', $fishTypeId)
            ->whereIn('status', [FishCatchStatus::AVAILABLE, FishCatchStatus::LOW_STOCK])
            ->when($excludeCatchId, fn($q) => $q->where('id', '!=', $excludeCatchId))
            ->selectRaw('*, (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) AS distance', 
                [$latitude, $longitude, $latitude])
            ->having('distance', '<=', $radiusKm)
            ->orderBy('distance')
            ->limit($limit)
            ->get();
    }
}