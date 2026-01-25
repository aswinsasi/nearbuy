<?php

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\UserType;
use App\Models\ConversationSession;
use App\Services\Flow\FlowRouter;
use App\Services\WhatsApp\Messages\MainMenuTemplate;
use App\Services\WhatsApp\Messages\MessageTemplates;

/**
 * ENHANCED Main Menu Handler.
 *
 * Key improvements:
 * 1. Better user experience with contextual greetings
 * 2. Proper handling of all menu selections
 * 3. Quick action buttons for common tasks
 * 4. About and Help information
 * 5. Fish seller support
 */
class MainMenuHandler extends AbstractFlowHandler
{
    protected function getFlowType(): FlowType
    {
        return FlowType::MAIN_MENU;
    }

    protected function getSteps(): array
    {
        return ['idle', 'show_menu', 'awaiting_selection'];
    }

    /**
     * Start the main menu flow.
     */
    public function start(ConversationSession $session): void
    {
        $this->showMainMenu($session);
    }

    /**
     * Handle incoming messages.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = $session->current_step;

        // Check for "more" button to show full menu
        if ($message->isInteractive()) {
            $selection = $this->getSelectionId($message);

            if ($selection === 'more') {
                $this->showFullMenu($session);
                return;
            }

            if ($selection === 'about') {
                $this->showAbout($session);
                return;
            }

            // Handle menu selection
            $this->handleMenuSelection($selection, $session);
            return;
        }

        // Handle text commands
        if ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));

            // Quick text commands
            $quickAction = match ($text) {
                'browse', 'offers' => 'browse_offers',
                'search', 'find' => 'search_product',
                'agree', 'agreement' => 'create_agreement',
                'upload' => 'upload_offer',
                'about' => 'about',
                'help', '?' => 'help',
                // Fish-related quick commands
                'fish', 'meen', 'pacha', 'pachameen', 'fresh fish' => 'fish_browse',
                'catch', 'post catch' => 'fish_post_catch',
                'stock', 'update stock' => 'fish_update_stock',
                'alerts', 'fish alerts' => 'fish_subscribe',
                'my catches', 'catches' => 'fish_my_catches',
                default => null,
            };

            if ($quickAction === 'about') {
                $this->showAbout($session);
                return;
            }

            if ($quickAction === 'help') {
                $this->showHelp($session);
                return;
            }

            if ($quickAction) {
                $this->handleMenuSelection($quickAction, $session);
                return;
            }
        }

        // Default: show main menu
        $this->showMainMenu($session);
    }

    /**
     * Show the main menu.
     */
    protected function showMainMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Get contextual greeting
        $body = MainMenuTemplate::getBody($user);

        // Build menu sections
        $sections = MainMenuTemplate::buildListSections($user);

        // Update session state
        $this->nextStep($session, 'awaiting_selection');

        // Send list message
        $this->sendListWithFooter(
            $session->phone,
            $body,
            MainMenuTemplate::getButtonText(),
            $sections,
            MainMenuTemplate::getHeader()
        );
    }

    /**
     * Show full menu (when user clicks "More Options").
     */
    protected function showFullMenu(ConversationSession $session): void
    {
        $this->showMainMenu($session);
    }

    /**
     * Show quick buttons (alternative to list for returning users).
     */
    protected function showQuickMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Time-based greeting for returning users
        $greeting = $user
            ? MainMenuTemplate::getTimeBasedGreeting($user->name ?? 'there')
            : "👋 Welcome!";

        $body = $greeting . "\n\nQuick actions:";

        $buttons = MainMenuTemplate::buildQuickButtons($user);

        $this->nextStep($session, 'awaiting_selection');

        $this->sendButtonsWithMenu(
            $session->phone,
            $body,
            $buttons,
            MainMenuTemplate::getHeader(),
            false // Don't add extra menu button
        );
    }

    /**
     * Show about information.
     */
    protected function showAbout(ConversationSession $session): void
    {
        $aboutMessage = MainMenuTemplate::getAboutMessage();

        $this->sendButtonsWithMenu(
            $session->phone,
            $aboutMessage,
            [
                ['id' => 'register', 'title' => '📝 Register Free'],
                ['id' => 'browse_offers', 'title' => '🛍️ Browse'],
            ]
        );
    }

    /**
     * Show help information.
     */
    protected function showHelp(ConversationSession $session): void
    {
        $helpMessage = MainMenuTemplate::getHelpMessage();

        $this->sendTextWithMenu($session->phone, $helpMessage);
    }

    /**
     * Handle menu selection and route to appropriate flow.
     */
    protected function handleMenuSelection(string $selectionId, ConversationSession $session): void
    {
        // Delegate to FlowRouter which handles special cases like my_requests
        app(FlowRouter::class)->handleMenuSelection($selectionId, $session);
    }

    /**
     * Get expected input type for steps.
     */
    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            'awaiting_selection' => 'list',
            default => 'text',
        };
    }

    /**
     * Re-prompt current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $this->showMainMenu($session);
    }
}