<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\ProductSearchStep;
use App\Enums\ShopCategory;
use App\Models\ConversationSession;
use App\Models\ProductRequest;
use App\Services\Products\ProductSearchService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\Messages\ProductMessages;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Product Search Flow Handler.
 *
 * SIMPLIFIED CONVERSATIONAL FLOW:
 * 1. ASK_CATEGORY → "🔍 Entha vendathu?" with shop counts (FR-PRD-01)
 * 2. ASK_DESCRIPTION → Free text with examples (FR-PRD-02)
 * 3. CONFIRM → Request#, shop count, Send/Edit/Cancel (FR-PRD-03, FR-PRD-04)
 * 4. WAITING → "✅ X shops-nu chodichittund!" (FR-PRD-05, FR-PRD-06)
 * 5. VIEW_RESPONSES → Browse responses sorted by price (FR-PRD-31)
 *
 * @srs-ref FR-PRD-01 to FR-PRD-06
 */
class ProductSearchFlowHandler implements FlowHandlerInterface
{
    /** Default search radius */
    protected const DEFAULT_RADIUS_KM = 5;

    /** Minimum description length */
    protected const MIN_DESCRIPTION = 10;

    /** Request expiry hours */
    protected const EXPIRY_HOURS = 2;

    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected ProductSearchService $searchService,
    ) {}

    public function getName(): string
    {
        return FlowType::PRODUCT_SEARCH->value;
    }

    public function canHandleStep(string $step): bool
    {
        $s = ProductSearchStep::tryFrom($step);
        return $s !== null && $s->isCustomerStep();
    }

    public function getExpectedInputType(string $step): string
    {
        return ProductSearchStep::tryFrom($step)?->expectedInput() ?? 'text';
    }

    /*
    |--------------------------------------------------------------------------
    | Entry Points
    |--------------------------------------------------------------------------
    */

    /**
     * Start new product search.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->whatsApp->sendButtons(
                $session->phone,
                "⚠️ Register cheyyuka first.",
                [
                    ['id' => 'register', 'title' => '📝 Register'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Check location
        if (!$this->hasLocation($user)) {
            $this->askLocation($session);
            return;
        }

        // Store user location
        $this->sessionManager->mergeTempData($session, [
            'lat' => (float) $user->latitude,
            'lng' => (float) $user->longitude,
            'radius' => self::DEFAULT_RADIUS_KM,
        ]);

        // Clear previous data
        $this->clearSearchData($session);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::PRODUCT_SEARCH,
            ProductSearchStep::ASK_CATEGORY->value
        );

        $this->showCategories($session);

        Log::info('Product search started', ['phone' => $this->maskPhone($session->phone)]);
    }

    /**
     * View user's requests.
     */
    public function startMyRequests(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::PRODUCT_SEARCH,
            ProductSearchStep::MY_REQUESTS->value
        );

        $this->showMyRequests($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Main Handler
    |--------------------------------------------------------------------------
    */

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle location at any point
        if ($message->isLocation()) {
            $this->handleLocationShare($message, $session);
            return;
        }

        $step = ProductSearchStep::tryFrom($session->current_step);

        if (!$step || !$step->isCustomerStep()) {
            $this->start($session);
            return;
        }

        match ($step) {
            ProductSearchStep::ASK_CATEGORY => $this->handleCategorySelect($message, $session),
            ProductSearchStep::ASK_DESCRIPTION => $this->handleDescription($message, $session),
            ProductSearchStep::CONFIRM => $this->handleConfirm($message, $session),
            ProductSearchStep::WAITING => $this->handleWaiting($message, $session),
            ProductSearchStep::VIEW_RESPONSES => $this->handleResponseSelect($message, $session),
            ProductSearchStep::RESPONSE_DETAIL => $this->handleResponseAction($message, $session),
            ProductSearchStep::SHOP_LOCATION => $this->handlePostLocation($message, $session),
            ProductSearchStep::MY_REQUESTS => $this->handleMyRequestSelect($message, $session),
            default => $this->start($session),
        };
    }

    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $step = ProductSearchStep::tryFrom($session->current_step);

        match ($step) {
            ProductSearchStep::ASK_CATEGORY => $this->showCategories($session, true),
            ProductSearchStep::ASK_DESCRIPTION => $this->askDescription($session, true),
            ProductSearchStep::CONFIRM => $this->showConfirmation($session),
            default => $this->start($session),
        };
    }

    public function handleTimeout(ConversationSession $session): void
    {
        $this->whatsApp->sendText(
            $session->phone,
            "⏰ Session expired. Type *search* to start again."
        );
        $this->sessionManager->resetToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Category Selection (FR-PRD-01)
    |--------------------------------------------------------------------------
    */

    protected function handleCategorySelect(IncomingMessage $message, ConversationSession $session): void
    {
        $category = $this->extractCategory($message);

        if (!$category) {
            $this->showCategories($session, true);
            return;
        }

        $this->sessionManager->setTempData($session, 'category', $category);

        // Move to description
        $this->sessionManager->setStep($session, ProductSearchStep::ASK_DESCRIPTION->value);
        $this->askDescription($session);

        Log::info('Category selected', [
            'category' => $category,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Show categories with shop counts.
     *
     * @srs-ref FR-PRD-01 - Present category selection via list message
     */
    protected function showCategories(ConversationSession $session, bool $isRetry = false): void
    {
        $lat = $this->sessionManager->getTempData($session, 'lat');
        $lng = $this->sessionManager->getTempData($session, 'lng');
        $radius = $this->sessionManager->getTempData($session, 'radius', self::DEFAULT_RADIUS_KM);

        // Get shop counts by category
        $counts = [];
        if ($lat && $lng) {
            $counts = $this->searchService->getShopCountsByCategory((float) $lat, (float) $lng, (float) $radius);
        }

        $message = $isRetry
            ? "👆 List-ൽ നിന്ന് category select cheyyuka"
            : ProductMessages::ASK_CATEGORY;

        // Build category list with counts
        $sections = ProductMessages::buildCategoryList($counts);

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            '📦 Categories',
            $sections,
            "📍 {$radius}km radius"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Description (FR-PRD-02)
    |--------------------------------------------------------------------------
    */

    protected function handleDescription(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->askDescription($session, true);
            return;
        }

        $description = trim($message->text ?? '');

        // Validate length
        if (mb_strlen($description) < self::MIN_DESCRIPTION) {
            $this->whatsApp->sendButtons(
                $session->phone,
                ProductMessages::ERROR_SHORT_DESCRIPTION,
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        $this->sessionManager->setTempData($session, 'description', $description);

        // Move to confirm
        $this->sessionManager->setStep($session, ProductSearchStep::CONFIRM->value);
        $this->showConfirmation($session);

        Log::info('Description entered', [
            'length' => mb_strlen($description),
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Ask for product description.
     *
     * @srs-ref FR-PRD-02 - Collect product description via free-text
     */
    protected function askDescription(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? ProductMessages::ERROR_SHORT_DESCRIPTION
            : ProductMessages::ASK_DESCRIPTION;

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [['id' => 'menu', 'title' => '🏠 Menu']]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Confirmation (FR-PRD-03, FR-PRD-04)
    |--------------------------------------------------------------------------
    */

    protected function handleConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $this->extractAction($message, ['send', 'edit', 'cancel']);

        match ($action) {
            'send' => $this->sendRequest($session),
            'edit' => $this->restartSearch($session),
            'cancel' => $this->cancelSearch($session),
            default => $this->showConfirmation($session),
        };
    }

    /**
     * Show confirmation with request# and shop count.
     *
     * @srs-ref FR-PRD-03 - Generate unique request number (NB-XXXX)
     * @srs-ref FR-PRD-04 - Display confirmation with shop count
     */
    protected function showConfirmation(ConversationSession $session): void
    {
        $description = $this->sessionManager->getTempData($session, 'description');
        $category = $this->sessionManager->getTempData($session, 'category', 'all');
        $lat = (float) $this->sessionManager->getTempData($session, 'lat');
        $lng = (float) $this->sessionManager->getTempData($session, 'lng');
        $radius = (int) $this->sessionManager->getTempData($session, 'radius', self::DEFAULT_RADIUS_KM);

        // FR-PRD-05: Count eligible shops by category AND proximity
        $shopCount = $this->searchService->countEligibleShops($lat, $lng, $radius, $category);

        if ($shopCount === 0) {
            $this->showNoShops($session, $category, $radius);
            return;
        }

        // Generate preview request number
        $requestNumber = ProductRequest::generateRequestNumber();
        $this->sessionManager->setTempData($session, 'request_number_preview', $requestNumber);

        $categoryLabel = ProductMessages::getCategoryLabel($category);

        $message = ProductMessages::format(ProductMessages::CONFIRM_REQUEST, [
            'request_number' => $requestNumber,
            'description' => ProductMessages::truncate($description, 100),
            'shop_count' => $shopCount,
            'category' => $categoryLabel,
            'radius' => $radius,
        ]);

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            ProductMessages::getConfirmButtons()
        );
    }

    protected function showNoShops(ConversationSession $session, string $category, int $radius): void
    {
        $message = ProductMessages::format(ProductMessages::NO_SHOPS, [
            'category' => ProductMessages::getCategoryLabel($category),
            'radius' => $radius,
        ]);

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'edit', 'title' => '🔄 Try Different'],
                ['id' => 'menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Send Request (FR-PRD-05, FR-PRD-06)
    |--------------------------------------------------------------------------
    */

    /**
     * Create and send request to shops.
     *
     * @srs-ref FR-PRD-05 - Identify eligible shops by category AND proximity
     * @srs-ref FR-PRD-06 - Set request expiration (default 2 hours)
     */
    protected function sendRequest(ConversationSession $session): void
    {
        try {
            $user = $this->getUser($session);

            // Create request
            $request = $this->searchService->createRequest($user, [
                'description' => $this->sessionManager->getTempData($session, 'description'),
                'category' => $this->sessionManager->getTempData($session, 'category'),
                'latitude' => (float) $this->sessionManager->getTempData($session, 'lat'),
                'longitude' => (float) $this->sessionManager->getTempData($session, 'lng'),
                'radius_km' => (int) $this->sessionManager->getTempData($session, 'radius', self::DEFAULT_RADIUS_KM),
            ]);

            // FR-PRD-05: Find eligible shops
            $shops = $this->searchService->findEligibleShops($request);

            // Notify shops
            $this->notifyShops($request, $shops);

            // Update count
            $this->searchService->updateShopsNotified($request, $shops->count());

            // Store request ID
            $this->sessionManager->setTempData($session, 'request_id', $request->id);

            // FR-PRD-06: Show expiry info
            $expiryHours = config('nearbuy.products.request_expiry_hours', self::EXPIRY_HOURS);

            $message = ProductMessages::format(ProductMessages::REQUEST_SENT, [
                'shop_count' => $shops->count(),
                'request_number' => $request->request_number,
                'hours' => $expiryHours,
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                [
                    ['id' => 'view_responses', 'title' => '📬 Check Responses'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );

            $this->sessionManager->setStep($session, ProductSearchStep::WAITING->value);

            Log::info('Product request sent', [
                'request_id' => $request->id,
                'request_number' => $request->request_number,
                'shops_notified' => $shops->count(),
                'phone' => $this->maskPhone($session->phone),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send product request', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                "❌ Request send cheyyaan pattiyilla. Try again.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );

            $this->start($session);
        }
    }

    /**
     * Notify shops about the request.
     */
    protected function notifyShops(ProductRequest $request, $shops): void
    {
        foreach ($shops as $shop) {
            $owner = $shop->owner;
            if (!$owner) continue;

            $message = ProductMessages::format(ProductMessages::SHOP_NEW_REQUEST, [
                'description' => $request->description,
                'distance' => ProductMessages::formatDistance($shop->distance_km ?? 0),
                'expiry' => ProductMessages::formatTimeRemaining($request->expires_at),
            ]);

            // Send with image if available
            if ($request->image_url) {
                $this->whatsApp->sendImage($owner->phone, $request->image_url, $message);
            } else {
                $this->whatsApp->sendText($owner->phone, $message);
            }

            // Response buttons
            $this->whatsApp->sendButtons(
                $owner->phone,
                ProductMessages::SHOP_RESPOND_PROMPT,
                ProductMessages::getShopRespondButtons($request->id)
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step 5: Waiting & Responses (FR-PRD-30 to FR-PRD-35)
    |--------------------------------------------------------------------------
    */

    protected function handleWaiting(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'view_responses' => $this->showResponses($session),
            'menu', 'main_menu' => $this->sessionManager->resetToMainMenu($session),
            default => $this->showResponses($session),
        };
    }

    /**
     * Show responses sorted by price.
     *
     * @srs-ref FR-PRD-31 - Sort responses by price (lowest first)
     */
    protected function showResponses(ConversationSession $session): void
    {
        $requestId = $this->sessionManager->getTempData($session, 'request_id');
        $request = ProductRequest::find($requestId);

        if (!$request) {
            $this->whatsApp->sendText($session->phone, ProductMessages::ERROR_REQUEST_NOT_FOUND);
            $this->showMyRequests($session);
            return;
        }

        $responses = $this->searchService->getResponses($request);

        if ($responses->isEmpty()) {
            $message = ProductMessages::format(ProductMessages::NO_RESPONSES, [
                'request_number' => $request->request_number,
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                [
                    ['id' => 'refresh', 'title' => '🔄 Refresh'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        $header = ProductMessages::format(ProductMessages::RESPONSES_HEADER, [
            'request_number' => $request->request_number,
            'count' => $responses->count(),
        ]);

        // Build list (FR-PRD-31: sorted by price)
        $sections = ProductMessages::buildResponsesList($responses->toArray());

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '💰 View Offers',
            $sections,
            '📬 Responses'
        );

        $this->sessionManager->setStep($session, ProductSearchStep::VIEW_RESPONSES->value);
    }

    protected function handleResponseSelect(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isListReply()) {
            $id = $message->getSelectionId();

            if (str_starts_with($id, 'resp_')) {
                $responseId = (int) str_replace('resp_', '', $id);
                $this->sessionManager->setTempData($session, 'response_id', $responseId);
                $this->sessionManager->setStep($session, ProductSearchStep::RESPONSE_DETAIL->value);
                $this->showResponseDetail($session);
                return;
            }
        }

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();

            match ($action) {
                'refresh' => $this->showResponses($session),
                'close' => $this->confirmCloseRequest($session),
                'menu', 'main_menu' => $this->sessionManager->resetToMainMenu($session),
                default => null,
            };
            return;
        }

        $this->showResponses($session);
    }

    /**
     * Show single response detail.
     *
     * @srs-ref FR-PRD-33 - Send product photo and details
     */
    protected function showResponseDetail(ConversationSession $session): void
    {
        $responseId = $this->sessionManager->getTempData($session, 'response_id');
        $requestId = $this->sessionManager->getTempData($session, 'request_id');

        $request = ProductRequest::find($requestId);
        if (!$request) {
            $this->showMyRequests($session);
            return;
        }

        $response = $request->responses()
            ->select('product_responses.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$request->longitude, $request->latitude])
            ->join('shops', 'product_responses.shop_id', '=', 'shops.id')
            ->with('shop')
            ->where('product_responses.id', $responseId)
            ->first();

        if (!$response) {
            $this->whatsApp->sendText($session->phone, "❌ Response kandilla.");
            $this->showResponses($session);
            return;
        }

        $shop = $response->shop;

        // Build card
        $template = $response->description
            ? ProductMessages::RESPONSE_CARD_WITH_NOTES
            : ProductMessages::RESPONSE_CARD;

        $card = ProductMessages::format($template, [
            'shop_name' => $shop->shop_name,
            'distance' => ProductMessages::formatDistance($response->distance_km ?? 0),
            'price' => number_format((float) $response->price),
            'notes' => $response->description ?? '',
        ]);

        // Send photo if available
        if ($response->photo_url) {
            $this->whatsApp->sendImage($session->phone, $response->photo_url, $card);
        } else {
            $this->whatsApp->sendText($session->phone, $card);
        }

        // FR-PRD-34: Get Location and Call Shop buttons
        $this->whatsApp->sendButtons(
            $session->phone,
            "Entha cheyyendathu?",
            ProductMessages::getResponseDetailButtons()
        );
    }

    protected function handleResponseAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'location' => $this->showShopLocation($session),
            'contact' => $this->showShopContact($session),
            'back' => $this->showResponses($session),
            'menu', 'main_menu' => $this->sessionManager->resetToMainMenu($session),
            default => $this->showResponses($session),
        };
    }

    /**
     * Send shop location.
     *
     * @srs-ref FR-PRD-34 - Provide Get Location option
     */
    protected function showShopLocation(ConversationSession $session): void
    {
        $responseId = $this->sessionManager->getTempData($session, 'response_id');
        $response = \App\Models\ProductResponse::with('shop')->find($responseId);

        if (!$response || !$response->shop) {
            $this->showResponses($session);
            return;
        }

        $shop = $response->shop;

        $this->whatsApp->sendLocation(
            $session->phone,
            (float) $shop->latitude,
            (float) $shop->longitude,
            $shop->shop_name,
            $shop->address ?? ''
        );

        $this->whatsApp->sendButtons(
            $session->phone,
            "📍 *{$shop->shop_name}*\n\nMaps-ൽ tap cheythu directions get cheyyuka.",
            [
                ['id' => 'contact', 'title' => '📞 Call Shop'],
                ['id' => 'back', 'title' => '⬅️ Back'],
                ['id' => 'menu', 'title' => '🏠 Menu'],
            ]
        );

        $this->sessionManager->setStep($session, ProductSearchStep::SHOP_LOCATION->value);
    }

    protected function showShopContact(ConversationSession $session): void
    {
        $responseId = $this->sessionManager->getTempData($session, 'response_id');
        $response = \App\Models\ProductResponse::with('shop.owner')->find($responseId);

        if (!$response || !$response->shop) {
            $this->showResponses($session);
            return;
        }

        $shop = $response->shop;
        $phone = $shop->owner?->phone ?? 'Not available';

        $this->whatsApp->sendButtons(
            $session->phone,
            "📞 *{$shop->shop_name}*\n\nPhone: +{$phone}\n\n_Tap to call_",
            [
                ['id' => 'location', 'title' => '📍 Get Location'],
                ['id' => 'back', 'title' => '⬅️ Back'],
                ['id' => 'menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    protected function handlePostLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'contact' => $this->showShopContact($session),
            'back' => $this->showResponseDetail($session),
            'menu', 'main_menu' => $this->sessionManager->resetToMainMenu($session),
            default => $this->showResponses($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | My Requests
    |--------------------------------------------------------------------------
    */

    protected function handleMyRequestSelect(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isListReply()) {
            $id = $message->getSelectionId();

            if (str_starts_with($id, 'req_')) {
                $requestId = (int) str_replace('req_', '', $id);
                $this->sessionManager->setTempData($session, 'request_id', $requestId);
                $this->sessionManager->setStep($session, ProductSearchStep::VIEW_RESPONSES->value);
                $this->showResponses($session);
                return;
            }
        }

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();

            match ($action) {
                'new_search' => $this->start($session),
                'menu', 'main_menu' => $this->sessionManager->resetToMainMenu($session),
                default => null,
            };
            return;
        }

        $this->showMyRequests($session);
    }

    protected function showMyRequests(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $requests = $this->searchService->getUserActiveRequests($user);

        if ($requests->isEmpty()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                ProductMessages::MY_REQUESTS_EMPTY,
                [
                    ['id' => 'new_search', 'title' => '🔍 Search'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        $header = ProductMessages::format(ProductMessages::MY_REQUESTS_HEADER, [
            'count' => $requests->count(),
        ]);

        $sections = ProductMessages::buildMyRequestsList($requests->toArray());

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '📋 View Requests',
            $sections,
            '📋 My Requests'
        );
    }

    protected function confirmCloseRequest(ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $session->phone,
            "🔒 *Request close cheyyano?*\n\nNew responses varunnathu nilkkum.",
            [
                ['id' => 'confirm_close', 'title' => '✅ Yes, Close'],
                ['id' => 'cancel_close', 'title' => '❌ Keep Open'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    protected function restartSearch(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, "🔄 Pudhiyathayi thudangaam.");
        $this->clearSearchData($session);
        $this->start($session);
    }

    protected function cancelSearch(ConversationSession $session): void
    {
        $this->clearSearchData($session);
        $this->whatsApp->sendText($session->phone, "❌ Search cancelled.");
        $this->sessionManager->resetToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Location Handling
    |--------------------------------------------------------------------------
    */

    protected function askLocation(ConversationSession $session): void
    {
        $this->whatsApp->requestLocation(
            $session->phone,
            ProductMessages::ERROR_NO_LOCATION
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::PRODUCT_SEARCH,
            ProductSearchStep::ASK_CATEGORY->value
        );
    }

    protected function handleLocationShare(IncomingMessage $message, ConversationSession $session): void
    {
        $coords = $message->getCoordinates();

        if ($coords) {
            $this->sessionManager->mergeTempData($session, [
                'lat' => $coords['latitude'],
                'lng' => $coords['longitude'],
            ]);

            // Update user
            $user = $this->getUser($session);
            if ($user) {
                $user->update([
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                ]);
            }

            $this->whatsApp->sendText($session->phone, "✅ Location saved!");
        }

        $this->showCategories($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function getUser(ConversationSession $session): ?\App\Models\User
    {
        if ($session->user_id) {
            return \App\Models\User::find($session->user_id);
        }
        return \App\Models\User::where('phone', $session->phone)->first();
    }

    protected function hasLocation($user): bool
    {
        return $user->latitude !== null
            && $user->longitude !== null
            && abs((float) $user->latitude) <= 90
            && abs((float) $user->longitude) <= 180;
    }

    protected function clearSearchData(ConversationSession $session): void
    {
        $keys = ['category', 'description', 'request_id', 'response_id', 'request_number_preview'];
        foreach ($keys as $key) {
            $this->sessionManager->removeTempData($session, $key);
        }
    }

    protected function extractCategory(IncomingMessage $message): ?string
    {
        if ($message->isListReply()) {
            $id = $message->getSelectionId();
            if (str_starts_with($id, 'cat_')) {
                return str_replace('cat_', '', $id);
            }
            return $id;
        }

        if ($message->isText()) {
            $text = mb_strtolower(trim($message->text ?? ''));

            $map = [
                'grocery' => ['grocery', 'kirana', 'സാധനം'],
                'electronics' => ['electronics', 'electronic', 'gadget'],
                'clothes' => ['clothes', 'dress', 'വസ്ത്രം'],
                'mobile' => ['mobile', 'phone', 'ഫോൺ'],
                'medical' => ['medical', 'medicine', 'മരുന്ന്'],
                'appliances' => ['appliances', 'appliance'],
                'furniture' => ['furniture'],
                'hardware' => ['hardware', 'tools'],
                'all' => ['all', 'ellaam', 'എല്ലാം'],
            ];

            foreach ($map as $cat => $keywords) {
                foreach ($keywords as $kw) {
                    if (str_contains($text, $kw)) {
                        return $cat;
                    }
                }
            }
        }

        return null;
    }

    protected function extractAction(IncomingMessage $message, array $valid): ?string
    {
        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
            if (in_array($action, $valid, true)) {
                return $action;
            }
        }

        if ($message->isText()) {
            $text = mb_strtolower(trim($message->text ?? ''));

            $map = [
                'send' => ['send', 'yes', 'confirm', 'ok', '1', 'aam'],
                'edit' => ['edit', 'change', '2'],
                'cancel' => ['cancel', 'no', 'stop', '3', 'venda'],
            ];

            foreach ($map as $action => $keywords) {
                if (in_array($action, $valid, true)) {
                    foreach ($keywords as $kw) {
                        if ($text === $kw || str_contains($text, $kw)) {
                            return $action;
                        }
                    }
                }
            }
        }

        return null;
    }

    protected function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 6) return str_repeat('*', $len);
        return substr($phone, 0, 3) . str_repeat('*', $len - 6) . substr($phone, -3);
    }
}