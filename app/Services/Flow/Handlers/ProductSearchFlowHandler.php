<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\ProductSearchStep;
use App\Models\ConversationSession;
use App\Models\ProductRequest;
use App\Services\Media\MediaService;
use App\Services\Products\ProductResponseService;
use App\Services\Products\ProductSearchService;
use App\Services\WhatsApp\Messages\MessageTemplates;
use App\Services\WhatsApp\Messages\ProductMessages;

/**
 * ENHANCED Product Search Flow Handler.
 *
 * Key improvements:
 * 1. Extends AbstractFlowHandler for consistent menu buttons
 * 2. Uses sendTextWithMenu/sendButtonsWithMenu patterns
 * 3. Consistent footer on all messages
 * 4. Menu button under every message
 *
 * @see SRS Section 3.3 - Product Search
 */
class ProductSearchFlowHandler extends AbstractFlowHandler
{
    protected const DEFAULT_RADIUS_KM = 5;
    protected const DEFAULT_EXPIRY_HOURS = 24;
    protected const MIN_DESCRIPTION_LENGTH = 10;
    protected const MAX_DESCRIPTION_LENGTH = 500;

    public function __construct(
        \App\Services\Session\SessionManager $sessionManager,
        \App\Services\WhatsApp\WhatsAppService $whatsApp,
        protected ProductSearchService $searchService,
        protected MediaService $mediaService,
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::PRODUCT_SEARCH;
    }

    protected function getSteps(): array
    {
        return [
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
        ];
    }

