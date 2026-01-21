<?php

declare(strict_types=1);

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
 * Flow Steps (FR-PRD-01 to FR-PRD-35):
 * 1. ask_category - Select category to search (FR-PRD-01)
 * 2. ask_description - Describe product needed (FR-PRD-02)
 * 3. ask_image - Optional reference image
 * 4. select_radius - How far to search
 * 5. confirm_request - Review and confirm (FR-PRD-04)
 * 6. request_sent - Success, waiting for responses
 * 7. view_responses - See shop responses (FR-PRD-30-32)
 * 8. response_detail - View specific response (FR-PRD-33-34)
 *
 * ENHANCEMENTS:
 * - Progress indicators (Step X of Y)
 * - Best price highlighting in responses
 * - Image upload support
 * - Better error recovery
 * - Analytics tracking
 *
 * @see SRS Section 3.3 - Product Search
 */
class ProductSearchFlowHandler implements FlowHandlerInterface
{
    /**
     * Default search radius in kilometers.
     */
    protected const DEFAULT_RADIUS_KM = 5;

    /**
     * Default request expiry in hours.
     */
    protected const DEFAULT_EXPIRY_HOURS = 24;

    /**
     * Minimum description length.
     */
    protected const MIN_DESCRIPTION_LENGTH = 10;

