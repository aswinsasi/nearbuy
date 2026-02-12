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
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Jobs\JobApplicationService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Handler for job selection flow.
 *
 * Task givers review and select workers. One-at-a-time viewing with Manglish.
 *
 * Flow (NP-018 to NP-021):
 * 1. Show first applicant: "👤 [Name] wants your job!"
 * 2. [✅ Select] [➡️ Next] [❌ Pass]
 * 3. If Select → Confirm → Notify worker (NP-020) → Reject others (NP-021)
 * 4. If Next → Show next applicant
 * 5. If Pass → Reject and show next
 *
 * Entry Points:
 * - select_worker_{app_id} - Direct from notification
 * - next_applicant_{job_id} - Show next applicant
 * - view_all_apps_{job_id} - Start review flow
 *
 * @srs-ref NP-018, NP-019, NP-020, NP-021
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobSelectionFlowHandler extends AbstractFlowHandler
{
    /**
     * Flow step constants.
     */
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
            self::STEP_VIEW_APPLICANT,
            self::STEP_CONFIRM_SELECTION,
            self::STEP_SELECTION_COMPLETE,
        ];
    }

    public function getExpectedInputType(string $step): string
    {
        return 'button';
    }

    /**
     * Start the selection flow (shows jobs with applications).
     */
    public function start(ConversationSession $session): void
    {
        $jobId = $this->getTempData($session, 'selection_job_id');

        if ($jobId) {
            $this->startReviewForJob($session, (int) $jobId);
            return;
        }

        // Show all jobs with pending applications
        $this->showJobsWithApplications($session);
    }

    /**
     * Start reviewing applications for a specific job.
     *
     * @srs-ref NP-018 - Show task giver ALL applications
     */
    public function startReviewForJob(ConversationSession $session, int $jobId): void
    {
        $this->logInfo('Starting selection flow for job', [
            'job_id' => $jobId,
            'phone' => $this->maskPhone($session->phone),
        ]);

        $job = JobPost::with(['category'])->find($jobId);

        if (!$job) {
            $this->sendJobNotFoundError($session);
            return;
        }

        // Verify ownership
        $user = $this->getUser($session);
        if (!$user || $job->poster_user_id !== $user->id) {
            $this->sendButtons(
                $session->phone,
                "❌ Ningalude job alla.",
                [['id' => 'main_menu', 'title' => '🏠 Menu']],
                '❌ Error'
            );
            return;
        }

        // Check job status
        if ($job->status !== JobStatus::OPEN) {
            $this->sendButtons(
                $session->phone,
                "ℹ️ Ee job *{$job->status_display}* aanu.\n\nNew applications review cheyyan pattilla.",
                [['id' => 'main_menu', 'title' => '🏠 Menu']],
                'ℹ️ Info'
            );
            return;
        }

        // Get first pending application
        $firstApplication = $this->applicationService->getFirstPendingApplication($job);

        if (!$firstApplication) {
            $this->sendNoApplicationsMessage($session, $job);
            return;
        }

        // Store context
        $this->clearTempData($session);
        $this->setTempData($session, 'selection_job_id', $job->id);
        $this->setTempData($session, 'job_title', $job->title);
        $this->setTempData($session, 'current_application_id', $firstApplication->id);
        $this->setTempData($session, 'current_position', 1);

        // Count total pending
        $totalPending = $this->applicationService->getPendingApplicationsCount($job);
        $this->setTempData($session, 'total_applications', $totalPending);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::JOB_SELECTION,
            self::STEP_VIEW_APPLICANT
        );

        // Show first applicant
        $this->showApplicantCard($session, $firstApplication, 1, $totalPending);
    }

    /**
     * Start with a specific application (from notification button).
     */
    public function startWithApplication(ConversationSession $session, int $applicationId): void
    {
        $application = JobApplication::with(['worker.user', 'jobPost.category'])->find($applicationId);

        if (!$application) {
            $this->sendButtons(
                $session->phone,
                "❌ Application kandethaan pattiyilla.",
                [['id' => 'main_menu', 'title' => '🏠 Menu']],
                '❌ Error'
            );
            return;
        }

        $job = $application->jobPost;

        // Verify ownership
        $user = $this->getUser($session);
        if (!$user || $job->poster_user_id !== $user->id) {
            $this->sendButtons(
                $session->phone,
                "❌ Ningalude job alla.",
                [['id' => 'main_menu', 'title' => '🏠 Menu']],
                '❌ Error'
            );
            return;
        }

        // Store context
        $this->clearTempData($session);
        $this->setTempData($session, 'selection_job_id', $job->id);
        $this->setTempData($session, 'job_title', $job->title);
        $this->setTempData($session, 'current_application_id', $application->id);

        $totalPending = $this->applicationService->getPendingApplicationsCount($job);
        $position = $this->applicationService->getApplicationPosition($application);

        $this->setTempData($session, 'total_applications', $totalPending);
        $this->setTempData($session, 'current_position', $position);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::JOB_SELECTION,
            self::STEP_VIEW_APPLICANT
        );

        // Show this applicant
        $this->showApplicantCard($session, $application, $position, $totalPending);
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
            self::STEP_VIEW_APPLICANT => $this->handleViewApplicant($message, $session),
            self::STEP_CONFIRM_SELECTION => $this->handleConfirmSelection($message, $session),
            self::STEP_SELECTION_COMPLETE => $this->handleSelectionComplete($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Re-prompt the current step.
     */
    public function promptCurrentStep(ConversationSession $session): void
    {
        $step = $session->current_step;

        match ($step) {
            self::STEP_VIEW_APPLICANT => $this->repromptApplicantCard($session),
            self::STEP_CONFIRM_SELECTION => $this->promptConfirmSelection($session),
            self::STEP_SELECTION_COMPLETE => $this->goToMenu($session),
            default => $this->start($session),
        };
    }

    /**
     * Handle selection-related button clicks.
     */
    protected function handleSelectionButtonClick(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }

        // Select worker: select_worker_{app_id}
        if (preg_match('/^select_worker_(\d+)$/', $selectionId, $matches)) {
            $applicationId = (int) $matches[1];
            $this->prepareSelection($session, $applicationId);
            return true;
        }

        // Next applicant: next_applicant_{job_id}
        if (preg_match('/^next_applicant_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->showNextApplicant($session, $jobId);
            return true;
        }

        // View all applications: view_all_apps_{job_id}
        if (preg_match('/^view_all_apps_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->startReviewForJob($session, $jobId);
            return true;
        }

        // Pass/reject: pass_applicant_{app_id}
        if (preg_match('/^pass_applicant_(\d+)$/', $selectionId, $matches)) {
            $applicationId = (int) $matches[1];
            $this->passApplicant($session, $applicationId);
            return true;
        }

        // Confirm selection: confirm_select_{app_id}
        if (preg_match('/^confirm_select_(\d+)$/', $selectionId, $matches)) {
            $applicationId = (int) $matches[1];
            $this->executeSelection($session, $applicationId);
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
            $this->sendButtons(
                $session->phone,
                "❌ Please login first.",
                [['id' => 'main_menu', 'title' => '🏠 Menu']],
                '❌ Error'
            );
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
            $this->sendButtons(
                $session->phone,
                "📋 *No Applications Yet*\n\n" .
                "Ningalude jobs-inu ippozhum applications vannittilla.\n" .
                "Workers apply cheyyumbol ariyikkaam! 🔔",
                [
                    ['id' => 'my_posted_jobs', 'title' => '📂 My Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '📋 Applications'
            );
            return;
        }

        $rows = [];
        foreach ($jobs as $job) {
            $icon = $job->category?->icon ?? '📋';
            $rows[] = [
                'id' => 'view_all_apps_' . $job->id,
                'title' => mb_substr("{$icon} {$job->title}", 0, 24),
                'description' => "👥 {$job->applications_count} applicants • {$job->pay_display}",
            ];
        }

        $this->sendList(
            $session->phone,
            "👥 *Jobs with Applications*\n\n" .
            "Applicants review cheyyaan job select cheyyuka:",
            '👥 View Applicants',
            [['title' => 'Your Jobs', 'rows' => $rows]],
            '👥 Applications'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | View Applicant (One-at-a-Time) - NP-018
    |--------------------------------------------------------------------------
    */

    protected function handleViewApplicant(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle select
        if ($selectionId === 'select_this') {
            $appId = (int) $this->getTempData($session, 'current_application_id');
            $this->prepareSelection($session, $appId);
            return;
        }

        // Handle next
        if ($selectionId === 'next_applicant') {
            $jobId = (int) $this->getTempData($session, 'selection_job_id');
            $this->showNextApplicant($session, $jobId);
            return;
        }

        // Handle pass/reject
        if ($selectionId === 'pass_this') {
            $appId = (int) $this->getTempData($session, 'current_application_id');
            $this->passApplicant($session, $appId);
            return;
        }

        // Re-prompt
        $this->repromptApplicantCard($session);
    }

    /**
     * Show applicant card (one-at-a-time viewing).
     *
     * @srs-ref NP-018 - Show: name, photo, rating, jobs done, distance, message
     */
    protected function showApplicantCard(
        ConversationSession $session,
        JobApplication $application,
        int $position,
        int $total
    ): void {
        $worker = $application->worker;
        $job = $application->jobPost;

        // Rating text
        $rating = $worker->rating ? "⭐{$worker->rating}" : '🆕 New';

        // Jobs completed
        $jobsCompleted = $worker->jobs_completed ?? 0;

        // Distance
        $distanceText = $application->distance_display ?? 'N/A';

        // Message
        $messageText = $application->message
            ? "\n💬 \"{$application->message}\""
            : "";

        // Build card (NP-018 format)
        $text = "👤 *{$worker->name}* wants your job!\n\n" .
            "{$rating} | {$jobsCompleted} jobs done | {$distanceText} away" .
            $messageText . "\n\n" .
            "📋 *{$job->title}*\n" .
            "💰 {$job->pay_display}";

        // Send worker photo if available
        if ($worker->photo_url) {
            $this->sendImage(
                $session->phone,
                $worker->photo_url,
                "📸 {$worker->name}"
            );
        }

        // Buttons (NP-019)
        $buttons = [
            ['id' => 'select_this', 'title' => '✅ Select'],
            ['id' => 'next_applicant', 'title' => '➡️ Next'],
            ['id' => 'pass_this', 'title' => '❌ Pass'],
        ];

        $header = "👤 Applicant {$position}/{$total}";

        $this->sendButtons(
            $session->phone,
            $text,
            $buttons,
            $header
        );
    }

    protected function repromptApplicantCard(ConversationSession $session): void
    {
        $appId = (int) $this->getTempData($session, 'current_application_id');
        $position = (int) $this->getTempData($session, 'current_position', 1);
        $total = (int) $this->getTempData($session, 'total_applications', 1);

        $application = JobApplication::with(['worker.user', 'jobPost'])->find($appId);

        if (!$application) {
            $jobId = (int) $this->getTempData($session, 'selection_job_id');
            $this->startReviewForJob($session, $jobId);
            return;
        }

        $this->showApplicantCard($session, $application, $position, $total);
    }

    /**
     * Show next applicant.
     */
    protected function showNextApplicant(ConversationSession $session, int $jobId): void
    {
        $job = JobPost::find($jobId);

        if (!$job) {
            $this->sendJobNotFoundError($session);
            return;
        }

        $currentAppId = (int) $this->getTempData($session, 'current_application_id');
        $currentPosition = (int) $this->getTempData($session, 'current_position', 1);

        // Get next pending application
        $nextApplication = $this->applicationService->getNextPendingApplication($job, $currentAppId);

        if (!$nextApplication) {
            // No more - wrap around to first
            $nextApplication = $this->applicationService->getFirstPendingApplication($job);

            if (!$nextApplication) {
                $this->sendButtons(
                    $session->phone,
                    "✅ *No More Applicants*\n\nElla applicants-um review cheythu!",
                    [
                        ['id' => 'view_all_apps_' . $jobId, 'title' => '🔄 Start Over'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                    '✅ Done'
                );
                return;
            }

            $currentPosition = 0; // Will be incremented to 1
        }

        $totalPending = $this->applicationService->getPendingApplicationsCount($job);
        $newPosition = ($currentPosition % $totalPending) + 1;

        // Update context
        $this->setTempData($session, 'current_application_id', $nextApplication->id);
        $this->setTempData($session, 'current_position', $newPosition);
        $this->setTempData($session, 'total_applications', $totalPending);

        $this->showApplicantCard($session, $nextApplication, $newPosition, $totalPending);
    }

    /**
     * Pass (reject) current applicant and show next.
     */
    protected function passApplicant(ConversationSession $session, int $applicationId): void
    {
        $application = JobApplication::with(['worker', 'jobPost'])->find($applicationId);

        if (!$application) {
            $this->repromptApplicantCard($session);
            return;
        }

        try {
            $this->applicationService->rejectApplication($application);

            $this->logInfo('Applicant passed/rejected', [
                'application_id' => $applicationId,
            ]);

            // Show next applicant
            $jobId = $application->job_post_id;
            $this->showNextApplicant($session, $jobId);

        } catch (\Exception $e) {
            $this->logError('Failed to pass applicant', [
                'error' => $e->getMessage(),
                'application_id' => $applicationId,
            ]);
            $this->repromptApplicantCard($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Confirm Selection - NP-019
    |--------------------------------------------------------------------------
    */

    protected function handleConfirmSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Confirm
        if ($selectionId === 'confirm_yes') {
            $appId = (int) $this->getTempData($session, 'current_application_id');
            $this->executeSelection($session, $appId);
            return;
        }

        // Cancel - go back to viewing
        if ($selectionId === 'confirm_no' || $selectionId === 'back') {
            $this->sessionManager->setFlowStep(
                $session,
                FlowType::JOB_SELECTION,
                self::STEP_VIEW_APPLICANT
            );
            $this->repromptApplicantCard($session);
            return;
        }

        // Re-prompt
        $this->promptConfirmSelection($session);
    }

    protected function prepareSelection(ConversationSession $session, int $applicationId): void
    {
        $application = JobApplication::with(['worker.user', 'jobPost'])->find($applicationId);

        if (!$application) {
            $this->sendButtons(
                $session->phone,
                "❌ Application kandethaan pattiyilla.",
                [['id' => 'main_menu', 'title' => '🏠 Menu']],
                '❌ Error'
            );
            return;
        }

        // Store context
        $this->setTempData($session, 'selection_job_id', $application->job_post_id);
        $this->setTempData($session, 'current_application_id', $applicationId);
        $this->setTempData($session, 'job_title', $application->jobPost->title);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::JOB_SELECTION,
            self::STEP_CONFIRM_SELECTION
        );

        $this->promptConfirmSelection($session);
    }

    protected function promptConfirmSelection(ConversationSession $session): void
    {
        $appId = (int) $this->getTempData($session, 'current_application_id');
        $application = JobApplication::with(['worker', 'jobPost'])->find($appId);

        if (!$application) {
            $this->start($session);
            return;
        }

        $worker = $application->worker;
        $job = $application->jobPost;

        $rating = $worker->rating ? "⭐{$worker->rating}" : '🆕 New';

        $text = "✅ *Confirm Selection*\n" .
            "*Thiranjeduppu sthirIkkarikkyuka*\n\n" .
            "📋 *Job:* {$job->title}\n" .
            "👤 *Worker:* {$worker->name}\n" .
            "{$rating} | {$worker->jobs_completed} jobs done\n\n" .
            "Select cheyyumbol:\n" .
            "• Worker-nu notify aakkum\n" .
            "• Matte applicants reject aakkum\n" .
            "• Worker-nte contact kittum\n\n" .
            "*{$worker->name}*-ne select cheyyano?";

        $this->sendButtons(
            $session->phone,
            $text,
            [
                ['id' => 'confirm_yes', 'title' => '✅ Yes, Select'],
                ['id' => 'confirm_no', 'title' => '⬅️ Back'],
            ],
            '✅ Confirm'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Execute Selection - NP-019, NP-020, NP-021
    |--------------------------------------------------------------------------
    */

    protected function executeSelection(ConversationSession $session, int $applicationId): void
    {
        $application = JobApplication::with(['worker.user', 'jobPost.category'])->find($applicationId);

        if (!$application) {
            $this->sendButtons(
                $session->phone,
                "❌ Application kandethaan pattiyilla.",
                [['id' => 'main_menu', 'title' => '🏠 Menu']],
                '❌ Error'
            );
            return;
        }

        try {
            // Accept this application (also rejects others)
            $this->applicationService->acceptApplication($application);

            $worker = $application->worker;
            $job = $application->jobPost;

            $this->logInfo('Worker selected for job', [
                'application_id' => $application->id,
                'job_id' => $job->id,
                'worker_id' => $worker->id,
            ]);

            // Set step to complete
            $this->sessionManager->setFlowStep(
                $session,
                FlowType::JOB_SELECTION,
                self::STEP_SELECTION_COMPLETE
            );

            // Send confirmation to task giver (NP-019, NP-020)
            $this->sendSelectionConfirmation($session, $worker, $job);

            // Notify selected worker (NP-020)
            $this->notifySelectedWorker($application);

            // Notify rejected workers (NP-021)
            $this->notifyRejectedWorkers($job, $application);

            // Clear temp
            $this->clearTempData($session);

        } catch (\Exception $e) {
            $this->logError('Failed to select worker', [
                'error' => $e->getMessage(),
                'application_id' => $applicationId,
            ]);

            $this->sendButtons(
                $session->phone,
                "❌ *Selection Failed*\n\n" . $e->getMessage(),
                [
                    ['id' => 'view_all_apps_' . $application->job_post_id, 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '❌ Error'
            );
        }
    }

    /**
     * Send selection confirmation to task giver.
     *
     * @srs-ref NP-019 - Task giver selects ONE worker
     * @srs-ref NP-020 - Includes worker contact
     */
    protected function sendSelectionConfirmation(
        ConversationSession $session,
        JobWorker $worker,
        JobPost $job
    ): void {
        $workerPhone = $worker->user?->phone ?? 'N/A';

        // Format: NP-020
        $text = "✅ *{$worker->name} selected!*\n\n" .
            "📞 Contact: {$workerPhone}\n\n" .
            "Worker-nu ariyichittund,\n" .
            "they'll be there! 🎉\n\n" .
            "📋 {$job->title}\n" .
            "📍 {$job->location_display}";

        $this->sendButtons(
            $session->phone,
            $text,
            [
                ['id' => 'my_posted_jobs', 'title' => '📂 My Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            '✅ Worker Selected!'
        );
    }

    /**
     * Notify the selected worker.
     *
     * @srs-ref NP-020 - Notify selected worker with confirmation + task giver contact
     */
    protected function notifySelectedWorker(JobApplication $application): void
    {
        $worker = $application->worker;
        $job = $application->jobPost;
        $poster = $job->poster;

        $workerUser = $worker->user;
        if (!$workerUser || !$workerUser->phone) {
            return;
        }

        $posterPhone = $poster?->phone ?? 'N/A';
        $posterName = $poster?->name ?? 'Task Giver';

        // Format: NP-020
        $text = "🎉 *Congratulations!*\n" .
            "*Abhivadyangal!*\n\n" .
            "Ningalude application *ACCEPTED* aayii!\n\n" .
            "📋 *{$job->title}*\n" .
            "📍 {$job->location_display}\n" .
            "📅 {$job->formatted_date} ⏰ {$job->formatted_time}\n" .
            "💰 {$job->pay_display}\n\n" .
            "👤 Poster: {$posterName}\n" .
            "📞 Contact: {$posterPhone}\n\n" .
            "Please contact poster to confirm details! 👍";

        $this->sendButtons(
            $workerUser->phone,
            $text,
            [
                ['id' => 'view_job_' . $job->id, 'title' => '📋 Job Details'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            '🎉 Selected!'
        );

        $this->logInfo('Selected worker notified', [
            'worker_id' => $worker->id,
            'job_id' => $job->id,
        ]);
    }

    /**
     * Notify rejected workers that position was filled.
     *
     * @srs-ref NP-021 - Notify REJECTED workers that position filled
     */
    protected function notifyRejectedWorkers(JobPost $job, JobApplication $exceptApplication): void
    {
        $rejectedApplications = $this->applicationService
            ->getRejectedApplicationsForNotification($job, $exceptApplication);

        foreach ($rejectedApplications as $application) {
            $worker = $application->worker;
            $workerUser = $worker?->user;

            if (!$workerUser || !$workerUser->phone) {
                continue;
            }

            // Format: NP-021
            $text = "ℹ️ *{$job->title}* position filled aayittund.\n\n" .
                "Next time try cheyyuka! 💪\n\n" .
                "Vere jobs nokkaam?";

            $this->sendButtons(
                $workerUser->phone,
                $text,
                [
                    ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                'ℹ️ Position Filled'
            );
        }

        $this->logInfo('Rejected workers notified', [
            'job_id' => $job->id,
            'count' => $rejectedApplications->count(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Selection Complete
    |--------------------------------------------------------------------------
    */

    protected function handleSelectionComplete(IncomingMessage $message, ConversationSession $session): void
    {
        $this->goToMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function sendJobNotFoundError(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "❌ *Job Not Found*\n\nEe job kandethaan pattiyilla.",
            [['id' => 'main_menu', 'title' => '🏠 Menu']],
            '❌ Error'
        );
        $this->goToMenu($session);
    }

    protected function sendNoApplicationsMessage(ConversationSession $session, JobPost $job): void
    {
        $icon = $job->category?->icon ?? '📋';

        $this->sendButtons(
            $session->phone,
            "📭 *No Applications Yet*\n\n" .
            "{$icon} *{$job->title}*\n\n" .
            "Ippozhum aarum apply cheythittilla.\n" .
            "Workers nearby-inu notify cheythittund! 🔔",
            [
                ['id' => 'my_posted_jobs', 'title' => '📂 My Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            '📭 No Applicants'
        );
    }
}