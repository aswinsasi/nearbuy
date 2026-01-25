<?php

declare(strict_types=1);

namespace App\Services\Fish;

use App\Enums\FishCatchStatus;
use App\Enums\FishQuantityRange;
use App\Models\FishCatch;
use App\Models\FishSeller;
use App\Models\FishType;
use App\Models\FishCatchResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for managing fish catches.
 *
 * Handles:
 * - Creating new catch postings
 * - Updating stock status
 * - Expiring old catches
 * - Browse and search functionality
 *
 * @srs-ref Pacha Meen Module - Section 2.3.2 Catch Posting
 * @srs-ref Pacha Meen Module - Section 2.3.5 Stock Management
 */
class FishCatchService
{
    /**
     * Default catch expiry time in hours.
     */
    public const DEFAULT_EXPIRY_HOURS = 6;

    /**
     * Maximum catches per day per seller.
     */
    public const MAX_CATCHES_PER_DAY = 20;

    /**
     * Create a new fish catch posting.
     *
     * @param FishSeller $seller
     * @param array $data {
     *     @type int $fish_type_id Fish type ID (required)
     *     @type string $quantity_range FishQuantityRange value (required)
     *     @type float $price_per_kg Price per kg (required)
     *     @type float|null $quantity_kg Exact quantity if known
     *     @type string|null $photo_url Photo URL
     *     @type string|null $photo_media_id WhatsApp media ID
     *     @type float|null $latitude Custom location latitude
     *     @type float|null $longitude Custom location longitude
     *     @type string|null $location_name Location name
     *     @type string|null $notes Seller notes
     *     @type string|null $freshness_tag Freshness indicator
     *     @type int|null $expiry_hours Custom expiry hours
     * }
     * @return FishCatch
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createCatch(FishSeller $seller, array $data): FishCatch
    {
        $this->validateCatchData($data);

        // Check daily limit
        if ($this->hasReachedDailyLimit($seller)) {
            throw new \InvalidArgumentException(
                'Daily catch posting limit reached (' . self::MAX_CATCHES_PER_DAY . ')'
            );
        }

        // Verify fish type exists
        $fishType = FishType::find($data['fish_type_id']);
        if (!$fishType || !$fishType->is_active) {
            throw new \InvalidArgumentException('Invalid or inactive fish type');
        }

        // Parse quantity range
        $quantityRange = $data['quantity_range'] instanceof FishQuantityRange
            ? $data['quantity_range']
            : FishQuantityRange::from($data['quantity_range']);

        // Determine location (use seller's location as default)
        $latitude = $data['latitude'] ?? $seller->latitude;
        $longitude = $data['longitude'] ?? $seller->longitude;
        $locationName = $data['location_name'] ?? $seller->market_name;

        // Calculate expiry time
        $expiryHours = $data['expiry_hours'] ?? self::DEFAULT_EXPIRY_HOURS;
        $arrivedAt = now();
        $expiresAt = $arrivedAt->copy()->addHours($expiryHours);

        $catch = FishCatch::create([
            'fish_seller_id' => $seller->id,
            'fish_type_id' => $fishType->id,
            'catch_number' => $this->generateCatchNumber(),
            'quantity_range' => $quantityRange,
            'quantity_kg' => $data['quantity_kg'] ?? null,
            'price_per_kg' => (float) $data['price_per_kg'],
            'photo_url' => $data['photo_url'] ?? null,
            'photo_media_id' => $data['photo_media_id'] ?? null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'location_name' => $locationName,
            'status' => FishCatchStatus::AVAILABLE,
            'arrived_at' => $arrivedAt,
            'expires_at' => $expiresAt,
            'freshness_tag' => $data['freshness_tag'] ?? $this->determineFreshnessTag($arrivedAt),
            'notes' => $data['notes'] ?? null,
            'view_count' => 0,
            'alerts_sent' => 0,
            'coming_count' => 0,
            'message_count' => 0,
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
     * Update catch details.
     */
    public function updateCatch(FishCatch $catch, array $data): FishCatch
    {
        $updateData = [];

        if (isset($data['price_per_kg'])) {
            $updateData['price_per_kg'] = (float) $data['price_per_kg'];
        }

        if (isset($data['quantity_range'])) {
            $updateData['quantity_range'] = $data['quantity_range'] instanceof FishQuantityRange
                ? $data['quantity_range']
                : FishQuantityRange::from($data['quantity_range']);
        }

        if (isset($data['quantity_kg'])) {
            $updateData['quantity_kg'] = (float) $data['quantity_kg'];
        }

        if (isset($data['photo_url'])) {
            $updateData['photo_url'] = $data['photo_url'];
        }

        if (isset($data['photo_media_id'])) {
            $updateData['photo_media_id'] = $data['photo_media_id'];
        }

        if (isset($data['notes'])) {
            $updateData['notes'] = $data['notes'];
        }

        if (!empty($updateData)) {
            $catch->update($updateData);
            Log::info('Fish catch updated', ['catch_id' => $catch->id]);
        }

        return $catch->fresh(['seller', 'fishType']);
    }

