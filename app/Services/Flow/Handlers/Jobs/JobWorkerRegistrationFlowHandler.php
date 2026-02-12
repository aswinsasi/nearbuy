<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\JobWorkerRegistrationStep;
use App\Enums\WorkerAvailability;
use App\Models\ConversationSession;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Jobs\JobWorkerService;
use App\Services\Media\MediaService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Job Worker Registration Flow Handler.
 *
 * Conversational Manglish flow:
 * 1. Location → 2. Photo → 3. Job Types → 4. Vehicle → 5. Availability → Done
 *
 * "Got 2 free hours? Earn money!" — anyone can sign up.
 *
 * @srs-ref NP-001 to NP-005: Worker Registration
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobWorkerRegistrationFlowHandler extends AbstractFlowHandler
{
    /**
     * Job types master data (NP-002: Tier 1 + Tier 2).
     */
    protected const JOB_TYPES = [
        // Tier 1: Zero Skills Required
        'queue' => ['emoji' => '⏱️', 'en' => 'Queue Standing', 'ml' => 'ക്യൂ നിൽക്കൽ'],
        'delivery' => ['emoji' => '📦', 'en' => 'Parcel Delivery', 'ml' => 'പാഴ്സൽ എടുക്കൽ'],
        'shopping' => ['emoji' => '🛒', 'en' => 'Grocery Shopping', 'ml' => 'സാധനം വാങ്ങൽ'],
        'bill' => ['emoji' => '💳', 'en' => 'Bill Payment', 'ml' => 'ബിൽ അടയ്ക്കൽ'],
        'moving' => ['emoji' => '🏋️', 'en' => 'Moving Help', 'ml' => 'സാധനം എടുക്കാൻ'],
        'event' => ['emoji' => '🎉', 'en' => 'Event Helper', 'ml' => 'ചടങ്ങിൽ സഹായം'],
        'pet' => ['emoji' => '🐕', 'en' => 'Pet Walking', 'ml' => 'നായയെ നടത്തൽ'],
        'garden' => ['emoji' => '🌿', 'en' => 'Garden Cleaning', 'ml' => 'തോട്ടം വൃത്തിയാക്കൽ'],
        // Tier 2: Basic Skills
        'food' => ['emoji' => '🍕', 'en' => 'Food Delivery', 'ml' => 'ഭക്ഷണം എത്തിക്കൽ'],
        'document' => ['emoji' => '📄', 'en' => 'Document Work', 'ml' => 'ഡോക്യുമെന്റ് പണി'],
        'typing' => ['emoji' => '⌨️', 'en' => 'Computer Typing', 'ml' => 'ടൈപ്പിംഗ്'],
        'photo' => ['emoji' => '📸', 'en' => 'Basic Photography', 'ml' => 'ഫോട്ടോ എടുക്കൽ'],
    ];

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected JobWorkerService $workerService,
        protected MediaService $mediaService
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::JOB_WORKER_REGISTER;
    }

    protected function getSteps(): array
    {
        return JobWorkerRegistrationStep::values();
    }

    public function getExpectedInputType(string $step): string
    {
        return JobWorkerRegistrationStep::tryFrom($step)?->expectedInput() ?? 'text';
    }

    /*
    |--------------------------------------------------------------------------
    | Flow Entry Point
    |--------------------------------------------------------------------------
    */

    /**
     * Re-prompt the current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = JobWorkerRegistrationStep::tryFrom($session->current_step);

        match ($step) {
            JobWorkerRegistrationStep::ASK_LOCATION => $this->askLocation($session),
            JobWorkerRegistrationStep::ASK_PHOTO => $this->askPhoto($session),
            JobWorkerRegistrationStep::ASK_JOB_TYPES => $this->askJobTypes($session),
            JobWorkerRegistrationStep::ASK_VEHICLE => $this->askVehicle($session),
            JobWorkerRegistrationStep::ASK_AVAILABILITY => $this->askAvailability($session),
            JobWorkerRegistrationStep::CONFIRM => $this->showConfirmation($session),
            default => $this->start($session),
        };
    }

    /**
     * Start the worker registration flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Already a worker?
        if ($user?->jobWorker) {
            $worker = $user->jobWorker;
            $this->sendButtons(
                $session->phone,
                "👷 *Already Registered!*\n" .
                "*ഇതിനകം രജിസ്റ്റർ ചെയ്തിട്ടുണ്ട്*\n\n" .
                "👤 {$worker->name}\n" .
                "{$worker->rating_display} | {$worker->jobs_display}\n\n" .
                "Ready to find jobs! 💪",
                [
                    ['id' => 'browse_jobs', 'title' => '🔍 See Jobs'],
                    ['id' => 'worker_profile', 'title' => '👤 My Profile'],
                ]
            );
            return;
        }

        // Clear temp and start
        $this->clearTempData($session);

        // Pre-fill name if user exists
        if ($user?->name) {
            $this->setTempData($session, 'name', $user->name);
        }

        $this->nextStep($session, JobWorkerRegistrationStep::ASK_LOCATION->value);

        Log::info('Worker registration started', [
            'phone' => $this->maskPhone($session->phone),
            'has_user' => $user !== null,
        ]);

        $this->askLocation($session);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Navigation buttons
        if ($this->handleNavigation($message, $session)) {
            return;
        }

        $step = JobWorkerRegistrationStep::tryFrom($session->current_step);

        Log::debug('Worker registration step', [
            'step' => $step?->value,
            'type' => $message->type,
        ]);

        match ($step) {
            JobWorkerRegistrationStep::ASK_LOCATION => $this->handleLocation($message, $session),
            JobWorkerRegistrationStep::ASK_PHOTO => $this->handlePhoto($message, $session),
            JobWorkerRegistrationStep::ASK_JOB_TYPES => $this->handleJobTypes($message, $session),
            JobWorkerRegistrationStep::ASK_VEHICLE => $this->handleVehicle($message, $session),
            JobWorkerRegistrationStep::ASK_AVAILABILITY => $this->handleAvailability($message, $session),
            JobWorkerRegistrationStep::CONFIRM => $this->handleConfirm($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Handle navigation buttons.
     */
    protected function handleNavigation(IncomingMessage $message, ConversationSession $session): bool
    {
        $id = $this->getSelectionId($message);

        if ($id === 'cancel_registration') {
            $this->clearTempData($session);
            $this->sendText($session->phone, "❌ Registration cancelled.\nRegister anytime! 👷");
            $this->goToMenu($session);
            return true;
        }

        if ($id === 'browse_jobs' || $id === 'see_jobs') {
            // Route to job browse flow
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Location (NP-001)
    |--------------------------------------------------------------------------
    */

    protected function askLocation(ConversationSession $session): void
    {
        $this->requestLocation(
            $session->phone,
            "👷 *Worker aayi register cheyyaam!*\n" .
            "Free time-il paisa earn cheyyaam 💪\n\n" .
            "📍 *Ninte location share cheyyuka:*\n" .
            "താഴെ button click ചെയ്ത് location ayakkuka"
        );
    }

    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $location = $this->getLocation($message);

        if ($location && isset($location['latitude'], $location['longitude'])) {
            if ($this->workerService->isValidCoordinates($location['latitude'], $location['longitude'])) {
                $this->setTempData($session, 'latitude', $location['latitude']);
                $this->setTempData($session, 'longitude', $location['longitude']);
                
                $locationData = $message->getLocationData();
                $this->setTempData($session, 'address', $locationData['address'] ?? $locationData['name'] ?? null);

                $this->nextStep($session, JobWorkerRegistrationStep::ASK_PHOTO->value);
                $this->askPhoto($session);
                return;
            }
        }

        // Invalid - re-prompt
        $this->sendText(
            $session->phone,
            "📍 Location share cheyyuka!\n" .
            "📎 button → Location → Send"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Photo (NP-001 - Optional)
    |--------------------------------------------------------------------------
    */

    protected function askPhoto(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "📸 *Profile selfie ayakkuka*\n" .
            "(Job posters kaanum - trust build cheyyum)\n\n" .
            "Camera/Gallery-il ninnu photo ayakkuka\n" .
            "അല്ലെങ്കിൽ Skip cheyyaam 👇",
            [
                ['id' => 'skip_photo', 'title' => '⏭️ Skip Photo'],
            ]
        );
    }

    protected function handlePhoto(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        // Skip photo
        if ($id === 'skip_photo') {
            $this->setTempData($session, 'photo_url', null);
            $this->nextStep($session, JobWorkerRegistrationStep::ASK_JOB_TYPES->value);
            $this->askJobTypes($session);
            return;
        }

        // Handle image
        if ($message->isImage()) {
            try {
                $mediaId = $this->getMediaId($message);
                if ($mediaId) {
                    $photoUrl = $this->mediaService->downloadAndStore(
                        $mediaId,
                        'worker-photos',
                        $session->phone
                    );

                    $this->setTempData($session, 'photo_url', $photoUrl);
                    $this->sendText($session->phone, "✅ Photo saved!");
                    
                    $this->nextStep($session, JobWorkerRegistrationStep::ASK_JOB_TYPES->value);
                    $this->askJobTypes($session);
                    return;
                }
            } catch (\Exception $e) {
                Log::error('Photo upload failed', ['error' => $e->getMessage()]);
            }
        }

        // Re-prompt
        $this->askPhoto($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Job Types (NP-002 - Multi-select)
    |--------------------------------------------------------------------------
    */

    protected function askJobTypes(ConversationSession $session): void
    {
        $selected = $this->getTempData($session, 'job_types', []);
        $count = count($selected);

        // Build list items
        $rows = [];
        foreach (self::JOB_TYPES as $id => $type) {
            $check = in_array($id, $selected) ? ' ✅' : '';
            $rows[] = [
                'id' => 'jt_' . $id,
                'title' => $type['emoji'] . ' ' . $type['en'] . $check,
                'description' => $type['ml'],
            ];
        }

        // Add "All Jobs" option
        $allCheck = in_array('all', $selected) ? ' ✅' : '';
        $rows[] = [
            'id' => 'jt_all',
            'title' => '✅ ALL Jobs' . $allCheck,
            'description' => 'Ella pani-yum cheyyaam',
        ];

        // Add "Done" option if selections made
        if ($count > 0 || in_array('all', $selected)) {
            $rows[] = [
                'id' => 'jt_done',
                'title' => '✔️ Done - Continue',
                'description' => "{$count} selected",
            ];
        }

        $this->sendList(
            $session->phone,
            "💼 *Entha pani cheyyaan interest?*\n" .
            "(Select cheyyuka, multiple select cheyyaam)\n\n" .
            "Selected: *{$count}*",
            'Select Jobs',
            [['title' => 'Job Types', 'rows' => array_slice($rows, 0, 10)]]
        );
    }

    protected function handleJobTypes(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        if (!$id || !str_starts_with($id, 'jt_')) {
            $this->askJobTypes($session);
            return;
        }

        $typeId = str_replace('jt_', '', $id);

        // Done selecting
        if ($typeId === 'done') {
            $selected = $this->getTempData($session, 'job_types', []);
            if (empty($selected) && !in_array('all', $selected)) {
                $this->sendText($session->phone, "⚠️ At least one job type select cheyyuka!");
                $this->askJobTypes($session);
                return;
            }

            $this->nextStep($session, JobWorkerRegistrationStep::ASK_VEHICLE->value);
            $this->askVehicle($session);
            return;
        }

        // Toggle selection
        $selected = $this->getTempData($session, 'job_types', []);

        if ($typeId === 'all') {
            // Toggle "all"
            if (in_array('all', $selected)) {
                $selected = [];
            } else {
                $selected = ['all'];
            }
        } else {
            // Remove "all" if specific type selected
            $selected = array_filter($selected, fn($s) => $s !== 'all');

            if (in_array($typeId, $selected)) {
                $selected = array_values(array_diff($selected, [$typeId]));
            } else {
                $selected[] = $typeId;
            }
        }

        $this->setTempData($session, 'job_types', $selected);

        // Show updated list
        $count = in_array('all', $selected) ? 'ALL' : count($selected);
        $typeName = self::JOB_TYPES[$typeId]['en'] ?? 'All Jobs';
        $action = in_array($typeId, $selected) || in_array('all', $selected) ? '✅ Added' : '❌ Removed';

        $this->sendButtons(
            $session->phone,
            "{$action}: *{$typeName}*\nSelected: *{$count}*\n\nAdd more or continue 👇",
            [
                ['id' => 'jt_done', 'title' => '✔️ Done'],
                ['id' => 'show_job_list', 'title' => '➕ Add More'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Vehicle (NP-003)
    |--------------------------------------------------------------------------
    */

    protected function askVehicle(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "🚗 *Vehicle undo?*\n" .
            "വാഹനം ഉണ്ടോ?\n\n" .
            "(Delivery jobs-nu vehicle venam)",
            [
                ['id' => 'v_none', 'title' => '🚶 No/Walking'],
                ['id' => 'v_two', 'title' => '🏍️ Two Wheeler'],
                ['id' => 'v_four', 'title' => '🚗 Four Wheeler'],
            ]
        );
    }

    protected function handleVehicle(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        // Handle "Add More" from job types
        if ($id === 'show_job_list') {
            $this->nextStep($session, JobWorkerRegistrationStep::ASK_JOB_TYPES->value);
            $this->askJobTypes($session);
            return;
        }

        $vehicle = match ($id) {
            'v_none' => 'none',
            'v_two' => 'two_wheeler',
            'v_four' => 'four_wheeler',
            default => null,
        };

        if ($vehicle) {
            $this->setTempData($session, 'vehicle_type', $vehicle);
            $this->nextStep($session, JobWorkerRegistrationStep::ASK_AVAILABILITY->value);
            $this->askAvailability($session);
            return;
        }

        $this->askVehicle($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 5: Availability (NP-004)
    |--------------------------------------------------------------------------
    */

    protected function askAvailability(ConversationSession $session): void
    {
        $this->sendList(
            $session->phone,
            "🕐 *Eppozha free?*\n" .
            "എപ്പോഴാണ് ലഭ്യം?\n\n" .
            "(Multiple select cheyyaam)",
            'Select Time',
            [[
                'title' => 'Availability',
                'rows' => [
                    ['id' => 'av_morning', 'title' => '🌅 Morning 6-12', 'description' => 'രാവിലെ'],
                    ['id' => 'av_afternoon', 'title' => '☀️ Afternoon 12-6', 'description' => 'ഉച്ചയ്ക്ക്'],
                    ['id' => 'av_evening', 'title' => '🌙 Evening 6-10', 'description' => 'വൈകിട്ട്'],
                    ['id' => 'av_flexible', 'title' => '🔄 Flexible', 'description' => 'എപ്പോഴും free'],
                ],
            ]]
        );
    }

    protected function handleAvailability(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        $availability = match ($id) {
            'av_morning' => 'morning',
            'av_afternoon' => 'afternoon',
            'av_evening' => 'evening',
            'av_flexible' => 'flexible',
            default => null,
        };

        if ($availability) {
            // For simplicity, store single selection (can extend to multi-select)
            $this->setTempData($session, 'availability', [$availability]);
            $this->nextStep($session, JobWorkerRegistrationStep::CONFIRM->value);
            $this->showConfirmation($session);
            return;
        }

        $this->askAvailability($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 6: Confirmation
    |--------------------------------------------------------------------------
    */

    protected function showConfirmation(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $name = $this->getTempData($session, 'name') ?? $user?->name ?? 'Worker';
        $photo = $this->getTempData($session, 'photo_url') ? '✅' : '❌';
        $vehicle = $this->getVehicleDisplay($this->getTempData($session, 'vehicle_type'));
        $jobTypes = $this->getJobTypesDisplay($this->getTempData($session, 'job_types', []));
        $availability = $this->getAvailabilityDisplay($this->getTempData($session, 'availability', []));

        $this->sendButtons(
            $session->phone,
            "📋 *Confirm Registration*\n\n" .
            "👤 Name: *{$name}*\n" .
            "📸 Photo: {$photo}\n" .
            "🚗 Vehicle: {$vehicle}\n" .
            "💼 Jobs: {$jobTypes}\n" .
            "🕐 Time: {$availability}\n\n" .
            "Ready to register? ✅",
            [
                ['id' => 'confirm_reg', 'title' => '✅ Confirm'],
                ['id' => 'cancel_registration', 'title' => '❌ Cancel'],
            ]
        );
    }

    protected function handleConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $id = $this->getSelectionId($message);

        if ($id === 'confirm_reg') {
            $this->registerWorker($session);
            return;
        }

        if ($id === 'cancel_registration') {
            $this->clearTempData($session);
            $this->sendText($session->phone, "❌ Cancelled. Register anytime! 👷");
            $this->goToMenu($session);
            return;
        }

        $this->showConfirmation($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Registration
    |--------------------------------------------------------------------------
    */

    protected function registerWorker(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        $data = [
            'name' => $this->getTempData($session, 'name') ?? $user?->name ?? 'Worker',
            'photo_url' => $this->getTempData($session, 'photo_url'),
            'latitude' => $this->getTempData($session, 'latitude'),
            'longitude' => $this->getTempData($session, 'longitude'),
            'address' => $this->getTempData($session, 'address'),
            'vehicle_type' => $this->getTempData($session, 'vehicle_type') ?? 'none',
            'job_types' => $this->getTempData($session, 'job_types') ?? [],
            'availability' => $this->getTempData($session, 'availability') ?? ['flexible'],
        ];

        try {
            if ($user?->registered_at) {
                // Existing user → add worker profile
                $worker = $this->workerService->registerExistingUserAsWorker($user, $data);
            } else {
                // New user → create user + worker
                $data['phone'] = $session->phone;
                $data['name'] = $data['name'] ?: 'Worker';
                $newUser = $this->workerService->createUserAndWorker($data);
                $worker = $newUser->jobWorker;
                $this->workerService->linkSessionToUser($session, $newUser);
            }

            $this->clearTempData($session);
            $this->nextStep($session, JobWorkerRegistrationStep::DONE->value);

            // Success message (NP-005: 0 rating, 0 jobs, available)
            $this->sendButtons(
                $session->phone,
                "✅ *Ready to earn!* 👷💪\n\n" .
                "👤 *{$worker->name}*\n" .
                "⭐ Rating: New | Jobs: 0\n" .
                "🟢 Status: Available\n\n" .
                "Job varunna neram ariyikkaam! 🔔\n" .
                "ജോലി വരുമ്പോൾ ariyikkaam!",
                [
                    ['id' => 'see_jobs', 'title' => '🔍 See Available Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ]
            );

            Log::info('Worker registered', [
                'worker_id' => $worker->id,
                'phone' => $this->maskPhone($session->phone),
            ]);

        } catch (\Exception $e) {
            Log::error('Worker registration failed', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            $this->sendButtons(
                $session->phone,
                "❌ *Registration failed*\n{$e->getMessage()}\n\nTry again?",
                [
                    ['id' => 'confirm_reg', 'title' => '🔄 Try Again'],
                    ['id' => 'cancel_registration', 'title' => '❌ Cancel'],
                ]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Display Helpers
    |--------------------------------------------------------------------------
    */

    protected function getVehicleDisplay(?string $type): string
    {
        return match ($type) {
            'two_wheeler' => '🏍️ Two Wheeler',
            'four_wheeler' => '🚗 Four Wheeler',
            default => '🚶 No/Walking',
        };
    }

    protected function getJobTypesDisplay(array $types): string
    {
        if (empty($types) || in_array('all', $types)) {
            return 'All Jobs ✅';
        }
        return count($types) . ' types';
    }

    protected function getAvailabilityDisplay(array $slots): string
    {
        if (empty($slots) || in_array('flexible', $slots)) {
            return '🔄 Flexible';
        }
        return collect($slots)
            ->map(fn($s) => WorkerAvailability::tryFrom($s)?->emoji() ?? $s)
            ->join(' ');
    }
}