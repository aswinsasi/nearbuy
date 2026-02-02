<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\JobStatus;
use App\Models\ConversationSession;
use App\Models\JobApplication;
use App\Models\JobPost;
use App\Models\User;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Flow\FlowRouter;
use App\Services\Jobs\JobApplicationService;
use App\Services\Jobs\JobPostingService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\JobMessages;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Handler for job poster menu - view and manage posted jobs.
 *
 * Features:
 * - View all posted jobs with status
 * - Filter by status (Active, Completed, All)
 * - View job details and applications
 * - Accept/Reject applications
 * - Cancel/Edit jobs
 *
 * @srs-ref Section 3.3 - Job Poster Management
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobPosterMenuFlowHandler extends AbstractFlowHandler
{
    /**
     * Flow step constants.
     */
    protected const STEP_MENU = 'poster_menu';
    protected const STEP_VIEW_JOBS = 'view_jobs';
    protected const STEP_VIEW_JOB_DETAIL = 'view_job_detail';
    protected const STEP_VIEW_APPLICATIONS = 'view_applications';
    protected const STEP_VIEW_APPLICANT = 'view_applicant';
    protected const STEP_CONFIRM_CANCEL = 'confirm_cancel';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected JobPostingService $postingService,
        protected JobApplicationService $applicationService,
        protected FlowRouter $flowRouter
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::JOB_POSTER_MENU;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_MENU,
            self::STEP_VIEW_JOBS,
            self::STEP_VIEW_JOB_DETAIL,
            self::STEP_VIEW_APPLICATIONS,
            self::STEP_VIEW_APPLICANT,
            self::STEP_CONFIRM_CANCEL,
        ];
    }

    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            self::STEP_MENU => 'list',
            self::STEP_VIEW_JOBS => 'list',
            self::STEP_VIEW_JOB_DETAIL => 'button',
            self::STEP_VIEW_APPLICATIONS => 'list',
            self::STEP_VIEW_APPLICANT => 'button',
            self::STEP_CONFIRM_CANCEL => 'button',
            default => 'button',
        };
    }

    /**
     * Start the poster menu flow.
     */
    public function start(ConversationSession $session): void
    {
        // Check if we should auto-show applications for a specific job
        $viewAppJobId = $this->getTemp($session, 'view_applications_job_id');
        if ($viewAppJobId) {
            // Clear only the trigger key, keep other temp data
            $this->setTemp($session, 'view_applications_job_id', null);
            // Set up context for showing applications
            $this->setTemp($session, 'current_job_id', (int) $viewAppJobId);
            $this->nextStep($session, self::STEP_VIEW_APPLICATIONS);
            $this->showApplicationsList($session);
            return;
        }
        
        $this->logInfo('Starting job poster menu', [
            'phone' => $this->maskPhone($session->phone),
        ]);

            $this->clearTemp($session);
            $this->nextStep($session, self::STEP_MENU);
            $this->showPosterMenu($session);
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

        // Handle application button clicks from any step
        if ($this->handleApplicationActions($selectionId, $session)) {
            return;
        }

        $step = $session->current_step;

        Log::debug('JobPosterMenuFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
            'selection_id' => $selectionId,
        ]);

        match ($step) {
            self::STEP_MENU => $this->handleMenu($message, $session),
            self::STEP_VIEW_JOBS => $this->handleViewJobs($message, $session),
            self::STEP_VIEW_JOB_DETAIL => $this->handleViewJobDetail($message, $session),
            self::STEP_VIEW_APPLICATIONS => $this->handleViewApplications($message, $session),
            self::STEP_VIEW_APPLICANT => $this->handleViewApplicant($message, $session),
            self::STEP_CONFIRM_CANCEL => $this->handleConfirmCancel($message, $session),
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
            self::STEP_MENU => $this->showPosterMenu($session),
            self::STEP_VIEW_JOBS => $this->showJobsList($session),
            self::STEP_VIEW_JOB_DETAIL => $this->showJobDetail($session),
            self::STEP_VIEW_APPLICATIONS => $this->showApplicationsList($session),
            self::STEP_VIEW_APPLICANT => $this->showApplicantDetail($session),
            self::STEP_CONFIRM_CANCEL => $this->showCancelConfirmation($session),
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

        // Post new job
        if (in_array($selectionId, ['post_job', 'post_another_job', 'post_new_job'])) {
            $this->clearTemp($session);
            $this->flowRouter->startFlow($session, FlowType::JOB_POST);
            return true;
        }

        // Main menu
        if ($selectionId === 'main_menu') {
            $this->clearTemp($session);
            $this->flowRouter->goToMainMenu($session);
            return true;
        }

        return false;
    }

    /**
     * Handle application-related button clicks from any step.
     */
    /**
     * Handle application-related button clicks from any step.
     */
    protected function handleApplicationActions(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }

        // Accept application
        if (preg_match('/^accept_app_(\d+)$/', $selectionId, $matches)) {
            $applicationId = (int) $matches[1];
            $this->acceptApplication($session, $applicationId);
            return true;
        }

        // Reject application
        if (preg_match('/^reject_app_(\d+)$/', $selectionId, $matches)) {
            $applicationId = (int) $matches[1];
            $this->rejectApplication($session, $applicationId);
            return true;
        }

        // View applicant from list
        if (preg_match('/^view_applicant_(\d+)$/', $selectionId, $matches)) {
            $applicationId = (int) $matches[1];
            $this->setTemp($session, 'current_application_id', $applicationId);
            $this->nextStep($session, self::STEP_VIEW_APPLICANT);
            $this->showApplicantDetail($session);
            return true;
        }

        // ========== ADD THESE NEW HANDLERS ==========

        // Poster confirms work is done
        if (preg_match('/^confirm_work_done_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->handlePosterConfirmWorkDone($session, $jobId);
            return true;
        }

        // Payment method selection
        if (in_array($selectionId, ['pay_cash', 'pay_upi', 'pay_other'])) {
            $this->handlePaymentMethodSelection($session, $selectionId);
            return true;
        }

        // Rating selection
        if (preg_match('/^rate_(\d)$/', $selectionId, $matches)) {
            $rating = (int) $matches[1];
            $this->handleRatingSelection($session, $rating);
            return true;
        }

        // ========== END NEW HANDLERS ==========

        return false;
    }

    /**
     * Get status value as string (handles both enum and string).
     */
    protected function getStatusValue($status): string
    {
        // Handle any BackedEnum (JobStatus, JobApplicationStatus, etc.)
        if ($status instanceof \BackedEnum) {
            return $status->value;
        }
        return (string) $status;
    }

    /**
     * Get status icon for job.
     */
    protected function getStatusIcon($status): string
    {
        $statusValue = $this->getStatusValue($status);
        
        return match ($statusValue) {
            'open' => '🟢',
            'assigned' => '🔵',
            'in_progress' => '🟡',
            'completed' => '✅',
            'cancelled' => '❌',
            'expired' => '⏰',
            default => '📋',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Menu Step
    |--------------------------------------------------------------------------
    */

    protected function handleMenu(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        switch ($selectionId) {
            case 'view_active_jobs':
                $this->setTemp($session, 'filter', 'active');
                $this->nextStep($session, self::STEP_VIEW_JOBS);
                $this->showJobsList($session);
                break;

            case 'view_completed_jobs':
                $this->setTemp($session, 'filter', 'completed');
                $this->nextStep($session, self::STEP_VIEW_JOBS);
                $this->showJobsList($session);
                break;

            case 'view_all_jobs':
                $this->setTemp($session, 'filter', 'all');
                $this->nextStep($session, self::STEP_VIEW_JOBS);
                $this->showJobsList($session);
                break;

            default:
                $this->showPosterMenu($session);
        }
    }

    protected function showPosterMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sendTextWithMenu($session->phone, "❌ Please register first.");
            $this->goToMainMenu($session);
            return;
        }

        // Get job counts - handle enum status
        $activeCount = JobPost::where('poster_user_id', $user->id)
            ->whereIn('status', [JobStatus::OPEN, JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])
            ->count();

        $completedCount = JobPost::where('poster_user_id', $user->id)
            ->where('status', JobStatus::COMPLETED)
            ->count();

        // Get pending applications count
        $pendingApplications = JobApplication::where('status', 'pending')
            ->whereHas('jobPost', function ($query) use ($user) {
                $query->where('poster_user_id', $user->id)
                    ->where('status', JobStatus::OPEN);
            })->count();

        $response = "📋 *My Posted Jobs*\n*എന്റെ പോസ്റ്റ് ചെയ്ത ജോലികൾ*\n\n" .
            "🟢 Active Jobs: *{$activeCount}*\n" .
            "✅ Completed: *{$completedCount}*\n";

        if ($pendingApplications > 0) {
            $response .= "\n🔔 *{$pendingApplications} pending application(s)!*\n" .
                "*{$pendingApplications} അപേക്ഷകൾ കാത്തിരിക്കുന്നു!*\n";
        }

        $response .= "\nSelect an option below:";
        
        // Send menu with list options
        $this->whatsApp->sendList(
            $session->phone,
            $response,
            'Select Option',
            [[
                'title' => 'Job Options',
                'rows' => [
                    ['id' => 'view_active_jobs', 'title' => '🟢 Active Jobs', 'description' => "View open & assigned ({$activeCount})"],
                    ['id' => 'view_completed_jobs', 'title' => '✅ Completed', 'description' => "View completed ({$completedCount})"],
                    ['id' => 'view_all_jobs', 'title' => '📋 All Jobs', 'description' => 'View all posted jobs'],
                    ['id' => 'post_new_job', 'title' => '➕ Post New Job', 'description' => 'Create a new job posting'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'],
                ],
            ]],
            '📋 My Jobs'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | View Jobs List
    |--------------------------------------------------------------------------
    */

    protected function handleViewJobs(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle job selection
        if ($selectionId && preg_match('/^view_posted_job_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->setTemp($session, 'current_job_id', $jobId);
            $this->nextStep($session, self::STEP_VIEW_JOB_DETAIL);
            $this->showJobDetail($session);
            return;
        }

        // Handle filter change
        if (in_array($selectionId, ['filter_active', 'filter_completed', 'filter_all'])) {
            $filter = str_replace('filter_', '', $selectionId);
            $this->setTemp($session, 'filter', $filter);
            $this->showJobsList($session);
            return;
        }

        // Back to menu
        if ($selectionId === 'back_to_poster_menu') {
            $this->nextStep($session, self::STEP_MENU);
            $this->showPosterMenu($session);
            return;
        }

        $this->showJobsList($session);
    }

    protected function showJobsList(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $filter = $this->getTemp($session, 'filter', 'active');

        if (!$user) {
            $this->goToMainMenu($session);
            return;
        }

        $query = JobPost::where('poster_user_id', $user->id)
            ->with(['category', 'assignedWorker'])
            ->orderByDesc('created_at');

        // Apply filter - use enum values
        switch ($filter) {
            case 'active':
                $query->whereIn('status', [JobStatus::OPEN, JobStatus::ASSIGNED, JobStatus::IN_PROGRESS]);
                $filterLabel = 'Active';
                break;
            case 'completed':
                $query->where('status', JobStatus::COMPLETED);
                $filterLabel = 'Completed';
                break;
            default:
                $filterLabel = 'All';
        }

        // WhatsApp limit: max 10 items per section
        $jobs = $query->limit(10)->get();

        if ($jobs->isEmpty()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                "📭 *No {$filterLabel} Jobs*\n\n" .
                "You don't have any {$filterLabel} jobs yet.\n\n" .
                "Post a new job to find workers!",
                [
                    ['id' => 'post_new_job', 'title' => '➕ Post Job'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '📋 No Jobs'
            );
            return;
        }

        // Build job list rows
        $rows = [];
        foreach ($jobs as $job) {
            $statusIcon = $this->getStatusIcon($job->status);
            $statusValue = $this->getStatusValue($job->status);
            
            // Safe date formatting
            $dateStr = 'TBD';
            if ($job->job_date) {
                try {
                    $dateStr = $job->job_date->format('d M');
                } catch (\Exception $e) {
                    $dateStr = (string) $job->job_date;
                }
            }

            // Show application count for open jobs
            $appCount = '';
            if ($statusValue === 'open' && $job->applications_count > 0) {
                $appCount = " • 👥{$job->applications_count}";
            }
            
            $rows[] = [
                'id' => 'view_posted_job_' . $job->id,
                'title' => mb_substr($statusIcon . ' ' . $job->title, 0, 24),
                'description' => "₹" . number_format((float) ($job->pay_amount ?? 0)) . " | " . $dateStr . $appCount,
            ];
        }

        $this->whatsApp->sendList(
            $session->phone,
            "📋 *{$filterLabel} Jobs* ({$jobs->count()})\n\nSelect a job to view details:",
            'View Jobs',
            [['title' => "{$filterLabel} Jobs", 'rows' => $rows]],
            "📋 {$filterLabel} Jobs"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | View Job Detail
    |--------------------------------------------------------------------------
    */

    protected function handleViewJobDetail(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $jobId = $this->getTemp($session, 'current_job_id');

        if (!$jobId) {
            $this->nextStep($session, self::STEP_VIEW_JOBS);
            $this->showJobsList($session);
            return;
        }

        switch ($selectionId) {
            case 'view_applications':
                $this->nextStep($session, self::STEP_VIEW_APPLICATIONS);
                $this->showApplicationsList($session);
                break;

            case 'cancel_job':
                $this->nextStep($session, self::STEP_CONFIRM_CANCEL);
                $this->showCancelConfirmation($session);
                break;

            case 'back_to_jobs_list':
                $this->nextStep($session, self::STEP_VIEW_JOBS);
                $this->showJobsList($session);
                break;

            case 'repost_job':
                $this->repostJob($session, $jobId);
                break;

            default:
                $this->showJobDetail($session);
        }
    }

    protected function showJobDetail(ConversationSession $session): void
    {
        $jobId = $this->getTemp($session, 'current_job_id');
        $job = JobPost::with(['category', 'assignedWorker', 'applications.worker'])->find($jobId);

        if (!$job) {
            $this->sendTextWithMenu($session->phone, "❌ Job not found.");
            $this->nextStep($session, self::STEP_VIEW_JOBS);
            $this->showJobsList($session);
            return;
        }

        $statusIcon = $this->getStatusIcon($job->status);
        $statusValue = $this->getStatusValue($job->status);
        $categoryIcon = $job->category?->icon ?? '📋';

        $message = "📋 *JOB DETAILS*\n\n" .
            "{$categoryIcon} *{$job->title}*\n" .
            "📍 {$job->location_display}\n" .
            "📅 {$job->formatted_date_time}\n" .
            "⏱️ {$job->duration_display}\n" .
            "💰 {$job->pay_display}\n\n" .
            "{$statusIcon} *Status:* " . ucfirst($statusValue) . "\n";

        // Show applications info
        $appCount = $job->applications->count();
        $pendingCount = $job->applications->filter(function ($app) {
            return $this->getStatusValue($app->status) === 'pending';
        })->count();
        
        if ($appCount > 0) {
            $message .= "👥 *Applications:* {$appCount}";
            if ($pendingCount > 0) {
                $message .= " ({$pendingCount} pending)";
            }
            $message .= "\n";
        }

        // Show assigned worker if any
        if ($job->assignedWorker) {
            $workerName = $job->assignedWorker->name ?? 'Worker';
            $message .= "\n👷 *Assigned to:* {$workerName}\n";
        }

        if ($job->description) {
            $message .= "\n📝 *Description:*\n{$job->description}\n";
        }
        
        // Build action buttons based on job status
        $buttons = [];
        
        if ($statusValue === 'open') {
            if ($appCount > 0) {
                $buttons[] = ['id' => 'view_applications', 'title' => "👥 Applications ({$appCount})"];
            }
            $buttons[] = ['id' => 'cancel_job', 'title' => '❌ Cancel Job'];
        } elseif (in_array($statusValue, ['cancelled', 'expired'])) {
            $buttons[] = ['id' => 'repost_job', 'title' => '🔄 Repost'];
        }
        
        $buttons[] = ['id' => 'back_to_jobs_list', 'title' => '⬅️ Back'];
        
        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            array_slice($buttons, 0, 3), // WhatsApp max 3 buttons
            '📋 Job Details'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | View Applications
    |--------------------------------------------------------------------------
    */

    protected function handleViewApplications(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Back to job detail
        if ($selectionId === 'back_to_job_detail') {
            $this->nextStep($session, self::STEP_VIEW_JOB_DETAIL);
            $this->showJobDetail($session);
            return;
        }

        // Re-show list
        $this->showApplicationsList($session);
    }

    protected function showApplicationsList(ConversationSession $session): void
    {
        $jobId = $this->getTemp($session, 'current_job_id');
        $job = JobPost::with(['applications.worker', 'category'])->find($jobId);

        if (!$job) {
            $this->sendTextWithMenu($session->phone, "❌ Job not found.");
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
                    ['id' => 'back_to_job_detail', 'title' => '⬅️ Back'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                "📋 {$job->title}"
            );
            return;
        }

        // Build list of applicants
        $rows = [];
        foreach ($applications as $app) {
            $worker = $app->worker;
            $statusValue = $this->getStatusValue($app->status);
            
            $amount = $app->proposed_amount 
                ? '₹' . number_format((float) $app->proposed_amount)
                : '₹' . number_format((float) $job->pay_amount);
            
            $statusEmoji = match($statusValue) {
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
                'title' => mb_substr("{$statusEmoji} {$workerName}", 0, 24),
                'description' => mb_substr("{$rating} • {$amount}", 0, 72),
            ];
        }

        $count = $applications->count();
        $pendingCount = $applications->filter(function ($app) {
            return $this->getStatusValue($app->status) === 'pending';
        })->count();
        $categoryIcon = $job->category?->icon ?? '📋';

        $this->sendList(
            $session->phone,
            "👥 *Applications for:*\n{$categoryIcon} *{$job->title}*\n\n" .
            "Total: *{$count}* application(s)\n" .
            "Pending: *{$pendingCount}*\n\n" .
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
    }

    /*
    |--------------------------------------------------------------------------
    | View Applicant Detail
    |--------------------------------------------------------------------------
    */

    protected function handleViewApplicant(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Back to applications list
        if ($selectionId === 'back_to_applications') {
            $this->nextStep($session, self::STEP_VIEW_APPLICATIONS);
            $this->showApplicationsList($session);
            return;
        }

        // Re-show applicant detail
        $this->showApplicantDetail($session);
    }

    protected function showApplicantDetail(ConversationSession $session): void
    {
        $applicationId = $this->getTemp($session, 'current_application_id');
        $application = JobApplication::with(['worker', 'jobPost.category'])->find($applicationId);

        if (!$application) {
            $this->sendTextWithMenu($session->phone, "❌ Application not found.");
            $this->nextStep($session, self::STEP_VIEW_APPLICATIONS);
            $this->showApplicationsList($session);
            return;
        }

        $worker = $application->worker;
        $job = $application->jobPost;
        $statusValue = $this->getStatusValue($application->status);

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

        // Show accept/reject buttons only if pending
        if ($statusValue === 'pending') {
            $this->sendButtons(
                $session->phone,
                $message,
                [
                    ['id' => 'accept_app_' . $application->id, 'title' => '✅ Accept'],
                    ['id' => 'reject_app_' . $application->id, 'title' => '❌ Reject'],
                    ['id' => 'back_to_applications', 'title' => '⬅️ Back'],
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
                    ['id' => 'back_to_applications', 'title' => '⬅️ Back'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '👤 Applicant'
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Accept/Reject Applications
    |--------------------------------------------------------------------------
    */

    protected function acceptApplication(ConversationSession $session, int $applicationId): void
    {
        $application = JobApplication::with(['worker', 'jobPost'])->find($applicationId);

        if (!$application) {
            $this->sendTextWithMenu($session->phone, "❌ Application not found.");
            return;
        }

        $statusValue = $this->getStatusValue($application->status);

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
                "The worker has been notified.\n" .
                "പണിക്കാരനെ അറിയിച്ചു.\n\n" .
                "📞 Worker Phone: " . ($worker->user->phone ?? 'Will be shared'),
                [
                    ['id' => 'back_to_job_detail', 'title' => '📋 View Job'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '✅ Accepted'
            );

            // Notify worker
            $workerUser = $worker->user;
            if ($workerUser?->phone) {
                $this->whatsApp->sendButtons(
                    $workerUser->phone,
                    "🎉 *Good News!*\n*നല്ല വാർത്ത!*\n\n" .
                    "Your application for *{$job->title}* has been ACCEPTED!\n" .
                    "നിങ്ങളുടെ അപേക്ഷ സ്വീകരിച്ചു!\n\n" .
                    "📍 {$job->location_display}\n" .
                    "📅 {$job->formatted_date_time}\n" .
                    "💰 {$job->pay_display}\n\n" .
                    "Please contact the job poster to confirm details.\n" .
                    "📞 Poster Phone: " . ($job->poster->phone ?? 'Not available'),
                    [
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                    '🎉 Job Accepted!'
                );
            }

            $this->logInfo('Application accepted', [
                'application_id' => $applicationId,
                'job_id' => $job->id,
                'worker_id' => $worker->id,
            ]);

            // Stay on job detail
            $this->setTemp($session, 'current_job_id', $job->id);
            $this->nextStep($session, self::STEP_VIEW_JOB_DETAIL);

        } catch (\Exception $e) {
            $this->logError('Failed to accept application', [
                'error' => $e->getMessage(),
                'application_id' => $applicationId,
            ]);

            $this->sendTextWithMenu($session->phone, "❌ Failed to accept application: " . $e->getMessage());
        }
    }

    protected function rejectApplication(ConversationSession $session, int $applicationId): void
    {
        $application = JobApplication::with(['worker', 'jobPost'])->find($applicationId);

        if (!$application) {
            $this->sendTextWithMenu($session->phone, "❌ Application not found.");
            return;
        }

        $statusValue = $this->getStatusValue($application->status);

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
                    ['id' => 'back_to_applications', 'title' => '👥 View Others'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '❌ Rejected'
            );

            $this->logInfo('Application rejected', [
                'application_id' => $applicationId,
                'job_id' => $job->id,
                'worker_id' => $worker->id,
            ]);

            // Go back to applications list
            $this->nextStep($session, self::STEP_VIEW_APPLICATIONS);

        } catch (\Exception $e) {
            $this->logError('Failed to reject application', [
                'error' => $e->getMessage(),
                'application_id' => $applicationId,
            ]);

            $this->sendTextWithMenu($session->phone, "❌ Failed to reject application. Please try again.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cancel Job
    |--------------------------------------------------------------------------
    */

    protected function handleConfirmCancel(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $jobId = $this->getTemp($session, 'current_job_id');

        if ($selectionId === 'confirm_cancel_job') {
            $this->cancelJob($session, $jobId);
            return;
        }

        if ($selectionId === 'back_to_job_detail' || $selectionId === 'cancel') {
            $this->nextStep($session, self::STEP_VIEW_JOB_DETAIL);
            $this->showJobDetail($session);
            return;
        }

        $this->showCancelConfirmation($session);
    }

    protected function showCancelConfirmation(ConversationSession $session): void
    {
        $jobId = $this->getTemp($session, 'current_job_id');
        $job = JobPost::with('category')->find($jobId);

        if (!$job) {
            $this->nextStep($session, self::STEP_VIEW_JOBS);
            $this->showJobsList($session);
            return;
        }

        $categoryIcon = $job->category?->icon ?? '📋';

        $this->sendButtons(
            $session->phone,
            "⚠️ *Cancel Job?*\n\n" .
            "{$categoryIcon} *{$job->title}*\n" .
            "📍 {$job->location_display}\n" .
            "💰 {$job->pay_display}\n\n" .
            "Are you sure you want to cancel this job?\n\n" .
            "_This action cannot be undone._",
            [
                ['id' => 'confirm_cancel_job', 'title' => '❌ Yes, Cancel'],
                ['id' => 'back_to_job_detail', 'title' => '⬅️ No, Go Back'],
            ],
            '⚠️ Confirm Cancel'
        );
    }

    protected function cancelJob(ConversationSession $session, int $jobId): void
    {
        $job = JobPost::find($jobId);

        if (!$job) {
            $this->sendTextWithMenu($session->phone, "❌ Job not found.");
            return;
        }

        $statusValue = $this->getStatusValue($job->status);

        if (!in_array($statusValue, ['open', 'assigned'])) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ Cannot cancel this job. It may already be completed or in progress."
            );
            return;
        }

        try {
            // Update job status directly
            $job->update([
                'status' => JobStatus::CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => 'Cancelled by poster',
            ]);

            $categoryIcon = $job->category?->icon ?? '📋';
            
            $this->sendButtons(
                $session->phone,
                "✅ *Job Cancelled*\n\n" .
                "{$categoryIcon} {$job->title}\n\n" .
                "The job has been cancelled.",
                [
                    ['id' => 'post_new_job', 'title' => '➕ Post New Job'],
                    ['id' => 'view_all_jobs', 'title' => '📂 My Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );

            $this->nextStep($session, self::STEP_MENU);

        } catch (\Exception $e) {
            $this->logError('Failed to cancel job', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);

            $this->sendTextWithMenu(
                $session->phone,
                "❌ Failed to cancel job. Please try again."
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Repost Job
    |--------------------------------------------------------------------------
    */

    protected function repostJob(ConversationSession $session, int $jobId): void
    {
        $job = JobPost::with('category')->find($jobId);

        if (!$job) {
            $this->sendTextWithMenu($session->phone, "❌ Job not found.");
            return;
        }

        // Pre-fill job posting flow with this job's data
        $this->setTemp($session, 'category_id', $job->job_category_id);
        $this->setTemp($session, 'category_name', $job->category->display_name ?? 'Other');
        $this->setTemp($session, 'title', $job->title);
        $this->setTemp($session, 'description', $job->description);
        $this->setTemp($session, 'location_name', $job->location_name);
        $this->setTemp($session, 'latitude', $job->latitude);
        $this->setTemp($session, 'longitude', $job->longitude);
        $this->setTemp($session, 'duration_hours', $job->duration_hours);
        $this->setTemp($session, 'pay_amount', $job->pay_amount);
        $this->setTemp($session, 'special_instructions', $job->special_instructions);

        $this->flowRouter->startFlow($session, FlowType::JOB_POST);
    }

    /*
    |--------------------------------------------------------------------------
    | Job Completion & Payment Flow
    |--------------------------------------------------------------------------
    */

    /**
     * Handle poster confirming work is done.
     */
    protected function handlePosterConfirmWorkDone(ConversationSession $session, int $jobId): void
    {
        $job = JobPost::with(['verification', 'assignedWorker'])->find($jobId);

        if (!$job) {
            $this->sendTextWithMenu($session->phone, "❌ Job not found.");
            return;
        }

        if (!$job->verification) {
            $this->sendTextWithMenu($session->phone, "❌ No verification record found for this job.");
            return;
        }

        try {
            // Mark poster confirmed
            $job->verification->update(['poster_confirmed_at' => now()]);

            // Store job ID for payment flow
            $this->setTemp($session, 'payment_job_id', $jobId);

            // Ask for payment method
            $this->sendMessage($session, JobMessages::requestPaymentConfirmation($job));

            Log::info('Poster confirmed work done', [
                'job_id' => $jobId,
                'verification_id' => $job->verification->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to confirm work done', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            $this->sendTextWithMenu($session->phone, "❌ Failed to confirm. Please try again.");
        }
    }

    /**
     * Handle payment method selection.
     */
    protected function handlePaymentMethodSelection(ConversationSession $session, string $method): void
    {
        $jobId = $this->getTemp($session, 'payment_job_id');
        $job = JobPost::with(['verification', 'assignedWorker'])->find($jobId);

        if (!$job || !$job->verification) {
            $this->sendTextWithMenu($session->phone, "❌ Job not found.");
            return;
        }

        $paymentMethod = match($method) {
            'pay_cash' => 'cash',
            'pay_upi' => 'upi',
            'pay_other' => 'other',
            default => 'cash',
        };

        try {
            // Record payment confirmation
            $job->verification->update([
                'payment_method' => $paymentMethod,
                'payment_confirmed_at' => now(),
            ]);

            // Store for rating step
            $this->setTemp($session, 'payment_method', $paymentMethod);

            // Ask for rating
            $this->sendMessage($session, JobMessages::paymentConfirmed($job, $paymentMethod));

            Log::info('Payment confirmed', [
                'job_id' => $jobId,
                'method' => $paymentMethod,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to confirm payment', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            $this->sendTextWithMenu($session->phone, "❌ Failed to process payment. Please try again.");
        }
    }

    /**
     * Handle rating selection.
     */
    protected function handleRatingSelection(ConversationSession $session, int $rating): void
    {
        $jobId = $this->getTemp($session, 'payment_job_id');
        $job = JobPost::with(['verification', 'assignedWorker'])->find($jobId);

        if (!$job || !$job->verification) {
            $this->sendTextWithMenu($session->phone, "❌ Job not found.");
            return;
        }

        try {
            // Record rating
            $job->verification->update([
                'rating' => $rating,
                'rated_at' => now(),
            ]);

            // Mark job as completed
            $job->update(['status' => JobStatus::COMPLETED]);

            // Update worker stats
            $worker = $job->assignedWorker;
            if ($worker) {
                $worker->increment('jobs_completed');
                $worker->increment('total_earnings', $job->pay_amount ?? 0);
                
                // Recalculate rating
                $avgRating = $worker->verifications()
                    ->whereNotNull('rating')
                    ->avg('rating');
                
                if ($avgRating) {
                    $worker->update([
                        'rating' => round((float) $avgRating, 1),
                        'rating_count' => $worker->verifications()->whereNotNull('rating')->count(),
                    ]);
                }

                // Notify worker of completion and rating
                $workerPhone = $worker->user?->phone;
                if ($workerPhone) {
                    $stars = str_repeat('⭐', $rating);
                    $this->whatsApp->sendButtons(
                        $workerPhone,
                        "🎉 *Job Complete & Paid!*\n" .
                        "*ജോലി പൂർത്തിയാക്കി & പണം ലഭിച്ചു!*\n\n" .
                        "📋 *{$job->title}*\n" .
                        "💰 {$job->pay_display}\n" .
                        "Rating: {$stars}\n\n" .
                        "Great work! Keep it up! 💪",
                        [
                            ['id' => 'find_jobs', 'title' => '🔍 Find More Jobs'],
                            ['id' => 'main_menu', 'title' => '🏠 Menu'],
                        ],
                        '🎉 Paid!'
                    );
                }
            }

            // Send completion message to poster
            $this->sendMessage($session, JobMessages::jobFullyCompleted($job, $rating));

            // Clear temp data
            $this->clearTemp($session);

            Log::info('Job fully completed', [
                'job_id' => $jobId,
                'rating' => $rating,
                'worker_id' => $worker?->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to complete job', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
            ]);
            $this->sendTextWithMenu($session->phone, "❌ Failed to complete. Please try again.");
        }
    }

    /**
     * Send a message using JobMessages array format.
     */
    protected function sendMessage(ConversationSession $session, array $message): void
    {
        $type = $message['type'] ?? 'text';

        switch ($type) {
            case 'buttons':
                $this->whatsApp->sendButtons(
                    $session->phone,
                    $message['body'],
                    $message['buttons'],
                    $message['header'] ?? null
                );
                break;

            case 'list':
                $this->whatsApp->sendList(
                    $session->phone,
                    $message['body'],
                    $message['button'] ?? 'Select',
                    $message['sections'],
                    $message['header'] ?? null
                );
                break;

            default:
                $this->whatsApp->sendText($session->phone, $message['body'] ?? $message['text'] ?? '');
        }
    }
}