    /**
     * Update catch status.
     */
    public function updateStatus(FishCatch $catch, FishCatchStatus $newStatus): FishCatch
    {
        if (!$catch->status->canTransitionTo($newStatus)) {
            throw new \InvalidArgumentException(
                "Cannot transition from {$catch->status->value} to {$newStatus->value}"
            );
        }

        $updateData = ['status' => $newStatus];

        if ($newStatus === FishCatchStatus::SOLD_OUT) {
            $updateData['sold_out_at'] = now();
            $catch->seller?->incrementSales();
        }

        $catch->update($updateData);

        Log::info('Fish catch status updated', [
            'catch_id' => $catch->id,
            'new_status' => $newStatus->value,
        ]);

        return $catch->fresh();
    }

    /**
     * Mark catch as low stock.
     */
    public function markLowStock(FishCatch $catch): FishCatch
    {
        return $this->updateStatus($catch, FishCatchStatus::LOW_STOCK);
    }

    /**
     * Mark catch as sold out.
     */
    public function markSoldOut(FishCatch $catch): FishCatch
    {
        return $this->updateStatus($catch, FishCatchStatus::SOLD_OUT);
    }

    /**
     * Mark catch as expired.
     */
    public function markExpired(FishCatch $catch): FishCatch
    {
        if ($catch->status === FishCatchStatus::EXPIRED) {
            return $catch;
        }

        $catch->update(['status' => FishCatchStatus::EXPIRED]);

        return $catch->fresh();
    }

    /**
     * Restore stock (mark available again).
     */
    public function restoreStock(FishCatch $catch): FishCatch
    {
        if (!$catch->status->canTransitionTo(FishCatchStatus::AVAILABLE)) {
            throw new \InvalidArgumentException('Cannot restore stock from current status');
        }

        $catch->update([
            'status' => FishCatchStatus::AVAILABLE,
            'sold_out_at' => null,
        ]);

        return $catch->fresh();
    }

    /**
     * Extend catch expiry time.
     */
    public function extendExpiry(FishCatch $catch, int $additionalHours = 2): FishCatch
    {
        $newExpiry = $catch->expires_at->addHours($additionalHours);

        // Don't extend more than 12 hours from original arrival
        $maxExpiry = $catch->arrived_at->copy()->addHours(12);
        if ($newExpiry->gt($maxExpiry)) {
            $newExpiry = $maxExpiry;
        }

        $catch->update(['expires_at' => $newExpiry]);

        Log::info('Fish catch expiry extended', [
            'catch_id' => $catch->id,
            'new_expiry' => $newExpiry->toIso8601String(),
        ]);

        return $catch->fresh();
    }

    /**
     * Expire all stale catches.
     *
     * @return int Number of catches expired
     */
    public function expireStale(): int
    {
        $count = FishCatch::where('status', FishCatchStatus::AVAILABLE)
            ->orWhere('status', FishCatchStatus::LOW_STOCK)
            ->where('expires_at', '<=', now())
            ->update(['status' => FishCatchStatus::EXPIRED]);

        if ($count > 0) {
            Log::info('Expired stale fish catches', ['count' => $count]);
        }

        return $count;
    }

    /**
     * Find catch by ID.
     */
    public function findById(int $catchId): ?FishCatch
    {
        return FishCatch::with(['seller', 'fishType'])->find($catchId);
    }

    /**
     * Find catch by catch number.
     */
    public function findByCatchNumber(string $catchNumber): ?FishCatch
    {
        return FishCatch::with(['seller', 'fishType'])
            ->where('catch_number', $catchNumber)
            ->first();
    }

    /**
     * Get active catches for a seller.
     */
    public function getSellerActiveCatches(FishSeller $seller): Collection
    {
        return $seller->catches()
            ->with('fishType')
            ->active()
            ->freshestFirst()
            ->get();
    }

