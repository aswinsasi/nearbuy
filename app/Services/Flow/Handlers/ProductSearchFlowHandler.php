<?php

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\ProductSearchStep;
use App\Models\ConversationSession;
use App\Models\ProductRequest;
use App\Services\Media\MediaService;
use App\Services\Products\ProductResponseService;
use App\Services\Products\ProductSearchService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\ProductMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handles the product search flow for customers.
 *
 * Flow Steps:
 * 1. ask_category - Select category to search
 * 2. ask_description - Describe product needed
 * 3. ask_image - Optional reference image
 * 4. select_radius - How far to search
 * 5. confirm_request - Review and confirm
 * 6. request_sent - Success, waiting for responses
 * 7. view_responses - See shop responses
 * 8. response_detail - View specific response
 */
class ProductSearchFlowHandler implements FlowHandlerInterface
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected ProductSearchService $searchService,
        protected MediaService $mediaService,
    ) {}

    /**
     * Get flow name.
     */
    public function getName(): string
    {
        return FlowType::PRODUCT_SEARCH->value;
    }

    /**
     * Check if can handle step.
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, [
            ProductSearchStep::ASK_CATEGORY->value,
            ProductSearchStep::ASK_DESCRIPTION->value,
            ProductSearchStep::ASK_IMAGE->value,
            ProductSearchStep::ASK_LOCATION->value,
            ProductSearchStep::SELECT_RADIUS->value,
            ProductSearchStep::CONFIRM_REQUEST->value,
            ProductSearchStep::REQUEST_SENT->value,
            ProductSearchStep::VIEW_RESPONSES->value,
            ProductSearchStep::RESPONSE_DETAIL->value,
            ProductSearchStep::SHOW_MY_REQUESTS->value,
            ProductSearchStep::SHOW_SHOP_LOCATION->value,
        ]);
    }

    /**
     * Start the flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);

        if (!$user) {
            $this->whatsApp->sendText($session->phone, "⚠️ Please register first to search for products.");
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Check if user has location
        if (!$user->hasLocation()) {
            $this->sessionManager->setFlowStep(
                $session,
                FlowType::PRODUCT_SEARCH,
                ProductSearchStep::ASK_LOCATION->value
            );
            $this->askLocation($session);
            return;
        }

        // Store user location
        $this->sessionManager->mergeTempData($session, [
            'user_lat' => $user->latitude,
            'user_lng' => $user->longitude,
        ]);

        // Clear previous search data
        $this->sessionManager->removeTempData($session, 'category');
        $this->sessionManager->removeTempData($session, 'description');
        $this->sessionManager->removeTempData($session, 'image_url');
        $this->sessionManager->removeTempData($session, 'radius');

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::PRODUCT_SEARCH,
            ProductSearchStep::ASK_CATEGORY->value
        );

        $this->askCategory($session);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = ProductSearchStep::tryFrom($session->current_step);

        if (!$step) {
            $this->start($session);
            return;
        }

        match ($step) {
            ProductSearchStep::ASK_CATEGORY => $this->handleCategorySelection($message, $session),
            ProductSearchStep::ASK_DESCRIPTION => $this->handleDescriptionInput($message, $session),
            ProductSearchStep::ASK_IMAGE => $this->handleImageInput($message, $session),
            ProductSearchStep::ASK_LOCATION => $this->handleLocationInput($message, $session),
            ProductSearchStep::SELECT_RADIUS => $this->handleRadiusSelection($message, $session),
            ProductSearchStep::CONFIRM_REQUEST => $this->handleConfirmation($message, $session),
            ProductSearchStep::REQUEST_SENT => $this->handlePostRequest($message, $session),
            ProductSearchStep::VIEW_RESPONSES => $this->handleResponseSelection($message, $session),
            ProductSearchStep::RESPONSE_DETAIL => $this->handleResponseAction($message, $session),
            ProductSearchStep::SHOW_MY_REQUESTS => $this->handleMyRequestSelection($message, $session),
            ProductSearchStep::SHOW_SHOP_LOCATION => $this->handleLocationAction($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Handle invalid input.
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $step = ProductSearchStep::tryFrom($session->current_step);

        match ($step) {
            ProductSearchStep::ASK_CATEGORY => $this->askCategory($session, true),
            ProductSearchStep::ASK_DESCRIPTION => $this->askDescription($session, true),
            ProductSearchStep::SELECT_RADIUS => $this->askRadius($session, true),
            ProductSearchStep::CONFIRM_REQUEST => $this->askConfirmation($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    protected function handleCategorySelection(IncomingMessage $message, ConversationSession $session): void
    {
        $category = null;

        if ($message->isListReply()) {
            $category = $message->getSelectionId();
        } elseif ($message->isText()) {
            $category = $this->matchCategory(strtolower(trim($message->text ?? '')));
        }

        if (!$category) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->sessionManager->setTempData($session, 'category', $category);

        $this->sessionManager->setStep($session, ProductSearchStep::ASK_DESCRIPTION->value);
        $this->askDescription($session);
    }

    protected function handleDescriptionInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $description = trim($message->text ?? '');

        if (mb_strlen($description) < 10) {
            $this->whatsApp->sendText(
                $session->phone,
                "⚠️ Please provide more details (at least 10 characters).\n\nExample: _Samsung Galaxy M34 5G, 6GB RAM, Green color_"
            );
            return;
        }

        if (mb_strlen($description) > 500) {
            $this->whatsApp->sendText(
                $session->phone,
                "⚠️ Description too long. Please keep it under 500 characters."
            );
            return;
        }

        $this->sessionManager->setTempData($session, 'description', $description);

        // Skip image step for now, go directly to radius
        $this->sessionManager->setStep($session, ProductSearchStep::SELECT_RADIUS->value);
        $this->askRadius($session);
    }

    protected function handleImageInput(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for skip
        if ($message->isText() && strtolower(trim($message->text ?? '')) === 'skip') {
            $this->sessionManager->setStep($session, ProductSearchStep::SELECT_RADIUS->value);
            $this->askRadius($session);
            return;
        }

        if (!$message->isImage()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $mediaId = $message->getMediaId();

        if ($mediaId) {
            $this->whatsApp->sendText($session->phone, "⏳ Uploading image...");

            $result = $this->mediaService->downloadAndStore($mediaId, 'requests');

            if ($result['success']) {
                $this->sessionManager->setTempData($session, 'image_url', $result['url']);
            }
        }

        $this->sessionManager->setStep($session, ProductSearchStep::SELECT_RADIUS->value);
        $this->askRadius($session);
    }

    protected function handleLocationInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isLocation()) {
            $this->whatsApp->sendText($session->phone, "📍 Please share your location using the button.");
            return;
        }

        $coords = $message->getCoordinates();

        if (!$coords) {
            $this->askLocation($session, true);
            return;
        }

        $this->sessionManager->mergeTempData($session, [
            'user_lat' => $coords['latitude'],
            'user_lng' => $coords['longitude'],
        ]);

        // Update user location
        $user = $this->sessionManager->getUser($session);
        if ($user) {
            $user->update([
                'latitude' => $coords['latitude'],
                'longitude' => $coords['longitude'],
            ]);
        }

        $this->sessionManager->setStep($session, ProductSearchStep::ASK_CATEGORY->value);
        $this->askCategory($session);
    }

    protected function handleRadiusSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $radius = null;

        if ($message->isButtonReply()) {
            $radius = (int) $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = trim($message->text ?? '');
            if (is_numeric($text)) {
                $radius = (int) $text;
            }
        }

        if (!$radius || $radius < 1 || $radius > 50) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->sessionManager->setTempData($session, 'radius', $radius);

        $this->sessionManager->setStep($session, ProductSearchStep::CONFIRM_REQUEST->value);
        $this->askConfirmation($session);
    }

    protected function handleConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (in_array($text, ['send', 'yes', 'confirm', '1'])) {
                $action = 'send';
            } elseif (in_array($text, ['edit', 'change', '2'])) {
                $action = 'edit';
            } elseif (in_array($text, ['cancel', 'no', '3'])) {
                $action = 'cancel';
            }
        }

        match ($action) {
            'send' => $this->createAndSendRequest($session),
            'edit' => $this->restartSearch($session),
            'cancel' => $this->cancelSearch($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    protected function handlePostRequest(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        }

        match ($action) {
            'view_responses' => $this->showResponses($session),
            'new_search' => $this->start($session),
            default => $this->goToMainMenu($session),
        };
    }

    protected function handleResponseSelection(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isListReply()) {
            $selectionId = $message->getSelectionId();

            if (str_starts_with($selectionId, 'response_')) {
                $responseId = (int) str_replace('response_', '', $selectionId);
                $this->sessionManager->setTempData($session, 'current_response_id', $responseId);
                $this->sessionManager->setStep($session, ProductSearchStep::RESPONSE_DETAIL->value);
                $this->showResponseDetail($session);
                return;
            }

            if ($selectionId === 'close_request') {
                $this->confirmCloseRequest($session);
                return;
            }
        }

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();

            match ($action) {
                'refresh' => $this->showResponses($session),
                'close' => $this->confirmCloseRequest($session),
                'menu' => $this->goToMainMenu($session),
                default => null,
            };
        }
    }

    protected function handleResponseAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        }

        match ($action) {
            'location' => $this->showShopLocation($session),
            'contact' => $this->showShopContact($session),
            'back' => $this->showResponses($session),
            default => $this->showResponses($session),
        };
    }

    protected function handleMyRequestSelection(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isListReply()) {
            $selectionId = $message->getSelectionId();

            if (str_starts_with($selectionId, 'my_request_')) {
                $requestId = (int) str_replace('my_request_', '', $selectionId);
                $this->sessionManager->setTempData($session, 'current_request_id', $requestId);
                $this->sessionManager->setStep($session, ProductSearchStep::VIEW_RESPONSES->value);
                $this->showResponses($session);
                return;
            }
        }

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();

            match ($action) {
                'new_search' => $this->start($session),
                default => $this->goToMainMenu($session),
            };
        }
    }

    protected function handleLocationAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'contact' => $this->showShopContact($session),
            'back' => $this->showResponseDetail($session),
            default => $this->showResponses($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Prompt Methods
    |--------------------------------------------------------------------------
    */

    protected function askCategory(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select a category from the list."
            : ProductMessages::ASK_CATEGORY;

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            '📦 Select Category',
            ProductMessages::getCategorySections()
        );
    }

    protected function askDescription(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please describe the product you're looking for in detail."
            : ProductMessages::ASK_DESCRIPTION;

        $this->whatsApp->sendText($session->phone, $message);
    }

    protected function askImage(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, ProductMessages::ASK_IMAGE);
    }

    protected function askLocation(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "📍 Please share your location to continue."
            : "📍 *Share Your Location*\n\nWe need your location to find nearby shops.";

        $this->whatsApp->requestLocation($session->phone, $message);
    }

    protected function askRadius(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select a search radius."
            : ProductMessages::ASK_RADIUS;

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            ProductMessages::getRadiusButtons()
        );
    }

    protected function askConfirmation(ConversationSession $session): void
    {
        $description = $this->sessionManager->getTempData($session, 'description');
        $category = $this->sessionManager->getTempData($session, 'category');
        $radius = $this->sessionManager->getTempData($session, 'radius', 5);
        $lat = $this->sessionManager->getTempData($session, 'user_lat');
        $lng = $this->sessionManager->getTempData($session, 'user_lng');

        // Count eligible shops
        $shopCount = $this->searchService->countEligibleShops($lat, $lng, $radius, $category);

        if ($shopCount === 0) {
            $message = ProductMessages::format(ProductMessages::NO_SHOPS_FOUND, [
                'category' => ProductMessages::getCategoryLabel($category ?? 'all'),
                'radius' => $radius,
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                [
                    ['id' => 'edit', 'title' => '🔄 Try Different'],
                    ['id' => 'menu', 'title' => '🏠 Main Menu'],
                ]
            );
            return;
        }

        $message = ProductMessages::format(ProductMessages::CONFIRM_REQUEST, [
            'description' => $description,
            'category' => ProductMessages::getCategoryLabel($category ?? 'all'),
            'radius' => $radius,
            'shop_count' => $shopCount,
        ]);

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            ProductMessages::getConfirmRequestButtons()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Action Methods
    |--------------------------------------------------------------------------
    */

    protected function createAndSendRequest(ConversationSession $session): void
    {
        try {
            $user = $this->sessionManager->getUser($session);

            $request = $this->searchService->createRequest($user, [
                'description' => $this->sessionManager->getTempData($session, 'description'),
                'category' => $this->sessionManager->getTempData($session, 'category'),
                'image_url' => $this->sessionManager->getTempData($session, 'image_url'),
                'latitude' => $this->sessionManager->getTempData($session, 'user_lat'),
                'longitude' => $this->sessionManager->getTempData($session, 'user_lng'),
                'radius_km' => $this->sessionManager->getTempData($session, 'radius', 5),
            ]);

            // Find and notify shops
            $shops = $this->searchService->findEligibleShops($request);
            $this->notifyShops($request, $shops);

            // Update notified count
            $this->searchService->updateShopsNotified($request, $shops->count());

            // Store current request ID
            $this->sessionManager->setTempData($session, 'current_request_id', $request->id);

            // Send success message
            $expiryHours = config('nearbuy.products.request_expiry_hours', 24);

            $message = ProductMessages::format(ProductMessages::REQUEST_SENT, [
                'request_number' => $request->request_number,
                'shop_count' => $shops->count(),
                'hours' => $expiryHours,
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                ProductMessages::getPostRequestButtons()
            );

            $this->sessionManager->setStep($session, ProductSearchStep::REQUEST_SENT->value);

            Log::info('Product request sent', [
                'request_id' => $request->id,
                'request_number' => $request->request_number,
                'shops_notified' => $shops->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create product request', [
                'error' => $e->getMessage(),
            ]);

            $this->whatsApp->sendText(
                $session->phone,
                "❌ Failed to send request. Please try again."
            );

            $this->start($session);
        }
    }

    protected function notifyShops(ProductRequest $request, $shops): void
    {
        foreach ($shops as $shop) {
            $owner = $shop->owner;

            if (!$owner) {
                continue;
            }

            $message = ProductMessages::format(ProductMessages::NEW_REQUEST_NOTIFICATION, [
                'description' => $request->description,
                'category' => ProductMessages::getCategoryLabel($request->category?->value ?? 'all'),
                'distance' => ProductMessages::formatDistance($shop->distance_km),
                'time_remaining' => ProductMessages::formatTimeRemaining($request->expires_at),
                'request_number' => $request->request_number,
            ]);

            // Send notification with reference image if available
            if ($request->image_url) {
                $this->whatsApp->sendImage($owner->phone, $request->image_url, $message);
            } else {
                $this->whatsApp->sendText($owner->phone, $message);
            }

            // Send response buttons
            $this->whatsApp->sendButtons(
                $owner->phone,
                ProductMessages::RESPOND_PROMPT,
                ProductMessages::getRespondChoiceButtons()
            );
        }
    }

    protected function showResponses(ConversationSession $session): void
    {
        $requestId = $this->sessionManager->getTempData($session, 'current_request_id');
        $request = ProductRequest::find($requestId);

        if (!$request) {
            $this->whatsApp->sendText($session->phone, "❌ Request not found.");
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
                    ['id' => 'menu', 'title' => '🏠 Main Menu'],
                ]
            );
            return;
        }

        // Filter to only available responses
        $availableResponses = $responses->where('is_available', true);

        $header = ProductMessages::format(ProductMessages::RESPONSES_HEADER, [
            'request_number' => $request->request_number,
            'description' => mb_substr($request->description, 0, 50),
            'response_count' => $availableResponses->count(),
        ]);

        $rows = [];
        foreach ($availableResponses as $response) {
            $shop = $response->shop;
            $price = number_format($response->price);
            $distance = ProductMessages::formatDistance($response->distance_km);

            $rows[] = [
                'id' => 'response_' . $response->id,
                'title' => mb_substr("₹{$price} - {$shop->shop_name}", 0, 24),
                'description' => mb_substr("{$distance} away", 0, 72),
            ];
        }

        $sections = [
            [
                'title' => 'Responses (by price)',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '💰 View Offers',
            $sections,
            null,
            "Sorted by price (lowest first)"
        );

        $this->sessionManager->setStep($session, ProductSearchStep::VIEW_RESPONSES->value);
    }

    protected function showResponseDetail(ConversationSession $session): void
    {
        $responseId = $this->sessionManager->getTempData($session, 'current_response_id');
        $requestId = $this->sessionManager->getTempData($session, 'current_request_id');
        $request = ProductRequest::find($requestId);

        if (!$request) {
            $this->showMyRequests($session);
            return;
        }

        $response = app(ProductResponseService::class)
            ->getResponseWithDistance($responseId, $request->latitude, $request->longitude);

        if (!$response) {
            $this->whatsApp->sendText($session->phone, "❌ Response not found.");
            $this->showResponses($session);
            return;
        }

        $shop = $response->shop;

        // Build card message
        $card = ProductMessages::format(ProductMessages::RESPONSE_CARD, [
            'shop_name' => $shop->shop_name,
            'distance' => ProductMessages::formatDistance($response->distance_km),
            'price' => number_format($response->price),
            'description' => $response->description ?? 'No additional details',
        ]);

        // Send image if available
        if ($response->image_url) {
            $this->whatsApp->sendImage($session->phone, $response->image_url, $card);
        } else {
            $this->whatsApp->sendText($session->phone, $card);
        }

        // Send action buttons
        $this->whatsApp->sendButtons(
            $session->phone,
            "What would you like to do?",
            ProductMessages::getResponseActionButtons()
        );

        $this->sessionManager->setStep($session, ProductSearchStep::RESPONSE_DETAIL->value);
    }

    protected function showShopLocation(ConversationSession $session): void
    {
        $responseId = $this->sessionManager->getTempData($session, 'current_response_id');
        $response = app(ProductResponseService::class)->getResponseWithShop($responseId);

        if (!$response || !$response->shop) {
            $this->showResponses($session);
            return;
        }

        $shop = $response->shop;

        $this->whatsApp->sendLocation(
            $session->phone,
            $shop->latitude,
            $shop->longitude,
            $shop->shop_name,
            $shop->address
        );

        $this->whatsApp->sendButtons(
            $session->phone,
            "📍 *{$shop->shop_name}*\n\nTap to open in maps.",
            [
                ['id' => 'contact', 'title' => '📞 Contact Shop'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );

        $this->sessionManager->setStep($session, ProductSearchStep::SHOW_SHOP_LOCATION->value);
    }

    protected function showShopContact(ConversationSession $session): void
    {
        $responseId = $this->sessionManager->getTempData($session, 'current_response_id');
        $response = app(ProductResponseService::class)->getResponseWithShop($responseId);

        if (!$response || !$response->shop) {
            $this->showResponses($session);
            return;
        }

        $shop = $response->shop;
        $owner = $shop->owner;
        $phone = $owner ? $owner->phone : 'Not available';

        $message = "📞 *Contact {$shop->shop_name}*\n\nPhone: {$phone}\n\nTap the number to call.";

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'location', 'title' => '📍 Get Location'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );
    }

    protected function showMyRequests(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);
        $requests = $this->searchService->getUserActiveRequests($user);

        if ($requests->isEmpty()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                ProductMessages::MY_REQUESTS_EMPTY,
                ProductMessages::getEmptyRequestsButtons()
            );
            return;
        }

        $header = ProductMessages::format(ProductMessages::MY_REQUESTS_HEADER, [
            'count' => $requests->count(),
        ]);

        $rows = [];
        foreach ($requests as $request) {
            $statusIcon = $request->status->value === 'open' ? '🟢' : '🟡';

            $rows[] = [
                'id' => 'my_request_' . $request->id,
                'title' => mb_substr($request->description, 0, 24),
                'description' => mb_substr("{$statusIcon} {$request->responses_count} responses • #{$request->request_number}", 0, 72),
            ];
        }

        $sections = [['title' => 'Your Requests', 'rows' => array_slice($rows, 0, 10)]];

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '📋 View Requests',
            $sections
        );

        $this->sessionManager->setStep($session, ProductSearchStep::SHOW_MY_REQUESTS->value);
    }

    protected function confirmCloseRequest(ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $session->phone,
            ProductMessages::CLOSE_REQUEST_CONFIRM,
            ProductMessages::getCloseRequestButtons()
        );
    }

    protected function restartSearch(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, "🔄 Let's start over.");
        $this->start($session);
    }

    protected function cancelSearch(ConversationSession $session): void
    {
        $this->sessionManager->clearTempData($session);
        $this->whatsApp->sendText($session->phone, "❌ Search cancelled.");
        $this->goToMainMenu($session);
    }

    protected function goToMainMenu(ConversationSession $session): void
    {
        $this->sessionManager->resetToMainMenu($session);
        app(MainMenuHandler::class)->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    protected function matchCategory(string $text): ?string
    {
        $categories = [
            'grocery' => ['grocery', 'grocer', 'kirana', 'supermarket'],
            'electronics' => ['electronics', 'electronic', 'gadget'],
            'clothes' => ['clothes', 'clothing', 'fashion', 'dress'],
            'medical' => ['medical', 'medicine', 'pharmacy', 'drug'],
            'mobile' => ['mobile', 'phone', 'smartphone'],
            'appliances' => ['appliance', 'appliances'],
            'furniture' => ['furniture'],
            'hardware' => ['hardware', 'tools'],
            'stationery' => ['stationery', 'book', 'books'],
            'all' => ['all', 'any', 'everything'],
        ];

        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }
}