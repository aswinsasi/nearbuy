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
use App\Services\WhatsApp\Messages\JobMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for job execution flow.
 *
 * Manages the entire job execution lifecycle from worker arrival to completion,
 * payment confirmation, and ratings.
 *
 * Flow Steps (from JobExecutionStep enum):
 *
 * Worker Side:
 * 1. ARRIVAL_PHOTO → Worker uploads photo when arriving at location
 * 2. WORK_IN_PROGRESS → Job is being executed
 * 3. COMPLETION_PHOTO → Worker uploads completion photo (optional)
 * 4. COMPLETION_CONFIRM_WORKER → Worker marks task as done
 * 5. CONFIRM_PAYMENT → Worker confirms payment received
 * 6. RATE_POSTER → Worker rates the task giver
 *
 * Task Giver Side:
 * 1. NOTIFY_POSTER_ARRIVAL → System notifies task giver worker has arrived
 * 2. COMPLETION_CONFIRM_POSTER → Task giver confirms task completed
 * 3. SELECT_PAYMENT_METHOD → Task giver selects payment method
 * 4. CONFIRM_PAYMENT → Task giver confirms payment made
 * 5. RATE_WORKER → Task giver rates worker 1-5 stars
 *
 * Entry Points:
 * - System triggers when job time approaches (scheduled job)
 * - Worker taps "Start Job" button
 * - Task giver receives arrival notification
 *
 * @srs-ref Section 3.5 - Job Verification & Completion
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobExecutionFlowHandler extends AbstractFlowHandler
{
    /**
     * Flow step constants (using enum values).
     */
    protected const STEP_ARRIVAL_PHOTO = 'arrival_photo';
    protected const STEP_NOTIFY_POSTER_ARRIVAL = 'notify_poster_arrival';
    protected const STEP_WORK_IN_PROGRESS = 'work_in_progress';
    protected const STEP_COMPLETION_PHOTO = 'completion_photo';
    protected const STEP_COMPLETION_CONFIRM_WORKER = 'completion_confirm_worker';
    protected const STEP_COMPLETION_CONFIRM_POSTER = 'completion_confirm_poster';
    protected const STEP_SELECT_PAYMENT_METHOD = 'select_payment_method';
    protected const STEP_CONFIRM_PAYMENT = 'confirm_payment';
    protected const STEP_RATE_WORKER = 'rate_worker';
    protected const STEP_RATE_POSTER = 'rate_poster';
    protected const STEP_COMPLETE = 'complete';

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

    protected function getExpectedInputType(string $step): string
    {
        $enumStep = JobExecutionStep::tryFrom($step);
        return $enumStep?->expectedInput() ?? 'button';
    }

    /**
     * Start the execution flow.
     */
    public function start(ConversationSession $session): void
    {
        $jobId = $this->getTemp($session, 'execution_job_id');

        if (!$jobId) {
            $this->showActiveJobOrError($session);
            return;
        }

        $job = JobPost::with(['assignedWorker', 'poster', 'category', 'verification'])->find($jobId);

        if (!$job) {
            $this->sendTextWithMenu($session->phone, "❌ Job not found.");
            $this->goToMainMenu($session);
            return;
        }

        // Determine if user is worker or poster
        $user = $this->getUser($session);
        $isWorker = $this->isUserWorker($user, $job);

        if ($isWorker) {
            $this->startWorkerFlow($session, $job);
        } else {
            $this->startPosterFlow($session, $job);
        }
    }

    /**
     * Start execution flow for a specific job.
     *
     * @param ConversationSession $session
     * @param int $jobId The job post ID
     */
    public function startWithJob(ConversationSession $session, int $jobId): void
    {
        $this->logInfo('Starting job execution flow', [
            'job_id' => $jobId,
            'phone' => $this->maskPhone($session->phone),
        ]);

        $job = JobPost::with(['assignedWorker.user', 'poster', 'category', 'verification'])->find($jobId);

        if (!$job) {
            $this->sendTextWithMenu($session->phone, "❌ Job not found.\n\nജോലി കണ്ടെത്താനായില്ല.");
            $this->goToMainMenu($session);
            return;
        }

        // Verify job is in correct status
        if (!in_array($job->status, [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ This job cannot be started.\n\nStatus: *{$job->status_display}*"
            );
            $this->goToMainMenu($session);
            return;
        }

        // Store context
        $this->clearTemp($session);
        $this->setTemp($session, 'execution_job_id', $job->id);
        $this->setTemp($session, 'job_title', $job->title);

        // Determine user role
        $user = $this->getUser($session);
        $isWorker = $this->isUserWorker($user, $job);
        $this->setTemp($session, 'is_worker', $isWorker);

        // Set flow
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::JOB_EXECUTION,
            self::STEP_ARRIVAL_PHOTO
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
        // Handle common navigation
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        // Handle execution-specific button clicks
        if ($this->handleExecutionButtonClick($message, $session)) {
            return;
        }

        $step = $session->current_step;
        $isWorker = $this->getTemp($session, 'is_worker', true);

        Log::debug('JobExecutionFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
            'is_worker' => $isWorker,
        ]);

        match ($step) {
            // Worker steps
            self::STEP_ARRIVAL_PHOTO => $this->handleArrivalPhoto($message, $session),
            self::STEP_WORK_IN_PROGRESS => $this->handleWorkInProgress($message, $session),
            self::STEP_COMPLETION_PHOTO => $this->handleCompletionPhoto($message, $session),
            self::STEP_COMPLETION_CONFIRM_WORKER => $this->handleWorkerCompletion($message, $session),
            self::STEP_RATE_POSTER => $this->handleRatePoster($message, $session),

            // Poster steps
            self::STEP_NOTIFY_POSTER_ARRIVAL => $this->handlePosterArrivalNotification($message, $session),
            self::STEP_COMPLETION_CONFIRM_POSTER => $this->handlePosterCompletion($message, $session),
            self::STEP_SELECT_PAYMENT_METHOD => $this->handleSelectPaymentMethod($message, $session),
            self::STEP_RATE_WORKER => $this->handleRateWorker($message, $session),

            // Shared steps
            self::STEP_CONFIRM_PAYMENT => $this->handleConfirmPayment($message, $session),
            self::STEP_COMPLETE => $this->handleComplete($message, $session),

            default => $this->start($session),
        };
    }

    /**
     * Re-prompt the current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = $session->current_step;
        $jobId = $this->getTemp($session, 'execution_job_id');
        $job = JobPost::with(['assignedWorker', 'poster', 'category', 'verification'])->find($jobId);

        if (!$job) {
            $this->start($session);
            return;
        }

        match ($step) {
            self::STEP_ARRIVAL_PHOTO => $this->promptArrivalPhoto($session, $job),
            self::STEP_WORK_IN_PROGRESS => $this->promptWorkInProgress($session, $job),
            self::STEP_COMPLETION_PHOTO => $this->promptCompletionPhoto($session, $job),
            self::STEP_COMPLETION_CONFIRM_WORKER => $this->promptWorkerCompletion($session, $job),
            self::STEP_COMPLETION_CONFIRM_POSTER => $this->promptPosterCompletion($session, $job),
            self::STEP_SELECT_PAYMENT_METHOD => $this->promptSelectPaymentMethod($session, $job),
            self::STEP_CONFIRM_PAYMENT => $this->promptConfirmPayment($session, $job),
            self::STEP_RATE_WORKER => $this->promptRateWorker($session, $job),
            self::STEP_RATE_POSTER => $this->promptRatePoster($session, $job),
            default => $this->start($session),
        };
    }

    /**
     * Handle execution-specific button clicks.
     */
    protected function handleExecutionButtonClick(IncomingMessage $message, ConversationSession $session): bool
    {
        $selectionId = $this->getSelectionId($message);

        if (!$selectionId) {
            return false;
        }

        // Start job execution
        if (preg_match('/^start_job_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->startWithJob($session, $jobId);
            return true;
        }

        // Skip arrival photo
        if (preg_match('/^skip_arrival_photo_(\d+)$/', $selectionId, $matches)) {
            $this->skipArrivalPhoto($session);
            return true;
        }

        // Confirm complete
        if (preg_match('/^confirm_complete_(\d+)$/', $selectionId, $matches)) {
            $this->confirmWorkerCompletion($session);
            return true;
        }

        // Send completion photo
        if (preg_match('/^send_completion_photo_(\d+)$/', $selectionId, $matches)) {
            $this->nextStep($session, self::STEP_COMPLETION_PHOTO);
            $this->promptCompletionPhoto($session, $this->getCurrentJob($session));
            return true;
        }

        // Rating buttons
        if (preg_match('/^rate_(\d)_(\d+)$/', $selectionId, $matches)) {
            $rating = (int) $matches[1];
            $jobId = (int) $matches[2];
            $this->processRating($session, $rating);
            return true;
        }

        // Payment method buttons
        if (preg_match('/^paid_(cash|upi|other)_(\d+)$/', $selectionId, $matches)) {
            $method = $matches[1];
            $this->processPaymentMethod($session, $method);
            return true;
        }

        // View active job
        if (preg_match('/^view_active_job_(\d+)$/', $selectionId, $matches)) {
            $jobId = (int) $matches[1];
            $this->startWithJob($session, $jobId);
            return true;
        }

        // Complete job button
        if (preg_match('/^complete_job_(\d+)$/', $selectionId, $matches)) {
            $this->nextStep($session, self::STEP_COMPLETION_CONFIRM_WORKER);
            $this->promptWorkerCompletion($session, $this->getCurrentJob($session));
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
        $this->setTemp($session, 'is_worker', true);

        // Check if job already has verification (resuming)
        $verification = $job->verification;

        if ($verification && $verification->is_arrival_verified) {
            // Already arrived, check current state
            if ($verification->is_worker_confirmed) {
                // Worker already confirmed, waiting for poster or payment
                $this->nextStep($session, self::STEP_CONFIRM_PAYMENT);
                $this->sendTextWithMenu(
                    $session->phone,
                    "⏳ *Waiting for confirmation*\n\n" .
                    "You've marked the job as complete. Waiting for task giver to confirm and pay."
                );
            } else {
                // Work in progress
                $this->nextStep($session, self::STEP_WORK_IN_PROGRESS);
                $this->promptWorkInProgress($session, $job);
            }
        } else {
            // Start fresh - request arrival photo
            $this->nextStep($session, self::STEP_ARRIVAL_PHOTO);
            $this->promptArrivalPhoto($session, $job);
        }
    }

    /**
     * Start poster-side flow.
     */
    protected function startPosterFlow(ConversationSession $session, JobPost $job): void
    {
        $this->setTemp($session, 'is_worker', false);

        $verification = $job->verification;

        if (!$verification) {
            // Job not started yet
            $this->sendTextWithMenu(
                $session->phone,
                "⏳ *Waiting for worker*\n\n" .
                "{$job->category->icon} {$job->title}\n\n" .
                "Worker hasn't started the job yet.\n" .
                "You'll be notified when they arrive."
            );
            $this->goToMainMenu($session);
            return;
        }

        // Check current state
        if ($verification->is_poster_confirmed && $verification->is_payment_confirmed) {
            // Already completed, show summary or rate
            if (!$verification->rating) {
                $this->nextStep($session, self::STEP_RATE_WORKER);
                $this->promptRateWorker($session, $job);
            } else {
                $this->showCompletionSummary($session, $job, false);
            }
        } elseif ($verification->is_worker_confirmed) {
            // Worker confirmed, poster needs to confirm and pay
            $this->nextStep($session, self::STEP_COMPLETION_CONFIRM_POSTER);
            $this->promptPosterCompletion($session, $job);
        } elseif ($verification->is_arrival_verified) {
            // Worker arrived, waiting for completion
            $this->sendTextWithMenu(
                $session->phone,
                "⏳ *Work in Progress*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "👤 {$job->assignedWorker->name}\n\n" .
                "Worker is currently working on the task.\n" .
                "You'll be notified when they mark it complete."
            );
        } else {
            // Waiting for arrival
            $this->sendTextWithMenu(
                $session->phone,
                "⏳ *Waiting for worker arrival*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "👤 {$job->assignedWorker->name}\n\n" .
                "You'll be notified when the worker arrives."
            );
        }
    }

    /**
     * Show active job or error if none.
     */
    protected function showActiveJobOrError(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sendTextWithMenu($session->phone, "❌ Please register first.");
            $this->goToMainMenu($session);
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

        // Check if user has active posted jobs
        $activePostedJob = JobPost::where('poster_user_id', $user->id)
            ->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])
            ->first();

        if ($activePostedJob) {
            $this->startWithJob($session, $activePostedJob->id);
            return;
        }

        $this->sendTextWithMenu(
            $session->phone,
            "ℹ️ *No active jobs*\n\n" .
            "You don't have any jobs in progress."
        );
        $this->goToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Flow Steps
    |--------------------------------------------------------------------------
    */

    /**
     * Step 1: Handle arrival photo.
     */
    protected function handleArrivalPhoto(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        // Check for skip
        $selectionId = $this->getSelectionId($message);
        if ($selectionId && str_starts_with($selectionId, 'skip_arrival')) {
            $this->skipArrivalPhoto($session);
            return;
        }

        // Check for image
        if ($message->isImage()) {
            $this->processArrivalPhoto($session, $message, $job);
            return;
        }

        // Invalid input
        $this->sendErrorWithOptions(
            $session->phone,
            "📸 Please send a photo to confirm arrival.\n\nഅല്ലെങ്കിൽ Skip ചെയ്യാം.",
            [
                ['id' => 'skip_arrival_photo_' . $job->id, 'title' => '⏭️ Skip'],
                self::MENU_BUTTON,
            ]
        );
    }

    protected function promptArrivalPhoto(ConversationSession $session, JobPost $job): void
    {
        $response = JobMessages::requestArrivalPhoto($job);
        $this->sendJobMessage($session->phone, $response);
    }

    protected function processArrivalPhoto(ConversationSession $session, IncomingMessage $message, JobPost $job): void
    {
        try {
            // Download and store photo
            $mediaId = $message->getMediaId();
            $result = $this->mediaService->downloadAndStore($mediaId, 'job-arrivals');

            // Extract URL from result array
            $photoUrl = $result['url'] ?? null;

            if (!$photoUrl && isset($result['path'])) {
                // Fallback to path if URL not provided
                $photoUrl = $result['path'];
            }

            // Get location if available
            $location = $this->getLocation($message);
            $latitude = $location['latitude'] ?? null;
            $longitude = $location['longitude'] ?? null;

            // Record arrival
            $verification = $this->executionService->startJobExecution($job);
            $this->executionService->recordArrival($verification, $photoUrl, $latitude, $longitude);

            $this->logInfo('Arrival photo recorded', [
                'job_id' => $job->id,
                'verification_id' => $verification->id,
            ]);

            // Notify poster
            $this->notifyPosterOfArrival($job, $verification);

            // Move to work in progress
            $this->nextStep($session, self::STEP_WORK_IN_PROGRESS);

            $this->sendButtons(
                $session->phone,
                "✅ *Arrival confirmed!*\n" .
                "*എത്തിയതായി സ്ഥിരീകരിച്ചു!*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "📍 {$job->location_display}\n\n" .
                "Task giver has been notified.\n" .
                "ടാസ്ക് ഗിവറിനെ അറിയിച്ചു.\n\n" .
                "_ജോലി കഴിഞ്ഞാൽ Complete അമർത്തുക_",
                [
                    ['id' => 'confirm_complete_' . $job->id, 'title' => '✅ ജോലി കഴിഞ്ഞു'],
                    ['id' => 'send_completion_photo_' . $job->id, 'title' => '📸 ഫോട്ടോ അയക്കുക'],
                    self::MENU_BUTTON,
                ]
            );

        } catch (\Exception $e) {
            $this->logError('Failed to process arrival photo', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed to upload photo. Please try again.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    ['id' => 'skip_arrival_photo_' . $job->id, 'title' => '⏭️ Skip'],
                ]
            );
        }
    }

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

        // Move to work in progress
        $this->nextStep($session, self::STEP_WORK_IN_PROGRESS);
        $this->promptWorkInProgress($session, $job);
    }

    /**
     * Step 2: Work in progress.
     */
    protected function handleWorkInProgress(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        $selectionId = $this->getSelectionId($message);

        // Handle completion buttons
        if ($selectionId === 'confirm_complete_' . $job->id || $selectionId === 'job_complete') {
            $this->nextStep($session, self::STEP_COMPLETION_CONFIRM_WORKER);
            $this->promptWorkerCompletion($session, $job);
            return;
        }

        // Handle completion photo
        if ($selectionId === 'send_completion_photo_' . $job->id || $selectionId === 'send_photo') {
            $this->nextStep($session, self::STEP_COMPLETION_PHOTO);
            $this->promptCompletionPhoto($session, $job);
            return;
        }

        // Check for image (completion photo sent directly)
        if ($message->isImage()) {
            $this->nextStep($session, self::STEP_COMPLETION_PHOTO);
            $this->handleCompletionPhoto($message, $session);
            return;
        }

        // Re-prompt
        $this->promptWorkInProgress($session, $job);
    }

    protected function promptWorkInProgress(ConversationSession $session, JobPost $job): void
    {
        $this->sendButtons(
            $session->phone,
            "⏳ *Work in Progress*\n" .
            "*ജോലി നടക്കുന്നു*\n\n" .
            "{$job->category->icon} {$job->title}\n" .
            "📍 {$job->location_display}\n" .
            "💰 {$job->pay_display}\n\n" .
            "When you're done, mark the job as complete.\n" .
            "ജോലി കഴിഞ്ഞാൽ Complete അമർത്തുക.",
            [
                ['id' => 'confirm_complete_' . $job->id, 'title' => '✅ ജോലി കഴിഞ്ഞു'],
                ['id' => 'send_completion_photo_' . $job->id, 'title' => '📸 ഫോട്ടോ അയക്കുക'],
                self::MENU_BUTTON,
            ],
            '⏳ Work in Progress'
        );
    }

    /**
     * Step 3: Completion photo.
     */
    protected function handleCompletionPhoto(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        // Check for skip
        $selectionId = $this->getSelectionId($message);
        if ($selectionId === 'skip_completion_photo' || $selectionId === 'skip') {
            $this->nextStep($session, self::STEP_COMPLETION_CONFIRM_WORKER);
            $this->promptWorkerCompletion($session, $job);
            return;
        }

        // Check for image
        if ($message->isImage()) {
            $this->processCompletionPhoto($session, $message, $job);
            return;
        }

        // Invalid input
        $this->sendErrorWithOptions(
            $session->phone,
            "📸 Please send a completion photo.\n\nഅല്ലെങ്കിൽ Skip ചെയ്യാം.",
            [
                ['id' => 'skip_completion_photo', 'title' => '⏭️ Skip'],
                self::MENU_BUTTON,
            ]
        );
    }

    protected function promptCompletionPhoto(ConversationSession $session, JobPost $job): void
    {
        $this->sendButtons(
            $session->phone,
            "📸 *Completion Photo*\n" .
            "*പൂർത്തിയായ ഫോട്ടോ*\n\n" .
            "{$job->category->icon} {$job->title}\n\n" .
            "Please send a photo showing the completed work.\n" .
            "പണി കഴിഞ്ഞതിന്റെ ഫോട്ടോ അയക്കുക.\n\n" .
            "_ഫോട്ടോ ഇല്ലെങ്കിൽ Skip ചെയ്യാം_",
            [
                ['id' => 'skip_completion_photo', 'title' => '⏭️ Skip'],
                self::MENU_BUTTON,
            ],
            '📸 Completion Photo'
        );
    }

    protected function processCompletionPhoto(ConversationSession $session, IncomingMessage $message, JobPost $job): void
    {
        try {
            // Download and store photo
            $mediaId = $message->getMediaId();
            $result = $this->mediaService->downloadAndStore($mediaId, 'job-completions');

            // Extract URL from result array
            $photoUrl = $result['url'] ?? null;

            if (!$photoUrl && isset($result['path'])) {
                // Fallback to path if URL not provided
                $photoUrl = $result['path'];
            }

            // Record completion photo
            $verification = $job->verification;
            if ($verification) {
                $this->executionService->recordCompletion($verification, $photoUrl);
            }

            $this->setTemp($session, 'completion_photo_url', $photoUrl);

            // Move to worker confirmation
            $this->nextStep($session, self::STEP_COMPLETION_CONFIRM_WORKER);
            $this->promptWorkerCompletion($session, $job);

        } catch (\Exception $e) {
            $this->logError('Failed to process completion photo', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed to upload photo. Please try again.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    ['id' => 'skip_completion_photo', 'title' => '⏭️ Skip'],
                ]
            );
        }
    }

    /**
     * Step 4: Worker confirms completion.
     */
    protected function handleWorkerCompletion(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'confirm_complete' || $selectionId === 'yes' || 
            $selectionId === 'confirm_complete_' . $job->id) {
            $this->confirmWorkerCompletion($session);
            return;
        }

        if ($selectionId === 'back' || $selectionId === 'not_done') {
            $this->nextStep($session, self::STEP_WORK_IN_PROGRESS);
            $this->promptWorkInProgress($session, $job);
            return;
        }

        // Re-prompt
        $this->promptWorkerCompletion($session, $job);
    }

    protected function promptWorkerCompletion(ConversationSession $session, JobPost $job): void
    {
        $response = JobMessages::requestCompletionConfirmation($job);
        $this->sendJobMessage($session->phone, $response);
    }

    protected function confirmWorkerCompletion(ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        try {
            $verification = $job->verification;

            if (!$verification) {
                $verification = $this->executionService->startJobExecution($job);
            }

            // Confirm by worker
            $this->executionService->confirmCompletion($verification, 'worker');

            $this->logInfo('Worker confirmed completion', [
                'job_id' => $job->id,
                'worker_id' => $job->assigned_worker_id,
            ]);

            // Notify poster for confirmation
            $this->notifyPosterForCompletion($job, $verification);

            // Worker waits for payment confirmation
            $this->nextStep($session, self::STEP_CONFIRM_PAYMENT);

            $this->sendTextWithMenu(
                $session->phone,
                "✅ *Marked as Complete!*\n" .
                "*പൂർത്തിയായി എന്ന് രേഖപ്പെടുത്തി!*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "💰 {$job->pay_display}\n\n" .
                "Task giver has been notified to confirm and pay.\n" .
                "ടാസ്ക് ഗിവറിനോട് സ്ഥിരീകരിക്കാനും പേയ്മെന്റ് ചെയ്യാനും ആവശ്യപ്പെട്ടു.\n\n" .
                "⏳ _Waiting for confirmation..._"
            );

        } catch (\Exception $e) {
            $this->logError('Failed to confirm worker completion', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed to confirm completion: " . $e->getMessage(),
                [['id' => 'retry', 'title' => '🔄 Try Again'], self::MENU_BUTTON]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Poster Flow Steps
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

        // Send arrival photo if available
        if ($verification->arrival_photo_url) {
            $this->sendImage(
                $poster->phone,
                $verification->arrival_photo_url,
                "📸 Worker arrival photo"
            );
        }

        $response = JobMessages::workerArrived($verification);
        $this->sendJobMessage($poster->phone, $response);

        $this->logInfo('Poster notified of arrival', [
            'job_id' => $job->id,
            'poster_phone' => $this->maskPhone($poster->phone),
        ]);
    }

    /**
     * Notify poster for completion confirmation.
     */
    protected function notifyPosterForCompletion(JobPost $job, JobVerification $verification): void
    {
        $poster = $job->poster;

        if (!$poster || !$poster->phone) {
            return;
        }

        // Send completion photo if available
        if ($verification->completion_photo_url) {
            $this->sendImage(
                $poster->phone,
                $verification->completion_photo_url,
                "📸 Completion photo"
            );
        }

        $worker = $job->assignedWorker;

        $this->sendButtons(
            $poster->phone,
            "✅ *Worker Marked Complete!*\n" .
            "*പണിക്കാരൻ പൂർത്തിയായി എന്ന് പറഞ്ഞു!*\n\n" .
            "{$job->category->icon} {$job->title}\n" .
            "👤 {$worker->name}\n" .
            "💰 {$job->pay_display}\n\n" .
            "Please verify the work is done and confirm payment.",
            [
                ['id' => 'confirm_work_done_' . $job->id, 'title' => '✅ പണി ശരിയാണ്'],
                ['id' => 'dispute_work_' . $job->id, 'title' => '❌ പ്രശ്നം ഉണ്ട്'],
                ['id' => 'call_worker_' . $worker->id, 'title' => '📞 വിളിക്കുക'],
            ],
            '✅ Confirm Work'
        );
    }

    /**
     * Handle poster arrival notification response.
     */
    protected function handlePosterArrivalNotification(IncomingMessage $message, ConversationSession $session): void
    {
        // Poster acknowledged arrival, nothing special needed
        $this->sendTextWithMenu(
            $session->phone,
            "👍 *Noted!*\n\nYou'll be notified when the work is complete."
        );
    }

    /**
     * Handle poster completion confirmation.
     */
    protected function handlePosterCompletion(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        $selectionId = $this->getSelectionId($message);

        // Confirm work done
        if ($selectionId === 'confirm_work_done_' . $job->id || $selectionId === 'yes' || $selectionId === 'confirm') {
            $this->confirmPosterCompletion($session, $job);
            return;
        }

        // Dispute
        if ($selectionId === 'dispute_work_' . $job->id || $selectionId === 'dispute') {
            $this->handleDispute($session, $job);
            return;
        }

        // Re-prompt
        $this->promptPosterCompletion($session, $job);
    }

    protected function promptPosterCompletion(ConversationSession $session, JobPost $job): void
    {
        $worker = $job->assignedWorker;

        $this->sendButtons(
            $session->phone,
            "✅ *Confirm Work Completion*\n" .
            "*പണി പൂർത്തിയായോ?*\n\n" .
            "{$job->category->icon} {$job->title}\n" .
            "👤 {$worker->name}\n\n" .
            "Is the work done satisfactorily?",
            [
                ['id' => 'confirm_work_done_' . $job->id, 'title' => '✅ ശരിയാണ്'],
                ['id' => 'dispute_work_' . $job->id, 'title' => '❌ പ്രശ്നം'],
                self::MENU_BUTTON,
            ],
            '✅ Confirm'
        );
    }

    protected function confirmPosterCompletion(ConversationSession $session, JobPost $job): void
    {
        try {
            $verification = $job->verification;

            if (!$verification) {
                throw new \Exception('Verification record not found');
            }

            // Confirm by poster
            $this->executionService->confirmCompletion($verification, 'poster');

            // Move to payment
            $this->nextStep($session, self::STEP_SELECT_PAYMENT_METHOD);
            $this->promptSelectPaymentMethod($session, $job);

        } catch (\Exception $e) {
            $this->logError('Failed to confirm poster completion', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed: " . $e->getMessage(),
                [['id' => 'retry', 'title' => '🔄 Try Again'], self::MENU_BUTTON]
            );
        }
    }

    /**
     * Handle selecting payment method.
     */
    protected function handleSelectPaymentMethod(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        $selectionId = $this->getSelectionId($message);

        // Payment method buttons
        if (preg_match('/^paid_(cash|upi|other)/', $selectionId, $matches)) {
            $method = $matches[1];
            $this->processPaymentMethod($session, $method);
            return;
        }

        // Re-prompt
        $this->promptSelectPaymentMethod($session, $job);
    }

    protected function promptSelectPaymentMethod(ConversationSession $session, JobPost $job): void
    {
        $response = JobMessages::requestPaymentConfirmation($job);
        $this->sendJobMessage($session->phone, $response);
    }

    protected function processPaymentMethod(ConversationSession $session, string $method): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        try {
            $paymentMethod = match($method) {
                'cash' => PaymentMethod::CASH,
                'upi' => PaymentMethod::UPI,
                'other' => PaymentMethod::OTHER,
                default => PaymentMethod::CASH,
            };

            $verification = $job->verification;

            if (!$verification) {
                throw new \Exception('Verification record not found');
            }

            // Confirm payment
            $this->executionService->confirmPayment($verification, $paymentMethod);

            $this->logInfo('Payment confirmed', [
                'job_id' => $job->id,
                'method' => $paymentMethod->value,
            ]);

            // Notify worker of payment
            $this->notifyWorkerOfPayment($job, $paymentMethod);

            // Move to rating
            $this->nextStep($session, self::STEP_RATE_WORKER);
            $this->promptRateWorker($session, $job);

        } catch (\Exception $e) {
            $this->logError('Failed to confirm payment', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed to confirm payment: " . $e->getMessage(),
                [['id' => 'retry', 'title' => '🔄 Try Again'], self::MENU_BUTTON]
            );
        }
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

        $methodDisplay = $method->display();

        $this->sendButtons(
            $worker->user->phone,
            "💰 *Payment Confirmed!*\n" .
            "*പേയ്മെന്റ് സ്ഥിരീകരിച്ചു!*\n\n" .
            "{$job->category->icon} {$job->title}\n" .
            "💵 Amount: *{$job->pay_display}*\n" .
            "💳 Method: {$methodDisplay}\n\n" .
            "Task giver has confirmed payment! 🎉",
            [
                ['id' => 'rate_poster_' . $job->id, 'title' => '⭐ Rate Task Giver'],
                ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
                self::MENU_BUTTON,
            ]
        );
    }

    /**
     * Handle rating worker.
     */
    protected function handleRateWorker(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        $selectionId = $this->getSelectionId($message);

        // Rating selection
        if (preg_match('/^rate_(\d)/', $selectionId, $matches)) {
            $rating = (int) $matches[1];
            $this->processRating($session, $rating);
            return;
        }

        // Skip rating
        if ($selectionId === 'skip_rating' || $selectionId === 'skip') {
            $this->completeJobFlow($session, $job);
            return;
        }

        // Re-prompt
        $this->promptRateWorker($session, $job);
    }

    protected function promptRateWorker(ConversationSession $session, JobPost $job): void
    {
        $worker = $job->assignedWorker;
        $response = JobMessages::requestWorkerRating($job, $worker);
        $this->sendJobMessage($session->phone, $response);
    }

    protected function processRating(ConversationSession $session, int $rating): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        $isWorker = $this->getTemp($session, 'is_worker', false);

        try {
            if ($isWorker) {
                // Worker rating poster
                $this->executionService->ratePoster($job, $rating);
                $this->logInfo('Worker rated poster', ['job_id' => $job->id, 'rating' => $rating]);
            } else {
                // Poster rating worker
                $this->executionService->rateWorker($job, $rating);
                $this->logInfo('Poster rated worker', ['job_id' => $job->id, 'rating' => $rating]);
            }

            // Complete the flow
            $this->completeJobFlow($session, $job);

        } catch (\Exception $e) {
            $this->logError('Failed to save rating', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
            ]);

            $this->sendTextWithMenu(
                $session->phone,
                "⚠️ Couldn't save rating, but job is complete!"
            );

            $this->completeJobFlow($session, $job);
        }
    }

    /**
     * Handle rating poster (by worker).
     */
    protected function handleRatePoster(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if (!$job) {
            $this->start($session);
            return;
        }

        $selectionId = $this->getSelectionId($message);

        // Rating selection
        if (preg_match('/^rate_poster_(\d)/', $selectionId, $matches)) {
            $rating = (int) $matches[1];
            $this->processRating($session, $rating);
            return;
        }

        // Skip
        if ($selectionId === 'skip_rating' || $selectionId === 'skip') {
            $this->completeJobFlow($session, $job);
            return;
        }

        // Re-prompt
        $this->promptRatePoster($session, $job);
    }

    protected function promptRatePoster(ConversationSession $session, JobPost $job): void
    {
        $this->sendList(
            $session->phone,
            "⭐ *Rate Task Giver*\n" .
            "*ടാസ്ക് ഗിവറിനെ റേറ്റ് ചെയ്യുക*\n\n" .
            "How was your experience with the task giver?",
            'Rate',
            [
                [
                    'title' => 'Rating',
                    'rows' => [
                        ['id' => 'rate_poster_5', 'title' => '⭐⭐⭐⭐⭐ Excellent', 'description' => 'Great experience!'],
                        ['id' => 'rate_poster_4', 'title' => '⭐⭐⭐⭐ Very Good', 'description' => 'Good experience'],
                        ['id' => 'rate_poster_3', 'title' => '⭐⭐⭐ Good', 'description' => 'Satisfactory'],
                        ['id' => 'rate_poster_2', 'title' => '⭐⭐ Fair', 'description' => 'Could be better'],
                        ['id' => 'rate_poster_1', 'title' => '⭐ Poor', 'description' => 'Not satisfied'],
                        ['id' => 'skip_rating', 'title' => '⏭️ Skip', 'description' => 'Skip rating'],
                    ],
                ],
            ],
            '⭐ Rate'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Shared Steps
    |--------------------------------------------------------------------------
    */

    /**
     * Handle payment confirmation (both parties).
     */
    protected function handleConfirmPayment(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);
        $isWorker = $this->getTemp($session, 'is_worker', true);

        if (!$job) {
            $this->start($session);
            return;
        }

        // For worker, this is just waiting
        if ($isWorker) {
            $verification = $job->verification;

            if ($verification && $verification->is_payment_confirmed) {
                // Payment confirmed, proceed to rating
                $this->nextStep($session, self::STEP_RATE_POSTER);
                $this->promptRatePoster($session, $job);
            } else {
                $this->sendTextWithMenu(
                    $session->phone,
                    "⏳ *Waiting for payment confirmation...*\n\n" .
                    "Task giver hasn't confirmed payment yet.\n" .
                    "You'll be notified when they do."
                );
            }
            return;
        }

        // For poster, handled in handleSelectPaymentMethod
        $this->promptSelectPaymentMethod($session, $job);
    }

    protected function promptConfirmPayment(ConversationSession $session, JobPost $job): void
    {
        $isWorker = $this->getTemp($session, 'is_worker', true);

        if ($isWorker) {
            $this->sendTextWithMenu(
                $session->phone,
                "⏳ *Waiting for payment...*\n\n" .
                "{$job->category->icon} {$job->title}\n" .
                "💰 {$job->pay_display}\n\n" .
                "Task giver is confirming payment."
            );
        } else {
            $this->promptSelectPaymentMethod($session, $job);
        }
    }

    /**
     * Handle completion.
     */
    protected function handleComplete(IncomingMessage $message, ConversationSession $session): void
    {
        $job = $this->getCurrentJob($session);

        if ($job) {
            $isWorker = $this->getTemp($session, 'is_worker', true);
            $this->showCompletionSummary($session, $job, $isWorker);
        }

        $this->goToMainMenu($session);
    }

    /**
     * Complete the job flow.
     */
    protected function completeJobFlow(ConversationSession $session, JobPost $job): void
    {
        $isWorker = $this->getTemp($session, 'is_worker', true);

        try {
            // Complete job if not already
            $this->executionService->completeJob($job);

            // Show completion summary
            $this->showCompletionSummary($session, $job, $isWorker);

        } catch (\Exception $e) {
            $this->logError('Error completing job flow', [
                'error' => $e->getMessage(),
                'job_id' => $job->id,
            ]);

            $this->showCompletionSummary($session, $job, $isWorker);
        }

        // Clear temp and go to menu
        $this->clearTemp($session);
        $this->nextStep($session, self::STEP_COMPLETE);
    }

    /**
     * Show completion summary.
     */
    protected function showCompletionSummary(ConversationSession $session, JobPost $job, bool $isWorker): void
    {
        $response = JobMessages::jobCompleted($job, $isWorker);
        $this->sendJobMessage($session->phone, $response);

        // For worker, also show earnings if badges earned
        if ($isWorker) {
            $worker = $job->assignedWorker;
            if ($worker) {
                $badges = $this->executionService->checkAndAwardBadges($worker);
                
                if (!empty($badges)) {
                    $badgeList = implode("\n", array_map(fn($b) => "🏅 {$b}", $badges));
                    $this->sendText(
                        $session->phone,
                        "🎉 *New Badge(s) Earned!*\n\n{$badgeList}"
                    );
                }
            }
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Dispute Handling
    |--------------------------------------------------------------------------
    */

    /**
     * Handle dispute from poster.
     */
    protected function handleDispute(ConversationSession $session, JobPost $job): void
    {
        $this->sendButtons(
            $session->phone,
            "⚠️ *Report Issue*\n\n" .
            "What's the problem with the work?\n\n" .
            "We'll help resolve this.",
            [
                ['id' => 'dispute_incomplete_' . $job->id, 'title' => '❌ Not Complete'],
                ['id' => 'dispute_quality_' . $job->id, 'title' => '⚠️ Poor Quality'],
                ['id' => 'call_worker_' . $job->assigned_worker_id, 'title' => '📞 Call Worker'],
            ]
        );

        // Log dispute
        $this->logInfo('Poster raised dispute concern', [
            'job_id' => $job->id,
            'poster_id' => $job->poster_user_id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get the current job from session.
     */
    protected function getCurrentJob(ConversationSession $session): ?JobPost
    {
        $jobId = $this->getTemp($session, 'execution_job_id');

        if (!$jobId) {
            return null;
        }

        return JobPost::with(['assignedWorker.user', 'poster', 'category', 'verification'])->find($jobId);
    }

    /**
     * Check if user is the worker for this job.
     */
    protected function isUserWorker(?User $user, JobPost $job): bool
    {
        if (!$user) {
            return false;
        }

        $worker = $job->assignedWorker;

        if (!$worker) {
            return false;
        }

        return $worker->user_id === $user->id;
    }

    /**
     * Send a JobMessages response via WhatsApp.
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
                $this->sendText($phone, $response['text'] ?? $response['body'] ?? 'Message sent.');
        }
    }
}