<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\VehicleType;
use App\Models\ConversationSession;
use App\Models\JobCategory;
use App\Models\JobWorker;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Flow\FlowRouter;
use App\Services\Jobs\JobWorkerService;
use App\Services\Media\MediaService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\JobMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for job worker menu - profile view and edit.
 *
 * Features:
 * - View worker profile
 * - Edit profile fields (name, photo, location, vehicle, job types, availability)
 * - Toggle availability status
 * - View earnings and badges
 *
 * @srs-ref Section 3.2 - Worker Profile Management
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobWorkerMenuFlowHandler extends AbstractFlowHandler
{
    /**
     * Flow step constants.
     */
    protected const STEP_MENU = 'worker_menu';
    protected const STEP_VIEW_PROFILE = 'view_profile';
    protected const STEP_EDIT_SELECT = 'edit_select';
    protected const STEP_EDIT_NAME = 'edit_name';
    protected const STEP_EDIT_PHOTO = 'edit_photo';
    protected const STEP_EDIT_LOCATION = 'edit_location';
    protected const STEP_EDIT_VEHICLE = 'edit_vehicle';
    protected const STEP_EDIT_JOB_TYPES = 'edit_job_types';
    protected const STEP_EDIT_AVAILABILITY = 'edit_availability';
    protected const STEP_CONFIRM_EDIT = 'confirm_edit';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected JobWorkerService $workerService,
        protected MediaService $mediaService,
        protected FlowRouter $flowRouter
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::JOB_WORKER_MENU;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_MENU,
            self::STEP_VIEW_PROFILE,
            self::STEP_EDIT_SELECT,
            self::STEP_EDIT_NAME,
            self::STEP_EDIT_PHOTO,
            self::STEP_EDIT_LOCATION,
            self::STEP_EDIT_VEHICLE,
            self::STEP_EDIT_JOB_TYPES,
            self::STEP_EDIT_AVAILABILITY,
            self::STEP_CONFIRM_EDIT,
        ];
    }

    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            self::STEP_MENU => 'list',
            self::STEP_VIEW_PROFILE => 'button',
            self::STEP_EDIT_SELECT => 'list',
            self::STEP_EDIT_NAME => 'text',
            self::STEP_EDIT_PHOTO => 'image',
            self::STEP_EDIT_LOCATION => 'location',
            self::STEP_EDIT_VEHICLE => 'button',
            self::STEP_EDIT_JOB_TYPES => 'list',
            self::STEP_EDIT_AVAILABILITY => 'list',
            self::STEP_CONFIRM_EDIT => 'button',
            default => 'button',
        };
    }

    /**
     * Start the worker menu flow.
     */
    public function start(ConversationSession $session): void
    {
        $this->logInfo('Starting worker menu', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        $this->clearTemp($session);
        $this->nextStep($session, self::STEP_MENU);
        $this->showWorkerMenu($session);
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

        // Handle cross-flow navigation
        if ($this->handleCrossFlowNavigation($selectionId, $session)) {
            return;
        }

        $step = $session->current_step;

        Log::debug('JobWorkerMenuFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
            'selection_id' => $selectionId,
        ]);

        match ($step) {
            self::STEP_MENU => $this->handleMenu($message, $session),
            self::STEP_VIEW_PROFILE => $this->handleViewProfile($message, $session),
            self::STEP_EDIT_SELECT => $this->handleEditSelect($message, $session),
            self::STEP_EDIT_NAME => $this->handleEditName($message, $session),
            self::STEP_EDIT_PHOTO => $this->handleEditPhoto($message, $session),
            self::STEP_EDIT_LOCATION => $this->handleEditLocation($message, $session),
            self::STEP_EDIT_VEHICLE => $this->handleEditVehicle($message, $session),
            self::STEP_EDIT_JOB_TYPES => $this->handleEditJobTypes($message, $session),
            self::STEP_EDIT_AVAILABILITY => $this->handleEditAvailability($message, $session),
            self::STEP_CONFIRM_EDIT => $this->handleConfirmEdit($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Re-prompt current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = $session->current_step;

        match ($step) {
            self::STEP_MENU => $this->showWorkerMenu($session),
            self::STEP_VIEW_PROFILE => $this->showProfile($session),
            self::STEP_EDIT_SELECT => $this->showEditOptions($session),
            self::STEP_EDIT_NAME => $this->promptEditName($session),
            self::STEP_EDIT_PHOTO => $this->promptEditPhoto($session),
            self::STEP_EDIT_LOCATION => $this->promptEditLocation($session),
            self::STEP_EDIT_VEHICLE => $this->promptEditVehicle($session),
            self::STEP_EDIT_JOB_TYPES => $this->promptEditJobTypes($session),
            self::STEP_EDIT_AVAILABILITY => $this->promptEditAvailability($session),
            self::STEP_CONFIRM_EDIT => $this->showConfirmEdit($session),
            default => $this->start($session),
        };
    }

    /**
     * Handle cross-flow navigation.
     */
    protected function handleCrossFlowNavigation(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }

        switch ($selectionId) {
            case 'browse_jobs':
            case 'find_jobs':
                $this->clearTemp($session);
                $this->flowRouter->startFlow($session, FlowType::JOB_BROWSE);
                return true;

            case 'my_applications':
                $this->clearTemp($session);
                $this->flowRouter->startFlow($session, FlowType::JOB_APPLICATION);
                return true;

            case 'my_active_jobs':
            case 'my_jobs':
                $this->clearTemp($session);
                $this->flowRouter->startFlow($session, FlowType::JOB_EXECUTION);
                return true;

            case 'post_job':
                $this->clearTemp($session);
                $this->flowRouter->startFlow($session, FlowType::JOB_POST);
                return true;

            case 'my_posted_jobs':
                $this->clearTemp($session);
                $this->flowRouter->startFlow($session, FlowType::JOB_POSTER_MENU);
                return true;

            case 'my_earnings':
            case 'earnings':
                $this->showEarnings($session);
                return true;

            case 'my_badges':
            case 'badges':
            case 'view_badges':
                $this->showBadges($session);
                return true;

            case 'main_menu':
                $this->clearTemp($session);
                $this->flowRouter->goToMainMenu($session);
                return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Menu Step
    |--------------------------------------------------------------------------
    */

    protected function handleMenu(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        switch ($selectionId) {
            case 'worker_profile':
            case 'view_profile':
                $this->nextStep($session, self::STEP_VIEW_PROFILE);
                $this->showProfile($session);
                break;

            case 'edit_profile':
                $this->nextStep($session, self::STEP_EDIT_SELECT);
                $this->showEditOptions($session);
                break;

            case 'toggle_availability':
                $this->toggleAvailability($session);
                break;

            case 'find_jobs':
                // Navigate to job browse flow
                $this->clearTemp($session);
                $this->flowRouter->startFlow($session, FlowType::JOB_BROWSE);
                break;

            case 'my_jobs':
                // Navigate to job execution flow (shows assigned jobs)
                $this->clearTemp($session);
                $this->flowRouter->startFlow($session, FlowType::JOB_EXECUTION);
                break;

            case 'post_job':
                // Navigate to job posting flow - workers can also post jobs
                $this->clearTemp($session);
                $this->flowRouter->startFlow($session, FlowType::JOB_POST);
                break;

            case 'my_posted_jobs':
                // Navigate to job poster menu to view posted jobs
                $this->clearTemp($session);
                $this->flowRouter->startFlow($session, FlowType::JOB_POSTER_MENU);
                break;

            case 'earnings':
                $this->showEarnings($session);
                break;

            case 'view_badges':
            case 'badges':
                $this->showBadges($session);
                break;

            case 'main_menu':
                $this->clearTemp($session);
                $this->flowRouter->goToMainMenu($session);
                break;

            default:
                $this->showWorkerMenu($session);
        }
    }

    protected function showWorkerMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $worker = $user?->jobWorker;

        if (!$worker) {
            // Not registered as worker - redirect to registration
            $this->sendButtons(
                $session->phone,
                "👷 *Not Registered*\n\n" .
                "You are not registered as a worker yet.\n" .
                "Register to start earning! 💰",
                [
                    ['id' => 'register_worker', 'title' => '✅ Register Now'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        $response = JobMessages::workerMenuHeader($worker);
        
        $this->whatsApp->sendList(
            $session->phone,
            $response,
            'Select Option',
            [[
                'title' => 'Worker Options',
                'rows' => [
                    ['id' => 'view_profile', 'title' => '👤 My Profile', 'description' => 'View your worker profile'],
                    ['id' => 'edit_profile', 'title' => '✏️ Edit Profile', 'description' => 'Update your details'],
                    ['id' => 'find_jobs', 'title' => '🔍 Find Jobs', 'description' => 'Browse available jobs'],
                    ['id' => 'my_jobs', 'title' => '📋 My Jobs', 'description' => 'View jobs assigned to you'],
                    ['id' => 'post_job', 'title' => '📝 Post a Job', 'description' => 'Post a task for others'],
                    ['id' => 'my_posted_jobs', 'title' => '📂 My Posted Jobs', 'description' => 'View jobs you have posted'],
                    ['id' => 'earnings', 'title' => '💰 Earnings', 'description' => 'View your earnings'],
                    ['id' => 'toggle_availability', 'title' => $worker->is_available ? '🔴 Go Offline' : '🟢 Go Online', 'description' => 'Toggle availability'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'],
                ],
            ]],
            '👷 Worker Menu'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | View Profile
    |--------------------------------------------------------------------------
    */

    protected function handleViewProfile(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'edit_profile') {
            $this->nextStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        if ($selectionId === 'toggle_availability') {
            $this->toggleAvailability($session);
            return;
        }

        if ($selectionId === 'back_to_menu') {
            $this->nextStep($session, self::STEP_MENU);
            $this->showWorkerMenu($session);
            return;
        }

        $this->showProfile($session);
    }

    protected function showProfile(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        
        if (!$user) {
            $this->showWorkerMenu($session);
            return;
        }
        
        $worker = $user->jobWorker;

        if (!$worker) {
            $this->showWorkerMenu($session);
            return;
        }

        // Load user relationship for profile display
        if (!$worker->relationLoaded('user')) {
            $worker->load('user');
        }

        $response = JobMessages::workerProfileView($worker);
        
        $this->whatsApp->sendButtons(
            $session->phone,
            $response,
            [
                ['id' => 'edit_profile', 'title' => '✏️ Edit Profile'],
                ['id' => 'toggle_availability', 'title' => $worker->is_available ? '🔴 Go Offline' : '🟢 Go Online'],
                ['id' => 'back_to_menu', 'title' => '⬅️ Back'],
            ],
            '👤 My Profile'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Profile - Selection
    |--------------------------------------------------------------------------
    */

    protected function handleEditSelect(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        switch ($selectionId) {
            case 'edit_name':
                $this->nextStep($session, self::STEP_EDIT_NAME);
                $this->promptEditName($session);
                break;

            case 'edit_photo':
                $this->nextStep($session, self::STEP_EDIT_PHOTO);
                $this->promptEditPhoto($session);
                break;

            case 'edit_location':
                $this->nextStep($session, self::STEP_EDIT_LOCATION);
                $this->promptEditLocation($session);
                break;

            case 'edit_vehicle':
                $this->nextStep($session, self::STEP_EDIT_VEHICLE);
                $this->promptEditVehicle($session);
                break;

            case 'edit_job_types':
                $this->nextStep($session, self::STEP_EDIT_JOB_TYPES);
                $this->setTemp($session, 'new_job_types', []);
                $this->promptEditJobTypes($session);
                break;

            case 'edit_availability':
                $this->nextStep($session, self::STEP_EDIT_AVAILABILITY);
                $this->promptEditAvailability($session);
                break;

            case 'back_to_profile':
                $this->nextStep($session, self::STEP_VIEW_PROFILE);
                $this->showProfile($session);
                break;

            default:
                $this->showEditOptions($session);
        }
    }

    protected function showEditOptions(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $worker = $user?->jobWorker;

        if (!$worker) {
            $this->showWorkerMenu($session);
            return;
        }

        // Name is stored in job_workers table
        $workerName = $worker->name ?? 'Worker';

        $this->sendList(
            $session->phone,
            "✏️ *Edit Profile*\n" .
            "*പ്രൊഫൈൽ എഡിറ്റ് ചെയ്യുക*\n\n" .
            "👤 *{$workerName}*\n\n" .
            "What would you like to change?",
            'Edit',
            [
                [
                    'title' => 'Profile Fields',
                    'rows' => [
                        ['id' => 'edit_name', 'title' => '👤 Edit Name', 'description' => 'Change your display name'],
                        ['id' => 'edit_photo', 'title' => '📸 Edit Photo', 'description' => 'Update profile photo'],
                        ['id' => 'edit_location', 'title' => '📍 Edit Location', 'description' => 'Update your location'],
                        ['id' => 'edit_vehicle', 'title' => '🚗 Edit Vehicle', 'description' => 'Change vehicle type'],
                        ['id' => 'edit_job_types', 'title' => '💼 Edit Job Types', 'description' => 'Update job preferences'],
                        ['id' => 'edit_availability', 'title' => '🕐 Edit Availability', 'description' => 'Change available times'],
                        ['id' => 'back_to_profile', 'title' => '⬅️ Back', 'description' => 'Return to profile'],
                    ],
                ],
            ],
            '✏️ Edit Profile'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Name
    |--------------------------------------------------------------------------
    */

    protected function handleEditName(IncomingMessage $message, ConversationSession $session): void
    {
        $text = $this->getTextContent($message);
        $selectionId = $this->getSelectionId($message);

        // Cancel
        if ($selectionId === 'cancel_edit' || $this->isCancel($message)) {
            $this->nextStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        // Validate and save name (2-100 characters, no special chars)
        $trimmedText = trim($text ?? '');
        $isValidName = strlen($trimmedText) >= 2 && strlen($trimmedText) <= 100 && preg_match('/^[\p{L}\p{M}\s\'-]+$/u', $trimmedText);
        
        if ($trimmedText && $isValidName) {
            $this->setTemp($session, 'edit_field', 'name');
            $this->setTemp($session, 'edit_value', $trimmedText);
            $this->nextStep($session, self::STEP_CONFIRM_EDIT);
            $this->showConfirmEdit($session);
            return;
        }

        // Invalid name
        $this->sendButtons(
            $session->phone,
            "❌ *Invalid Name*\n\n" .
            "Please enter a valid name (2-100 characters).\n" .
            "ദയവായി ശരിയായ പേര് നൽകുക.",
            [
                ['id' => 'cancel_edit', 'title' => '❌ Cancel'],
            ]
        );
    }

    protected function promptEditName(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;
        $currentName = $worker?->name ?? 'Unknown';

        $this->sendButtons(
            $session->phone,
            "👤 *Edit Name*\n\n" .
            "Current: *{$currentName}*\n\n" .
            "Enter your new name:",
            [
                ['id' => 'cancel_edit', 'title' => '❌ Cancel'],
            ],
            '✏️ Edit Name'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Photo
    |--------------------------------------------------------------------------
    */

    protected function handleEditPhoto(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Cancel
        if ($selectionId === 'cancel_edit' || $this->isCancel($message)) {
            $this->nextStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        // Remove photo
        if ($selectionId === 'remove_photo') {
            $this->setTemp($session, 'edit_field', 'photo_url');
            $this->setTemp($session, 'edit_value', null);
            $this->nextStep($session, self::STEP_CONFIRM_EDIT);
            $this->showConfirmEdit($session);
            return;
        }

        // Handle image upload
        if ($message->isImage()) {
            try {
                $mediaId = $this->getMediaId($message);
                if ($mediaId) {
                    $photoUrl = $this->mediaService->downloadAndStore(
                        $mediaId,
                        'worker-photos',
                        $session->phone
                    );

                    $this->setTemp($session, 'edit_field', 'photo_url');
                    $this->setTemp($session, 'edit_value', $photoUrl);
                    $this->nextStep($session, self::STEP_CONFIRM_EDIT);
                    $this->showConfirmEdit($session);
                    return;
                }
            } catch (\Exception $e) {
                Log::error('Failed to upload worker photo', ['error' => $e->getMessage()]);
            }
        }

        $this->promptEditPhoto($session);
    }

    protected function promptEditPhoto(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;
        $hasPhoto = !empty($worker?->photo_url);

        $buttons = [
            ['id' => 'cancel_edit', 'title' => '❌ Cancel'],
        ];

        if ($hasPhoto) {
            array_unshift($buttons, ['id' => 'remove_photo', 'title' => '🗑️ Remove Photo']);
        }

        $this->sendButtons(
            $session->phone,
            "📸 *Edit Photo*\n\n" .
            "Current photo: " . ($hasPhoto ? '✅ Yes' : '❌ None') . "\n\n" .
            "Send a new photo or remove current one.\n" .
            "📎 → Camera/Gallery tap",
            $buttons,
            '✏️ Edit Photo'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Location
    |--------------------------------------------------------------------------
    */

    protected function handleEditLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Cancel
        if ($selectionId === 'cancel_edit' || $this->isCancel($message)) {
            $this->nextStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        // Handle location
        $location = $this->getLocation($message);
        if ($location && isset($location['latitude'], $location['longitude'])) {
            // Validate coordinates (latitude: -90 to 90, longitude: -180 to 180)
            $lat = (float) $location['latitude'];
            $lng = (float) $location['longitude'];
            $isValidCoordinates = $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
            
            if ($isValidCoordinates) {
                $this->setTemp($session, 'edit_field', 'location');
                $this->setTemp($session, 'edit_value', [
                    'latitude' => $lat,
                    'longitude' => $lng,
                    'address' => $location['address'] ?? $location['name'] ?? null,
                ]);
                $this->nextStep($session, self::STEP_CONFIRM_EDIT);
                $this->showConfirmEdit($session);
                return;
            }
        }

        $this->promptEditLocation($session);
    }

    protected function promptEditLocation(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;
        $currentLocation = $worker?->address ?? 'Not set';

        $this->sendButtons(
            $session->phone,
            "📍 *Edit Location*\n\n" .
            "Current: {$currentLocation}\n\n" .
            "Share your new location.\n" .
            "📎 → Location tap",
            [
                ['id' => 'cancel_edit', 'title' => '❌ Cancel'],
            ],
            '✏️ Edit Location'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Vehicle
    |--------------------------------------------------------------------------
    */

    protected function handleEditVehicle(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Cancel
        if ($selectionId === 'cancel_edit' || $this->isCancel($message)) {
            $this->nextStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        $vehicleType = match ($selectionId) {
            'vehicle_none' => 'none',
            'vehicle_two_wheeler' => 'two_wheeler',
            'vehicle_four_wheeler' => 'four_wheeler',
            default => null,
        };

        if ($vehicleType !== null) {
            $this->setTemp($session, 'edit_field', 'vehicle_type');
            $this->setTemp($session, 'edit_value', $vehicleType);
            $this->nextStep($session, self::STEP_CONFIRM_EDIT);
            $this->showConfirmEdit($session);
            return;
        }

        $this->promptEditVehicle($session);
    }

    protected function promptEditVehicle(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;
        $currentVehicle = $worker?->vehicle_display ?? 'Not set';

        $this->sendButtons(
            $session->phone,
            "🚗 *Edit Vehicle*\n\n" .
            "Current: {$currentVehicle}\n\n" .
            "Select your vehicle type:",
            [
                ['id' => 'vehicle_none', 'title' => '🚶 Walking Only'],
                ['id' => 'vehicle_two_wheeler', 'title' => '🛵 Two Wheeler'],
                ['id' => 'vehicle_four_wheeler', 'title' => '🚗 Four Wheeler'],
            ],
            '✏️ Edit Vehicle'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Job Types
    |--------------------------------------------------------------------------
    */

    protected function handleEditJobTypes(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Done selecting
        if ($selectionId === 'jobtype_done') {
            $newJobTypes = $this->getTemp($session, 'new_job_types', []);
            
            if (empty($newJobTypes)) {
                $this->sendTextWithMenu(
                    $session->phone,
                    "⚠️ Please select at least one job type."
                );
                return;
            }

            $this->setTemp($session, 'edit_field', 'job_types');
            $this->setTemp($session, 'edit_value', $newJobTypes);
            $this->nextStep($session, self::STEP_CONFIRM_EDIT);
            $this->showConfirmEdit($session);
            return;
        }

        // Cancel
        if ($selectionId === 'cancel_edit' || $this->isCancel($message)) {
            $this->nextStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        // Handle job type selection
        if ($selectionId && str_starts_with($selectionId, 'jobtype_')) {
            $categoryId = (int) str_replace('jobtype_', '', $selectionId);

            if ($categoryId > 0) {
                $category = JobCategory::find($categoryId);
                if ($category) {
                    $currentTypes = $this->getTemp($session, 'new_job_types', []);

                    if (in_array($categoryId, $currentTypes)) {
                        $currentTypes = array_values(array_diff($currentTypes, [$categoryId]));
                        $emoji = '❌';
                        $action = 'removed';
                    } else {
                        $currentTypes[] = $categoryId;
                        $emoji = '✅';
                        $action = 'added';
                    }

                    $this->setTemp($session, 'new_job_types', $currentTypes);

                    $count = count($currentTypes);
                    $this->sendButtons(
                        $session->phone,
                        "{$emoji} *{$category->name_ml}* {$action}\n\n" .
                        "📋 Selected: *{$count}* job types\n\n" .
                        "Select more or tap Done.",
                        [
                            ['id' => 'jobtype_done', 'title' => '✅ Done'],
                            ['id' => 'show_job_types', 'title' => '📋 Add More'],
                        ]
                    );
                    return;
                }
            }
        }

        // Show more job types
        if ($selectionId === 'show_job_types') {
            $this->promptEditJobTypes($session);
            return;
        }

        $this->promptEditJobTypes($session);
    }

    protected function promptEditJobTypes(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;
        $currentCount = count($worker?->job_types ?? []);

        $categories = JobCategory::active()
            ->orderBy('tier')
            ->orderBy('sort_order')
            ->get();

        $rows = $categories->take(9)->map(function($cat) {
            return [
                'id' => 'jobtype_' . $cat->id,
                'title' => mb_substr($cat->icon . ' ' . $cat->name_en, 0, 24),
                'description' => $cat->name_ml,
            ];
        })->toArray();

        $rows[] = ['id' => 'jobtype_done', 'title' => '✅ Done', 'description' => 'Finish selection'];

        $this->sendList(
            $session->phone,
            "💼 *Edit Job Types*\n\n" .
            "Current: {$currentCount} job types\n\n" .
            "Select the job types you want to do.\n" .
            "_Previous selection will be replaced._",
            'Select Jobs',
            [['title' => 'Job Types', 'rows' => $rows]],
            '✏️ Edit Job Types'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Availability
    |--------------------------------------------------------------------------
    */

    protected function handleEditAvailability(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Cancel
        if ($selectionId === 'cancel_edit' || $this->isCancel($message)) {
            $this->nextStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        $availability = match ($selectionId) {
            'avail_morning' => ['morning'],
            'avail_afternoon' => ['afternoon'],
            'avail_evening' => ['evening'],
            'avail_flexible' => ['flexible'],
            default => null,
        };

        if ($availability !== null) {
            $this->setTemp($session, 'edit_field', 'availability');
            $this->setTemp($session, 'edit_value', $availability);
            $this->nextStep($session, self::STEP_CONFIRM_EDIT);
            $this->showConfirmEdit($session);
            return;
        }

        $this->promptEditAvailability($session);
    }

    protected function promptEditAvailability(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;
        $currentAvail = $worker?->availability ?? ['flexible'];
        $currentDisplay = match($currentAvail[0] ?? 'flexible') {
            'morning' => '🌅 Morning',
            'afternoon' => '☀️ Afternoon',
            'evening' => '🌆 Evening',
            default => '🔄 Flexible',
        };

        $this->sendList(
            $session->phone,
            "🕐 *Edit Availability*\n\n" .
            "Current: {$currentDisplay}\n\n" .
            "When are you available for work?",
            'Select Time',
            [
                [
                    'title' => 'Available Time',
                    'rows' => [
                        ['id' => 'avail_morning', 'title' => '🌅 Morning', 'description' => '6:00 AM - 12:00 PM'],
                        ['id' => 'avail_afternoon', 'title' => '☀️ Afternoon', 'description' => '12:00 PM - 6:00 PM'],
                        ['id' => 'avail_evening', 'title' => '🌆 Evening', 'description' => '6:00 PM - 10:00 PM'],
                        ['id' => 'avail_flexible', 'title' => '🔄 Flexible', 'description' => 'Any time'],
                    ],
                ],
            ],
            '✏️ Edit Availability'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Confirm Edit
    |--------------------------------------------------------------------------
    */

    protected function handleConfirmEdit(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'confirm_save') {
            $this->saveEdit($session);
            return;
        }

        if ($selectionId === 'cancel_edit') {
            $this->nextStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        $this->showConfirmEdit($session);
    }

    protected function showConfirmEdit(ConversationSession $session): void
    {
        $field = $this->getTemp($session, 'edit_field');
        $value = $this->getTemp($session, 'edit_value');

        $fieldLabel = match($field) {
            'name' => '👤 Name',
            'photo_url' => '📸 Photo',
            'location' => '📍 Location',
            'vehicle_type' => '🚗 Vehicle',
            'job_types' => '💼 Job Types',
            'availability' => '🕐 Availability',
            default => 'Field',
        };

        $valueDisplay = match($field) {
            'name' => $value,
            'photo_url' => $value ? 'New photo' : 'Remove photo',
            'location' => $value['address'] ?? 'New location',
            'vehicle_type' => match($value) {
                'none' => '🚶 Walking Only',
                'two_wheeler' => '🛵 Two Wheeler',
                'four_wheeler' => '🚗 Four Wheeler',
                default => $value,
            },
            'job_types' => count($value) . ' job types',
            'availability' => match($value[0] ?? 'flexible') {
                'morning' => '🌅 Morning',
                'afternoon' => '☀️ Afternoon',
                'evening' => '🌆 Evening',
                default => '🔄 Flexible',
            },
            default => (string) $value,
        };

        $this->sendButtons(
            $session->phone,
            "✅ *Confirm Change*\n\n" .
            "{$fieldLabel}: *{$valueDisplay}*\n\n" .
            "Save this change?",
            [
                ['id' => 'confirm_save', 'title' => '✅ Save'],
                ['id' => 'cancel_edit', 'title' => '❌ Cancel'],
            ],
            '✏️ Confirm Edit'
        );
    }

    protected function saveEdit(ConversationSession $session): void
    {
        $field = $this->getTemp($session, 'edit_field');
        $value = $this->getTemp($session, 'edit_value');

        $user = $this->getUser($session);
        $worker = $user?->jobWorker;

        if (!$worker) {
            $this->sendTextWithMenu($session->phone, "❌ Worker profile not found.");
            return;
        }

        try {
            $updateData = [];

            if ($field === 'location' && is_array($value)) {
                $updateData['latitude'] = $value['latitude'];
                $updateData['longitude'] = $value['longitude'];
                $updateData['address'] = $value['address'] ?? null;
            } else {
                $updateData[$field] = $value;
            }

            // Update worker directly using the model
            $worker->update($updateData);

            $this->sendButtons(
                $session->phone,
                "✅ *Profile Updated!*\n\n" .
                "Your profile has been updated successfully.\n" .
                "പ്രൊഫൈൽ അപ്‌ഡേറ്റ് ചെയ്തു!",
                [
                    ['id' => 'view_profile', 'title' => '👤 View Profile'],
                    ['id' => 'back_to_menu', 'title' => '⬅️ Back to Menu'],
                ],
                '✅ Updated'
            );

            $this->clearTemp($session);
            $this->nextStep($session, self::STEP_MENU);

        } catch (\Exception $e) {
            Log::error('Failed to update worker profile', [
                'error' => $e->getMessage(),
                'field' => $field,
            ]);

            $this->sendTextWithMenu(
                $session->phone,
                "❌ Failed to update profile. Please try again."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Toggle Availability
    |--------------------------------------------------------------------------
    */

    protected function toggleAvailability(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;

        if (!$worker) {
            return;
        }

        // Toggle availability directly using the model
        $newStatus = !$worker->is_available;
        $worker->update(['is_available' => $newStatus]);
        
        $statusText = $newStatus ? '🟢 *Available*' : '🔴 *Unavailable*';

        $this->sendButtons(
            $session->phone,
            "✅ Status updated!\n\n" .
            "You are now: {$statusText}\n\n" .
            ($newStatus
                ? "You'll receive job notifications."
                : "You won't receive new job notifications."),
            [
                ['id' => 'toggle_availability', 'title' => $newStatus ? '🔴 Go Offline' : '🟢 Go Online'],
                ['id' => 'view_profile', 'title' => '👤 Profile'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Earnings & Badges
    |--------------------------------------------------------------------------
    */

    protected function showEarnings(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;

        if (!$worker) {
            $this->showWorkerMenu($session);
            return;
        }

        // Check if earnings relationship exists
        $weekEarnings = null;
        if (method_exists($worker, 'earnings')) {
            $weekEarnings = $worker->earnings()->where('created_at', '>=', now()->startOfWeek())->first();
        }
        
        $response = JobMessages::workerEarningsSummary($worker, $weekEarnings);
        
        $this->whatsApp->sendButtons(
            $session->phone,
            $response,
            [
                ['id' => 'view_badges', 'title' => '🏅 View Badges'],
                ['id' => 'find_jobs', 'title' => '🔍 Find Jobs'],
                ['id' => 'back_to_menu', 'title' => '⬅️ Back'],
            ],
            '💰 Earnings'
        );
    }

    protected function showBadges(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;

        if (!$worker) {
            $this->showWorkerMenu($session);
            return;
        }

        $badges = $worker->badges;

        if ($badges->isEmpty()) {
            $this->sendButtons(
                $session->phone,
                "🏅 *My Badges*\n\n" .
                "No badges yet.\n\n" .
                "Complete jobs to earn badges!\n" .
                "• 5 jobs = First Step 🎯\n" .
                "• 10 queue jobs = Queue Master 🧍\n" .
                "• ⭐4.5+ rating = Top Rated ⭐",
                [
                    ['id' => 'browse_jobs', 'title' => '🔍 Find Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        $badgeList = $badges->map(fn($b) => "• {$b->badge_type->emoji()} {$b->badge_type->label()}")->join("\n");

        $this->sendButtons(
            $session->phone,
            "🏅 *My Badges* ({$badges->count()})\n\n" .
            $badgeList,
            [
                ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /**
     * Extract location data from incoming message.
     */
    protected function getLocation(IncomingMessage $message): ?array
    {
        if (!$message->isLocation()) {
            return null;
        }

        $location = $message->location ?? [];
        
        if (isset($location['latitude'], $location['longitude'])) {
            return [
                'latitude' => (float) $location['latitude'],
                'longitude' => (float) $location['longitude'],
                'name' => $location['name'] ?? null,
                'address' => $location['address'] ?? null,
            ];
        }

        return null;
    }
}