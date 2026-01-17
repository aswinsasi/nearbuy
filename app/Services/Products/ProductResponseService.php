<?php

namespace App\Services\Products;

use App\Models\ProductRequest;
use App\Models\ProductResponse;
use App\Models\Shop;
use App\Services\Media\MediaService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing shop responses to product requests.
 *
 * Handles response creation, validation, and queries.
 *
 * @example
 * $service = app(ProductResponseService::class);
 *
 * // Check if already responded
 * if (!$service->hasAlreadyResponded($request, $shop)) {
 *     $response = $service->createResponse($request, $shop, [
 *         'price' => 15000,
 *         'description' => 'Samsung M34, Green, 1 year warranty',
 *         'image_url' => 'https://s3.../product.jpg',
 *     ]);
 * }
 */
class ProductResponseService
{
    public function __construct(
        protected MediaService $mediaService,
        protected ProductSearchService $searchService,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Response Creation
    |--------------------------------------------------------------------------
    */

    /**
     * Create a response indicating product is available.
     *
     * @param ProductRequest $request
     * @param Shop $shop
     * @param array{
     *     price: float,
     *     description?: string,
     *     image_url?: string
     * } $data
     * @return ProductResponse
     * @throws \Exception
     */
    public function createResponse(ProductRequest $request, Shop $shop, array $data): ProductResponse
    {
        // Verify request is still accepting responses
        if (!$this->searchService->acceptsResponses($request)) {
            throw new \Exception('This request is no longer accepting responses');
        }

        // Check for duplicate
        if ($this->hasAlreadyResponded($request, $shop)) {
            throw new \Exception('You have already responded to this request');
        }

        $response = ProductResponse::create([
            'request_id' => $request->id,
            'shop_id' => $shop->id,
            'is_available' => true,
            'price' => $data['price'],
            'description' => $data['description'] ?? null,
            'image_url' => $data['image_url'] ?? null,
        ]);

        // Increment response count on request
        $this->searchService->incrementResponseCount($request);

        Log::info('Product response created', [
            'response_id' => $response->id,
            'request_id' => $request->id,
            'shop_id' => $shop->id,
            'price' => $data['price'],
        ]);

        return $response;
    }

    /**
     * Create a response indicating product is NOT available.
     *
     * @param ProductRequest $request
     * @param Shop $shop
     * @return ProductResponse
     */
    public function createUnavailableResponse(ProductRequest $request, Shop $shop): ProductResponse
    {
        // Check for duplicate
        if ($this->hasAlreadyResponded($request, $shop)) {
            throw new \Exception('You have already responded to this request');
        }

        $response = ProductResponse::create([
            'request_id' => $request->id,
            'shop_id' => $shop->id,
            'is_available' => false,
            'price' => null,
            'description' => null,
            'image_url' => null,
        ]);

        // Increment response count
        $this->searchService->incrementResponseCount($request);

        Log::info('Product unavailable response created', [
            'response_id' => $response->id,
            'request_id' => $request->id,
            'shop_id' => $shop->id,
        ]);

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | Response Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Check if shop has already responded to a request.
     */
    public function hasAlreadyResponded(ProductRequest $request, Shop $shop): bool
    {
        return ProductResponse::query()
            ->where('request_id', $request->id)
            ->where('shop_id', $shop->id)
            ->exists();
    }

    /**
     * Get shop's response to a request.
     */
    public function getShopResponse(ProductRequest $request, Shop $shop): ?ProductResponse
    {
        return ProductResponse::query()
            ->where('request_id', $request->id)
            ->where('shop_id', $shop->id)
            ->first();
    }

    /**
     * Get response count for a request.
     */
    public function getResponseCount(ProductRequest $request): int
    {
        return $request->responses()->count();
    }

    /**
     * Get available response count (excluding "not available").
     */
    public function getAvailableResponseCount(ProductRequest $request): int
    {
        return $request->responses()
            ->where('is_available', true)
            ->count();
    }

    /**
     * Get a response by ID with shop details.
     */
    public function getResponseWithShop(int $responseId): ?ProductResponse
    {
        return ProductResponse::query()
            ->with(['shop', 'shop.owner', 'request'])
            ->find($responseId);
    }

    /**
     * Get response with distance from customer.
     */
    public function getResponseWithDistance(int $responseId, float $customerLat, float $customerLng): ?ProductResponse
    {
        return ProductResponse::query()
            ->select('product_responses.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$customerLng, $customerLat])
            ->join('shops', 'product_responses.shop_id', '=', 'shops.id')
            ->with(['shop', 'shop.owner'])
            ->where('product_responses.id', $responseId)
            ->first();
    }

    /**
     * Get all responses by a shop.
     */
    public function getShopResponses(Shop $shop, int $limit = 20): Collection
    {
        return ProductResponse::query()
            ->where('shop_id', $shop->id)
            ->with(['request'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Response Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get response statistics for a shop.
     */
    public function getShopResponseStats(Shop $shop): array
    {
        $total = ProductResponse::where('shop_id', $shop->id)->count();
        $available = ProductResponse::where('shop_id', $shop->id)->where('is_available', true)->count();
        $thisWeek = ProductResponse::where('shop_id', $shop->id)
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();

        return [
            'total_responses' => $total,
            'available_responses' => $available,
            'unavailable_responses' => $total - $available,
            'this_week' => $thisWeek,
        ];
    }

    /**
     * Get average response time for a shop.
     */
    public function getAverageResponseTime(Shop $shop): ?float
    {
        $responses = ProductResponse::query()
            ->where('shop_id', $shop->id)
            ->join('product_requests', 'product_responses.request_id', '=', 'product_requests.id')
            ->selectRaw('AVG(TIMESTAMPDIFF(MINUTE, product_requests.created_at, product_responses.created_at)) as avg_minutes')
            ->first();

        return $responses->avg_minutes;
    }

    /*
    |--------------------------------------------------------------------------
    | Response Update
    |--------------------------------------------------------------------------
    */

    /**
     * Update a response.
     */
    public function updateResponse(ProductResponse $response, array $data): ProductResponse
    {
        $updateData = [];

        if (isset($data['price'])) {
            $updateData['price'] = $data['price'];
        }

        if (isset($data['description'])) {
            $updateData['description'] = $data['description'];
        }

        if (isset($data['image_url'])) {
            $updateData['image_url'] = $data['image_url'];
        }

        if (!empty($updateData)) {
            $response->update($updateData);
        }

        return $response->fresh();
    }

    /**
     * Delete a response and its media.
     */
    public function deleteResponse(ProductResponse $response): bool
    {
        try {
            // Delete media if exists
            if ($response->image_url) {
                $this->mediaService->deleteFromStorage($response->image_url);
            }

            $response->delete();

            Log::info('Product response deleted', [
                'response_id' => $response->id,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to delete response', [
                'response_id' => $response->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate price input.
     */
    public function validatePrice(string $input): ?float
    {
        // Remove currency symbols, spaces, commas
        $cleaned = preg_replace('/[₹,\s]/', '', $input);

        // Check if numeric
        if (!is_numeric($cleaned)) {
            return null;
        }

        $price = (float) $cleaned;

        // Check reasonable range
        if ($price <= 0 || $price > 10000000) {
            return null;
        }

        return $price;
    }

    /**
     * Parse price and details from combined input.
     * Format: "15000 - description here" or just "15000"
     */
    public function parsePriceAndDetails(string $input): array
    {
        $input = trim($input);

        // Check for separator
        if (str_contains($input, ' - ')) {
            $parts = explode(' - ', $input, 2);
            $price = $this->validatePrice($parts[0]);
            $description = trim($parts[1] ?? '');

            return [
                'price' => $price,
                'description' => $description ?: null,
            ];
        }

        // Just price
        return [
            'price' => $this->validatePrice($input),
            'description' => null,
        ];
    }
}