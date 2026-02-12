<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\BadgeType;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Jobs\SendJobNotificationJob;
use App\Jobs\SendWhatsAppMessage;
use App\Models\JobApplication;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service for job-related notifications.
 *
 * NP-015: Notification includes all job details + distance
 * NP-016: Social proof count in notifications ("X workers already applied")
 *
 * Format: MAX 5 lines + 2-3 buttons
 *
 * @srs-ref NP-015, NP-016
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobNotificationService
{
    /**
     * Maximum workers to notify per batch.
     */
    public const BATCH_SIZE = 50;

    public function __construct(
        protected WhatsAppService $whatsApp,
        protected JobMatchingService $matchingService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | New Job Notifications (NP-015, NP-016)
    |--------------------------------------------------------------------------
    */

    /**
     * Notify matching workers about a new job posting.
     *
     * NP-014: Notify within 5km (expands to 10km if <3 workers)
     *
     * @param JobPost $job
     * @return int Number of workers notified
     */
    public function notifyWorkersOfNewJob(JobPost $job): int
    {
        if ($job->status !== JobStatus::OPEN) {
            return 0;
        }

        // Find matching workers (NP-014 compliant)
        $workers = $this->matchingService->findMatchingWorkers($job);

        if ($workers->isEmpty()) {
            Log::info('No matching workers for job', ['job_id' => $job->id]);
            return 0;
        }

        $notifiedCount = 0;

        // Process in batches
        $workers->chunk(self::BATCH_SIZE)->each(function ($batch) use ($job, &$notifiedCount) {
            foreach ($batch as $worker) {
                $this->sendNewJobNotification($job, $worker);
                $notifiedCount++;
            }
        });

        // Update job with notification count
        $job->update(['workers_notified' => $notifiedCount]);

        Log::info('Workers notified of new job', [
            'job_id' => $job->id,
            'count' => $notifiedCount,
        ]);

        return $notifiedCount;
    }

    /**
     * Send new job notification to a specific worker.
     *
     * NP-015: Includes job details + distance
     * NP-016: Social proof count ("X workers notified")
     *
     * Format: MAX 5 lines + 2-3 buttons
     *
     * @param JobPost $job
     * @param JobWorker $worker
     */
    public function sendNewJobNotification(JobPost $job, JobWorker $worker): void
    {
        $user = $worker->user;

        if (!$user || !$user->phone) {
            return;
        }

        // Calculate distance
        $distance = $this->matchingService->calculateDistance(
            (float) $worker->latitude,
            (float) $worker->longitude,
            (float) $job->latitude,
            (float) $job->longitude
        );
        $distanceDisplay = $this->matchingService->formatDistance($distance);

        // Get social proof count (NP-016)
        $workersNotified = $job->workers_notified ?? 0;
        $socialProof = $workersNotified > 1 ? " • 👥{$workersNotified}" : '';

        // Build compact notification (MAX 5 lines - NP-015)
        $catIcon = $job->category?->icon ?? '📋';
        $pay = '₹' . number_format((float) $job->pay_amount);
        $dateDisplay = $job->job_date ? $job->job_date->format('d M') : 'Flexible';

        // 5 lines max:
        // Line 1: Title with icon
        // Line 2: Pay + Distance
        // Line 3: Location
        // Line 4: Date/Time
        // Line 5: Social proof
        $message = "🆕 *{$catIcon} {$job->title}*\n" .
            "💰 {$pay} • 📍 {$distanceDisplay}\n" .
            "📍 {$job->location_name}\n" .
            "📅 {$dateDisplay}{$socialProof}";

        // 2-3 buttons
        $buttons = [
            ['id' => 'apply_' . $job->id, 'title' => '✅ Apply'],
            ['id' => 'skip_' . $job->id, 'title' => '⏭️ Skip'],
        ];

        // Queue notification
        SendWhatsAppMessage::dispatch(
            $user->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');

        Log::debug('Job notification sent', [
            'job_id' => $job->id,
            'worker_id' => $worker->id,
            'distance' => $distanceDisplay,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Application Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify job poster about a new application.
     *
     * Format: MAX 5 lines + 2-3 buttons
     * Includes social proof (NP-016): "X applications pending"
     *
     * @param JobApplication $application
     */
    public function notifyPosterOfApplication(JobApplication $application): void
    {
        $job = $application->jobPost;
        $poster = $job->poster;
        $worker = $application->worker;

        if (!$poster || !$poster->phone) {
            return;
        }

        // Social proof (NP-016)
        $pendingCount = $job->applications()->where('status', 'pending')->count();
        $socialProof = $pendingCount > 1 ? "\n👥 {$pendingCount} applications" : '';

        // Worker rating
        $rating = $worker->rating ? "⭐{$worker->rating}" : '🆕';
        $jobs = $worker->jobs_completed ?? 0;

        // Compact notification (5 lines max)
        $message = "📩 *New Application!*\n" .
            "📋 {$job->title}\n" .
            "👤 {$worker->name} ({$rating} • {$jobs} jobs){$socialProof}";

        $buttons = [
            ['id' => 'applicant_' . $application->id, 'title' => '👁️ View'],
            ['id' => 'accept_' . $application->id, 'title' => '✅ Accept'],
        ];

        SendWhatsAppMessage::dispatch(
            $poster->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');

        Log::debug('Poster notified of application', [
            'job_id' => $job->id,
            'application_id' => $application->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Selection/Rejection Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify worker that they were selected.
     *
     * @param JobApplication $application
     */
    public function notifyWorkerSelected(JobApplication $application): void
    {
        $job = $application->jobPost;
        $worker = $application->worker;
        $user = $worker->user;

        if (!$user || !$user->phone) {
            return;
        }

        $poster = $job->poster;
        $pay = '₹' . number_format((float) $job->pay_amount);
        $dateDisplay = $job->job_date ? $job->job_date->format('d M') : 'Flexible';

        // Compact: 5 lines
        $message = "🎉 *Job kitiyi!*\n" .
            "📋 {$job->title}\n" .
            "💰 {$pay} • 📅 {$dateDisplay}\n" .
            "📍 {$job->location_name}\n" .
            "📞 {$poster->phone}";

        $buttons = [
            ['id' => 'view_job_' . $job->id, 'title' => '📋 Details'],
            ['id' => 'menu', 'title' => '📋 Menu'],
        ];

        SendWhatsAppMessage::dispatch(
            $user->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');

        Log::info('Worker notified of selection', [
            'job_id' => $job->id,
            'worker_id' => $worker->id,
        ]);
    }

    /**
     * Notify worker that their application was rejected.
     *
     * @param JobApplication $application
     */
    public function notifyWorkerRejected(JobApplication $application): void
    {
        $job = $application->jobPost;
        $worker = $application->worker;
        $user = $worker->user;

        if (!$user || !$user->phone) {
            return;
        }

        // Compact: 3 lines
        $message = "📋 *Application Update*\n" .
            "{$job->title} - not selected\n" .
            "New jobs vannaal ariyikkam! 💪";

        $buttons = [
            ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
            ['id' => 'menu', 'title' => '📋 Menu'],
        ];

        SendWhatsAppMessage::dispatch(
            $user->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');
    }

    /*
    |--------------------------------------------------------------------------
    | Job Reminders
    |--------------------------------------------------------------------------
    */

    /**
     * Send job reminder (1 hour before).
     *
     * @param JobPost $job
     */
    public function sendJobReminder(JobPost $job): void
    {
        if ($job->status !== JobStatus::ASSIGNED) {
            return;
        }

        $worker = $job->assignedWorker;
        if (!$worker) {
            return;
        }

        // Notify worker
        $this->sendWorkerReminder($job, $worker);

        // Notify poster
        $this->sendPosterReminder($job);

        // Mark reminder sent
        $job->update(['reminder_sent_at' => now()]);

        Log::info('Job reminders sent', ['job_id' => $job->id]);
    }

    /**
     * Send reminder to worker.
     */
    protected function sendWorkerReminder(JobPost $job, JobWorker $worker): void
    {
        $user = $worker->user;
        if (!$user || !$user->phone) {
            return;
        }

        $timeDisplay = $job->job_time ?? 'Soon';
        $poster = $job->poster;

        // Compact: 5 lines
        $message = "⏰ *Job Reminder!*\n" .
            "📋 {$job->title}\n" .
            "📍 {$job->location_name}\n" .
            "🕐 {$timeDisplay}\n" .
            "📞 {$poster->phone}";

        $buttons = [
            ['id' => 'start_job_' . $job->id, 'title' => '🚀 Start'],
            ['id' => 'get_directions_' . $job->id, 'title' => '📍 Directions'],
        ];

        SendWhatsAppMessage::dispatch(
            $user->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');
    }

    /**
     * Send reminder to poster.
     */
    protected function sendPosterReminder(JobPost $job): void
    {
        $poster = $job->poster;
        $worker = $job->assignedWorker;

        if (!$poster || !$poster->phone || !$worker) {
            return;
        }

        $timeDisplay = $job->job_time ?? 'Soon';
        $rating = $worker->rating ? "⭐{$worker->rating}" : '';

        // Compact: 4 lines
        $message = "⏰ *Job Reminder!*\n" .
            "📋 {$job->title}\n" .
            "👷 {$worker->name} {$rating}\n" .
            "📞 {$worker->user->phone}";

        $buttons = [
            ['id' => 'contact_worker_' . $job->id, 'title' => '📞 Contact'],
        ];

        SendWhatsAppMessage::dispatch(
            $poster->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');
    }

    /**
     * Get jobs needing reminders (1 hour before).
     */
    public function getJobsNeedingReminders(): Collection
    {
        $reminderTime = now()->addMinutes(60);

        return JobPost::where('status', JobStatus::ASSIGNED)
            ->whereDate('job_date', today())
            ->whereNotNull('job_time')
            ->whereTime('job_time', '>=', now()->format('H:i:s'))
            ->whereTime('job_time', '<=', $reminderTime->format('H:i:s'))
            ->whereNull('reminder_sent_at')
            ->with(['assignedWorker.user', 'poster', 'category'])
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify about job cancellation.
     *
     * @param JobPost $job
     * @param string $reason
     * @param string $cancelledBy
     */
    public function notifyJobCancelled(JobPost $job, string $reason, string $cancelledBy = 'poster'): void
    {
        // Notify assigned worker
        if ($job->assigned_worker_id) {
            $this->notifyWorkerOfCancellation($job, $reason);
        }

        // Notify poster if cancelled by worker
        if ($cancelledBy === 'worker') {
            $this->notifyPosterOfCancellation($job, $reason);
        }

        // Notify pending applicants
        $this->notifyApplicantsOfCancellation($job);

        Log::info('Cancellation notifications sent', ['job_id' => $job->id]);
    }

    protected function notifyWorkerOfCancellation(JobPost $job, string $reason): void
    {
        $worker = $job->assignedWorker;
        $user = $worker?->user;

        if (!$user || !$user->phone) {
            return;
        }

        $message = "❌ *Job Cancelled*\n" .
            "📋 {$job->title}\n" .
            "📝 {$reason}";

        $buttons = [
            ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
            ['id' => 'menu', 'title' => '📋 Menu'],
        ];

        SendWhatsAppMessage::dispatch($user->phone, $message, 'buttons', $buttons)
            ->onQueue('job-notifications');
    }

    protected function notifyPosterOfCancellation(JobPost $job, string $reason): void
    {
        $poster = $job->poster;

        if (!$poster || !$poster->phone) {
            return;
        }

        $message = "❌ *Worker Cancelled*\n" .
            "📋 {$job->title}\n" .
            "📝 {$reason}";

        $buttons = [
            ['id' => 'repost_' . $job->id, 'title' => '🔄 Repost'],
            ['id' => 'menu', 'title' => '📋 Menu'],
        ];

        SendWhatsAppMessage::dispatch($poster->phone, $message, 'buttons', $buttons)
            ->onQueue('job-notifications');
    }

    protected function notifyApplicantsOfCancellation(JobPost $job): void
    {
        $applications = $job->applications()->where('status', 'pending')->with('worker.user')->get();

        foreach ($applications as $app) {
            $user = $app->worker?->user;
            if (!$user || !$user->phone) {
                continue;
            }

            $message = "📋 *Job Cancelled*\n{$job->title}";

            SendWhatsAppMessage::dispatch($user->phone, $message, 'text')
                ->onQueue('job-notifications');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Completion & Payment Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify worker of job completion.
     *
     * @param JobPost $job
     */
    public function notifyJobCompleted(JobPost $job): void
    {
        $worker = $job->assignedWorker;
        $user = $worker?->user;

        if (!$user || !$user->phone) {
            return;
        }

        $pay = '₹' . number_format((float) $job->pay_amount);
        $rating = $job->verification?->rating;
        $ratingDisplay = $rating ? str_repeat('⭐', $rating) : '';

        $message = "✅ *Job Complete!*\n" .
            "📋 {$job->title}\n" .
            "💰 {$pay} earned\n" .
            "{$ratingDisplay}";

        $buttons = [
            ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
            ['id' => 'earnings', 'title' => '💰 Earnings'],
        ];

        SendWhatsAppMessage::dispatch($user->phone, $message, 'buttons', $buttons)
            ->onQueue('job-notifications');
    }

    /**
     * Notify worker of payment.
     *
     * @param JobPost $job
     * @param PaymentMethod $method
     */
    public function notifyPaymentReceived(JobPost $job, PaymentMethod $method): void
    {
        $worker = $job->assignedWorker;
        $user = $worker?->user;

        if (!$user || !$user->phone) {
            return;
        }

        $pay = '₹' . number_format((float) $job->pay_amount);
        $methodDisplay = $method === PaymentMethod::UPI ? '📱 UPI' : '💵 Cash';

        $message = "💰 *Payment Confirmed!*\n" .
            "📋 {$job->title}\n" .
            "{$pay} via {$methodDisplay}";

        SendWhatsAppMessage::dispatch($user->phone, $message, 'text')
            ->onQueue('job-notifications');
    }

    /*
    |--------------------------------------------------------------------------
    | Weekly Earnings
    |--------------------------------------------------------------------------
    */

    /**
     * Send weekly earnings summary to worker.
     *
     * @param JobWorker $worker
     */
    public function sendWeeklyEarnings(JobWorker $worker): void
    {
        $user = $worker->user;

        if (!$user || !$user->phone) {
            return;
        }

        // Get this week's earnings
        $startOfWeek = now()->startOfWeek();
        $weekEarnings = JobPost::where('assigned_worker_id', $worker->id)
            ->where('status', JobStatus::COMPLETED)
            ->where('completed_at', '>=', $startOfWeek)
            ->sum('pay_amount');

        $weekJobs = JobPost::where('assigned_worker_id', $worker->id)
            ->where('status', JobStatus::COMPLETED)
            ->where('completed_at', '>=', $startOfWeek)
            ->count();

        if ($weekEarnings <= 0 && $weekJobs <= 0) {
            return;
        }

        $earnings = '₹' . number_format((float) $weekEarnings);

        // Compact: 4 lines
        $message = "📊 *Weekly Summary*\n" .
            "💰 {$earnings} earned\n" .
            "📋 {$weekJobs} jobs completed\n" .
            "Keep up the great work! 💪";

        $buttons = [
            ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
            ['id' => 'earnings', 'title' => '💰 Full Stats'],
        ];

        SendWhatsAppMessage::dispatch($user->phone, $message, 'buttons', $buttons)
            ->onQueue('job-notifications');

        Log::info('Weekly earnings sent', [
            'worker_id' => $worker->id,
            'earnings' => $weekEarnings,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Badge Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Send badge earned notification.
     *
     * @param JobWorker $worker
     * @param BadgeType $badge
     */
    public function sendBadgeEarned(JobWorker $worker, BadgeType $badge): void
    {
        $user = $worker->user;

        if (!$user || !$user->phone) {
            return;
        }

        // Compact: 3 lines
        $message = "🏆 *New Badge!*\n" .
            "{$badge->emoji()} {$badge->label()}\n" .
            "Congrats, {$worker->name}! 🎉";

        $buttons = [
            ['id' => 'view_badges', 'title' => '🏆 My Badges'],
            ['id' => 'browse_jobs', 'title' => '🔍 More Jobs'],
        ];

        SendWhatsAppMessage::dispatch($user->phone, $message, 'buttons', $buttons)
            ->onQueue('job-notifications');

        Log::info('Badge notification sent', [
            'worker_id' => $worker->id,
            'badge' => $badge->value,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Send weekly summaries to all active workers.
     *
     * @return int Count of workers notified
     */
    public function sendAllWeeklySummaries(): int
    {
        $startOfWeek = now()->startOfWeek();

        $workerIds = JobPost::where('status', JobStatus::COMPLETED)
            ->where('completed_at', '>=', $startOfWeek)
            ->distinct()
            ->pluck('assigned_worker_id');

        $count = 0;

        foreach ($workerIds as $workerId) {
            if ($workerId) {
                SendJobNotificationJob::dispatch(
                    SendJobNotificationJob::TYPE_WEEKLY_EARNINGS,
                    $workerId
                )->onQueue('job-notifications')->delay(now()->addSeconds($count * 2));

                $count++;
            }
        }

        Log::info('Weekly summaries queued', ['count' => $count]);

        return $count;
    }

    /**
     * Process all pending job reminders.
     *
     * @return int Count of reminders sent
     */
    public function processJobReminders(): int
    {
        $jobs = $this->getJobsNeedingReminders();
        $count = 0;

        foreach ($jobs as $job) {
            $this->sendJobReminder($job);
            $count++;
        }

        return $count;
    }
}