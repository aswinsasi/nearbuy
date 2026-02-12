<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\JobPostingStep;
use App\Models\ConversationSession;
use App\Models\JobCategory;
use App\Models\JobPost;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Flow\FlowRouter;
use App\Services\Jobs\JobPostingService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Job Posting Flow Handler.
 *
 * Conversational Manglish flow for task givers:
 * "Need someone to stand in queue at RTO? Post it in 1 minute!"
 *
 * Flow: Category → Location → Coordinates → Date → Time → Duration → Pay → Instructions → Review → Done
 *
 * @srs-ref NP-006 to NP-014: Job Posting
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobPostFlowHandler extends AbstractFlowHandler
{
    /**
     * Job categories master data (NP-006: Tier 1 + Tier 2).
     */
    protected const JOB_CATEGORIES = [
        // Tier 1: Zero Skills Required
        'queue' => ['emoji' => '⏱️', 'en' => 'Queue Standing', 'ml' => 'ക്യൂ നിൽക്കൽ', 'pay' => [100, 200]],
        'delivery' => ['emoji' => '📦', 'en' => 'Parcel Delivery', 'ml' => 'പാഴ്സൽ എടുക്കൽ', 'pay' => [50, 150]],
        'shopping' => ['emoji' => '🛒', 'en' => 'Grocery Shopping', 'ml' => 'സാധനം വാങ്ങൽ', 'pay' => [80, 150]],
        'bill' => ['emoji' => '💳', 'en' => 'Bill Payment', 'ml' => 'ബിൽ അടയ്ക്കൽ', 'pay' => [50, 100]],
        'moving' => ['emoji' => '🏋️', 'en' => 'Moving Help', 'ml' => 'സാധനം എടുക്കാൻ', 'pay' => [200, 500]],
        'event' => ['emoji' => '🎉', 'en' => 'Event Helper', 'ml' => 'ചടങ്ങിൽ സഹായം', 'pay' => [300, 500]],
        'pet' => ['emoji' => '🐕', 'en' => 'Pet Walking', 'ml' => 'നായയെ നടത്തൽ', 'pay' => [100, 200]],
        'garden' => ['emoji' => '🌿', 'en' => 'Garden Cleaning', 'ml' => 'തോട്ടം വൃത്തിയാക്കൽ', 'pay' => [200, 400]],
        // Tier 2: Basic Skills
        'food' => ['emoji' => '🍕', 'en' => 'Food Delivery', 'ml' => 'ഭക്ഷണം എത്തിക്കൽ', 'pay' => [50, 100]],
        'document' => ['emoji' => '📄', 'en' => 'Document Work', 'ml' => 'ഡോക്യുമെന്റ് പണി', 'pay' => [50, 100]],
        'typing' => ['emoji' => '⌨️', 'en' => 'Computer Typing', 'ml' => 'ടൈപ്പിംഗ്', 'pay' => [100, 300]],
        'translation' => ['emoji' => '🗣️', 'en' => 'Translation Help', 'ml' => 'തർജ്ജമ', 'pay' => [200, 500]],
        'photo' => ['emoji' => '📸', 'en' => 'Basic Photography', 'ml' => 'ഫോട്ടോ എടുക്കൽ', 'pay' => [200, 500]],
    ];

    /**
     * Duration options (NP-011).
     */
    protected const DURATIONS = [
        '1-2hr' => ['label' => '1-2 hours', 'hours' => 1.5],
        '2-3hr' => ['label' => '2-3 hours', 'hours' => 2.5],
        '3-4hr' => ['label' => '3-4 hours', 'hours' => 3.5],
        '4+hr' => ['label' => '4+ hours', 'hours' => 5.0],
        'halfday' => ['label' => 'Half day', 'hours' => 4.0],
        'fullday' => ['label' => 'Full day', 'hours' => 8.0],
    ];

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

    public function getExpectedInputType(string $step): string
    {
        return JobPostingStep::tryFrom($step)?->expectedInput() ?? 'text';
    }

    /*
    |--------------------------------------------------------------------------
    | Flow Entry & Navigation
    |--------------------------------------------------------------------------
    */

    /**
     * Re-prompt the current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = JobPostingStep::tryFrom($session->current_step);

        match ($step) {
            JobPostingStep::ASK_CATEGORY => $this->askCategory($session),
            JobPostingStep::ASK_CUSTOM_CATEGORY => $this->askCustomCategory($session),
            JobPostingStep::ASK_LOCATION => $this->askLocation($session),
            JobPostingStep::ASK_COORDINATES => $this->askCoordinates($session),
            JobPostingStep::ASK_DATE => $this->askDate($session),
            JobPostingStep::ASK_CUSTOM_DATE => $this->askCustomDate($session),
            JobPostingStep::ASK_TIME => $this->askTime($session),
            JobPostingStep::ASK_DURATION => $this->askDuration($session),
            JobPostingStep::ASK_PAY => $this->askPay($session),
            JobPostingStep::ASK_INSTRUCTIONS => $this->askInstructions($session),
            JobPostingStep::REVIEW => $this->showReview($session),
            default => $this->start($session),
        };
    }

    /**
     * Start the job posting flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sendButtons(
                $session->phone,
                "❌ *Register first!*\n" .
                "ആദ്യം രജിസ്റ്റർ ചെയ്യുക\n\n" .
                "You need to register before posting jobs.",
                [
                    ['id' => 'register', 'title' => '📝 Register'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        $this->clearTempData($session);
        $this->nextStep($session, JobPostingStep::ASK_CATEGORY->value);

        Log::info('Job posting started', [
            'phone' => $this->maskPhone($session->phone),
            'user_id' => $user->id,
        ]);

        $this->askCategory($session);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle common navigation (menu, cancel, back)
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = JobPostingStep::tryFrom($session->current_step);

        if (!$step) {
            $this->start($session);
            return;
        }

        match ($step) {
            JobPostingStep::ASK_CATEGORY => $this->handleCategory($message, $session),
            JobPostingStep::ASK_CUSTOM_CATEGORY => $this->handleCustomCategory($message, $session),
            JobPostingStep::ASK_LOCATION => $this->handleLocation($message, $session),
            JobPostingStep::ASK_COORDINATES => $this->handleCoordinates($message, $session),
            JobPostingStep::ASK_DATE => $this->handleDate($message, $session),
            JobPostingStep::ASK_CUSTOM_DATE => $this->handleCustomDate($message, $session),
            JobPostingStep::ASK_TIME => $this->handleTime($message, $session),
            JobPostingStep::ASK_DURATION => $this->handleDuration($message, $session),
            JobPostingStep::ASK_PAY => $this->handlePay($message, $session),
            JobPostingStep::ASK_INSTRUCTIONS => $this->handleInstructions($message, $session),
            JobPostingStep::REVIEW => $this->handleReview($message, $session),
            JobPostingStep::DONE => $this->goToMenu($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Category Selection (NP-006)
    |--------------------------------------------------------------------------
    */

    protected function askCategory(ConversationSession $session): void
    {
        // Build list from database categories first, fallback to const
        $rows = [];
        
        try {
            $categories = JobCategory::where('is_active', true)
                ->orderBy('sort_order')
                ->limit(9)
                ->get();
            
            foreach ($categories as $cat) {
                $rows[] = [
                    'id' => 'cat_' . $cat->id,
                    'title' => ($cat->icon ?? '📋') . ' ' . substr($cat->name_en ?? $cat->name, 0, 20),
                    'description' => substr($cat->name_ml ?? '', 0, 70),
                ];
            }
        } catch (\Exception $e) {
            // Fallback to const if DB fails
            foreach (self::JOB_CATEGORIES as $id => $cat) {
                $rows[] = [
                    'id' => 'cat_' . $id,
                    'title' => $cat['emoji'] . ' ' . $cat['en'],
                    'description' => $cat['ml'],
                ];
            }
            $rows = array_slice($rows, 0, 9);
        }

        // Add "Other" option
        $rows[] = [
            'id' => 'cat_other',
            'title' => '🔧 Other / മറ്റുള്ളവ',
            'description' => 'Custom job type',
        ];

        $this->sendList(
            $session->phone,
            "👷 *Entha pani?*\n" .
            "എന്ത് പണിക്കാണ് ആളെ വേണ്ടത്?\n\n" .
            "Select the type of job:",
            'Select Job Type',
            [['title' => 'Job Types', 'rows' => array_slice($rows, 0, 10)]],
            '📋 Post Job'
        );
    }

    protected function handleCategory(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        if (!$id || !str_starts_with($id, 'cat_')) {
            $this->askCategory($session);
            return;
        }

        $catId = str_replace('cat_', '', $id);

        // "Other" selected
        if ($catId === 'other') {
            $this->setTempData($session, 'category_id', null);
            $this->nextStep($session, JobPostingStep::ASK_CUSTOM_CATEGORY->value);
            $this->askCustomCategory($session);
            return;
        }

        // Try database category first
        if (is_numeric($catId)) {
            $category = JobCategory::find($catId);
            if ($category) {
                $this->setTempData($session, 'category_id', $category->id);
                $this->setTempData($session, 'category_name', $category->name_en ?? $category->name);
                $this->setTempData($session, 'category_icon', $category->icon ?? '📋');
                $this->setTempData($session, 'pay_min', $category->typical_pay_min ?? 100);
                $this->setTempData($session, 'pay_max', $category->typical_pay_max ?? 500);
                
                $this->nextStep($session, JobPostingStep::ASK_LOCATION->value);
                $this->askLocation($session);
                return;
            }
        }

        // Fallback to const categories
        if (isset(self::JOB_CATEGORIES[$catId])) {
            $cat = self::JOB_CATEGORIES[$catId];
            $this->setTempData($session, 'category_slug', $catId);
            $this->setTempData($session, 'category_name', $cat['en']);
            $this->setTempData($session, 'category_icon', $cat['emoji']);
            $this->setTempData($session, 'pay_min', $cat['pay'][0]);
            $this->setTempData($session, 'pay_max', $cat['pay'][1]);
            
            $this->nextStep($session, JobPostingStep::ASK_LOCATION->value);
            $this->askLocation($session);
            return;
        }

        // Invalid selection
        $this->askCategory($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1b: Custom Category
    |--------------------------------------------------------------------------
    */

    protected function askCustomCategory(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "✏️ *Custom job type*\n" .
            "മറ്റ് ജോലി തരം\n\n" .
            "Type cheyyuka (eg: Coconut climber, Electrician, Plumber):",
            [
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );
    }

    protected function handleCustomCategory(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->askCustomCategory($session);
            return;
        }

        $customType = trim($message->text ?? '');

        if (mb_strlen($customType) < 3 || mb_strlen($customType) > 100) {
            $this->sendText($session->phone, "❌ 3-100 characters needed. Try again:");
            return;
        }

        $this->setTempData($session, 'category_id', null);
        $this->setTempData($session, 'custom_category', $customType);
        $this->setTempData($session, 'category_name', $customType);
        $this->setTempData($session, 'category_icon', '🔧');
        $this->setTempData($session, 'pay_min', 100);
        $this->setTempData($session, 'pay_max', 1000);

        $this->sendText($session->phone, "✅ Job type: *{$customType}*");

        $this->nextStep($session, JobPostingStep::ASK_LOCATION->value);
        $this->askLocation($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Location Name (NP-007)
    |--------------------------------------------------------------------------
    */

    protected function askLocation(ConversationSession $session): void
    {
        $catName = $this->getTempData($session, 'category_name', 'Job');
        $catIcon = $this->getTempData($session, 'category_icon', '📋');

        $this->sendButtons(
            $session->phone,
            "{$catIcon} *{$catName}*\n\n" .
            "📍 *Location evide?*\n" .
            "പണിക്കാരൻ എവിടെ വരണം?\n\n" .
            "Type cheyyuka (eg: RTO Kakkanad, Collectorate Ernakulam):",
            [
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );
    }

    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->askLocation($session);
            return;
        }

        $location = trim($message->text ?? '');

        if (mb_strlen($location) < 3 || mb_strlen($location) > 200) {
            $this->sendText($session->phone, "❌ Location valid alla. Try again:");
            return;
        }

        $this->setTempData($session, 'location_name', $location);

        $this->nextStep($session, JobPostingStep::ASK_COORDINATES->value);
        $this->askCoordinates($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Coordinates (NP-008) - Optional
    |--------------------------------------------------------------------------
    */

    protected function askCoordinates(ConversationSession $session): void
    {
        $location = $this->getTempData($session, 'location_name', '');

        $this->sendButtons(
            $session->phone,
            "📍 *{$location}*\n\n" .
            "🗺️ *Exact location share cheyyumo?*\n" .
            "Workers-ine kaanan easy aakum\n\n" .
            "📎 button → Location → Send\n" .
            "അല്ലെങ്കിൽ Skip cheyyuka 👇",
            [
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );
    }

    protected function handleCoordinates(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        // Skip coordinates
        if ($id === 'skip') {
            $this->setTempData($session, 'latitude', null);
            $this->setTempData($session, 'longitude', null);
            $this->nextStep($session, JobPostingStep::ASK_DATE->value);
            $this->askDate($session);
            return;
        }

        // Handle location share
        if ($message->isLocation()) {
            $coords = $this->getLocation($message);
            if ($coords && isset($coords['latitude'], $coords['longitude'])) {
                $this->setTempData($session, 'latitude', $coords['latitude']);
                $this->setTempData($session, 'longitude', $coords['longitude']);
                
                $this->sendText($session->phone, "✅ Location saved!");
                
                $this->nextStep($session, JobPostingStep::ASK_DATE->value);
                $this->askDate($session);
                return;
            }
        }

        $this->askCoordinates($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Date Selection (NP-009)
    |--------------------------------------------------------------------------
    */

    protected function askDate(ConversationSession $session): void
    {
        $today = Carbon::today()->format('d M');
        $tomorrow = Carbon::tomorrow()->format('d M');

        $this->sendButtons(
            $session->phone,
            "📅 *Eppozha vende?*\n" .
            "ഏത് ദിവസം വേണം?\n\n" .
            "Select date:",
            [
                ['id' => 'date_today', 'title' => "📅 Today ({$today})"],
                ['id' => 'date_tomorrow', 'title' => "📅 Tomorrow ({$tomorrow})"],
                ['id' => 'date_other', 'title' => '📆 Other Date'],
            ]
        );
    }

    protected function handleDate(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        $date = match ($id) {
            'date_today' => Carbon::today(),
            'date_tomorrow' => Carbon::tomorrow(),
            'date_other' => null,
            default => null,
        };

        if ($id === 'date_other') {
            $this->nextStep($session, JobPostingStep::ASK_CUSTOM_DATE->value);
            $this->askCustomDate($session);
            return;
        }

        if ($date) {
            $this->setTempData($session, 'job_date', $date->format('Y-m-d'));
            $this->setTempData($session, 'job_date_display', $date->format('d M Y'));

            $this->nextStep($session, JobPostingStep::ASK_TIME->value);
            $this->askTime($session);
            return;
        }

        $this->askDate($session);
    }

    protected function askCustomDate(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "📆 *Date type cheyyuka*\n" .
            "തീയതി നൽകുക\n\n" .
            "Format: DD/MM/YYYY or DD/MM\n" .
            "Eg: 15/02/2026 or 15/02",
            [
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );
    }

    protected function handleCustomDate(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->askCustomDate($session);
            return;
        }

        $dateText = trim($message->text ?? '');
        $date = $this->parseDate($dateText);

        if (!$date) {
            $this->sendText($session->phone, "❌ Invalid date. Try DD/MM/YYYY (eg: 15/02/2026):");
            return;
        }

        if ($date->isPast()) {
            $this->sendText($session->phone, "❌ Date must be future. Try again:");
            return;
        }

        $this->setTempData($session, 'job_date', $date->format('Y-m-d'));
        $this->setTempData($session, 'job_date_display', $date->format('d M Y'));

        $this->nextStep($session, JobPostingStep::ASK_TIME->value);
        $this->askTime($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 5: Time (NP-010)
    |--------------------------------------------------------------------------
    */

    protected function askTime(ConversationSession $session): void
    {
        $dateDisplay = $this->getTempData($session, 'job_date_display', 'Selected date');

        $this->sendButtons(
            $session->phone,
            "📅 *{$dateDisplay}*\n\n" .
            "⏰ *Time ethra manikku?*\n" .
            "എത്ര മണിക്ക് എത്തണം?\n\n" .
            "Type cheyyuka (eg: 7 AM, 9:30 AM, afternoon 2 PM):",
            [
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );
    }

    protected function handleTime(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->askTime($session);
            return;
        }

        $timeText = trim($message->text ?? '');
        $parsed = $this->parseTime($timeText);

        if (!$parsed) {
            $this->sendText(
                $session->phone,
                "❌ Time valid alla. Try like:\n" .
                "• 7 AM\n• 9:30 AM\n• 2 PM\n• 14:30"
            );
            return;
        }

        $this->setTempData($session, 'job_time', $parsed['mysql']);
        $this->setTempData($session, 'job_time_display', $parsed['display']);

        $this->nextStep($session, JobPostingStep::ASK_DURATION->value);
        $this->askDuration($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 6: Duration (NP-011)
    |--------------------------------------------------------------------------
    */

    protected function askDuration(ConversationSession $session): void
    {
        $rows = [
            ['id' => 'dur_1-2hr', 'title' => '⏱️ 1-2 hours', 'description' => 'Quick task'],
            ['id' => 'dur_2-3hr', 'title' => '⏱️ 2-3 hours', 'description' => 'Medium task'],
            ['id' => 'dur_3-4hr', 'title' => '⏱️ 3-4 hours', 'description' => 'Longer task'],
            ['id' => 'dur_4+hr', 'title' => '⏱️ 4+ hours', 'description' => 'Extended task'],
            ['id' => 'dur_halfday', 'title' => '⏱️ Half day', 'description' => '4-5 hours'],
            ['id' => 'dur_fullday', 'title' => '⏱️ Full day', 'description' => '8+ hours'],
        ];

        $this->sendList(
            $session->phone,
            "⏱️ *Ethra samayam edukkum?*\n" .
            "ഏകദേശം എത്ര സമയം എടുക്കും?\n\n" .
            "Select duration:",
            'Select Duration',
            [['title' => 'Duration', 'rows' => $rows]]
        );
    }

    protected function handleDuration(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        if (!$id || !str_starts_with($id, 'dur_')) {
            $this->askDuration($session);
            return;
        }

        $durId = str_replace('dur_', '', $id);

        if (!isset(self::DURATIONS[$durId])) {
            $this->askDuration($session);
            return;
        }

        $dur = self::DURATIONS[$durId];
        $this->setTempData($session, 'duration', $durId);
        $this->setTempData($session, 'duration_hours', $dur['hours']);
        $this->setTempData($session, 'duration_display', $dur['label']);

        $this->nextStep($session, JobPostingStep::ASK_PAY->value);
        $this->askPay($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 7: Pay Amount (NP-012)
    |--------------------------------------------------------------------------
    */

    protected function askPay(ConversationSession $session): void
    {
        $catName = $this->getTempData($session, 'category_name', 'Job');
        $durDisplay = $this->getTempData($session, 'duration_display', '');
        $payMin = $this->getTempData($session, 'pay_min', 100);
        $payMax = $this->getTempData($session, 'pay_max', 500);

        // Adjust pay based on duration
        $durHours = $this->getTempData($session, 'duration_hours', 1);
        $multiplier = max(1, $durHours / 2);
        $suggestedMin = (int) round($payMin * $multiplier, -1);
        $suggestedMax = (int) round($payMax * $multiplier, -1);
        $suggested = (int) round(($suggestedMin + $suggestedMax) / 2, -1);

        $this->setTempData($session, 'suggested_pay', $suggested);

        $this->sendButtons(
            $session->phone,
            "💰 *Ethra kodukkum?*\n" .
            "എത്ര രൂപ കൊടുക്കും?\n\n" .
            "📋 *{$catName}* | ⏱️ {$durDisplay}\n" .
            "💡 Suggested: ₹{$suggestedMin} - ₹{$suggestedMax}\n\n" .
            "Amount type cheyyuka (in ₹):",
            [
                ['id' => 'pay_' . $suggested, 'title' => "₹{$suggested}"],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );
    }

    protected function handlePay(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        // Quick select suggested amount
        if ($id && preg_match('/^pay_(\d+)$/', $id, $matches)) {
            $amount = (int) $matches[1];
            $this->setTempData($session, 'pay_amount', $amount);
            $this->nextStep($session, JobPostingStep::ASK_INSTRUCTIONS->value);
            $this->askInstructions($session);
            return;
        }

        // Parse text input
        if ($message->isText()) {
            $text = trim($message->text ?? '');
            $amount = (int) preg_replace('/[^0-9]/', '', $text);

            if ($amount < 50) {
                $this->sendText($session->phone, "❌ Minimum ₹50 vende. Try again:");
                return;
            }

            if ($amount > 50000) {
                $this->sendText($session->phone, "❌ Maximum ₹50,000. Try again:");
                return;
            }

            $this->setTempData($session, 'pay_amount', $amount);
            $this->nextStep($session, JobPostingStep::ASK_INSTRUCTIONS->value);
            $this->askInstructions($session);
            return;
        }

        $this->askPay($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 8: Special Instructions (NP-013) - Optional
    |--------------------------------------------------------------------------
    */

    protected function askInstructions(ConversationSession $session): void
    {
        $payAmount = $this->getTempData($session, 'pay_amount', 0);

        $this->sendButtons(
            $session->phone,
            "💰 *₹{$payAmount}*\n\n" .
            "📝 *Special instructions?*\n" .
            "പ്രത്യേക നിർദ്ദേശങ്ങൾ ഉണ്ടോ?\n\n" .
            "Type cheyyuka OR skip:",
            [
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );
    }

    protected function handleInstructions(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        if ($id === 'skip') {
            $this->setTempData($session, 'instructions', null);
            $this->nextStep($session, JobPostingStep::REVIEW->value);
            $this->showReview($session);
            return;
        }

        if ($message->isText()) {
            $instructions = trim($message->text ?? '');

            if (mb_strlen($instructions) > 500) {
                $this->sendText($session->phone, "❌ Maximum 500 characters. Shorten it:");
                return;
            }

            $this->setTempData($session, 'instructions', $instructions);
            $this->nextStep($session, JobPostingStep::REVIEW->value);
            $this->showReview($session);
            return;
        }

        $this->askInstructions($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 9: Review & Confirm
    |--------------------------------------------------------------------------
    */

    protected function showReview(ConversationSession $session): void
    {
        $catIcon = $this->getTempData($session, 'category_icon', '📋');
        $catName = $this->getTempData($session, 'category_name', 'Job');
        $location = $this->getTempData($session, 'location_name', '');
        $dateDisplay = $this->getTempData($session, 'job_date_display', '');
        $timeDisplay = $this->getTempData($session, 'job_time_display', '');
        $durDisplay = $this->getTempData($session, 'duration_display', '');
        $payAmount = $this->getTempData($session, 'pay_amount', 0);
        $instructions = $this->getTempData($session, 'instructions', '');
        $hasCoords = $this->getTempData($session, 'latitude') ? '✅' : '❌';

        $instLine = $instructions ? "\n📝 {$instructions}" : '';

        $this->sendButtons(
            $session->phone,
            "👷 *Job Review*\n" .
            "━━━━━━━━━━━━━━━━\n\n" .
            "{$catIcon} *{$catName}*\n" .
            "📍 {$location} ({$hasCoords} GPS)\n" .
            "📅 {$dateDisplay} ⏰ {$timeDisplay}\n" .
            "⏱️ {$durDisplay}\n" .
            "💰 *₹{$payAmount}*" .
            $instLine . "\n\n" .
            "━━━━━━━━━━━━━━━━\n" .
            "Ready to post? ✅",
            [
                ['id' => 'confirm_post', 'title' => '✅ Post Job'],
                ['id' => 'edit_job', 'title' => '✏️ Edit'],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
            ]
        );
    }

    protected function handleReview(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        if ($id === 'confirm_post') {
            $this->postJob($session);
            return;
        }

        if ($id === 'edit_job') {
            // Go back to start
            $this->nextStep($session, JobPostingStep::ASK_CATEGORY->value);
            $this->askCategory($session);
            return;
        }

        if ($id === 'cancel') {
            $this->clearTempData($session);
            $this->sendText($session->phone, "❌ Cancelled.");
            $this->goToMenu($session);
            return;
        }

        $this->showReview($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Post Job & Notify Workers (NP-014)
    |--------------------------------------------------------------------------
    */

    protected function postJob(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sendText($session->phone, "❌ User not found. Please register.");
            $this->goToMenu($session);
            return;
        }

        try {
            // Build job data
            $jobData = [
                'poster_user_id' => $user->id,
                'job_category_id' => $this->getTempData($session, 'category_id'),
                'custom_category_text' => $this->getTempData($session, 'custom_category'),
                'title' => $this->getTempData($session, 'category_name', 'Job'),
                'location_name' => $this->getTempData($session, 'location_name'),
                'latitude' => $this->getTempData($session, 'latitude'),
                'longitude' => $this->getTempData($session, 'longitude'),
                'job_date' => $this->getTempData($session, 'job_date'),
                'job_time' => $this->getTempData($session, 'job_time'),
                'duration_hours' => $this->getTempData($session, 'duration_hours'),
                'pay_amount' => $this->getTempData($session, 'pay_amount'),
                'special_instructions' => $this->getTempData($session, 'instructions'),
                'status' => 'open',
            ];

            // Create job
            $job = JobPost::create($jobData);

            // Notify workers (NP-014)
            $workersNotified = 0;
            try {
                $workersNotified = $this->postingService->notifyMatchingWorkers($job);
            } catch (\Exception $e) {
                Log::warning('Failed to notify workers', ['error' => $e->getMessage()]);
            }

            $this->clearTempData($session);
            $this->nextStep($session, JobPostingStep::DONE->value);

            // Success message
            $this->sendButtons(
                $session->phone,
                "🎉 *Job Posted!*\n" .
                "ജോലി പോസ്റ്റ് ചെയ്തു!\n\n" .
                "🆔 *{$job->job_number}*\n\n" .
                "👷 *{$workersNotified}* workers nearby notified! 🔔\n" .
                "അടുത്തുള്ള പണിക്കാർക്ക് അറിയിപ്പ് അയച്ചു\n\n" .
                "Applicants varunna neram ariyikkaam! 📲",
                [
                    ['id' => 'view_job_' . $job->id, 'title' => '📋 View Job'],
                    ['id' => 'post_another', 'title' => '➕ Post Another'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );

            Log::info('Job posted', [
                'job_id' => $job->id,
                'job_number' => $job->job_number,
                'user_id' => $user->id,
                'workers_notified' => $workersNotified,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to post job', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            $this->sendButtons(
                $session->phone,
                "❌ *Failed to post*\n" .
                "Please try again.\n\n" .
                "Error: " . $e->getMessage(),
                [
                    ['id' => 'confirm_post', 'title' => '🔄 Try Again'],
                    ['id' => 'cancel', 'title' => '❌ Cancel'],
                ]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Parse date from various formats.
     */
    protected function parseDate(string $input): ?Carbon
    {
        $input = trim($input);

        // Try DD/MM/YYYY
        try {
            $date = Carbon::createFromFormat('d/m/Y', $input);
            if ($date && $date->isValid()) {
                return $date->startOfDay();
            }
        } catch (\Exception $e) {}

        // Try DD/MM (assume current year)
        try {
            $date = Carbon::createFromFormat('d/m', $input);
            if ($date && $date->isValid()) {
                $date->year(Carbon::now()->year);
                // If date is past, assume next year
                if ($date->isPast()) {
                    $date->addYear();
                }
                return $date->startOfDay();
            }
        } catch (\Exception $e) {}

        // Try DD-MM-YYYY
        try {
            $date = Carbon::createFromFormat('d-m-Y', $input);
            if ($date && $date->isValid()) {
                return $date->startOfDay();
            }
        } catch (\Exception $e) {}

        // Try natural language
        try {
            $date = Carbon::parse($input);
            if ($date && $date->isValid()) {
                return $date->startOfDay();
            }
        } catch (\Exception $e) {}

        return null;
    }

    /**
     * Parse time from various formats.
     *
     * @return array|null ['mysql' => 'HH:MM:SS', 'display' => 'H:MM AM/PM']
     */
    protected function parseTime(string $input): ?array
    {
        $input = trim(strtoupper($input));

        // Handle common keywords
        $keywords = [
            'MORNING' => ['mysql' => '09:00:00', 'display' => '9:00 AM'],
            'AFTERNOON' => ['mysql' => '14:00:00', 'display' => '2:00 PM'],
            'EVENING' => ['mysql' => '17:00:00', 'display' => '5:00 PM'],
            'NIGHT' => ['mysql' => '20:00:00', 'display' => '8:00 PM'],
        ];

        if (isset($keywords[$input])) {
            return $keywords[$input];
        }

        // Try 12-hour format: 9 AM, 9:00 AM, 9:30AM
        if (preg_match('/^(\d{1,2}):?(\d{2})?\s*(AM|PM)$/i', $input, $matches)) {
            $hour = (int) $matches[1];
            $minute = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : 0;
            $period = strtoupper($matches[3]);

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

            if ($hour < 0 || $hour > 23 || $minute < 0 || $minute > 59) {
                return null;
            }

            $period = $hour >= 12 ? 'PM' : 'AM';
            $hour12 = $hour % 12;
            if ($hour12 === 0) $hour12 = 12;

            return [
                'mysql' => sprintf('%02d:%02d:00', $hour, $minute),
                'display' => sprintf('%d:%02d %s', $hour12, $minute, $period),
            ];
        }

        // Try just hour: "7", "14"
        if (preg_match('/^(\d{1,2})$/', $input, $matches)) {
            $hour = (int) $matches[1];
            if ($hour >= 0 && $hour <= 23) {
                $period = $hour >= 12 ? 'PM' : 'AM';
                $hour12 = $hour % 12;
                if ($hour12 === 0) $hour12 = 12;

                return [
                    'mysql' => sprintf('%02d:00:00', $hour),
                    'display' => sprintf('%d:00 %s', $hour12, $period),
                ];
            }
        }

        return null;
    }
}