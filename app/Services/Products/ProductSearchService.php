<?php

declare(strict_types=1);

namespace App\Services\Products;

use App\Enums\RequestStatus;
use App\Enums\ShopCategory;
use App\Models\ProductRequest;
use App\Models\ProductResponse;
use App\Models\Shop;
use App\Models\User;
use App\Services\WhatsApp\Messages\ProductMessages;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Product Search Service.
 *
 * Handles request creation, shop matching, response aggregation, and delivery.
 *
 * @srs-ref FR-PRD-01 to FR-PRD-35
 */
class ProductSearchService
{
    public function __construct(
        protected ?WhatsAppService $whatsApp = null
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Request Creation (FR-PRD-01 to FR-PRD-06)
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new product request.
     *
     * @srs-ref FR-PRD-03 - Generate unique request number
     */
    public function createRequest(User $user, array $data): ProductRequest
    {
        $latitude = $data['latitude'] ?? $user->latitude;
        $longitude = $data['longitude'] ?? $user->longitude;
        $radiusKm = $data['radius_km'] ?? config('nearbuy.products.default_radius_km', 5);

        $category = null;
        if (!empty($data['category']) && $data['category'] !== 'all') {
            $category = ShopCategory::tryFrom(strtolower($data['category']));
        }

        $expiryHours = config('nearbuy.products.request_expiry_hours', 24);

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
            'expires_at' => now()->addHours($expiryHours),
            'shops_notified' => 0,
            'response_count' => 0,
        ]);

        Log::info('Product request created', [
            'request_id' => $request->id,
            'request_number' => $request->request_number,
            'user_id' => $user->id,
        ]);

