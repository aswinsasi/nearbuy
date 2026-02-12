<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Jobs;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\JobExecutionStep;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Models\ConversationSession;
use App\Models\JobPost;
use App\Models\JobVerification;
use App\Models\JobWorker;
use App\Models\User;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Jobs\JobExecutionService;
use App\Services\Media\MediaService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Handler for job execution flow with Manglish messages.
 *
 * Manages the entire job execution lifecycle:
 * - NP-022: Worker sends arrival photo
 * - NP-023: Notify task giver with photo and timestamp
 * - NP-024: Handover confirmation for queue jobs
 * - NP-025: Mutual completion confirmation
 * - NP-026: Rating (1-5 stars) + optional review
 * - NP-027: Payment method selection (Cash/UPI)
 * - NP-028: Update worker stats
 *
 * Entry Points:
 * - start_job_{id}: Start job execution
 * - job_arrived_{id}: Worker arrived at location
 * - confirm_arrival_{id}: Poster confirms arrival
 *
 * @srs-ref NP-022 to NP-028
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobExecutionFlowHandler extends AbstractFlowHandler
{
    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected JobExecutionService $executionService,
        protected MediaService $mediaService
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::JOB_EXECUTION;
    }

    protected function getSteps(): array
    {
        return JobExecutionStep::values();
    }

    public function getExpectedInputType(string $step): string
    {
        $enumStep = JobExecutionStep::tryFrom($step);
        return $enumStep?->expectedInput() ?? 'button';
    }

    /*
    |--------------------------------------------------------------------------
    | Entry Points
    |--------------------------------------------------------------------------
    */

    /**
     * Start the execution flow.
     */
    public function start(ConversationSession $session): void
    {
        $jobId = $this->getTempData($session, 'job_id');

        if ($jobId) {
            $this->startWithJob($session, (int) $jobId);
            return;
        }

        // Try to find active job
        $this->showActiveJobOrError($session);
    }

    /**
     * Start execution flow for a specific job.
     */
    public function startWithJob(ConversationSession $session, int $jobId): void
    {
        $job = JobPost::with(['assignedWorker.user', 'poster', 'category', 'verification'])
            ->find($jobId);

        if (!$job) {
            $this->sendText($session->phone, "❌ Job kandilla.");
            $this->goToMenu($session);
            return;
        }

        // Verify job can be started
        if (!$this->executionService->canStartJob($job)) {
            $this->sendText(
                $session->phone,
                "❌ Ee job start cheyan pattilla.\nStatus: *{$job->status_display}*"
            );
            $this->goToMenu($session);
            return;
        }

        // Store context
        $this->clearTempData($session);
        $this->setTempData($session, 'job_id', $job->id);
        $this->setTempData($session, 'job_title', $job->title);

        // Determine user role
        $user = $this->getUser($session);
        $isWorker = $this->isUserWorker($user, $job);
        $this->setTempData($session, 'is_worker', $isWorker);
        $this->setTempData($session, 'is_handover_job', $job->is_handover_job);

        // Set flow
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::JOB_EXECUTION,
            JobExecutionStep::ARRIVAL_PHOTO->value
        );

        if ($isWorker) {
            $this->startWorkerFlow($session, $job);
        } else {
            $this->startPosterFlow($session, $job);
        }
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle button clicks first
        if ($this->handleButtonClick($message, $session)) {
            return;
        }

        $step = $session->current_step;

        match ($step) {
            // Worker steps
            JobExecutionStep::ARRIVAL_PHOTO->value => $this->handleArrivalPhoto($message, $session),
            JobExecutionStep::HANDOVER_WORKER->value => $this->handleHandoverWorker($message, $session),
            JobExecutionStep::COMPLETION_WORKER->value => $this->handleCompletionWorker($message, $session),

            // Poster steps
            JobExecutionStep::ARRIVAL_CONFIRMED->value => $this->handleArrivalConfirmed($message, $session),
            JobExecutionStep::HANDOVER_POSTER->value => $this->handleHandoverPoster($message, $session),
            JobExecutionStep::COMPLETION_POSTER->value => $this->handleCompletionPoster($message, $session),
            JobExecutionStep::PAYMENT->value => $this->handlePayment($message, $session),
            JobExecutionStep::RATING->value => $this->handleRating($message, $session),
            JobExecutionStep::RATING_COMMENT->value => $this->handleRatingComment($message, $session),

            // Done
            JobExecutionStep::DONE->value => $this->handleDone($message, $session),

            default => $this->start($session),
        };
    }

    /**
     * Re-prompt the current step.
     */
    public function promptCurrentStep(ConversationSession $session): void
    {
        $step = $session->current_step;
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        match ($step) {
            JobExecutionStep::ARRIVAL_PHOTO->value => $this->promptArrivalPhoto($session, $job),
            JobExecutionStep::ARRIVAL_CONFIRMED->value => $this->promptArrivalConfirmed($session, $job),
            JobExecutionStep::HANDOVER_WORKER->value => $this->promptHandoverWorker($session, $job),
            JobExecutionStep::HANDOVER_POSTER->value => $this->promptHandoverPoster($session, $job),
            JobExecutionStep::COMPLETION_WORKER->value => $this->promptCompletionWorker($session, $job),
            JobExecutionStep::COMPLETION_POSTER->value => $this->promptCompletionPoster($session, $job),
            JobExecutionStep::PAYMENT->value => $this->promptPayment($session, $job),
            JobExecutionStep::RATING->value => $this->promptRating($session, $job),
            JobExecutionStep::RATING_COMMENT->value => $this->promptRatingComment($session, $job),
            default => $this->start($session),
        };
    }

    /**
     * Handle button clicks.
     */
    protected function handleButtonClick(IncomingMessage $message, ConversationSession $session): bool
    {
        $buttonId = $this->getSelectionId($message);

        if (!$buttonId) {
            return false;
        }

        // Start job
        if (preg_match('/^start_job_(\d+)$/', $buttonId, $matches)) {
            $this->startWithJob($session, (int) $matches[1]);
            return true;
        }

        // Skip arrival photo
        if (preg_match('/^skip_photo_(\d+)$/', $buttonId, $matches)) {
            $this->skipArrivalPhoto($session);
            return true;
        }

        // Poster confirms arrival
        if (preg_match('/^arrival_ok_(\d+)$/', $buttonId, $matches)) {
            $this->processArrivalConfirmed($session);
            return true;
        }

        // Handover buttons
        if (preg_match('/^handover_done_(\d+)$/', $buttonId, $matches)) {
            $this->processHandoverWorker($session);
            return true;
        }

        if (preg_match('/^handover_ok_(\d+)$/', $buttonId, $matches)) {
            $this->processHandoverPoster($session);
            return true;
        }

        // Completion buttons
        if (preg_match('/^work_done_(\d+)$/', $buttonId, $matches)) {
            $this->processCompletionWorker($session);
            return true;
        }

        if (preg_match('/^work_ok_(\d+)$/', $buttonId, $matches)) {
            $this->processCompletionPoster($session);
            return true;
        }

        // Payment buttons
        if (preg_match('/^pay_(cash|upi)_(\d+)$/', $buttonId, $matches)) {
            $this->processPayment($session, $matches[1]);
            return true;
        }

        // Rating buttons
        if (preg_match('/^rate_(\d)_(\d+)$/', $buttonId, $matches)) {
            $this->processRating($session, (int) $matches[1]);
            return true;
        }

        // Skip review
        if (preg_match('/^skip_review_(\d+)$/', $buttonId, $matches)) {
            $this->completeJobFlow($session);
            return true;
        }

        // Menu
        if ($buttonId === 'menu' || $buttonId === 'main_menu') {
            $this->goToMenu($session);
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Flow Initialization
    |--------------------------------------------------------------------------
    */

    /**
     * Start worker-side flow.
     */
    protected function startWorkerFlow(ConversationSession $session, JobPost $job): void
    {
        $verification = $job->verification;

        if ($verification && $verification->has_arrived) {
            // Already arrived, check state
            if ($verification->is_worker_confirmed) {
                // Waiting for poster/payment
                $this->showWaitingForPoster($session, $job);
            } else {
                // Show completion prompt
                $this->setStep($session, JobExecutionStep::COMPLETION_WORKER->value);
                $this->promptCompletionWorker($session, $job);
            }
        } else {
            // Request arrival photo (NP-022)
            $this->setStep($session, JobExecutionStep::ARRIVAL_PHOTO->value);
            $this->promptArrivalPhoto($session, $job);
        }
    }

    /**
     * Start poster-side flow.
     */
    protected function startPosterFlow(ConversationSession $session, JobPost $job): void
    {
        $verification = $job->verification;

        if (!$verification || !$verification->has_arrived) {
            // Worker hasn't arrived yet
            $this->sendText(
                $session->phone,
                "⏳ *Worker varunnund...*\n\n" .
                "{$job->category_icon} {$job->title}\n" .
                "👤 {$job->assignedWorker->name}\n\n" .
                "Worker ethumbol ariyikkam."
            );
            $this->goToMenu($session);
            return;
        }

        // Check state and show appropriate step
        if ($verification->is_payment_confirmed) {
            // Already paid, show rating or summary
            if (!$verification->is_rated) {
                $this->setStep($session, JobExecutionStep::RATING->value);
                $this->promptRating($session, $job);
            } else {
                $this->showCompletionSummary($session, $job, false);
            }
        } elseif ($verification->is_poster_confirmed) {
            // Waiting for payment
            $this->setStep($session, JobExecutionStep::PAYMENT->value);
            $this->promptPayment($session, $job);
        } elseif ($verification->is_worker_confirmed) {
            // Worker confirmed, poster needs to confirm
            $this->setStep($session, JobExecutionStep::COMPLETION_POSTER->value);
            $this->promptCompletionPoster($session, $job);
        } elseif (!$verification->is_arrival_confirmed) {
            // Arrival not confirmed
            $this->setStep($session, JobExecutionStep::ARRIVAL_CONFIRMED->value);
            $this->promptArrivalConfirmed($session, $job);
        } else {
            // Waiting for worker to complete
            $this->sendText(
                $session->phone,
                "⏳ *Pani nadakkunnu...*\n\n" .
                "{$job->category_icon} {$job->title}\n" .
                "👤 {$job->assignedWorker->name}\n\n" .
                "Worker pani kazhiyumbol ariyikkam."
            );
        }
    }

    /**
     * Show active job or error.
     */
    protected function showActiveJobOrError(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sendText($session->phone, "❌ Please register first.");
            $this->goToMenu($session);
            return;
        }

        // Check if user is a worker with active job
        $worker = JobWorker::where('user_id', $user->id)->first();

        if ($worker) {
            $activeJob = $this->executionService->getActiveJobForWorker($worker);
            if ($activeJob) {
                $this->startWithJob($session, $activeJob->id);
                return;
            }
        }

        // Check for active posted jobs
        $activePostedJob = JobPost::where('poster_user_id', $user->id)
            ->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])
            ->first();

        if ($activePostedJob) {
            $this->startWithJob($session, $activePostedJob->id);
            return;
        }

        $this->sendText(
            $session->phone,
            "ℹ️ Active jobs onnum illa."
        );
        $this->goToMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Flow: Arrival Photo (NP-022)
    |--------------------------------------------------------------------------
    */

    /**
     * Prompt for arrival photo.
     */
    protected function promptArrivalPhoto(ConversationSession $session, JobPost $job): void
    {
        $this->sendButtons(
            $session->phone,
            "⏰ *Job time!*\n\n" .
            "{$job->category_icon} {$job->title}\n" .
            "📍 {$job->location_display}\n\n" .
            "*{$job->location_display}-il ethi?*\n" .
            "📸 Arrival photo ayakkuka:",
            [
                ['id' => "skip_photo_{$job->id}", 'title' => '⏭️ Skip Photo'],
                ['id' => 'menu', 'title' => '📋 Menu'],
            ]
        );
    }

    /**
     * Handle arrival photo upload.
     */
    protected function handleArrivalPhoto(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        if ($message->isImage()) {
            $this->processArrivalPhoto($session, $message, $job);
            return;
        }

        // Re-prompt
        $this->promptArrivalPhoto($session, $job);
    }

    /**
     * Process arrival photo.
     */
    protected function processArrivalPhoto(
        ConversationSession $session,
        IncomingMessage $message,
        JobPost $job
    ): void {
        try {
            // Download and store photo
            $mediaId = $message->getMediaId();
            $result = $this->mediaService->downloadAndStore($mediaId, 'job-arrivals');
            $photoUrl = $result['url'] ?? $result['path'] ?? null;

            // Get location if available
            $latitude = null;
            $longitude = null;

            // Start execution and record arrival
            $verification = $this->executionService->startJobExecution($job);
            $this->executionService->recordArrival($verification, $photoUrl, $latitude, $longitude);

            // Notify poster (NP-023)
            $this->notifyPosterOfArrival($job, $verification);

            // Determine next step
            $isHandoverJob = $this->getTempData($session, 'is_handover_job', false);

            if ($isHandoverJob) {
                $this->setStep($session, JobExecutionStep::HANDOVER_WORKER->value);
                $this->promptHandoverWorker($session, $job);
            } else {
                $this->setStep($session, JobExecutionStep::COMPLETION_WORKER->value);
                $this->sendButtons(
                    $session->phone,
                    "✅ *Ethi!*\n\n" .
                    "{$job->category_icon} {$job->title}\n" .
                    "📍 {$job->location_display}\n\n" .
                    "Poster-nu ariyichittund. 👍\n\n" .
                    "_Pani kazhinjhal 'Done' press cheyyuka_",
                    [
                        ['id' => "work_done_{$job->id}", 'title' => '✅ Done'],
                        ['id' => 'menu', 'title' => '📋 Menu'],
                    ]
                );
            }

        } catch (\Exception $e) {
            Log::error('Failed to process arrival photo', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
            ]);

            $this->sendButtons(
                $session->phone,
                "❌ Photo upload failed. Try again?",
                [
                    ['id' => "skip_photo_{$job->id}", 'title' => '⏭️ Skip Photo'],
                    ['id' => 'menu', 'title' => '📋 Menu'],
                ]
            );
        }
    }

    /**
     * Skip arrival photo.
     */
    protected function skipArrivalPhoto(ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        // Start execution without photo
        $verification = $this->executionService->startJobExecution($job);
        $this->executionService->recordArrival($verification, null);

        // Notify poster
        $this->notifyPosterOfArrival($job, $verification);

        // Next step
        $isHandoverJob = $this->getTempData($session, 'is_handover_job', false);

        if ($isHandoverJob) {
            $this->setStep($session, JobExecutionStep::HANDOVER_WORKER->value);
            $this->promptHandoverWorker($session, $job);
        } else {
            $this->setStep($session, JobExecutionStep::COMPLETION_WORKER->value);
            $this->promptCompletionWorker($session, $job);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Poster Flow: Arrival Confirmation (NP-023)
    |--------------------------------------------------------------------------
    */

    /**
     * Notify poster of worker arrival.
     */
    protected function notifyPosterOfArrival(JobPost $job, JobVerification $verification): void
    {
        $poster = $job->poster;

        if (!$poster || !$poster->phone) {
            return;
        }

        $worker = $job->assignedWorker;
        $time = $verification->arrival_time_formatted ?? now()->format('g:i A');

        // Send photo if available
        if ($verification->arrival_photo_url) {
            $this->sendImage(
                $poster->phone,
                $verification->arrival_photo_url,
                "📸 Arrival photo"
            );
        }

        // Send notification with confirm button
        $this->sendButtons(
            $poster->phone,
            "✅ *{$worker->name} ethi!*\n\n" .
            "{$job->category_icon} {$job->title}\n" .
            "📍 {$job->location_display}\n" .
            "⏰ {$time}",
            [
                ['id' => "arrival_ok_{$job->id}", 'title' => '👍 Confirmed'],
            ]
        );

        Log::info('Poster notified of arrival (NP-023)', [
            'job_id' => $job->id,
            'poster_phone' => $this->maskPhone($poster->phone),
        ]);
    }

    /**
     * Prompt poster to confirm arrival.
     */
    protected function promptArrivalConfirmed(ConversationSession $session, JobPost $job): void
    {
        $verification = $job->verification;
        $worker = $job->assignedWorker;
        $time = $verification?->arrival_time_formatted ?? '?';

        $this->sendButtons(
            $session->phone,
            "✅ *{$worker->name} ethi!*\n\n" .
            "{$job->category_icon} {$job->title}\n" .
            "📍 {$job->location_display}\n" .
            "⏰ {$time}",
            [
                ['id' => "arrival_ok_{$job->id}", 'title' => '👍 Confirmed'],
            ]
        );
    }

    /**
     * Handle arrival confirmation.
     */
    protected function handleArrivalConfirmed(IncomingMessage $message, ConversationSession $session): void
    {
        $this->processArrivalConfirmed($session);
    }

    /**
     * Process arrival confirmation by poster.
     */
    protected function processArrivalConfirmed(ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job || !$job->verification) {
            $this->start($session);
            return;
        }

        $this->executionService->confirmArrival($job->verification);

        $isHandoverJob = $job->is_handover_job;

        if ($isHandoverJob) {
            $this->setStep($session, JobExecutionStep::HANDOVER_POSTER->value);
            $this->promptHandoverPoster($session, $job);
        } else {
            $this->sendText(
                $session->phone,
                "👍 *Confirmed!*\n\nPani kazhiyumbol ariyikkam."
            );
            // Wait for worker to complete
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Handover Flow (NP-024)
    |--------------------------------------------------------------------------
    */

    /**
     * Prompt worker for handover confirmation.
     */
    protected function promptHandoverWorker(ConversationSession $session, JobPost $job): void
    {
        $this->sendButtons(
            $session->phone,
            "🤝 *Handover cheytho?*\n\n" .
            "{$job->category_icon} {$job->title}\n" .
            "📍 {$job->location_display}\n\n" .
            "Poster-nu position handover cheytho?",
            [
                ['id' => "handover_done_{$job->id}", 'title' => '✅ Handover Done'],
                ['id' => 'menu', 'title' => '📋 Menu'],
            ]
        );
    }

    /**
     * Handle worker handover.
     */
    protected function handleHandoverWorker(IncomingMessage $message, ConversationSession $session): void
    {
        $this->processHandoverWorker($session);
    }

    /**
     * Process worker handover confirmation.
     */
    protected function processHandoverWorker(ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job || !$job->verification) {
            $this->start($session);
            return;
        }

        $this->executionService->confirmHandoverByWorker($job->verification);

        // Notify poster
        $poster = $job->poster;
        if ($poster && $poster->phone) {
            $this->sendButtons(
                $poster->phone,
                "🤝 *Handover!*\n\n" .
                "{$job->category_icon} {$job->title}\n" .
                "👤 {$job->assignedWorker->name} handover cheythu.\n\n" .
                "Confirm cheyyuka:",
                [
                    ['id' => "handover_ok_{$job->id}", 'title' => '✅ Handover Confirmed'],
                ]
            );
        }

        $this->setStep($session, JobExecutionStep::COMPLETION_WORKER->value);
        $this->sendText(
            $session->phone,
            "✅ *Handover done!*\n\nPoster confirm cheyyum. Pani thudanguka!"
        );
    }

    /**
     * Prompt poster for handover confirmation.
     */
    protected function promptHandoverPoster(ConversationSession $session, JobPost $job): void
    {
        $this->sendButtons(
            $session->phone,
            "🤝 *Handover Confirm cheyyuka*\n\n" .
            "{$job->category_icon} {$job->title}\n" .
            "👤 {$job->assignedWorker->name}\n\n" .
            "Worker position edutho?",
            [
                ['id' => "handover_ok_{$job->id}", 'title' => '✅ Handover Confirmed'],
                ['id' => 'menu', 'title' => '📋 Menu'],
            ]
        );
    }

    /**
     * Handle poster handover.
     */
    protected function handleHandoverPoster(IncomingMessage $message, ConversationSession $session): void
    {
        $this->processHandoverPoster($session);
    }

    /**
     * Process poster handover confirmation.
     */
    protected function processHandoverPoster(ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job || !$job->verification) {
            $this->start($session);
            return;
        }

        $this->executionService->confirmHandoverByPoster($job->verification);

        $this->sendText(
            $session->phone,
            "✅ *Handover confirmed!*\n\nPani kazhiyumbol ariyikkam."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Completion (NP-025)
    |--------------------------------------------------------------------------
    */

    /**
     * Prompt worker to confirm completion.
     */
    protected function promptCompletionWorker(ConversationSession $session, JobPost $job): void
    {
        $this->sendButtons(
            $session->phone,
            "✅ *Pani kazhinjho?*\n\n" .
            "{$job->category_icon} {$job->title}\n" .
            "📍 {$job->location_display}\n" .
            "💰 {$job->pay_display}",
            [
                ['id' => "work_done_{$job->id}", 'title' => '✅ Done'],
                ['id' => 'menu', 'title' => '📋 Menu'],
            ]
        );
    }

    /**
     * Handle worker completion.
     */
    protected function handleCompletionWorker(IncomingMessage $message, ConversationSession $session): void
    {
        $this->processCompletionWorker($session);
    }

    /**
     * Process worker completion confirmation.
     */
    protected function processCompletionWorker(ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        $verification = $this->executionService->getOrCreateVerification($job);
        $this->executionService->confirmCompletionByWorker($verification);

        // Notify poster for confirmation
        $this->notifyPosterForCompletion($job, $verification);

        $this->showWaitingForPoster($session, $job);
    }

    /**
     * Show waiting for poster message.
     */
    protected function showWaitingForPoster(ConversationSession $session, JobPost $job): void
    {
        $this->sendText(
            $session->phone,
            "✅ *Pani kazhinjhu!*\n\n" .
            "{$job->category_icon} {$job->title}\n" .
            "💰 {$job->pay_display}\n\n" .
            "Poster-nu ariyichittund.\n" .
            "⏳ Payment confirm cheyyumbol ariyikkam..."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Poster Completion (NP-025)
    |--------------------------------------------------------------------------
    */

    /**
     * Notify poster for completion confirmation.
     */
    protected function notifyPosterForCompletion(JobPost $job, JobVerification $verification): void
    {
        $poster = $job->poster;

        if (!$poster || !$poster->phone) {
            return;
        }

        $worker = $job->assignedWorker;

        $this->sendButtons(
            $poster->phone,
            "✅ *{$worker->name} pani kazhichu!*\n\n" .
            "{$job->category_icon} {$job->title}\n" .
            "💰 {$job->pay_display}\n\n" .
            "*Worker pani kazhicho?*",
            [
                ['id' => "work_ok_{$job->id}", 'title' => '✅ Yes, Completed'],
                ['id' => "dispute_{$job->id}", 'title' => '❌ Problem'],
            ]
        );
    }

    /**
     * Prompt poster to confirm completion.
     */
    protected function promptCompletionPoster(ConversationSession $session, JobPost $job): void
    {
        $worker = $job->assignedWorker;

        $this->sendButtons(
            $session->phone,
            "✅ *Worker pani kazhicho?*\n\n" .
            "{$job->category_icon} {$job->title}\n" .
            "👤 {$worker->name}\n" .
            "💰 {$job->pay_display}",
            [
                ['id' => "work_ok_{$job->id}", 'title' => '✅ Yes, Completed'],
                ['id' => "dispute_{$job->id}", 'title' => '❌ Problem'],
            ]
        );
    }

    /**
     * Handle poster completion.
     */
    protected function handleCompletionPoster(IncomingMessage $message, ConversationSession $session): void
    {
        $this->processCompletionPoster($session);
    }

    /**
     * Process poster completion confirmation.
     */
    protected function processCompletionPoster(ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job || !$job->verification) {
            $this->start($session);
            return;
        }

        $this->executionService->confirmCompletionByPoster($job->verification);

        // Move to payment
        $this->setStep($session, JobExecutionStep::PAYMENT->value);
        $this->promptPayment($session, $job);
    }

    /*
    |--------------------------------------------------------------------------
    | Payment (NP-027)
    |--------------------------------------------------------------------------
    */

    /**
     * Prompt for payment method.
     */
    protected function promptPayment(ConversationSession $session, JobPost $job): void
    {
        $this->sendButtons(
            $session->phone,
            "💰 *Payment engane?*\n\n" .
            "{$job->category_icon} {$job->title}\n" .
            "👤 {$job->assignedWorker->name}\n" .
            "💵 Amount: *{$job->pay_display}*",
            [
                ['id' => "pay_cash_{$job->id}", 'title' => '💵 Cash'],
                ['id' => "pay_upi_{$job->id}", 'title' => '📱 UPI'],
            ]
        );
    }

    /**
     * Handle payment selection.
     */
    protected function handlePayment(IncomingMessage $message, ConversationSession $session): void
    {
        // Handled by button click
        $this->promptPayment($session, $this->getCurrentJob($session));
    }

    /**
     * Process payment confirmation.
     */
    protected function processPayment(ConversationSession $session, string $method): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job || !$job->verification) {
            $this->start($session);
            return;
        }

        $paymentMethod = match ($method) {
            'cash' => PaymentMethod::CASH,
            'upi' => PaymentMethod::UPI,
            default => PaymentMethod::CASH,
        };

        $this->executionService->confirmPayment($job->verification, $paymentMethod);

        // Notify worker of payment
        $this->notifyWorkerOfPayment($job, $paymentMethod);

        // Move to rating
        $this->setStep($session, JobExecutionStep::RATING->value);
        $this->promptRating($session, $job);
    }

    /**
     * Notify worker of payment.
     */
    protected function notifyWorkerOfPayment(JobPost $job, PaymentMethod $method): void
    {
        $worker = $job->assignedWorker;

        if (!$worker || !$worker->user || !$worker->user->phone) {
            return;
        }

        $methodDisplay = $method->label();

        // Get updated stats
        $stats = $this->executionService->getWorkerCompletionSummary($job);

        $this->sendText(
            $worker->user->phone,
            "✅ *Job complete!* 🎉\n\n" .
            "{$job->category_icon} {$job->title}\n\n" .
            "💰 *{$job->pay_display}* earned!\n" .
            "💳 Via: {$methodDisplay}\n\n" .
            "📊 Total: {$stats['total_jobs']} jobs | ⭐ {$stats['avg_rating']}"
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Rating (NP-026)
    |--------------------------------------------------------------------------
    */

    /**
     * Prompt for worker rating.
     */
    protected function promptRating(ConversationSession $session, JobPost $job): void
    {
        $worker = $job->assignedWorker;

        $this->sendButtons(
            $session->phone,
            "⭐ *{$worker->name}-ne rate cheyyuka:*\n\n" .
            "{$job->category_icon} {$job->title}",
            [
                ['id' => "rate_5_{$job->id}", 'title' => '⭐⭐⭐⭐⭐'],
                ['id' => "rate_4_{$job->id}", 'title' => '⭐⭐⭐⭐'],
                ['id' => "rate_3_{$job->id}", 'title' => '⭐⭐⭐'],
            ]
        );

        // Send additional rating options as list
        $this->sendList(
            $session->phone,
            "More ratings:",
            'Select Rating',
            [
                [
                    'title' => 'Rating',
                    'rows' => [
                        ['id' => "rate_2_{$job->id}", 'title' => '⭐⭐ Fair'],
                        ['id' => "rate_1_{$job->id}", 'title' => '⭐ Poor'],
                        ['id' => "skip_review_{$job->id}", 'title' => '⏭️ Skip Rating'],
                    ],
                ],
            ]
        );
    }

    /**
     * Handle rating selection.
     */
    protected function handleRating(IncomingMessage $message, ConversationSession $session): void
    {
        // Handled by button click
        $this->promptRating($session, $this->getCurrentJob($session));
    }

    /**
     * Process rating.
     */
    protected function processRating(ConversationSession $session, int $rating): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job || !$job->verification) {
            $this->start($session);
            return;
        }

        $this->setTempData($session, 'rating', $rating);

        // Ask for optional review
        $this->setStep($session, JobExecutionStep::RATING_COMMENT->value);
        $this->promptRatingComment($session, $job);
    }

    /**
     * Prompt for optional review.
     */
    protected function promptRatingComment(ConversationSession $session, JobPost $job): void
    {
        $rating = $this->getTempData($session, 'rating', 5);

        $this->sendButtons(
            $session->phone,
            "⭐ Rating: {$rating}/5\n\n" .
            "*Review ezhuthano? (optional)*\n\n" .
            "Type your review or skip:",
            [
                ['id' => "skip_review_{$job->id}", 'title' => '⏭️ Skip'],
            ]
        );
    }

    /**
     * Handle rating comment.
     */
    protected function handleRatingComment(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job || !$job->verification) {
            $this->start($session);
            return;
        }

        $rating = $this->getTempData($session, 'rating', 5);
        $comment = $message->isText() ? trim($message->getText()) : null;

        // Save rating with optional comment
        $this->executionService->rateWorker($job->verification, $rating, $comment);

        // Complete the flow
        $this->completeJobFlow($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Job Completion (NP-028)
    |--------------------------------------------------------------------------
    */

    /**
     * Complete job flow.
     */
    protected function completeJobFlow(ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->goToMenu($session);
            return;
        }

        // Complete job and update stats (NP-028)
        $this->executionService->completeJob($job);

        // Show completion summary
        $isWorker = $this->getTempData($session, 'is_worker', false);
        $this->showCompletionSummary($session, $job, $isWorker);

        // Clear temp and done
        $this->clearTempData($session);
        $this->setStep($session, JobExecutionStep::DONE->value);
    }

    /**
     * Show completion summary.
     */
    protected function showCompletionSummary(ConversationSession $session, JobPost $job, bool $isWorker): void
    {
        $verification = $job->verification;

        if ($isWorker) {
            // Worker summary (NP-028)
            $stats = $this->executionService->getWorkerCompletionSummary($job);

            $ratingLine = $stats['rating_received'] 
                ? "⭐ Rating: {$stats['rating_received']}/5" 
                : "";

            $this->sendButtons(
                $session->phone,
                "✅ *Job complete!* 🎉\n\n" .
                "{$job->category_icon} {$job->title}\n\n" .
                "💰 *{$stats['amount_earned']}* earned!\n" .
                "{$ratingLine}\n\n" .
                "📊 Total: {$stats['total_jobs']} jobs | ⭐ {$stats['avg_rating']}",
                [
                    ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
                    ['id' => 'menu', 'title' => '📋 Menu'],
                ]
            );
        } else {
            // Poster summary
            $rating = $verification?->rating;
            $ratingDisplay = $rating ? str_repeat('⭐', $rating) : 'Not rated';

            $this->sendButtons(
                $session->phone,
                "✅ *Job completed!* 🎉\n\n" .
                "{$job->category_icon} {$job->title}\n" .
                "👤 {$job->assignedWorker->name}\n" .
                "💰 {$job->pay_display}\n" .
                "Rating: {$ratingDisplay}\n\n" .
                "Nanni! 🙏",
                [
                    ['id' => 'post_job', 'title' => '📝 Post New Job'],
                    ['id' => 'menu', 'title' => '📋 Menu'],
                ]
            );
        }
    }

    /**
     * Handle done step.
     */
    protected function handleDone(IncomingMessage $message, ConversationSession $session): void
    {
        $this->goToMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get current job from session.
     */
    protected function getCurrentJob(ConversationSession $session): ?JobPost
    {
        $jobId = $this->getTempData($session, 'job_id');

        if (!$jobId) {
            return null;
        }

        return JobPost::with(['assignedWorker.user', 'poster', 'category', 'verification'])
            ->find($jobId);
    }

    /**
     * Check if user is the worker for this job.
     */
    protected function isUserWorker(?User $user, JobPost $job): bool
    {
        if (!$user || !$job->assignedWorker) {
            return false;
        }

        return $job->assignedWorker->user_id === $user->id;
    }

    /**
     * Set the current step.
     */
    protected function setStep(ConversationSession $session, string $step): void
    {
        $this->sessionManager->setFlowStep($session, FlowType::JOB_EXECUTION, $step);
    }

    /**
     * Mask phone number for logging.
     */
    protected function maskPhone(string $phone): string
    {
        return substr($phone, 0, 4) . '****' . substr($phone, -4);
    }
}