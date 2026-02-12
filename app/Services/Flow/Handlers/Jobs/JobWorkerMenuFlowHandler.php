<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\JobCategory;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Jobs\JobWorkerService;
use App\Services\Media\MediaService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Handler for job worker menu - simplified dashboard.
 *
 * Dashboard format:
 * "👷 Worker Dashboard:
 *  💰 This week: ₹[Amount] earned
 *  ⭐ Rating: [X]/5 | Jobs: [Y] completed
 *  [🔍 Available Jobs Nearby]
 *  [📋 My Active Jobs]
 *  [💰 Earnings History]
 *  [⚙️ Update Preferences]"
 *
 * @srs-ref Section 3.2 - Worker Profile Management
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobWorkerMenuFlowHandler extends AbstractFlowHandler
{
    protected const STEP_MENU = 'worker_menu';
    protected const STEP_VIEW_PROFILE = 'view_profile';
    protected const STEP_EDIT_SELECT = 'edit_select';
    protected const STEP_EDIT_NAME = 'edit_name';
    protected const STEP_EDIT_VEHICLE = 'edit_vehicle';
    protected const STEP_EDIT_JOB_TYPES = 'edit_job_types';
    protected const STEP_CONFIRM_EDIT = 'confirm_edit';

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
        return FlowType::JOB_WORKER_MENU;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_MENU,
            self::STEP_VIEW_PROFILE,
            self::STEP_EDIT_SELECT,
            self::STEP_EDIT_NAME,
            self::STEP_EDIT_VEHICLE,
            self::STEP_EDIT_JOB_TYPES,
            self::STEP_CONFIRM_EDIT,
        ];
    }

    public function getExpectedInputType(string $step): string
    {
        return match ($step) {
            self::STEP_EDIT_NAME => 'text',
            default => 'button',
        };
    }

    /**
     * Start the worker menu flow.
     */
    public function start(ConversationSession $session): void
    {
        $this->clearTempData($session);
        $this->sessionManager->setFlowStep($session, FlowType::JOB_WORKER_MENU, self::STEP_MENU);
        $this->showWorkerDashboard($session);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle button clicks
        if ($this->handleButtonClick($selectionId, $session)) {
            return;
        }

        $step = $session->current_step;

        match ($step) {
            self::STEP_MENU => $this->handleMenu($message, $session),
            self::STEP_VIEW_PROFILE => $this->handleViewProfile($message, $session),
            self::STEP_EDIT_SELECT => $this->handleEditSelect($message, $session),
            self::STEP_EDIT_NAME => $this->handleEditName($message, $session),
            self::STEP_EDIT_VEHICLE => $this->handleEditVehicle($message, $session),
            self::STEP_EDIT_JOB_TYPES => $this->handleEditJobTypes($message, $session),
            //self::STEP_CONFIRM_EDIT => $this->handleConfirmEdit($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Re-prompt current step.
     */
    public function promptCurrentStep(ConversationSession $session): void
    {
        $step = $session->current_step;

        match ($step) {
            self::STEP_MENU => $this->showWorkerDashboard($session),
            self::STEP_VIEW_PROFILE => $this->showProfile($session),
            self::STEP_EDIT_SELECT => $this->showEditOptions($session),
            default => $this->start($session),
        };
    }

    /**
     * Handle button clicks.
     */
    protected function handleButtonClick(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }

        switch ($selectionId) {
            case 'browse_jobs':
            case 'find_jobs':
            case 'available_jobs':
                $this->clearTempData($session);
                $this->startFlow($session, FlowType::JOB_BROWSE);
                return true;

            case 'my_active_jobs':
            case 'active_jobs':
                $this->clearTempData($session);
                $this->startFlow($session, FlowType::JOB_EXECUTION);
                return true;

            case 'earnings':
            case 'earnings_history':
                $this->showEarnings($session);
                return true;

            case 'update_prefs':
            case 'edit_profile':
                $this->setStep($session, self::STEP_EDIT_SELECT);
                $this->showEditOptions($session);
                return true;

            case 'toggle_online':
                $this->toggleAvailability($session);
                return true;

            case 'view_profile':
                $this->setStep($session, self::STEP_VIEW_PROFILE);
                $this->showProfile($session);
                return true;

            case 'back_to_menu':
            case 'worker_menu':
                $this->setStep($session, self::STEP_MENU);
                $this->showWorkerDashboard($session);
                return true;

            case 'main_menu':
            case 'menu':
                $this->goToMenu($session);
                return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Dashboard (Main Menu)
    |--------------------------------------------------------------------------
    */

    protected function handleMenu(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId) {
            $this->handleButtonClick($selectionId, $session);
            return;
        }

        $this->showWorkerDashboard($session);
    }

    /**
     * Show simplified worker dashboard.
     */
    protected function showWorkerDashboard(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $worker = $user?->jobWorker;

        if (!$worker) {
            $this->sendButtons(
                $session->phone,
                "👷 *Worker allallo?*\n\nRegister cheytho? 💰",
                [
                    ['id' => 'register_worker', 'title' => '✅ Register Now'],
                    ['id' => 'menu', 'title' => '📋 Menu'],
                ]
            );
            return;
        }

        // Get stats
        $weekEarnings = $this->getWeekEarnings($worker);
        $rating = $worker->rating ? number_format($worker->rating, 1) : '0.0';
        $jobsCompleted = $worker->jobs_completed ?? 0;
        $status = $worker->is_available ? '🟢 Online' : '🔴 Offline';

        // Active jobs count
        $activeJobs = JobPost::where('assigned_worker_id', $worker->id)
            ->whereIn('status', ['assigned', 'in_progress'])
            ->count();

        $activeLabel = $activeJobs > 0 ? "({$activeJobs})" : '';

        // Dashboard message (compact)
        $message = "👷 *Worker Dashboard*\n\n" .
            "💰 This week: *₹{$weekEarnings}* earned\n" .
            "⭐ Rating: *{$rating}/5* | Jobs: *{$jobsCompleted}*\n" .
            "Status: {$status}";

        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'available_jobs', 'title' => '🔍 Jobs Nearby'],
                ['id' => 'active_jobs', 'title' => "📋 Active {$activeLabel}"],
                ['id' => 'update_prefs', 'title' => '⚙️ Preferences'],
            ]
        );

        // Send secondary options as list
        $this->sendList(
            $session->phone,
            "More options:",
            'More',
            [[
                'title' => 'Options',
                'rows' => [
                    ['id' => 'earnings_history', 'title' => '💰 Earnings History'],
                    ['id' => 'view_profile', 'title' => '👤 My Profile'],
                    ['id' => 'toggle_online', 'title' => $worker->is_available ? '🔴 Go Offline' : '🟢 Go Online'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ],
            ]]
        );
    }

    /**
     * Get this week's earnings.
     */
    protected function getWeekEarnings(JobWorker $worker): string
    {
        $startOfWeek = now()->startOfWeek();

        $earnings = JobPost::where('assigned_worker_id', $worker->id)
            ->where('status', 'completed')
            ->where('completed_at', '>=', $startOfWeek)
            ->sum('pay_amount');

        return number_format((float) $earnings);
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
            $this->setStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        if ($selectionId === 'back_to_menu') {
            $this->setStep($session, self::STEP_MENU);
            $this->showWorkerDashboard($session);
            return;
        }

        $this->showProfile($session);
    }

    protected function showProfile(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $worker = $user?->jobWorker;

        if (!$worker) {
            $this->showWorkerDashboard($session);
            return;
        }

        $rating = $worker->rating ? number_format($worker->rating, 1) . '/5' : 'New';
        $jobTypes = $this->formatJobTypes($worker->job_types ?? []);
        $vehicle = $this->formatVehicle($worker->vehicle_type);
        $status = $worker->is_available ? '🟢 Available' : '🔴 Unavailable';

        $message = "👤 *{$worker->name}*\n\n" .
            "⭐ Rating: {$rating}\n" .
            "✅ Jobs: {$worker->jobs_completed} completed\n" .
            "🚗 Vehicle: {$vehicle}\n" .
            "💼 Jobs: {$jobTypes}\n" .
            "Status: {$status}";

        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'edit_profile', 'title' => '✏️ Edit'],
                ['id' => 'toggle_online', 'title' => $worker->is_available ? '🔴 Offline' : '🟢 Online'],
                ['id' => 'back_to_menu', 'title' => '⬅️ Back'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Profile
    |--------------------------------------------------------------------------
    */

    protected function handleEditSelect(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        switch ($selectionId) {
            case 'edit_name':
                $this->setStep($session, self::STEP_EDIT_NAME);
                $this->promptEditName($session);
                break;

            case 'edit_vehicle':
                $this->setStep($session, self::STEP_EDIT_VEHICLE);
                $this->promptEditVehicle($session);
                break;

            case 'edit_job_types':
                $this->setStep($session, self::STEP_EDIT_JOB_TYPES);
                $this->setTempData($session, 'new_job_types', []);
                $this->promptEditJobTypes($session);
                break;

            case 'back_to_menu':
                $this->setStep($session, self::STEP_MENU);
                $this->showWorkerDashboard($session);
                break;

            default:
                $this->showEditOptions($session);
        }
    }

    protected function showEditOptions(ConversationSession $session): void
    {
        $this->sendList(
            $session->phone,
            "⚙️ *Update Preferences*\n\nEnthokke maattan?",
            'Select',
            [[
                'title' => 'Edit Options',
                'rows' => [
                    ['id' => 'edit_name', 'title' => '👤 Name'],
                    ['id' => 'edit_vehicle', 'title' => '🚗 Vehicle'],
                    ['id' => 'edit_job_types', 'title' => '💼 Job Types'],
                    ['id' => 'back_to_menu', 'title' => '⬅️ Back'],
                ],
            ]]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Edit Name
    |--------------------------------------------------------------------------
    */

    protected function handleEditName(IncomingMessage $message, ConversationSession $session): void
    {
        $text = trim($message->getText() ?? '');
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'cancel' || $this->isCancel($message)) {
            $this->setStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        if ($text && strlen($text) >= 2 && strlen($text) <= 100) {
            $this->saveWorkerField($session, 'name', $text);
            return;
        }

        $this->promptEditName($session);
    }

    protected function promptEditName(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;
        
        $this->sendButtons(
            $session->phone,
            "👤 *Name maattan*\n\nCurrent: *{$worker->name}*\n\nPuthiya peru type cheyyuka:",
            [['id' => 'cancel', 'title' => '❌ Cancel']]
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

        if ($selectionId === 'cancel') {
            $this->setStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        $vehicleType = match ($selectionId) {
            'vehicle_none' => 'none',
            'vehicle_two' => 'two_wheeler',
            'vehicle_four' => 'four_wheeler',
            default => null,
        };

        if ($vehicleType !== null) {
            $this->saveWorkerField($session, 'vehicle_type', $vehicleType);
            return;
        }

        $this->promptEditVehicle($session);
    }

    protected function promptEditVehicle(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "🚗 *Vehicle maattan*\n\nSelect cheyyuka:",
            [
                ['id' => 'vehicle_none', 'title' => '🚶 Walking'],
                ['id' => 'vehicle_two', 'title' => '🛵 Two Wheeler'],
                ['id' => 'vehicle_four', 'title' => '🚗 Four Wheeler'],
            ]
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

        if ($selectionId === 'done_types') {
            $newTypes = $this->getTempData($session, 'new_job_types', []);
            
            if (empty($newTypes)) {
                $this->sendText($session->phone, "⚠️ Minimum 1 job type select cheyyuka.");
                return;
            }

            $this->saveWorkerField($session, 'job_types', $newTypes);
            return;
        }

        if ($selectionId === 'cancel') {
            $this->setStep($session, self::STEP_EDIT_SELECT);
            $this->showEditOptions($session);
            return;
        }

        // Handle job type toggle
        if ($selectionId && str_starts_with($selectionId, 'type_')) {
            $categoryId = (int) str_replace('type_', '', $selectionId);
            $currentTypes = $this->getTempData($session, 'new_job_types', []);

            if (in_array($categoryId, $currentTypes)) {
                $currentTypes = array_values(array_diff($currentTypes, [$categoryId]));
            } else {
                $currentTypes[] = $categoryId;
            }

            $this->setTempData($session, 'new_job_types', $currentTypes);
            
            $count = count($currentTypes);
            $this->sendButtons(
                $session->phone,
                "✅ *{$count}* job types selected\n\nAdd more or tap Done:",
                [
                    ['id' => 'done_types', 'title' => '✅ Done'],
                    ['id' => 'show_types', 'title' => '➕ Add More'],
                ]
            );
            return;
        }

        $this->promptEditJobTypes($session);
    }

    protected function promptEditJobTypes(ConversationSession $session): void
    {
        $categories = JobCategory::where('is_active', true)
            ->orderBy('sort_order')
            ->take(9)
            ->get();

        $rows = $categories->map(fn($cat) => [
            'id' => 'type_' . $cat->id,
            'title' => mb_substr($cat->icon . ' ' . $cat->name_en, 0, 24),
        ])->toArray();

        $rows[] = ['id' => 'done_types', 'title' => '✅ Done'];

        $this->sendList(
            $session->phone,
            "💼 *Job Types select cheyyuka*\n\nMultiple select cheyyam:",
            'Select',
            [['title' => 'Job Types', 'rows' => $rows]]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Save & Helpers
    |--------------------------------------------------------------------------
    */

    protected function saveWorkerField(ConversationSession $session, string $field, $value): void
    {
        $worker = $this->getUser($session)?->jobWorker;

        if (!$worker) {
            $this->sendText($session->phone, "❌ Worker not found.");
            return;
        }

        try {
            $worker->update([$field => $value]);

            $this->sendButtons(
                $session->phone,
                "✅ *Updated!*",
                [
                    ['id' => 'view_profile', 'title' => '👤 Profile'],
                    ['id' => 'back_to_menu', 'title' => '⬅️ Menu'],
                ]
            );

            $this->clearTempData($session);
            $this->setStep($session, self::STEP_MENU);

        } catch (\Exception $e) {
            Log::error('Failed to update worker', ['error' => $e->getMessage()]);
            $this->sendText($session->phone, "❌ Update failed. Try again.");
        }
    }

    protected function toggleAvailability(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;

        if (!$worker) {
            return;
        }

        $newStatus = !$worker->is_available;
        $worker->update(['is_available' => $newStatus]);

        $statusText = $newStatus ? '🟢 *Online*' : '🔴 *Offline*';
        $nextAction = $newStatus ? 'Jobs vannaal ariyikkam!' : 'Job notifications off.';

        $this->sendButtons(
            $session->phone,
            "✅ Status: {$statusText}\n{$nextAction}",
            [
                ['id' => 'toggle_online', 'title' => $newStatus ? '🔴 Go Offline' : '🟢 Go Online'],
                ['id' => 'back_to_menu', 'title' => '⬅️ Menu'],
            ]
        );
    }

    protected function showEarnings(ConversationSession $session): void
    {
        $worker = $this->getUser($session)?->jobWorker;

        if (!$worker) {
            $this->showWorkerDashboard($session);
            return;
        }

        $weekEarnings = $this->getWeekEarnings($worker);
        $totalEarnings = number_format((float) ($worker->total_earnings ?? 0));
        $jobsCompleted = $worker->jobs_completed ?? 0;

        $message = "💰 *Earnings*\n\n" .
            "This week: *₹{$weekEarnings}*\n" .
            "Total: *₹{$totalEarnings}*\n" .
            "Jobs: *{$jobsCompleted}* completed";

        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'available_jobs', 'title' => '🔍 Find Jobs'],
                ['id' => 'back_to_menu', 'title' => '⬅️ Menu'],
            ]
        );
    }

    protected function formatJobTypes(array $typeIds): string
    {
        if (empty($typeIds)) {
            return 'All types';
        }

        $categories = JobCategory::whereIn('id', $typeIds)->pluck('name_en')->toArray();
        
        if (count($categories) > 2) {
            return count($categories) . ' types';
        }

        return implode(', ', $categories);
    }

    protected function formatVehicle(?string $type): string
    {
        return match ($type) {
            'two_wheeler' => '🛵 Two Wheeler',
            'four_wheeler' => '🚗 Four Wheeler',
            default => '🚶 Walking',
        };
    }

    protected function setStep(ConversationSession $session, string $step): void
    {
        $this->sessionManager->setFlowStep($session, FlowType::JOB_WORKER_MENU, $step);
    }

    protected function startFlow(ConversationSession $session, FlowType $flow): void
    {
        $this->sessionManager->setFlowStep($session, $flow, 'start');
    }
}