<?php

namespace App\Jobs;

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
 * Handles various job notification types with retry support.
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
     * Notification types supported.
     */
    public const TYPE_NEW_JOB = 'new_job';
    public const TYPE_APPLICATION = 'application';
    public const TYPE_SELECTION = 'selection';
    public const TYPE_REJECTION = 'rejection';
    public const TYPE_REMINDER = 'reminder';
    public const TYPE_CANCELLATION = 'cancellation';
    public const TYPE_WEEKLY_EARNINGS = 'weekly_earnings';
    public const TYPE_BADGE_EARNED = 'badge_earned';

    /**
     * Create a new job instance.
     *
     * @param string $type Notification type
     * @param int $targetId Target ID (worker_id, job_id, or application_id based on type)
     * @param array $data Additional data for the notification
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
                self::TYPE_NEW_JOB => $this->handleNewJobNotification($notificationService),
                self::TYPE_APPLICATION => $this->handleApplicationNotification($notificationService),
                self::TYPE_SELECTION => $this->handleSelectionNotification($notificationService),
                self::TYPE_REJECTION => $this->handleRejectionNotification($notificationService),
                self::TYPE_REMINDER => $this->handleReminderNotification($notificationService),
                self::TYPE_CANCELLATION => $this->handleCancellationNotification($notificationService),
                self::TYPE_WEEKLY_EARNINGS => $this->handleWeeklyEarningsNotification($notificationService),
                self::TYPE_BADGE_EARNED => $this->handleBadgeEarnedNotification($notificationService),
                default => Log::warning('Unknown job notification type', ['type' => $this->type]),
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
     */
    protected function handleNewJobNotification(JobNotificationService $service): void
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
     */
    protected function handleApplicationNotification(JobNotificationService $service): void
    {
        $application = \App\Models\JobApplication::with(['jobPost', 'worker'])->find($this->targetId);

        if ($application) {
            $service->notifyPosterOfApplication($application);
        }
    }

    /**
     * Handle selection notification.
     */
    protected function handleSelectionNotification(JobNotificationService $service): void
    {
        $application = \App\Models\JobApplication::with(['jobPost', 'worker'])->find($this->targetId);

        if ($application) {
            $service->notifyWorkerSelected($application);
        }
    }

    /**
     * Handle rejection notification.
     */
    protected function handleRejectionNotification(JobNotificationService $service): void
    {
        $application = \App\Models\JobApplication::with(['jobPost', 'worker'])->find($this->targetId);

        if ($application) {
            $service->notifyWorkerRejected($application);
        }
    }

    /**
     * Handle reminder notification.
     */
    protected function handleReminderNotification(JobNotificationService $service): void
    {
        $job = JobPost::with(['assignedWorker', 'user'])->find($this->targetId);

        if ($job) {
            $service->sendJobReminder($job);
        }
    }

    /**
     * Handle cancellation notification.
     */
    protected function handleCancellationNotification(JobNotificationService $service): void
    {
        $job = JobPost::with(['assignedWorker', 'user', 'applications.worker'])->find($this->targetId);
        $reason = $this->data['reason'] ?? 'Job cancelled';
        $cancelledBy = $this->data['cancelled_by'] ?? 'poster';

        if ($job) {
            $service->notifyJobCancelled($job, $reason, $cancelledBy);
        }
    }

    /**
     * Handle weekly earnings notification.
     */
    protected function handleWeeklyEarningsNotification(JobNotificationService $service): void
    {
        $worker = JobWorker::with('user')->find($this->targetId);

        if ($worker) {
            $service->sendWeeklyEarnings($worker);
        }
    }

    /**
     * Handle badge earned notification.
     */
    protected function handleBadgeEarnedNotification(JobNotificationService $service): void
    {
        $worker = JobWorker::with('user')->find($this->targetId);
        $badgeType = $this->data['badge_type'] ?? null;

        if ($worker && $badgeType) {
            $badge = \App\Enums\BadgeType::tryFrom($badgeType);
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
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return ['job-notification', 'type:' . $this->type, 'target:' . $this->targetId];
    }
}