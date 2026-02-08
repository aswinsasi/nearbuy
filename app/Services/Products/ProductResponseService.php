<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Models\ProductRequest;
use App\Models\ProductResponse;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Product Response Service.
 *
 * Handles shop responses to product requests.
 *
 * @srs-ref FR-PRD-20 to FR-PRD-23
 */
class ProductResponseService
{
    /*
    |--------------------------------------------------------------------------
    | Response Creation (FR-PRD-20 to FR-PRD-23)
    |--------------------------------------------------------------------------
    */

    /**
     * Create available response with price.
     *
     * @srs-ref FR-PRD-22 - Store response with photo URL, price, description
     * @srs-ref FR-PRD-23 - Prevent duplicate responses
     *
     * @throws \Exception if duplicate or request closed
     */
    public function createResponse(ProductRequest $request, Shop $shop, array $data): ProductResponse
    {
        // FR-PRD-23: Check for duplicate
        if ($this->hasAlreadyResponded($request, $shop)) {
            throw new \Exception('Already responded to this request');
        }

        // Check if request still accepts responses
        if (!$request->isOpen()) {
            throw new \Exception('Request no longer accepts responses');
        }

        $response = ProductResponse::createAvailable(
            $request,
            $shop,
            (float) $data['price'],
            $data['description'] ?? null,
            $data['photo_url'] ?? $data['image_url'] ?? null
        );

        Log::info('Product response created', [
            'response_id' => $response->id,
            'request_id' => $request->id,
            'shop_id' => $shop->id,
            'price' => $data['price'],
        ]);

        return $response;
    }

    /**
     * Create unavailable response (shop doesn't have product).
     *
     * @srs-ref FR-PRD-23 - Prevent duplicate responses
     */
    public function createUnavailableResponse(ProductRequest $request, Shop $shop): ProductResponse
    {
        // FR-PRD-23: Check for duplicate
        if ($this->hasAlreadyResponded($request, $shop)) {
            throw new \Exception('Already responded to this request');
        }

        $response = ProductResponse::createUnavailable($request, $shop);

        Log::info('Unavailable response created', [
            'response_id' => $response->id,
            'request_id' => $request->id,
            'shop_id' => $shop->id,
        ]);

        return $response;
    }

    /*
    |--------------------------------------------------------------------------
    | Duplicate Check (FR-PRD-23)
    |--------------------------------------------------------------------------
    */

    /**
     * Check if shop already responded.
     *
     * @srs-ref FR-PRD-23 - Prevent duplicate responses from same shop
     */
    public function hasAlreadyResponded(ProductRequest $request, Shop $shop): bool
    {
        return ProductResponse::existsForShop($request, $shop);
    }

    /**
     * Get shop's existing response to a request.
     */
    public function getShopResponse(ProductRequest $request, Shop $shop): ?ProductResponse
    {
        return ProductResponse::query()
            ->forRequest($request)
            ->fromShop($shop)
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Response Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Get response by ID with shop details.
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
    public function getResponseWithDistance(
        int $responseId,
        float $customerLat,
        float $customerLng
    ): ?ProductResponse {
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
            ->fromShop($shop)
            ->with('request')
            ->newest()
            ->limit($limit)
            ->get();
    }

    /**
     * Get response count for request.
     */
    public function getResponseCount(ProductRequest $request): int
    {
        return $request->responses()->count();
    }

    /**
     * Get available response count.
     */
    public function getAvailableCount(ProductRequest $request): int
    {
        return $request->responses()->available()->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Price Parsing (FR-PRD-21)
    |--------------------------------------------------------------------------
    */

    /**
     * Parse price from input.
     *
     * @srs-ref FR-PRD-21 - Collect price via free-text
     */
    public function parsePrice(string $input): ?float
    {
        // Remove currency symbols, spaces, commas
        $cleaned = preg_replace('/[₹,\s]/', '', trim($input));

        if (!is_numeric($cleaned)) {
            return null;
        }

        $price = (float) $cleaned;

        // Validate range (₹1 to ₹1 crore)
        if ($price <= 0 || $price > 10000000) {
            return null;
        }

        return $price;
    }

    /**
     * Parse price and description from combined input.
     *
     * Formats:
     * - "15000" → price only
     * - "15000, Samsung model" → price + description
     * - "15000 - Black color" → price + description
     *
     * @srs-ref FR-PRD-21 - Collect price and model info
     */
    public function parsePriceAndDetails(string $input): array
    {
        $input = trim($input);

        // Try separators: " - ", ", ", " "
        $separators = [' - ', ', ', ' '];

        foreach ($separators as $sep) {
            if (str_contains($input, $sep)) {
                $parts = explode($sep, $input, 2);
                $price = $this->parsePrice($parts[0]);

                if ($price !== null) {
                    $desc = trim($parts[1] ?? '');
                    return [
                        'price' => $price,
                        'description' => $desc ?: null,
                    ];
                }
            }
        }

        // Just price
        return [
            'price' => $this->parsePrice($input),
            'description' => null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get shop response stats.
     */
    public function getShopStats(Shop $shop): array
    {
        $total = ProductResponse::fromShop($shop)->count();
        $available = ProductResponse::fromShop($shop)->available()->count();
        $thisWeek = ProductResponse::fromShop($shop)
            ->where('created_at', '>=', now()->startOfWeek())
            ->count();

        return [
            'total' => $total,
            'available' => $available,
            'unavailable' => $total - $available,
            'this_week' => $thisWeek,
        ];
    }
}