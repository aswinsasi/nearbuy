<?php

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Services\Flow\FlowRouter;
use App\Services\WhatsApp\Messages\MainMenuTemplate;

/**
 * Main Menu Handler - The heart of NearBuy navigation.
 *
 * FIRST IMPRESSION MATTERS - This is what users see when they open NearBuy.
 *
 * Features:
 * - Personalized greeting: "Hii [Name]! 👋"
 * - Role-based quick actions at top
 * - All 6 core services always visible
 * - Graceful re-entry from any state
 * - Malayalam + English quick commands
 *
 * @srs-ref Section 6.2 - Unified Menu Structure
 * @srs-ref NFR-U-04 - Main menu accessible from any flow state
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
        // Handle interactive responses (button/list selections)
        if ($message->isInteractive()) {
            $selection = $this->getSelectionId($message);

            // Handle special menu options
            if ($selection === 'more') {
                $this->showMainMenu($session);
                return;
            }

            if ($selection === 'about') {
                $this->showAbout($session);
                return;
            }

            if ($selection === 'help') {
                $this->showHelp($session);
                return;
            }

            // Route to selected flow
            $this->handleMenuSelection($selection, $session);
            return;
        }

        // Handle text commands (English + Malayalam)
        if ($message->isText()) {
            $text = mb_strtolower(trim($message->text ?? ''));

            // Check for quick commands
            $action = $this->parseQuickCommand($text);

            if ($action === 'about') {
                $this->showAbout($session);
                return;
            }

            if ($action === 'help') {
                $this->showHelp($session);
                return;
            }

            if ($action) {
                $this->handleMenuSelection($action, $session);
                return;
            }
        }

        // Default: show main menu (handles "menu", "hi", random text, etc.)
        $this->showMainMenu($session);
    }

    /**
     * Show the main menu with personalized greeting.
     */
    protected function showMainMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Build menu components
        $header = MainMenuTemplate::getHeader();
        $body = MainMenuTemplate::getBody($user);
        $buttonText = MainMenuTemplate::getButtonText();
        $sections = MainMenuTemplate::buildListSections($user);
        $footer = MainMenuTemplate::getFooter();

        // Update session state
        $this->setStep($session, 'awaiting_selection');

        // Send list message
        $this->sendList(
            $session->phone,
            $body,
            $buttonText,
            $sections,
            $header,
            $footer
        );

        $this->logInfo('Main menu shown', [
            'user_type' => $user?->type?->value ?? 'guest',
            'has_fish_seller' => $user?->fishSeller !== null,
            'has_job_worker' => $user?->jobWorker !== null,
        ]);
    }

    /**
     * Show quick buttons instead of full list (for returning users).
     */
    protected function showQuickMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        $greeting = $user
            ? MainMenuTemplate::getTimeBasedGreeting($user->name ?? 'Friend')
            : "🙏 Swaagatham!";

        $buttons = MainMenuTemplate::buildQuickButtons($user);

        $this->setStep($session, 'awaiting_selection');

        $this->sendButtons(
            $session->phone,
            $greeting . "\n\nEntha vendathu?",
            $buttons,
            MainMenuTemplate::getHeader()
        );
    }

    /**
     * Show about information.
     */
    protected function showAbout(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            MainMenuTemplate::getAboutMessage(),
            [
                ['id' => 'register', 'title' => '📝 Register Free'],
                ['id' => 'browse_offers', 'title' => '🛍️ Browse'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /**
     * Show help information.
     */
    protected function showHelp(ConversationSession $session): void
    {
        $this->sendTextWithMenu(
            $session->phone,
            MainMenuTemplate::getHelpMessage()
        );
    }

    /**
     * Handle menu selection - route to appropriate flow.
     */
    protected function handleMenuSelection(string $selectionId, ConversationSession $session): void
    {
        // Delegate to FlowRouter
        app(FlowRouter::class)->handleMenuSelection($selectionId, $session);
    }

    /**
     * Parse quick text commands (English + Malayalam).
     *
     * @return string|null Action ID or null
     */
    protected function parseQuickCommand(string $text): ?string
    {
        // Normalize: remove extra spaces, convert to lowercase
        $text = preg_replace('/\s+/', ' ', trim(mb_strtolower($text)));

        // English commands
        $englishCommands = [
            // Offers
            'offers' => 'browse_offers',
            'browse' => 'browse_offers',
            'deals' => 'browse_offers',
            'shop' => 'browse_offers',
            'shops' => 'browse_offers',

            // Search
            'search' => 'search_product',
            'find' => 'search_product',
            'product' => 'search_product',

            // Fish
            'fish' => 'fish_browse',
            'meen' => 'fish_browse',
            'pacha' => 'fish_browse',
            'fresh fish' => 'fish_browse',
            'catch' => 'fish_post_catch',
            'post catch' => 'fish_post_catch',
            'stock' => 'fish_update_stock',
            'update stock' => 'fish_update_stock',

            // Jobs
            'jobs' => 'job_browse',
            'job' => 'job_browse',
            'work' => 'job_browse',
            'task' => 'job_post',
            'tasks' => 'job_poster_menu',
            'worker' => 'job_worker_menu',
            'my jobs' => 'job_worker_menu',

            // Flash Deals
            'flash' => 'flash_deals',
            'flash deals' => 'flash_deals',
            'flash deal' => 'flash_deals',

            // Agreements
            'agreement' => 'create_agreement',
            'agree' => 'create_agreement',
            'karar' => 'create_agreement',
            'loan' => 'create_agreement',
            'my agreements' => 'my_agreements',

            // Upload
            'upload' => 'upload_offer',
            'post' => 'upload_offer',
            'new offer' => 'upload_offer',

            // Settings
            'settings' => 'settings',
            'profile' => 'settings',

            // Help/About
            'help' => 'help',
            '?' => 'help',
            'about' => 'about',
            'info' => 'about',

            // Register
            'register' => 'register',
            'signup' => 'register',
            'sign up' => 'register',
        ];

        // Malayalam commands (romanized)
        $malayalamCommands = [
            // Fish
            'pachameen' => 'fish_browse',
            'meen' => 'fish_browse',
            'മീൻ' => 'fish_browse',
            'പച്ച' => 'fish_browse',

            // Jobs
            'pani' => 'job_browse',
            'panikkar' => 'job_browse',
            'njaanum' => 'job_browse',
            'പണി' => 'job_browse',

            // Agreements
            'karar' => 'create_agreement',
            'കരാർ' => 'create_agreement',

            // General
            'sahayam' => 'help',
            'സഹായം' => 'help',
        ];

        // Check English first
        if (isset($englishCommands[$text])) {
            return $englishCommands[$text];
        }

        // Check Malayalam
        if (isset($malayalamCommands[$text])) {
            return $malayalamCommands[$text];
        }

        // Check if text contains any keyword
        foreach ($englishCommands as $keyword => $action) {
            if (str_contains($text, $keyword)) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Get expected input type for steps.
     */
    public function getExpectedInputType(string $step): string
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