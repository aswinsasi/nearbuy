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
use Illuminate\Support\Facades\Log;

/**
 * Routes incoming messages to appropriate flow handlers.
 *
 * This is the main entry point for processing WhatsApp messages.
 * It determines which flow handler should process the message
 * based on session state and message content.
 *
 * @example
 * $router = app(FlowRouter::class);
 * $router->route($incomingMessage, $session);
 */
class FlowRouter
{
    /**
     * Keywords that trigger return to main menu.
     */
    protected const MENU_KEYWORDS = ['menu', 'home', 'start', '0', 'hi', 'hello', 'main'];

    /**
     * Keywords that trigger help message.
     */
    protected const HELP_KEYWORDS = ['help', '?', 'support'];

    /**
     * Keywords that trigger cancel action.
     */
    protected const CANCEL_KEYWORDS = ['cancel', 'exit', 'quit', 'stop'];

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
                $this->sendError($message->from, ErrorTemplate::generic('Handler not found'));
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
            ]);

            $this->sendError($message->from, ErrorTemplate::generic());
        }
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
            $this->showHelp($message->from);
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
     * Go to main menu.
     */
    protected function goToMainMenu(ConversationSession $session): void
    {
        $this->sessionManager->resetToMainMenu($session);

        $handler = $this->resolveHandler(FlowType::MAIN_MENU);
        $handler?->start($session);
    }

    /**
     * Show help message.
     */
    protected function showHelp(string $phone): void
    {
        $helpText = "ℹ️ *NearBuy Help*\n\n" .
            "Available commands:\n" .
            "• *menu* - Return to main menu\n" .
            "• *cancel* - Cancel current action\n" .
            "• *help* - Show this message\n\n" .
            "Need more help? Contact support at:\n" .
            config('nearbuy.app.support_phone', 'support');

        $this->whatsApp->sendText($phone, $helpText);
    }

    /**
     * Handle cancel action.
     */
    protected function handleCancel(ConversationSession $session): void
    {
        $this->whatsApp->sendText(
            $session->phone,
            "❌ Action cancelled.\n\nReturning to main menu..."
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
            $error['buttons']
        );

        // If user selects register, route to registration
        // This will be handled by the main menu handler
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
            $error['buttons']
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
        $flowType = match ($selectionId) {
            'register' => FlowType::REGISTRATION,
            'browse_offers' => FlowType::OFFERS_BROWSE,
            'upload_offer' => FlowType::OFFERS_UPLOAD,
            'my_offers' => FlowType::OFFERS_MANAGE,
            'search_product' => FlowType::PRODUCT_SEARCH,
            'my_requests' => FlowType::PRODUCT_SEARCH, // Different initial step
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
     * Send an error message.
     */
    protected function sendError(string $phone, string $message): void
    {
        $this->whatsApp->sendText($phone, $message);
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