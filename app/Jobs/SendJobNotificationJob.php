<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BadgeType;
use App\Models\JobApplication;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Services\Jobs\JobNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for sending job-related notifications asynchronously.
 *
 * Handles various notification types with retry support.
 *
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class SendJobNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 60;

    /**
     * Notification types.
     */
    public const TYPE_NEW_JOB = 'new_job';
    public const TYPE_APPLICATION = 'application';
    public const TYPE_SELECTION = 'selection';
    public const TYPE_REJECTION = 'rejection';
    public const TYPE_REMINDER = 'reminder';
    public const TYPE_CANCELLATION = 'cancellation';
    public const TYPE_COMPLETION = 'completion';
    public const TYPE_WEEKLY_EARNINGS = 'weekly_earnings';
    public const TYPE_BADGE_EARNED = 'badge_earned';

    /**
     * Create a new job instance.
     *
     * @param string $type Notification type
     * @param int $targetId Target ID (worker_id, job_id, or application_id)
     * @param array $data Additional data
     */
    public function __construct(
        public string $type,
        public int $targetId,
        public array $data = []
    ) {}

    /**
     * Execute the job.
     */
    public function handle(JobNotificationService $notificationService): void
    {
        try {
            match ($this->type) {
                self::TYPE_NEW_JOB => $this->handleNewJob($notificationService),
                self::TYPE_APPLICATION => $this->handleApplication($notificationService),
                self::TYPE_SELECTION => $this->handleSelection($notificationService),
                self::TYPE_REJECTION => $this->handleRejection($notificationService),
                self::TYPE_REMINDER => $this->handleReminder($notificationService),
                self::TYPE_CANCELLATION => $this->handleCancellation($notificationService),
                self::TYPE_COMPLETION => $this->handleCompletion($notificationService),
                self::TYPE_WEEKLY_EARNINGS => $this->handleWeeklyEarnings($notificationService),
                self::TYPE_BADGE_EARNED => $this->handleBadgeEarned($notificationService),
                default => Log::warning('Unknown notification type', ['type' => $this->type]),
            };

            Log::debug('Job notification processed', [
                'type' => $this->type,
                'target_id' => $this->targetId,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process job notification', [
                'type' => $this->type,
                'target_id' => $this->targetId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle new job notification.
     * targetId = job_id, data['worker_id'] = worker to notify
     */
    protected function handleNewJob(JobNotificationService $service): void
    {
        $job = JobPost::find($this->targetId);
        $workerId = $this->data['worker_id'] ?? null;

        if (!$job || !$workerId) {
            return;
        }

        $worker = JobWorker::find($workerId);
        if ($worker) {
            $service->sendNewJobNotification($job, $worker);
        }
    }

    /**
     * Handle application notification.
     * targetId = application_id
     */
    protected function handleApplication(JobNotificationService $service): void
    {
        $application = JobApplication::with(['jobPost', 'worker'])->find($this->targetId);

        if ($application) {
            $service->notifyPosterOfApplication($application);
        }
    }

    /**
     * Handle selection notification.
     * targetId = application_id
     */
    protected function handleSelection(JobNotificationService $service): void
    {
        $application = JobApplication::with(['jobPost', 'worker'])->find($this->targetId);

        if ($application) {
            $service->notifyWorkerSelected($application);
        }
    }

    /**
     * Handle rejection notification.
     * targetId = application_id
     */
    protected function handleRejection(JobNotificationService $service): void
    {
        $application = JobApplication::with(['jobPost', 'worker'])->find($this->targetId);

        if ($application) {
            $service->notifyWorkerRejected($application);
        }
    }

    /**
     * Handle reminder notification.
     * targetId = job_id
     */
    protected function handleReminder(JobNotificationService $service): void
    {
        $job = JobPost::with(['assignedWorker.user', 'poster'])->find($this->targetId);

        if ($job) {
            $service->sendJobReminder($job);
        }
    }

    /**
     * Handle cancellation notification.
     * targetId = job_id, data['reason'], data['cancelled_by']
     */
    protected function handleCancellation(JobNotificationService $service): void
    {
        $job = JobPost::with(['assignedWorker.user', 'poster', 'applications.worker.user'])
            ->find($this->targetId);

        if ($job) {
            $reason = $this->data['reason'] ?? 'Job cancelled';
            $cancelledBy = $this->data['cancelled_by'] ?? 'poster';
            $service->notifyJobCancelled($job, $reason, $cancelledBy);
        }
    }

    /**
     * Handle completion notification.
     * targetId = job_id
     */
    protected function handleCompletion(JobNotificationService $service): void
    {
        $job = JobPost::with(['assignedWorker.user', 'verification'])->find($this->targetId);

        if ($job) {
            $service->notifyJobCompleted($job);
        }
    }

    /**
     * Handle weekly earnings notification.
     * targetId = worker_id
     */
    protected function handleWeeklyEarnings(JobNotificationService $service): void
    {
        $worker = JobWorker::with('user')->find($this->targetId);

        if ($worker) {
            $service->sendWeeklyEarnings($worker);
        }
    }

    /**
     * Handle badge earned notification.
     * targetId = worker_id, data['badge_type']
     */
    protected function handleBadgeEarned(JobNotificationService $service): void
    {
        $worker = JobWorker::with('user')->find($this->targetId);
        $badgeType = $this->data['badge_type'] ?? null;

        if ($worker && $badgeType) {
            $badge = BadgeType::tryFrom($badgeType);
            if ($badge) {
                $service->sendBadgeEarned($worker, $badge);
            }
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Job notification job failed permanently', [
            'type' => $this->type,
            'target_id' => $this->targetId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags for the job.
     */
    public function tags(): array
    {
        return [
            'job-notification',
            'type:' . $this->type,
            'target:' . $this->targetId,
        ];
    }
}