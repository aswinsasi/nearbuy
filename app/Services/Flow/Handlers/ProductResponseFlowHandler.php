<?php

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
 * ENHANCED Product Response Flow Handler.
 *
 * Key improvements:
 * 1. Extends AbstractFlowHandler for consistent menu buttons
 * 2. Uses sendTextWithMenu/sendButtonsWithMenu patterns
 * 3. Main Menu button on all messages
 */
class ProductResponseFlowHandler extends AbstractFlowHandler
{
    public function __construct(
        \App\Services\Session\SessionManager $sessionManager,
        \App\Services\WhatsApp\WhatsAppService $whatsApp,
        protected ProductSearchService $searchService,
        protected ProductResponseService $responseService,
        protected MediaService $mediaService,
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::PRODUCT_RESPOND;
    }

    protected function getSteps(): array
    {
        return [
            ProductSearchStep::VIEW_REQUEST->value,
            ProductSearchStep::RESPOND_AVAILABILITY->value,
            ProductSearchStep::RESPOND_PRICE->value,
            ProductSearchStep::RESPOND_IMAGE->value,
            ProductSearchStep::RESPOND_NOTES->value,
            ProductSearchStep::CONFIRM_RESPONSE->value,
            ProductSearchStep::RESPONSE_SENT->value,
        ];
    }

