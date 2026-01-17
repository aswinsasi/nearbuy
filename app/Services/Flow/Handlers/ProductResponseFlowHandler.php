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
 * Handles the product response flow for shop owners.
 *
 * Flow Steps:
 * 1. view_request - Show request details with choice buttons
 * 2. respond_availability - Yes/No/Skip choice
 * 3. respond_price - Enter price
 * 4. respond_image - Send product photo (optional)
 * 5. respond_notes - Add description (optional)
 * 6. confirm_response - Review and send
 * 7. response_sent - Success message
 */
class ProductResponseFlowHandler implements FlowHandlerInterface
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected ProductSearchService $searchService,
        protected ProductResponseService $responseService,
        protected MediaService $mediaService,
    ) {}

    /**
     * Get flow name.
     */
    public function getName(): string
    {
        return FlowType::PRODUCT_RESPOND->value;
    }

    /**
     * Check if can handle step.
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, [
            ProductSearchStep::VIEW_REQUEST->value,
            ProductSearchStep::RESPOND_AVAILABILITY->value,
            ProductSearchStep::RESPOND_PRICE->value,
            ProductSearchStep::RESPOND_IMAGE->value,
            ProductSearchStep::RESPOND_NOTES->value,
            ProductSearchStep::CONFIRM_RESPONSE->value,
            ProductSearchStep::RESPONSE_SENT->value,
        ]);
    }

    /**
     * Start the flow - show pending requests.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);

        if (!$user || !$user->isShopOwner()) {
            $this->whatsApp->sendText(
                $session->phone,
                "⚠️ Only shop owners can respond to product requests."
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        $shop = $user->shop;

        // Get pending requests for this shop
        $requests = $this->searchService->getPendingRequestsForShop($shop);

        if ($requests->isEmpty()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                ProductMessages::PENDING_REQUESTS_EMPTY,
                [
                    ['id' => 'menu', 'title' => '🏠 Main Menu'],
                ]
            );
            $this->sessionManager->resetToMainMenu($session);
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

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '📬 View Requests',
            $sections
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

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    protected function handleRequestSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $requestId = null;

        if ($message->isListReply()) {
            $selectionId = $message->getSelectionId();
            if (str_starts_with($selectionId, 'request_')) {
                $requestId = (int) str_replace('request_', '', $selectionId);
            }
        }

        if (!$requestId) {
            $this->start($session);
            return;
        }

        $user = $this->sessionManager->getUser($session);
        $shop = $user->shop;

        $request = $this->searchService->getRequestForShop($requestId, $shop);

        if (!$request) {
            $this->whatsApp->sendText($session->phone, "❌ Request not found or has expired.");
            $this->start($session);
            return;
        }

        // Check if already responded
        if ($this->responseService->hasAlreadyResponded($request, $shop)) {
            $existingResponse = $this->responseService->getShopResponse($request, $shop);
            $message = ProductMessages::format(ProductMessages::ALREADY_RESPONDED, [
                'price' => number_format($existingResponse->price ?? 0),
            ]);
            $this->whatsApp->sendText($session->phone, $message);
            $this->start($session);
            return;
        }

        // Check if request is still active
        if (!$this->searchService->acceptsResponses($request)) {
            $this->whatsApp->sendText($session->phone, ProductMessages::REQUEST_NO_LONGER_ACTIVE);
            $this->start($session);
            return;
        }

        // Store current request
        $this->sessionManager->setTempData($session, 'respond_request_id', $request->id);

        // Show request details
        $this->showRequestDetails($session, $request);
    }

    protected function handleAvailabilityChoice(IncomingMessage $message, ConversationSession $session): void
    {
        $choice = null;

        if ($message->isButtonReply()) {
            $choice = $message->getSelectionId();
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
            $this->whatsApp->sendText(
                $session->phone,
                "⚠️ Invalid price. Please enter a number.\n\nExample: _15000_ or _15000 - Black color, warranty included_"
            );
            return;
        }

        $this->sessionManager->setTempData($session, 'response_price', $parsed['price']);

        if ($parsed['description']) {
            $this->sessionManager->setTempData($session, 'response_description', $parsed['description']);
        }

        // Move to image step
        $this->sessionManager->setStep($session, ProductSearchStep::RESPOND_IMAGE->value);
        $this->askImage($session);
    }

    protected function handleImageInput(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for skip
        if ($message->isText() && strtolower(trim($message->text ?? '')) === 'skip') {
            $this->sessionManager->setStep($session, ProductSearchStep::RESPOND_NOTES->value);
            $this->askNotes($session);
            return;
        }

        if (!$message->isImage()) {
            $this->whatsApp->sendText(
                $session->phone,
                "📷 Please send a photo of the product, or type 'skip' to continue without one."
            );
            return;
        }

        $mediaId = $message->getMediaId();

        if ($mediaId) {
            $this->whatsApp->sendText($session->phone, "⏳ Uploading image...");

            $result = $this->mediaService->downloadAndStore($mediaId, 'responses');

            if ($result['success']) {
                $this->sessionManager->setTempData($session, 'response_image_url', $result['url']);
                $this->whatsApp->sendText($session->phone, "✅ Image uploaded!");
            } else {
                $this->whatsApp->sendText($session->phone, "⚠️ Image upload failed, but you can continue.");
            }
        }

        // Check if we already have description from price input
        $description = $this->sessionManager->getTempData($session, 'response_description');

        if ($description) {
            // Skip notes step, go to confirmation
            $this->sessionManager->setStep($session, ProductSearchStep::CONFIRM_RESPONSE->value);
            $this->askConfirmation($session);
        } else {
            $this->sessionManager->setStep($session, ProductSearchStep::RESPOND_NOTES->value);
            $this->askNotes($session);
        }
    }

    protected function handleNotesInput(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isText()) {
            $text = trim($message->text ?? '');

            if (strtolower($text) !== 'skip' && mb_strlen($text) > 0) {
                $this->sessionManager->setTempData($session, 'response_description', $text);
            }
        }

        // Move to confirmation
        $this->sessionManager->setStep($session, ProductSearchStep::CONFIRM_RESPONSE->value);
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
            'send' => $this->createAndSendResponse($session),
            'edit' => $this->restartResponse($session),
            'cancel' => $this->cancelResponse($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    protected function handlePostResponse(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

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
            $requestId = $this->sessionManager->getTempData($session, 'respond_request_id');
            $user = $this->sessionManager->getUser($session);
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
            $this->whatsApp->sendImage($session->phone, $request->image_url, $message);
        } else {
            $this->whatsApp->sendText($session->phone, $message);
        }

        // Send choice buttons
        $this->whatsApp->sendButtons(
            $session->phone,
            ProductMessages::RESPOND_PROMPT,
            ProductMessages::getRespondChoiceButtons()
        );

        $this->sessionManager->setStep($session, ProductSearchStep::RESPOND_AVAILABILITY->value);
    }

    protected function askPrice(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "⚠️ Invalid price. Please enter a number.\n\nExample: _15000_ or _15000 - Black color, warranty included_"
            : ProductMessages::ASK_PRICE;

        $this->whatsApp->sendText($session->phone, $message);
    }

    protected function askImage(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, ProductMessages::ASK_PHOTO);
    }

    protected function askNotes(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, ProductMessages::ASK_DETAILS);
    }

    protected function askConfirmation(ConversationSession $session): void
    {
        $price = $this->sessionManager->getTempData($session, 'response_price');
        $description = $this->sessionManager->getTempData($session, 'response_description');
        $imageUrl = $this->sessionManager->getTempData($session, 'response_image_url');

        $message = ProductMessages::format(ProductMessages::CONFIRM_RESPONSE, [
            'price' => number_format($price),
            'description' => $description ?: '(None)',
            'has_photo' => $imageUrl ? '✅ Included' : '❌ Not included',
        ]);

        // Show image preview if uploaded
        if ($imageUrl) {
            $this->whatsApp->sendImage($session->phone, $imageUrl);
        }

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            ProductMessages::getConfirmResponseButtons()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Action Methods
    |--------------------------------------------------------------------------
    */

    protected function startResponseFlow(ConversationSession $session): void
    {
        $this->sessionManager->setStep($session, ProductSearchStep::RESPOND_PRICE->value);
        $this->askPrice($session);
    }

    protected function createAndSendResponse(ConversationSession $session): void
    {
        try {
            $requestId = $this->sessionManager->getTempData($session, 'respond_request_id');
            $request = ProductRequest::find($requestId);

            if (!$request) {
                throw new \Exception('Request not found');
            }

            $user = $this->sessionManager->getUser($session);
            $shop = $user->shop;

            $response = $this->responseService->createResponse($request, $shop, [
                'price' => $this->sessionManager->getTempData($session, 'response_price'),
                'description' => $this->sessionManager->getTempData($session, 'response_description'),
                'image_url' => $this->sessionManager->getTempData($session, 'response_image_url'),
            ]);

            // Send success message
            $message = ProductMessages::format(ProductMessages::RESPONSE_SENT, [
                'price' => number_format($response->price),
                'request_number' => $request->request_number,
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                ProductMessages::getPostResponseButtons()
            );

            // Notify customer
            $this->notifyCustomer($request, $response, $shop);

            // Clear temp data
            $this->clearResponseTempData($session);

            $this->sessionManager->setStep($session, ProductSearchStep::RESPONSE_SENT->value);

            Log::info('Product response sent', [
                'response_id' => $response->id,
                'request_id' => $request->id,
                'shop_id' => $shop->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send response', [
                'error' => $e->getMessage(),
            ]);

            $this->whatsApp->sendText(
                $session->phone,
                "❌ Failed to send response: " . $e->getMessage()
            );

            $this->start($session);
        }
    }

    protected function createUnavailableResponse(ConversationSession $session): void
    {
        try {
            $requestId = $this->sessionManager->getTempData($session, 'respond_request_id');
            $request = ProductRequest::find($requestId);

            if (!$request) {
                throw new \Exception('Request not found');
            }

            $user = $this->sessionManager->getUser($session);
            $shop = $user->shop;

            $this->responseService->createUnavailableResponse($request, $shop);

            $this->whatsApp->sendText($session->phone, ProductMessages::RESPOND_NO_THANKS);

            // Clear and go to next request
            $this->clearResponseTempData($session);
            $this->start($session);

        } catch (\Exception $e) {
            Log::error('Failed to create unavailable response', [
                'error' => $e->getMessage(),
            ]);

            $this->start($session);
        }
    }

    protected function skipRequest(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, ProductMessages::RESPOND_SKIPPED);
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
            "Check all responses from the main menu.";

        // Send response image if available
        if ($response->image_url) {
            $this->whatsApp->sendImage($customer->phone, $response->image_url, $message);
        } else {
            $this->whatsApp->sendText($customer->phone, $message);
        }
    }

    protected function restartResponse(ConversationSession $session): void
    {
        // Keep the request ID, clear response data
        $requestId = $this->sessionManager->getTempData($session, 'respond_request_id');
        $this->clearResponseTempData($session);
        $this->sessionManager->setTempData($session, 'respond_request_id', $requestId);

        $this->whatsApp->sendText($session->phone, "🔄 Let's start over.");

        $this->startResponseFlow($session);
    }

    protected function cancelResponse(ConversationSession $session): void
    {
        $this->clearResponseTempData($session);
        $this->whatsApp->sendText($session->phone, "❌ Response cancelled.");
        $this->start($session);
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

    protected function clearResponseTempData(ConversationSession $session): void
    {
        $this->sessionManager->removeTempData($session, 'respond_request_id');
        $this->sessionManager->removeTempData($session, 'response_price');
        $this->sessionManager->removeTempData($session, 'response_description');
        $this->sessionManager->removeTempData($session, 'response_image_url');
    }
}