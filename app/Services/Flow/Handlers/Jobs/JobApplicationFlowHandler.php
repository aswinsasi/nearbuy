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
use Illuminate\Support\Facades\Log;

/**
 * Handler for the job application flow.
 *
 * Simplified conversational Manglish flow for workers applying to jobs.
 *
 * Flow:
 * 1. Worker receives job notification (from JobPostingService)
 * 2. Taps [✅ Apply] → Instant apply → "✅ Applied! Poster-nu ariyichittund 👍"
 * 3. OR Taps [💬 Apply + Message] → Enter message → "✅ Applied with message!"
 * 4. OR Taps [📋 Details] → See full job → [✅ Apply] [💬 Apply + Message] [❌ Skip]
 *
 * Entry Points:
 * - apply_job_{id} - Direct apply (instant)
 * - apply_job_msg_{id} - Apply with message prompt
 * - view_job_{id} - View job details first
 *
 * @srs-ref NP-015, NP-016, NP-017
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

    public function getExpectedInputType(string $step): string
    {
        $stepEnum = JobApplicationStep::tryFrom($step);
        return $stepEnum?->expectedInput() ?? 'button';
    }

    /**
     * Start the application flow (browse mode).
     */
    public function start(ConversationSession $session): void
    {
        // Check if we have a job ID in temp data
        $jobId = $this->getTempData($session, 'apply_job_id');

        if ($jobId) {
            $this->showJobDetails($session, (int) $jobId);
            return;
        }

        // No job ID - show browse mode (list of nearby jobs)
        $this->showNearbyJobs($session);
    }

    /**
     * Start with direct apply (from notification button).
     *
     * @srs-ref NP-017 - Instant apply without message
     */
    public function startDirectApply(ConversationSession $session, int $jobId): void
    {
        $this->logInfo('Direct apply to job', [
            'job_id' => $jobId,
            'phone' => $this->maskPhone($session->phone),
        ]);

        $job = JobPost::with(['category', 'poster'])->find($jobId);
        $worker = $this->getWorker($session);

        if (!$job || !$worker) {
            $this->sendJobNotFoundError($session);
            return;
        }

        // Validate and apply
        $error = $this->validateCanApply($worker, $job, $session);
        if ($error) {
            return; // Error already sent
        }

        // Instant apply (no message)
        $this->submitApplication($session, $job, $worker, null);
    }

    /**
     * Start with apply + message flow (from notification button).
     *
     * @srs-ref NP-017 - Apply with optional message
     */
    public function startApplyWithMessage(ConversationSession $session, int $jobId): void
    {
        $this->logInfo('Apply with message flow', [
            'job_id' => $jobId,
            'phone' => $this->maskPhone($session->phone),
        ]);

        $job = JobPost::with(['category', 'poster'])->find($jobId);
        $worker = $this->getWorker($session);

        if (!$job || !$worker) {
            $this->sendJobNotFoundError($session);
            return;
        }

        // Validate
        $error = $this->validateCanApply($worker, $job, $session);
        if ($error) {
            return;
        }

        // Store context and prompt for message
        $this->clearTempData($session);
        $this->setTempData($session, 'apply_job_id', $job->id);
        $this->setTempData($session, 'job_title', $job->title);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::JOB_APPLICATION,
            JobApplicationStep::ENTER_MESSAGE->value
        );

        $this->promptEnterMessage($session);
    }

    /**
     * Start with job details view (from notification button).
     */
    public function startWithJobDetails(ConversationSession $session, int $jobId): void
    {
        $this->logInfo('Viewing job details', [
            'job_id' => $jobId,
            'phone' => $this->maskPhone($session->phone),
        ]);

        $job = JobPost::with(['category', 'poster'])->find($jobId);

        if (!$job) {
            $this->sendJobNotFoundError($session);
            return;
        }

        // Store context
        $this->clearTempData($session);
        $this->setTempData($session, 'apply_job_id', $job->id);
        $this->setTempData($session, 'job_title', $job->title);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::JOB_APPLICATION,
            JobApplicationStep::VIEW_JOB->value
        );

        $this->showJobDetails($session, $jobId);
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

        // Handle job-specific button clicks
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
            'browse' => $this->handleBrowseSelection($message, $session),
            JobApplicationStep::VIEW_JOB->value => $this->handleViewJob($message, $session),
            JobApplicationStep::ENTER_MESSAGE->value => $this->handleEnterMessage($message, $session),
            JobApplicationStep::APPLIED->value => $this->handleApplied($message, $session),
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
            'browse' => $this->showNearbyJobs($session),
            JobApplicationStep::VIEW_JOB->value => $this->showJobDetails($session, (int) $this->getTempData($session, 'apply_job_id')),
            JobApplicationStep::ENTER_MESSAGE->value => $this->promptEnterMessage($session),
            JobApplicationStep::APPLIED->value => $this->promptApplied($session),
            default => $this->start($session),
        };
    }

    /**
     * Handle job-related button clicks from any context.
     */
    protected function handleJobButtonClick(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }

        // Direct apply: apply_job_{id}
        if (preg_match('/^apply_job_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->startDirectApply($session, $jobId);
            return true;
        }

        // Apply with message: apply_job_msg_{id}
        if (preg_match('/^apply_job_msg_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->startApplyWithMessage($session, $jobId);
            return true;
        }

        // View job details: view_job_{id} or view_job_detail_{id}
        if (preg_match('/^view_job_(?:detail_)?(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->startWithJobDetails($session, $jobId);
            return true;
        }

        // Skip job: skip_job_{id}
        if (preg_match('/^skip_job_(\d+)$/', $selectionId, $matches)) {
            $this->sendText(
                $session->phone,
                "✅ Job skipped. Vere jobs varunnathu nokkaam! 💪"
            );
            $this->goToMenu($session);
            return true;
        }

        // Browse jobs
        if (in_array($selectionId, ['job_browse', 'browse_jobs', 'find_jobs'])) {
            $this->clearTempData($session);
            $this->showNearbyJobs($session);
            return true;
        }

        // Worker registration
        if ($selectionId === 'start_worker_registration') {
            $this->flowRouter->startFlow($session, FlowType::JOB_WORKER_REGISTER);
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Browse Jobs
    |--------------------------------------------------------------------------
    */

    protected function showNearbyJobs(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $worker = $user?->jobWorker;

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

        // Add distance calculation if coordinates available
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
                "📭 *Jobs Illa!*\n\n" .
                "Ippo ningalude aduthu jobs onnum illa.\n" .
                "Korachukoodikazhinju nokkuka! 🔄",
                [
                    ['id' => 'job_post', 'title' => '📝 Post a Job'],
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

            $icon = $job->category?->icon ?? '📋';

            $rows[] = [
                'id' => 'view_job_' . $job->id,
                'title' => mb_substr("{$icon} {$job->title}", 0, 24),
                'description' => mb_substr($job->pay_display . $distanceText . ' • ' . $job->formatted_date, 0, 72),
            ];
        }

        $jobCount = $jobs->count();

        $this->sendList(
            $session->phone,
            "🔍 *Nearby Jobs*\n\n" .
            "*{$jobCount}* jobs kandu!\n" .
            "Select cheyyuka details kaanaan.",
            '📋 View Jobs',
            [
                [
                    'title' => '📋 Available Jobs',
                    'rows' => $rows,
                ],
            ],
            '👷 Jobs'
        );

        $this->sessionManager->setFlowStep($session, FlowType::JOB_APPLICATION, 'browse');
    }

    protected function handleBrowseSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId && preg_match('/^view_job_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->startWithJobDetails($session, $jobId);
            return;
        }

        $this->showNearbyJobs($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: View Job Details
    |--------------------------------------------------------------------------
    */

    protected function handleViewJob(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $jobId = (int) $this->getTempData($session, 'apply_job_id');

        // Handle apply button
        if ($selectionId === 'apply_now') {
            $this->startDirectApply($session, $jobId);
            return;
        }

        // Handle apply with message
        if ($selectionId === 'apply_msg') {
            $this->startApplyWithMessage($session, $jobId);
            return;
        }

        // Handle skip
        if ($selectionId === 'skip_job' || $selectionId === 'not_interested') {
            $this->clearTempData($session);
            $this->sendText(
                $session->phone,
                "✅ Okay! Vere jobs nokkaam 💪"
            );
            $this->goToMenu($session);
            return;
        }

        // Handle get directions
        if ($selectionId && str_starts_with($selectionId, 'get_directions_')) {
            $this->sendJobLocation($session, $jobId);
            return;
        }

        // Re-show details
        $this->showJobDetails($session, $jobId);
    }

    /**
     * Show full job details with apply buttons.
     *
     * @srs-ref NP-015 - Job notification includes type, location, distance, date/time, duration, pay, poster rating
     * @srs-ref NP-016 - Social proof "X workers already applied"
     */
    protected function showJobDetails(ConversationSession $session, int $jobId): void
    {
        $job = JobPost::with(['category', 'poster'])->find($jobId);

        if (!$job) {
            $this->sendJobNotFoundError($session);
            return;
        }

        $worker = $this->getWorker($session);

        // Calculate distance
        $distanceText = 'N/A';
        if ($job->latitude && $job->longitude && $worker?->latitude && $worker?->longitude) {
            $distanceKm = $this->calculateDistance(
                (float) $worker->latitude,
                (float) $worker->longitude,
                (float) $job->latitude,
                (float) $job->longitude
            );
            $distanceText = $distanceKm < 1
                ? round($distanceKm * 1000) . 'm'
                : round($distanceKm, 1) . 'km';
        }

        // Build poster rating text
        $posterRating = '🆕 New';
        if ($job->poster?->rating) {
            $posterRating = "⭐{$job->poster->rating}";
        }

        // Social proof (NP-016)
        $applicationsText = '';
        $appCount = $job->applications_count ?? 0;
        if ($appCount > 0) {
            $applicationsText = "\n👥 *{$appCount}* workers already applied";
        } else {
            $applicationsText = "\n🎯 Be the first to apply!";
        }

        // Build message (NP-015 format)
        $icon = $job->category?->icon ?? '📋';
        $categoryName = $job->category?->name ?? 'Job';

        $message = "👷 *JOB DETAILS*\n\n" .
            "{$icon} *{$categoryName}* — {$job->location_display} • {$distanceText}\n\n" .
            "📅 {$job->formatted_date} ⏰ {$job->formatted_time}\n" .
            "⏱️ {$job->duration_display}\n" .
            "💰 *{$job->pay_display}* | Poster: {$posterRating}" .
            $applicationsText;

        if ($job->description) {
            $message .= "\n\n📝 {$job->description}";
        }

        if ($job->special_instructions) {
            $message .= "\n\n📌 _{$job->special_instructions}_";
        }

        // Buttons with Manglish labels
        $buttons = [
            ['id' => 'apply_now', 'title' => '✅ Apply'],
            ['id' => 'apply_msg', 'title' => '💬 Apply + Message'],
            ['id' => 'skip_job', 'title' => '❌ Skip'],
        ];

        $this->sendButtons(
            $session->phone,
            $message,
            $buttons,
            '👷 Job'
        );
    }

    protected function sendJobLocation(ConversationSession $session, int $jobId): void
    {
        $job = JobPost::find($jobId);

        if ($job && $job->latitude && $job->longitude) {
            $this->sendLocation(
                $session->phone,
                (float) $job->latitude,
                (float) $job->longitude,
                $job->title,
                $job->location_name
            );

            // Follow up with apply buttons
            $this->sendButtons(
                $session->phone,
                "📍 *Job Location*\n\nApply cheyyaan ready?",
                [
                    ['id' => 'apply_now', 'title' => '✅ Apply'],
                    ['id' => 'apply_msg', 'title' => '💬 Apply + Message'],
                ],
                '📍 Location'
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
            $jobId = (int) $this->getTempData($session, 'apply_job_id');
            $this->startDirectApply($session, $jobId);
            return;
        }

        // Handle cancel
        if ($selectionId === 'cancel') {
            $this->clearTempData($session);
            $this->sendText($session->phone, "❌ Cancelled.");
            $this->goToMenu($session);
            return;
        }

        // Handle text message
        if ($text && strlen(trim($text)) > 0) {
            $messageText = mb_substr(trim($text), 0, 300);

            $jobId = (int) $this->getTempData($session, 'apply_job_id');
            $job = JobPost::with(['category', 'poster'])->find($jobId);
            $worker = $this->getWorker($session);

            if (!$job || !$worker) {
                $this->sendJobNotFoundError($session);
                return;
            }

            // Apply with message
            $this->submitApplication($session, $job, $worker, $messageText);
            return;
        }

        // Re-prompt
        $this->promptEnterMessage($session);
    }

    protected function promptEnterMessage(ConversationSession $session): void
    {
        $jobTitle = $this->getTempData($session, 'job_title', 'Job');

        $this->sendButtons(
            $session->phone,
            "💬 *Message Type Cheyyuka*\n\n" .
            "Poster-nu oru message ayakkaam.\n" .
            "Eg: \"I have experience\" / \"Available anytime\"\n\n" .
            "📋 For: *{$jobTitle}*\n\n" .
            "_Message type cheyyuka or Skip press cheyyuka_",
            [
                ['id' => 'skip_message', 'title' => '⏭️ Skip Message'],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
            ],
            '💬 Message'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Submit Application
    |--------------------------------------------------------------------------
    */

    /**
     * Submit the application.
     *
     * @srs-ref NP-017 - Worker can apply with optional message
     */
    protected function submitApplication(
        ConversationSession $session,
        JobPost $job,
        JobWorker $worker,
        ?string $message
    ): void {
        try {
            // Create application
            $application = $this->applicationService->applyToJobWithMessage(
                $worker,
                $job,
                $message
            );

            // Get position for social proof
            $position = $this->applicationService->getApplicationPosition($application);

            $this->logInfo('Application submitted', [
                'application_id' => $application->id,
                'job_id' => $job->id,
                'worker_id' => $worker->id,
                'has_message' => !empty($message),
                'position' => $position,
            ]);

            // Clear temp
            $this->clearTempData($session);

            // Set step to applied
            $this->sessionManager->setFlowStep(
                $session,
                FlowType::JOB_APPLICATION,
                JobApplicationStep::APPLIED->value
            );

            // Send confirmation to worker
            $this->sendApplicationConfirmation($session, $job, $position, !empty($message));

            // Notify task giver
            $this->notifyPosterOfApplication($application);

        } catch (\Exception $e) {
            $this->logError('Failed to submit application', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
                'worker_id' => $worker->id,
            ]);

            $this->sendButtons(
                $session->phone,
                "❌ *Apply cheyyan pattiyilla*\n\n" . $e->getMessage(),
                [
                    ['id' => 'view_job_' . $job->id, 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '❌ Error'
            );
        }
    }

    /**
     * Send application confirmation to worker.
     */
    protected function sendApplicationConfirmation(
        ConversationSession $session,
        JobPost $job,
        int $position,
        bool $hasMessage
    ): void {
        $icon = $job->category?->icon ?? '📋';

        if ($hasMessage) {
            $text = "✅ *Applied with message!*\n" .
                "*Message-um kootti apply aayii!*\n\n" .
                "{$icon} {$job->title}\n" .
                "📍 {$job->location_display}\n\n" .
                "Poster-nu ariyichittund 👍\n" .
                "Response varunnathu nokkuka!";
        } else {
            $text = "✅ *Applied!*\n" .
                "*Apply cheythu!*\n\n" .
                "{$icon} {$job->title}\n" .
                "📍 {$job->location_display}\n\n" .
                "Poster-nu ariyichittund 👍\n" .
                "Response varunnathu nokkuka!";
        }

        if ($position <= 3) {
            $text .= "\n\n🏃 You're #{$position} in line!";
        }

        $this->sendButtons(
            $session->phone,
            $text,
            [
                ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            '✅ Applied!'
        );
    }

    /**
     * Notify task giver of new application.
     *
     * @srs-ref NP-018 - Show task giver application details
     */
    protected function notifyPosterOfApplication(\App\Models\JobApplication $application): void
    {
        $poster = $application->jobPost?->poster;

        if (!$poster || !$poster->phone) {
            return;
        }

        $worker = $application->worker;
        $job = $application->jobPost;

        // Build notification (NP-018 format)
        $rating = $worker->rating ? "⭐{$worker->rating}" : '🆕 New';
        $jobsCompleted = $worker->jobs_completed ?? 0;
        $distanceText = $application->distance_display;

        $messageText = $application->message
            ? "\n💬 \"{$application->message}\""
            : "";

        $appCount = $this->applicationService->getPendingApplicationsCount($job);

        $text = "👤 *{$worker->name}* wants your job!\n\n" .
            "{$rating} | {$jobsCompleted} jobs done | {$distanceText} away" .
            $messageText . "\n\n" .
            "📋 *{$job->title}*\n" .
            "👥 {$appCount} total applications";

        // Send worker photo if available
        if ($worker->photo_url) {
            $this->sendImage(
                $poster->phone,
                $worker->photo_url,
                "📸 {$worker->name}"
            );
        }

        $this->sendButtons(
            $poster->phone,
            $text,
            [
                ['id' => 'select_worker_' . $application->id, 'title' => '✅ Select'],
                ['id' => 'next_applicant_' . $job->id, 'title' => '➡️ Next'],
                ['id' => 'view_all_apps_' . $job->id, 'title' => '👥 View All'],
            ],
            '👤 New Applicant'
        );

        $this->logInfo('Poster notified of application', [
            'application_id' => $application->id,
            'poster_phone' => $this->maskPhone($poster->phone),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Applied (Complete)
    |--------------------------------------------------------------------------
    */

    protected function handleApplied(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'browse_jobs') {
            $this->clearTempData($session);
            $this->showNearbyJobs($session);
            return;
        }

        $this->goToMenu($session);
    }

    protected function promptApplied(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "✅ *Application submitted!*\n\n" .
            "Response varumbol ariyikkaam.",
            [
                ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            '✅ Done'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function getWorker(ConversationSession $session): ?JobWorker
    {
        $user = $this->getUser($session);
        return $user?->jobWorker;
    }

    protected function validateCanApply(
        JobWorker $worker,
        JobPost $job,
        ConversationSession $session
    ): ?string {
        // Check job is open
        if ($job->status !== JobStatus::OPEN) {
            $this->sendButtons(
                $session->phone,
                "❌ *Job Closed*\n\nEe job ippo available alla.",
                [
                    ['id' => 'browse_jobs', 'title' => '🔍 Other Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '❌ Closed'
            );
            return 'closed';
        }

        // Check not own job
        if ($worker->user_id === $job->poster_user_id) {
            $this->sendButtons(
                $session->phone,
                "❌ Swantham job-inu apply cheyyan pattilla!",
                [
                    ['id' => 'browse_jobs', 'title' => '🔍 Other Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '❌ Error'
            );
            return 'own_job';
        }

        // Check already applied
        if ($this->applicationService->hasWorkerApplied($worker, $job)) {
            $this->sendButtons(
                $session->phone,
                "✅ *Already Applied!*\n\nNingal ee job-inu already apply cheythittund.",
                [
                    ['id' => 'browse_jobs', 'title' => '🔍 Other Jobs'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '✅ Applied'
            );
            return 'already_applied';
        }

        // Check worker not registered
        if (!$worker) {
            $this->sendButtons(
                $session->phone,
                "👷 *Register First!*\n\n" .
                "Jobs-inu apply cheyyaan worker aayittu register cheyyuka.\n" .
                "_2 minutes mathram!_",
                [
                    ['id' => 'start_worker_registration', 'title' => '✅ Register'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '👷 Register'
            );
            return 'not_registered';
        }

        return null;
    }

    protected function sendJobNotFoundError(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "❌ *Job Not Found*\n\nEe job kandethaan pattiyilla.",
            [
                ['id' => 'browse_jobs', 'title' => '🔍 Browse Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            '❌ Error'
        );
    }

    protected function calculateDistance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371; // km

        $latFrom = deg2rad($lat1);
        $lonFrom = deg2rad($lon1);
        $latTo = deg2rad($lat2);
        $lonTo = deg2rad($lon2);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) ** 2 +
            cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}