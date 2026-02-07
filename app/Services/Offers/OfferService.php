<?php

namespace App\Services\Offers;

use App\Enums\OfferValidity;
use App\Enums\ShopCategory;
use App\Enums\UserType;
use App\Models\Offer;
use App\Models\Shop;
use App\Models\User;
use App\Services\Media\MediaService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Offer Service - Create, query, and track offers.
 *
 * @srs-ref FR-OFR-01 to FR-OFR-06
 *
 * @example
 * // Create offer (auto-detect shop from user)
 * $offer = $offerService->createOfferForUser($user, [
 *     'media_url' => 'https://s3.../offer.jpg',
 *     'media_type' => 'image',
 *     'validity' => 'today',
 * ]);
 *
 * // Get nearby offers
 * $offers = $offerService->getNearbyOffers(9.5916, 76.5222, 5);
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
     * Create offer for user (auto-detect shop).
     *
     * @srs-ref FR-OFR-03 - Store in cloud storage with unique identifiers
     * @srs-ref FR-OFR-06 - Initialize view_count=0, location_tap_count=0
     */
    public function createOfferForUser(User $user, array $data): Offer
    {
        // Auto-detect shop from user
        $shop = $user->shop;

        if (!$shop) {
            throw new \Exception('User does not have a shop');
        }

        return $this->createOffer($shop, $data);
    }

    /**
     * Create offer for shop.
     *
     * @param Shop $shop
     * @param array{media_url: string, media_type: string, validity: string, caption?: string} $data
     * @return Offer
     * @throws \Exception
     */
    public function createOffer(Shop $shop, array $data): Offer
    {
        // Check max offers limit
        $maxOffers = config('nearbuy.offers.max_active_per_shop', 5);
        $activeCount = $shop->offers()->where('is_active', true)->count();

        if ($activeCount >= $maxOffers) {
            throw new \Exception("Maximum of {$maxOffers} active offers reached");
        }

        // Parse validity
        $validity = OfferValidity::tryFrom($data['validity']) ?? OfferValidity::THREE_DAYS;

        // FR-OFR-06: Initialize metrics to 0
        $offer = Offer::create([
            'shop_id' => $shop->id,
            'media_url' => $data['media_url'],
            'media_type' => $data['media_type'],
            'caption' => $data['caption'] ?? null,
            'validity_type' => $validity,
            'expires_at' => $validity->expiresAt(),
            'view_count' => 0,
            'location_tap_count' => 0,
            'is_active' => true,
        ]);

        Log::info('Offer created', [
            'offer_id' => $offer->id,
            'shop_id' => $shop->id,
            'validity' => $validity->value,
            'expires_at' => $offer->expires_at,
        ]);

        return $offer;
    }

    /**
     * Delete offer and its media.
     */
    public function deleteOffer(Offer $offer): bool
    {
        try {
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
     * Deactivate offer.
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
     * Get offers near a location.
     *
     * @srs-ref FR-OFR-11 - Query within configurable radius (default 5km)
     * @srs-ref FR-OFR-12 - Sort by distance (nearest first)
     */
    public function getNearbyOffers(
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
            $catEnum = ShopCategory::tryFrom($category);
            if ($catEnum) {
                $query->where('shops.category', $catEnum);
            }
        }

        return $query->get();
    }

    /**
     * Get offers by category.
     */
    public function getOffersByCategory(
        string $category,
        float $latitude,
        float $longitude,
        float $radiusKm = 5
    ): Collection {
        return $this->getNearbyOffers($latitude, $longitude, $radiusKm, $category);
    }

    /**
     * Get offer counts by category.
     *
     * @srs-ref FR-OFR-10 - Display category list with offer counts
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
        $total = 0;

        foreach ($results as $row) {
            $counts[strtolower($row->category)] = $row->count;
            $total += $row->count;
        }

        $counts['all'] = $total;

        return $counts;
    }

    /**
     * Get active offers for a shop.
     */
    public function getShopOffers(Shop $shop): Collection
    {
        return $shop->offers()
            ->where('is_active', true)
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get single offer with distance.
     */
    public function getOfferWithDistance(int $offerId, float $lat, float $lng): ?Offer
    {
        return Offer::query()
            ->select('offers.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$lng, $lat])
            ->join('shops', 'offers.shop_id', '=', 'shops.id')
            ->where('offers.id', $offerId)
            ->with(['shop'])
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Analytics (FR-OFR-06)
    |--------------------------------------------------------------------------
    */

    /**
     * Increment view count.
     *
     * @srs-ref FR-OFR-06 - Track offer view counts
     */
    public function incrementViewCount(Offer $offer): void
    {
        $offer->increment('view_count');
        Log::debug('Offer view recorded', ['offer_id' => $offer->id]);
    }

    /**
     * Increment location tap count.
     *
     * @srs-ref FR-OFR-06 - Track location tap metrics
     */
    public function incrementLocationTap(Offer $offer): void
    {
        $offer->increment('location_tap_count');
        Log::debug('Offer location tap recorded', ['offer_id' => $offer->id]);
    }

    /**
     * Calculate estimated customer reach.
     *
     * @srs-ref FR-OFR-05 - Show estimated customer reach
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
            ->where('type', UserType::CUSTOMER)
            ->whereNotNull('registered_at')
            ->count();
    }

    /**
     * Get shop offer stats.
     */
    public function getShopOfferStats(Shop $shop): array
    {
        $offers = $this->getShopOffers($shop);

        return [
            'active_offers' => $offers->count(),
            'total_views' => $offers->sum('view_count'),
            'total_location_taps' => $offers->sum('location_tap_count'),
            'expiring_today' => $offers->filter(fn($o) => $o->expires_at->isToday())->count(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Check if shop can upload more offers.
     */
    public function canUploadOffer(Shop $shop): bool
    {
        $maxOffers = config('nearbuy.offers.max_active_per_shop', 5);
        $activeCount = $shop->offers()->where('is_active', true)->count();

        return $activeCount < $maxOffers;
    }

    /**
     * Get remaining offer slots.
     */
    public function getRemainingSlots(Shop $shop): int
    {
        $maxOffers = config('nearbuy.offers.max_active_per_shop', 5);
        $activeCount = $shop->offers()->where('is_active', true)->count();

        return max(0, $maxOffers - $activeCount);
    }

    /**
     * Check if user can upload offers.
     */
    public function canUserUploadOffer(User $user): bool
    {
        if (!$user->isShopOwner() || !$user->shop) {
            return false;
        }

        return $this->canUploadOffer($user->shop);
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance
    |--------------------------------------------------------------------------
    */

    /**
     * Expire old offers (for scheduled task).
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
     * Cleanup very old inactive offers.
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
}