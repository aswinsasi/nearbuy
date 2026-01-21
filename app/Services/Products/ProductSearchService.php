<?php

namespace App\Services\Products;

use App\Enums\RequestStatus;
use App\Enums\ShopCategory;
use App\Models\ProductRequest;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for managing product search requests.
 *
 * Handles request creation, shop matching, and request lifecycle.
 *
 * @example
 * $service = app(ProductSearchService::class);
 *
 * // Create a new request
 * $request = $service->createRequest($user, [
 *     'description' => 'Samsung Galaxy M34 5G',
 *     'category' => 'mobile',
 *     'radius_km' => 5,
 * ]);
 *
 * // Find eligible shops
 * $shops = $service->findEligibleShops($request);
 */
class ProductSearchService
{
    /*
    |--------------------------------------------------------------------------
    | Request Creation
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new product request.
     *
     * @param User $user
     * @param array{
     *     description: string,
     *     category?: string,
     *     image_url?: string,
     *     latitude?: float,
     *     longitude?: float,
     *     radius_km?: float
     * } $data
     * @return ProductRequest
     */
    public function createRequest(User $user, array $data): ProductRequest
    {
        // Use user's location if not provided
        $latitude = $data['latitude'] ?? $user->latitude;
        $longitude = $data['longitude'] ?? $user->longitude;
        $radiusKm = $data['radius_km'] ?? config('nearbuy.products.default_radius_km', 5);

        // Parse category
        $category = null;
        if (!empty($data['category']) && $data['category'] !== 'all') {
            $category = ShopCategory::tryFrom(strtolower($data['category']));
        }

        // Calculate expiration
        $expiryHours = config('nearbuy.products.request_expiry_hours', 24);
        $expiresAt = now()->addHours($expiryHours);

        $request = ProductRequest::create([
            'user_id' => $user->id,
            'request_number' => $this->generateRequestNumber(),
            'category' => $category,
            'description' => trim($data['description']),
            'image_url' => $data['image_url'] ?? null,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_km' => $radiusKm,
            'status' => RequestStatus::OPEN,
            'expires_at' => $expiresAt,
            'shops_notified' => 0,
            'response_count' => 0,
        ]);

        Log::info('Product request created', [
            'request_id' => $request->id,
            'request_number' => $request->request_number,
            'user_id' => $user->id,
            'category' => $category?->value,
        ]);

        return $request;
    }

    /**
     * Generate a unique request number (NB-XXXX format).
     */
    public function generateRequestNumber(): string
    {
        do {
            $number = 'NB-' . strtoupper(Str::random(4));
        } while (ProductRequest::where('request_number', $number)->exists());

        return $number;
    }

