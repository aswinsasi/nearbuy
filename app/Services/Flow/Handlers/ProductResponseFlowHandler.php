<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\ProductRequest;
use App\Models\ProductResponse;
use App\Services\Media\MediaService;
use App\Services\Products\ProductResponseService;
use App\Services\Products\ProductSearchService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Product Response Flow Handler (Shop Side).
 *
 * SIMPLIFIED 2-STEP RESPONSE FLOW:
 * 1. "💰 Price/model entha?" → free text (FR-PRD-21)
 * 2. "📸 Photo undo?" → [Send Photo] [Skip] (FR-PRD-20)
 * → "✅ Response ayachittund!" (FR-PRD-22)
 *
 * Entry points:
 * - Notification button click (yes_{id}/no_{id}/skip_{id})
 * - "Product Requests" menu option
 *
 * @srs-ref FR-PRD-20 to FR-PRD-23
 */
class ProductResponseFlowHandler implements FlowHandlerInterface
{
    /** Flow steps */
    protected const STEP_VIEW_REQUESTS = 'view_requests';
    protected const STEP_ASK_PRICE = 'ask_price';
    protected const STEP_ASK_PHOTO = 'ask_photo';
    protected const STEP_DONE = 'done';

    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected ProductSearchService $searchService,
        protected ProductResponseService $responseService,
        protected MediaService $mediaService,
    ) {}

    public function getName(): string
    {
        return FlowType::PRODUCT_RESPOND->value;
    }

    public function canHandleStep(string $step): bool
    {
        return in_array($step, [
            self::STEP_VIEW_REQUESTS,
            self::STEP_ASK_PRICE,
            self::STEP_ASK_PHOTO,
            self::STEP_DONE,
        ], true);
    }

    public function getExpectedInputType(string $step): string
    {
        return match ($step) {
            self::STEP_VIEW_REQUESTS => 'list',
            self::STEP_ASK_PRICE => 'text',
            self::STEP_ASK_PHOTO => 'image_or_button',
            default => 'button',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Entry Points
    |--------------------------------------------------------------------------
    */

    /**
     * Start from menu - show pending requests.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user || !$user->isShopOwner()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                "⚠️ Shop owners mathram respond cheyyaan pattoo.",
                [
                    ['id' => 'register', 'title' => '📝 Register Shop'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        $this->showPendingRequests($session);
    }

    /**
     * Start from notification button click.
     *
     * Called when shop clicks yes_{id}, no_{id}, or skip_{id}.
     *
     * @param string $action 'yes', 'no', or 'skip'
     * @param int $requestId Product request ID
     */
    public function startWithRequest(ConversationSession $session, int $requestId, string $action = 'yes'): void
    {
        $user = $this->getUser($session);

        if (!$user || !$user->isShopOwner()) {
            $this->whatsApp->sendText($session->phone, "⚠️ Shop owners only.");
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        $shop = $user->shop;
        $request = ProductRequest::find($requestId);

        if (!$request) {
            $this->whatsApp->sendText($session->phone, "❌ Request kandilla or expired.");
            $this->showPendingRequests($session);
            return;
        }

        // FR-PRD-23: Check for duplicate
        if ($this->responseService->hasAlreadyResponded($request, $shop)) {
            $existing = $this->responseService->getShopResponse($request, $shop);
            $price = $existing?->price ? '₹' . number_format((float) $existing->price) : 'N/A';

            $this->whatsApp->sendButtons(
                $session->phone,
                "⚠️ *Already respond cheythittund*\n\nYour price: {$price}",
                [
                    ['id' => 'more_requests', 'title' => '📬 More Requests'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        // Check if still accepting
        if (!$request->isOpen()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                "⚠️ *Request closed aayi*\n\nExpired or customer closed.",
                [
                    ['id' => 'more_requests', 'title' => '📬 More Requests'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        // Store context
        $this->sessionManager->mergeTempData($session, [
            'request_id' => $requestId,
            'request_desc' => $request->description,
        ]);

        // Set flow
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::PRODUCT_RESPOND,
            self::STEP_ASK_PRICE
        );

        // Handle action
        match ($action) {
            'yes' => $this->askPrice($session),
            'no' => $this->createNoResponse($session, $request, $shop),
            'skip' => $this->skipRequest($session),
            default => $this->askPrice($session),
        };

        Log::info('Shop response started', [
            'request_id' => $requestId,
            'action' => $action,
            'shop_id' => $shop->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Main Handler
    |--------------------------------------------------------------------------
    */

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = $session->current_step;

        match ($step) {
            self::STEP_VIEW_REQUESTS => $this->handleRequestSelection($message, $session),
            self::STEP_ASK_PRICE => $this->handlePriceInput($message, $session),
            self::STEP_ASK_PHOTO => $this->handlePhotoInput($message, $session),
            self::STEP_DONE => $this->handlePostResponse($message, $session),
            default => $this->start($session),
        };
    }

    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $step = $session->current_step;

        match ($step) {
            self::STEP_ASK_PRICE => $this->askPrice($session, true),
            self::STEP_ASK_PHOTO => $this->askPhoto($session),
            default => $this->start($session),
        };
    }

    public function handleTimeout(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, "⏰ Session expired.");
        $this->sessionManager->resetToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: View Pending Requests
    |--------------------------------------------------------------------------
    */

    protected function handleRequestSelection(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isListReply()) {
            $this->showPendingRequests($session);
            return;
        }

        $id = $message->getSelectionId();

        if (str_starts_with($id, 'req_')) {
            $requestId = (int) str_replace('req_', '', $id);
            $this->startWithRequest($session, $requestId, 'yes');
            return;
        }

        $this->showPendingRequests($session);
    }

    protected function showPendingRequests(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $shop = $user?->shop;

        if (!$shop) {
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        $requests = $this->searchService->getRequestsForShop($shop, 10);

        if ($requests->isEmpty()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                "📭 *Requests onnum illa*\n\nCustomers undaakumbol notify cheyyaam.",
                [['id' => 'menu', 'title' => '🏠 Menu']]
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Build list
        $rows = [];
        foreach ($requests as $req) {
            $distance = $this->formatDistance($req->distance_km ?? 0);
            $rows[] = [
                'id' => 'req_' . $req->id,
                'title' => $this->truncate($req->description, 24),
                'description' => "📍 {$distance} • ⏰ {$req->time_remaining}",
            ];
        }

        $this->whatsApp->sendList(
            $session->phone,
            "📬 *{$requests->count()} Product Request(s)*\n\nRespond cheyyaan select cheyyuka:",
            '📬 View Requests',
            [['title' => 'Pending', 'rows' => array_slice($rows, 0, 10)]],
            'Product Requests'
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::PRODUCT_RESPOND,
            self::STEP_VIEW_REQUESTS
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Price Input (FR-PRD-21)
    |--------------------------------------------------------------------------
    */

    protected function handlePriceInput(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for cancel/back
        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
            if (in_array($action, ['cancel', 'back', 'menu', 'main_menu'])) {
                $this->clearTempData($session);
                $this->sessionManager->resetToMainMenu($session);
                return;
            }
        }

        if (!$message->isText()) {
            $this->askPrice($session, true);
            return;
        }

        $input = trim($message->text ?? '');

        // FR-PRD-21: Parse price and model info
        $parsed = $this->responseService->parsePriceAndDetails($input);

        if (!$parsed['price']) {
            $this->whatsApp->sendButtons(
                $session->phone,
                "⚠️ Valid price enter cheyyuka.\n\n_Eg: 1500 or 1500, Samsung model_",
                [
                    ['id' => 'cancel', 'title' => '❌ Cancel'],
                ]
            );
            return;
        }

        // Store price and optional description
        $this->sessionManager->setTempData($session, 'price', $parsed['price']);
        if ($parsed['description']) {
            $this->sessionManager->setTempData($session, 'description', $parsed['description']);
        }

        // Move to photo step
        $this->sessionManager->setStep($session, self::STEP_ASK_PHOTO);
        $this->askPhoto($session);
    }

    /**
     * Ask for price.
     *
     * @srs-ref FR-PRD-21 - Collect price and model info via free-text
     */
    protected function askPrice(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "⚠️ Valid price enter cheyyuka.\n\n_Eg: 1500 or 1500, Samsung model_"
            : "💰 *Price/model entha?*\n\n_Eg: 1500 or 1500, Samsung model_";

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [['id' => 'cancel', 'title' => '❌ Cancel']]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Photo Input (FR-PRD-20)
    |--------------------------------------------------------------------------
    */

    protected function handlePhotoInput(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for skip
        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();

            if ($action === 'skip') {
                // No photo, create response
                $this->createResponse($session);
                return;
            }

            if (in_array($action, ['cancel', 'menu', 'main_menu'])) {
                $this->clearTempData($session);
                $this->sessionManager->resetToMainMenu($session);
                return;
            }
        }

        // Check for text "skip"
        if ($message->isText() && strtolower(trim($message->text ?? '')) === 'skip') {
            $this->createResponse($session);
            return;
        }

        // FR-PRD-20: Handle photo upload
        if ($message->isImage()) {
            $mediaId = $message->getMediaId();

            if ($mediaId) {
                $this->whatsApp->sendText($session->phone, "⏳ Photo uploading...");

                try {
                    $result = $this->mediaService->downloadAndStore($mediaId, 'responses');

                    if ($result['success']) {
                        $this->sessionManager->setTempData($session, 'photo_url', $result['url']);
                        $this->whatsApp->sendText($session->phone, "✅ Photo saved!");
                    }
                } catch (\Exception $e) {
                    Log::error('Photo upload failed', ['error' => $e->getMessage()]);
                    $this->whatsApp->sendText($session->phone, "⚠️ Photo upload failed, continuing...");
                }
            }

            // Create response with photo
            $this->createResponse($session);
            return;
        }

        // Invalid input, re-prompt
        $this->askPhoto($session);
    }

    /**
     * Ask for photo.
     *
     * @srs-ref FR-PRD-20 - Prompt for product photo
     */
    protected function askPhoto(ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $session->phone,
            "📸 *Photo undo? (optional)*\n\nSend photo or skip.",
            [
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Response Creation (FR-PRD-22)
    |--------------------------------------------------------------------------
    */

    /**
     * Create and send response.
     *
     * @srs-ref FR-PRD-22 - Store response with photo URL, price, description
     */
    protected function createResponse(ConversationSession $session): void
    {
        try {
            $requestId = $this->sessionManager->getTempData($session, 'request_id');
            $request = ProductRequest::find($requestId);

            if (!$request) {
                throw new \Exception('Request not found');
            }

            $user = $this->getUser($session);
            $shop = $user->shop;

            $price = (float) $this->sessionManager->getTempData($session, 'price');
            $description = $this->sessionManager->getTempData($session, 'description');
            $photoUrl = $this->sessionManager->getTempData($session, 'photo_url');

            $response = $this->responseService->createResponse($request, $shop, [
                'price' => $price,
                'description' => $description,
                'photo_url' => $photoUrl,
            ]);

            // Success message
            $this->whatsApp->sendButtons(
                $session->phone,
                "✅ *Response ayachittund!*\n\n💰 ₹" . number_format($price) . "\nCustomer-nu ariyikkaam 👍",
                [
                    ['id' => 'more_requests', 'title' => '📬 More Requests'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );

            // Notify customer
            $this->notifyCustomer($request, $response, $shop);

            // Clear and set done
            $this->clearTempData($session);
            $this->sessionManager->setStep($session, self::STEP_DONE);

            Log::info('Product response sent', [
                'response_id' => $response->id,
                'request_id' => $request->id,
                'shop_id' => $shop->id,
                'price' => $price,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create response', ['error' => $e->getMessage()]);

            $this->whatsApp->sendButtons(
                $session->phone,
                "❌ Response send cheyyaan pattiyilla.\n\n{$e->getMessage()}",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );

            $this->start($session);
        }
    }

    /**
     * Create "don't have" response.
     */
    protected function createNoResponse(ConversationSession $session, ProductRequest $request, $shop): void
    {
        try {
            $this->responseService->createUnavailableResponse($request, $shop);

            $this->whatsApp->sendButtons(
                $session->phone,
                "👍 Noted. Ee request pinne kaanilla.",
                [
                    ['id' => 'more_requests', 'title' => '📬 More Requests'],
                    ['id' => 'menu', 'title' => '🏠 Menu'],
                ]
            );

            $this->clearTempData($session);
            $this->showPendingRequests($session);

        } catch (\Exception $e) {
            Log::error('Failed to create no response', ['error' => $e->getMessage()]);
            $this->showPendingRequests($session);
        }
    }

    /**
     * Skip request (don't respond now).
     */
    protected function skipRequest(ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $session->phone,
            "⏭️ Skipped. 'Product Requests'-ൽ pinne respond cheyyaam.",
            [
                ['id' => 'more_requests', 'title' => '📬 More Requests'],
                ['id' => 'menu', 'title' => '🏠 Menu'],
            ]
        );

        $this->clearTempData($session);
        $this->showPendingRequests($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Post-Response
    |--------------------------------------------------------------------------
    */

    protected function handlePostResponse(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'more_requests' => $this->showPendingRequests($session),
            'retry' => $this->askPrice($session),
            default => $this->sessionManager->resetToMainMenu($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Customer Notification
    |--------------------------------------------------------------------------
    */

    protected function notifyCustomer(ProductRequest $request, ProductResponse $response, $shop): void
    {
        $customer = $request->user;
        if (!$customer) return;

        $price = '₹' . number_format((float) $response->price);

        $message = "🔔 *Response for #{$request->request_number}*\n\n" .
            "🏪 {$shop->shop_name}\n" .
            "💰 {$price}";

        if ($response->description) {
            $message .= "\n📝 {$response->description}";
        }

        // Send with photo if available
        if ($response->photo_url) {
            $this->whatsApp->sendImage($customer->phone, $response->photo_url, $message);
        } else {
            $this->whatsApp->sendText($customer->phone, $message);
        }

        $this->whatsApp->sendButtons(
            $customer->phone,
            "Check all responses?",
            [
                ['id' => 'view_responses', 'title' => '📬 View Responses'],
                ['id' => 'menu', 'title' => '🏠 Menu'],
            ]
        );
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

    protected function clearTempData(ConversationSession $session): void
    {
        $keys = ['request_id', 'request_desc', 'price', 'description', 'photo_url'];
        foreach ($keys as $key) {
            $this->sessionManager->removeTempData($session, $key);
        }
    }

    protected function formatDistance(float $km): string
    {
        if ($km < 0.1) return 'Very close';
        if ($km < 1) return round($km * 1000) . 'm';
        return round($km, 1) . 'km';
    }

    protected function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;
        return mb_substr($text, 0, $max - 1) . '…';
    }
}