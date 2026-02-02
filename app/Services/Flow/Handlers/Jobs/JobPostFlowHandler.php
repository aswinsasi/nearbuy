<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\JobCategory;
use App\Enums\JobPostingStep;
use App\Models\ConversationSession;
use App\Models\JobCategory as JobCategoryModel;
use App\Models\JobPost;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Flow\FlowRouter;
use App\Services\Jobs\JobPostingService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\JobMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for job posting flow.
 *
 * Features:
 * - Multi-step job posting wizard
 * - Category selection with "Other" option for custom job types
 * - Location sharing support
 * - Pay suggestion based on category
 * - Confirmation before posting
 *
 * @srs-ref Section 3.3 - Job Posting Flow
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 * 
 * UPDATED: Added support for "Other" category with custom text
 */
class JobPostFlowHandler extends AbstractFlowHandler
{
    /**
     * ID for "Other" category in the list.
     */
    protected const OTHER_CATEGORY_ID = 'other';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected JobPostingService $postingService,
        protected FlowRouter $flowRouter
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::JOB_POST;
    }

    protected function getSteps(): array
    {
        return JobPostingStep::values();
    }

    protected function getExpectedInputType(string $step): string
    {
        $stepEnum = JobPostingStep::tryFrom($step);
        return $stepEnum?->expectedInput() ?? 'text';
    }

    /**
     * Start the job posting flow.
     */
    public function start(ConversationSession $session): void
    {
        $this->logInfo('Starting job posting flow', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        $this->clearTemp($session);
        $this->nextStep($session, JobPostingStep::SELECT_CATEGORY->value);
        $this->askCategory($session);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle common navigation
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

         $selectionId = $this->getSelectionId($message);
            if ($this->handleJobButtons($selectionId, $session)) {
                return;
            }

        $step = JobPostingStep::tryFrom($session->current_step);
        
        // Debug logging - more detailed
        Log::debug('JobPostFlowHandler', [
            'step' => $session->current_step,
            'message_type' => $message->type,
            'is_button_reply' => $message->isButtonReply(),
            'is_list_reply' => $message->isListReply(),
            'interactive' => $message->interactive ?? null,
            'selection_id' => $this->getSelectionId($message),
        ]);

        if (!$step) {
            $this->start($session);
            return;
        }

        match ($step) {
            JobPostingStep::SELECT_CATEGORY => $this->handleCategorySelection($message, $session),
            JobPostingStep::ENTER_CUSTOM_CATEGORY => $this->handleCustomCategory($message, $session),
            JobPostingStep::ENTER_TITLE => $this->handleTitle($message, $session),
            JobPostingStep::ENTER_DESCRIPTION => $this->handleDescription($message, $session),
            JobPostingStep::ENTER_LOCATION => $this->handleLocation($message, $session),
            JobPostingStep::REQUEST_LOCATION_COORDS => $this->handleLocationCoords($message, $session),
            JobPostingStep::SELECT_DATE => $this->handleDate($message, $session),
            JobPostingStep::ENTER_TIME => $this->handleTime($message, $session),
            JobPostingStep::ENTER_CUSTOM_TIME => $this->handleCustomTime($message, $session),
            JobPostingStep::SELECT_DURATION => $this->handleDuration($message, $session),
            JobPostingStep::SUGGEST_PAY => $this->handlePaySuggestion($message, $session),
            JobPostingStep::ENTER_PAY => $this->handlePay($message, $session),
            JobPostingStep::ENTER_INSTRUCTIONS => $this->handleInstructions($message, $session),
            JobPostingStep::CONFIRM_POST => $this->handleConfirmation($message, $session),
            JobPostingStep::COMPLETE => $this->goToMainMenu($session),
            default => $this->start($session),
        };
    }

    /**
     * Handle common navigation commands.
     */
    protected function handleCommonNavigation(IncomingMessage $message, ConversationSession $session): bool
    {
        $selection = $this->getSelectionId($message);

        if ($selection === 'main_menu' || $selection === 'cancel') {
            $this->goToMainMenu($session);
            return true;
        }

        if ($selection === 'back') {
            $this->goBack($session);
            return true;
        }

        return false;
    }

    /**
     * Go back to previous step.
     */
    protected function goBack(ConversationSession $session): void
    {
        $currentStep = JobPostingStep::tryFrom($session->current_step);
        
        if (!$currentStep) {
            $this->start($session);
            return;
        }

        // Handle special back navigation for custom category
        if ($currentStep === JobPostingStep::ENTER_TITLE) {
            $hasCustomCategory = $this->getTemp($session, 'custom_category_text');
            if ($hasCustomCategory) {
                $this->nextStep($session, JobPostingStep::ENTER_CUSTOM_CATEGORY->value);
                $this->askCustomCategory($session);
                return;
            }
        }

        $previousStep = $currentStep->previous();

        if ($previousStep) {
            $this->nextStep($session, $previousStep->value);
            $this->showStepPrompt($session, $previousStep);
        } else {
            $this->start($session);
        }
    }

    /**
     * Show prompt for a specific step.
     */
    protected function showStepPrompt(ConversationSession $session, JobPostingStep $step): void
    {
        match ($step) {
            JobPostingStep::SELECT_CATEGORY => $this->askCategory($session),
            JobPostingStep::ENTER_CUSTOM_CATEGORY => $this->askCustomCategory($session),
            JobPostingStep::ENTER_TITLE => $this->askTitle($session),
            JobPostingStep::ENTER_DESCRIPTION => $this->askDescription($session),
            JobPostingStep::ENTER_LOCATION => $this->askLocation($session),
            JobPostingStep::REQUEST_LOCATION_COORDS => $this->askLocationCoords($session),
            JobPostingStep::SELECT_DATE => $this->askDate($session),
            JobPostingStep::ENTER_TIME => $this->askTime($session),
            JobPostingStep::ENTER_CUSTOM_TIME => $this->askCustomTime($session),
            JobPostingStep::SELECT_DURATION => $this->askDuration($session),
            JobPostingStep::SUGGEST_PAY => $this->suggestPay($session),
            JobPostingStep::ENTER_PAY => $this->askPay($session),
            JobPostingStep::ENTER_INSTRUCTIONS => $this->askInstructions($session),
            JobPostingStep::CONFIRM_POST => $this->showConfirmation($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Category Selection
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user to select a category.
     */
    protected function askCategory(ConversationSession $session): void
    {
        // WhatsApp limit: max 10 TOTAL items across all sections
        // So we can only show 9 categories + 1 "Other" option
        $categories = JobCategoryModel::where('is_active', true)
            ->orderBy('sort_order')
            ->limit(9)  // Leave room for "Other" option
            ->get();

        $rows = [];
        foreach ($categories as $category) {
            // Truncate description to max 72 characters (WhatsApp limit)
            $description = $category->description ?? '';
            if (strlen($description) > 72) {
                $description = substr($description, 0, 69) . '...';
            }
            
            $rows[] = [
                'id' => "cat_{$category->id}",
                'title' => $category->icon . ' ' . $category->name,
                'description' => $description,
            ];
        }

        // Add "Other" option at the end (total becomes 10)
        $rows[] = [
            'id' => self::OTHER_CATEGORY_ID,
            'title' => '🔧 Other / മറ്റുള്ളവ',
            'description' => 'Specify a custom job type',
        ];

        $this->whatsApp->sendList(
            $session->phone,
            JobMessages::categorySelection(),
            'Select Category',
            [['title' => 'Job Types', 'rows' => $rows]],
            '📋 Post Job'
        );
    }

    /**
     * Handle category selection.
     */
    protected function handleCategorySelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        if (!$selection) {
            $this->askCategory($session);
            return;
        }

        // Check if "Other" was selected
        if ($selection === self::OTHER_CATEGORY_ID) {
            $this->setTemp($session, 'job_category_id', null);
            $this->nextStep($session, JobPostingStep::ENTER_CUSTOM_CATEGORY->value);
            $this->askCustomCategory($session);
            return;
        }

        // Extract category ID from selection (format: "cat_123")
        if (preg_match('/^cat_(\d+)$/', $selection, $matches)) {
            $categoryId = (int) $matches[1];
            $category = JobCategoryModel::find($categoryId);

            if ($category) {
                $this->setTemp($session, 'job_category_id', $categoryId);
                $this->setTemp($session, 'custom_category_text', null); // Clear any custom text
                
                // Store pay range for later suggestion
                $this->setTemp($session, 'suggested_pay_min', $category->min_pay ?? 200);
                $this->setTemp($session, 'suggested_pay_max', $category->max_pay ?? 500);

                $this->nextStep($session, JobPostingStep::ENTER_TITLE->value);
                $this->askTitle($session);
                return;
            }
        }

        // Invalid selection
        $this->sendText($session->phone, "❌ Please select a valid category.");
        $this->askCategory($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1b: Custom Category (for "Other" selection)
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user to enter custom category text.
     */
    protected function askCustomCategory(ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $session->phone,
            JobMessages::customCategoryPrompt(),
            [
                ['id' => 'back', 'title' => '⬅️ Back'],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
            ],
            '✏️ Custom Job Type'
        );
    }

    /**
     * Handle custom category input.
     */
    protected function handleCustomCategory(IncomingMessage $message, ConversationSession $session): void
    {
        // If user selects from a list (old category list), redirect to appropriate handler
        $selection = $this->getSelectionId($message);
        
        // Check if user clicked back or cancel
        if ($selection === 'back') {
            $this->nextStep($session, JobPostingStep::SELECT_CATEGORY->value);
            $this->askCategory($session);
            return;
        }
        
        if ($selection === 'cancel') {
            $this->goToMainMenu($session);
            return;
        }
        
        // If user selected from old category list, handle it
        if ($selection === self::OTHER_CATEGORY_ID) {
            // Already in custom category mode, just reprompt
            $this->askCustomCategory($session);
            return;
        }
        
        if ($selection && preg_match('/^cat_(\d+)$/', $selection, $matches)) {
            // User selected a category from old list - process it
            $categoryId = (int) $matches[1];
            $category = JobCategoryModel::find($categoryId);
            
            if ($category) {
                $this->setTemp($session, 'job_category_id', $categoryId);
                $this->setTemp($session, 'custom_category_text', null);
                $this->setTemp($session, 'suggested_pay_min', $category->min_pay ?? 200);
                $this->setTemp($session, 'suggested_pay_max', $category->max_pay ?? 500);
                
                $this->nextStep($session, JobPostingStep::ENTER_TITLE->value);
                $this->askTitle($session);
                return;
            }
        }
        
        // For interactive selections that aren't handled above, reprompt
        if (!$message->isText()) {
            $this->askCustomCategory($session);
            return;
        }

        $customText = trim($message->text ?? '');

        // Validate custom category text
        if (strlen($customText) < 3) {
            $this->sendText($session->phone, "❌ Job type must be at least 3 characters.");
            $this->askCustomCategory($session);
            return;
        }

        if (strlen($customText) > 100) {
            $this->sendText($session->phone, "❌ Job type must be less than 100 characters.");
            $this->askCustomCategory($session);
            return;
        }

        // Check for invalid characters
        if (preg_match('/[<>{}\\[\\]]/', $customText)) {
            $this->sendText($session->phone, JobMessages::customCategoryInvalid());
            $this->askCustomCategory($session);
            return;
        }

        // Store custom category
        $this->setTemp($session, 'custom_category_text', $customText);
        $this->setTemp($session, 'job_category_id', null);

        // Set default pay range for custom categories
        $this->setTemp($session, 'suggested_pay_min', 200);
        $this->setTemp($session, 'suggested_pay_max', 1000);

        $this->sendText($session->phone, JobMessages::customCategoryConfirmed($customText));

        $this->nextStep($session, JobPostingStep::ENTER_TITLE->value);
        $this->askTitle($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Title
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user for job title.
     */
    protected function askTitle(ConversationSession $session): void
    {
        $message = JobPostingStep::ENTER_TITLE->instruction();

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'back', 'title' => '⬅️ Back'],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
            ],
            '✏️ Job Title'
        );
    }

    /**
     * Handle title input.
     */
    protected function handleTitle(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->sendText($session->phone, "❌ Please type the job title.");
            return;
        }

        $title = trim($message->text ?? '');

        if (strlen($title) < 5) {
            $this->sendText($session->phone, "❌ Title must be at least 5 characters.");
            return;
        }

        if (strlen($title) > 100) {
            $this->sendText($session->phone, "❌ Title must be less than 100 characters.");
            return;
        }

        $this->setTemp($session, 'title', $title);
        $this->nextStep($session, JobPostingStep::ENTER_DESCRIPTION->value);
        $this->askDescription($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Description
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user for job description.
     */
    protected function askDescription(ConversationSession $session): void
    {
        $message = JobPostingStep::ENTER_DESCRIPTION->instruction();

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ],
            '📝 Description'
        );
    }

    /**
     * Handle description input.
     */
    protected function handleDescription(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        if ($selection === 'skip') {
            $this->setTemp($session, 'description', null);
            $this->nextStep($session, JobPostingStep::ENTER_LOCATION->value);
            $this->askLocation($session);
            return;
        }

        if ($message->isText()) {
            $description = trim($message->text ?? '');

            if (strlen($description) > 500) {
                $this->sendText($session->phone, "❌ Description must be less than 500 characters.");
                return;
            }

            $this->setTemp($session, 'description', $description);
            $this->nextStep($session, JobPostingStep::ENTER_LOCATION->value);
            $this->askLocation($session);
            return;
        }

        $this->askDescription($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Location Name
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user for location name.
     */
    protected function askLocation(ConversationSession $session): void
    {
        $message = JobPostingStep::ENTER_LOCATION->instruction();

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'back', 'title' => '⬅️ Back'],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
            ],
            '📍 Location'
        );
    }

    /**
     * Handle location name input.
     */
    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->sendText($session->phone, "❌ Please type the location name.");
            return;
        }

        $location = trim($message->text ?? '');

        if (strlen($location) < 5) {
            $this->sendText($session->phone, "❌ Location must be at least 5 characters.");
            return;
        }

        $this->setTemp($session, 'location_name', $location);
        $this->nextStep($session, JobPostingStep::REQUEST_LOCATION_COORDS->value);
        $this->askLocationCoords($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 5: Location Coordinates
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user to share location coordinates.
     */
    protected function askLocationCoords(ConversationSession $session): void
    {
        $message = JobPostingStep::REQUEST_LOCATION_COORDS->instruction();

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ],
            '🗺️ Share Location'
        );
    }

    /**
     * Handle location coordinates input.
     */
    protected function handleLocationCoords(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        if ($selection === 'skip') {
            $this->nextStep($session, JobPostingStep::SELECT_DATE->value);
            $this->askDate($session);
            return;
        }

        if ($message->isLocation()) {
            // Access location from the message's location property
            $location = $message->location ?? [];
            $latitude = $location['latitude'] ?? null;
            $longitude = $location['longitude'] ?? null;
            
            if ($latitude && $longitude) {
                $this->setTemp($session, 'latitude', $latitude);
                $this->setTemp($session, 'longitude', $longitude);
                
                $this->nextStep($session, JobPostingStep::SELECT_DATE->value);
                $this->askDate($session);
                return;
            }
        }

        $this->askLocationCoords($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 6: Date Selection
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user to select job date.
     */
    protected function askDate(ConversationSession $session): void
    {
        $message = JobPostingStep::SELECT_DATE->instruction();

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'today', 'title' => '📅 Today'],
                ['id' => 'tomorrow', 'title' => '📅 Tomorrow'],
                ['id' => 'custom_date', 'title' => '📆 Other Date'],
            ],
            '📅 Job Date'
        );
    }

    /**
     * Handle date selection.
     */
    protected function handleDate(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        $date = match ($selection) {
            'today' => now()->format('Y-m-d'),
            'tomorrow' => now()->addDay()->format('Y-m-d'),
            default => null,
        };

        if ($selection === 'custom_date') {
            $this->sendText($session->phone, "📆 Enter the date (DD/MM/YYYY):");
            $this->setTemp($session, 'awaiting_custom_date', true);
            return;
        }

        // Handle custom date input
        if ($this->getTemp($session, 'awaiting_custom_date') && $message->isText()) {
            $dateText = trim($message->text ?? '');
            
            // Try to parse date
            try {
                $parsed = \Carbon\Carbon::createFromFormat('d/m/Y', $dateText);
                if ($parsed && $parsed->isFuture()) {
                    $date = $parsed->format('Y-m-d');
                    $this->setTemp($session, 'awaiting_custom_date', false);
                } else {
                    $this->sendText($session->phone, "❌ Please enter a future date.");
                    return;
                }
            } catch (\Exception $e) {
                $this->sendText($session->phone, "❌ Invalid date format. Use DD/MM/YYYY.");
                return;
            }
        }

        if ($date) {
            $this->setTemp($session, 'job_date', $date);
            $this->nextStep($session, JobPostingStep::ENTER_TIME->value);
            $this->askTime($session);
            return;
        }

        $this->askDate($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 7: Time
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user for job time.
     */
    protected function askTime(ConversationSession $session): void
    {
        $message = "⏰ *When should this job start?*\n*ജോലി എപ്പോൾ തുടങ്ങണം?*\n\n" .
            "Select a time slot or enter your own:\n" .
            "ഒരു സമയം തിരഞ്ഞെടുക്കുക:";

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            'Select Time',
            [[
                'title' => 'Time Options',
                'rows' => [
                    ['id' => 'morning', 'title' => '🌅 Morning (9:00 AM)', 'description' => 'Start at 9 AM'],
                    ['id' => 'afternoon', 'title' => '☀️ Afternoon (2:00 PM)', 'description' => 'Start at 2 PM'],
                    ['id' => 'evening', 'title' => '🌆 Evening (5:00 PM)', 'description' => 'Start at 5 PM'],
                    ['id' => 'custom_time', 'title' => '⌨️ Enter Custom Time', 'description' => 'Type your preferred time'],
                ],
            ]],
            '⏰ Job Time'
        );
    }
    
    /**
     * Ask for custom time input.
     */
    protected function askCustomTime(ConversationSession $session): void
    {
        $this->sendText(
            $session->phone,
            "⏰ *Enter your preferred time*\n*നിങ്ങൾ ഇഷ്ടപ്പെടുന്ന സമയം നൽകുക*\n\n" .
            "Examples:\n" .
            "• 9:00 AM\n" .
            "• 10:30 AM\n" .
            "• 2:00 PM\n" .
            "• 14:30\n\n" .
            "_You can use 12-hour (AM/PM) or 24-hour format_"
        );
    }

    /**
     * Handle time input.
     */
    protected function handleTime(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        // Handle preset time selections (store in 24-hour format for MySQL)
        $time = match ($selection) {
            'morning' => '09:00:00',
            'afternoon' => '14:00:00',
            'evening' => '17:00:00',
            default => null,
        };
        
        // Also store display version for confirmation screen
        $timeDisplay = match ($selection) {
            'morning' => '9:00 AM',
            'afternoon' => '2:00 PM',
            'evening' => '5:00 PM',
            default => null,
        };

        if ($selection === 'custom_time') {
            $this->nextStep($session, JobPostingStep::ENTER_CUSTOM_TIME->value);
            $this->askCustomTime($session);
            return;
        }
        
        // Handle custom time text input
        if (!$time && $message->isText()) {
            $inputTime = trim($message->text ?? '');
            $parsed = $this->parseTimeInput($inputTime);
            
            if ($parsed) {
                $time = $parsed['mysql'];
                $timeDisplay = $parsed['display'];
            } else {
                $this->sendText(
                    $session->phone,
                    "❌ Invalid time format. Please use formats like:\n" .
                    "• 9:00 AM\n" .
                    "• 2:30 PM\n" .
                    "• 14:30"
                );
                $this->askTime($session);
                return;
            }
        }

        if ($time) {
            $this->setTemp($session, 'job_time', $time);
            $this->setTemp($session, 'job_time_display', $timeDisplay);
            $this->nextStep($session, JobPostingStep::SELECT_DURATION->value);
            $this->askDuration($session);
            return;
        }

        $this->askTime($session);
    }
    
    /**
     * Handle custom time input.
     */
    protected function handleCustomTime(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->askCustomTime($session);
            return;
        }
        
        $inputTime = trim($message->text ?? '');
        $parsed = $this->parseTimeInput($inputTime);
        
        if (!$parsed) {
            $this->sendText(
                $session->phone,
                "❌ Invalid time format.\n\n" .
                "Please enter time like:\n" .
                "• 9:00 AM\n" .
                "• 10:30 AM\n" .
                "• 2:00 PM\n" .
                "• 14:30"
            );
            $this->askCustomTime($session);
            return;
        }
        
        $this->setTemp($session, 'job_time', $parsed['mysql']);
        $this->setTemp($session, 'job_time_display', $parsed['display']);
        $this->nextStep($session, JobPostingStep::SELECT_DURATION->value);
        $this->askDuration($session);
    }
    
    /**
     * Parse user time input to MySQL format.
     * 
     * @param string $input User input like "9:00 AM", "2:30 PM", "14:30"
     * @return array|null ['mysql' => 'HH:MM:SS', 'display' => 'H:MM AM/PM'] or null if invalid
     */
    protected function parseTimeInput(string $input): ?array
    {
        $input = trim(strtoupper($input));
        
        // Try 12-hour format: 9:00 AM, 9:00AM, 9 AM, 9AM
        if (preg_match('/^(\d{1,2}):?(\d{2})?\s*(AM|PM)$/i', $input, $matches)) {
            $hour = (int) $matches[1];
            $minute = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
            $period = strtoupper($matches[3]);
            
            // Validate hour and minute
            if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
                return null;
            }
            
            // Convert to 24-hour
            if ($period === 'AM') {
                $hour24 = ($hour === 12) ? 0 : $hour;
            } else {
                $hour24 = ($hour === 12) ? 12 : $hour + 12;
            }
            
            return [
                'mysql' => sprintf('%02d:%02d:00', $hour24, $minute),
                'display' => sprintf('%d:%02d %s', $hour, $minute, $period),
            ];
        }
        
        // Try 24-hour format: 14:30, 09:00
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $input, $matches)) {
            $hour = (int) $matches[1];
            $minute = (int) $matches[2];
            
            // Validate
            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                return null;
            }
            
            // Convert to 12-hour for display
            $period = $hour >= 12 ? 'PM' : 'AM';
            $hour12 = $hour % 12;
            if ($hour12 === 0) $hour12 = 12;
            
            return [
                'mysql' => sprintf('%02d:%02d:00', $hour, $minute),
                'display' => sprintf('%d:%02d %s', $hour12, $minute, $period),
            ];
        }
        
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Step 8: Duration
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user for estimated duration.
     */
    protected function askDuration(ConversationSession $session): void
    {
        $message = JobPostingStep::SELECT_DURATION->instruction();

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            'Select Duration',
            [[
                'title' => 'Duration Options',
                'rows' => [
                    ['id' => '30min', 'title' => '⏱️ 30 minutes', 'description' => 'Quick task'],
                    ['id' => '1hr', 'title' => '⏱️ 1 hour', 'description' => 'Short task'],
                    ['id' => '2hr', 'title' => '⏱️ 2 hours', 'description' => 'Medium task'],
                    ['id' => '3hr', 'title' => '⏱️ 3 hours', 'description' => 'Longer task'],
                    ['id' => 'halfday', 'title' => '⏱️ Half day', 'description' => '4-5 hours'],
                    ['id' => 'fullday', 'title' => '⏱️ Full day', 'description' => '8+ hours'],
                ],
            ]],
            '⏱️ Duration'
        );
    }

    /**
     * Handle duration selection.
     */
    protected function handleDuration(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        $duration = match ($selection) {
            '30min' => '30 minutes',
            '1hr' => '1 hour',
            '2hr' => '2 hours',
            '3hr' => '3 hours',
            'halfday' => 'Half day',
            'fullday' => 'Full day',
            default => null,
        };

        if ($duration) {
            $this->setTemp($session, 'estimated_duration', $duration);
            $this->nextStep($session, JobPostingStep::SUGGEST_PAY->value);
            $this->suggestPay($session);
            return;
        }

        $this->askDuration($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 9: Pay Suggestion
    |--------------------------------------------------------------------------
    */

    /**
     * Suggest pay based on category.
     */
    protected function suggestPay(ConversationSession $session): void
    {
        $minPay = $this->getTemp($session, 'suggested_pay_min') ?? 200;
        $maxPay = $this->getTemp($session, 'suggested_pay_max') ?? 500;
        $suggestedPay = (int) (($minPay + $maxPay) / 2);

        $this->setTemp($session, 'suggested_pay', $suggestedPay);

        $message = str_replace(
            ['{min}', '{max}'],
            [$minPay, $maxPay],
            JobPostingStep::SUGGEST_PAY->instruction()
        );

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => "pay_{$suggestedPay}", 'title' => "₹{$suggestedPay}"],
                ['id' => 'custom_pay', 'title' => '💵 Enter Amount'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ],
            '💰 Payment'
        );
    }

    /**
     * Handle pay suggestion response.
     */
    protected function handlePaySuggestion(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        if ($selection === 'custom_pay') {
            $this->nextStep($session, JobPostingStep::ENTER_PAY->value);
            $this->askPay($session);
            return;
        }

        if (preg_match('/^pay_(\d+)$/', $selection ?? '', $matches)) {
            $pay = (int) $matches[1];
            $this->setTemp($session, 'pay_amount', $pay);
            $this->nextStep($session, JobPostingStep::ENTER_INSTRUCTIONS->value);
            $this->askInstructions($session);
            return;
        }

        $this->suggestPay($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 10: Custom Pay
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user for custom pay amount.
     */
    protected function askPay(ConversationSession $session): void
    {
        $message = JobPostingStep::ENTER_PAY->instruction();

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'back', 'title' => '⬅️ Back'],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
            ],
            '💵 Payment Amount'
        );
    }

    /**
     * Handle custom pay input.
     */
    protected function handlePay(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->sendText($session->phone, "❌ Please type the pay amount.");
            return;
        }

        $text = trim($message->text ?? '');
        $pay = (int) preg_replace('/[^0-9]/', '', $text);

        if ($pay < 50) {
            $this->sendText($session->phone, "❌ Minimum pay is ₹50.");
            return;
        }

        if ($pay > 50000) {
            $this->sendText($session->phone, "❌ Maximum pay is ₹50,000.");
            return;
        }

        $this->setTemp($session, 'pay_amount', $pay);
        $this->nextStep($session, JobPostingStep::ENTER_INSTRUCTIONS->value);
        $this->askInstructions($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 11: Special Instructions
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user for special instructions.
     */
    protected function askInstructions(ConversationSession $session): void
    {
        $message = JobPostingStep::ENTER_INSTRUCTIONS->instruction();

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ],
            '📌 Instructions'
        );
    }

    /**
     * Handle instructions input.
     */
    protected function handleInstructions(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        if ($selection === 'skip') {
            $this->setTemp($session, 'special_instructions', null);
            $this->nextStep($session, JobPostingStep::CONFIRM_POST->value);
            $this->showConfirmation($session);
            return;
        }

        if ($message->isText()) {
            $instructions = trim($message->text ?? '');

            if (strlen($instructions) > 300) {
                $this->sendText($session->phone, "❌ Instructions must be less than 300 characters.");
                return;
            }

            $this->setTemp($session, 'special_instructions', $instructions);
            $this->nextStep($session, JobPostingStep::CONFIRM_POST->value);
            $this->showConfirmation($session);
            return;
        }

        $this->askInstructions($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 12: Confirmation
    |--------------------------------------------------------------------------
    */

    /**
     * Show confirmation message.
     */
    protected function showConfirmation(ConversationSession $session): void
    {
        $jobData = $this->getJobDataFromTemp($session);
        $message = JobMessages::jobPostConfirmation($jobData);

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'confirm', 'title' => '✅ Post Job'],
                ['id' => 'edit', 'title' => '✏️ Edit'],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
            ],
            '✅ Confirm Job'
        );
    }

    /**
     * Handle confirmation.
     */
    protected function handleConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        if ($selection === 'confirm') {
            $this->postJob($session);
            return;
        }

        if ($selection === 'edit') {
            $this->start($session);
            return;
        }

        if ($selection === 'cancel') {
            $this->goToMainMenu($session);
            return;
        }

        $this->showConfirmation($session);
    }

    /**
     * Post the job.
     */
    protected function postJob(ConversationSession $session): void
    {
        try {
            $user = $this->sessionManager->getUser($session);
            $jobData = $this->getJobDataFromTemp($session);
            $jobData['poster_user_id'] = $user->id;
            
            // Remove display-only fields not in database
            unset($jobData['estimated_duration']);
            unset($jobData['job_time_display']);

            // Create job directly using the model
            $job = JobPost::create($jobData);

            $this->sendText($session->phone, JobMessages::jobPosted($job));

            $this->nextStep($session, JobPostingStep::COMPLETE->value);
            
            // Show success buttons
            $this->whatsApp->sendButtons(
                $session->phone,
                "What would you like to do next?",
                [
                    ['id' => 'view_job_' . $job->id, 'title' => '📋 View Job'],
                    ['id' => 'post_another', 'title' => '➕ Post Another'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ],
                '🎉 Success'
            );

        } catch (\Exception $e) {
            Log::error('Failed to post job', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            $this->sendText($session->phone, "❌ Failed to post job. Please try again.");
            $this->showConfirmation($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get job data from temp storage.
     */
    protected function getJobDataFromTemp(ConversationSession $session): array
    {
        // Get raw duration string for display
        $durationStr = $this->getTemp($session, 'estimated_duration');
        // Convert duration string to hours for database
        $durationHours = $this->parseDurationToHours($durationStr);
        
        return [
            'job_category_id' => $this->getTemp($session, 'job_category_id'),
            'custom_category_text' => $this->getTemp($session, 'custom_category_text'),
            'title' => $this->getTemp($session, 'title'),
            'description' => $this->getTemp($session, 'description'),
            'location_name' => $this->getTemp($session, 'location_name'),
            'latitude' => $this->getTemp($session, 'latitude'),
            'longitude' => $this->getTemp($session, 'longitude'),
            'job_date' => $this->getTemp($session, 'job_date'),
            'job_time' => $this->getTemp($session, 'job_time'),
            'job_time_display' => $this->getTemp($session, 'job_time_display'), // For confirmation display
            'duration_hours' => $durationHours,
            'estimated_duration' => $durationStr, // Keep for preview display
            'pay_amount' => $this->getTemp($session, 'pay_amount'),
            'special_instructions' => $this->getTemp($session, 'special_instructions'),
            'status' => 'open',
            'posted_at' => now(),
        ];
    }
    
    /**
     * Parse duration string to decimal hours.
     */
    protected function parseDurationToHours(?string $duration): ?float
    {
        if (!$duration) {
            return null;
        }
        
        return match ($duration) {
            '30 minutes', '30min' => 0.5,
            '1 hour', '1hr' => 1.0,
            '2 hours', '2hr' => 2.0,
            '3 hours', '3hr' => 3.0,
            '4 hours', '4hr' => 4.0,
            'Half day', 'half_day' => 4.0,
            'Full day', 'full_day' => 8.0,
            default => 1.0,
        };
    }

    /**
     * Go to main menu.
     */
    protected function goToMainMenu(ConversationSession $session): void
    {
        $this->flowRouter->goToMainMenu($session);
    }

    /**
     * Handle job-related button clicks (view applications, etc.)
     */
    protected function handleJobButtons(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }

        // Handle "View All Applications" button
        if (preg_match('/^view_all_apps_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            // Switch to poster menu and show applications
            $this->setTemp($session, 'view_applications_job_id', $jobId);
            $this->flowRouter->startFlow($session, FlowType::JOB_POSTER_MENU);
            return true;
        }

        // Handle "View Job" button
        if (preg_match('/^view_job_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->setTemp($session, 'selected_job_id', $jobId);
            $this->flowRouter->startFlow($session, FlowType::JOB_POSTER_MENU);
            return true;
        }

        // Handle "Post Another" button
        if ($selectionId === 'post_another') {
            $this->start($session);
            return true;
        }

        return false;
    }
}