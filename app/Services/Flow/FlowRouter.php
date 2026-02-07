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
 * FlowRouter - Routes incoming messages to appropriate flow handlers.
 *
 * ROUTING PRIORITY (checked in order):
 * 1. Global keywords (menu, help, cancel) — ALWAYS checked first
 * 2. Module shortcuts (fish, job, offer, etc.) — bilingual support
 * 3. Notification button responses (product, fish, job alerts)
 * 4. Smart input routing (numbers, images, locations)
 * 5. Current flow handler
 *
 * @srs-ref Section 7.1 Message Router
 * @srs-ref NFR-U-04 Main menu accessible from ANY flow state
 * @srs-ref NFR-U-05 Support English and Malayalam
 */
class FlowRouter
{
    /*
    |--------------------------------------------------------------------------
    | Global Keywords — Checked BEFORE any session routing
    |--------------------------------------------------------------------------
    */

    /**
     * Keywords that ALWAYS trigger main menu (regardless of current flow).
     */
    protected const MENU_KEYWORDS = [
        // English
        'menu', 'home', 'start', 'main', 'reset', '0',
        'hi', 'hello', 'hey',
        // Malayalam
        'ഹായ്', 'ഹലോ', 'മെനു', 'തുടക്കം',
    ];

    /**
     * Keywords that trigger help.
     */
    protected const HELP_KEYWORDS = [
        'help', '?', 'support', 'how',
        'സഹായം', 'എങ്ങനെ',
    ];

    /**
     * Keywords that trigger cancel/back.
     */
    protected const CANCEL_KEYWORDS = [
        'cancel', 'exit', 'quit', 'stop', 'end', 'back',
        'റദ്ദാക്കുക', 'പുറത്ത്', 'മടങ്ങുക',
    ];

    /*
    |--------------------------------------------------------------------------
    | Module Shortcuts — Bilingual quick access
    |--------------------------------------------------------------------------
    */

    /**
     * Module shortcut keywords mapped to flows.
     * Checked BEFORE session routing for quick navigation.
     */
    protected const MODULE_SHORTCUTS = [
        // Fish Module (Pacha Meen)
        'fish' => 'fish_menu',
        'meen' => 'fish_menu',
        'മീൻ' => 'fish_menu',
        'pacha' => 'fish_menu',
        'പച്ച' => 'fish_menu',
        'pachameen' => 'fish_menu',
        'പച്ചമീൻ' => 'fish_menu',
        'fresh fish' => 'fish_browse',
        'catch' => 'fish_post_catch',

        // Jobs Module (Njaanum Panikkar)
        'job' => 'job_menu',
        'jobs' => 'job_menu',
        'work' => 'job_browse',
        'pani' => 'job_menu',
        'പണി' => 'job_menu',
        'panikkar' => 'job_menu',
        'പണിക്കാർ' => 'job_menu',
        'njaanum' => 'job_menu',
        'ഞാനും' => 'job_menu',
        'task' => 'job_post',
        'tasks' => 'job_poster_menu',
        'worker' => 'job_worker_menu',

        // Offers Module
        'offer' => 'browse_offers',
        'offers' => 'browse_offers',
        'deal' => 'browse_offers',
        'deals' => 'browse_offers',
        'ഓഫർ' => 'browse_offers',
        'browse' => 'browse_offers',

        // Flash Deals Module
        'flash' => 'flash_deals',
        '⚡' => 'flash_deals',
        'flash deal' => 'flash_deals',

        // Product Search
        'find' => 'search_product',
        'search' => 'search_product',
        'തിരയുക' => 'search_product',
        'കണ്ടെത്തുക' => 'search_product',

        // Agreements
        'agree' => 'create_agreement',
        'agreement' => 'create_agreement',
        'karar' => 'create_agreement',
        'കരാർ' => 'create_agreement',

        // Upload (for shop owners)
        'upload' => 'upload_offer',
        'post' => 'upload_offer',
    ];

    /*
    |--------------------------------------------------------------------------
    | Steps that expect specific input types
    |--------------------------------------------------------------------------
    */

