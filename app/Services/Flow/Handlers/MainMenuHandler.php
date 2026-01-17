<?php

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Services\Flow\FlowRouter;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\MainMenuTemplate;
use App\Services\WhatsApp\Messages\ErrorTemplate;
use Illuminate\Support\Facades\Log;

/**
 * Handles the main menu flow.
 *
 * Displays the appropriate menu based on user type and
 * routes menu selections to the correct flow handlers.
 */
class MainMenuHandler implements FlowHandlerInterface
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected FlowRouter $router,
    ) {}

    /**
     * Get the flow name.
     */
    public function getName(): string
    {
        return FlowType::MAIN_MENU->value;
    }

    /**
     * Check if this handler can process the given step.
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, ['idle', 'show_menu', 'awaiting_selection', 'more_options']);
    }

    /**
     * Start the main menu flow.
     */
    public function start(ConversationSession $session): void
    {
        $this->showMainMenu($session);
    }

    /**
     * Handle an incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = $session->current_step;

        switch ($step) {
            case 'idle':
            case 'show_menu':
                $this->showMainMenu($session);
                break;

            case 'awaiting_selection':
            case 'more_options':
                $this->handleSelection($message, $session);
                break;

            default:
                // For any unknown step, show menu
                $this->showMainMenu($session);
        }
    }

    /**
     * Show the main menu.
     */
    protected function showMainMenu(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);

        // Check for pending agreements to notify user
        $pendingCount = $this->getPendingAgreementsCount($session);

        $body = MainMenuTemplate::getBody($user);

        if ($pendingCount > 0) {
            $body .= "\n\n⚠️ You have *{$pendingCount}* pending agreement(s) to review.";
        }

        // Use list message for full menu
        $sections = MainMenuTemplate::buildListSections($user);

        $this->whatsApp->sendList(
            $session->phone,
            $body,
            MainMenuTemplate::getButtonText(),
            $sections,
            MainMenuTemplate::getHeader(),
            MainMenuTemplate::getFooter()
        );

        $this->sessionManager->setStep($session, 'awaiting_selection');
    }

    /**
     * Show quick buttons menu (alternative to list).
     */
    protected function showQuickMenu(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);
        $buttons = MainMenuTemplate::buildQuickButtons($user);

        $this->whatsApp->sendButtons(
            $session->phone,
            MainMenuTemplate::getBody($user),
            $buttons,
            MainMenuTemplate::getHeader()
        );

        $this->sessionManager->setStep($session, 'awaiting_selection');
    }

    /**
     * Handle menu selection.
     */
    protected function handleSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = null;

        // Handle list reply
        if ($message->isListReply()) {
            $selectionId = $message->getSelectionId();
        }
        // Handle button reply
        elseif ($message->isButtonReply()) {
            $selectionId = $message->getSelectionId();
        }
        // Handle text input (for quick commands)
        elseif ($message->isText()) {
            $selectionId = $this->parseTextAsMenuOption($message->text ?? '');
        }

        if (!$selectionId) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        Log::info('Menu selection', [
            'phone' => $this->maskPhone($session->phone),
            'selection' => $selectionId,
        ]);

        // Handle "more options" specially
        if ($selectionId === 'more') {
            $this->showMainMenu($session);
            return;
        }

        // Handle "about" option
        if ($selectionId === 'about') {
            $this->showAbout($session);
            return;
        }

        // Route to appropriate flow
        $this->router->handleMenuSelection($selectionId, $session);
    }

    /**
     * Parse text input as menu option.
     */
    protected function parseTextAsMenuOption(string $text): ?string
    {
        $text = strtolower(trim($text));

        // Number shortcuts
        $numberMap = [
            '1' => 'browse_offers',
            '2' => 'search_product',
            '3' => 'create_agreement',
            '4' => 'my_agreements',
            '5' => 'settings',
        ];

        if (isset($numberMap[$text])) {
            return $numberMap[$text];
        }

        // Keyword shortcuts
        $keywordMap = [
            'offers' => 'browse_offers',
            'browse' => 'browse_offers',
            'search' => 'search_product',
            'find' => 'search_product',
            'agreement' => 'create_agreement',
            'register' => 'register',
            'settings' => 'settings',
            'upload' => 'upload_offer',
        ];

        foreach ($keywordMap as $keyword => $option) {
            if (str_contains($text, $keyword)) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Show about information.
     */
    protected function showAbout(ConversationSession $session): void
    {
        $aboutText = "ℹ️ *About NearBuy*\n\n" .
            "NearBuy is your local marketplace on WhatsApp!\n\n" .
            "🛍️ *Browse Offers* - See daily deals from shops near you\n\n" .
            "🔍 *Search Products* - Can't find something? Ask local shops!\n\n" .
            "📝 *Digital Agreements* - Create secure records of loans and payments\n\n" .
            "No app download needed - everything works right here in WhatsApp!\n\n" .
            "_Powered by NearBuy_";

        $this->whatsApp->sendButtons(
            $session->phone,
            $aboutText,
            [
                ['id' => 'register', 'title' => '📝 Register Now'],
                ['id' => 'menu', 'title' => '🏠 Main Menu'],
            ]
        );
    }

    /**
     * Handle invalid input.
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $this->whatsApp->sendText(
            $session->phone,
            ErrorTemplate::invalidInput('list', "Please select an option from the menu.")
        );

        // Show menu again
        $this->showMainMenu($session);
    }

    /**
     * Get count of pending agreements for user.
     */
    protected function getPendingAgreementsCount(ConversationSession $session): int
    {
        if (!$session->user_id) {
            return 0;
        }

        // Check if Agreement model exists
        if (!class_exists(\App\Models\Agreement::class)) {
            return 0;
        }

        try {
            return \App\Models\Agreement::where('to_user_id', $session->user_id)
                ->where('status', 'pending')
                ->whereNull('to_confirmed_at')
                ->count();
        } catch (\Exception $e) {
            return 0;
        }
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