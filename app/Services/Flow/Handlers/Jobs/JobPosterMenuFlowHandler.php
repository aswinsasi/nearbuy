<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Models\ConversationSession;
use App\Models\JobApplication;
use App\Models\JobPost;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Jobs\JobApplicationService;
use App\Services\Jobs\JobExecutionService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Handler for job poster menu - simplified format.
 *
 * Menu format:
 * "📋 My Jobs:
 *  [➕ Post New Job]
 *  [📋 Active Jobs ([X])]
 *  [✅ Completed Jobs]
 *  [👷 Favourite Workers]"
 *
 * @srs-ref Section 3.3 - Job Poster Management
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobPosterMenuFlowHandler extends AbstractFlowHandler
{
    protected const STEP_MENU = 'poster_menu';
    protected const STEP_VIEW_JOBS = 'view_jobs';
    protected const STEP_VIEW_JOB = 'view_job';
    protected const STEP_VIEW_APPLICATIONS = 'view_applications';
    protected const STEP_VIEW_APPLICANT = 'view_applicant';
    protected const STEP_CONFIRM_CANCEL = 'confirm_cancel';
    protected const STEP_PAYMENT = 'payment';
    protected const STEP_RATING = 'rating';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected JobApplicationService $applicationService,
        protected JobExecutionService $executionService
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
            self::STEP_VIEW_JOB,
            self::STEP_VIEW_APPLICATIONS,
            self::STEP_VIEW_APPLICANT,
            self::STEP_CONFIRM_CANCEL,
            self::STEP_PAYMENT,
            self::STEP_RATING,
        ];
    }

    public function getExpectedInputType(string $step): string
    {
        return 'button';
    }

    /**
     * Start the poster menu flow.
     */
    public function start(ConversationSession $session): void
    {
        // Check if should auto-show applications
        $viewAppJobId = $this->getTempData($session, 'view_applications_job_id');
        if ($viewAppJobId) {
            $this->setTempData($session, 'view_applications_job_id', null);
            $this->setTempData($session, 'job_id', (int) $viewAppJobId);
            $this->setStep($session, self::STEP_VIEW_APPLICATIONS);
            $this->showApplicationsList($session);
            return;
        }

        $this->clearTempData($session);
        $this->setStep($session, self::STEP_MENU);
        $this->showPosterMenu($session);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle button clicks first
        if ($this->handleButtonClick($selectionId, $session)) {
            return;
        }

        $step = $session->current_step;

        match ($step) {
            self::STEP_MENU => $this->handleMenu($message, $session),
            self::STEP_VIEW_JOBS => $this->handleViewJobs($message, $session),
            self::STEP_VIEW_JOB => $this->handleViewJob($message, $session),
            self::STEP_VIEW_APPLICATIONS => $this->handleViewApplications($message, $session),
            self::STEP_VIEW_APPLICANT => $this->handleViewApplicant($message, $session),
            self::STEP_CONFIRM_CANCEL => $this->handleConfirmCancel($message, $session),
            self::STEP_PAYMENT => $this->handlePayment($message, $session),
            self::STEP_RATING => $this->handleRating($message, $session),
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
            self::STEP_MENU => $this->showPosterMenu($session),
            self::STEP_VIEW_JOBS => $this->showJobsList($session),
            self::STEP_VIEW_JOB => $this->showJobDetail($session),
            self::STEP_VIEW_APPLICATIONS => $this->showApplicationsList($session),
            self::STEP_VIEW_APPLICANT => $this->showApplicantDetail($session),
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

        // Navigation
        if ($selectionId === 'post_job' || $selectionId === 'post_new_job') {
            $this->clearTempData($session);
            $this->startFlow($session, FlowType::JOB_POST);
            return true;
        }

        if ($selectionId === 'main_menu' || $selectionId === 'menu') {
            $this->goToMenu($session);
            return true;
        }

        // Accept/Reject applications
        if (preg_match('/^accept_(\d+)$/', $selectionId, $m)) {
            $this->acceptApplication($session, (int) $m[1]);
            return true;
        }

        if (preg_match('/^reject_(\d+)$/', $selectionId, $m)) {
            $this->rejectApplication($session, (int) $m[1]);
            return true;
        }

        // View applicant
        if (preg_match('/^applicant_(\d+)$/', $selectionId, $m)) {
            $this->setTempData($session, 'app_id', (int) $m[1]);
            $this->setStep($session, self::STEP_VIEW_APPLICANT);
            $this->showApplicantDetail($session);
            return true;
        }

        // View job
        if (preg_match('/^job_(\d+)$/', $selectionId, $m)) {
            $this->setTempData($session, 'job_id', (int) $m[1]);
            $this->setStep($session, self::STEP_VIEW_JOB);
            $this->showJobDetail($session);
            return true;
        }

        // Payment
        if (preg_match('/^pay_(cash|upi)_(\d+)$/', $selectionId, $m)) {
            $this->processPayment($session, $m[1], (int) $m[2]);
            return true;
        }

        // Rating
        if (preg_match('/^rate_(\d)_(\d+)$/', $selectionId, $m)) {
            $this->processRating($session, (int) $m[1], (int) $m[2]);
            return true;
        }

        // Confirm work done
        if (preg_match('/^work_done_(\d+)$/', $selectionId, $m)) {
            $this->confirmWorkDone($session, (int) $m[1]);
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Poster Menu
    |--------------------------------------------------------------------------
    */

    protected function handleMenu(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        switch ($selectionId) {
            case 'active_jobs':
                $this->setTempData($session, 'filter', 'active');
                $this->setStep($session, self::STEP_VIEW_JOBS);
                $this->showJobsList($session);
                break;

            case 'completed_jobs':
                $this->setTempData($session, 'filter', 'completed');
                $this->setStep($session, self::STEP_VIEW_JOBS);
                $this->showJobsList($session);
                break;

            case 'all_jobs':
                $this->setTempData($session, 'filter', 'all');
                $this->setStep($session, self::STEP_VIEW_JOBS);
                $this->showJobsList($session);
                break;

            default:
                $this->showPosterMenu($session);
        }
    }

    /**
     * Show simplified poster menu.
     */
    protected function showPosterMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sendText($session->phone, "❌ Please register first.");
            $this->goToMenu($session);
            return;
        }

        // Get counts
        $activeCount = JobPost::where('poster_user_id', $user->id)
            ->whereIn('status', [JobStatus::OPEN, JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])
            ->count();

        $completedCount = JobPost::where('poster_user_id', $user->id)
            ->where('status', JobStatus::COMPLETED)
            ->count();

        $pendingApps = JobApplication::where('status', 'pending')
            ->whereHas('jobPost', fn($q) => $q->where('poster_user_id', $user->id)->where('status', JobStatus::OPEN))
            ->count();

        // Compact menu
        $message = "📋 *My Jobs*\n\n" .
            "🟢 Active: *{$activeCount}*\n" .
            "✅ Completed: *{$completedCount}*";

        if ($pendingApps > 0) {
            $message .= "\n\n🔔 *{$pendingApps} applications waiting!*";
        }

        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'post_job', 'title' => '➕ Post New Job'],
                ['id' => 'active_jobs', 'title' => "📋 Active ({$activeCount})"],
                ['id' => 'completed_jobs', 'title' => '✅ Completed'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Jobs List
    |--------------------------------------------------------------------------
    */

    protected function handleViewJobs(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'back') {
            $this->setStep($session, self::STEP_MENU);
            $this->showPosterMenu($session);
            return;
        }

        $this->showJobsList($session);
    }

    protected function showJobsList(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $filter = $this->getTempData($session, 'filter', 'active');

        $query = JobPost::where('poster_user_id', $user->id)
            ->with(['category', 'assignedWorker'])
            ->orderByDesc('created_at');

        // Apply filter
        if ($filter === 'active') {
            $query->whereIn('status', [JobStatus::OPEN, JobStatus::ASSIGNED, JobStatus::IN_PROGRESS]);
            $filterLabel = 'Active';
        } elseif ($filter === 'completed') {
            $query->where('status', JobStatus::COMPLETED);
            $filterLabel = 'Completed';
        } else {
            $filterLabel = 'All';
        }

        $jobs = $query->limit(10)->get();

        if ($jobs->isEmpty()) {
            $this->sendButtons(
                $session->phone,
                "📭 *No {$filterLabel} Jobs*\n\nPost cheyyaan start cheyyuka!",
                [
                    ['id' => 'post_job', 'title' => '➕ Post Job'],
                    ['id' => 'menu', 'title' => '📋 Menu'],
                ]
            );
            return;
        }

        // Build list
        $rows = [];
        foreach ($jobs as $job) {
            $icon = $this->getStatusIcon($job->status);
            $appCount = $job->applications_count ?? 0;
            $appLabel = $appCount > 0 ? " • 👥{$appCount}" : '';

            $rows[] = [
                'id' => 'job_' . $job->id,
                'title' => mb_substr("{$icon} {$job->title}", 0, 24),
                'description' => "₹" . number_format((float) $job->pay_amount) . $appLabel,
            ];
        }

        $this->sendList(
            $session->phone,
            "📋 *{$filterLabel} Jobs* ({$jobs->count()})",
            'View',
            [['title' => 'Jobs', 'rows' => $rows]]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Job Detail
    |--------------------------------------------------------------------------
    */

    protected function handleViewJob(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'view_apps') {
            $this->setStep($session, self::STEP_VIEW_APPLICATIONS);
            $this->showApplicationsList($session);
            return;
        }

        if ($selectionId === 'cancel_job') {
            $this->setStep($session, self::STEP_CONFIRM_CANCEL);
            $this->showCancelConfirmation($session);
            return;
        }

        if ($selectionId === 'back') {
            $this->setStep($session, self::STEP_VIEW_JOBS);
            $this->showJobsList($session);
            return;
        }

        $this->showJobDetail($session);
    }

    protected function showJobDetail(ConversationSession $session): void
    {
        $jobId = $this->getTempData($session, 'job_id');
        $job = JobPost::with(['category', 'assignedWorker', 'applications'])->find($jobId);

        if (!$job) {
            $this->sendText($session->phone, "❌ Job kandilla.");
            $this->setStep($session, self::STEP_VIEW_JOBS);
            $this->showJobsList($session);
            return;
        }

        $icon = $this->getStatusIcon($job->status);
        $statusLabel = $this->getStatusLabel($job->status);
        $catIcon = $job->category?->icon ?? '📋';
        $appCount = $job->applications->count();

        $message = "{$catIcon} *{$job->title}*\n\n" .
            "📍 {$job->location_name}\n" .
            "💰 ₹" . number_format((float) $job->pay_amount) . "\n" .
            "{$icon} Status: {$statusLabel}";

        if ($appCount > 0) {
            $pendingCount = $job->applications->where('status', 'pending')->count();
            $message .= "\n👥 Applications: {$appCount}";
            if ($pendingCount > 0) {
                $message .= " ({$pendingCount} pending)";
            }
        }

        if ($job->assignedWorker) {
            $message .= "\n👷 Worker: {$job->assignedWorker->name}";
        }

        // Buttons based on status
        $buttons = [];
        $statusValue = $job->status instanceof JobStatus ? $job->status->value : $job->status;

        if ($statusValue === 'open') {
            if ($appCount > 0) {
                $buttons[] = ['id' => 'view_apps', 'title' => "👥 Applications ({$appCount})"];
            }
            $buttons[] = ['id' => 'cancel_job', 'title' => '❌ Cancel'];
        } elseif ($statusValue === 'in_progress') {
            $buttons[] = ['id' => "work_done_{$job->id}", 'title' => '✅ Work Done'];
        }

        $buttons[] = ['id' => 'back', 'title' => '⬅️ Back'];

        $this->sendButtons($session->phone, $message, array_slice($buttons, 0, 3));
    }

    /*
    |--------------------------------------------------------------------------
    | Applications
    |--------------------------------------------------------------------------
    */

    protected function handleViewApplications(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'back') {
            $this->setStep($session, self::STEP_VIEW_JOB);
            $this->showJobDetail($session);
            return;
        }

        $this->showApplicationsList($session);
    }

    protected function showApplicationsList(ConversationSession $session): void
    {
        $jobId = $this->getTempData($session, 'job_id');
        $job = JobPost::with('applications.worker')->find($jobId);

        if (!$job) {
            $this->sendText($session->phone, "❌ Job kandilla.");
            return;
        }

        $applications = $job->applications()->with('worker')->orderByDesc('created_at')->get();

        if ($applications->isEmpty()) {
            $this->sendButtons(
                $session->phone,
                "📭 *No Applications*\n\nWorkersine ariyichittund. Wait cheyyuka!",
                [['id' => 'back', 'title' => '⬅️ Back']]
            );
            return;
        }

        $rows = [];
        foreach ($applications as $app) {
            $worker = $app->worker;
            $statusIcon = $this->getAppStatusIcon($app->status);
            $rating = $worker->rating ? "⭐{$worker->rating}" : '🆕';

            $rows[] = [
                'id' => 'applicant_' . $app->id,
                'title' => mb_substr("{$statusIcon} {$worker->name}", 0, 24),
                'description' => "{$rating} • {$worker->jobs_completed} jobs",
            ];
        }

        $pendingCount = $applications->where('status', 'pending')->count();

        $this->sendList(
            $session->phone,
            "👥 *Applications* ({$applications->count()})\nPending: {$pendingCount}",
            'View',
            [['title' => 'Applicants', 'rows' => $rows]]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Applicant Detail
    |--------------------------------------------------------------------------
    */

    protected function handleViewApplicant(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'back') {
            $this->setStep($session, self::STEP_VIEW_APPLICATIONS);
            $this->showApplicationsList($session);
            return;
        }

        $this->showApplicantDetail($session);
    }

    protected function showApplicantDetail(ConversationSession $session): void
    {
        $appId = $this->getTempData($session, 'app_id');
        $app = JobApplication::with(['worker', 'jobPost'])->find($appId);

        if (!$app) {
            $this->sendText($session->phone, "❌ Application kandilla.");
            $this->setStep($session, self::STEP_VIEW_APPLICATIONS);
            $this->showApplicationsList($session);
            return;
        }

        $worker = $app->worker;
        $job = $app->jobPost;
        $rating = $worker->rating ? "⭐ {$worker->rating}/5" : '🆕 New';
        $statusValue = is_string($app->status) ? $app->status : $app->status->value;

        $message = "👤 *{$worker->name}*\n\n" .
            "{$rating}\n" .
            "✅ {$worker->jobs_completed} jobs done";

        if ($app->message) {
            $message .= "\n\n💬 \"{$app->message}\"";
        }

        // Buttons based on status
        if ($statusValue === 'pending') {
            $this->sendButtons(
                $session->phone,
                $message,
                [
                    ['id' => "accept_{$app->id}", 'title' => '✅ Accept'],
                    ['id' => "reject_{$app->id}", 'title' => '❌ Reject'],
                    ['id' => 'back', 'title' => '⬅️ Back'],
                ]
            );
        } else {
            $statusLabel = strtoupper($statusValue);
            $this->sendButtons(
                $session->phone,
                $message . "\n\n*Status:* {$statusLabel}",
                [['id' => 'back', 'title' => '⬅️ Back']]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Accept/Reject
    |--------------------------------------------------------------------------
    */

    protected function acceptApplication(ConversationSession $session, int $appId): void
    {
        $app = JobApplication::with(['worker.user', 'jobPost'])->find($appId);

        if (!$app) {
            $this->sendText($session->phone, "❌ Application kandilla.");
            return;
        }

        try {
            $this->applicationService->acceptApplication($app);

            $worker = $app->worker;
            $job = $app->jobPost;

            // Notify poster
            $this->sendButtons(
                $session->phone,
                "✅ *{$worker->name}* accepted!\n\n📞 {$worker->user?->phone}",
                [
                    ['id' => 'job_' . $job->id, 'title' => '📋 View Job'],
                    ['id' => 'menu', 'title' => '📋 Menu'],
                ]
            );

            // Notify worker
            if ($worker->user?->phone) {
                $this->sendButtons(
                    $worker->user->phone,
                    "🎉 *Job kitiyi!*\n\n{$job->title}\n📍 {$job->location_name}\n💰 ₹" . number_format((float) $job->pay_amount),
                    [['id' => 'menu', 'title' => '📋 Menu']]
                );
            }

            $this->setTempData($session, 'job_id', $job->id);
            $this->setStep($session, self::STEP_VIEW_JOB);

        } catch (\Exception $e) {
            Log::error('Accept failed', ['error' => $e->getMessage()]);
            $this->sendText($session->phone, "❌ Accept failed. Try again.");
        }
    }

    protected function rejectApplication(ConversationSession $session, int $appId): void
    {
        $app = JobApplication::with(['worker', 'jobPost'])->find($appId);

        if (!$app) {
            $this->sendText($session->phone, "❌ Application kandilla.");
            return;
        }

        try {
            $this->applicationService->rejectApplication($app);

            $this->sendButtons(
                $session->phone,
                "❌ Application rejected.",
                [
                    ['id' => 'back', 'title' => '👥 Other Applicants'],
                    ['id' => 'menu', 'title' => '📋 Menu'],
                ]
            );

            $this->setStep($session, self::STEP_VIEW_APPLICATIONS);

        } catch (\Exception $e) {
            Log::error('Reject failed', ['error' => $e->getMessage()]);
            $this->sendText($session->phone, "❌ Reject failed. Try again.");
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

        if ($selectionId === 'yes_cancel') {
            $this->cancelJob($session);
            return;
        }

        if ($selectionId === 'no_cancel' || $selectionId === 'back') {
            $this->setStep($session, self::STEP_VIEW_JOB);
            $this->showJobDetail($session);
            return;
        }

        $this->showCancelConfirmation($session);
    }

    protected function showCancelConfirmation(ConversationSession $session): void
    {
        $jobId = $this->getTempData($session, 'job_id');
        $job = JobPost::find($jobId);

        if (!$job) {
            $this->setStep($session, self::STEP_VIEW_JOBS);
            $this->showJobsList($session);
            return;
        }

        $this->sendButtons(
            $session->phone,
            "⚠️ *Cancel Job?*\n\n{$job->title}\n\nUrappaano?",
            [
                ['id' => 'yes_cancel', 'title' => '❌ Yes, Cancel'],
                ['id' => 'no_cancel', 'title' => '⬅️ No, Back'],
            ]
        );
    }

    protected function cancelJob(ConversationSession $session): void
    {
        $jobId = $this->getTempData($session, 'job_id');
        $job = JobPost::find($jobId);

        if (!$job) {
            $this->sendText($session->phone, "❌ Job kandilla.");
            return;
        }

        try {
            $job->update([
                'status' => JobStatus::CANCELLED,
                'cancelled_at' => now(),
            ]);

            $this->sendButtons(
                $session->phone,
                "✅ Job cancelled.",
                [
                    ['id' => 'post_job', 'title' => '➕ New Job'],
                    ['id' => 'menu', 'title' => '📋 Menu'],
                ]
            );

            $this->setStep($session, self::STEP_MENU);

        } catch (\Exception $e) {
            Log::error('Cancel failed', ['error' => $e->getMessage()]);
            $this->sendText($session->phone, "❌ Cancel failed. Try again.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Work Done / Payment / Rating
    |--------------------------------------------------------------------------
    */

    protected function confirmWorkDone(ConversationSession $session, int $jobId): void
    {
        $job = JobPost::with(['verification', 'assignedWorker'])->find($jobId);

        if (!$job || !$job->verification) {
            $this->sendText($session->phone, "❌ Job kandilla.");
            return;
        }

        $this->executionService->confirmCompletionByPoster($job->verification);

        // Ask for payment method
        $this->sendButtons(
            $session->phone,
            "💰 *Payment engane?*\n\n{$job->title}\n💵 ₹" . number_format((float) $job->pay_amount),
            [
                ['id' => "pay_cash_{$job->id}", 'title' => '💵 Cash'],
                ['id' => "pay_upi_{$job->id}", 'title' => '📱 UPI'],
            ]
        );

        $this->setTempData($session, 'job_id', $jobId);
        $this->setStep($session, self::STEP_PAYMENT);
    }

    protected function handlePayment(IncomingMessage $message, ConversationSession $session): void
    {
        // Handled by button click
        $this->promptCurrentStep($session);
    }

    protected function processPayment(ConversationSession $session, string $method, int $jobId): void
    {
        $job = JobPost::with(['verification', 'assignedWorker'])->find($jobId);

        if (!$job || !$job->verification) {
            $this->sendText($session->phone, "❌ Job kandilla.");
            return;
        }

        $paymentMethod = $method === 'upi' ? PaymentMethod::UPI : PaymentMethod::CASH;
        $this->executionService->confirmPayment($job->verification, $paymentMethod);

        // Ask for rating
        $worker = $job->assignedWorker;
        $this->sendButtons(
            $session->phone,
            "⭐ *{$worker->name}-ne rate cheyyuka:*",
            [
                ['id' => "rate_5_{$job->id}", 'title' => '⭐⭐⭐⭐⭐'],
                ['id' => "rate_4_{$job->id}", 'title' => '⭐⭐⭐⭐'],
                ['id' => "rate_3_{$job->id}", 'title' => '⭐⭐⭐'],
            ]
        );

        $this->setStep($session, self::STEP_RATING);
    }

    protected function handleRating(IncomingMessage $message, ConversationSession $session): void
    {
        // Handled by button click
        $this->promptCurrentStep($session);
    }

    protected function processRating(ConversationSession $session, int $rating, int $jobId): void
    {
        $job = JobPost::with(['verification', 'assignedWorker'])->find($jobId);

        if (!$job || !$job->verification) {
            $this->sendText($session->phone, "❌ Job kandilla.");
            return;
        }

        $this->executionService->rateWorker($job->verification, $rating);
        $this->executionService->completeJob($job);

        // Notify worker
        $worker = $job->assignedWorker;
        if ($worker->user?->phone) {
            $stars = str_repeat('⭐', $rating);
            $this->sendText(
                $worker->user->phone,
                "✅ *Job complete!*\n💰 ₹" . number_format((float) $job->pay_amount) . " earned\nRating: {$stars}"
            );
        }

        $this->sendButtons(
            $session->phone,
            "✅ *Job completed!*\n\nNanni! 🙏",
            [
                ['id' => 'post_job', 'title' => '➕ New Job'],
                ['id' => 'menu', 'title' => '📋 Menu'],
            ]
        );

        $this->clearTempData($session);
        $this->setStep($session, self::STEP_MENU);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function getStatusIcon($status): string
    {
        $value = $status instanceof JobStatus ? $status->value : $status;
        
        return match ($value) {
            'open' => '🟢',
            'assigned' => '🔵',
            'in_progress' => '🟡',
            'completed' => '✅',
            'cancelled' => '❌',
            default => '📋',
        };
    }

    protected function getStatusLabel($status): string
    {
        $value = $status instanceof JobStatus ? $status->value : $status;
        
        return match ($value) {
            'open' => 'Open',
            'assigned' => 'Assigned',
            'in_progress' => 'In Progress',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            default => ucfirst($value),
        };
    }

    protected function getAppStatusIcon($status): string
    {
        $value = is_string($status) ? $status : $status->value;
        
        return match ($value) {
            'pending' => '🟡',
            'accepted' => '✅',
            'rejected' => '❌',
            default => '🔵',
        };
    }

    protected function setStep(ConversationSession $session, string $step): void
    {
        $this->sessionManager->setFlowStep($session, FlowType::JOB_POSTER_MENU, $step);
    }

    protected function startFlow(ConversationSession $session, FlowType $flow): void
    {
        $this->sessionManager->setFlowStep($session, $flow, 'start');
    }
}