    /**
     * Update request with shop notification count.
     */
    public function updateShopsNotified(ProductRequest $request, int $count): void
    {
        $request->update(['shops_notified' => $count]);
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Matching
    |--------------------------------------------------------------------------
    */

    /**
     * Find shops eligible to respond to a request.
     *
     * @param ProductRequest $request
     * @return Collection<Shop>
     */
    public function findEligibleShops(ProductRequest $request): Collection
    {
        $query = Shop::query()
            ->select('shops.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$request->longitude, $request->latitude])
            ->where('shops.is_active', true)
            ->havingRaw('distance_km <= ?', [$request->radius_km])
            ->orderBy('distance_km');

        // Filter by category if specified
        if ($request->category) {
            $query->where('shops.category', $request->category);
        }

        return $query->get();
    }

    /**
     * Count eligible shops for a potential request.
     */
    public function countEligibleShops(
        float $latitude,
        float $longitude,
        float $radiusKm,
        ?string $category = null
    ): int {
        $query = Shop::query()
            ->where('is_active', true)
            ->whereRaw('
                (ST_Distance_Sphere(
                    POINT(longitude, latitude),
                    POINT(?, ?)
                ) / 1000) <= ?
            ', [$longitude, $latitude, $radiusKm]);

        if ($category && $category !== 'all') {
            $categoryEnum = ShopCategory::tryFrom(strtolower($category));
            if ($categoryEnum) {
                $query->where('category', $categoryEnum);
            }
        }

        return $query->count();
    }

    /**
     * Get shops that haven't responded to a request yet.
     */
    public function getShopsNotResponded(ProductRequest $request): Collection
    {
        $respondedShopIds = $request->responses()->pluck('shop_id');

        return Shop::query()
            ->select('shops.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$request->longitude, $request->latitude])
            ->where('shops.is_active', true)
            ->whereNotIn('shops.id', $respondedShopIds)
            ->havingRaw('distance_km <= ?', [$request->radius_km])
            ->when($request->category, function ($query) use ($request) {
                $query->where('shops.category', $request->category);
            })
            ->orderBy('distance_km')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Request Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Get active requests for a user.
     */
    public function getUserActiveRequests(User $user): Collection
    {
        return ProductRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [RequestStatus::OPEN, RequestStatus::COLLECTING])
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get all requests for a user (including expired).
     */
    public function getUserRequests(User $user, int $limit = 10): Collection
    {
        return ProductRequest::query()
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get a request by number.
     */
    public function getByRequestNumber(string $requestNumber): ?ProductRequest
    {
        return ProductRequest::where('request_number', $requestNumber)->first();
    }

    /**
     * Get a single request by ID with distance from shop.
     */
    public function getRequestForShop(int $requestId, Shop $shop): ?ProductRequest
    {
        return ProductRequest::query()
            ->select('product_requests.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(product_requests.longitude, product_requests.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$shop->longitude, $shop->latitude])
            ->where('product_requests.id', $requestId)
            ->first();
    }

    /**
     * Get requests matching a shop's category and location.
     */
    public function getRequestsForShop(Shop $shop, int $limit = 10): Collection
    {
        return ProductRequest::query()
            ->select('product_requests.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(product_requests.longitude, product_requests.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$shop->longitude, $shop->latitude])
            ->where('status', RequestStatus::OPEN)
            ->where('expires_at', '>', now())
            ->where(function ($query) use ($shop) {
                $query->whereNull('category')
                    ->orWhere('category', $shop->category);
            })
            ->havingRaw('distance_km <= product_requests.radius_km')
            ->whereNotExists(function ($subquery) use ($shop) {
                $subquery->select(DB::raw(1))
                    ->from('product_responses')
                    ->whereColumn('product_responses.request_id', 'product_requests.id')
                    ->where('product_responses.shop_id', $shop->id);
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Count pending requests for a shop.
     */
    public function countPendingRequestsForShop(Shop $shop): int
    {
        return $this->getPendingRequestsForShop($shop)->count();
    }

    /**
     * Get pending requests for a shop (alias for getRequestsForShop).
     */
    public function getPendingRequestsForShop(Shop $shop, int $limit = 10): Collection
    {
        return $this->getRequestsForShop($shop, $limit);
    }

    /*
    |--------------------------------------------------------------------------
    | Response Management
    |--------------------------------------------------------------------------
    */

    /**
     * Get responses for a request, sorted by price.
     */
    public function getResponses(ProductRequest $request): Collection
    {
        return $request->responses()
            ->select('product_responses.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$request->longitude, $request->latitude])
            ->join('shops', 'product_responses.shop_id', '=', 'shops.id')
            ->with(['shop'])
            ->orderByDesc('is_available')
            ->orderBy('price')
            ->get();
    }

    /**
     * Get response count for a request.
     */
    public function getResponseCount(ProductRequest $request): int
    {
        return $request->responses()->count();
    }

    /**
     * Increment response count (called when new response is created).
     */
    public function incrementResponseCount(ProductRequest $request): void
    {
        $request->increment('response_count');

        // Update status if first response
        if ($request->status === RequestStatus::OPEN && $request->response_count >= 1) {
            $request->update(['status' => RequestStatus::COLLECTING]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Request Status Management
    |--------------------------------------------------------------------------
    */

    /**
     * Close a request.
     */
    public function closeRequest(ProductRequest $request): void
    {
        $request->update(['status' => RequestStatus::CLOSED]);

        Log::info('Product request closed', [
            'request_id' => $request->id,
            'request_number' => $request->request_number,
            'response_count' => $request->response_count,
        ]);
    }

    /**
     * Mark a request as expired.
     */
    public function expireRequest(ProductRequest $request): void
    {
        $request->update(['status' => RequestStatus::EXPIRED]);

        Log::info('Product request expired', [
            'request_id' => $request->id,
            'request_number' => $request->request_number,
        ]);
    }

    /**
     * Check if a request is still active.
     */
    public function isRequestActive(ProductRequest $request): bool
    {
        return in_array($request->status, [RequestStatus::OPEN, RequestStatus::COLLECTING])
            && $request->expires_at->isFuture();
    }

    /**
     * Check if a request accepts new responses.
     */
    public function acceptsResponses(ProductRequest $request): bool
    {
        return in_array($request->status, [RequestStatus::OPEN, RequestStatus::COLLECTING])
            && $request->expires_at->isFuture();
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance
    |--------------------------------------------------------------------------
    */

    /**
     * Expire old requests (for scheduled task).
     */
    public function expireOldRequests(): int
    {
        $count = ProductRequest::query()
            ->whereIn('status', [RequestStatus::OPEN, RequestStatus::COLLECTING])
            ->where('expires_at', '<', now())
            ->update(['status' => RequestStatus::EXPIRED]);

        if ($count > 0) {
            Log::info('Product requests expired', ['count' => $count]);
        }

        return $count;
    }

    /**
     * Get requests that are about to expire (for notification).
     */
    public function getExpiringRequests(int $minutesUntilExpiry = 30): Collection
    {
        return ProductRequest::query()
            ->whereIn('status', [RequestStatus::OPEN, RequestStatus::COLLECTING])
            ->whereBetween('expires_at', [
                now(),
                now()->addMinutes($minutesUntilExpiry),
            ])
            ->where('response_count', '>', 0)
            ->get();
    }

    /**
     * Delete very old requests.
     */
    public function cleanupOldRequests(int $daysOld = 30): int
    {
        $count = ProductRequest::query()
            ->whereIn('status', [RequestStatus::CLOSED, RequestStatus::EXPIRED])
            ->where('expires_at', '<', now()->subDays($daysOld))
            ->delete();

        if ($count > 0) {
            Log::info('Old product requests cleaned up', ['count' => $count]);
        }

        return $count;
    }
}