<?php

namespace App\Services\Offers;

use App\Enums\OfferValidity;
use App\Enums\ShopCategory;
use App\Models\Offer;
use App\Models\Shop;
use App\Models\User;
use App\Services\Media\MediaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing offers.
 *
 * Handles offer creation, querying, and analytics.
 *
 * @example
 * $offerService = app(OfferService::class);
 *
 * // Create offer
 * $offer = $offerService->createOffer($shop, [
 *     'media_url' => 'https://s3.../offer.jpg',
 *     'media_type' => 'image',
 *     'caption' => 'Special discount!',
 *     'validity' => 'week',
 * ]);
 *
 * // Browse offers near location
 * $offers = $offerService->getOffersNearLocation(
 *     latitude: 9.5916,
 *     longitude: 76.5222,
 *     radiusKm: 5,
 *     category: 'grocery'
 * );
 */
class OfferService
{
    public function __construct(
        protected MediaService $mediaService,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Offer Creation
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new offer for a shop.
     *
     * @param Shop $shop
     * @param array{
     *     media_url: string,
     *     media_type: string,
     *     caption?: string,
     *     validity: string
     * } $data
     * @return Offer
     * @throws \Exception
     */
    public function createOffer(Shop $shop, array $data): Offer
    {
        // Check max offers limit
        $maxOffers = config('nearbuy.offers.max_active_per_shop', 5);
        $activeCount = $shop->offers()->active()->count();

        if ($activeCount >= $maxOffers) {
            throw new \Exception("Maximum of {$maxOffers} active offers reached");
        }

        // Calculate expiry based on validity
        $expiresAt = $this->calculateExpiry($data['validity']);

        $offer = Offer::create([
            'shop_id' => $shop->id,
            'media_url' => $data['media_url'],
            'media_type' => $data['media_type'],
            'caption' => $data['caption'] ?? null,
            'validity_type' => $this->parseValidity($data['validity']),
            'expires_at' => $expiresAt,
            'view_count' => 0,
            'location_tap_count' => 0,
            'is_active' => true,
        ]);

        Log::info('Offer created', [
            'offer_id' => $offer->id,
            'shop_id' => $shop->id,
            'expires_at' => $expiresAt,
        ]);

        return $offer;
    }

    /**
     * Update an existing offer.
     *
     * @param Offer $offer
     * @param array $data
     * @return Offer
     */
    public function updateOffer(Offer $offer, array $data): Offer
    {
        $updateData = [];

        if (isset($data['caption'])) {
            $updateData['caption'] = $data['caption'];
        }

        if (isset($data['validity'])) {
            $updateData['validity_type'] = $this->parseValidity($data['validity']);
            $updateData['expires_at'] = $this->calculateExpiry($data['validity']);
        }

        if (isset($data['is_active'])) {
            $updateData['is_active'] = $data['is_active'];
        }

        if (!empty($updateData)) {
            $offer->update($updateData);
        }

        return $offer->fresh();
    }

    /**
     * Delete an offer and its media.
     *
     * @param Offer $offer
     * @return bool
     */
    public function deleteOffer(Offer $offer): bool
    {
        try {
            // Delete media from storage
            if ($offer->media_url) {
                $this->mediaService->deleteFromStorage($offer->media_url);
            }

            $offer->delete();

            Log::info('Offer deleted', ['offer_id' => $offer->id]);

            return true;

        } catch (\Exception $e) {
            Log::error('Offer deletion failed', [
                'offer_id' => $offer->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Deactivate an offer (soft delete alternative).
     *
     * @param Offer $offer
     * @return Offer
     */
    public function deactivateOffer(Offer $offer): Offer
    {
        $offer->update(['is_active' => false]);

        return $offer;
    }

    /*
    |--------------------------------------------------------------------------
    | Offer Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Get active offers near a location.
     *
     * @param float $latitude User latitude
     * @param float $longitude User longitude
     * @param float $radiusKm Search radius in km
     * @param string|null $category Filter by category
     * @param int $limit Max results
     * @return Collection
     */
    public function getOffersNearLocation(
        float $latitude,
        float $longitude,
        float $radiusKm = 5,
        ?string $category = null,
        int $limit = 20
    ): Collection {
        $query = Offer::query()
            ->select('offers.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$longitude, $latitude])
            ->join('shops', 'offers.shop_id', '=', 'shops.id')
            ->where('offers.is_active', true)
            ->where('offers.expires_at', '>', now())
            ->where('shops.is_active', true)
            ->havingRaw('distance_km <= ?', [$radiusKm])
            ->orderBy('distance_km')
            ->limit($limit)
            ->with(['shop']);

        if ($category && $category !== 'all') {
            $categoryEnum = ShopCategory::tryFrom(strtoupper($category));
            if ($categoryEnum) {
                $query->where('shops.category', $categoryEnum);
            }
        }

        return $query->get();
    }

    /**
     * Get offers by category with distance from user.
     *
     * @param string $category
     * @param float $latitude
     * @param float $longitude
     * @param float $radiusKm
     * @return Collection
     */
    public function getOffersByCategory(
        string $category,
        float $latitude,
        float $longitude,
        float $radiusKm = 5
    ): Collection {
        return $this->getOffersNearLocation($latitude, $longitude, $radiusKm, $category);
    }

    /**
     * Get shops with active offers near location.
     *
     * @param float $latitude
     * @param float $longitude
     * @param float $radiusKm
     * @param string|null $category
     * @return Collection
     */
    public function getShopsWithOffers(
        float $latitude,
        float $longitude,
        float $radiusKm = 5,
        ?string $category = null
    ): Collection {
        $query = Shop::query()
            ->selectRaw('
                shops.*,
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km,
                COUNT(offers.id) as offer_count
            ', [$longitude, $latitude])
            ->join('offers', 'shops.id', '=', 'offers.shop_id')
            ->where('shops.is_active', true)
            ->where('offers.is_active', true)
            ->where('offers.expires_at', '>', now())
            ->havingRaw('distance_km <= ?', [$radiusKm])
            ->groupBy('shops.id')
            ->orderBy('distance_km')
            ->with(['offers' => function ($query) {
                $query->active()->orderBy('created_at', 'desc');
            }]);

        if ($category && $category !== 'all') {
            $categoryEnum = ShopCategory::tryFrom(strtoupper($category));
            if ($categoryEnum) {
                $query->where('shops.category', $categoryEnum);
            }
        }

        return $query->get();
    }

    /**
     * Get offer counts by category near location.
     *
     * @param float $latitude
     * @param float $longitude
     * @param float $radiusKm
     * @return array<string, int>
     */
    public function getOfferCountsByCategory(
        float $latitude,
        float $longitude,
        float $radiusKm = 5
    ): array {
        $results = DB::table('offers')
            ->selectRaw('shops.category, COUNT(offers.id) as count')
            ->join('shops', 'offers.shop_id', '=', 'shops.id')
            ->where('offers.is_active', true)
            ->where('offers.expires_at', '>', now())
            ->where('shops.is_active', true)
            ->whereRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) <= ?
            ', [$longitude, $latitude, $radiusKm])
            ->groupBy('shops.category')
            ->get();

        $counts = [];
        foreach ($results as $row) {
            $counts[strtolower($row->category)] = $row->count;
        }

        return $counts;
    }

    /**
     * Get active offers for a shop.
     *
     * @param Shop $shop
     * @return Collection
     */
    public function getShopOffers(Shop $shop): Collection
    {
        return $shop->offers()
            ->active()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get a single offer with distance from user.
     *
     * @param int $offerId
     * @param float $latitude
     * @param float $longitude
     * @return Offer|null
     */
    public function getOfferWithDistance(int $offerId, float $latitude, float $longitude): ?Offer
    {
        return Offer::query()
            ->select('offers.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$longitude, $latitude])
            ->join('shops', 'offers.shop_id', '=', 'shops.id')
            ->where('offers.id', $offerId)
            ->with(['shop'])
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Analytics
    |--------------------------------------------------------------------------
    */

    /**
     * Increment view count for an offer.
     *
     * @param Offer $offer
     * @return void
     */
    public function incrementViewCount(Offer $offer): void
    {
        $offer->increment('view_count');

        Log::debug('Offer view recorded', ['offer_id' => $offer->id]);
    }

    /**
     * Increment location tap count for an offer.
     *
     * @param Offer $offer
     * @return void
     */
    public function incrementLocationTap(Offer $offer): void
    {
        $offer->increment('location_tap_count');

        Log::debug('Offer location tap recorded', ['offer_id' => $offer->id]);
    }

    /**
     * Calculate estimated customer reach for a shop.
     *
     * @param Shop $shop
     * @param float $radiusKm
     * @return int
     */
    public function calculateEstimatedReach(Shop $shop, float $radiusKm = 5): int
    {
        return User::query()
            ->whereRaw('
                (ST_Distance_Sphere(
                    POINT(longitude, latitude),
                    POINT(?, ?)
                ) / 1000) <= ?
            ', [$shop->longitude, $shop->latitude, $radiusKm])
            ->where('type', 'CUSTOMER')
            ->whereNotNull('registered_at')
            ->count();
    }

    /**
     * Get offer statistics for a shop.
     *
     * @param Shop $shop
     * @return array
     */
    public function getShopOfferStats(Shop $shop): array
    {
        $offers = $shop->offers()->active()->get();

        return [
            'active_offers' => $offers->count(),
            'total_views' => $offers->sum('view_count'),
            'total_location_taps' => $offers->sum('location_tap_count'),
            'expiring_today' => $offers->filter(fn($o) => $o->expires_at->isToday())->count(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance
    |--------------------------------------------------------------------------
    */

    /**
     * Expire old offers (for scheduled task).
     *
     * @return int Number of offers expired
     */
    public function expireOldOffers(): int
    {
        $count = Offer::query()
            ->where('is_active', true)
            ->where('expires_at', '<', now())
            ->update(['is_active' => false]);

        if ($count > 0) {
            Log::info('Offers expired', ['count' => $count]);
        }

        return $count;
    }

    /**
     * Delete very old inactive offers and their media.
     *
     * @param int $daysOld
     * @return int
     */
    public function cleanupOldOffers(int $daysOld = 30): int
    {
        $offers = Offer::query()
            ->where('is_active', false)
            ->where('expires_at', '<', now()->subDays($daysOld))
            ->get();

        $count = 0;
        foreach ($offers as $offer) {
            if ($this->deleteOffer($offer)) {
                $count++;
            }
        }

        Log::info('Old offers cleaned up', ['count' => $count]);

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Check if shop can upload more offers.
     *
     * @param Shop $shop
     * @return bool
     */
    public function canUploadOffer(Shop $shop): bool
    {
        $maxOffers = config('nearbuy.offers.max_active_per_shop', 5);
        $activeCount = $shop->offers()->active()->count();

        return $activeCount < $maxOffers;
    }

    /**
     * Get remaining offer slots for shop.
     *
     * @param Shop $shop
     * @return int
     */
    public function getRemainingOfferSlots(Shop $shop): int
    {
        $maxOffers = config('nearbuy.offers.max_active_per_shop', 5);
        $activeCount = $shop->offers()->active()->count();

        return max(0, $maxOffers - $activeCount);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate expiry date based on validity type.
     *
     * @param string $validity
     * @return \Carbon\Carbon
     */
    protected function calculateExpiry(string $validity): \Carbon\Carbon
    {
        return match (strtolower($validity)) {
            'today' => now()->endOfDay(),
            '3days', 'three_days' => now()->addDays(3)->endOfDay(),
            'week', 'this_week' => now()->addWeek()->endOfDay(),
            default => now()->addDays(3)->endOfDay(),
        };
    }

    /**
     * Parse validity string to enum.
     *
     * @param string $validity
     * @return OfferValidity
     */
    protected function parseValidity(string $validity): OfferValidity
    {
        return match (strtolower($validity)) {
            'today' => OfferValidity::TODAY,
            '3days', 'three_days' => OfferValidity::THREE_DAYS,
            'week', 'this_week' => OfferValidity::WEEK,
            default => OfferValidity::THREE_DAYS,
        };
    }
}