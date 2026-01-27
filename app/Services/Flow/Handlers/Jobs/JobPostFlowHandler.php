<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\JobPostingStep;
use App\Models\ConversationSession;
use App\Models\JobCategory;
use App\Models\JobPost;
use App\Models\User;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Flow\FlowRouter;
use App\Services\Jobs\JobPostingService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\JobMessages;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Handler for the job posting flow.
 *
 * Flow Steps (from JobPostingStep enum):
 * 1. SELECT_CATEGORY - List message with job categories
 * 2. ENTER_TITLE - Free text job title
 * 3. ENTER_DESCRIPTION - Optional description
 * 4. ENTER_LOCATION - Location text description
 * 5. REQUEST_LOCATION_COORDS - WhatsApp location for proximity
 * 6. SELECT_DATE - Today/Tomorrow/Pick date
 * 7. ENTER_TIME - Time input
 * 8. SELECT_DURATION - Duration selection
 * 9. SUGGEST_PAY - Suggested pay with custom option
 * 10. ENTER_PAY - Custom pay amount
 * 11. ENTER_INSTRUCTIONS - Optional special instructions
 * 12. CONFIRM_POST - Review and confirm
 * 13. COMPLETE - Done
 *
 * @srs-ref Section 3.3 - Job Posting Flow
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobPostFlowHandler extends AbstractFlowHandler
{
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

        $response = JobMessages::postJobWelcome();
        $this->sendJobMessage($session->phone, $response);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle common navigation (main menu, cancel, retry)
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        // Handle cross-flow navigation buttons
        $selectionId = $this->getSelectionId($message);
        if ($this->handleCrossFlowNavigation($selectionId, $session)) {
            return;
        }

        $step = $session->current_step;

        Log::debug('JobPostFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
            'selection_id' => $selectionId,
        ]);

        // Route to appropriate handler based on step
        match ($step) {
            JobPostingStep::SELECT_CATEGORY->value => $this->handleSelectCategory($message, $session),
            JobPostingStep::ENTER_TITLE->value => $this->handleEnterTitle($message, $session),
            JobPostingStep::ENTER_DESCRIPTION->value => $this->handleEnterDescription($message, $session),
            JobPostingStep::ENTER_LOCATION->value => $this->handleEnterLocation($message, $session),
            JobPostingStep::REQUEST_LOCATION_COORDS->value => $this->handleLocationCoords($message, $session),
            JobPostingStep::SELECT_DATE->value => $this->handleSelectDate($message, $session),
            JobPostingStep::ENTER_TIME->value => $this->handleEnterTime($message, $session),
            JobPostingStep::SELECT_DURATION->value => $this->handleSelectDuration($message, $session),
            JobPostingStep::SUGGEST_PAY->value => $this->handleSuggestPay($message, $session),
            JobPostingStep::ENTER_PAY->value => $this->handleEnterPay($message, $session),
            JobPostingStep::ENTER_INSTRUCTIONS->value => $this->handleEnterInstructions($message, $session),
            JobPostingStep::CONFIRM_POST->value => $this->handleConfirmPost($message, $session),
            JobPostingStep::COMPLETE->value => $this->handleComplete($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Re-prompt the current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = $session->current_step;

        match ($step) {
            JobPostingStep::SELECT_CATEGORY->value => $this->promptSelectCategory($session),
            JobPostingStep::ENTER_TITLE->value => $this->promptEnterTitle($session),
            JobPostingStep::ENTER_DESCRIPTION->value => $this->promptEnterDescription($session),
            JobPostingStep::ENTER_LOCATION->value => $this->promptEnterLocation($session),
            JobPostingStep::REQUEST_LOCATION_COORDS->value => $this->promptLocationCoords($session),
            JobPostingStep::SELECT_DATE->value => $this->promptSelectDate($session),
            JobPostingStep::ENTER_TIME->value => $this->promptEnterTime($session),
            JobPostingStep::SELECT_DURATION->value => $this->promptSelectDuration($session),
            JobPostingStep::SUGGEST_PAY->value => $this->promptSuggestPay($session),
            JobPostingStep::ENTER_PAY->value => $this->promptEnterPay($session),
            JobPostingStep::ENTER_INSTRUCTIONS->value => $this->promptEnterInstructions($session),
            JobPostingStep::CONFIRM_POST->value => $this->promptConfirmPost($session),
            default => $this->start($session),
        };
    }

    /**
     * Handle cross-flow navigation buttons.
     */
    protected function handleCrossFlowNavigation(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }

        // Main menu
        if ($selectionId === 'main_menu') {
            $this->clearTemp($session);
            $this->goToMainMenu($session);
            return true;
        }

        // My posted jobs
        if ($selectionId === 'my_posted_jobs') {
            $this->clearTemp($session);
            $this->goToFlow($session, FlowType::JOB_POSTER_MENU);
            return true;
        }

        // Post another job
        if ($selectionId === 'post_another_job') {
            $this->clearTemp($session);
            $this->start($session);
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Select Category
    |--------------------------------------------------------------------------
    */

    protected function handleSelectCategory(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle "Start posting" button from welcome message
        if ($selectionId === 'start_job_posting') {
            $this->promptSelectCategory($session);
            return;
        }

        // Handle category selection (format: post_cat_1, post_cat_2, etc.)
        if ($selectionId && str_starts_with($selectionId, 'post_cat_')) {
            $categoryId = (int) str_replace('post_cat_', '', $selectionId);
            $category = JobCategory::find($categoryId);

            if ($category && $category->is_active) {
                $this->setTemp($session, 'category_id', $category->id);
                $this->setTemp($session, 'category_name', $category->display_name);
                $this->setTemp($session, 'category_slug', $category->slug);
                
                $this->nextStep($session, JobPostingStep::ENTER_TITLE->value);
                $this->promptEnterTitle($session);
                return;
            }
        }

        // Show category selection
        $this->promptSelectCategory($session);
    }

    protected function promptSelectCategory(ConversationSession $session): void
    {
        $response = JobMessages::selectJobCategory();
        $this->sendJobMessage($session->phone, $response);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Enter Title
    |--------------------------------------------------------------------------
    */

    protected function handleEnterTitle(IncomingMessage $message, ConversationSession $session): void
    {
        $text = $this->getTextContent($message);

        if ($text && $this->postingService->isValidTitle($text)) {
            $this->setTemp($session, 'title', trim($text));
            
            $this->nextStep($session, JobPostingStep::ENTER_DESCRIPTION->value);
            $this->promptEnterDescription($session);
            return;
        }

        // Invalid title
        $this->sendButtons(
            $session->phone,
            "❌ *Invalid title*\n\nPlease enter a title between 5-100 characters.\n\nദയവായി 5-100 അക്ഷരങ്ങൾക്കിടയിൽ ഒരു ടൈറ്റിൽ നൽകുക.",
            [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    protected function promptEnterTitle(ConversationSession $session): void
    {
        $categoryId = $this->getTemp($session, 'category_id');
        $category = JobCategory::find($categoryId);

        if ($category) {
            $response = JobMessages::askJobTitle($category);
            $this->sendJobMessage($session->phone, $response);
        } else {
            // Category lost - restart
            $this->start($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Enter Description (Optional)
    |--------------------------------------------------------------------------
    */

    protected function handleEnterDescription(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        // Skip description
        if ($selectionId === 'skip_description' || $this->isSkip($message)) {
            $this->setTemp($session, 'description', null);
            $this->nextStep($session, JobPostingStep::ENTER_LOCATION->value);
            $this->promptEnterLocation($session);
            return;
        }

        // Process description text
        if ($text) {
            $description = trim($text);
            if (mb_strlen($description) > 500) {
                $description = mb_substr($description, 0, 500);
            }
            $this->setTemp($session, 'description', $description);
            
            $this->nextStep($session, JobPostingStep::ENTER_LOCATION->value);
            $this->promptEnterLocation($session);
            return;
        }

        // Re-prompt
        $this->promptEnterDescription($session);
    }

    protected function promptEnterDescription(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "*Step 3/12* 📝\n\n" .
            "📝 *വിവരണം (Optional)*\n\n" .
            "Describe what needs to be done.\n" .
            "ചെയ്യേണ്ട കാര്യം വിവരിക്കുക.\n\n" .
            "_ഉദാ: Token എടുത്ത് RC-ക്ക് apply ചെയ്യുക, copy അടക്കം_\n\n" .
            "_ഇല്ലെങ്കിൽ Skip ചെയ്യാം_",
            [
                ['id' => 'skip_description', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Enter Location (Text)
    |--------------------------------------------------------------------------
    */

    protected function handleEnterLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $text = $this->getTextContent($message);

        if ($text && mb_strlen(trim($text)) >= 3) {
            $this->setTemp($session, 'location_name', trim($text));
            
            $this->nextStep($session, JobPostingStep::REQUEST_LOCATION_COORDS->value);
            $this->promptLocationCoords($session);
            return;
        }

        // Invalid location
        $this->sendButtons(
            $session->phone,
            "❌ *Invalid location*\n\nPlease enter a valid location (at least 3 characters).\n\nദയവായി ഒരു ശരിയായ സ്ഥലം നൽകുക.",
            [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    protected function promptEnterLocation(ConversationSession $session): void
    {
        $response = JobMessages::askJobLocation();
        $this->sendJobMessage($session->phone, $response);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 5: Request Location Coordinates (Optional)
    |--------------------------------------------------------------------------
    */

    protected function handleLocationCoords(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Skip coordinates
        if ($selectionId === 'skip_job_coords' || $this->isSkip($message)) {
            $this->setTemp($session, 'latitude', null);
            $this->setTemp($session, 'longitude', null);
            $this->nextStep($session, JobPostingStep::SELECT_DATE->value);
            $this->promptSelectDate($session);
            return;
        }

        // Handle location message
        $coords = $this->getLocation($message);
        if ($coords && $this->postingService->isValidCoordinates($coords['latitude'], $coords['longitude'])) {
            $this->setTemp($session, 'latitude', $coords['latitude']);
            $this->setTemp($session, 'longitude', $coords['longitude']);
            
            // Update location name with address if available
            if (!empty($coords['address'])) {
                $this->setTemp($session, 'location_address', $coords['address']);
            }

            $this->nextStep($session, JobPostingStep::SELECT_DATE->value);
            $this->promptSelectDate($session);
            return;
        }

        // Re-prompt
        $this->promptLocationCoords($session);
    }

    protected function promptLocationCoords(ConversationSession $session): void
    {
        $response = JobMessages::requestJobLocationCoords();
        $this->sendJobMessage($session->phone, $response);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 6: Select Date
    |--------------------------------------------------------------------------
    */

    protected function handleSelectDate(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        $jobDate = null;

        // Handle button selections
        if ($selectionId === 'job_date_today') {
            $jobDate = Carbon::today();
        } elseif ($selectionId === 'job_date_tomorrow') {
            $jobDate = Carbon::tomorrow();
        } elseif ($selectionId === 'job_date_pick') {
            // Ask for custom date input
            $this->sendButtons(
                $session->phone,
                "📅 *തീയതി നൽകുക*\n\nPlease enter the date (DD/MM/YYYY format)\n\nഉദാ: " . now()->addDays(3)->format('d/m/Y'),
                [
                    ['id' => 'job_date_today', 'title' => '📅 Today'],
                    ['id' => 'job_date_tomorrow', 'title' => '📅 Tomorrow'],
                ]
            );
            return;
        }

        // Handle text date input
        if (!$jobDate && $text) {
            $jobDate = $this->parseDate($text);
        }

        if ($jobDate) {
            // Validate date is not in the past
            if ($jobDate->lt(Carbon::today())) {
                $this->sendButtons(
                    $session->phone,
                    "❌ *Past date not allowed*\n\nPlease select today or a future date.\n\nഭൂതകാലം തിരഞ്ഞെടുക്കാനാവില്ല.",
                    [
                        ['id' => 'job_date_today', 'title' => '📅 Today'],
                        ['id' => 'job_date_tomorrow', 'title' => '📅 Tomorrow'],
                    ]
                );
                return;
            }

            // Validate date is within reasonable range (30 days)
            if ($jobDate->gt(Carbon::today()->addDays(30))) {
                $this->sendButtons(
                    $session->phone,
                    "❌ *Date too far*\n\nPlease select a date within 30 days.\n\n30 ദിവസത്തിനുള്ളിൽ തീയതി തിരഞ്ഞെടുക്കുക.",
                    [
                        ['id' => 'job_date_today', 'title' => '📅 Today'],
                        ['id' => 'job_date_tomorrow', 'title' => '📅 Tomorrow'],
                    ]
                );
                return;
            }

            $this->setTemp($session, 'job_date', $jobDate->format('Y-m-d'));
            $this->setTemp($session, 'job_date_display', $jobDate->format('D, M j'));
            
            $this->nextStep($session, JobPostingStep::ENTER_TIME->value);
            $this->promptEnterTime($session);
            return;
        }

        // Re-prompt
        $this->promptSelectDate($session);
    }

    protected function promptSelectDate(ConversationSession $session): void
    {
        $response = JobMessages::askJobDate();
        $this->sendJobMessage($session->phone, $response);
    }

    /**
     * Parse date from various formats.
     */
    protected function parseDate(string $text): ?Carbon
    {
        $text = trim(strtolower($text));

        // Handle "today", "tomorrow"
        if (in_array($text, ['today', 'ഇന്ന്', 'innu'])) {
            return Carbon::today();
        }
        if (in_array($text, ['tomorrow', 'നാളെ', 'naale'])) {
            return Carbon::tomorrow();
        }

        // Try various date formats
        $formats = ['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m', 'd-m'];
        foreach ($formats as $format) {
            try {
                $date = Carbon::createFromFormat($format, $text);
                if ($date && $date->isValid()) {
                    // If year not specified, assume current year
                    if (!str_contains($format, 'Y')) {
                        $date->year(now()->year);
                        // If date is in the past, assume next year
                        if ($date->lt(Carbon::today())) {
                            $date->addYear();
                        }
                    }
                    return $date;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Step 7: Enter Time
    |--------------------------------------------------------------------------
    */

    protected function handleEnterTime(IncomingMessage $message, ConversationSession $session): void
    {
        $text = $this->getTextContent($message);

        if ($text) {
            $time = $this->parseTime($text);
            if ($time) {
                $this->setTemp($session, 'job_time', $time);
                
                $this->nextStep($session, JobPostingStep::SELECT_DURATION->value);
                $this->promptSelectDuration($session);
                return;
            }
        }

        // Invalid time
        $this->sendButtons(
            $session->phone,
            "❌ *Invalid time*\n\nPlease enter a valid time.\n\nഉദാ: 9 AM, 2:30 PM, morning, രാവിലെ 10\n\nദയവായി ശരിയായ സമയം നൽകുക.",
            [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    protected function promptEnterTime(ConversationSession $session): void
    {
        $response = JobMessages::askJobTime();
        $this->sendJobMessage($session->phone, $response);
    }

    /**
     * Parse time from various formats.
     */
    protected function parseTime(string $text): ?string
    {
        $text = trim(strtolower($text));

        // Handle general time words
        $timeWords = [
            'morning' => '9:00 AM',
            'രാവിലെ' => '9:00 AM',
            'afternoon' => '2:00 PM',
            'ഉച്ചയ്ക്ക്' => '12:00 PM',
            'evening' => '5:00 PM',
            'വൈകുന്നേരം' => '5:00 PM',
            'night' => '8:00 PM',
            'രാത്രി' => '8:00 PM',
        ];

        if (isset($timeWords[$text])) {
            return $timeWords[$text];
        }

        // Try to parse time with regex
        // Patterns: "9 AM", "9:30 PM", "09:30", "9", "രാവിലെ 10"
        if (preg_match('/(\d{1,2})[:\s]?(\d{2})?\s*(am|pm|AM|PM)?/i', $text, $matches)) {
            $hour = (int) $matches[1];
            $minute = isset($matches[2]) ? (int) $matches[2] : 0;
            $meridiem = isset($matches[3]) ? strtoupper($matches[3]) : null;

            // Validate hour
            if ($hour < 0 || $hour > 23) {
                return null;
            }

            // If no meridiem and hour < 12, assume it's based on context
            if (!$meridiem) {
                if ($hour >= 1 && $hour <= 6) {
                    // Could be AM or PM, default to PM for work hours
                    $meridiem = 'PM';
                } elseif ($hour >= 7 && $hour <= 11) {
                    $meridiem = 'AM';
                } elseif ($hour == 12) {
                    $meridiem = 'PM';
                } else {
                    // 24-hour format
                    $meridiem = $hour >= 12 ? 'PM' : 'AM';
                    if ($hour > 12) {
                        $hour -= 12;
                    }
                }
            }

            // Adjust hour for 12-hour format
            if ($meridiem === 'PM' && $hour < 12) {
                $hour += 12;
            } elseif ($meridiem === 'AM' && $hour === 12) {
                $hour = 0;
            }

            // Format display time
            $displayHour = $hour % 12;
            if ($displayHour === 0) $displayHour = 12;
            $displayMeridiem = $hour < 12 ? 'AM' : 'PM';

            return sprintf('%d:%02d %s', $displayHour, $minute, $displayMeridiem);
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Step 8: Select Duration
    |--------------------------------------------------------------------------
    */

    protected function handleSelectDuration(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        $durationHours = null;

        // Handle button selections
        $durationMap = [
            'duration_30min' => 0.5,
            'duration_1hr' => 1,
            'duration_2hr' => 2,
            'duration_3hr' => 3,
            'duration_4hr_plus' => 4,
        ];

        if ($selectionId && isset($durationMap[$selectionId])) {
            $durationHours = $durationMap[$selectionId];
        }

        // Try to parse text input
        if (!$durationHours && $text) {
            $durationHours = $this->parseDuration($text);
        }

        if ($durationHours !== null) {
            $this->setTemp($session, 'duration_hours', $durationHours);
            
            $this->nextStep($session, JobPostingStep::SUGGEST_PAY->value);
            $this->promptSuggestPay($session);
            return;
        }

        // Re-prompt
        $this->promptSelectDuration($session);
    }

    protected function promptSelectDuration(ConversationSession $session): void
    {
        $response = JobMessages::askJobDuration();
        $this->sendJobMessage($session->phone, $response);
    }

    /**
     * Parse duration from text.
     */
    protected function parseDuration(string $text): ?float
    {
        $text = trim(strtolower($text));

        // Extract number
        if (preg_match('/(\d+(?:\.\d+)?)\s*(hr|hour|hrs|hours|min|mins|minutes|മണിക്കൂർ)?/i', $text, $matches)) {
            $value = (float) $matches[1];
            $unit = $matches[2] ?? 'hr';

            if (in_array($unit, ['min', 'mins', 'minutes'])) {
                return $value / 60;
            }

            return $value;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Step 9: Suggest Pay
    |--------------------------------------------------------------------------
    */

    protected function handleSuggestPay(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        $categoryId = $this->getTemp($session, 'category_id');
        $category = JobCategory::find($categoryId);
        $durationHours = $this->getTemp($session, 'duration_hours', 1);

        $payRange = $this->postingService->calculateSuggestedPay($category, $durationHours);

        // Handle suggested amount buttons
        if ($selectionId === 'pay_suggested_min') {
            $this->setTemp($session, 'pay_amount', $payRange['min']);
            $this->nextStep($session, JobPostingStep::ENTER_INSTRUCTIONS->value);
            $this->promptEnterInstructions($session);
            return;
        }

        if ($selectionId === 'pay_suggested_max') {
            $this->setTemp($session, 'pay_amount', $payRange['max']);
            $this->nextStep($session, JobPostingStep::ENTER_INSTRUCTIONS->value);
            $this->promptEnterInstructions($session);
            return;
        }

        // Custom amount - go to enter pay step
        if ($selectionId === 'pay_custom') {
            $this->nextStep($session, JobPostingStep::ENTER_PAY->value);
            $this->promptEnterPay($session);
            return;
        }

        // Try to parse text as amount
        if ($text) {
            $amount = $this->parseAmount($text);
            if ($amount) {
                $this->setTemp($session, 'pay_amount', $amount);
                $this->nextStep($session, JobPostingStep::ENTER_INSTRUCTIONS->value);
                $this->promptEnterInstructions($session);
                return;
            }
        }

        // Re-prompt
        $this->promptSuggestPay($session);
    }

    protected function promptSuggestPay(ConversationSession $session): void
    {
        $categoryId = $this->getTemp($session, 'category_id');
        $category = JobCategory::find($categoryId);
        $durationHours = $this->getTemp($session, 'duration_hours', 1);

        if ($category) {
            $response = JobMessages::suggestPay($category, $durationHours);
            $this->sendJobMessage($session->phone, $response);
        } else {
            // Category lost - restart
            $this->start($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step 10: Enter Pay (Custom Amount)
    |--------------------------------------------------------------------------
    */

    protected function handleEnterPay(IncomingMessage $message, ConversationSession $session): void
    {
        $text = $this->getTextContent($message);

        if ($text) {
            $amount = $this->parseAmount($text);
            if ($amount && $amount >= 50 && $amount <= 50000) {
                // Warn if amount seems too low
                $categoryId = $this->getTemp($session, 'category_id');
                $category = JobCategory::find($categoryId);
                $payRange = $this->postingService->calculateSuggestedPay($category, $this->getTemp($session, 'duration_hours', 1));

                if ($amount < $payRange['min'] * 0.5) {
                    // Amount is less than half the suggested minimum
                    $this->sendButtons(
                        $session->phone,
                        "⚠️ *Low amount warning*\n\n" .
                        "₹{$amount} is lower than typical for this job.\n" .
                        "Suggested: ₹{$payRange['min']} - ₹{$payRange['max']}\n\n" .
                        "Workers may not apply. Continue anyway?",
                        [
                            ['id' => 'confirm_low_pay', 'title' => '✅ Continue'],
                            ['id' => 'retry', 'title' => '✏️ Change Amount'],
                        ]
                    );
                    $this->setTemp($session, 'pending_pay_amount', $amount);
                    return;
                }

                $this->setTemp($session, 'pay_amount', $amount);
                $this->nextStep($session, JobPostingStep::ENTER_INSTRUCTIONS->value);
                $this->promptEnterInstructions($session);
                return;
            }
        }

        // Check for low pay confirmation
        $selectionId = $this->getSelectionId($message);
        if ($selectionId === 'confirm_low_pay') {
            $pendingAmount = $this->getTemp($session, 'pending_pay_amount');
            if ($pendingAmount) {
                $this->setTemp($session, 'pay_amount', $pendingAmount);
                $this->setTemp($session, 'pending_pay_amount', null);
                $this->nextStep($session, JobPostingStep::ENTER_INSTRUCTIONS->value);
                $this->promptEnterInstructions($session);
                return;
            }
        }

        // Invalid amount
        $this->sendButtons(
            $session->phone,
            "❌ *Invalid amount*\n\nPlease enter a valid amount between ₹50 and ₹50,000.\n\nദയവായി ₹50 മുതൽ ₹50,000 വരെയുള്ള തുക നൽകുക.",
            [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    protected function promptEnterPay(ConversationSession $session): void
    {
        $categoryId = $this->getTemp($session, 'category_id');
        $category = JobCategory::find($categoryId);
        $payRange = $this->postingService->calculateSuggestedPay($category, $this->getTemp($session, 'duration_hours', 1));

        $this->sendButtons(
            $session->phone,
            "*Step 9/12* 📝\n\n" .
            "💰 *Enter Payment Amount*\n\n" .
            "Suggested: ₹{$payRange['min']} - ₹{$payRange['max']}\n\n" .
            "Please enter your amount (in ₹)\n" .
            "നിങ്ങളുടെ തുക നൽകുക (₹-ൽ)\n\n" .
            "_ഉദാ: 300, ₹500_",
            [
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /**
     * Parse amount from text.
     */
    protected function parseAmount(string $text): ?float
    {
        // Remove currency symbols and text
        $cleaned = preg_replace('/[₹,Rs\.INR\s]/i', '', $text);

        if (is_numeric($cleaned)) {
            return round((float) $cleaned, 2);
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Step 11: Enter Instructions (Optional)
    |--------------------------------------------------------------------------
    */

    protected function handleEnterInstructions(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        // Skip instructions
        if ($selectionId === 'skip_instructions' || $this->isSkip($message)) {
            $this->setTemp($session, 'special_instructions', null);
            $this->nextStep($session, JobPostingStep::CONFIRM_POST->value);
            $this->promptConfirmPost($session);
            return;
        }

        // Process instructions text
        if ($text) {
            $instructions = trim($text);
            if (mb_strlen($instructions) > 500) {
                $instructions = mb_substr($instructions, 0, 500);
            }
            $this->setTemp($session, 'special_instructions', $instructions);
            
            $this->nextStep($session, JobPostingStep::CONFIRM_POST->value);
            $this->promptConfirmPost($session);
            return;
        }

        // Re-prompt
        $this->promptEnterInstructions($session);
    }

    protected function promptEnterInstructions(ConversationSession $session): void
    {
        $response = JobMessages::askSpecialInstructions();
        $this->sendJobMessage($session->phone, $response);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 12: Confirm Post
    |--------------------------------------------------------------------------
    */

    protected function handleConfirmPost(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Confirm posting
        if ($selectionId === 'confirm_job_post') {
            $this->createJobPost($session);
            return;
        }

        // Edit - restart flow
        if ($selectionId === 'edit_job_post') {
            // Go back to category selection
            $this->nextStep($session, JobPostingStep::SELECT_CATEGORY->value);
            $this->promptSelectCategory($session);
            return;
        }

        // Cancel
        if ($selectionId === 'cancel_job_post') {
            $this->clearTemp($session);
            $this->sendTextWithMenu($session->phone, "❌ *Job posting cancelled*\n\nജോലി പോസ്റ്റിംഗ് റദ്ദാക്കി.");
            $this->goToMainMenu($session);
            return;
        }

        // Handle edit navigation buttons
        if ($this->handleEditNavigation($selectionId, $session)) {
            return;
        }

        // Re-prompt
        $this->promptConfirmPost($session);
    }

    protected function promptConfirmPost(ConversationSession $session): void
    {
        $categoryId = $this->getTemp($session, 'category_id');
        $category = JobCategory::find($categoryId);

        if (!$category) {
            $this->start($session);
            return;
        }

        $jobData = [
            'title' => $this->getTemp($session, 'title'),
            'description' => $this->getTemp($session, 'description'),
            'location_name' => $this->getTemp($session, 'location_name'),
            'job_date' => $this->getTemp($session, 'job_date_display'),
            'job_time' => $this->getTemp($session, 'job_time'),
            'duration_hours' => $this->getTemp($session, 'duration_hours'),
            'pay_amount' => $this->getTemp($session, 'pay_amount'),
            'special_instructions' => $this->getTemp($session, 'special_instructions'),
        ];

        $response = JobMessages::confirmJobPost($jobData, $category);
        $this->sendJobMessage($session->phone, $response);
    }

    /**
     * Handle edit navigation buttons.
     */
    protected function handleEditNavigation(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }

        $editSteps = [
            'edit_category' => JobPostingStep::SELECT_CATEGORY->value,
            'edit_title' => JobPostingStep::ENTER_TITLE->value,
            'edit_description' => JobPostingStep::ENTER_DESCRIPTION->value,
            'edit_location' => JobPostingStep::ENTER_LOCATION->value,
            'edit_date' => JobPostingStep::SELECT_DATE->value,
            'edit_time' => JobPostingStep::ENTER_TIME->value,
            'edit_duration' => JobPostingStep::SELECT_DURATION->value,
            'edit_pay' => JobPostingStep::ENTER_PAY->value,
            'edit_instructions' => JobPostingStep::ENTER_INSTRUCTIONS->value,
        ];

        if (isset($editSteps[$selectionId])) {
            $this->setTemp($session, 'editing', true);
            $this->nextStep($session, $editSteps[$selectionId]);
            $this->promptCurrentStep($session);
            return true;
        }

        // Back to confirm
        if ($selectionId === 'back_to_confirm') {
            $this->setTemp($session, 'editing', false);
            $this->nextStep($session, JobPostingStep::CONFIRM_POST->value);
            $this->promptConfirmPost($session);
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Create Job Post
    |--------------------------------------------------------------------------
    */

    protected function createJobPost(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        
        if (!$user) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ *Error*\n\nYou must be registered to post jobs.\n\nജോലി പോസ്റ്റ് ചെയ്യാൻ രജിസ്റ്റർ ചെയ്യണം."
            );
            return;
        }

        try {
            $jobData = [
                'job_category_id' => $this->getTemp($session, 'category_id'),
                'title' => $this->getTemp($session, 'title'),
                'description' => $this->getTemp($session, 'description'),
                'location_name' => $this->getTemp($session, 'location_name'),
                'latitude' => $this->getTemp($session, 'latitude'),
                'longitude' => $this->getTemp($session, 'longitude'),
                'job_date' => $this->getTemp($session, 'job_date'),
                'job_time' => $this->getTemp($session, 'job_time'),
                'duration_hours' => $this->getTemp($session, 'duration_hours'),
                'pay_amount' => $this->getTemp($session, 'pay_amount'),
                'special_instructions' => $this->getTemp($session, 'special_instructions'),
            ];

            // Create the job post
            $job = $this->postingService->createJobPost($user, $jobData);

            // Publish the job (move from draft to open)
            $job->publish();

            // Find and notify matching workers
            $workerCount = $this->postingService->notifyMatchingWorkers($job);

            $this->logInfo('Job posted successfully', [
                'job_id' => $job->id,
                'job_number' => $job->job_number,
                'workers_notified' => $workerCount,
            ]);

            // Clear temp data
            $this->clearTemp($session);

            // Move to complete step
            $this->nextStep($session, JobPostingStep::COMPLETE->value);

            // Send success message
            $response = JobMessages::jobPostedSuccess($job, $workerCount);
            $this->sendJobMessage($session->phone, $response);

        } catch (\Exception $e) {
            $this->logError('Failed to create job post', [
                'error' => $e->getMessage(),
                'user_id' => $user->id,
            ]);

            $this->sendButtons(
                $session->phone,
                "❌ *Error*\n\nFailed to post job. Please try again.\n\nജോലി പോസ്റ്റ് ചെയ്യാൻ കഴിഞ്ഞില്ല. വീണ്ടും ശ്രമിക്കുക.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step 13: Complete
    |--------------------------------------------------------------------------
    */

    protected function handleComplete(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // View job
        if ($selectionId && str_starts_with($selectionId, 'view_job_')) {
            // TODO: Go to job detail view
            $this->goToMainMenu($session);
            return;
        }

        // Post another
        if ($selectionId === 'post_another_job') {
            $this->start($session);
            return;
        }

        // Default - go to main menu
        $this->goToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Send a JobMessages response.
     */
    protected function sendJobMessage(string $phone, array $response): void
    {
        $type = $response['type'] ?? 'text';

        switch ($type) {
            case 'text':
                $this->sendText($phone, $response['text']);
                break;

            case 'buttons':
                $this->sendButtons(
                    $phone,
                    $response['body'] ?? $response['text'] ?? '',
                    $response['buttons'] ?? [],
                    $response['header'] ?? null,
                    $response['footer'] ?? null
                );
                break;

            case 'list':
                $this->sendList(
                    $phone,
                    $response['body'] ?? '',
                    $response['button'] ?? 'Select',
                    $response['sections'] ?? [],
                    $response['header'] ?? null,
                    $response['footer'] ?? null
                );
                break;

            default:
                $this->sendText($phone, $response['text'] ?? 'Message sent.');
        }
    }
}