    /**
     * Steps that expect numeric input (amount, price, phone, etc.).
     */
    protected const NUMERIC_INPUT_STEPS = [
        'ask_amount', 'enter_amount', 'ask_price', 'enter_price',
        'ask_phone', 'enter_phone', 'ask_quantity',
        'enter_pay_amount', 'ask_pay',
    ];

    /**
     * Steps that expect image input.
     */
    protected const IMAGE_INPUT_STEPS = [
        'upload_image', 'ask_image', 'upload_offer', 'offer_image',
        'ask_photo', 'upload_photo', 'catch_photo', 'arrival_photo',
        'product_photo', 'shop_photo', 'selfie',
    ];

    /**
     * Steps that expect location input.
     */
    protected const LOCATION_INPUT_STEPS = [
        'ask_location', 'get_location', 'shop_location', 'user_location',
        'select_location', 'confirm_location',
    ];

    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
    ) {}

    /**
     * Route an incoming message to the appropriate handler.
     *
     * ROUTING PRIORITY:
     * 1. Record message & mark as read
     * 2. Check global keywords (menu, help, cancel)
     * 3. Check module shortcuts
     * 4. Check notification button responses
     * 5. Smart input routing (numbers, images, locations)
     * 6. Route to current flow handler
     */
    public function route(IncomingMessage $message, ConversationSession $session): void
    {
        try {
            // 1. Record and acknowledge
            $this->sessionManager->recordMessage(
                $session,
                $message->messageId,
                $message->type
            );
            $this->whatsApp->markAsRead($message->messageId);

            // 2. GLOBAL KEYWORDS — Always checked first (NFR-U-04)
            if ($this->handleGlobalKeywords($message, $session)) {
                return;
            }

            // 3. MODULE SHORTCUTS — Quick navigation
            if ($this->handleModuleShortcuts($message, $session)) {
                return;
            }

            // 4. NOTIFICATION RESPONSES — Button clicks from notifications
            if ($this->handleNotificationResponses($message, $session)) {
                return;
            }

            // 5. SMART INPUT ROUTING — Context-aware input handling
            if ($this->handleSmartInputRouting($message, $session)) {
                return;
            }

            // 6. FLOW ROUTING — Route to current flow handler
            $this->routeToFlowHandler($message, $session);

        } catch (\Throwable $e) {
            Log::error('FlowRouter: Error routing message', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($message->from),
                'flow' => $session->current_flow,
                'step' => $session->current_step,
                'trace' => $e->getTraceAsString(),
            ]);

            $this->sendErrorWithMenu($message->from, MessageTemplates::ERROR_GENERIC);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 1. Global Keywords Handler
    |--------------------------------------------------------------------------
    */

    /**
     * Handle global keywords that work from ANY flow state.
     *
     * @srs-ref NFR-U-04 Main menu accessible from any flow state
     */
    protected function handleGlobalKeywords(IncomingMessage $message, ConversationSession $session): bool
    {
        if (!$message->isText()) {
            return false;
        }

        $text = $this->normalizeText($message->text ?? '');

        // Menu keywords — always return to main menu
        if ($this->matchesKeywords($text, self::MENU_KEYWORDS)) {
            Log::debug('FlowRouter: Menu keyword detected', ['text' => $text]);
            $this->goToMainMenu($session);
            return true;
        }

        // Help keywords
        if ($this->matchesKeywords($text, self::HELP_KEYWORDS)) {
            Log::debug('FlowRouter: Help keyword detected', ['text' => $text]);
            $this->showHelp($message->from, $session);
            return true;
        }

        // Cancel/back keywords (only if not idle)
        if ($this->matchesKeywords($text, self::CANCEL_KEYWORDS)) {
            if (!$this->sessionManager->isIdle($session)) {
                Log::debug('FlowRouter: Cancel keyword detected', ['text' => $text]);
                $this->handleCancel($session);
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | 2. Module Shortcuts Handler
    |--------------------------------------------------------------------------
    */

    /**
     * Handle module shortcut keywords for quick navigation.
     */
    protected function handleModuleShortcuts(IncomingMessage $message, ConversationSession $session): bool
    {
        if (!$message->isText()) {
            return false;
        }

        $text = $this->normalizeText($message->text ?? '');

        // Check exact match first
        if (isset(self::MODULE_SHORTCUTS[$text])) {
            $selectionId = self::MODULE_SHORTCUTS[$text];
            Log::debug('FlowRouter: Module shortcut detected', [
                'text' => $text,
                'selection' => $selectionId,
            ]);
            $this->handleMenuSelection($selectionId, $session);
            return true;
        }

        // Check if text starts with any shortcut
        foreach (self::MODULE_SHORTCUTS as $keyword => $selectionId) {
            if (str_starts_with($text, $keyword . ' ')) {
                Log::debug('FlowRouter: Module shortcut prefix detected', [
                    'text' => $text,
                    'keyword' => $keyword,
                    'selection' => $selectionId,
                ]);
                $this->handleMenuSelection($selectionId, $session);
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | 3. Notification Responses Handler
    |--------------------------------------------------------------------------
    */

    /**
     * Handle button clicks from notification messages.
     */
    protected function handleNotificationResponses(IncomingMessage $message, ConversationSession $session): bool
    {
        if (!$message->isInteractive()) {
            return false;
        }

        // Product request responses
        if ($this->handleProductRequestResponse($message, $session)) {
            return true;
        }

        // Fish alert responses
        if ($this->handleFishAlertResponse($message, $session)) {
            return true;
        }

        // Job notification responses
        if ($this->handleJobNotificationResponse($message, $session)) {
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | 4. Smart Input Routing
    |--------------------------------------------------------------------------
    */

    /**
     * Route input based on expected type for current step.
     *
     * Examples:
     * - User sends "5000" while in ASK_AMOUNT step → route to amount handler
     * - User sends image while in UPLOAD_OFFER step → route to image handler
     * - User sends location while in ASK_LOCATION step → route to location handler
     */
    protected function handleSmartInputRouting(IncomingMessage $message, ConversationSession $session): bool
    {
        $currentStep = strtolower($session->current_step);

        // Numeric input detection
        if ($message->isText() && $this->isNumericInput($message->text)) {
            if ($this->stepExpectsNumeric($currentStep)) {
                Log::debug('FlowRouter: Numeric input routed', [
                    'step' => $currentStep,
                    'value' => $message->text,
                ]);
                // Let flow handler process it
                return false;
            }
        }

        // Image input detection
        if ($message->isImage()) {
            if (!$this->stepExpectsImage($currentStep)) {
                // User sent image but we're not expecting one
                Log::debug('FlowRouter: Unexpected image received', [
                    'step' => $currentStep,
                    'flow' => $session->current_flow,
                ]);
                $this->handleUnexpectedImage($message, $session);
                return true;
            }
        }

        // Location input detection
        if ($message->isLocation()) {
            if (!$this->stepExpectsLocation($currentStep)) {
                Log::debug('FlowRouter: Unexpected location received', [
                    'step' => $currentStep,
                    'flow' => $session->current_flow,
                ]);
                $this->handleUnexpectedLocation($message, $session);
                return true;
            }
        }

        // Document input detection
        if ($message->isDocument()) {
            $this->handleDocumentInput($message, $session);
            return true;
        }

        return false;
    }

    /**
     * Check if input looks like a number (amount, price, phone, etc.).
     */
    protected function isNumericInput(?string $text): bool
    {
        if (!$text) {
            return false;
        }

        $cleaned = preg_replace('/[₹,\s]/', '', trim($text));
        return is_numeric($cleaned);
    }

    /**
     * Check if current step expects numeric input.
     */
    protected function stepExpectsNumeric(string $step): bool
    {
        foreach (self::NUMERIC_INPUT_STEPS as $pattern) {
            if (str_contains($step, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if current step expects image input.
     */
    protected function stepExpectsImage(string $step): bool
    {
        foreach (self::IMAGE_INPUT_STEPS as $pattern) {
            if (str_contains($step, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if current step expects location input.
     */
    protected function stepExpectsLocation(string $step): bool
    {
        foreach (self::LOCATION_INPUT_STEPS as $pattern) {
            if (str_contains($step, $pattern)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Handle unexpected image input.
     */
    protected function handleUnexpectedImage(IncomingMessage $message, ConversationSession $session): void
    {
        // Suggest what they might want to do with the image
        $isShopOwner = $this->isShopOwner($session);
        $isFishSeller = $this->isFishSeller($session);

        $buttons = [];

        if ($isShopOwner) {
            $buttons[] = ['id' => 'upload_offer', 'title' => '📤 Upload as Offer'];
        }

        if ($isFishSeller) {
            $buttons[] = ['id' => 'fish_post_catch', 'title' => '🐟 Post Fish Catch'];
        }

        $buttons[] = ['id' => 'main_menu', 'title' => '🏠 Main Menu'];

        $this->whatsApp->sendButtons(
            $message->from,
            "📷 *Image Received*\n\nWhat would you like to do with this image?",
            array_slice($buttons, 0, 3),
            '📷 Image',
            MessageTemplates::GLOBAL_FOOTER ?? null
        );
    }

    /**
     * Handle unexpected location input.
     */
    protected function handleUnexpectedLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $message->from,
            "📍 *Location Received*\n\nThanks for sharing your location! However, I wasn't expecting a location right now.\n\nWould you like to:",
            [
                ['id' => 'browse_offers', 'title' => '🛍️ Nearby Offers'],
                ['id' => 'fish_browse', 'title' => '🐟 Fresh Fish Nearby'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
            '📍 Location',
            MessageTemplates::GLOBAL_FOOTER ?? null
        );
    }

    /**
     * Handle document input (PDF, etc.).
     */
    protected function handleDocumentInput(IncomingMessage $message, ConversationSession $session): void
    {
        $filename = $message->getDocumentFilename() ?? 'document';
        $isPdf = $message->isPdf();

        if ($isPdf && $this->isShopOwner($session)) {
            // Could be an offer PDF
            $this->whatsApp->sendButtons(
                $message->from,
                "📄 *Document Received*\n\nFile: {$filename}\n\nWould you like to upload this as an offer?",
                [
                    ['id' => 'upload_offer', 'title' => '📤 Upload as Offer'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ],
                '📄 Document'
            );
        } else {
            $this->whatsApp->sendText(
                $message->from,
                "📄 I received your document ({$filename}), but I'm not sure what to do with it.\n\nType *menu* to see available options."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | 5. Flow Handler Routing
    |--------------------------------------------------------------------------
    */

    /**
     * Route to the appropriate flow handler based on session state.
     */
    protected function routeToFlowHandler(IncomingMessage $message, ConversationSession $session): void
    {
        // Get current flow type
        $flowType = $this->sessionManager->getCurrentFlowType($session);

        if (!$flowType) {
            // Default to main menu if flow is invalid
            Log::warning('FlowRouter: Invalid flow, defaulting to main menu', [
                'flow' => $session->current_flow,
            ]);
            $flowType = FlowType::MAIN_MENU;
            $this->sessionManager->setFlowStep($session, $flowType, 'show_menu');
        }

        // Check authorization
        if (!$this->checkFlowAuthorization($flowType, $message, $session)) {
            return;
        }

        // Resolve and dispatch to handler
        $handler = $this->resolveHandler($flowType);

        if (!$handler) {
            Log::error('FlowRouter: No handler found for flow', ['flow' => $flowType->value]);
            $this->sendErrorWithMenu($message->from, 'Something went wrong. Please try again.');
            return;
        }

        $handler->handle($message, $session);
    }

    /**
     * Check if user is authorized for the flow.
     */
    protected function checkFlowAuthorization(FlowType $flowType, IncomingMessage $message, ConversationSession $session): bool
    {
        // Check registration requirement
        if ($flowType->requiresAuth() && !$this->sessionManager->isRegistered($session)) {
            $this->handleNotRegistered($message, $session);
            return false;
        }

        // Check shop-only requirement
        if ($flowType->isShopOnly() && !$this->isShopOwner($session)) {
            $this->handleShopOnly($message, $session);
            return false;
        }

        // Check fish-seller-only requirement
        if ($flowType->isFishSellerOnly() && !$this->isFishSeller($session)) {
            $this->handleFishSellerOnly($message, $session);
            return false;
        }

        // Check job-worker-only requirement
        if ($flowType->isJobWorkerOnly() && !$this->isJobWorker($session)) {
            $this->handleJobWorkerOnly($message, $session);
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Product Request Response Handling
    |--------------------------------------------------------------------------
    */

    /**
     * Handle product request response buttons from notifications.
     */
    protected function handleProductRequestResponse(IncomingMessage $message, ConversationSession $session): bool
    {
        $buttonId = $this->extractSelectionId($message);

        if (!$buttonId) {
            return false;
        }

        // Pattern: "respond_yes_17" or "respond_no_17"
        if (preg_match('/^respond_(yes|no)_(\d+)$/', $buttonId, $matches)) {
            $action = $matches[1];
            $requestId = (int) $matches[2];

            Log::info('FlowRouter: Product request response', [
                'action' => $action,
                'request_id' => $requestId,
            ]);

            return $this->routeToProductResponseHandler($session, $requestId, $action);
        }

        // Legacy pattern: plain "yes" or "no"
        if (in_array($buttonId, ['yes', 'no']) && $session->current_flow !== 'product_respond') {
            $requestId = $this->sessionManager->getTempData($session, 'respond_request_id');

            if ($requestId) {
                return $this->routeToProductResponseHandler($session, (int) $requestId, $buttonId);
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

        Log::error('FlowRouter: Could not resolve ProductResponseFlowHandler');
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Fish Alert Response Handling
    |--------------------------------------------------------------------------
    */

    /**
     * Handle fish alert response buttons.
     */
    protected function handleFishAlertResponse(IncomingMessage $message, ConversationSession $session): bool
    {
        $buttonId = $this->extractSelectionId($message);

        if (!$buttonId) {
            return false;
        }

        // Pattern: "fish_coming_123_456"
        if (preg_match('/^fish_coming_(\d+)_(\d+)$/', $buttonId, $matches)) {
            $catchId = (int) $matches[1];
            $alertId = (int) $matches[2];
            return $this->routeToFishBrowseHandler($session, $catchId, 'coming', $alertId);
        }

        // Pattern: "fish_location_123_456"
        if (preg_match('/^fish_location_(\d+)_(\d+)$/', $buttonId, $matches)) {
            $catchId = (int) $matches[1];
            $alertId = (int) $matches[2];
            return $this->routeToFishBrowseHandler($session, $catchId, 'location', $alertId);
        }

        return false;
    }

    /**
     * Route to FishBrowseFlowHandler.
     */
    protected function routeToFishBrowseHandler(ConversationSession $session, int $catchId, string $action, int $alertId): bool
    {
        $handler = $this->resolveHandler(FlowType::FISH_BROWSE);

        if ($handler instanceof \App\Services\Flow\Handlers\Fish\FishBrowseFlowHandler) {
            $handler->handleAlertResponse($session, $catchId, $action, $alertId);
            return true;
        }

        Log::error('FlowRouter: Could not resolve FishBrowseFlowHandler');
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Job Notification Response Handling
    |--------------------------------------------------------------------------
    */

    /**
     * Handle job notification response buttons.
     */
    protected function handleJobNotificationResponse(IncomingMessage $message, ConversationSession $session): bool
    {
        $buttonId = $this->extractSelectionId($message);

        if (!$buttonId) {
            return false;
        }

        // Job apply: "job_apply_123"
        if (preg_match('/^job_apply_(\d+)$/', $buttonId, $matches)) {
            $jobId = (int) $matches[1];
            return $this->routeToJobHandler($session, FlowType::JOB_APPLICATION, $jobId, 'apply');
        }

        // Job view: "job_view_123"
        if (preg_match('/^job_view_(\d+)$/', $buttonId, $matches)) {
            $jobId = (int) $matches[1];
            return $this->routeToJobHandler($session, FlowType::JOB_APPLICATION, $jobId, 'view');
        }

        // Accept application: "job_accept_app_123"
        if (preg_match('/^job_accept_app_(\d+)$/', $buttonId, $matches)) {
            $applicationId = (int) $matches[1];
            return $this->routeToJobSelectionHandler($session, $applicationId, 'accept');
        }

        // Reject application: "job_reject_app_123"
        if (preg_match('/^job_reject_app_(\d+)$/', $buttonId, $matches)) {
            $applicationId = (int) $matches[1];
            return $this->routeToJobSelectionHandler($session, $applicationId, 'reject');
        }

        // Start job: "job_start_123"
        if (preg_match('/^job_start_(\d+)$/', $buttonId, $matches)) {
            $jobId = (int) $matches[1];
            return $this->routeToJobExecutionHandler($session, $jobId, 'start');
        }

        // Complete job: "job_complete_123"
        if (preg_match('/^job_complete_(\d+)$/', $buttonId, $matches)) {
            $jobId = (int) $matches[1];
            return $this->routeToJobExecutionHandler($session, $jobId, 'complete');
        }

        return false;
    }

    /**
     * Route to job application handler.
     */
    protected function routeToJobHandler(ConversationSession $session, FlowType $flowType, int $jobId, string $action): bool
    {
        $handler = $this->resolveHandler($flowType);

        if ($handler instanceof \App\Services\Flow\Handlers\Jobs\JobApplicationFlowHandler) {
            $showDetailsFirst = $action !== 'apply';
            $handler->startWithJob($session, $jobId, $showDetailsFirst);
            return true;
        }

        Log::error('FlowRouter: Could not resolve JobApplicationFlowHandler');
        return false;
    }

    /**
     * Route to job selection handler.
     */
    protected function routeToJobSelectionHandler(ConversationSession $session, int $applicationId, string $action): bool
    {
        $handler = $this->resolveHandler(FlowType::JOB_SELECTION);

        if ($handler instanceof \App\Services\Flow\Handlers\Jobs\JobSelectionFlowHandler) {
            $handler->startWithApplication($session, $applicationId);
            return true;
        }

        Log::error('FlowRouter: Could not resolve JobSelectionFlowHandler');
        return false;
    }

    /**
     * Route to job execution handler.
     */
    protected function routeToJobExecutionHandler(ConversationSession $session, int $jobId, string $action): bool
    {
        $handler = $this->resolveHandler(FlowType::JOB_EXECUTION);

        if ($handler instanceof \App\Services\Flow\Handlers\Jobs\JobExecutionFlowHandler) {
            $handler->startWithJob($session, $jobId);
            return true;
        }

        Log::error('FlowRouter: Could not resolve JobExecutionFlowHandler');
        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Menu & Navigation
    |--------------------------------------------------------------------------
    */

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
     * Show help message.
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
            MessageTemplates::GLOBAL_FOOTER ?? null
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
            MessageTemplates::GLOBAL_FOOTER ?? null
        );

        $this->goToMainMenu($session);
    }

    /**
     * Handle menu selection and route to appropriate flow.
     */
    public function handleMenuSelection(string $selectionId, ConversationSession $session): void
    {
        // Handle main_menu explicitly
        if ($selectionId === 'main_menu') {
            $this->goToMainMenu($session);
            return;
        }

        // Handle special selections
        if (in_array($selectionId, ['my_requests', 'view_responses'])) {
            $this->startMyRequestsFlow($session);
            return;
        }

        if ($selectionId === 'shop_profile') {
            $this->startShopProfileFlow($session);
            return;
        }

        // Handle fish menu routing
        if (in_array($selectionId, ['fish_menu', 'menu_fish_dashboard'])) {
            $flowType = $this->routeToFishMenu($session);
            $this->startFlow($session, $flowType);
            return;
        }

        // Handle job menu routing
        if (in_array($selectionId, ['job_menu', 'menu_job_dashboard', 'job_browse'])) {
            $this->handleJobMenuSelection($session);
            return;
        }

        // Map selection to flow
        $flowType = $this->mapSelectionToFlow($selectionId);

        if ($flowType) {
            $this->startFlow($session, $flowType);
        } else {
            Log::warning('FlowRouter: Unknown menu selection', ['id' => $selectionId]);
            $this->goToMainMenu($session);
        }
    }

    /**
     * Map menu selection ID to FlowType.
     */
    protected function mapSelectionToFlow(string $selectionId): ?FlowType
    {
        return match ($selectionId) {
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
            // Fish flows
            'fish_browse', 'menu_fish_browse' => FlowType::FISH_BROWSE,
            'fish_subscribe', 'menu_fish_subscribe', 'fish_alerts' => FlowType::FISH_SUBSCRIBE,
            'fish_manage_alerts', 'menu_fish_manage' => FlowType::FISH_MANAGE_SUBSCRIPTION,
            'fish_seller_register', 'menu_fish_seller_register' => FlowType::FISH_SELLER_REGISTER,
            'fish_post_catch', 'menu_fish_post' => FlowType::FISH_POST_CATCH,
            'fish_update_stock', 'menu_fish_stock', 'fish_my_catches', 'view_my_catches' => FlowType::FISH_STOCK_UPDATE,
            'fish_seller_menu' => FlowType::FISH_SELLER_MENU,
            // Job flows
            'menu_job_browse', 'find_work' => FlowType::JOB_BROWSE,
            'job_post', 'menu_job_post', 'post_task' => FlowType::JOB_POST,
            'job_worker_register', 'menu_job_worker_register', 'become_worker' => FlowType::JOB_WORKER_REGISTER,
            'job_worker_menu', 'menu_job_worker_dashboard', 'worker_dashboard' => FlowType::JOB_WORKER_MENU,
            'job_poster_menu', 'menu_job_poster_dashboard', 'my_tasks', 'posted_tasks' => FlowType::JOB_POSTER_MENU,
            'job_applications', 'menu_job_applications', 'view_applications' => FlowType::JOB_APPLICATIONS,
            default => null,
        };
    }

    /**
     * Handle job menu selection with worker check.
     */
    protected function handleJobMenuSelection(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);

        if ($user?->jobWorker !== null) {
            $this->startFlow($session, FlowType::JOB_WORKER_MENU);
            return;
        }

        // Show landing menu for non-workers
        $this->showJobsLandingMenu($session);
    }

    /**
     * Show jobs landing menu.
     */
    protected function showJobsLandingMenu(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);
        $isWorker = $user?->jobWorker !== null;

        $message = "👷 *Jobs / Njaanum Panikkar*\n" .
            "*ജോലികൾ / ഞാനും പണിക്കാർ*\n\n";

        if ($isWorker) {
            $message .= "Welcome back! What would you like to do?";
            $buttons = [
                ['id' => 'job_browse', 'title' => '🔍 Find Work'],
                ['id' => 'job_worker_menu', 'title' => '👷 My Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ];
        } else {
            $message .= "Post tasks for workers or become a worker yourself!";
            $buttons = [
                ['id' => 'job_post', 'title' => '📝 Post a Task'],
                ['id' => 'job_worker_register', 'title' => '👷 Become Worker'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ];
        }

        $this->whatsApp->sendButtons($session->phone, $message, $buttons, '👷 Jobs');
    }

    /**
     * Route to fish menu based on user's seller status.
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
     * Start a flow.
     */
    public function startFlow(ConversationSession $session, FlowType $flowType): void
    {
        // Create a dummy message for authorization checks
        $dummyMessage = new IncomingMessage(
            messageId: '',
            from: $session->phone,
            type: 'text',
            timestamp: now()
        );

        if (!$this->checkFlowAuthorization($flowType, $dummyMessage, $session)) {
            return;
        }

        $this->sessionManager->clearTempData($session);
        $this->sessionManager->setFlowStep($session, $flowType, $flowType->initialStep());

        $handler = $this->resolveHandler($flowType);
        $handler?->start($session);
    }

    /**
     * Start My Requests flow.
     */
    protected function startMyRequestsFlow(ConversationSession $session): void
    {
        $handler = $this->resolveHandler(FlowType::PRODUCT_SEARCH);

        if ($handler instanceof \App\Services\Flow\Handlers\ProductSearchFlowHandler) {
            $handler->startMyRequests($session);
        } else {
            $this->startFlow($session, FlowType::PRODUCT_SEARCH);
        }
    }

    /**
     * Start Shop Profile flow.
     */
    protected function startShopProfileFlow(ConversationSession $session): void
    {
        if (!$this->isShopOwner($session)) {
            $this->handleShopOnly(
                new IncomingMessage(messageId: '', from: $session->phone, type: 'text', timestamp: now()),
                $session
            );
            return;
        }

        $handler = $this->resolveHandler(FlowType::SETTINGS);

        if ($handler instanceof \App\Services\Flow\Handlers\SettingsFlowHandler) {
            $handler->startShopProfile($session);
        } else {
            $this->startFlow($session, FlowType::SETTINGS);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization Handlers
    |--------------------------------------------------------------------------
    */

    protected function handleNotRegistered(IncomingMessage $message, ConversationSession $session): void
    {
        $error = ErrorTemplate::notRegistered();

        $this->whatsApp->sendButtons(
            $message->from,
            $error['message'],
            $error['buttons'],
            '📝 Registration Required',
            MessageTemplates::GLOBAL_FOOTER ?? null
        );
    }

    protected function handleShopOnly(IncomingMessage $message, ConversationSession $session): void
    {
        $error = ErrorTemplate::shopOnly();

        $this->whatsApp->sendButtons(
            $message->from,
            $error['message'],
            $error['buttons'],
            '🏪 Shop Feature',
            MessageTemplates::GLOBAL_FOOTER ?? null
        );
    }

    protected function handleFishSellerOnly(IncomingMessage $message, ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $message->from,
            "🐟 *Fish Seller Feature*\n\nThis feature is only available for registered fish sellers.\n\nWould you like to register?",
            [
                ['id' => 'fish_seller_register', 'title' => '🐟 Register as Seller'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
            '🐟 Fish Seller Required'
        );
    }

    protected function handleJobWorkerOnly(IncomingMessage $message, ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $message->from,
            "👷 *Worker Feature*\n\nThis feature is only available for registered workers.\n\nWould you like to register?",
            [
                ['id' => 'job_worker_register', 'title' => '👷 Register as Worker'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
            '👷 Worker Required'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | User Type Checks
    |--------------------------------------------------------------------------
    */

    protected function isShopOwner(ConversationSession $session): bool
    {
        $user = $this->sessionManager->getUser($session);
        return $user?->isShopOwner() ?? false;
    }

    protected function isFishSeller(ConversationSession $session): bool
    {
        $user = $this->sessionManager->getUser($session);
        return $user?->fishSeller !== null;
    }

    protected function isJobWorker(ConversationSession $session): bool
    {
        $user = $this->sessionManager->getUser($session);
        return $user?->jobWorker !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Utilities
    |--------------------------------------------------------------------------
    */

    /**
     * Resolve handler for a flow type.
     */
    protected function resolveHandler(FlowType $flowType): ?FlowHandlerInterface
    {
        $handlerClass = $flowType->handlerClass();

        if (!class_exists($handlerClass)) {
            Log::warning('FlowRouter: Handler class does not exist', ['class' => $handlerClass]);
            return null;
        }

        return app($handlerClass);
    }

    /**
     * Extract selection ID from interactive message.
     */
    protected function extractSelectionId(IncomingMessage $message): ?string
    {
        if ($message->isButtonReply()) {
            return $message->getButtonId();
        }

        if ($message->isListReply()) {
            return $message->getListId();
        }

        // Fallback to raw interactive data
        $interactive = $message->interactive ?? [];

        return $interactive['button_reply']['id']
            ?? $interactive['list_reply']['id']
            ?? null;
    }

    /**
     * Send error with menu button.
     */
    protected function sendErrorWithMenu(string $phone, string $message): void
    {
        $this->whatsApp->sendButtons(
            $phone,
            $message,
            [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ]
        );
    }

    /**
     * Normalize text for comparison.
     */
    protected function normalizeText(string $text): string
    {
        return mb_strtolower(trim($text));
    }

    /**
     * Check if text matches any keyword.
     */
    protected function matchesKeywords(string $text, array $keywords): bool
    {
        return in_array($text, $keywords, true);
    }

    /**
     * Mask phone for logging.
     */
    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return $phone;
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }
}