    /**
     * Start the product search flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "⚠️ *Registration Required*\n\nPlease register first to search for products.",
                [['id' => 'register', 'title' => '📝 Register']]
            );
            $this->goToMainMenu($session);
            return;
        }

        // Check if user has location
        if (!$this->hasValidLocation($user)) {
            $this->sessionManager->setFlowStep(
                $session,
                FlowType::PRODUCT_SEARCH,
                ProductSearchStep::ASK_LOCATION->value
            );
            $this->askLocation($session);
            return;
        }

        // Initialize session with user location
        $this->setTemp($session, 'user_lat', $user->latitude);
        $this->setTemp($session, 'user_lng', $user->longitude);

        // Clear previous search data
        $this->clearSearchData($session);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::PRODUCT_SEARCH,
            ProductSearchStep::ASK_CATEGORY->value
        );

        $this->askCategory($session);

        $this->logInfo('Product search started', [
            'phone' => $this->maskPhone($session->phone),
            'user_id' => $user->id,
        ]);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle common navigation (menu, cancel, etc.)
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = ProductSearchStep::tryFrom($session->current_step);

        if (!$step) {
            $this->logError('Invalid product search step', [
                'step' => $session->current_step,
            ]);
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
     * Handle invalid input with helpful re-prompting.
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

    /**
     * Get expected input type.
     */
    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            ProductSearchStep::ASK_CATEGORY->value => 'list',
            ProductSearchStep::ASK_DESCRIPTION->value => 'text',
            ProductSearchStep::ASK_LOCATION->value => 'location',
            ProductSearchStep::SELECT_RADIUS->value => 'button',
            ProductSearchStep::CONFIRM_REQUEST->value => 'button',
            default => 'text',
        };
    }

    /**
     * Re-prompt current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = ProductSearchStep::tryFrom($session->current_step);

        match ($step) {
            ProductSearchStep::ASK_CATEGORY => $this->askCategory($session),
            ProductSearchStep::ASK_DESCRIPTION => $this->askDescription($session),
            ProductSearchStep::SELECT_RADIUS => $this->askRadius($session),
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
        $category = $this->extractCategory($message);

        if (!$category) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->setTemp($session, 'category', $category);

        $this->logInfo('Category selected', [
            'category' => $category,
            'phone' => $this->maskPhone($session->phone),
        ]);

        $this->nextStep($session, ProductSearchStep::ASK_DESCRIPTION->value);
        $this->askDescription($session);
    }

    protected function handleDescriptionInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $description = trim($message->text ?? '');

        // Validate length
        if (mb_strlen($description) < self::MIN_DESCRIPTION_LENGTH) {
            $this->sendErrorWithOptions(
                $session->phone,
                ProductMessages::ERROR_INVALID_DESCRIPTION,
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );
            return;
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $this->sendErrorWithOptions(
                $session->phone,
                "⚠️ Description too long. Please keep it under " . self::MAX_DESCRIPTION_LENGTH . " characters.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );
            return;
        }

        $this->setTemp($session, 'description', $description);

        $this->logInfo('Description entered', [
            'length' => mb_strlen($description),
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Skip image step, go directly to radius selection
        $this->nextStep($session, ProductSearchStep::SELECT_RADIUS->value);
        $this->askRadius($session);
    }

    protected function handleImageInput(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for skip
        if ($this->isSkip($message)) {
            $this->nextStep($session, ProductSearchStep::SELECT_RADIUS->value);
            $this->askRadius($session);
            return;
        }

        if ($message->isImage()) {
            $mediaId = $message->getMediaId();

            if ($mediaId) {
                $this->sendTextWithMenu($session->phone, "⏳ Uploading image...");

                try {
                    $result = $this->mediaService->downloadAndStore($mediaId, 'requests');

                    if ($result['success']) {
                        $this->setTemp($session, 'image_url', $result['url']);
                        $this->sendTextWithMenu($session->phone, "✅ Image uploaded!");
                    }
                } catch (\Exception $e) {
                    $this->logError('Image upload failed', ['error' => $e->getMessage()]);
                    $this->sendTextWithMenu($session->phone, "⚠️ Image upload failed. Continuing without image.");
                }
            }
        }

        $this->nextStep($session, ProductSearchStep::SELECT_RADIUS->value);
        $this->askRadius($session);
    }

    protected function handleLocationInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isLocation()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "📍 Please share your location using the button below.",
                []
            );
            return;
        }

        $coords = $message->getCoordinates();

        if (!$coords) {
            $this->askLocation($session, true);
            return;
        }

        // Store location
        $this->setTemp($session, 'user_lat', $coords['latitude']);
        $this->setTemp($session, 'user_lng', $coords['longitude']);

        // Update user profile
        $user = $this->getUser($session);
        if ($user) {
            $user->update([
                'latitude' => $coords['latitude'],
                'longitude' => $coords['longitude'],
            ]);
        }

        // Continue to category selection
        $this->nextStep($session, ProductSearchStep::ASK_CATEGORY->value);
        $this->askCategory($session);
    }

    protected function handleRadiusSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $radius = $this->extractRadius($message);

        if (!$radius) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->setTemp($session, 'radius', $radius);

        // Move to confirmation
        $this->nextStep($session, ProductSearchStep::CONFIRM_REQUEST->value);
        $this->askConfirmation($session);
    }

    protected function handleConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $this->extractConfirmationAction($message);

        match ($action) {
            'send' => $this->createAndSendRequest($session),
            'edit' => $this->restartSearch($session),
            'cancel' => $this->cancelSearch($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    protected function handlePostRequest(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

        match ($action) {
            'view_responses' => $this->showResponses($session),
            'new_search' => $this->start($session),
            default => $this->goToMainMenu($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Response Handling
    |--------------------------------------------------------------------------
    */

    protected function handleResponseSelection(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isListReply()) {
            $selectionId = $this->getSelectionId($message);

            if (str_starts_with($selectionId, 'response_')) {
                $responseId = (int) str_replace('response_', '', $selectionId);
                $this->viewResponseDetail($session, $responseId);
                return;
            }

            if ($selectionId === 'close_request') {
                $this->confirmCloseRequest($session);
                return;
            }
        }

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);

            match ($action) {
                'refresh' => $this->showResponses($session),
                'close' => $this->confirmCloseRequest($session),
                default => null,
            };
            return;
        }

        $this->showResponses($session);
    }

    protected function handleResponseAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

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
            $selectionId = $this->getSelectionId($message);

            if (str_starts_with($selectionId, 'my_request_') || str_starts_with($selectionId, 'request_')) {
                $requestId = (int) preg_replace('/^(my_request_|request_)/', '', $selectionId);
                $this->setTemp($session, 'current_request_id', $requestId);
                $this->nextStep($session, ProductSearchStep::VIEW_RESPONSES->value);
                $this->showResponses($session);
                return;
            }
        }

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);

            match ($action) {
                'new_search' => $this->start($session),
                default => $this->goToMainMenu($session),
            };
            return;
        }

        $this->showMyRequests($session);
    }

    protected function handleLocationAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

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

        $this->sendListWithFooter(
            $session->phone,
            $message,
            '📦 Select Category',
            ProductMessages::getCategorySections(),
            '🔍 Product Search'
        );
    }

    protected function askDescription(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? ProductMessages::ERROR_INVALID_DESCRIPTION
            : ProductMessages::ASK_DESCRIPTION;

        $this->sendButtonsWithMenu(
            $session->phone,
            $message,
            [],
            '📝 Describe Product'
        );
    }

    protected function askImage(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            ProductMessages::ASK_IMAGE,
            [
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                self::MENU_BUTTON,
            ],
            '📷 Reference Image',
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    protected function askLocation(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "📍 Please share your location to continue."
            : ProductMessages::ERROR_NO_LOCATION;

        $this->requestLocation($session->phone, $message);
        
        // Send follow-up with menu
        $this->sendButtonsWithMenu(
            $session->phone,
            "📍 Share your location to find nearby shops.",
            []
        );
    }

    protected function askRadius(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select a search radius."
            : ProductMessages::ASK_RADIUS;

        $buttons = ProductMessages::getRadiusButtons();
        
        // Ensure room for menu button
        if (count($buttons) >= 3) {
            $buttons = array_slice($buttons, 0, 2);
        }
        $buttons[] = self::MENU_BUTTON;

        $this->sendButtons(
            $session->phone,
            $message,
            $buttons,
            '📏 Search Radius',
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    protected function askConfirmation(ConversationSession $session): void
    {
        $description = $this->getTemp($session, 'description');
        $category = $this->getTemp($session, 'category');
        $radius = $this->getTemp($session, 'radius', self::DEFAULT_RADIUS_KM);
        $lat = $this->getTemp($session, 'user_lat');
        $lng = $this->getTemp($session, 'user_lng');

        // Identify eligible shops by category and proximity
        $shopCount = $this->searchService->countEligibleShops($lat, $lng, $radius, $category);

        if ($shopCount === 0) {
            $this->showNoShopsMessage($session, $category, $radius);
            return;
        }

        $message = ProductMessages::format(ProductMessages::CONFIRM_REQUEST, [
            'description' => $description,
            'category' => ProductMessages::getCategoryLabel($category ?? 'all'),
            'radius' => $radius,
            'shop_count' => $shopCount,
        ]);

        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'send', 'title' => '✅ Send Request'],
                ['id' => 'edit', 'title' => '✏️ Edit'],
                self::MENU_BUTTON,
            ],
            '📋 Confirm Request',
            MessageTemplates::GLOBAL_FOOTER
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
            $user = $this->getUser($session);

            $request = $this->searchService->createRequest($user, [
                'description' => $this->getTemp($session, 'description'),
                'category' => $this->getTemp($session, 'category'),
                'image_url' => $this->getTemp($session, 'image_url'),
                'latitude' => $this->getTemp($session, 'user_lat'),
                'longitude' => $this->getTemp($session, 'user_lng'),
                'radius_km' => $this->getTemp($session, 'radius', self::DEFAULT_RADIUS_KM),
            ]);

            // Identify eligible shops by category and proximity
            $shops = $this->searchService->findEligibleShops($request);

            // Notify shops
            $this->notifyShops($request, $shops);

            // Update shops notified count
            $this->searchService->updateShopsNotified($request, $shops->count());

            // Store current request ID
            $this->setTemp($session, 'current_request_id', $request->id);

            $expiryHours = config('nearbuy.products.request_expiry_hours', self::DEFAULT_EXPIRY_HOURS);

            $message = ProductMessages::format(ProductMessages::REQUEST_SENT, [
                'request_number' => $request->request_number,
                'shop_count' => $shops->count(),
                'hours' => $expiryHours,
            ]);

            $this->sendButtonsWithMenu(
                $session->phone,
                $message,
                [
                    ['id' => 'view_responses', 'title' => '📬 View Responses'],
                    ['id' => 'new_search', 'title' => '🔍 New Search'],
                ],
                '✅ Request Sent!'
            );

            $this->nextStep($session, ProductSearchStep::REQUEST_SENT->value);

            $this->logInfo('Product request created', [
                'request_id' => $request->id,
                'request_number' => $request->request_number,
                'shops_notified' => $shops->count(),
                'phone' => $this->maskPhone($session->phone),
            ]);

        } catch (\Exception $e) {
            $this->logError('Failed to create product request', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed to send request. Please try again.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
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
                'distance' => ProductMessages::formatDistance($shop->distance_km ?? 0),
                'time_remaining' => ProductMessages::formatTimeRemaining($request->expires_at),
                'request_number' => $request->request_number,
            ]);

            // Send with reference image if available
            if ($request->image_url) {
                $this->sendImage($owner->phone, $request->image_url, $message);
            } else {
                $this->sendTextWithMenu($owner->phone, $message);
            }

            // Provide response options
            $this->sendButtons(
                $owner->phone,
                ProductMessages::RESPOND_PROMPT,
                [
                    ['id' => 'yes', 'title' => '✅ Yes, I Have It'],
                    ['id' => 'no', 'title' => "❌ Don't Have"],
                    self::MENU_BUTTON,
                ],
                null,
                MessageTemplates::GLOBAL_FOOTER
            );
        }
    }

    protected function showResponses(ConversationSession $session): void
    {
        $requestId = $this->getTemp($session, 'current_request_id');
        $request = ProductRequest::find($requestId);

        if (!$request) {
            $this->sendTextWithMenu($session->phone, ProductMessages::ERROR_REQUEST_NOT_FOUND);
            $this->showMyRequests($session);
            return;
        }

        // Aggregate responses
        $responses = $this->searchService->getResponses($request);

        if ($responses->isEmpty()) {
            $message = ProductMessages::format(ProductMessages::NO_RESPONSES, [
                'request_number' => $request->request_number,
            ]);

            $this->sendButtonsWithMenu(
                $session->phone,
                $message,
                [['id' => 'refresh', 'title' => '🔄 Refresh']],
                '📬 Responses'
            );
            return;
        }

        // Sort responses by price (lowest first)
        $availableResponses = $responses->where('is_available', true)->sortBy('price');

        $header = ProductMessages::format(ProductMessages::RESPONSES_HEADER, [
            'request_number' => $request->request_number,
            'description' => ProductMessages::truncate($request->description, 50),
            'response_count' => $availableResponses->count(),
        ]);

        // Present responses via list message with price and shop info
        $rows = [];
        $lowestPrice = $availableResponses->first()?->price;

        foreach ($availableResponses as $response) {
            $shop = $response->shop;
            $price = number_format($response->price);
            $distance = ProductMessages::formatDistance($response->distance_km ?? 0);

            // Highlight best price
            $priceLabel = "₹{$price}";
            if ($response->price === $lowestPrice && $availableResponses->count() > 1) {
                $priceLabel .= ' ⭐';
            }

            $rows[] = [
                'id' => 'response_' . $response->id,
                'title' => ProductMessages::truncate("{$priceLabel} - {$shop->shop_name}", 24),
                'description' => ProductMessages::truncate("{$distance} away", 72),
            ];
        }

        $sections = [
            [
                'title' => 'Responses (lowest price first)',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];

        $this->sendListWithFooter(
            $session->phone,
            $header,
            '💰 View Offers',
            $sections,
            '📬 Responses'
        );

        $this->nextStep($session, ProductSearchStep::VIEW_RESPONSES->value);
    }

    protected function viewResponseDetail(ConversationSession $session, int $responseId): void
    {
        $this->setTemp($session, 'current_response_id', $responseId);
        $this->nextStep($session, ProductSearchStep::RESPONSE_DETAIL->value);
        $this->showResponseDetail($session);
    }

    protected function showResponseDetail(ConversationSession $session): void
    {
        $responseId = $this->getTemp($session, 'current_response_id');
        $requestId = $this->getTemp($session, 'current_request_id');
        $request = ProductRequest::find($requestId);

        if (!$request) {
            $this->showMyRequests($session);
            return;
        }

        $response = app(ProductResponseService::class)
            ->getResponseWithDistance($responseId, $request->latitude, $request->longitude);

        if (!$response) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Response not found.",
                [
                    ['id' => 'back', 'title' => '⬅️ Back'],
                    self::MENU_BUTTON,
                ]
            );
            $this->showResponses($session);
            return;
        }

        $shop = $response->shop;

        // Send product photo and details upon selection
        $card = ProductMessages::format(
            $response->description ? ProductMessages::RESPONSE_CARD : ProductMessages::RESPONSE_CARD_NO_DESC,
            [
                'shop_name' => $shop->shop_name,
                'distance' => ProductMessages::formatDistance($response->distance_km ?? 0),
                'price' => number_format($response->price),
                'description' => $response->description ?? '',
            ]
        );

        // Send image if available
        if ($response->image_url) {
            $this->sendImage($session->phone, $response->image_url, $card);
        } else {
            $this->sendTextWithMenu($session->phone, $card);
        }

        // Provide Get Location and Call Shop options
        $this->sendButtonsWithMenu(
            $session->phone,
            "What would you like to do?",
            [
                ['id' => 'location', 'title' => '📍 Get Location'],
                ['id' => 'contact', 'title' => '📞 Call Shop'],
            ]
        );
    }

    protected function showShopLocation(ConversationSession $session): void
    {
        $responseId = $this->getTemp($session, 'current_response_id');
        $response = app(ProductResponseService::class)->getResponseWithShop($responseId);

        if (!$response || !$response->shop) {
            $this->showResponses($session);
            return;
        }

        $shop = $response->shop;

        $this->sendLocation(
            $session->phone,
            (float) $shop->latitude,
            (float) $shop->longitude,
            $shop->shop_name,
            $shop->address
        );

        $this->sendButtonsWithMenu(
            $session->phone,
            "📍 *{$shop->shop_name}*\n\nTap to open in maps.",
            [
                ['id' => 'contact', 'title' => '📞 Call Shop'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );

        $this->nextStep($session, ProductSearchStep::SHOW_SHOP_LOCATION->value);
    }

    protected function showShopContact(ConversationSession $session): void
    {
        $responseId = $this->getTemp($session, 'current_response_id');
        $response = app(ProductResponseService::class)->getResponseWithShop($responseId);

        if (!$response || !$response->shop) {
            $this->showResponses($session);
            return;
        }

        $shop = $response->shop;
        $owner = $shop->owner;
        $phone = $owner?->phone ?? 'Not available';

        $message = "📞 *Contact {$shop->shop_name}*\n\nPhone: {$phone}\n\n_Tap to call_";

        $this->sendButtonsWithMenu(
            $session->phone,
            $message,
            [
                ['id' => 'location', 'title' => '📍 Get Location'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ],
            '📞 Contact'
        );
    }

    protected function showMyRequests(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $requests = $this->searchService->getUserActiveRequests($user);

        if ($requests->isEmpty()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                ProductMessages::MY_REQUESTS_EMPTY,
                [['id' => 'new_search', 'title' => '🔍 New Search']],
                '📋 My Requests'
            );
            return;
        }

        $header = ProductMessages::format(ProductMessages::MY_REQUESTS_HEADER, [
            'count' => $requests->count(),
        ]);

        $rows = [];
        foreach ($requests as $request) {
            $statusEmoji = match ($request->status->value) {
                'open' => '🟢',
                'collecting' => '🟡',
                'closed' => '✅',
                'expired' => '⏰',
                default => '📋',
            };

            $rows[] = [
                'id' => 'my_request_' . $request->id,
                'title' => ProductMessages::truncate($request->description, 24),
                'description' => ProductMessages::truncate(
                    "{$statusEmoji} {$request->responses_count} responses • #{$request->request_number}",
                    72
                ),
            ];
        }

        $sections = [['title' => 'Your Requests', 'rows' => array_slice($rows, 0, 10)]];

        $this->sendListWithFooter(
            $session->phone,
            $header,
            '📋 View Requests',
            $sections,
            '📋 My Requests'
        );

        $this->nextStep($session, ProductSearchStep::SHOW_MY_REQUESTS->value);
    }

    protected function confirmCloseRequest(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            ProductMessages::CLOSE_REQUEST_CONFIRM,
            [
                ['id' => 'confirm_close', 'title' => '✅ Yes, Close'],
                ['id' => 'cancel_close', 'title' => '❌ No, Keep Open'],
                self::MENU_BUTTON,
            ],
            '⚠️ Close Request',
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    protected function showNoShopsMessage(ConversationSession $session, ?string $category, int $radius): void
    {
        $message = ProductMessages::format(ProductMessages::NO_SHOPS_FOUND, [
            'category' => ProductMessages::getCategoryLabel($category ?? 'all'),
            'radius' => $radius,
        ]);

        $this->sendButtonsWithMenu(
            $session->phone,
            $message,
            [['id' => 'edit', 'title' => '🔄 Try Different']],
            '😕 No Shops Found'
        );
    }

    protected function restartSearch(ConversationSession $session): void
    {
        $this->sendTextWithMenu($session->phone, "🔄 Let's start over.");
        $this->clearSearchData($session);
        $this->start($session);
    }

    protected function cancelSearch(ConversationSession $session): void
    {
        $this->clearSearchData($session);
        $this->sendTextWithMenu($session->phone, "❌ Search cancelled.");
        $this->goToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    protected function clearSearchData(ConversationSession $session): void
    {
        $keysToRemove = ['category', 'description', 'image_url', 'radius', 'current_request_id', 'current_response_id'];

        foreach ($keysToRemove as $key) {
            $this->sessionManager->removeTempData($session, $key);
        }
    }

    protected function hasValidLocation($user): bool
    {
        return $user->latitude !== null
            && $user->longitude !== null
            && abs((float) $user->latitude) <= 90
            && abs((float) $user->longitude) <= 180;
    }

    protected function extractCategory(IncomingMessage $message): ?string
    {
        if ($message->isListReply()) {
            return $this->getSelectionId($message);
        }

        if ($message->isText()) {
            return $this->matchCategory(strtolower(trim($message->text ?? '')));
        }

        return null;
    }

    protected function extractRadius(IncomingMessage $message): ?int
    {
        $value = null;

        if ($message->isInteractive()) {
            $value = (int) $this->getSelectionId($message);
        } elseif ($message->isText()) {
            $text = trim($message->text ?? '');
            if (is_numeric($text)) {
                $value = (int) $text;
            }
        }

        if ($value && $value >= 1 && $value <= 50) {
            return $value;
        }

        return null;
    }

    protected function extractConfirmationAction(IncomingMessage $message): ?string
    {
        if ($message->isInteractive()) {
            return $this->getSelectionId($message);
        }

        if ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));

            if (in_array($text, ['send', 'yes', 'confirm', 'ok', '1'])) {
                return 'send';
            }
            if (in_array($text, ['edit', 'change', '2'])) {
                return 'edit';
            }
            if (in_array($text, ['cancel', 'no', 'stop', '3'])) {
                return 'cancel';
            }
        }

        return null;
    }

    protected function matchCategory(string $text): ?string
    {
        $mappings = [
            'grocery' => ['grocery', 'grocer', 'kirana', 'supermarket'],
            'electronics' => ['electronics', 'electronic', 'gadget', 'computer', 'laptop'],
            'clothes' => ['clothes', 'clothing', 'fashion', 'dress', 'garment'],
            'medical' => ['medical', 'medicine', 'pharmacy', 'drug', 'chemist'],
            'mobile' => ['mobile', 'phone', 'smartphone'],
            'appliances' => ['appliance', 'appliances', 'electrical'],
            'furniture' => ['furniture', 'wood', 'sofa'],
            'hardware' => ['hardware', 'tools', 'building'],
            'stationery' => ['stationery', 'book', 'books', 'office'],
            'all' => ['all', 'any', 'everything'],
            'other' => ['other', 'misc'],
        ];

        foreach ($mappings as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }
}