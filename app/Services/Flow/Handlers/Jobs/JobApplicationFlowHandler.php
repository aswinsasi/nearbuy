<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\JobApplicationStep;
use App\Enums\JobStatus;
use App\Models\ConversationSession;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Flow\FlowRouter;
use App\Services\Jobs\JobApplicationService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\JobMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for the job application flow.
 *
 * Handles workers viewing job details and applying to jobs.
 * Also handles job browsing when no specific job is selected.
 *
 * Flow Steps (from JobApplicationStep enum):
 * 1. VIEW_DETAILS - Show full job details
 * 2. ENTER_MESSAGE - Optional message to task giver
 * 3. PROPOSE_AMOUNT - Optional proposed amount (can be different from posted)
 * 4. CONFIRM_APPLICATION - Summary with Apply / Cancel buttons
 * 5. COMPLETE - Application sent confirmation
 *
 * Entry Points:
 * - Worker taps "I'm Interested" (apply_job_X) on job notification
 * - Worker taps "View Details" (view_job_detail_X) on job notification
 * - Worker selects job from browse list
 * - Worker browses jobs from menu (no job ID - shows list)
 *
 * @srs-ref Section 3.4 - Job Applications
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobApplicationFlowHandler extends AbstractFlowHandler
{
    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected JobApplicationService $applicationService,
        protected FlowRouter $flowRouter
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::JOB_APPLICATION;
    }

    protected function getSteps(): array
    {
        return JobApplicationStep::values();
    }

    protected function getExpectedInputType(string $step): string
    {
        $stepEnum = JobApplicationStep::tryFrom($step);
        return $stepEnum?->expectedInput() ?? 'text';
    }

    /**
     * Start the application flow.
     *
     * If no job ID is set, shows available jobs nearby (browse mode).
     * If job ID is set, starts the application flow for that job.
     */
    public function start(ConversationSession $session): void
    {
        // Check if we have a job ID in temp data
        $jobId = $this->getTemp($session, 'apply_job_id');

        if ($jobId) {
            $this->startWithJob($session, $jobId);
            return;
        }

        // No job ID - show browse mode (list of nearby jobs)
        $this->showNearbyJobs($session);
    }

    /**
     * Show nearby available jobs for browsing.
     */
    protected function showNearbyJobs(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $worker = $user?->jobWorker;

        // Get worker's location or use default
        $latitude = $worker?->latitude ?? $user?->latitude ?? null;
        $longitude = $worker?->longitude ?? $user?->longitude ?? null;

        // Fetch nearby open jobs
        $query = JobPost::with(['category', 'poster'])
            ->where('status', JobStatus::OPEN)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderBy('created_at', 'desc')
            ->limit(10);

        // If we have coordinates, order by distance
        if ($latitude && $longitude) {
            $query->selectRaw('*, (
                6371 * acos(
                    cos(radians(?)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians(?)) +
                    sin(radians(?)) * sin(radians(latitude))
                )
            ) AS distance', [$latitude, $longitude, $latitude])
                ->orderBy('distance');
        }

        $jobs = $query->get();

        if ($jobs->isEmpty()) {
            $this->sendButtons(
                $session->phone,
                "📭 *No Jobs Available*\n" .
                "*ഇപ്പോൾ ജോലികൾ ഇല്ല*\n\n" .
                "There are no open tasks near you right now.\n" .
                "നിങ്ങളുടെ അടുത്ത് ഇപ്പോൾ ജോലികൾ ഇല്ല.\n\n" .
                "Check back later or post your own task!",
                [
                    ['id' => 'job_post', 'title' => '📝 Post a Task'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '👷 Jobs'
            );
            return;
        }

        // Build job list
        $rows = [];
        foreach ($jobs as $job) {
            $distanceText = '';
            if (isset($job->distance)) {
                $distanceText = $job->distance < 1
                    ? round($job->distance * 1000) . 'm'
                    : round($job->distance, 1) . 'km';
                $distanceText = " • {$distanceText}";
            }

            $rows[] = [
                'id' => 'view_job_' . $job->id,
                'title' => mb_substr(($job->category?->icon ?? '📋') . ' ' . $job->title, 0, 24),
                'description' => mb_substr($job->pay_display . $distanceText . ' • ' . $job->formatted_date, 0, 72),
            ];
        }

        $jobCount = $jobs->count();
        $message = "🔍 *Available Jobs Nearby*\n" .
            "*അടുത്തുള്ള ജോലികൾ*\n\n" .
            "Found *{$jobCount}* tasks near you.\n" .
            "{$jobCount} ജോലികൾ കണ്ടെത്തി.\n\n" .
            "Select a job to see details and apply.";

        $this->sendList(
            $session->phone,
            $message,
            '📋 View Jobs',
            [
                [
                    'title' => '📋 Available Tasks',
                    'rows' => $rows,
                ],
            ],
            '👷 Jobs'
        );
    }

    /**
     * Start application flow for a specific job.
     *
     * Called from:
     * - FlowRouter when worker clicks apply_job_X or view_job_detail_X
     * - JobBrowseFlowHandler when worker selects a job
     *
     * @param ConversationSession $session
     * @param int $jobId The job post ID
     * @param bool $showDetailsFirst Whether to show details before apply prompt
     */
    public function startWithJob(ConversationSession $session, int $jobId, bool $showDetailsFirst = true): void
    {
        $this->logInfo('Starting job application flow', [
            'job_id' => $jobId,
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Get the job
        $job = JobPost::with(['category', 'poster'])->find($jobId);

        if (!$job) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ Job not found.\n\nജോലി കണ്ടെത്താനായില്ല."
            );
            $this->goToMainMenu($session);
            return;
        }

        // Check if job is still open
        if (!$job->accepts_applications) {
            $response = JobMessages::jobExpired();
            $this->sendJobMessage($session->phone, $response);
            $this->goToMainMenu($session);
            return;
        }

        // Get worker profile
        $worker = $this->getWorker($session);

        if (!$worker) {
            // User is not a registered worker
            $this->sendButtons(
                $session->phone,
                "👷 *Worker Registration Required*\n\n" .
                "You need to register as a worker to apply for jobs.\n" .
                "ജോലിക്ക് അപേക്ഷിക്കാൻ പണിക്കാരനായി രജിസ്റ്റർ ചെയ്യണം.\n\n" .
                "_It only takes 2 minutes!_",
                [
                    ['id' => 'start_worker_registration', 'title' => '✅ Register'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '👷 Registration'
            );
            return;
        }

        // Check if worker already applied
        if ($this->applicationService->hasWorkerApplied($worker, $job)) {
            $response = JobMessages::alreadyApplied();
            $this->sendJobMessage($session->phone, $response);
            return;
        }

        // Check if worker has an active job at conflicting time
        $activeJob = $this->applicationService->getWorkerActiveJob($worker);
        if ($activeJob && $this->hasTimeConflict($activeJob, $job)) {
            $response = JobMessages::workerBusy($activeJob);
            $this->sendJobMessage($session->phone, $response);
            return;
        }

        // Store job context
        $this->clearTemp($session);
        $this->setTemp($session, 'apply_job_id', $job->id);
        $this->setTemp($session, 'job_title', $job->title);
        $this->setTemp($session, 'job_pay', $job->pay_amount);

        // Set flow
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::JOB_APPLICATION,
            JobApplicationStep::VIEW_DETAILS->value
        );

        // Show job details
        $this->showJobDetails($session, $job, $worker);
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

        // Handle job-specific button clicks that might come from notifications
        $selectionId = $this->getSelectionId($message);
        if ($this->handleJobButtonClick($selectionId, $session)) {
            return;
        }

        $step = $session->current_step;

        Log::debug('JobApplicationFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
            'selection_id' => $selectionId,
        ]);

        match ($step) {
            'show_nearby' => $this->handleBrowseSelection($message, $session),
            JobApplicationStep::VIEW_DETAILS->value => $this->handleViewDetails($message, $session),
            JobApplicationStep::ENTER_MESSAGE->value => $this->handleEnterMessage($message, $session),
            JobApplicationStep::PROPOSE_AMOUNT->value => $this->handleProposeAmount($message, $session),
            JobApplicationStep::CONFIRM_APPLICATION->value => $this->handleConfirmApplication($message, $session),
            JobApplicationStep::COMPLETE->value => $this->handleComplete($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Handle browse job selection from list.
     */
    protected function handleBrowseSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle view_job_X selection from list
        if ($selectionId && preg_match('/^view_job_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->startWithJob($session, $jobId, true);
            return;
        }

        // Handle job_post button
        if ($selectionId === 'job_post') {
            $this->flowRouter->startFlow($session, FlowType::JOB_POST);
            return;
        }

        // Re-show job list
        $this->showNearbyJobs($session);
    }

    /**
     * Re-prompt the current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = $session->current_step;

        match ($step) {
            'show_nearby' => $this->showNearbyJobs($session),
            JobApplicationStep::VIEW_DETAILS->value => $this->promptViewDetails($session),
            JobApplicationStep::ENTER_MESSAGE->value => $this->promptEnterMessage($session),
            JobApplicationStep::PROPOSE_AMOUNT->value => $this->promptProposeAmount($session),
            JobApplicationStep::CONFIRM_APPLICATION->value => $this->promptConfirmApplication($session),
            default => $this->start($session),
        };
    }

    /**
     * Handle job-related button clicks from notifications.
     */
    protected function handleJobButtonClick(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }

        // Handle Worker Menu navigation
        if ($selectionId === 'job_worker_menu') {
            $this->clearTemp($session);
            $this->flowRouter->startFlow($session, FlowType::JOB_WORKER_MENU);
            return true;
        }

        // Handle Job Browse navigation
        if ($selectionId === 'job_browse' || $selectionId === 'browse_jobs' || $selectionId === 'find_jobs') {
            $this->clearTemp($session);
            $this->flowRouter->startFlow($session, FlowType::JOB_BROWSE);
            return true;
        }

        // Handle Job Poster Menu navigation
        if ($selectionId === 'job_poster_menu' || $selectionId === 'my_jobs') {
            $this->clearTemp($session);
            $this->flowRouter->startFlow($session, FlowType::JOB_POSTER_MENU);
            return true;
        }

        // Handle "Register as Worker" button
        if ($selectionId === 'start_worker_registration') {
            $this->flowRouter->startFlow($session, FlowType::JOB_WORKER_REGISTER);
            return true;
        }

        // Handle "I'm Interested" button (apply_job_X)
        if (preg_match('/^apply_job_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->startWithJob($session, $jobId, false);
            return true;
        }

        // Handle "View Details" button (view_job_detail_X or view_job_X)
        if (preg_match('/^view_job_(?:detail_)?(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->startWithJob($session, $jobId, true);
            return true;
        }

        // Handle "Skip Job" button (skip_job_X)
        if (preg_match('/^skip_job_(\d+)$/', $selectionId, $matches)) {
            $this->sendTextWithMenu(
                $session->phone,
                "✅ Job skipped. We'll notify you of other opportunities!\n\nജോലി ഒഴിവാക്കി."
            );
            return true;
        }

        // Handle "View All Applications" button (view_all_apps_X) - for job posters
        if (preg_match('/^view_all_apps_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->showJobApplications($session, $jobId);
            return true;
        }

        // Handle "View Applicant" selection (view_applicant_X) - for job posters
        if (preg_match('/^view_applicant_(\d+)$/', $selectionId, $matches)) {
            $applicationId = (int) $matches[1];
            $this->showApplicantDetails($session, $applicationId);
            return true;
        }

        // Handle "Accept Applicant" button (accept_app_X)
        if (preg_match('/^accept_app_(\d+)$/', $selectionId, $matches)) {
            $applicationId = (int) $matches[1];
            $this->acceptApplication($session, $applicationId);
            return true;
        }

        // Handle "Reject Applicant" button (reject_app_X)
        if (preg_match('/^reject_app_(\d+)$/', $selectionId, $matches)) {
            $applicationId = (int) $matches[1];
            $this->rejectApplication($session, $applicationId);
            return true;
        }

        return false;
    }

    /**
     * Show all applications for a job (for job poster).
     */
    protected function showJobApplications(ConversationSession $session, int $jobId): void
    {
        $user = $this->getUser($session);
        $job = JobPost::with(['applications.worker', 'category'])->find($jobId);

        if (!$job) {
            $this->sendTextWithMenu($session->phone, "❌ Job not found.");
            return;
        }

        // Verify user is the poster
        if ($job->poster_user_id !== $user?->id) {
            $this->sendTextWithMenu($session->phone, "❌ You can only view applications for your own jobs.");
            return;
        }

        $applications = $job->applications()
            ->with('worker')
            ->orderBy('created_at', 'desc')
            ->get();

        if ($applications->isEmpty()) {
            $this->sendButtons(
                $session->phone,
                "📭 *No Applications Yet*\n\n" .
                "No one has applied to your job yet.\n" .
                "ഇതുവരെ ആരും അപേക്ഷിച്ചിട്ടില്ല.\n\n" .
                "Workers nearby will be notified!",
                [
                    ['id' => 'my_jobs', 'title' => '📋 My Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                "📋 {$job->title}"
            );
            return;
        }

        // Build list of applicants
        $rows = [];
        foreach ($applications as $index => $app) {
            $worker = $app->worker;
            $statusValue = is_object($app->status) ? $app->status->value : $app->status;
            $amount = $app->proposed_amount 
                ? '₹' . number_format((float) $app->proposed_amount)
                : '₹' . number_format((float) $job->pay_amount);
            
            $status = match($statusValue) {
                'pending' => '🟡',
                'accepted' => '✅',
                'rejected' => '❌',
                'withdrawn' => '⬜',
                default => '🔵',
            };

            $workerName = $worker->name ?? 'Worker';
            $rating = $worker->rating ? "⭐{$worker->rating}" : '🆕';

            $rows[] = [
                'id' => 'view_applicant_' . $app->id,
                'title' => mb_substr("{$status} {$workerName}", 0, 24),
                'description' => mb_substr("{$rating} • {$amount}", 0, 72),
            ];
        }

        $count = $applications->count();
        $categoryIcon = $job->category?->icon ?? '📋';
        
        $this->sendList(
            $session->phone,
            "👥 *Applications for:*\n{$categoryIcon} *{$job->title}*\n\n" .
            "You have *{$count}* application(s).\n" .
            "{$count} അപേക്ഷകൾ ലഭിച്ചു.\n\n" .
            "Select an applicant to view details and accept/reject.",
            '👥 View Applicants',
            [
                [
                    'title' => '👥 Applicants',
                    'rows' => $rows,
                ],
            ],
            "📋 {$job->title}"
        );

        // Store job context for follow-up actions
        $this->setTemp($session, 'viewing_job_id', $jobId);
    }

    /**
     * Show details of a specific applicant (for job poster).
     */
    protected function showApplicantDetails(ConversationSession $session, int $applicationId): void
    {
        $user = $this->getUser($session);
        $application = \App\Models\JobApplication::with(['worker', 'jobPost.category'])->find($applicationId);

        if (!$application) {
            $this->sendTextWithMenu($session->phone, "❌ Application not found.");
            return;
        }

        // Verify user is the poster
        if ($application->jobPost->poster_user_id !== $user?->id) {
            $this->sendTextWithMenu($session->phone, "❌ You can only view applications for your own jobs.");
            return;
        }

        $worker = $application->worker;
        $job = $application->jobPost;
        $statusValue = is_object($application->status) ? $application->status->value : $application->status;

        $amount = $application->proposed_amount 
            ? '₹' . number_format((float) $application->proposed_amount) . ' (proposed)'
            : '₹' . number_format((float) $job->pay_amount);

        $messageText = $application->message 
            ? "\n\n✉️ *Message:*\n_{$application->message}_"
            : "";

        $rating = $worker->rating 
            ? "⭐ {$worker->rating}/5" 
            : "🆕 New worker";

        $completedJobs = $worker->jobs_completed ?? 0;

        $message = "👤 *APPLICANT DETAILS*\n\n" .
            "👷 *{$worker->name}*\n" .
            "{$rating}\n" .
            "✅ {$completedJobs} jobs completed\n\n" .
            "💰 *Amount:* {$amount}" .
            $messageText . "\n\n" .
            "📋 *For:* {$job->title}";

        // Send worker photo if available
        if ($worker->photo_url) {
            $this->sendImage($session->phone, $worker->photo_url, "📸 {$worker->name}");
        }

        // Show accept/reject buttons only if pending
        if ($statusValue === 'pending') {
            $this->sendButtons(
                $session->phone,
                $message,
                [
                    ['id' => 'accept_app_' . $application->id, 'title' => '✅ Accept'],
                    ['id' => 'reject_app_' . $application->id, 'title' => '❌ Reject'],
                    ['id' => 'view_all_apps_' . $job->id, 'title' => '👥 All Applicants'],
                ],
                '👤 Applicant'
            );
        } else {
            $statusText = match($statusValue) {
                'accepted' => '✅ ACCEPTED',
                'rejected' => '❌ REJECTED',
                'withdrawn' => '⬜ WITHDRAWN',
                default => strtoupper($statusValue),
            };

            $this->sendButtons(
                $session->phone,
                $message . "\n\n*Status:* {$statusText}",
                [
                    ['id' => 'view_all_apps_' . $job->id, 'title' => '👥 All Applicants'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '👤 Applicant'
            );
        }
    }

    /**
     * Accept an application (for job poster).
     */
    protected function acceptApplication(ConversationSession $session, int $applicationId): void
    {
        $user = $this->getUser($session);
        $application = \App\Models\JobApplication::with(['worker', 'jobPost'])->find($applicationId);

        if (!$application) {
            $this->sendTextWithMenu($session->phone, "❌ Application not found.");
            return;
        }

        // Verify user is the poster
        if ($application->jobPost->poster_user_id !== $user?->id) {
            $this->sendTextWithMenu($session->phone, "❌ You can only accept applications for your own jobs.");
            return;
        }

        $statusValue = is_object($application->status) ? $application->status->value : $application->status;

        if ($statusValue !== 'pending') {
            $this->sendTextWithMenu($session->phone, "❌ This application has already been processed.");
            return;
        }

        try {
            $this->applicationService->acceptApplication($application);

            $worker = $application->worker;
            $job = $application->jobPost;

            // Notify poster
            $this->sendButtons(
                $session->phone,
                "✅ *Worker Accepted!*\n*പണിക്കാരനെ സ്വീകരിച്ചു!*\n\n" .
                "You've accepted *{$worker->name}* for:\n" .
                "📋 {$job->title}\n\n" .
                "The worker has been notified and will contact you soon.\n\n" .
                "📞 Worker's phone: " . ($worker->user && $worker->user->phone ? $worker->user->phone : 'Will be shared'),
                [
                    ['id' => 'view_all_apps_' . $job->id, 'title' => '👥 View Others'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '✅ Accepted'
            );

            // Notify worker
            $workerUser = $worker->user;
            if ($workerUser?->phone) {
                $this->sendButtons(
                    $workerUser->phone,
                    "🎉 *Good News!*\n*നല്ല വാർത്ത!*\n\n" .
                    "Your application for *{$job->title}* has been ACCEPTED!\n" .
                    "നിങ്ങളുടെ അപേക്ഷ സ്വീകരിച്ചു!\n\n" .
                    "📍 {$job->location_display}\n" .
                    "📅 {$job->formatted_date_time}\n" .
                    "💰 {$job->pay_display}\n\n" .
                    "Please contact the task giver to confirm details.\n" .
                    "📞 Poster Phone: " . ($job->poster->phone ?? 'Not available'),
                    [
                        ['id' => 'view_job_' . $job->id, 'title' => '📋 View Job'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                    '🎉 Accepted!'
                );
            }

            Log::info('Application accepted', [
                'application_id' => $applicationId,
                'job_id' => $job->id,
                'worker_id' => $worker->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to accept application', [
                'error' => $e->getMessage(),
                'application_id' => $applicationId,
            ]);

            $this->sendTextWithMenu($session->phone, "❌ Failed to accept application: " . $e->getMessage());
        }
    }

    /**
     * Reject an application (for job poster).
     */
    protected function rejectApplication(ConversationSession $session, int $applicationId): void
    {
        $user = $this->getUser($session);
        $application = \App\Models\JobApplication::with(['worker', 'jobPost'])->find($applicationId);

        if (!$application) {
            $this->sendTextWithMenu($session->phone, "❌ Application not found.");
            return;
        }

        // Verify user is the poster
        if ($application->jobPost->poster_user_id !== $user?->id) {
            $this->sendTextWithMenu($session->phone, "❌ You can only reject applications for your own jobs.");
            return;
        }

        $statusValue = is_object($application->status) ? $application->status->value : $application->status;

        if ($statusValue !== 'pending') {
            $this->sendTextWithMenu($session->phone, "❌ This application has already been processed.");
            return;
        }

        try {
            $this->applicationService->rejectApplication($application);

            $worker = $application->worker;
            $job = $application->jobPost;

            // Notify poster
            $this->sendButtons(
                $session->phone,
                "❌ *Application Rejected*\n\n" .
                "You've rejected {$worker->name}'s application.\n\n" .
                "View other applicants or return to menu.",
                [
                    ['id' => 'view_all_apps_' . $job->id, 'title' => '👥 View Others'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '❌ Rejected'
            );

            // Optionally notify worker (some apps don't notify on rejection)
            // We'll skip this for now to avoid negative notifications

            Log::info('Application rejected', [
                'application_id' => $applicationId,
                'job_id' => $job->id,
                'worker_id' => $worker->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to reject application', [
                'error' => $e->getMessage(),
                'application_id' => $applicationId,
            ]);

            $this->sendTextWithMenu($session->phone, "❌ Failed to reject application. Please try again.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: View Details
    |--------------------------------------------------------------------------
    */

    protected function handleViewDetails(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle apply button
        if ($selectionId === 'apply_now' || $selectionId === 'interested') {
            $this->nextStep($session, JobApplicationStep::ENTER_MESSAGE->value);
            $this->promptEnterMessage($session);
            return;
        }

        // Handle get directions
        if ($selectionId && str_starts_with($selectionId, 'get_directions_')) {
            $this->sendJobLocation($session);
            return;
        }

        // Handle skip/not interested
        if ($selectionId === 'not_interested' || $selectionId === 'skip') {
            $this->clearTemp($session);
            $this->sendTextWithMenu(
                $session->phone,
                "✅ Okay, we'll show you other jobs!\n\nമറ്റ് ജോലികൾ കാണിക്കാം!"
            );
            $this->goToMainMenu($session);
            return;
        }

        // Re-prompt
        $this->promptViewDetails($session);
    }

    protected function promptViewDetails(ConversationSession $session): void
    {
        $jobId = $this->getTemp($session, 'apply_job_id');
        $job = JobPost::with(['category', 'poster'])->find($jobId);
        $worker = $this->getWorker($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        $this->showJobDetails($session, $job, $worker);
    }

    protected function showJobDetails(ConversationSession $session, JobPost $job, ?JobWorker $worker): void
    {
        // Calculate distance if both have coordinates
        $distanceKm = 0;
        if ($job->latitude && $job->longitude && $worker?->latitude && $worker?->longitude) {
            $distanceKm = $job->distanceFrom($worker->latitude, $worker->longitude) ?? 0;
        }

        // Build detailed job view
        $distance = $distanceKm < 1
            ? round($distanceKm * 1000) . 'm'
            : round($distanceKm, 1) . ' km';

        $applicationsText = $job->applications_count > 0
            ? "👥 *{$job->applications_count}* others applied"
            : "🎯 Be the first to apply!";

        $instructionsText = $job->special_instructions
            ? "\n\n📌 *Instructions:*\n_{$job->special_instructions}_"
            : "";

        $descriptionText = $job->description
            ? "\n\n📝 *Description:*\n{$job->description}"
            : "";

        $message = "📋 *JOB DETAILS*\n" .
            "*ജോലി വിവരങ്ങൾ*\n\n" .
            ($job->category?->icon ?? '📋') . " *{$job->title}*\n\n" .
            "📍 *Location:* {$job->location_display}\n" .
            "🗺️ Distance: {$distance} away\n" .
            "📅 *Date:* {$job->formatted_date_time}\n" .
            "⏱️ *Duration:* {$job->duration_display}\n" .
            "💰 *Payment:* *{$job->pay_display}*\n\n" .
            "👤 *Posted by:* {$job->poster->display_name}\n" .
            $applicationsText .
            $descriptionText .
            $instructionsText;

        $buttons = [
            ['id' => 'apply_now', 'title' => '✅ താൽപ്പര്യമുണ്ട്'],
            ['id' => 'not_interested', 'title' => '❌ താൽപ്പര്യമില്ല'],
        ];

        // Add directions button if coordinates available
        if ($job->latitude && $job->longitude) {
            $buttons = [
                ['id' => 'apply_now', 'title' => '✅ താൽപ്പര്യമുണ്ട്'],
                ['id' => 'get_directions_' . $job->id, 'title' => '📍 ദിശ കാണുക'],
                ['id' => 'not_interested', 'title' => '❌ ഒഴിവാക്കുക'],
            ];
        }

        $this->sendButtons(
            $session->phone,
            $message,
            $buttons,
            '📋 Job Details'
        );
    }

    protected function sendJobLocation(ConversationSession $session): void
    {
        $jobId = $this->getTemp($session, 'apply_job_id');
        $job = JobPost::find($jobId);

        if ($job && $job->latitude && $job->longitude) {
            $this->sendLocation(
                $session->phone,
                (float) $job->latitude,
                (float) $job->longitude,
                $job->title,
                $job->location_name
            );

            // Follow up with apply button
            $this->sendButtons(
                $session->phone,
                "📍 *Job Location*\n\nReady to apply?",
                [
                    ['id' => 'apply_now', 'title' => '✅ Apply Now'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Enter Message (Optional)
    |--------------------------------------------------------------------------
    */

    protected function handleEnterMessage(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        // Handle skip
        if ($selectionId === 'skip_message' || $this->isSkip($message)) {
            $this->setTemp($session, 'application_message', null);
            $this->nextStep($session, JobApplicationStep::PROPOSE_AMOUNT->value);
            $this->promptProposeAmount($session);
            return;
        }

        // Handle text message
        if ($text) {
            $messageText = trim($text);
            if (mb_strlen($messageText) > 300) {
                $messageText = mb_substr($messageText, 0, 300);
            }
            $this->setTemp($session, 'application_message', $messageText);

            $this->nextStep($session, JobApplicationStep::PROPOSE_AMOUNT->value);
            $this->promptProposeAmount($session);
            return;
        }

        // Re-prompt
        $this->promptEnterMessage($session);
    }

    protected function promptEnterMessage(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "✉️ *Add a Message (Optional)*\n\n" .
            "Want to add a message to the task giver?\n" .
            "ടാസ്ക് ഗൈവർക്ക് ഒരു സന്ദേശം ചേർക്കണോ?\n\n" .
            "_ഉദാ: \"I have experience with this type of work\"_\n" .
            "_ഉദാ: \"ഞാൻ ഈ ടൈപ്പ് ജോലിയിൽ പരിചയമുണ്ട്\"_\n\n" .
            "Send your message or tap Skip.",
            [
                ['id' => 'skip_message', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            '✉️ Message'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Propose Amount (Optional)
    |--------------------------------------------------------------------------
    */

    protected function handleProposeAmount(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        // Handle skip / accept posted amount
        if ($selectionId === 'skip_amount' || $selectionId === 'accept_posted' || $this->isSkip($message)) {
            $this->setTemp($session, 'proposed_amount', null);
            $this->nextStep($session, JobApplicationStep::CONFIRM_APPLICATION->value);
            $this->promptConfirmApplication($session);
            return;
        }

        // Handle text amount
        if ($text) {
            $amount = $this->parseAmount($text);
            if ($amount && $amount >= 50 && $amount <= 50000) {
                $this->setTemp($session, 'proposed_amount', $amount);
                $this->nextStep($session, JobApplicationStep::CONFIRM_APPLICATION->value);
                $this->promptConfirmApplication($session);
                return;
            }

            // Invalid amount
            $this->sendButtons(
                $session->phone,
                "❌ *Invalid amount*\n\nPlease enter a valid amount between ₹50 and ₹50,000.\n\nഅല്ലെങ്കിൽ Skip ചെയ്യുക.",
                [
                    ['id' => 'skip_amount', 'title' => '⏭️ Skip'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        // Re-prompt
        $this->promptProposeAmount($session);
    }

    protected function promptProposeAmount(ConversationSession $session): void
    {
        $postedPay = (float) $this->getTemp($session, 'job_pay', 0);
        $payDisplay = '₹' . number_format($postedPay);

        $this->sendButtons(
            $session->phone,
            "💰 *Propose Different Amount? (Optional)*\n\n" .
            "Posted pay: *{$payDisplay}*\n\n" .
            "Want to propose a different amount?\n" .
            "വേറെ തുക നിർദ്ദേശിക്കണോ?\n\n" .
            "_ഉദാ: 350, ₹400_\n\n" .
            "Or tap 'Accept Posted' to continue with {$payDisplay}.",
            [
                ['id' => 'accept_posted', 'title' => "✅ Accept {$payDisplay}"],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            '💰 Payment'
        );
    }

    /**
     * Parse amount from text.
     */
    protected function parseAmount(string $text): ?float
    {
        $cleaned = preg_replace('/[₹,Rs\.INR\s]/i', '', $text);

        if (is_numeric($cleaned)) {
            return round((float) $cleaned, 2);
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Confirm Application
    |--------------------------------------------------------------------------
    */

    protected function handleConfirmApplication(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle confirm
        if ($selectionId === 'confirm_apply' || $selectionId === 'send') {
            $this->submitApplication($session);
            return;
        }

        // Handle edit
        if ($selectionId === 'edit_application') {
            // Go back to message step
            $this->nextStep($session, JobApplicationStep::ENTER_MESSAGE->value);
            $this->promptEnterMessage($session);
            return;
        }

        // Handle cancel
        if ($selectionId === 'cancel_apply' || $selectionId === 'cancel') {
            $this->clearTemp($session);
            $this->sendTextWithMenu(
                $session->phone,
                "❌ *Application cancelled*\n\nഅപേക്ഷ റദ്ദാക്കി."
            );
            $this->goToMainMenu($session);
            return;
        }

        // Re-prompt
        $this->promptConfirmApplication($session);
    }

    protected function promptConfirmApplication(ConversationSession $session): void
    {
        $jobTitle = $this->getTemp($session, 'job_title', 'Job');
        $postedPay = (float) $this->getTemp($session, 'job_pay', 0);
        $applicationMessage = $this->getTemp($session, 'application_message');
        $proposedAmount = $this->getTemp($session, 'proposed_amount');

        $payDisplay = $proposedAmount
            ? '₹' . number_format((float) $proposedAmount) . ' (proposed)'
            : '₹' . number_format($postedPay);

        $messageDisplay = $applicationMessage ?: '(No message)';

        $message = "✅ *Confirm Application*\n" .
            "*അപേക്ഷ സ്ഥിരീകരിക്കുക*\n\n" .
            "📋 *Job:* {$jobTitle}\n" .
            "💰 *Amount:* {$payDisplay}\n" .
            "✉️ *Message:* {$messageDisplay}\n\n" .
            "Ready to apply?\n" .
            "അപേക്ഷിക്കാൻ തയ്യാറാണോ?";

        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'confirm_apply', 'title' => '✅ Apply Now'],
                ['id' => 'edit_application', 'title' => '✏️ Edit'],
                ['id' => 'cancel_apply', 'title' => '❌ Cancel'],
            ],
            '✅ Confirm'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Submit Application
    |--------------------------------------------------------------------------
    */

    protected function submitApplication(ConversationSession $session): void
    {
        $worker = $this->getWorker($session);
        $jobId = $this->getTemp($session, 'apply_job_id');
        $job = JobPost::with(['category', 'poster'])->find($jobId);

        if (!$worker || !$job) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Error submitting application. Please try again.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );
            return;
        }

        try {
            // Create application
            $application = $this->applicationService->applyToJob(
                $worker,
                $job,
                $this->getTemp($session, 'application_message'),
                $this->getTemp($session, 'proposed_amount')
            );

            // Get position
            $position = $this->applicationService->getApplicationPosition($application);

            $this->logInfo('Application submitted', [
                'application_id' => $application->id,
                'job_id' => $job->id,
                'worker_id' => $worker->id,
                'position' => $position,
            ]);

            // Clear temp data
            $this->clearTemp($session);

            // Move to complete step
            $this->nextStep($session, JobApplicationStep::COMPLETE->value);

            // Send confirmation to worker
            $response = JobMessages::applicationConfirmed($job, $position);
            $this->sendJobMessage($session->phone, $response);

            // Notify task giver
            $this->notifyPosterOfApplication($application);

        } catch (\Exception $e) {
            $this->logError('Failed to submit application', [
                'error' => $e->getMessage(),
                'job_id' => $jobId,
                'worker_id' => $worker->id,
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ *Application failed*\n\n" . $e->getMessage(),
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );
        }
    }

    /**
     * Notify task giver about new application.
     */
    protected function notifyPosterOfApplication(\App\Models\JobApplication $application): void
    {
        $poster = $application->jobPost->poster;

        if (!$poster || !$poster->phone) {
            return;
        }

        // Send notification
        $response = JobMessages::newApplicationNotification($application);
        $this->sendJobMessage($poster->phone, $response);

        // Send worker photo if available
        $worker = $application->worker;
        if ($worker->photo_url) {
            $this->sendImage(
                $poster->phone,
                $worker->photo_url,
                "📸 {$worker->name}'s profile photo"
            );
        }

        $this->logInfo('Poster notified of new application', [
            'application_id' => $application->id,
            'poster_phone' => $this->maskPhone($poster->phone),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 5: Complete
    |--------------------------------------------------------------------------
    */

    protected function handleComplete(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle browse more jobs
        if ($selectionId === 'job_browse' || $selectionId === 'browse_jobs') {
            $this->clearTemp($session);
            $this->flowRouter->startFlow($session, FlowType::JOB_BROWSE);
            return;
        }

        // Handle view applications
        if ($selectionId === 'my_applications') {
            // TODO: Go to my applications flow
            $this->flowRouter->goToMainMenu($session);
            return;
        }

        // Default - go to main menu
        $this->flowRouter->goToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get worker profile for this session.
     */
    protected function getWorker(ConversationSession $session): ?JobWorker
    {
        $user = $this->getUser($session);
        return $user?->jobWorker;
    }

    /**
     * Check if two jobs have a time conflict.
     */
    protected function hasTimeConflict(JobPost $existingJob, JobPost $newJob): bool
    {
        // Same day check
        if (!$existingJob->job_date->isSameDay($newJob->job_date)) {
            return false;
        }

        // If we don't have specific times, assume conflict on same day
        if (!$existingJob->job_time || !$newJob->job_time) {
            return true;
        }

        // TODO: Implement proper time overlap check
        // For now, flag same day as potential conflict
        return true;
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