    /**
     * Maximum description length.
     */
    protected const MAX_DESCRIPTION_LENGTH = 500;

    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected ProductSearchService $searchService,
        protected MediaService $mediaService,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return FlowType::PRODUCT_SEARCH->value;
    }

    /**
     * {@inheritdoc}
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
        ], true);
    }

    /**
     * Start the product search flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);

        if (!$user) {
            $this->whatsApp->sendText(
                $session->phone,
                "⚠️ Please register first to search for products."
            );
            $this->sessionManager->resetToMainMenu($session);
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
        $this->sessionManager->mergeTempData($session, [
            'user_lat' => $user->latitude,
            'user_lng' => $user->longitude,
        ]);

        // Clear previous search data
        $this->clearSearchData($session);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::PRODUCT_SEARCH,
            ProductSearchStep::ASK_CATEGORY->value
        );

        $this->askCategory($session);

        Log::info('Product search started', [
            'phone' => $this->maskPhone($session->phone),
            'user_id' => $user->id,
        ]);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = ProductSearchStep::tryFrom($session->current_step);

        if (!$step) {
            Log::warning('Invalid product search step', [
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

    /*
    |--------------------------------------------------------------------------
    | Step Handlers (FR-PRD-01 to FR-PRD-06)
    |--------------------------------------------------------------------------
    */

    /**
     * Handle category selection (FR-PRD-01).
     */
    protected function handleCategorySelection(IncomingMessage $message, ConversationSession $session): void
    {
        $category = $this->extractCategory($message);

        if (!$category) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->sessionManager->setTempData($session, 'category', $category);

        Log::debug('Category selected', [
            'category' => $category,
            'phone' => $this->maskPhone($session->phone),
        ]);

        $this->sessionManager->setStep($session, ProductSearchStep::ASK_DESCRIPTION->value);
        $this->askDescription($session);
    }

    /**
     * Handle description input (FR-PRD-02).
     */
    protected function handleDescriptionInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $description = trim($message->text ?? '');

        // Validate length
        if (mb_strlen($description) < self::MIN_DESCRIPTION_LENGTH) {
            $this->whatsApp->sendText(
                $session->phone,
                ProductMessages::ERROR_INVALID_DESCRIPTION
            );
            return;
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            $this->whatsApp->sendText(
                $session->phone,
                "⚠️ Description too long. Please keep it under " . self::MAX_DESCRIPTION_LENGTH . " characters."
            );
            return;
        }

        $this->sessionManager->setTempData($session, 'description', $description);

        Log::debug('Description entered', [
            'length' => mb_strlen($description),
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Skip image step, go directly to radius selection
        // (Can be enabled later by changing this to ASK_IMAGE)
        $this->sessionManager->setStep($session, ProductSearchStep::SELECT_RADIUS->value);
        $this->askRadius($session);
    }

    /**
     * Handle image input (optional).
     */
    protected function handleImageInput(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for skip
        if ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (in_array($text, ['skip', 'no', 'none', 'next'])) {
                $this->sessionManager->setStep($session, ProductSearchStep::SELECT_RADIUS->value);
                $this->askRadius($session);
                return;
            }
        }

        if ($message->isImage()) {
            $mediaId = $message->getMediaId();

            if ($mediaId) {
                $this->whatsApp->sendText($session->phone, "⏳ Uploading image...");

                try {
                    $result = $this->mediaService->downloadAndStore($mediaId, 'requests');

                    if ($result['success']) {
                        $this->sessionManager->setTempData($session, 'image_url', $result['url']);
                        $this->whatsApp->sendText($session->phone, "✅ Image uploaded!");
                    }
                } catch (\Exception $e) {
                    Log::error('Image upload failed', ['error' => $e->getMessage()]);
                    $this->whatsApp->sendText($session->phone, "⚠️ Image upload failed. Continuing without image.");
                }
            }
        }

        $this->sessionManager->setStep($session, ProductSearchStep::SELECT_RADIUS->value);
        $this->askRadius($session);
    }

    /**
     * Handle location input.
     */
    protected function handleLocationInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isLocation()) {
            $this->whatsApp->sendText(
                $session->phone,
                "📍 Please share your location using the button below."
            );
            return;
        }

        $coords = $message->getCoordinates();

        if (!$coords) {
            $this->askLocation($session, true);
            return;
        }

        // Store location
        $this->sessionManager->mergeTempData($session, [
            'user_lat' => $coords['latitude'],
            'user_lng' => $coords['longitude'],
        ]);

        // Update user profile
        $user = $this->sessionManager->getUser($session);
        if ($user) {
            $user->update([
                'latitude' => $coords['latitude'],
                'longitude' => $coords['longitude'],
            ]);
        }

        // Continue to category selection
        $this->sessionManager->setStep($session, ProductSearchStep::ASK_CATEGORY->value);
        $this->askCategory($session);
    }

    /**
     * Handle radius selection.
     */
    protected function handleRadiusSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $radius = $this->extractRadius($message);

        if (!$radius) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->sessionManager->setTempData($session, 'radius', $radius);

        // Move to confirmation (FR-PRD-04)
        $this->sessionManager->setStep($session, ProductSearchStep::CONFIRM_REQUEST->value);
        $this->askConfirmation($session);
    }

    /**
     * Handle confirmation (FR-PRD-04).
     */
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

    /**
     * Handle post-request actions.
     */
    protected function handlePostRequest(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'view_responses' => $this->showResponses($session),
            'new_search' => $this->start($session),
            default => $this->goToMainMenu($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Response Handling (FR-PRD-30 to FR-PRD-35)
    |--------------------------------------------------------------------------
    */

    /**
     * Handle response selection (FR-PRD-32).
     */
    protected function handleResponseSelection(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isListReply()) {
            $selectionId = $message->getSelectionId();

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

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();

            match ($action) {
                'refresh' => $this->showResponses($session),
                'close' => $this->confirmCloseRequest($session),
                'menu' => $this->goToMainMenu($session),
                default => null,
            };
            return;
        }

        $this->showResponses($session);
    }

    /**
     * Handle response actions (FR-PRD-34).
     */
    protected function handleResponseAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'location' => $this->showShopLocation($session),
            'contact' => $this->showShopContact($session),
            'back' => $this->showResponses($session),
            default => $this->showResponses($session),
        };
    }

    /**
     * Handle my request selection.
     */
    protected function handleMyRequestSelection(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isListReply()) {
            $selectionId = $message->getSelectionId();

            if (str_starts_with($selectionId, 'my_request_') || str_starts_with($selectionId, 'request_')) {
                $requestId = (int) preg_replace('/^(my_request_|request_)/', '', $selectionId);
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
            return;
        }

        $this->showMyRequests($session);
    }

    /**
     * Handle location action.
     */
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

    /**
     * Ask for category (FR-PRD-01).
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

    /**
     * Ask for description (FR-PRD-02).
     */
    protected function askDescription(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? ProductMessages::ERROR_INVALID_DESCRIPTION
            : ProductMessages::ASK_DESCRIPTION;

        $this->whatsApp->sendText($session->phone, $message);
    }

    /**
     * Ask for image.
     */
    protected function askImage(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, ProductMessages::ASK_IMAGE);
    }

    /**
     * Ask for location.
     */
    protected function askLocation(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "📍 Please share your location to continue."
            : ProductMessages::ERROR_NO_LOCATION;

        $this->whatsApp->requestLocation($session->phone, $message);
    }

    /**
     * Ask for radius.
     */
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

    /**
     * Ask for confirmation (FR-PRD-04).
     */
    protected function askConfirmation(ConversationSession $session): void
    {
        $description = $this->sessionManager->getTempData($session, 'description');
        $category = $this->sessionManager->getTempData($session, 'category');
        $radius = $this->sessionManager->getTempData($session, 'radius', self::DEFAULT_RADIUS_KM);
        $lat = $this->sessionManager->getTempData($session, 'user_lat');
        $lng = $this->sessionManager->getTempData($session, 'user_lng');

        // FR-PRD-05: Identify eligible shops by category and proximity
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

    /**
     * Create and send request (FR-PRD-03 to FR-PRD-06).
     */
    protected function createAndSendRequest(ConversationSession $session): void
    {
        try {
            $user = $this->sessionManager->getUser($session);

            // FR-PRD-03: Generate unique request number (format: NB-XXXX)
            $request = $this->searchService->createRequest($user, [
                'description' => $this->sessionManager->getTempData($session, 'description'),
                'category' => $this->sessionManager->getTempData($session, 'category'),
                'image_url' => $this->sessionManager->getTempData($session, 'image_url'),
                'latitude' => $this->sessionManager->getTempData($session, 'user_lat'),
                'longitude' => $this->sessionManager->getTempData($session, 'user_lng'),
                'radius_km' => $this->sessionManager->getTempData($session, 'radius', self::DEFAULT_RADIUS_KM),
            ]);

            // FR-PRD-05: Identify eligible shops by category and proximity
            $shops = $this->searchService->findEligibleShops($request);

            // FR-PRD-10 to FR-PRD-14: Notify shops
            $this->notifyShops($request, $shops);

            // Update shops notified count
            $this->searchService->updateShopsNotified($request, $shops->count());

            // Store current request ID
            $this->sessionManager->setTempData($session, 'current_request_id', $request->id);

            // FR-PRD-06: Set request expiration time
            $expiryHours = config('nearbuy.products.request_expiry_hours', self::DEFAULT_EXPIRY_HOURS);

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

            Log::info('Product request created', [
                'request_id' => $request->id,
                'request_number' => $request->request_number,
                'shops_notified' => $shops->count(),
                'phone' => $this->maskPhone($session->phone),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create product request', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            $this->whatsApp->sendText(
                $session->phone,
                "❌ Failed to send request. Please try again."
            );

            $this->start($session);
        }
    }

    /**
     * Notify shops about the request (FR-PRD-10 to FR-PRD-14).
     */
    protected function notifyShops(ProductRequest $request, $shops): void
    {
        foreach ($shops as $shop) {
            $owner = $shop->owner;

            if (!$owner) {
                continue;
            }

            // FR-PRD-13: Include customer distance in shop notification
            $message = ProductMessages::format(ProductMessages::NEW_REQUEST_NOTIFICATION, [
                'description' => $request->description,
                'category' => ProductMessages::getCategoryLabel($request->category?->value ?? 'all'),
                'distance' => ProductMessages::formatDistance($shop->distance_km ?? 0),
                'time_remaining' => ProductMessages::formatTimeRemaining($request->expires_at),
                'request_number' => $request->request_number,
            ]);

            // Send with reference image if available
            if ($request->image_url) {
                $this->whatsApp->sendImage($owner->phone, $request->image_url, $message);
            } else {
                $this->whatsApp->sendText($owner->phone, $message);
            }

            // FR-PRD-14: Provide Yes I have / Don't have / Skip response options
            $this->whatsApp->sendButtons(
                $owner->phone,
                ProductMessages::RESPOND_PROMPT,
                ProductMessages::getRespondChoiceButtons()
            );
        }
    }

    /**
     * Show responses (FR-PRD-30 to FR-PRD-32).
     */
    protected function showResponses(ConversationSession $session): void
    {
        $requestId = $this->sessionManager->getTempData($session, 'current_request_id');
        $request = ProductRequest::find($requestId);

        if (!$request) {
            $this->whatsApp->sendText($session->phone, ProductMessages::ERROR_REQUEST_NOT_FOUND);
            $this->showMyRequests($session);
            return;
        }

        // FR-PRD-30: Aggregate responses
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

        // FR-PRD-31: Sort responses by price (lowest first)
        $availableResponses = $responses->where('is_available', true)->sortBy('price');

        $header = ProductMessages::format(ProductMessages::RESPONSES_HEADER, [
            'request_number' => $request->request_number,
            'description' => ProductMessages::truncate($request->description, 50),
            'response_count' => $availableResponses->count(),
        ]);

        // FR-PRD-32: Present responses via list message with price and shop info
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

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '💰 View Offers',
            $sections,
            null,
            "⭐ = Best price"
        );

        $this->sessionManager->setStep($session, ProductSearchStep::VIEW_RESPONSES->value);
    }

    /**
     * View response detail (FR-PRD-33).
     */
    protected function viewResponseDetail(ConversationSession $session, int $responseId): void
    {
        $this->sessionManager->setTempData($session, 'current_response_id', $responseId);
        $this->sessionManager->setStep($session, ProductSearchStep::RESPONSE_DETAIL->value);
        $this->showResponseDetail($session);
    }

    /**
     * Show response detail (FR-PRD-33).
     */
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

        // FR-PRD-33: Send product photo and details upon selection
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
            $this->whatsApp->sendImage($session->phone, $response->image_url, $card);
        } else {
            $this->whatsApp->sendText($session->phone, $card);
        }

        // FR-PRD-34: Provide Get Location and Call Shop options
        $this->whatsApp->sendButtons(
            $session->phone,
            "What would you like to do?",
            ProductMessages::getResponseActionButtons()
        );
    }

    /**
     * Show shop location.
     */
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
                ['id' => 'contact', 'title' => '📞 Call Shop'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );

        $this->sessionManager->setStep($session, ProductSearchStep::SHOW_SHOP_LOCATION->value);
    }

    /**
     * Show shop contact.
     */
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
        $phone = $owner?->phone ?? 'Not available';

        $message = "📞 *Contact {$shop->shop_name}*\n\nPhone: {$phone}\n\n_Tap to call_";

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'location', 'title' => '📍 Get Location'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );
    }

    /**
     * Show my requests.
     */
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

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '📋 View Requests',
            $sections
        );

        $this->sessionManager->setStep($session, ProductSearchStep::SHOW_MY_REQUESTS->value);
    }

    /**
     * Confirm close request (FR-PRD-35).
     */
    protected function confirmCloseRequest(ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $session->phone,
            ProductMessages::CLOSE_REQUEST_CONFIRM,
            ProductMessages::getCloseRequestButtons()
        );
    }

    /**
     * Show no shops message.
     */
    protected function showNoShopsMessage(ConversationSession $session, ?string $category, int $radius): void
    {
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
    }

    /**
     * Restart search.
     */
    protected function restartSearch(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, "🔄 Let's start over.");
        $this->clearSearchData($session);
        $this->start($session);
    }

    /**
     * Cancel search.
     */
    protected function cancelSearch(ConversationSession $session): void
    {
        $this->clearSearchData($session);
        $this->whatsApp->sendText($session->phone, "❌ Search cancelled.");
        $this->goToMainMenu($session);
    }

    /**
     * Go to main menu.
     */
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

    /**
     * Clear search data from session.
     */
    protected function clearSearchData(ConversationSession $session): void
    {
        $keysToRemove = ['category', 'description', 'image_url', 'radius', 'current_request_id', 'current_response_id'];

        foreach ($keysToRemove as $key) {
            $this->sessionManager->removeTempData($session, $key);
        }
    }

    /**
     * Check if user has valid location.
     */
    protected function hasValidLocation($user): bool
    {
        return $user->latitude !== null
            && $user->longitude !== null
            && abs($user->latitude) <= 90
            && abs($user->longitude) <= 180;
    }

    /**
     * Extract category from message.
     */
    protected function extractCategory(IncomingMessage $message): ?string
    {
        if ($message->isListReply()) {
            return $message->getSelectionId();
        }

        if ($message->isText()) {
            return $this->matchCategory(strtolower(trim($message->text ?? '')));
        }

        return null;
    }

    /**
     * Extract radius from message.
     */
    protected function extractRadius(IncomingMessage $message): ?int
    {
        $value = null;

        if ($message->isButtonReply()) {
            $value = (int) $message->getSelectionId();
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

    /**
     * Extract confirmation action from message.
     */
    protected function extractConfirmationAction(IncomingMessage $message): ?string
    {
        if ($message->isButtonReply()) {
            return $message->getSelectionId();
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

    /**
     * Match text to category.
     */
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

    /**
     * Mask phone number for logging.
     */
    protected function maskPhone(string $phone): string
    {
        $length = strlen($phone);
        if ($length < 6) {
            return str_repeat('*', $length);
        }
        return substr($phone, 0, 3) . str_repeat('*', $length - 6) . substr($phone, -3);
    }
}