    /**
     * Get seller's catches for today.
     */
    public function getSellerTodayCatches(FishSeller $seller): Collection
    {
        return $seller->catches()
            ->with('fishType')
            ->whereDate('created_at', today())
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Browse available catches near a location.
     */
    public function browseNearby(
        float $latitude,
        float $longitude,
        float $radiusKm = 5,
        ?int $fishTypeId = null,
        int $limit = 20
    ): Collection {
        $query = FishCatch::with(['seller', 'fishType'])
            ->active()
            ->withDistanceFrom($latitude, $longitude)
            ->nearLocation($latitude, $longitude, $radiusKm);

        if ($fishTypeId) {
            $query->ofFishType($fishTypeId);
        }

        return $query->nearestFirst()
            ->limit($limit)
            ->get();
    }

    /**
     * Browse catches by fish type.
     */
    public function browseByFishType(
        int $fishTypeId,
        float $latitude,
        float $longitude,
        float $radiusKm = 10
    ): Collection {
        return FishCatch::with(['seller', 'fishType'])
            ->active()
            ->ofFishType($fishTypeId)
            ->withDistanceFrom($latitude, $longitude)
            ->nearLocation($latitude, $longitude, $radiusKm)
            ->nearestFirst()
            ->get();
    }

    /**
     * Get catches for specific fish types near location.
     */
    public function getCatchesForFishTypes(
        array $fishTypeIds,
        float $latitude,
        float $longitude,
        float $radiusKm = 5
    ): Collection {
        return FishCatch::with(['seller', 'fishType'])
            ->active()
            ->ofFishTypes($fishTypeIds)
            ->withDistanceFrom($latitude, $longitude)
            ->nearLocation($latitude, $longitude, $radiusKm)
            ->nearestFirst()
            ->get();
    }

    /**
     * Search catches by keyword (fish name).
     */
    public function search(
        string $keyword,
        float $latitude,
        float $longitude,
        float $radiusKm = 10
    ): Collection {
        $fishTypes = FishType::search($keyword)->pluck('id')->toArray();

        if (empty($fishTypes)) {
            return collect();
        }

        return $this->getCatchesForFishTypes($fishTypes, $latitude, $longitude, $radiusKm);
    }

    /**
     * Record customer response (I'm Coming).
     */
    public function recordComingResponse(
        FishCatch $catch,
        int $userId,
        ?int $alertId = null,
        ?int $estimatedMins = null,
        ?float $latitude = null,
        ?float $longitude = null
    ): FishCatchResponse {
        // Check if already responded
        if (FishCatchResponse::hasUserResponded($catch->id, $userId, 'coming')) {
            throw new \InvalidArgumentException('Already marked as coming');
        }

        $response = FishCatchResponse::createComingResponse(
            $catch,
            \App\Models\User::find($userId),
            $alertId ? \App\Models\FishAlert::find($alertId) : null,
            $estimatedMins,
            $latitude,
            $longitude
        );

        Log::info('Customer coming response recorded', [
            'catch_id' => $catch->id,
            'user_id' => $userId,
        ]);

        return $response;
    }

    /**
     * Record customer message response.
     */
    public function recordMessageResponse(
        FishCatch $catch,
        int $userId,
        string $message,
        ?int $alertId = null
    ): FishCatchResponse {
        $response = FishCatchResponse::createMessageResponse(
            $catch,
            \App\Models\User::find($userId),
            $message,
            $alertId ? \App\Models\FishAlert::find($alertId) : null
        );

        return $response;
    }

    /**
     * Get catch statistics.
     */
    public function getCatchStats(FishCatch $catch): array
    {
        return [
            'view_count' => $catch->view_count,
            'alerts_sent' => $catch->alerts_sent,
            'coming_count' => $catch->coming_count,
            'message_count' => $catch->message_count,
            'response_rate' => $catch->alerts_sent > 0
                ? round(($catch->coming_count + $catch->message_count) / $catch->alerts_sent * 100, 1)
                : 0,
            'freshness' => $catch->freshness_display,
            'time_remaining' => $catch->time_remaining,
            'status' => $catch->status->value,
        ];
    }

    /**
     * Increment view count.
     */
    public function recordView(FishCatch $catch): void
    {
        $catch->increment('view_count');
    }

    /*
    |--------------------------------------------------------------------------
    | Validation & Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Validate catch data.
     */
    protected function validateCatchData(array $data): void
    {
        $required = ['fish_type_id', 'quantity_range', 'price_per_kg'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!is_numeric($data['price_per_kg']) || $data['price_per_kg'] <= 0) {
            throw new \InvalidArgumentException('Invalid price');
        }

        if ($data['price_per_kg'] > 10000) {
            throw new \InvalidArgumentException('Price exceeds maximum allowed');
        }

        // Validate quantity range
        $qtyRange = $data['quantity_range'];
        if (!$qtyRange instanceof FishQuantityRange && FishQuantityRange::tryFrom($qtyRange) === null) {
            throw new \InvalidArgumentException('Invalid quantity range');
        }
    }

    /**
     * Check if seller has reached daily limit.
     */
    protected function hasReachedDailyLimit(FishSeller $seller): bool
    {
        $todayCount = $seller->catches()
            ->whereDate('created_at', today())
            ->count();

        return $todayCount >= self::MAX_CATCHES_PER_DAY;
    }

    /**
     * Generate unique catch number.
     */
    protected function generateCatchNumber(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(4));
        $number = "FC-{$date}-{$random}";

        while (FishCatch::where('catch_number', $number)->exists()) {
            $random = strtoupper(Str::random(4));
            $number = "FC-{$date}-{$random}";
        }

        return $number;
    }

    /**
     * Determine freshness tag based on arrival time.
     */
    protected function determineFreshnessTag(\Carbon\Carbon $arrivedAt): string
    {
        $hour = $arrivedAt->hour;

        if ($hour >= 4 && $hour < 8) {
            return 'early_morning_catch';
        }
        if ($hour >= 8 && $hour < 12) {
            return 'morning_catch';
        }
        if ($hour >= 12 && $hour < 16) {
            return 'afternoon_catch';
        }

        return 'today_catch';
    }
}
