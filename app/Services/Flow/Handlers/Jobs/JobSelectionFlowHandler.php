<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\JobApplicationStatus;
use App\Enums\JobStatus;
use App\Models\ConversationSession;
use App\Models\JobApplication;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Models\User;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Jobs\JobApplicationService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\JobMessages;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler for job selection flow.
 *
 * Handles task givers reviewing applications and selecting workers.
 *
 * Flow Steps:
 * 1. VIEW_APPLICATIONS - Show list of all applicants
 * 2. VIEW_APPLICANT - Show single applicant details
 * 3. CONFIRM_SELECTION - Confirm worker selection
 * 4. SELECTION_COMPLETE - Worker selected confirmation
 *
 * Entry Points:
 * - Task giver receives new application notification
 * - Task giver clicks "View All Applications" (view_all_apps_X)
 * - Task giver clicks "Select This Worker" (select_worker_X)
 *
 * @srs-ref Section 3.5 - Worker Selection
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobSelectionFlowHandler extends AbstractFlowHandler
{
    /**
     * Flow step constants.
     */
    protected const STEP_VIEW_APPLICATIONS = 'view_applications';
    protected const STEP_VIEW_APPLICANT = 'view_applicant';
    protected const STEP_CONFIRM_SELECTION = 'confirm_selection';
    protected const STEP_SELECTION_COMPLETE = 'selection_complete';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected JobApplicationService $applicationService
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::JOB_SELECTION;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_VIEW_APPLICATIONS,
            self::STEP_VIEW_APPLICANT,
            self::STEP_CONFIRM_SELECTION,
            self::STEP_SELECTION_COMPLETE,
        ];
    }

    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            self::STEP_VIEW_APPLICATIONS => 'list',
            self::STEP_VIEW_APPLICANT => 'button',
            self::STEP_CONFIRM_SELECTION => 'button',
            self::STEP_SELECTION_COMPLETE => 'button',
            default => 'button',
        };
    }

    /**
     * Start the selection flow.
     */
    public function start(ConversationSession $session): void
    {
        $jobId = $this->getTemp($session, 'selection_job_id');

        if (!$jobId) {
            // Show all jobs with pending applications
            $this->showJobsWithApplications($session);
            return;
        }

        $this->showApplicationsList($session, $jobId);
    }

    /**
     * Start selection flow for a specific job.
     *
     * @param ConversationSession $session
     * @param int $jobId The job post ID
     */
    public function startWithJob(ConversationSession $session, int $jobId): void
    {
        $this->logInfo('Starting job selection flow', [
            'job_id' => $jobId,
            'phone' => $this->maskPhone($session->phone),
        ]);

        $job = JobPost::with(['category', 'applications.worker'])->find($jobId);

        if (!$job) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ Job not found.\n\nജോലി കണ്ടെത്താനായില്ല."
            );
            $this->goToMainMenu($session);
            return;
        }

        // Verify ownership
        $user = $this->getUser($session);
        if (!$user || $job->poster_user_id !== $user->id) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ You don't have permission to view this job's applications."
            );
            $this->goToMainMenu($session);
            return;
        }

        // Check if job is still accepting applications
        if ($job->status !== JobStatus::OPEN) {
            $this->sendTextWithMenu(
                $session->phone,
                "ℹ️ This job is already *{$job->status_display}*.\n\nNew applications cannot be reviewed."
            );
            $this->goToMainMenu($session);
            return;
        }

        // Store context
        $this->clearTemp($session);
        $this->setTemp($session, 'selection_job_id', $job->id);
        $this->setTemp($session, 'job_title', $job->title);

        // Set flow
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::JOB_SELECTION,
            self::STEP_VIEW_APPLICATIONS
        );

        $this->showApplicationsList($session, $jobId);
    }

    /**
     * Start with a specific application (from notification button).
     *
     * @param ConversationSession $session
     * @param int $applicationId The application ID to review
     */
    public function startWithApplication(ConversationSession $session, int $applicationId): void
    {
        $application = JobApplication::with(['worker', 'jobPost.category'])->find($applicationId);

        if (!$application) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ Application not found."
            );
            $this->goToMainMenu($session);
            return;
        }

        $job = $application->jobPost;

        // Verify ownership
        $user = $this->getUser($session);
        if (!$user || $job->poster_user_id !== $user->id) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ You don't have permission to view this application."
            );
            $this->goToMainMenu($session);
            return;
        }

        // Store context
        $this->clearTemp($session);
        $this->setTemp($session, 'selection_job_id', $job->id);
        $this->setTemp($session, 'current_application_id', $application->id);
        $this->setTemp($session, 'job_title', $job->title);

        // Set flow
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::JOB_SELECTION,
            self::STEP_VIEW_APPLICANT
        );

        $this->showApplicantDetails($session, $application);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle common navigation (menu, cancel, etc.)
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        // Handle selection-specific button clicks
        $selectionId = $this->getSelectionId($message);
        if ($this->handleSelectionButtonClick($selectionId, $session)) {
            return;
        }

        $step = $session->current_step;

        Log::debug('JobSelectionFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
            'selection_id' => $selectionId,
        ]);

        match ($step) {
            self::STEP_VIEW_APPLICATIONS => $this->handleViewApplications($message, $session),
            self::STEP_VIEW_APPLICANT => $this->handleViewApplicant($message, $session),
            self::STEP_CONFIRM_SELECTION => $this->handleConfirmSelection($message, $session),
            self::STEP_SELECTION_COMPLETE => $this->handleSelectionComplete($message, $session),
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
            self::STEP_VIEW_APPLICATIONS => $this->showApplicationsList($session, $this->getTemp($session, 'selection_job_id')),
            self::STEP_VIEW_APPLICANT => $this->promptViewApplicant($session),
            self::STEP_CONFIRM_SELECTION => $this->promptConfirmSelection($session),
            default => $this->start($session),
        };
    }

    /**
     * Handle selection-related button clicks from any context.
     */
    protected function handleSelectionButtonClick(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }

        // Handle "Select This Worker" button (select_worker_X)
        if (preg_match('/^select_worker_(\d+)$/', $selectionId, $matches)) {
            $applicationId = (int) $matches[1];
            $this->prepareSelection($session, $applicationId);
            return true;
        }

        // Handle "View All Applications" button (view_all_apps_X)
        if (preg_match('/^view_all_apps_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->startWithJob($session, $jobId);
            return true;
        }

        // Handle "Reject Application" button (reject_app_X)
        if (preg_match('/^reject_app_(\d+)$/', $selectionId, $matches)) {
            $applicationId = (int) $matches[1];
            $this->rejectApplication($session, $applicationId);
            return true;
        }

        // Handle "Call Worker" button (call_worker_X)
        if (preg_match('/^call_worker_(\d+)$/', $selectionId, $matches)) {
            $workerId = (int) $matches[1];
            $this->showWorkerContact($session, $workerId);
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Show Jobs with Applications
    |--------------------------------------------------------------------------
    */

    protected function showJobsWithApplications(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sendTextWithMenu($session->phone, "❌ Please log in first.");
            $this->goToMainMenu($session);
            return;
        }

        // Get jobs with pending applications
        $jobs = JobPost::where('poster_user_id', $user->id)
            ->where('status', JobStatus::OPEN)
            ->where('applications_count', '>', 0)
            ->with('category')
            ->orderBy('created_at', 'desc')
            ->take(10)
            ->get();

        if ($jobs->isEmpty()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "📋 *No Applications Yet*\n\n" .
                "You don't have any pending applications to review.\n" .
                "അപേക്ഷകൾ ഒന്നും ലഭിച്ചിട്ടില്ല.\n\n" .
                "_Workers will apply when they see your jobs!_",
                [['id' => 'my_posted_jobs', 'title' => '📂 My Posted Jobs']]
            );
            return;
        }

        $rows = $jobs->map(function ($job) {
            return [
                'id' => 'view_all_apps_' . $job->id,
                'title' => mb_substr($job->category->icon . ' ' . $job->title, 0, 24),
                'description' => "👥 {$job->applications_count} applicants • {$job->pay_display}",
            ];
        })->toArray();

        $rows[] = ['id' => 'main_menu', 'title' => '🏠 Menu', 'description' => 'Main Menu'];

        $this->sendList(
            $session->phone,
            "👥 *Jobs with Applications*\n\n" .
            "Select a job to review applicants:",
            'View Applicants',
            [['title' => 'Your Jobs', 'rows' => $rows]],
            '👥 Applications'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: View Applications List
    |--------------------------------------------------------------------------
    */

    protected function handleViewApplications(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle application selection from list
        if ($selectionId && str_starts_with($selectionId, 'select_worker_')) {
            $applicationId = (int) str_replace('select_worker_', '', $selectionId);
            $this->setTemp($session, 'current_application_id', $applicationId);
            $this->nextStep($session, self::STEP_VIEW_APPLICANT);
            $this->promptViewApplicant($session);
            return;
        }

        // Re-prompt
        $jobId = $this->getTemp($session, 'selection_job_id');
        $this->showApplicationsList($session, $jobId);
    }

    protected function showApplicationsList(ConversationSession $session, int $jobId): void
    {
        $job = JobPost::with(['category'])->find($jobId);

        if (!$job) {
            $this->start($session);
            return;
        }

        $applications = $this->applicationService->getPendingApplications($job);

        $response = JobMessages::showAllApplications($applications, $job);
        $this->sendJobMessage($session->phone, $response);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: View Single Applicant
    |--------------------------------------------------------------------------
    */

    protected function handleViewApplicant(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle select this worker
        if ($selectionId === 'select_this_worker' || $selectionId === 'select') {
            $this->nextStep($session, self::STEP_CONFIRM_SELECTION);
            $this->promptConfirmSelection($session);
            return;
        }

        // Handle see next applicant
        if ($selectionId === 'see_next' || $selectionId === 'next') {
            $this->showNextApplicant($session);
            return;
        }

        // Handle see previous applicant
        if ($selectionId === 'see_previous' || $selectionId === 'previous') {
            $this->showPreviousApplicant($session);
            return;
        }

        // Handle reject
        if ($selectionId === 'reject_this') {
            $applicationId = $this->getTemp($session, 'current_application_id');
            if ($applicationId) {
                $this->rejectApplication($session, $applicationId);
            }
            return;
        }

        // Handle back to list
        if ($selectionId === 'back_to_list') {
            $this->nextStep($session, self::STEP_VIEW_APPLICATIONS);
            $jobId = $this->getTemp($session, 'selection_job_id');
            $this->showApplicationsList($session, $jobId);
            return;
        }

        // Re-prompt
        $this->promptViewApplicant($session);
    }

    protected function promptViewApplicant(ConversationSession $session): void
    {
        $applicationId = $this->getTemp($session, 'current_application_id');
        $application = JobApplication::with(['worker.user', 'jobPost'])->find($applicationId);

        if (!$application) {
            $jobId = $this->getTemp($session, 'selection_job_id');
            $this->showApplicationsList($session, $jobId);
            return;
        }

        $this->showApplicantDetails($session, $application);
    }

    protected function showApplicantDetails(ConversationSession $session, JobApplication $application): void
    {
        $worker = $application->worker;
        $job = $application->jobPost;

        // Calculate distance if available
        $distance = null;
        if ($job->latitude && $job->longitude && $worker->latitude && $worker->longitude) {
            $distanceKm = $worker->getDistanceFrom($job->latitude, $job->longitude);
            $distance = $distanceKm < 1
                ? round($distanceKm * 1000) . 'm away'
                : round($distanceKm, 1) . ' km away';
        }

        // Build worker info message
        $ratingText = $worker->rating_count > 0
            ? "⭐ *{$worker->short_rating}*"
            : "🆕 *New Worker*";

        $vehicleText = $worker->has_vehicle
            ? "\n🚗 Vehicle: {$worker->vehicle_display}"
            : "";

        $distanceText = $distance
            ? "\n📍 Distance: {$distance}"
            : "";

        $messageText = $application->message
            ? "\n\n💬 *Worker's Message:*\n\"{$application->message}\""
            : "";

        $proposedText = $application->proposed_amount
            ? "\n💵 *Proposed Amount:* {$application->proposed_amount_display}"
            : "";

        $message = "👤 *APPLICANT DETAILS*\n" .
            "*അപേക്ഷകന്റെ വിവരങ്ങൾ*\n\n" .
            "👤 *{$worker->name}*\n\n" .
            "{$ratingText}\n" .
            "✅ Jobs Completed: {$worker->jobs_completed}" .
            $vehicleText .
            $distanceText .
            $proposedText .
            $messageText . "\n\n" .
            "📋 For: {$job->title}\n" .
            "💰 Posted Pay: {$job->pay_display}\n" .
            "⏰ Applied: {$application->time_since_applied}";

        // Send worker photo if available
        if ($worker->photo_url) {
            $this->sendImage(
                $session->phone,
                $worker->photo_url,
                "📸 {$worker->name}"
            );
        }

        // Get application position info
        $pendingCount = $this->applicationService->getPendingApplications($job)->count();
        $currentIndex = $this->getTemp($session, 'current_app_index', 1);

        $buttons = [
            ['id' => 'select_this_worker', 'title' => '✅ Select Worker'],
            ['id' => 'see_next', 'title' => '➡️ Next Applicant'],
            ['id' => 'reject_this', 'title' => '❌ Reject'],
        ];

        $this->sendButtons(
            $session->phone,
            $message,
            $buttons,
            "👤 Applicant {$currentIndex}/{$pendingCount}"
        );
    }

    protected function showNextApplicant(ConversationSession $session): void
    {
        $jobId = $this->getTemp($session, 'selection_job_id');
        $currentAppId = $this->getTemp($session, 'current_application_id');
        $job = JobPost::find($jobId);

        if (!$job) {
            $this->start($session);
            return;
        }

        $applications = $this->applicationService->getPendingApplications($job)->values();

        if ($applications->isEmpty()) {
            $this->sendTextWithMenu(
                $session->phone,
                "✅ No more pending applications."
            );
            $this->goToMainMenu($session);
            return;
        }

        // Find next application index
        $currentIndex = null;
        foreach ($applications as $index => $app) {
            if ($app->id === $currentAppId) {
                $currentIndex = (int) $index;
                break;
            }
        }

        $nextIndex = ($currentIndex !== null && $currentIndex < $applications->count() - 1)
            ? $currentIndex + 1
            : 0;

        $nextApplication = $applications->get($nextIndex);

        if (!$nextApplication) {
            $nextApplication = $applications->first();
        }

        $this->setTemp($session, 'current_application_id', $nextApplication->id);
        $this->setTemp($session, 'current_app_index', $nextIndex + 1);

        $this->showApplicantDetails($session, $nextApplication);
    }

    protected function showPreviousApplicant(ConversationSession $session): void
    {
        $jobId = $this->getTemp($session, 'selection_job_id');
        $currentAppId = $this->getTemp($session, 'current_application_id');
        $job = JobPost::find($jobId);

        if (!$job) {
            $this->start($session);
            return;
        }

        $applications = $this->applicationService->getPendingApplications($job)->values();

        if ($applications->isEmpty()) {
            $this->sendTextWithMenu(
                $session->phone,
                "✅ No more pending applications."
            );
            $this->goToMainMenu($session);
            return;
        }

        // Find previous application index
        $currentIndex = null;
        foreach ($applications as $index => $app) {
            if ($app->id === $currentAppId) {
                $currentIndex = (int) $index;
                break;
            }
        }

        $prevIndex = ($currentIndex !== null && $currentIndex > 0)
            ? $currentIndex - 1
            : $applications->count() - 1;

        $prevApplication = $applications->get($prevIndex);

        if (!$prevApplication) {
            $prevApplication = $applications->last();
        }

        $this->setTemp($session, 'current_application_id', $prevApplication->id);
        $this->setTemp($session, 'current_app_index', $prevIndex + 1);

        $this->showApplicantDetails($session, $prevApplication);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Confirm Selection
    |--------------------------------------------------------------------------
    */

    protected function handleConfirmSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle confirm
        if ($selectionId === 'confirm_select' || $selectionId === 'yes') {
            $this->executeSelection($session);
            return;
        }

        // Handle back
        if ($selectionId === 'back' || $selectionId === 'cancel') {
            $this->nextStep($session, self::STEP_VIEW_APPLICANT);
            $this->promptViewApplicant($session);
            return;
        }

        // Re-prompt
        $this->promptConfirmSelection($session);
    }

    protected function promptConfirmSelection(ConversationSession $session): void
    {
        $applicationId = $this->getTemp($session, 'current_application_id');
        $application = JobApplication::with(['worker', 'jobPost'])->find($applicationId);

        if (!$application) {
            $this->start($session);
            return;
        }

        $worker = $application->worker;
        $job = $application->jobPost;

        $finalAmount = $application->proposed_amount ?? $job->pay_amount;
        $amountDisplay = '₹' . number_format($finalAmount);

        $message = "✅ *Confirm Selection*\n" .
            "*തിരഞ്ഞെടുപ്പ് സ്ഥിരീകരിക്കുക*\n\n" .
            "📋 *Job:* {$job->title}\n" .
            "👤 *Worker:* {$worker->name}\n" .
            "⭐ Rating: {$worker->short_rating}\n" .
            "💰 *Amount:* {$amountDisplay}\n\n" .
            "After confirming:\n" .
            "• Worker will be notified\n" .
            "• Other applicants will be rejected\n" .
            "• You'll get worker's contact\n\n" .
            "Select this worker?";

        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'confirm_select', 'title' => '✅ Confirm'],
                ['id' => 'back', 'title' => '⬅️ Back'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            '✅ Confirm Selection'
        );
    }

    protected function prepareSelection(ConversationSession $session, int $applicationId): void
    {
        $application = JobApplication::with(['worker', 'jobPost'])->find($applicationId);

        if (!$application) {
            $this->sendTextWithMenu($session->phone, "❌ Application not found.");
            return;
        }

        // Store context
        $this->setTemp($session, 'selection_job_id', $application->job_post_id);
        $this->setTemp($session, 'current_application_id', $applicationId);
        $this->setTemp($session, 'job_title', $application->jobPost->title);

        // Set flow
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::JOB_SELECTION,
            self::STEP_CONFIRM_SELECTION
        );

        $this->promptConfirmSelection($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Execute Selection
    |--------------------------------------------------------------------------
    */

    protected function executeSelection(ConversationSession $session): void
    {
        $applicationId = $this->getTemp($session, 'current_application_id');
        $application = JobApplication::with(['worker.user', 'jobPost.category'])->find($applicationId);

        if (!$application) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Application not found. Please try again.",
                [['id' => 'retry', 'title' => '🔄 Try Again'], self::MENU_BUTTON]
            );
            return;
        }

        try {
            // Accept this application
            $this->applicationService->acceptApplication($application);

            $worker = $application->worker;
            $job = $application->jobPost;

            $this->logInfo('Worker selected for job', [
                'application_id' => $application->id,
                'job_id' => $job->id,
                'worker_id' => $worker->id,
            ]);

            // Move to complete step
            $this->nextStep($session, self::STEP_SELECTION_COMPLETE);

            // Send confirmation to task giver
            $response = JobMessages::workerSelected($worker, $job);
            $this->sendJobMessage($session->phone, $response);

            // Notify selected worker
            $this->notifySelectedWorker($application);

            // Notify rejected workers
            $this->notifyRejectedWorkers($job, $application);

            // Clear temp data
            $this->clearTemp($session);

        } catch (\Exception $e) {
            $this->logError('Failed to select worker', [
                'error' => $e->getMessage(),
                'application_id' => $applicationId,
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ *Selection failed*\n\n" . $e->getMessage(),
                [['id' => 'retry', 'title' => '🔄 Try Again'], self::MENU_BUTTON]
            );
        }
    }

    /**
     * Notify the selected worker.
     */
    protected function notifySelectedWorker(JobApplication $application): void
    {
        $worker = $application->worker;
        $job = $application->jobPost;

        if (!$worker->user || !$worker->user->phone) {
            return;
        }

        $response = JobMessages::youAreSelected($job);
        $this->sendJobMessage($worker->user->phone, $response);

        $this->logInfo('Selected worker notified', [
            'worker_id' => $worker->id,
            'job_id' => $job->id,
        ]);
    }

    /**
     * Notify rejected workers that position was filled.
     */
    protected function notifyRejectedWorkers(JobPost $job, JobApplication $exceptApplication): void
    {
        $rejectedApplications = JobApplication::where('job_post_id', $job->id)
            ->where('id', '!=', $exceptApplication->id)
            ->where('status', JobApplicationStatus::REJECTED)
            ->with('worker.user')
            ->get();

        foreach ($rejectedApplications as $application) {
            $worker = $application->worker;

            if (!$worker->user || !$worker->user->phone) {
                continue;
            }

            $response = JobMessages::positionFilled($job);
            $this->sendJobMessage($worker->user->phone, $response);
        }

        $this->logInfo('Rejected workers notified', [
            'job_id' => $job->id,
            'count' => $rejectedApplications->count(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Selection Complete
    |--------------------------------------------------------------------------
    */

    protected function handleSelectionComplete(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle view job
        if ($selectionId && str_starts_with($selectionId, 'view_job_')) {
            // TODO: Go to job detail view
            $this->goToMainMenu($session);
            return;
        }

        // Handle call worker
        if ($selectionId && str_starts_with($selectionId, 'call_worker_')) {
            $workerId = (int) str_replace('call_worker_', '', $selectionId);
            $this->showWorkerContact($session, $workerId);
            return;
        }

        // Default - go to main menu
        $this->goToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Reject Application
    |--------------------------------------------------------------------------
    */

    protected function rejectApplication(ConversationSession $session, int $applicationId): void
    {
        $application = JobApplication::with(['worker', 'jobPost'])->find($applicationId);

        if (!$application) {
            $this->sendTextWithMenu($session->phone, "❌ Application not found.");
            return;
        }

        try {
            $this->applicationService->rejectApplication($application);

            $this->sendTextWithMenu(
                $session->phone,
                "✅ Application rejected.\n\n" .
                "*{$application->worker->name}* has been notified."
            );

            // Notify rejected worker
            $worker = $application->worker;
            if ($worker->user && $worker->user->phone) {
                $this->sendButtons(
                    $worker->user->phone,
                    "❌ *Application Not Selected*\n\n" .
                    "Your application for *{$application->jobPost->title}* was not selected.\n\n" .
                    "Don't worry - there are more opportunities!",
                    [
                        ['id' => 'browse_jobs', 'title' => '🔍 Browse Jobs'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ]
                );
            }

            // Show next applicant or return to list
            $this->showNextApplicant($session);

        } catch (\Exception $e) {
            $this->logError('Failed to reject application', [
                'error' => $e->getMessage(),
                'application_id' => $applicationId,
            ]);

            $this->sendTextWithMenu(
                $session->phone,
                "❌ Failed to reject application: " . $e->getMessage()
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    protected function showWorkerContact(ConversationSession $session, int $workerId): void
    {
        $worker = JobWorker::with('user')->find($workerId);

        if (!$worker || !$worker->user) {
            $this->sendTextWithMenu($session->phone, "❌ Worker not found.");
            return;
        }

        $phone = $worker->user->formatted_phone ?? $worker->user->phone;

        $this->sendTextWithMenu(
            $session->phone,
            "📞 *Contact Worker*\n\n" .
            "👤 *{$worker->name}*\n" .
            "📱 {$phone}\n\n" .
            "_Tap the number to call._"
        );
    }

    /**
     * Send a JobMessages response via WhatsApp.
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