    /**
     * Start the flow - show pending requests.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user || !$user->isShopOwner()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "⚠️ *Shop Owner Required*\n\nOnly shop owners can respond to product requests.",
                [['id' => 'register', 'title' => '📝 Register Shop']]
            );
            $this->goToMainMenu($session);
            return;
        }

        $shop = $user->shop;

        // Get pending requests for this shop
        $requests = $this->searchService->getPendingRequestsForShop($shop);

        if ($requests->isEmpty()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                ProductMessages::PENDING_REQUESTS_EMPTY,
                []
            );
            $this->goToMainMenu($session);
            return;
        }

        $header = ProductMessages::format(ProductMessages::PENDING_REQUESTS_HEADER, [
            'count' => $requests->count(),
        ]);

        // Build list
        $rows = [];
        foreach ($requests as $request) {
            $distance = ProductMessages::formatDistance($request->distance_km);
            $timeRemaining = ProductMessages::formatTimeRemaining($request->expires_at);

            $rows[] = [
                'id' => 'request_' . $request->id,
                'title' => mb_substr($request->description, 0, 24),
                'description' => mb_substr("📍 {$distance} • ⏰ {$timeRemaining}", 0, 72),
            ];
        }

        $sections = [
            [
                'title' => 'Pending Requests',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];

        $this->sendListWithFooter(
            $session->phone,
            $header,
            '📬 View Requests',
            $sections,
            '📬 Product Requests'
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::PRODUCT_RESPOND,
            ProductSearchStep::VIEW_REQUEST->value
        );
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
            $this->start($session);
            return;
        }

        match ($step) {
            ProductSearchStep::VIEW_REQUEST => $this->handleRequestSelection($message, $session),
            ProductSearchStep::RESPOND_AVAILABILITY => $this->handleAvailabilityChoice($message, $session),
            ProductSearchStep::RESPOND_PRICE => $this->handlePriceInput($message, $session),
            ProductSearchStep::RESPOND_IMAGE => $this->handleImageInput($message, $session),
            ProductSearchStep::RESPOND_NOTES => $this->handleNotesInput($message, $session),
            ProductSearchStep::CONFIRM_RESPONSE => $this->handleConfirmation($message, $session),
            ProductSearchStep::RESPONSE_SENT => $this->handlePostResponse($message, $session),
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
            ProductSearchStep::RESPOND_PRICE => $this->askPrice($session, true),
            ProductSearchStep::CONFIRM_RESPONSE => $this->askConfirmation($session),
            default => $this->start($session),
        };
    }

    /**
     * Get expected input type.
     */
    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            ProductSearchStep::VIEW_REQUEST->value => 'list',
            ProductSearchStep::RESPOND_AVAILABILITY->value => 'button',
            ProductSearchStep::RESPOND_PRICE->value => 'text',
            ProductSearchStep::RESPOND_IMAGE->value => 'media',
            ProductSearchStep::RESPOND_NOTES->value => 'text',
            ProductSearchStep::CONFIRM_RESPONSE->value => 'button',
            default => 'button',
        };
    }

    /**
     * Re-prompt current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = ProductSearchStep::tryFrom($session->current_step);

        match ($step) {
            ProductSearchStep::VIEW_REQUEST => $this->start($session),
            ProductSearchStep::RESPOND_AVAILABILITY => $this->showRequestDetails($session),
            ProductSearchStep::RESPOND_PRICE => $this->askPrice($session),
            ProductSearchStep::RESPOND_IMAGE => $this->askImage($session),
            ProductSearchStep::RESPOND_NOTES => $this->askNotes($session),
            ProductSearchStep::CONFIRM_RESPONSE => $this->askConfirmation($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    protected function handleRequestSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $requestId = null;

        if ($message->isListReply()) {
            $selectionId = $this->getSelectionId($message);
            if (str_starts_with($selectionId, 'request_')) {
                $requestId = (int) str_replace('request_', '', $selectionId);
            }
        }

        if (!$requestId) {
            $this->start($session);
            return;
        }

        $user = $this->getUser($session);
        $shop = $user->shop;

        $request = $this->searchService->getRequestForShop($requestId, $shop);

        if (!$request) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Request not found or has expired.",
                [
                    ['id' => 'retry', 'title' => '🔄 View Requests'],
                    self::MENU_BUTTON,
                ]
            );
            $this->start($session);
            return;
        }

        // Check if already responded
        if ($this->responseService->hasAlreadyResponded($request, $shop)) {
            $existingResponse = $this->responseService->getShopResponse($request, $shop);
            $message = ProductMessages::format(ProductMessages::ALREADY_RESPONDED, [
                'price' => number_format($existingResponse->price ?? 0),
            ]);
            $this->sendButtonsWithMenu(
                $session->phone,
                $message,
                [['id' => 'more_requests', 'title' => '📬 More Requests']]
            );
            $this->start($session);
            return;
        }

        // Check if request is still active
        if (!$this->searchService->acceptsResponses($request)) {
            $this->sendButtonsWithMenu(
                $session->phone,
                ProductMessages::REQUEST_NO_LONGER_ACTIVE,
                [['id' => 'more_requests', 'title' => '📬 More Requests']]
            );
            $this->start($session);
            return;
        }

        // Store current request
        $this->setTemp($session, 'respond_request_id', $request->id);

        // Show request details
        $this->showRequestDetails($session, $request);
    }

    protected function handleAvailabilityChoice(IncomingMessage $message, ConversationSession $session): void
    {
        $choice = null;

        if ($message->isInteractive()) {
            $choice = $this->getSelectionId($message);
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (in_array($text, ['yes', 'have', 'available', '1'])) {
                $choice = 'yes';
            } elseif (in_array($text, ['no', 'dont', "don't", 'not', '2'])) {
                $choice = 'no';
            } elseif (in_array($text, ['skip', 'later', '3'])) {
                $choice = 'skip';
            }
        }

        match ($choice) {
            'yes' => $this->startResponseFlow($session),
            'no' => $this->createUnavailableResponse($session),
            'skip' => $this->skipRequest($session),
            default => $this->showRequestDetails($session),
        };
    }

    protected function handlePriceInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $input = trim($message->text ?? '');

        // Parse price and optional details
        $parsed = $this->responseService->parsePriceAndDetails($input);

        if (!$parsed['price']) {
            $this->sendErrorWithOptions(
                $session->phone,
                "⚠️ Invalid price. Please enter a number.\n\nExample: _15000_ or _15000 - Black color, warranty included_",
                [
                    ['id' => 'back', 'title' => '⬅️ Back'],
                    self::MENU_BUTTON,
                ]
            );
            return;
        }

        $this->setTemp($session, 'response_price', $parsed['price']);

        if ($parsed['description']) {
            $this->setTemp($session, 'response_description', $parsed['description']);
        }

        // Move to image step
        $this->nextStep($session, ProductSearchStep::RESPOND_IMAGE->value);
        $this->askImage($session);
    }

    protected function handleImageInput(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for skip
        if ($this->isSkip($message) || ($message->isText() && strtolower(trim($message->text ?? '')) === 'skip')) {
            $this->nextStep($session, ProductSearchStep::RESPOND_NOTES->value);
            $this->askNotes($session);
            return;
        }

        if (!$message->isImage()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "📷 Please send a photo of the product, or tap Skip to continue without one.",
                [['id' => 'skip', 'title' => '⏭️ Skip Photo']]
            );
            return;
        }

        $mediaId = $this->getMediaId($message);

        if ($mediaId) {
            $this->sendTextWithMenu($session->phone, "⏳ Uploading image...");

            $result = $this->mediaService->downloadAndStore($mediaId, 'responses');

            if ($result['success']) {
                $this->setTemp($session, 'response_image_url', $result['url']);
                $this->sendTextWithMenu($session->phone, "✅ Image uploaded!");
            } else {
                $this->sendTextWithMenu($session->phone, "⚠️ Image upload failed, but you can continue.");
            }
        }

        // Check if we already have description from price input
        $description = $this->getTemp($session, 'response_description');

        if ($description) {
            // Skip notes step, go to confirmation
            $this->nextStep($session, ProductSearchStep::CONFIRM_RESPONSE->value);
            $this->askConfirmation($session);
        } else {
            $this->nextStep($session, ProductSearchStep::RESPOND_NOTES->value);
            $this->askNotes($session);
        }
    }

    protected function handleNotesInput(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for skip
        if ($this->isSkip($message)) {
            $this->nextStep($session, ProductSearchStep::CONFIRM_RESPONSE->value);
            $this->askConfirmation($session);
            return;
        }

        if ($message->isText()) {
            $text = trim($message->text ?? '');

            if (strtolower($text) !== 'skip' && mb_strlen($text) > 0) {
                $this->setTemp($session, 'response_description', $text);
            }
        }

        // Move to confirmation
        $this->nextStep($session, ProductSearchStep::CONFIRM_RESPONSE->value);
        $this->askConfirmation($session);
    }

    protected function handleConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);
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
            'send' => $this->createAndSendResponse($session),
            'edit' => $this->restartResponse($session),
            'cancel' => $this->cancelResponse($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    protected function handlePostResponse(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

        match ($action) {
            'more_requests' => $this->start($session),
            default => $this->goToMainMenu($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Display Methods
    |--------------------------------------------------------------------------
    */

    protected function showRequestDetails(ConversationSession $session, ?ProductRequest $request = null): void
    {
        if (!$request) {
            $requestId = $this->getTemp($session, 'respond_request_id');
            $user = $this->getUser($session);
            $request = $this->searchService->getRequestForShop($requestId, $user->shop);
        }

        if (!$request) {
            $this->start($session);
            return;
        }

        $message = ProductMessages::format(ProductMessages::NEW_REQUEST_NOTIFICATION, [
            'description' => $request->description,
            'category' => ProductMessages::getCategoryLabel($request->category?->value ?? 'all'),
            'distance' => ProductMessages::formatDistance($request->distance_km),
            'time_remaining' => ProductMessages::formatTimeRemaining($request->expires_at),
            'request_number' => $request->request_number,
        ]);

        // Send request image if available
        if ($request->image_url) {
            $this->sendImage($session->phone, $request->image_url, $message);
        } else {
            $this->sendTextWithMenu($session->phone, $message);
        }

        // Send choice buttons with menu
        $this->sendButtonsWithMenu(
            $session->phone,
            ProductMessages::RESPOND_PROMPT,
            [
                ['id' => 'yes', 'title' => '✅ Yes, I Have It'],
                ['id' => 'no', 'title' => "❌ Don't Have"],
            ]
        );

        $this->nextStep($session, ProductSearchStep::RESPOND_AVAILABILITY->value);
    }

    protected function askPrice(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "⚠️ Invalid price. Please enter a number.\n\nExample: _15000_ or _15000 - Black color, warranty included_"
            : ProductMessages::ASK_PRICE;

        $this->sendButtonsWithMenu(
            $session->phone,
            $message,
            [['id' => 'back', 'title' => '⬅️ Back']]
        );
    }

    protected function askImage(ConversationSession $session): void
    {
        $this->sendButtonsWithMenu(
            $session->phone,
            ProductMessages::ASK_PHOTO,
            [['id' => 'skip', 'title' => '⏭️ Skip Photo']]
        );
    }

    protected function askNotes(ConversationSession $session): void
    {
        $this->sendButtonsWithMenu(
            $session->phone,
            ProductMessages::ASK_DETAILS,
            [['id' => 'skip', 'title' => '⏭️ Skip']]
        );
    }

    protected function askConfirmation(ConversationSession $session): void
    {
        $price = $this->getTemp($session, 'response_price');
        $description = $this->getTemp($session, 'response_description');
        $imageUrl = $this->getTemp($session, 'response_image_url');

        $message = ProductMessages::format(ProductMessages::RESPONSE_CONFIRM, [
            'request_description' => $this->getTemp($session, 'request_description') ?? 'Product request',
            'available' => 'Yes',
            'price' => number_format($price),
            'description' => $description ?: '(None)',
            'has_photo' => $imageUrl ? '✅ Included' : '❌ Not included',
        ]);

        // Show image preview if uploaded
        if ($imageUrl) {
            $this->sendImage($session->phone, $imageUrl);
        }

        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'send', 'title' => '📤 Send Response'],
                ['id' => 'edit', 'title' => '✏️ Edit'],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
            ],
            null,
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Action Methods
    |--------------------------------------------------------------------------
    */

    protected function startResponseFlow(ConversationSession $session): void
    {
        $this->nextStep($session, ProductSearchStep::RESPOND_PRICE->value);
        $this->askPrice($session);
    }

    protected function createAndSendResponse(ConversationSession $session): void
    {
        try {
            $requestId = $this->getTemp($session, 'respond_request_id');
            $request = ProductRequest::find($requestId);

            if (!$request) {
                throw new \Exception('Request not found');
            }

            $user = $this->getUser($session);
            $shop = $user->shop;

            $response = $this->responseService->createResponse($request, $shop, [
                'price' => $this->getTemp($session, 'response_price'),
                'description' => $this->getTemp($session, 'response_description'),
                'image_url' => $this->getTemp($session, 'response_image_url'),
            ]);

            // Send success message
            $message = ProductMessages::format(ProductMessages::RESPONSE_SENT, [
                'price' => number_format($response->price),
                'request_number' => $request->request_number,
            ]);

            $this->sendButtonsWithMenu(
                $session->phone,
                $message,
                [['id' => 'more_requests', 'title' => '📬 More Requests']]
            );

            // Notify customer
            $this->notifyCustomer($request, $response, $shop);

            // Clear temp data
            $this->clearResponseTempData($session);

            $this->nextStep($session, ProductSearchStep::RESPONSE_SENT->value);

            $this->logInfo('Product response sent', [
                'response_id' => $response->id,
                'request_id' => $request->id,
                'shop_id' => $shop->id,
            ]);

        } catch (\Exception $e) {
            $this->logError('Failed to send response', [
                'error' => $e->getMessage(),
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed to send response: " . $e->getMessage(),
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );

            $this->start($session);
        }
    }

    protected function createUnavailableResponse(ConversationSession $session): void
    {
        try {
            $requestId = $this->getTemp($session, 'respond_request_id');
            $request = ProductRequest::find($requestId);

            if (!$request) {
                throw new \Exception('Request not found');
            }

            $user = $this->getUser($session);
            $shop = $user->shop;

            $this->responseService->createUnavailableResponse($request, $shop);

            $this->sendButtonsWithMenu(
                $session->phone,
                ProductMessages::RESPOND_NO_THANKS,
                [['id' => 'more_requests', 'title' => '📬 More Requests']]
            );

            // Clear and go to next request
            $this->clearResponseTempData($session);
            $this->start($session);

        } catch (\Exception $e) {
            $this->logError('Failed to create unavailable response', [
                'error' => $e->getMessage(),
            ]);

            $this->start($session);
        }
    }

    protected function skipRequest(ConversationSession $session): void
    {
        $this->sendButtonsWithMenu(
            $session->phone,
            ProductMessages::RESPOND_SKIPPED,
            [['id' => 'more_requests', 'title' => '📬 More Requests']]
        );
        $this->clearResponseTempData($session);
        $this->start($session);
    }

    protected function notifyCustomer(ProductRequest $request, $response, $shop): void
    {
        $customer = $request->user;

        if (!$customer) {
            return;
        }

        $message = "🔔 *New Response to #{$request->request_number}*\n\n" .
            "🏪 *{$shop->shop_name}* has responded:\n\n" .
            "💰 *Price:* ₹" . number_format($response->price) . "\n\n" .
            ($response->description ? "📝 *Details:* {$response->description}\n\n" : "") .
            "Check all responses from the main menu.";

        // Send response image if available
        if ($response->photo_url) {
            $this->sendImage($customer->phone, $response->photo_url, $message);
            
            // Send buttons separately after image
            $this->sendButtonsWithMenu(
                $customer->phone,
                "What would you like to do?",
                [['id' => 'view_responses', 'title' => '📬 View All Responses']]
            );
        } else {
            $this->sendButtonsWithMenu(
                $customer->phone,
                $message,
                [['id' => 'view_responses', 'title' => '📬 View All Responses']]
            );
        }
    }

    protected function restartResponse(ConversationSession $session): void
    {
        // Keep the request ID, clear response data
        $requestId = $this->getTemp($session, 'respond_request_id');
        $this->clearResponseTempData($session);
        $this->setTemp($session, 'respond_request_id', $requestId);

        $this->sendTextWithMenu($session->phone, "🔄 Let's start over.");

        $this->startResponseFlow($session);
    }

    protected function cancelResponse(ConversationSession $session): void
    {
        $this->clearResponseTempData($session);
        $this->sendTextWithMenu($session->phone, "❌ Response cancelled.");
        $this->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    protected function clearResponseTempData(ConversationSession $session): void
    {
        $this->sessionManager->removeTempData($session, 'respond_request_id');
        $this->sessionManager->removeTempData($session, 'response_price');
        $this->sessionManager->removeTempData($session, 'response_description');
        $this->sessionManager->removeTempData($session, 'response_image_url');
    }
}