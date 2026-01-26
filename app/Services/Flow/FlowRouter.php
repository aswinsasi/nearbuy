<?php

namespace App\Services\Flow;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\UserType;
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
 * 6. Fish (Pacha Meen) flow support
 * 7. FIXED: isFishSeller() now checks for fish_seller PROFILE, not user type
 * 8. FIXED: main_menu handled in handleMenuSelection()
 *
 * @srs-ref Section 2.2: Fish sellers are separate registration from customers/shops
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
        // Fish-related quick actions
        'fish' => 'fish_menu',
        'meen' => 'fish_menu',
        'pacha' => 'fish_menu',
        'pachameen' => 'fish_menu',
        'fresh fish' => 'fish_browse',
        'catch' => 'fish_post_catch',
        // Quick actions for fish alerts
        'alerts' => 'fish_manage_alerts',
        'unsubscribe' => 'fish_manage_alerts',
        'sell fish' => 'fish_seller_register',
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
            // Check for product request response buttons
            // This intercepts yes/no buttons from notifications
            // regardless of what flow the user is currently in
            // =====================================================
            if ($this->handleProductRequestResponse($message, $session)) {
                return;
            }

            // =====================================================
            // Check for fish alert response buttons
            // =====================================================
            if ($this->handleFishAlertResponse($message, $session)) {
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

            // Check if flow is fish-seller-only
            if ($flowType->isFishSellerOnly() && !$this->isFishSeller($session)) {
                $this->handleFishSellerOnly($message, $session);
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
     * Handle fish alert response buttons from notifications.
     */
    protected function handleFishAlertResponse(IncomingMessage $message, ConversationSession $session): bool
    {
        if (!$message->isInteractive()) {
            return false;
        }

        $buttonId = $this->extractSelectionId($message);

        if (!$buttonId) {
            return false;
        }

        // Pattern: "fish_coming_123_456" - I'm Coming button
        if (preg_match('/^fish_coming_(\d+)_(\d+)$/', $buttonId, $matches)) {
            $catchId = (int) $matches[1];
            $alertId = (int) $matches[2];

            Log::info('Fish alert "Coming" button intercepted', [
                'button_id' => $buttonId,
                'catch_id' => $catchId,
                'alert_id' => $alertId,
                'phone' => $this->maskPhone($message->from),
            ]);

            return $this->routeToFishBrowseHandler($session, $catchId, 'coming', $alertId);
        }

        // Pattern: "fish_location_123_456" - Get Location button
        if (preg_match('/^fish_location_(\d+)_(\d+)$/', $buttonId, $matches)) {
            $catchId = (int) $matches[1];
            $alertId = (int) $matches[2];

            Log::info('Fish alert "Location" button intercepted', [
                'button_id' => $buttonId,
                'catch_id' => $catchId,
                'alert_id' => $alertId,
                'phone' => $this->maskPhone($message->from),
            ]);

            return $this->routeToFishBrowseHandler($session, $catchId, 'location', $alertId);
        }

        return false;
    }

    /**
     * Route to FishBrowseFlowHandler for alert responses.
     */
    protected function routeToFishBrowseHandler(ConversationSession $session, int $catchId, string $action, int $alertId): bool
    {
        $handler = $this->resolveHandler(FlowType::FISH_BROWSE);

        if ($handler instanceof \App\Services\Flow\Handlers\Fish\FishBrowseFlowHandler) {
            $handler->handleAlertResponse($session, $catchId, $action, $alertId);
            return true;
        }

        Log::error('Could not resolve FishBrowseFlowHandler', [
            'catch_id' => $catchId,
            'action' => $action,
        ]);
        
        return false;
    }

    /**
     * Handle product request response buttons from notifications.
     */
    protected function handleProductRequestResponse(IncomingMessage $message, ConversationSession $session): bool
    {
        if (!$message->isInteractive()) {
            return false;
        }

        $buttonId = $this->extractSelectionId($message);

        if (!$buttonId) {
            return false;
        }

        // Pattern 1: New format with request ID - "respond_yes_17" or "respond_no_17"
        if (preg_match('/^respond_(yes|no)_(\d+)$/', $buttonId, $matches)) {
            $action = $matches[1];
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
        if (in_array($buttonId, ['yes', 'no']) && $session->current_flow !== 'product_respond') {
            $requestId = $this->sessionManager->getTempData($session, 'respond_request_id');
            
            if ($requestId) {
                $action = $buttonId;

                Log::info('Product request response button intercepted (legacy format)', [
                    'button_id' => $buttonId,
                    'action' => $action,
                    'request_id' => $requestId,
                    'phone' => $this->maskPhone($message->from),
                    'current_flow' => $session->current_flow,
                ]);

                return $this->routeToProductResponseHandler($session, (int) $requestId, $action);
            }
            
            if ($this->isShopOwner($session)) {
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
     */
    protected function extractSelectionId(IncomingMessage $message): ?string
    {
        if ($message->isButtonReply()) {
            return $message->buttonReplyId ?? null;
        }

        if ($message->isListReply()) {
            return $message->listReplyId ?? null;
        }

        $interactive = $message->interactive ?? [];
        
        if (isset($interactive['button_reply']['id'])) {
            return $interactive['button_reply']['id'];
        }
        
        if (isset($interactive['list_reply']['id'])) {
            return $interactive['list_reply']['id'];
        }

        return null;
    }

    /**
     * Get the most recent notification request ID for a shop.
     */
    protected function getRecentNotificationRequestId(ConversationSession $session): ?int
    {
        $user = $this->sessionManager->getUser($session);
        
        if (!$user || !$user->isShopOwner() || !$user->shop) {
            return null;
        }

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

        if (in_array($text, self::MENU_KEYWORDS)) {
            $this->goToMainMenu($session);
            return true;
        }

        if (in_array($text, self::HELP_KEYWORDS)) {
            $this->showHelp($message->from, $session);
            return true;
        }

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
    public function goToMainMenu(ConversationSession $session): void
    {
        $this->sessionManager->resetToMainMenu($session);

        $handler = $this->resolveHandler(FlowType::MAIN_MENU);
        $handler?->start($session);
    }

    /**
     * Show help message with buttons.
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
     */
    protected function handleCancel(ConversationSession $session): void
    {
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
     * Handle fish-seller-only feature access by non-fish-seller user.
     */
    protected function handleFishSellerOnly(IncomingMessage $message, ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $message->from,
            "🐟 *Fish Seller Feature*\n\nThis feature is only available for registered fish sellers.\n\nWould you like to register as a fish seller? You can sell fish while keeping your current account.",
            [
                ['id' => 'fish_seller_register', 'title' => '🐟 Register as Seller'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
            '🐟 Fish Seller Required',
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
     * Check if user is a fish seller (has fish seller PROFILE).
     *
     * FIXED: Checks for profile, not user type.
     */
    protected function isFishSeller(ConversationSession $session): bool
    {
        $user = $this->sessionManager->getUser($session);
        
        if (!$user) {
            return false;
        }

        return $user->fishSeller !== null;
    }

    /**
     * Check if user has an active fish subscription.
     */
    protected function hasFishSubscription(ConversationSession $session): bool
    {
        $user = $this->sessionManager->getUser($session);
        
        if (!$user) {
            return false;
        }

        if (method_exists($user, 'activeFishSubscriptions')) {
            return $user->activeFishSubscriptions()->exists();
        }

        return false;
    }

    /**
     * Route to appropriate fish menu based on user's fish seller profile.
     */
    protected function routeToFishMenu(ConversationSession $session): FlowType
    {
        $user = $this->sessionManager->getUser($session);
        
        if ($user?->fishSeller !== null) {
            return FlowType::FISH_SELLER_MENU;
        }
        
        return FlowType::FISH_BROWSE;
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

        if ($flowType->isFishSellerOnly() && !$this->isFishSeller($session)) {
            $this->handleFishSellerOnly(
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

        $this->sessionManager->clearTempData($session);

        $this->sessionManager->setFlowStep(
            $session,
            $flowType,
            $flowType->initialStep()
        );

        $handler = $this->resolveHandler($flowType);
        $handler?->start($session);
    }

    /**
     * Handle menu selection and route to appropriate flow.
     *
     * FIXED: Added 'main_menu' case to prevent "Unknown menu selection" warning.
     */
    public function handleMenuSelection(string $selectionId, ConversationSession $session): void
    {
        // FIXED: Handle main_menu selection explicitly
        if ($selectionId === 'main_menu') {
            $this->goToMainMenu($session);
            return;
        }

        if (in_array($selectionId, ['my_requests', 'view_responses'])) {
            $this->startMyRequestsFlow($session);
            return;
        }

        if ($selectionId === 'shop_profile') {
            $this->startShopProfileFlow($session);
            return;
        }

        if (in_array($selectionId, ['fish_menu', 'menu_fish_dashboard'])) {
            $flowType = $this->routeToFishMenu($session);
            $this->startFlow($session, $flowType);
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
            'settings' => FlowType::SETTINGS,
            // Fish-related menu selections
            'fish_browse', 'menu_fish_browse' => FlowType::FISH_BROWSE,
            'fish_subscribe', 'menu_fish_subscribe', 'fish_alerts' => FlowType::FISH_SUBSCRIBE,
            'fish_manage_alerts', 'menu_fish_manage' => FlowType::FISH_MANAGE_SUBSCRIPTION,
            'fish_seller_register', 'menu_fish_seller_register' => FlowType::FISH_SELLER_REGISTER,
            'fish_post_catch', 'menu_fish_post' => FlowType::FISH_POST_CATCH,
            'fish_update_stock', 'menu_fish_stock', 'fish_my_catches', 'view_my_catches' => FlowType::FISH_STOCK_UPDATE,
            'fish_seller_menu' => FlowType::FISH_SELLER_MENU,
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
     * Start the Shop Profile flow.
     */
    protected function startShopProfileFlow(ConversationSession $session): void
    {
        $flowType = FlowType::SETTINGS;

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

        if (!$this->isShopOwner($session)) {
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

        $this->sessionManager->clearTempData($session);

        $handler = $this->resolveHandler($flowType);
        
        if ($handler instanceof \App\Services\Flow\Handlers\SettingsFlowHandler) {
            $handler->startShopProfile($session);
        } else {
            $this->startFlow($session, $flowType);
        }
    }

    /**
     * Start the My Requests flow.
     */
    protected function startMyRequestsFlow(ConversationSession $session): void
    {
        $flowType = FlowType::PRODUCT_SEARCH;

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

        $this->sessionManager->clearTempData($session);

        $handler = $this->resolveHandler($flowType);
        
        if ($handler instanceof \App\Services\Flow\Handlers\ProductSearchFlowHandler) {
            $handler->startMyRequests($session);
        } else {
            $this->startFlow($session, $flowType);
        }
    }

    /**
     * Send an error message with menu button.
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