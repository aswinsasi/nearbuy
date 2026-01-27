<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\JobWorkerRegistrationStep;
use App\Enums\UserType;
use App\Models\ConversationSession;
use App\Models\JobCategory;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Jobs\JobWorkerService;
use App\Services\Media\MediaService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\JobMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for job worker registration flow.
 *
 * IMPORTANT: This handler supports TWO registration paths:
 * 1. Existing registered users (customers/shops) - adds worker profile
 * 2. New unregistered users - creates new user with worker profile
 *
 * Flow Steps (from JobWorkerRegistrationStep enum):
 * 1. ask_name → Collect worker's display name
 * 2. ask_photo → Request profile selfie (optional, builds trust)
 * 3. ask_location → Request location via WhatsApp location picker
 * 4. ask_vehicle → Button selection: None/Two Wheeler/Four Wheeler
 * 5. ask_job_types → Multi-step job category selection
 * 6. ask_availability → Availability slots selection
 * 7. confirm_registration → Show summary, Confirm/Edit/Cancel
 *
 * @srs-ref Section 3.2 - Job Worker Registration
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobWorkerRegistrationFlowHandler extends AbstractFlowHandler
{
    /**
     * Step constants matching JobWorkerRegistrationStep enum values.
     */
    protected const STEP_ASK_NAME = 'ask_name';
    protected const STEP_ASK_PHOTO = 'ask_photo';
    protected const STEP_ASK_LOCATION = 'ask_location';
    protected const STEP_ASK_VEHICLE = 'ask_vehicle';
    protected const STEP_ASK_JOB_TYPES = 'ask_job_types';
    protected const STEP_ASK_AVAILABILITY = 'ask_availability';
    protected const STEP_CONFIRM = 'confirm_registration';
    protected const STEP_COMPLETE = 'complete';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected JobWorkerService $workerService,
        protected MediaService $mediaService
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    /**
     * {@inheritdoc}
     */
    protected function getFlowType(): FlowType
    {
        return FlowType::JOB_WORKER_REGISTER;
    }

    /**
     * {@inheritdoc}
     */
    protected function getSteps(): array
    {
        return [
            self::STEP_ASK_NAME,
            self::STEP_ASK_PHOTO,
            self::STEP_ASK_LOCATION,
            self::STEP_ASK_VEHICLE,
            self::STEP_ASK_JOB_TYPES,
            self::STEP_ASK_AVAILABILITY,
            self::STEP_CONFIRM,
            self::STEP_COMPLETE,
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            self::STEP_ASK_NAME => 'text',
            self::STEP_ASK_PHOTO => 'image',
            self::STEP_ASK_LOCATION => 'location',
            self::STEP_ASK_VEHICLE => 'button',
            self::STEP_ASK_JOB_TYPES => 'list',
            self::STEP_ASK_AVAILABILITY => 'list',
            self::STEP_CONFIRM => 'button',
            default => 'text',
        };
    }

    /**
     * Start the worker registration flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Check if user is already a worker
        if ($user && $user->jobWorker) {
            $worker = $user->jobWorker;
            $this->sendButtonsWithMenu(
                $session->phone,
                "👷 *Already Registered*\n" .
                "*ഇതിനകം രജിസ്റ്റർ ചെയ്തിട്ടുണ്ട്*\n\n" .
                "You are already registered as a worker!\n\n" .
                "👤 Name: *{$worker->name}*\n" .
                "⭐ Rating: {$worker->short_rating}\n" .
                "✅ Jobs: {$worker->jobs_completed}",
                [
                    ['id' => 'browse_jobs', 'title' => '🔍 ജോലികൾ കാണുക'],
                    ['id' => 'worker_profile', 'title' => '👤 പ്രൊഫൈൽ'],
                ]
            );
            return;
        }

        // Clear any previous temp data and start fresh
        $this->clearTemp($session);
        $this->nextStep($session, self::STEP_ASK_NAME);

        Log::info('Worker registration started', [
            'phone' => $this->maskPhone($session->phone),
            'has_user' => $user !== null,
            'is_registered' => $user?->registered_at !== null,
        ]);

        // Send welcome message
        $response = JobMessages::workerWelcome();
        $this->sendJobMessage($session->phone, $response);
    }

    /**
     * Handle incoming message during registration.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle common navigation (main menu, cancel, etc.)
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        // Handle "start registration" button from welcome screen
        if ($this->getSelectionId($message) === 'start_worker_registration') {
            $this->nextStep($session, self::STEP_ASK_NAME);
            $response = JobMessages::askWorkerName();
            $this->sendJobMessage($session->phone, $response);
            return;
        }

        // Handle edit navigation buttons
        if ($this->handleEditNavigation($message, $session)) {
            return;
        }

        $step = $session->current_step;

        Log::debug('JobWorkerRegistrationFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
            'selection_id' => $this->getSelectionId($message),
        ]);

        match ($step) {
            self::STEP_ASK_NAME => $this->handleName($message, $session),
            self::STEP_ASK_PHOTO => $this->handlePhoto($message, $session),
            self::STEP_ASK_LOCATION => $this->handleLocation($message, $session),
            self::STEP_ASK_VEHICLE => $this->handleVehicle($message, $session),
            self::STEP_ASK_JOB_TYPES => $this->handleJobTypes($message, $session),
            self::STEP_ASK_AVAILABILITY => $this->handleAvailability($message, $session),
            self::STEP_CONFIRM => $this->handleConfirmation($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Re-prompt the current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = $session->current_step;

        $response = match ($step) {
            self::STEP_ASK_NAME => JobMessages::askWorkerName(),
            self::STEP_ASK_PHOTO => JobMessages::askWorkerPhoto(),
            self::STEP_ASK_LOCATION => JobMessages::askWorkerLocation(),
            self::STEP_ASK_VEHICLE => JobMessages::askVehicleType(),
            self::STEP_ASK_JOB_TYPES => JobMessages::askJobTypes(),
            self::STEP_ASK_AVAILABILITY => JobMessages::askAvailability(),
            self::STEP_CONFIRM => $this->buildConfirmationMessage($session),
            default => JobMessages::workerWelcome(),
        };

        $this->sendJobMessage($session->phone, $response);
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    /**
     * Handle name input (Step 1/7).
     */
    protected function handleName(IncomingMessage $message, ConversationSession $session): void
    {
        $text = $this->getTextContent($message);

        if ($text && $this->workerService->isValidName($text)) {
            $this->setTemp($session, 'name', trim($text));
            $this->nextStep($session, self::STEP_ASK_PHOTO);

            Log::info('Worker name captured', [
                'phone' => $this->maskPhone($session->phone),
            ]);

            $response = JobMessages::askWorkerPhoto();
            $this->sendJobMessage($session->phone, $response);
            return;
        }

        // Invalid name - re-prompt with error
        $this->sendTextWithMenu(
            $session->phone,
            "⚠️ *Invalid Name*\n\n" .
            "Please enter a valid name (2-100 characters).\n" .
            "ദയവായി ശരിയായ പേര് നൽകുക (2-100 അക്ഷരങ്ങൾ)."
        );
    }

    /**
     * Handle photo upload (Step 2/7 - Optional).
     */
    protected function handlePhoto(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for skip button
        $selectionId = $this->getSelectionId($message);
        if ($selectionId === 'skip_worker_photo' || $this->isSkip($message)) {
            $this->setTemp($session, 'photo_url', null);
            $this->nextStep($session, self::STEP_ASK_LOCATION);

            Log::info('Worker photo skipped', [
                'phone' => $this->maskPhone($session->phone),
            ]);

            $response = JobMessages::askWorkerLocation();
            $this->sendJobMessage($session->phone, $response);
            return;
        }

        // Handle image upload
        if ($message->isImage()) {
            try {
                $mediaId = $this->getMediaId($message);

                if ($mediaId) {
                    // Download and store the image
                    $photoUrl = $this->mediaService->downloadAndStore(
                        $mediaId,
                        'worker-photos',
                        $session->phone
                    );

                    $this->setTemp($session, 'photo_url', $photoUrl);
                    $this->nextStep($session, self::STEP_ASK_LOCATION);

                    Log::info('Worker photo uploaded', [
                        'phone' => $this->maskPhone($session->phone),
                    ]);

                    // Acknowledge and move to next step
                    $this->sendText($session->phone, "✅ ഫോട്ടോ സേവ് ചെയ്തു! Photo saved!");

                    $response = JobMessages::askWorkerLocation();
                    $this->sendJobMessage($session->phone, $response);
                    return;
                }
            } catch (\Exception $e) {
                Log::error('Failed to upload worker photo', [
                    'error' => $e->getMessage(),
                    'phone' => $this->maskPhone($session->phone),
                ]);

                $this->sendTextWithMenu(
                    $session->phone,
                    "⚠️ Photo upload failed. Please try again or skip."
                );
                return;
            }
        }

        // Re-prompt for photo
        $response = JobMessages::askWorkerPhoto();
        $this->sendJobMessage($session->phone, $response);
    }

    /**
     * Handle location input (Step 3/7).
     */
    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $location = $this->getLocation($message);

        if ($location && isset($location['latitude'], $location['longitude'])) {
            // Validate coordinates
            if ($this->workerService->isValidCoordinates($location['latitude'], $location['longitude'])) {
                $this->setTemp($session, 'latitude', $location['latitude']);
                $this->setTemp($session, 'longitude', $location['longitude']);

                // Get address from location data
                $locationData = $message->getLocationData();
                $address = $locationData['address'] ?? $locationData['name'] ?? null;
                $this->setTemp($session, 'address', $address);

                $this->nextStep($session, self::STEP_ASK_VEHICLE);

                Log::info('Worker location captured', [
                    'phone' => $this->maskPhone($session->phone),
                ]);

                $response = JobMessages::askVehicleType();
                $this->sendJobMessage($session->phone, $response);
                return;
            }
        }

        // Invalid or missing location - re-prompt
        $this->sendTextWithMenu(
            $session->phone,
            "📍 *Location Required*\n\n" .
            "Please share your location using the attachment button.\n" .
            "ദയവായി ലൊക്കേഷൻ ബട്ടൺ ഉപയോഗിച്ച് നിങ്ങളുടെ ലൊക്കേഷൻ ഷെയർ ചെയ്യുക.\n\n" .
            "📎 → *Location* ടാപ്പ് ചെയ്യുക"
        );
    }

    /**
     * Handle vehicle type selection (Step 4/7).
     */
    protected function handleVehicle(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        $vehicleType = match ($selectionId) {
            'vehicle_none' => 'none',
            'vehicle_two_wheeler' => 'two_wheeler',
            'vehicle_four_wheeler' => 'four_wheeler',
            default => null,
        };

        if ($vehicleType !== null) {
            $this->setTemp($session, 'vehicle_type', $vehicleType);
            $this->nextStep($session, self::STEP_ASK_JOB_TYPES);

            // Initialize job_types array
            $this->setTemp($session, 'job_types', []);

            Log::info('Worker vehicle type captured', [
                'phone' => $this->maskPhone($session->phone),
                'vehicle_type' => $vehicleType,
            ]);

            $response = JobMessages::askJobTypes();
            $this->sendJobMessage($session->phone, $response);
            return;
        }

        // Invalid selection - re-prompt
        $response = JobMessages::askVehicleType();
        $this->sendJobMessage($session->phone, $response);
    }

    /**
     * Handle job types selection (Step 5/7 - Multi-select).
     */
    protected function handleJobTypes(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Check for "Done" selection
        if ($selectionId === 'jobtype_done') {
            $selectedTypes = $this->getTemp($session, 'job_types', []);

            if (empty($selectedTypes)) {
                $this->sendTextWithMenu(
                    $session->phone,
                    "⚠️ *Select at least one job type*\n\n" .
                    "കുറഞ്ഞത് ഒരു ജോലി തരം തിരഞ്ഞെടുക്കുക."
                );
                return;
            }

            // Move to availability step
            $this->nextStep($session, self::STEP_ASK_AVAILABILITY);

            Log::info('Worker job types captured', [
                'phone' => $this->maskPhone($session->phone),
                'job_types_count' => count($selectedTypes),
            ]);

            $response = JobMessages::askAvailability();
            $this->sendJobMessage($session->phone, $response);
            return;
        }

        // Handle job type selection (jobtype_X format)
        if ($selectionId && str_starts_with($selectionId, 'jobtype_')) {
            $categoryId = (int) str_replace('jobtype_', '', $selectionId);

            if ($categoryId > 0) {
                // Verify category exists
                $category = JobCategory::find($categoryId);

                if ($category) {
                    $currentTypes = $this->getTemp($session, 'job_types', []);

                    // Toggle selection
                    if (in_array($categoryId, $currentTypes)) {
                        // Remove if already selected
                        $currentTypes = array_values(array_diff($currentTypes, [$categoryId]));
                        $action = 'removed';
                        $emoji = '❌';
                    } else {
                        // Add if not selected
                        $currentTypes[] = $categoryId;
                        $action = 'added';
                        $emoji = '✅';
                    }

                    $this->setTemp($session, 'job_types', $currentTypes);

                    // Show confirmation and prompt for more
                    $count = count($currentTypes);
                    $this->sendButtons(
                        $session->phone,
                        "{$emoji} *{$category->name_ml}* {$action}\n\n" .
                        "📋 Selected: *{$count}* job types\n\n" .
                        "Select more or tap Done when finished.",
                        [
                            ['id' => 'jobtype_done', 'title' => '✅ Done'],
                            ['id' => 'show_job_types', 'title' => '📋 Add More'],
                        ]
                    );
                    return;
                }
            }
        }

        // Handle "Add More" / "Show Job Types" button
        if ($selectionId === 'show_job_types') {
            $response = JobMessages::askJobTypes();
            $this->sendJobMessage($session->phone, $response);
            return;
        }

        // Re-prompt for job types
        $response = JobMessages::askJobTypes();
        $this->sendJobMessage($session->phone, $response);
    }

    /**
     * Handle availability selection (Step 6/7).
     */
    protected function handleAvailability(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        $availability = match ($selectionId) {
            'avail_morning' => 'morning',
            'avail_afternoon' => 'afternoon',
            'avail_evening' => 'evening',
            'avail_flexible' => 'flexible',
            default => null,
        };

        if ($availability !== null) {
            // Store as array (could be extended to multi-select later)
            $this->setTemp($session, 'availability', [$availability]);
            $this->nextStep($session, self::STEP_CONFIRM);

            Log::info('Worker availability captured', [
                'phone' => $this->maskPhone($session->phone),
                'availability' => $availability,
            ]);

            // Show confirmation
            $this->showConfirmation($session);
            return;
        }

        // Re-prompt for availability
        $response = JobMessages::askAvailability();
        $this->sendJobMessage($session->phone, $response);
    }

    /**
     * Handle confirmation response (Step 7/7).
     */
    protected function handleConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        switch ($selectionId) {
            case 'confirm_worker_reg':
                $this->registerWorker($session);
                break;

            case 'edit_worker_reg':
                $this->showEditOptions($session);
                break;

            case 'cancel_worker_reg':
                $this->clearTemp($session);
                $this->sendTextWithMenu(
                    $session->phone,
                    "❌ *Registration Cancelled*\n" .
                    "*രജിസ്ട്രേഷൻ റദ്ദാക്കി*\n\n" .
                    "You can register anytime by selecting 'Become a Worker'."
                );
                $this->goToMainMenu($session);
                break;

            default:
                // Re-show confirmation
                $this->showConfirmation($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Navigation Handlers
    |--------------------------------------------------------------------------
    */

    /**
     * Handle edit navigation buttons.
     *
     * @return bool True if handled, false otherwise
     */
    protected function handleEditNavigation(IncomingMessage $message, ConversationSession $session): bool
    {
        $selectionId = $this->getSelectionId($message);

        if (!$selectionId) {
            return false;
        }

        $editStep = match ($selectionId) {
            'edit_name' => self::STEP_ASK_NAME,
            'edit_photo' => self::STEP_ASK_PHOTO,
            'edit_location' => self::STEP_ASK_LOCATION,
            'edit_vehicle' => self::STEP_ASK_VEHICLE,
            'edit_job_types' => self::STEP_ASK_JOB_TYPES,
            'edit_availability' => self::STEP_ASK_AVAILABILITY,
            'back_to_confirm' => self::STEP_CONFIRM,
            default => null,
        };

        if ($editStep !== null) {
            $this->nextStep($session, $editStep);

            if ($editStep === self::STEP_ASK_JOB_TYPES) {
                // Reset job types for re-selection
                $this->setTemp($session, 'job_types', []);
            }

            $this->promptCurrentStep($session);
            return true;
        }

        return false;
    }

    /**
     * Show edit options.
     */
    protected function showEditOptions(ConversationSession $session): void
    {
        $this->sendList(
            $session->phone,
            "✏️ *What would you like to edit?*\n" .
            "*എന്ത് എഡിറ്റ് ചെയ്യണം?*",
            'Edit',
            [
                [
                    'title' => 'Edit Options',
                    'rows' => [
                        ['id' => 'edit_name', 'title' => '👤 Edit Name', 'description' => 'Change your name'],
                        ['id' => 'edit_photo', 'title' => '📸 Edit Photo', 'description' => 'Change profile photo'],
                        ['id' => 'edit_location', 'title' => '📍 Edit Location', 'description' => 'Change location'],
                        ['id' => 'edit_vehicle', 'title' => '🚗 Edit Vehicle', 'description' => 'Change vehicle type'],
                        ['id' => 'edit_job_types', 'title' => '💼 Edit Job Types', 'description' => 'Change job preferences'],
                        ['id' => 'edit_availability', 'title' => '🕐 Edit Availability', 'description' => 'Change availability'],
                        ['id' => 'back_to_confirm', 'title' => '⬅️ Back', 'description' => 'Return to confirmation'],
                    ],
                ],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Registration Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Show registration confirmation.
     */
    protected function showConfirmation(ConversationSession $session): void
    {
        $response = $this->buildConfirmationMessage($session);
        $this->sendJobMessage($session->phone, $response);
    }

    /**
     * Build confirmation message from temp data.
     */
    protected function buildConfirmationMessage(ConversationSession $session): array
    {
        $workerData = [
            'name' => $this->getTemp($session, 'name'),
            'photo_url' => $this->getTemp($session, 'photo_url'),
            'vehicle_type' => $this->getTemp($session, 'vehicle_type'),
            'job_types' => $this->getTemp($session, 'job_types', []),
            'availability' => $this->getTemp($session, 'availability', []),
        ];

        return JobMessages::confirmWorkerRegistration($workerData);
    }

    /**
     * Register the worker.
     *
     * FIXED: Now handles TWO cases:
     * 1. Existing registered user → registerExistingUserAsWorker()
     * 2. New unregistered user → createUserAndWorker()
     *
     * @srs-ref Section 3.2: Any user can become a job worker
     */
    protected function registerWorker(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Gather registration data from temp storage
        $workerData = [
            'name' => $this->getTemp($session, 'name'),
            'photo_url' => $this->getTemp($session, 'photo_url'),
            'latitude' => (float) $this->getTemp($session, 'latitude'),
            'longitude' => (float) $this->getTemp($session, 'longitude'),
            'address' => $this->getTemp($session, 'address'),
            'vehicle_type' => $this->getTemp($session, 'vehicle_type'),
            'job_types' => $this->getTemp($session, 'job_types', []),
            'availability' => $this->getTemp($session, 'availability', []),
        ];

        try {
            // Check if user is already registered (has registered_at set)
            if ($user && $user->registered_at) {
                // EXISTING USER: Add worker profile without changing user type
                // User keeps their type (CUSTOMER, SHOP) but also becomes a worker
                Log::info('Registering existing user as worker', [
                    'user_id' => $user->id,
                    'user_type' => $user->type->value,
                ]);

                $worker = $this->workerService->registerExistingUserAsWorker($user, $workerData);

                $this->clearTemp($session);
                $this->nextStep($session, self::STEP_COMPLETE);

                // Show success message with context for existing users
                $userTypeLabel = $user->type === UserType::SHOP ? 'shop owner' : 'customer';
                $this->sendButtons(
                    $session->phone,
                    "🎉 *Registration Successful!*\n" .
                    "*രജിസ്ട്രേഷൻ വിജയകരമായി!*\n\n" .
                    "👷 *{$worker->name}*\n\n" .
                    "You can now accept tasks while continuing as a {$userTypeLabel}!\n" .
                    "നിങ്ങൾക്ക് ഇപ്പോൾ {$userTypeLabel} ആയി തുടരുമ്പോൾ തന്നെ ജോലികൾ സ്വീകരിക്കാം!\n\n" .
                    "Ready to find your first task? 💼",
                    [
                        ['id' => 'browse_jobs', 'title' => '🔍 ജോലികൾ കാണുക'],
                        ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                    ],
                    '👷 Job Worker'
                );

            } else {
                // NEW USER: Create new user and worker profile together
                Log::info('Creating new user and worker', [
                    'phone' => $this->maskPhone($session->phone),
                ]);

                $workerData['phone'] = $session->phone;
                $newUser = $this->workerService->createUserAndWorker($workerData);
                $worker = $newUser->jobWorker;

                // Link session to new user
                $this->workerService->linkSessionToUser($session, $newUser);

                $this->clearTemp($session);
                $this->nextStep($session, self::STEP_COMPLETE);

                // Send standard success message for new users
                $response = JobMessages::workerRegistrationSuccess($worker);
                $this->sendJobMessage($session->phone, $response);
            }

        } catch (\InvalidArgumentException $e) {
            Log::error('Worker registration validation failed', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
                'user_id' => $user?->id,
            ]);

            $this->sendButtons(
                $session->phone,
                "❌ *Registration Failed*\n\n{$e->getMessage()}\n\nPlease try again.",
                [
                    ['id' => 'start_worker_registration', 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ]
            );

        } catch (\Exception $e) {
            Log::error('Worker registration failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'phone' => $this->maskPhone($session->phone),
                'user_id' => $user?->id,
            ]);

            $this->sendButtons(
                $session->phone,
                "❌ *Registration Failed*\n" .
                "*രജിസ്ട്രേഷൻ പരാജയപ്പെട്ടു*\n\n" .
                "Something went wrong. Please try again later.\n" .
                "എന്തോ പിശക് സംഭവിച്ചു. പിന്നീട് ശ്രമിക്കുക.",
                [
                    ['id' => 'start_worker_registration', 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
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
     * Send a JobMessages response.
     *
     * Routes message array to appropriate WhatsApp method based on type.
     */
    protected function sendJobMessage(string $phone, array $response): void
    {
        $type = $response['type'] ?? 'text';

        switch ($type) {
            case 'text':
                $this->sendText($phone, $response['text'] ?? $response['body'] ?? '');
                break;

            case 'buttons':
                $this->sendButtons(
                    $phone,
                    $response['body'] ?? '',
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

            case 'image':
                if (!empty($response['image'])) {
                    $this->sendImage($phone, $response['image'], $response['caption'] ?? null);
                }
                break;

            default:
                $this->sendText($phone, $response['text'] ?? $response['body'] ?? '');
        }
    }
}