        return $request;
    }

    /**
     * Generate unique request number (NB-XXXX).
     *
     * @srs-ref FR-PRD-03
     */
    public function generateRequestNumber(): string
    {
        do {
            $number = 'NB-' . strtoupper(Str::random(4));
        } while (ProductRequest::where('request_number', $number)->exists());

        return $number;
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Matching (FR-PRD-05)
    |--------------------------------------------------------------------------
    */

    /**
     * Find eligible shops for a request.
     *
     * @srs-ref FR-PRD-05 - Identify eligible shops by category and proximity
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

        if ($request->category) {
            $query->where('shops.category', $request->category);
        }

        return $query->get();
    }

    /**
     * Count eligible shops before creating request.
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
            ->whereIn('status', [RequestStatus::OPEN, RequestStatus::COLLECTING])
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
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->each(function ($request) {
                $request->time_remaining = ProductMessages::formatTimeRemaining($request->expires_at);
            });
    }

    /**
     * Get a single request for a shop (with distance).
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

    /*
    |--------------------------------------------------------------------------
    | Response Aggregation (FR-PRD-30, FR-PRD-31)
    |--------------------------------------------------------------------------
    */

    /**
     * Get responses sorted by price.
     *
     * @srs-ref FR-PRD-30 - Aggregate responses
     * @srs-ref FR-PRD-31 - Sort by price (lowest first)
     */
    public function getResponsesSortedByPrice(ProductRequest $request): Collection
    {
        return ProductResponse::query()
            ->select('product_responses.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$request->longitude, $request->latitude])
            ->join('shops', 'product_responses.shop_id', '=', 'shops.id')
            ->where('product_responses.request_id', $request->id)
            ->where('product_responses.is_available', true)
            ->whereNotNull('product_responses.price')
            ->with(['shop', 'shop.owner'])
            ->orderBy('product_responses.price', 'asc') // FR-PRD-31
            ->get();
    }

    /**
     * Get all responses (including unavailable).
     */
    public function getAllResponses(ProductRequest $request): Collection
    {
        return ProductResponse::query()
            ->select('product_responses.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$request->longitude, $request->latitude])
            ->join('shops', 'product_responses.shop_id', '=', 'shops.id')
            ->where('product_responses.request_id', $request->id)
            ->with(['shop', 'shop.owner'])
            ->orderByDesc('product_responses.is_available')
            ->orderBy('product_responses.price')
            ->get();
    }

    /**
     * Get a single response with full details.
     *
     * @srs-ref FR-PRD-33 - Get response for detail view
     */
    public function getResponseWithDetails(int $responseId, ProductRequest $request): ?ProductResponse
    {
        return ProductResponse::query()
            ->select('product_responses.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$request->longitude, $request->latitude])
            ->join('shops', 'product_responses.shop_id', '=', 'shops.id')
            ->where('product_responses.id', $responseId)
            ->with(['shop', 'shop.owner'])
            ->first();
    }

    /**
     * Get best (lowest price) response.
     */
    public function getBestResponse(ProductRequest $request): ?ProductResponse
    {
        return ProductResponse::query()
            ->where('request_id', $request->id)
            ->where('is_available', true)
            ->whereNotNull('price')
            ->with('shop')
            ->orderBy('price', 'asc')
            ->first();
    }

    /**
     * Get response summary for customer notification.
     */
    public function getResponseSummary(ProductRequest $request): array
    {
        $responses = $this->getResponsesSortedByPrice($request);
        $count = $responses->count();

        if ($count === 0) {
            return [
                'count' => 0,
                'best_price' => null,
                'best_shop' => null,
                'responses' => collect(),
            ];
        }

        $best = $responses->first();

        return [
            'count' => $count,
            'best_price' => $best->price,
            'best_shop' => $best->shop->shop_name ?? 'Shop',
            'responses' => $responses,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Real-Time Response Delivery (NEW!)
    |--------------------------------------------------------------------------
    */

    /**
     * Notify customer of new response (real-time).
     *
     * Called when a shop submits a response.
     * Feels like a friend found something for you!
     */
    public function notifyCustomerOfResponse(ProductRequest $request, ProductResponse $response): void
    {
        if (!$this->whatsApp) return;

        $customer = $request->user;
        if (!$customer) return;

        $shop = $response->shop;
        $distance = ProductMessages::formatDistance($response->distance_km ?? 0);

        // Build friendly notification
        $template = $response->description
            ? ProductMessages::NEW_RESPONSE_ALERT_WITH_DESC
            : ProductMessages::NEW_RESPONSE_ALERT;

        $message = ProductMessages::format($template, [
            'request_number' => $request->request_number,
            'shop_name' => $shop->shop_name ?? 'Shop',
            'distance' => $distance,
            'price' => number_format((float) $response->price),
            'description' => $response->description ?? '',
        ]);

        // Send with photo if available
        if ($response->photo_url) {
            $this->whatsApp->sendImage($customer->phone, $response->photo_url, $message);
        } else {
            $this->whatsApp->sendText($customer->phone, $message);
        }

        // Send action buttons (FR-PRD-34)
        $this->whatsApp->sendButtons(
            $customer->phone,
            "Interested?",
            ProductMessages::getResponseAlertButtons($response->id)
        );

        Log::info('Customer notified of response', [
            'request_id' => $request->id,
            'response_id' => $response->id,
            'customer_phone' => $customer->phone,
        ]);
    }

    /**
     * Send response summary to customer.
     *
     * Called when threshold reached or on-demand.
     *
     * @srs-ref FR-PRD-30 - Aggregate after threshold
     */
    public function sendResponseSummary(ProductRequest $request): void
    {
        if (!$this->whatsApp) return;

        $customer = $request->user;
        if (!$customer) return;

        $summary = $this->getResponseSummary($request);

        if ($summary['count'] === 0) {
            $this->whatsApp->sendButtons(
                $customer->phone,
                ProductMessages::format(ProductMessages::NO_RESPONSES_YET, [
                    'request_number' => $request->request_number,
                    'description' => ProductMessages::truncate($request->description, 50),
                ]),
                ProductMessages::getWaitingButtons()
            );
            return;
        }

        $message = ProductMessages::format(ProductMessages::RESPONSES_SUMMARY, [
            'count' => $summary['count'],
            'request_number' => $request->request_number,
            'description' => ProductMessages::truncate($request->description, 40),
            'best_price' => number_format((float) $summary['best_price']),
            'best_shop' => $summary['best_shop'],
        ]);

        $this->whatsApp->sendButtons(
            $customer->phone,
            $message,
            ProductMessages::getResponseSummaryButtons()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Response List View (FR-PRD-32)
    |--------------------------------------------------------------------------
    */

    /**
     * Send responses list to customer.
     *
     * @srs-ref FR-PRD-32 - Present via list message with price and shop info
     */
    public function sendResponsesList(ProductRequest $request, string $phone): void
    {
        if (!$this->whatsApp) return;

        $responses = $this->getResponsesSortedByPrice($request);

        if ($responses->isEmpty()) {
            $this->whatsApp->sendButtons(
                $phone,
                ProductMessages::format(ProductMessages::NO_RESPONSES_YET, [
                    'request_number' => $request->request_number,
                    'description' => ProductMessages::truncate($request->description, 50),
                ]),
                ProductMessages::getWaitingButtons()
            );
            return;
        }

        // Build header
        $header = ProductMessages::format(ProductMessages::RESPONSES_LIST_HEADER, [
            'count' => $responses->count(),
            'request_number' => $request->request_number,
            'description' => ProductMessages::truncate($request->description, 40),
        ]);

        // Build list (FR-PRD-32)
        $sections = ProductMessages::buildResponsesList($responses->toArray());

        $this->whatsApp->sendList(
            $phone,
            $header,
            '📋 View Responses',
            $sections,
            "#{$request->request_number}"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Response Detail (FR-PRD-33, FR-PRD-34)
    |--------------------------------------------------------------------------
    */

    /**
     * Send response detail with photo.
     *
     * @srs-ref FR-PRD-33 - Send product photo and details
     * @srs-ref FR-PRD-34 - Provide Get Location and Call Shop options
     */
    public function sendResponseDetail(ProductResponse $response, ProductRequest $request, string $phone): void
    {
        if (!$this->whatsApp) return;

        $shop = $response->shop;
        $distance = ProductMessages::formatDistance($response->distance_km ?? 0);
        $rating = $shop->rating ?? '4.0';

        // Build detail message
        $template = $response->description
            ? ProductMessages::RESPONSE_DETAIL
            : ProductMessages::RESPONSE_DETAIL_NO_DESC;

        $descBlock = $response->description
            ? "📝 {$response->description}\n"
            : '';

        $message = ProductMessages::format($template, [
            'shop_name' => $shop->shop_name ?? 'Shop',
            'distance' => $distance,
            'rating' => $rating,
            'price' => number_format((float) $response->price),
            'description_block' => $descBlock,
        ]);

        // FR-PRD-33: Send with photo
        if ($response->photo_url) {
            $this->whatsApp->sendImage($phone, $response->photo_url, $message);
        } else {
            $this->whatsApp->sendText($phone, $message);
        }

        // FR-PRD-34: Action buttons
        $this->whatsApp->sendButtons(
            $phone,
            "What would you like to do?",
            ProductMessages::getResponseDetailWithCloseButtons($response->id)
        );
    }

    /**
     * Send shop location.
     *
     * @srs-ref FR-PRD-34 - Get Location
     */
    public function sendShopLocation(Shop $shop, string $phone): void
    {
        if (!$this->whatsApp) return;

        $this->whatsApp->sendLocation(
            $phone,
            $shop->latitude,
            $shop->longitude,
            $shop->shop_name ?? 'Shop',
            $shop->address ?? ''
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Request Status Management (FR-PRD-35)
    |--------------------------------------------------------------------------
    */

    /**
     * Close a request.
     *
     * @srs-ref FR-PRD-35 - Allow customer to close request
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
     * Send close confirmation.
     *
     * @srs-ref FR-PRD-35
     */
    public function sendCloseConfirmation(ProductRequest $request, string $phone): void
    {
        if (!$this->whatsApp) return;

        $this->whatsApp->sendButtons(
            $phone,
            ProductMessages::format(ProductMessages::CLOSE_CONFIRM, [
                'request_number' => $request->request_number,
            ]),
            ProductMessages::getCloseConfirmButtons()
        );
    }

    /**
     * Send close success message.
     */
    public function sendClosedMessage(string $phone): void
    {
        if (!$this->whatsApp) return;

        $this->whatsApp->sendButtons(
            $phone,
            ProductMessages::REQUEST_CLOSED,
            ProductMessages::getPostCloseButtons()
        );
    }

    /**
     * Check if request is still open.
     */
    public function isRequestOpen(ProductRequest $request): bool
    {
        return in_array($request->status, [RequestStatus::OPEN, RequestStatus::COLLECTING], true)
            && $request->expires_at->isFuture();
    }

    /**
     * Check if request accepts responses.
     */
    public function acceptsResponses(ProductRequest $request): bool
    {
        return $this->isRequestOpen($request);
    }

    /**
     * Expire a request.
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
     * Increment response count (move to COLLECTING status).
     */
    public function incrementResponseCount(ProductRequest $request): void
    {
        $request->increment('response_count');

        if ($request->status === RequestStatus::OPEN && $request->response_count >= 1) {
            $request->update(['status' => RequestStatus::COLLECTING]);
        }
    }

    /**
     * Record shops notified.
     */
    public function recordShopsNotified(ProductRequest $request, int $count): void
    {
        $request->update(['shops_notified' => $count]);
    }

    /*
    |--------------------------------------------------------------------------
    | User Requests
    |--------------------------------------------------------------------------
    */

    /**
     * Get user's active requests.
     */
    public function getUserActiveRequests(User $user): Collection
    {
        return ProductRequest::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [RequestStatus::OPEN, RequestStatus::COLLECTING])
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get user's all requests.
     */
    public function getUserRequests(User $user, int $limit = 10): Collection
    {
        return ProductRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get request by number.
     */
    public function getByRequestNumber(string $requestNumber): ?ProductRequest
    {
        return ProductRequest::where('request_number', $requestNumber)->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance
    |--------------------------------------------------------------------------
    */

    /**
     * Expire old requests.
     */
    public function expireOldRequests(): int
    {
        $count = ProductRequest::query()
            ->whereIn('status', [RequestStatus::OPEN, RequestStatus::COLLECTING])
            ->where('expires_at', '<', now())
            ->update(['status' => RequestStatus::EXPIRED]);

        if ($count > 0) {
            Log::info('Expired product requests', ['count' => $count]);
        }

        return $count;
    }

    /**
     * Get requests about to expire (for notification).
     */
    public function getExpiringRequests(int $minutesUntilExpiry = 30): Collection
    {
        return ProductRequest::query()
            ->whereIn('status', [RequestStatus::OPEN, RequestStatus::COLLECTING])
            ->whereBetween('expires_at', [now(), now()->addMinutes($minutesUntilExpiry)])
            ->where('response_count', '>', 0)
            ->get();
    }

    /**
     * Cleanup old requests.
     */
    public function cleanupOldRequests(int $daysOld = 30): int
    {
        return ProductRequest::query()
            ->whereIn('status', [RequestStatus::CLOSED, RequestStatus::EXPIRED])
            ->where('expires_at', '<', now()->subDays($daysOld))
            ->delete();
    }
}