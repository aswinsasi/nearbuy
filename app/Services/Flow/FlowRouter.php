<?php

namespace App\Services\Flow;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\ErrorTemplate;
use App\Services\WhatsApp\Messages\MessageTemplates;
use App\Services\WhatsApp\Messages\MainMenuTemplate;
use Illuminate\Support\Facades\Log;

/**
 * ENHANCED FlowRouter - Routes incoming messages to appropriate flow handlers.
 *
 * Key improvements:
 * 1. Better help message with buttons
 * 2. Consistent error handling with options
 * 3. Quick command support
 * 4. Better session recovery
 * 5. Global interception of product request response buttons
 */
class FlowRouter
{
    /**
     * Keywords that trigger return to main menu.
     */
    protected const MENU_KEYWORDS = ['menu', 'home', 'start', '0', 'hi', 'hello', 'main', 'reset'];

    /**
     * Keywords that trigger help message.
     */
    protected const HELP_KEYWORDS = ['help', '?', 'support', 'how'];

    /**
     * Keywords that trigger cancel action.
     */
    protected const CANCEL_KEYWORDS = ['cancel', 'exit', 'quit', 'stop', 'end'];

    /**
     * Quick action keywords mapped to flows.
     */
    protected const QUICK_ACTIONS = [
        'browse' => 'browse_offers',
        'offers' => 'browse_offers',
        'search' => 'search_product',
        'find' => 'search_product',
        'agree' => 'create_agreement',
        'agreement' => 'create_agreement',
        'upload' => 'upload_offer',
    ];

    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
    ) {}

    /**
     * Route an incoming message to the appropriate handler.
     */
    public function route(IncomingMessage $message, ConversationSession $session): void
    {
        try {
            // Record the message
            $this->sessionManager->recordMessage(
                $session,
                $message->messageId,
                $message->type
            );

            // Mark message as read
            $this->whatsApp->markAsRead($message->messageId);

            // Check for global keywords first
            if ($this->handleGlobalKeywords($message, $session)) {
                return;
            }

            // =====================================================
            // NEW: Check for product request response buttons
            // This intercepts yes/no buttons from notifications
            // regardless of what flow the user is currently in
            // =====================================================
            if ($this->handleProductRequestResponse($message, $session)) {
                return;
            }

            // Check for quick action commands
            if ($this->handleQuickActions($message, $session)) {
                return;
            }

            // Get the current flow type
            $flowType = $this->sessionManager->getCurrentFlowType($session);

            if (!$flowType) {
                // Default to main menu if flow is invalid
                $flowType = FlowType::MAIN_MENU;
                $this->sessionManager->setFlowStep($session, $flowType, 'show_menu');
            }

            // Check if flow requires authentication
            if ($flowType->requiresAuth() && !$this->sessionManager->isRegistered($session)) {
                $this->handleNotRegistered($message, $session);
                return;
            }

            // Check if flow is shop-only
            if ($flowType->isShopOnly() && !$this->isShopOwner($session)) {
                $this->handleShopOnly($message, $session);
                return;
            }

            // Get the handler for this flow
            $handler = $this->resolveHandler($flowType);

            if (!$handler) {
                Log::error('No handler found for flow', ['flow' => $flowType->value]);
                $this->sendErrorWithMenu($message->from, 'Something went wrong. Please try again.');
                return;
            }

            // Dispatch to handler
            $handler->handle($message, $session);

        } catch (\Exception $e) {
            Log::error('Error routing message', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($message->from),
                'flow' => $session->current_flow,
                'step' => $session->current_step,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->sendErrorWithMenu($message->from, MessageTemplates::ERROR_GENERIC);
        }
    }

    /**
     * Handle product request response buttons from notifications.
     * 
     * This intercepts buttons like:
     * - "respond_yes_17" or "respond_no_17" (new format with request ID)
     * - "yes" or "no" (legacy format - uses temp data for request ID)
     * 
     * This allows shops to respond to product requests from ANY flow,
     * not just when they're in the ProductRespond flow.
     *
     * @param IncomingMessage $message
     * @param ConversationSession $session
     * @return bool True if handled, false otherwise
     */
    protected function handleProductRequestResponse(IncomingMessage $message, ConversationSession $session): bool
    {
        if (!$message->isInteractive()) {
            return false;
        }

        // Extract the button/list selection ID
        $buttonId = $this->extractSelectionId($message);

        if (!$buttonId) {
            return false;
        }

        // Pattern 1: New format with request ID - "respond_yes_17" or "respond_no_17"
        if (preg_match('/^respond_(yes|no)_(\d+)$/', $buttonId, $matches)) {
            $action = $matches[1]; // 'yes' or 'no'
            $requestId = (int) $matches[2];

            Log::info('Product request response button intercepted (new format)', [
                'button_id' => $buttonId,
                'action' => $action,
                'request_id' => $requestId,
                'phone' => $this->maskPhone($message->from),
                'current_flow' => $session->current_flow,
            ]);

            return $this->routeToProductResponseHandler($session, $requestId, $action);
        }

        // Pattern 2: Legacy format - plain "yes" or "no" button
        // Only intercept if user is NOT already in the product_respond flow
        // (to avoid double-handling when they're already in the correct flow)
        if (in_array($buttonId, ['yes', 'no']) && $session->current_flow !== 'product_respond') {
            
            // Check if there's a pending request ID in temp data
            $requestId = $this->sessionManager->getTempData($session, 'respond_request_id');
            
            if ($requestId) {
                $action = $buttonId; // 'yes' or 'no'

                Log::info('Product request response button intercepted (legacy format)', [
                    'button_id' => $buttonId,
                    'action' => $action,
                    'request_id' => $requestId,
                    'phone' => $this->maskPhone($message->from),
                    'current_flow' => $session->current_flow,
                ]);

                return $this->routeToProductResponseHandler($session, (int) $requestId, $action);
            }
            
            // No request ID in temp data - this might be a yes/no from another context
            // Check if the user is a shop owner and there are pending requests
            if ($this->isShopOwner($session)) {
                // Try to get the most recent notification request for this shop
                $requestId = $this->getRecentNotificationRequestId($session);
                
                if ($requestId) {
                    $action = $buttonId;

                    Log::info('Product request response button intercepted (legacy format, inferred request)', [
                        'button_id' => $buttonId,
                        'action' => $action,
                        'request_id' => $requestId,
                        'phone' => $this->maskPhone($message->from),
                        'current_flow' => $session->current_flow,
                    ]);

                    return $this->routeToProductResponseHandler($session, $requestId, $action);
                }
            }
        }

        return false;
    }

    /**
     * Route to ProductResponseFlowHandler.
     *
     * @param ConversationSession $session
     * @param int $requestId
     * @param string $action 'yes' or 'no'
     * @return bool
     */
    protected function routeToProductResponseHandler(ConversationSession $session, int $requestId, string $action): bool
    {
        $handler = $this->resolveHandler(FlowType::PRODUCT_RESPOND);

        if ($handler instanceof \App\Services\Flow\Handlers\ProductResponseFlowHandler) {
            $handler->startWithRequest($session, $requestId, $action);
            return true;
        }

        Log::error('Could not resolve ProductResponseFlowHandler', [
            'request_id' => $requestId,
            'action' => $action,
        ]);
        
        return false;
    }

    /**
     * Extract selection ID from interactive message (button or list reply).
     *
     * @param IncomingMessage $message
     * @return string|null
     */
    protected function extractSelectionId(IncomingMessage $message): ?string
    {
        // Try button reply first
        if ($message->isButtonReply()) {
            return $message->buttonReplyId ?? null;
        }

        // Try list reply
        if ($message->isListReply()) {
            return $message->listReplyId ?? null;
        }

        // Fallback: check interactive data directly
        $interactive = $message->interactive ?? [];
        
        // Button reply format
        if (isset($interactive['button_reply']['id'])) {
            return $interactive['button_reply']['id'];
        }
        
        // List reply format
        if (isset($interactive['list_reply']['id'])) {
            return $interactive['list_reply']['id'];
        }

        return null;
    }

    /**
     * Get the most recent notification request ID for a shop.
     * This is used as a fallback when legacy 'yes'/'no' buttons are clicked.
     *
     * @param ConversationSession $session
     * @return int|null
     */
    protected function getRecentNotificationRequestId(ConversationSession $session): ?int
    {
        $user = $this->sessionManager->getUser($session);
        
        if (!$user || !$user->isShopOwner() || !$user->shop) {
            return null;
        }

        // Get the most recent pending product request for this shop
        // that was notified within the last hour
        $recentRequest = \App\Models\ProductRequest::query()
            ->where('status', 'open')
            ->where('expires_at', '>', now())
            ->where('created_at', '>', now()->subHour())
            ->whereDoesntHave('responses', function ($query) use ($user) {
                $query->where('shop_id', $user->shop->id);
            })
            ->orderBy('created_at', 'desc')
            ->first();

        return $recentRequest?->id;
    }

    /**
     * Handle global keywords that work from any flow.
     */
    protected function handleGlobalKeywords(IncomingMessage $message, ConversationSession $session): bool
    {
        if (!$message->isText()) {
            return false;
        }

        $text = strtolower(trim($message->text ?? ''));

        // Menu keywords
        if (in_array($text, self::MENU_KEYWORDS)) {
            $this->goToMainMenu($session);
            return true;
        }

        // Help keywords
        if (in_array($text, self::HELP_KEYWORDS)) {
            $this->showHelp($message->from, $session);
            return true;
        }

        // Cancel keywords (only if in a flow)
        if (in_array($text, self::CANCEL_KEYWORDS) && !$this->sessionManager->isIdle($session)) {
            $this->handleCancel($session);
            return true;
        }

        return false;
    }

    /**
     * Handle quick action keywords.
     */
    protected function handleQuickActions(IncomingMessage $message, ConversationSession $session): bool
    {
        if (!$message->isText()) {
            return false;
        }

        $text = strtolower(trim($message->text ?? ''));

        if (isset(self::QUICK_ACTIONS[$text])) {
            $menuSelection = self::QUICK_ACTIONS[$text];
            $this->handleMenuSelection($menuSelection, $session);
            return true;
        }

        return false;
    }

    /**
     * Go to main menu.
     */
    protected function goToMainMenu(ConversationSession $session): void
    {
        $this->sessionManager->resetToMainMenu($session);

        $handler = $this->resolveHandler(FlowType::MAIN_MENU);
        $handler?->start($session);
    }

    /**
     * Show help message with buttons.
     * 
     * ENHANCED: Now uses buttons instead of plain text.
     */
    protected function showHelp(string $phone, ConversationSession $session): void
    {
        $helpMessage = MainMenuTemplate::getHelpMessage();

        $this->whatsApp->sendButtons(
            $phone,
            $helpMessage,
            [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ['id' => 'browse_offers', 'title' => '🛍️ Browse'],
            ],
            'ℹ️ Help',
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    /**
     * Handle cancel action.
     * 
     * ENHANCED: Now uses buttons and clears temp data.
     */
    protected function handleCancel(ConversationSession $session): void
    {
        // Clear any temp data
        $this->sessionManager->clearTempData($session);

        $this->whatsApp->sendButtons(
            $session->phone,
            "❌ *Action Cancelled*\n\nWhat would you like to do?",
            [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ['id' => 'retry', 'title' => '🔄 Start Over'],
            ],
            null,
            MessageTemplates::GLOBAL_FOOTER
        );

        $this->goToMainMenu($session);
    }

    /**
     * Handle user not registered.
     * 
     * ENHANCED: Better messaging with clear CTA.
     */
    protected function handleNotRegistered(IncomingMessage $message, ConversationSession $session): void
    {
        $error = ErrorTemplate::notRegistered();

        $this->whatsApp->sendButtons(
            $message->from,
            $error['message'],
            $error['buttons'],
            '📝 Registration Required',
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    /**
     * Handle shop-only feature access by non-shop user.
     */
    protected function handleShopOnly(IncomingMessage $message, ConversationSession $session): void
    {
        $error = ErrorTemplate::shopOnly();

        $this->whatsApp->sendButtons(
            $message->from,
            $error['message'],
            $error['buttons'],
            '🏪 Shop Feature',
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    /**
     * Check if user is a shop owner.
     */
    protected function isShopOwner(ConversationSession $session): bool
    {
        $user = $this->sessionManager->getUser($session);
        return $user?->isShopOwner() ?? false;
    }

    /**
     * Resolve the handler for a flow type.
     */
    protected function resolveHandler(FlowType $flowType): ?FlowHandlerInterface
    {
        $handlerClass = $flowType->handlerClass();

        if (!class_exists($handlerClass)) {
            Log::warning('Handler class does not exist', ['class' => $handlerClass]);
            return null;
        }

        return app($handlerClass);
    }

    /**
     * Start a specific flow.
     */
    public function startFlow(ConversationSession $session, FlowType $flowType): void
    {
        // Check access first
        if ($flowType->requiresAuth() && !$this->sessionManager->isRegistered($session)) {
            $this->handleNotRegistered(
                new IncomingMessage(
                    messageId: '',
                    from: $session->phone,
                    type: 'text',
                    timestamp: now()
                ),
                $session
            );
            return;
        }

        if ($flowType->isShopOnly() && !$this->isShopOwner($session)) {
            $this->handleShopOnly(
                new IncomingMessage(
                    messageId: '',
                    from: $session->phone,
                    type: 'text',
                    timestamp: now()
                ),
                $session
            );
            return;
        }

        // Clear any previous temp data before starting new flow
        $this->sessionManager->clearTempData($session);

        // Update session
        $this->sessionManager->setFlowStep(
            $session,
            $flowType,
            $flowType->initialStep()
        );

        // Start the flow
        $handler = $this->resolveHandler($flowType);
        $handler?->start($session);
    }

    /**
     * Handle menu selection and route to appropriate flow.
     */
    public function handleMenuSelection(string $selectionId, ConversationSession $session): void
    {
        // Special handling for my_requests - show existing requests, not start new search
        if ($selectionId === 'my_requests') {
            $this->startMyRequestsFlow($session);
            return;
        }

        $flowType = match ($selectionId) {
            'register' => FlowType::REGISTRATION,
            'browse_offers' => FlowType::OFFERS_BROWSE,
            'upload_offer' => FlowType::OFFERS_UPLOAD,
            'my_offers' => FlowType::OFFERS_MANAGE,
            'search_product' => FlowType::PRODUCT_SEARCH,
            'product_requests' => FlowType::PRODUCT_RESPOND,
            'create_agreement' => FlowType::AGREEMENT_CREATE,
            'my_agreements' => FlowType::AGREEMENT_LIST,
            'pending_agreements' => FlowType::AGREEMENT_CONFIRM,
            'settings', 'shop_profile' => FlowType::SETTINGS,
            default => null,
        };

        if ($flowType) {
            $this->startFlow($session, $flowType);
        } else {
            Log::warning('Unknown menu selection', ['id' => $selectionId]);
            $this->goToMainMenu($session);
        }
    }

    /**
     * Start the My Requests flow (view existing product requests).
     */
    protected function startMyRequestsFlow(ConversationSession $session): void
    {
        $flowType = FlowType::PRODUCT_SEARCH;

        // Check access
        if ($flowType->requiresAuth() && !$this->sessionManager->isRegistered($session)) {
            $this->handleNotRegistered(
                new IncomingMessage(
                    messageId: '',
                    from: $session->phone,
                    type: 'text',
                    timestamp: now()
                ),
                $session
            );
            return;
        }

        // Clear any previous temp data
        $this->sessionManager->clearTempData($session);

        // Get the handler and call startMyRequests instead of start
        $handler = $this->resolveHandler($flowType);
        
        if ($handler instanceof \App\Services\Flow\Handlers\ProductSearchFlowHandler) {
            $handler->startMyRequests($session);
        } else {
            // Fallback to regular start if handler doesn't have the method
            $this->startFlow($session, $flowType);
        }
    }

    /**
     * Send an error message with menu button.
     * 
     * ENHANCED: All errors now have actionable buttons.
     */
    protected function sendErrorWithMenu(string $phone, string $message): void
    {
        $this->whatsApp->sendButtons(
            $phone,
            $message,
            [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
            null,
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    /**
     * Mask phone number for logging.
     */
    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return $phone;
        }

        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }
}