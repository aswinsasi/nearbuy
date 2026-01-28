<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\BadgeType;
use App\Enums\JobPostStatus;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Jobs\SendJobNotificationJob;
use App\Jobs\SendWhatsAppMessage;
use App\Models\JobApplication;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Models\User;
use App\Models\WorkerBadge;
use App\Models\WorkerEarning;
use App\Services\WhatsApp\Messages\JobMessages;
use App\Services\WhatsApp\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing job-related notifications.
 *
 * Handles:
 * - Notifying workers of new jobs
 * - Application notifications
 * - Selection/rejection notifications
 * - Job reminders
 * - Earnings summaries
 * - Badge awards
 *
 * @srs-ref Section 3.5 - Job Notifications
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobNotificationService
{
    /**
     * Maximum workers to notify per batch.
     */
    public const BATCH_SIZE = 50;

    /**
     * Reminder time before job (in minutes).
     */
    public const REMINDER_MINUTES_BEFORE = 60;

    /**
     * Weekly earnings summary day (0 = Sunday).
     */
    public const WEEKLY_SUMMARY_DAY = 0;

    /**
     * Weekly earnings summary hour (24-hour format).
     */
    public const WEEKLY_SUMMARY_HOUR = 9;

    public function __construct(
        protected WhatsAppService $whatsApp,
        protected JobMatchingService $matchingService
    ) {}

    /*
    |--------------------------------------------------------------------------
    | New Job Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify matching workers about a new job posting.
     *
     * @param JobPost $job The job to notify about
     * @param int $radiusKm Search radius for workers
     * @return int Number of workers notified
     */
    public function notifyWorkersOfNewJob(JobPost $job, int $radiusKm = 5): int
    {
        if ($job->status !== JobStatus::OPEN) {
            Log::warning('Attempted to notify workers of non-open job', [
                'job_id' => $job->id,
                'status' => $job->status->value,
            ]);
            return 0;
        }

        // Find matching workers
        $workers = $this->matchingService->findMatchingWorkers($job, $radiusKm);

        if ($workers->isEmpty()) {
            Log::info('No matching workers found for job', [
                'job_id' => $job->id,
                'radius_km' => $radiusKm,
            ]);
            return 0;
        }

        $notifiedCount = 0;

        // Process in batches for large worker sets
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
            'workers_notified' => $notifiedCount,
        ]);

        return $notifiedCount;
    }

    /**
     * Send new job notification to a specific worker.
     */
    public function sendNewJobNotification(JobPost $job, JobWorker $worker): void
    {
        $user = $worker->user;

        if (!$user || !$user->phone) {
            return;
        }

        // Calculate distance
        $distance = $this->matchingService->calculateDistance(
            $worker->latitude,
            $worker->longitude,
            $job->latitude,
            $job->longitude
        );

        $distanceDisplay = $distance < 1
            ? round($distance * 1000) . 'm'
            : round($distance, 1) . ' km';

        // Build message
        $message = $this->buildNewJobMessage($job, $distanceDisplay);

        // Build action buttons
        $buttons = [
            [
                'id' => 'apply_job_' . $job->id,
                'title' => '✅ Apply / അപേക്ഷിക്കുക',
            ],
            [
                'id' => 'view_job_' . $job->id,
                'title' => '👁️ View Details',
            ],
            [
                'id' => 'skip_job_' . $job->id,
                'title' => '⏭️ Skip',
            ],
        ];

        // Queue notification
        SendWhatsAppMessage::dispatch(
            $user->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');

        Log::debug('New job notification sent', [
            'job_id' => $job->id,
            'worker_id' => $worker->id,
            'distance_km' => $distance,
        ]);
    }

    /**
     * Build new job notification message.
     */
    protected function buildNewJobMessage(JobPost $job, string $distanceDisplay): string
    {
        $category = $job->category;
        $poster = $job->poster;

        $lines = [
            "🆕 *New Job Available!*",
            "*പുതിയ ജോലി ലഭ്യമാണ്!*",
            "",
            "{$category->icon} *{$job->title}*",
            "",
            "💰 *{$job->amount_display}*" . ($job->is_negotiable ? ' (Negotiable)' : ''),
            "📍 {$job->location_display}",
            "🚗 {$distanceDisplay} away",
            "📅 {$job->scheduled_date->format('M j')} at {$job->scheduled_time->format('g:i A')}",
        ];

        if ($job->estimated_duration) {
            $lines[] = "⏱️ ~{$job->estimated_duration} hours";
        }

        if ($job->description) {
            $lines[] = "";
            $lines[] = "📝 " . \Illuminate\Support\Str::limit($job->description, 100);
        }

        if ($poster) {
            $lines[] = "";
            $lines[] = "👤 Posted by: {$poster->name}";
            if ($poster->rating_count > 0) {
                $lines[] = "⭐ {$poster->average_rating}/5 ({$poster->rating_count} reviews)";
            }
        }

        $lines[] = "";
        $lines[] = "_Apply now to get this job!_";
        $lines[] = "_ഈ ജോലി ലഭിക്കാൻ ഇപ്പോൾ അപേക്ഷിക്കുക!_";

        return implode("\n", $lines);
    }

    /*
    |--------------------------------------------------------------------------
    | Application Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify job poster about a new application.
     */
    public function notifyPosterOfApplication(JobApplication $application): void
    {
        $job = $application->jobPost;
        $poster = $job->poster;
        $worker = $application->worker;

        if (!$poster || !$poster->phone) {
            return;
        }

        $message = $this->buildApplicationNotificationMessage($application, $job, $worker);

        $buttons = [
            [
                'id' => 'view_applicant_' . $application->id,
                'title' => '👁️ View Profile',
            ],
            [
                'id' => 'accept_applicant_' . $application->id,
                'title' => '✅ Accept',
            ],
            [
                'id' => 'view_all_applicants_' . $job->id,
                'title' => '📋 All Applicants',
            ],
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
            'poster_id' => $poster->id,
        ]);
    }

    /**
     * Build application notification message.
     */
    protected function buildApplicationNotificationMessage(
        JobApplication $application,
        JobPost $job,
        JobWorker $worker
    ): string {
        $pendingCount = $job->applications()->pending()->count();

        $lines = [
            "📩 *New Application Received!*",
            "*പുതിയ അപേക്ഷ ലഭിച്ചു!*",
            "",
            "For: {$job->category->icon} *{$job->title}*",
            "",
            "👤 *{$worker->name}*",
        ];

        if ($worker->rating_count > 0) {
            $lines[] = "⭐ {$worker->average_rating}/5 ({$worker->rating_count} reviews)";
        }

        $lines[] = "✅ {$worker->jobs_completed} jobs completed";

        if ($worker->hasVehicle()) {
            $lines[] = "🚗 Has vehicle: {$worker->vehicle_type->label()}";
        }

        if ($application->proposed_amount) {
            $lines[] = "";
            $lines[] = "💰 Proposed: {$application->proposed_amount_display}";
        }

        if ($application->message) {
            $lines[] = "";
            $lines[] = "💬 \"{$application->message}\"";
        }

        $lines[] = "";
        $lines[] = "📊 Total pending applications: {$pendingCount}";

        return implode("\n", $lines);
    }

    /*
    |--------------------------------------------------------------------------
    | Selection/Rejection Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify worker that they were selected for a job.
     */
    public function notifyWorkerSelected(JobApplication $application): void
    {
        $job = $application->jobPost;
        $worker = $application->worker;
        $user = $worker->user;

        if (!$user || !$user->phone) {
            return;
        }

        $message = $this->buildSelectionMessage($job, $application);

        $buttons = [
            [
                'id' => 'confirm_job_' . $job->id,
                'title' => '✅ Confirm / സ്ഥിരീകരിക്കുക',
            ],
            [
                'id' => 'view_job_details_' . $job->id,
                'title' => '📋 View Details',
            ],
            [
                'id' => 'contact_poster_' . $job->id,
                'title' => '💬 Contact Poster',
            ],
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
            'application_id' => $application->id,
        ]);
    }

    /**
     * Build selection notification message.
     */
    protected function buildSelectionMessage(JobPost $job, JobApplication $application): string
    {
        $poster = $job->poster;
        $finalAmount = $application->proposed_amount ?? $job->amount;

        $lines = [
            "🎉 *Congratulations! You Got the Job!*",
            "*അഭിനന്ദനങ്ങൾ! നിങ്ങൾ തിരഞ്ഞെടുക്കപ്പെട്ടു!*",
            "",
            "{$job->category->icon} *{$job->title}*",
            "",
            "💰 Amount: ₹" . number_format($finalAmount),
            "📅 Date: {$job->scheduled_date->format('l, M j, Y')}",
            "🕐 Time: {$job->scheduled_time->format('g:i A')}",
            "📍 Location: {$job->location_display}",
        ];

        if ($poster) {
            $lines[] = "";
            $lines[] = "👤 Contact: {$poster->name}";
            $lines[] = "📞 Phone: {$poster->phone}";
        }

        if ($job->special_instructions) {
            $lines[] = "";
            $lines[] = "📝 Instructions: {$job->special_instructions}";
        }

        $lines[] = "";
        $lines[] = "_Please confirm your availability._";
        $lines[] = "_ദയവായി നിങ്ങളുടെ ലഭ്യത സ്ഥിരീകരിക്കുക._";

        return implode("\n", $lines);
    }

    /**
     * Notify worker that their application was rejected.
     */
    public function notifyWorkerRejected(JobApplication $application): void
    {
        $job = $application->jobPost;
        $worker = $application->worker;
        $user = $worker->user;

        if (!$user || !$user->phone) {
            return;
        }

        $message = $this->buildRejectionMessage($job);

        $buttons = [
            [
                'id' => 'browse_jobs',
                'title' => '🔍 Find Other Jobs',
            ],
            [
                'id' => 'main_menu',
                'title' => '🏠 Main Menu',
            ],
        ];

        SendWhatsAppMessage::dispatch(
            $user->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');

        Log::debug('Worker notified of rejection', [
            'job_id' => $job->id,
            'worker_id' => $worker->id,
        ]);
    }

    /**
     * Build rejection notification message.
     */
    protected function buildRejectionMessage(JobPost $job): string
    {
        return implode("\n", [
            "📋 *Application Update*",
            "",
            "Your application for *{$job->title}* was not selected this time.",
            "",
            "നിങ്ങളുടെ *{$job->title}* അപേക്ഷ ഇത്തവണ തിരഞ്ഞെടുത്തില്ല.",
            "",
            "_Don't worry! New jobs are posted regularly._",
            "_വിഷമിക്കേണ്ട! പുതിയ ജോലികൾ പതിവായി പോസ്റ്റ് ചെയ്യുന്നു._",
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Job Reminders
    |--------------------------------------------------------------------------
    */

    /**
     * Send job reminder (1 hour before scheduled time).
     */
    public function sendJobReminder(JobPost $job): void
    {
        if (!in_array($job->status, [JobStatus::ASSIGNED])) {
            return;
        }

        $worker = $job->assignedWorker;

        if (!$worker) {
            return;
        }

        // Send to worker
        $this->sendWorkerReminder($job, $worker);

        // Send to poster
        $this->sendPosterReminder($job);

        Log::info('Job reminders sent', [
            'job_id' => $job->id,
            'worker_id' => $worker->id,
        ]);
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

        $message = implode("\n", [
            "⏰ *Job Reminder!*",
            "*ജോലി ഓർമ്മപ്പെടുത്തൽ!*",
            "",
            "{$job->category->icon} *{$job->title}*",
            "",
            "📅 Today at {$job->scheduled_time->format('g:i A')}",
            "📍 {$job->location_display}",
            "",
            "👤 Contact: {$job->poster->name}",
            "📞 {$job->poster->phone}",
            "",
            "_Tap 'Start Job' when you arrive._",
            "_നിങ്ങൾ എത്തുമ്പോൾ 'Start Job' ടാപ്പ് ചെയ്യുക._",
        ]);

        $buttons = [
            [
                'id' => 'start_job_' . $job->id,
                'title' => '🚀 Start Job',
            ],
            [
                'id' => 'get_directions_' . $job->id,
                'title' => '📍 Get Directions',
            ],
            [
                'id' => 'contact_poster_' . $job->id,
                'title' => '📞 Contact',
            ],
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

        $message = implode("\n", [
            "⏰ *Job Reminder!*",
            "*ജോലി ഓർമ്മപ്പെടുത്തൽ!*",
            "",
            "{$job->category->icon} *{$job->title}*",
            "",
            "📅 Today at {$job->scheduled_time->format('g:i A')}",
            "",
            "👷 Worker: *{$worker->name}*",
            "📞 {$worker->user->phone}",
            "⭐ {$worker->short_rating}",
            "",
            "_Your worker will arrive soon._",
            "_നിങ്ങളുടെ തൊഴിലാളി ഉടൻ എത്തും._",
        ]);

        $buttons = [
            [
                'id' => 'contact_worker_' . $job->id,
                'title' => '📞 Contact Worker',
            ],
            [
                'id' => 'view_job_' . $job->id,
                'title' => '👁️ View Job',
            ],
        ];

        SendWhatsAppMessage::dispatch(
            $poster->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');
    }

    /**
     * Get jobs needing reminders.
     */
    public function getJobsNeedingReminders(): Collection
    {
        $reminderTime = now()->addMinutes(self::REMINDER_MINUTES_BEFORE);

        return JobPost::whereIn('status', [JobStatus::ASSIGNED])
            ->whereDate('scheduled_date', today())
            ->whereTime('scheduled_time', '>=', now()->format('H:i:s'))
            ->whereTime('scheduled_time', '<=', $reminderTime->format('H:i:s'))
            ->whereNull('reminder_sent_at')
            ->with(['assignedWorker.user', 'poster', 'category'])
            ->get();
    }

    /**
     * Mark reminder as sent.
     */
    public function markReminderSent(JobPost $job): void
    {
        $job->update(['reminder_sent_at' => now()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify about job cancellation.
     */
    public function notifyJobCancelled(JobPost $job, string $reason, string $cancelledBy = 'poster'): void
    {
        // Notify assigned worker if any
        if ($job->assigned_worker_id) {
            $this->notifyWorkerOfCancellation($job, $reason);
        }

        // Notify poster if cancelled by worker
        if ($cancelledBy === 'worker') {
            $this->notifyPosterOfCancellation($job, $reason);
        }

        // Notify pending applicants
        $this->notifyApplicantsOfCancellation($job);

        Log::info('Cancellation notifications sent', [
            'job_id' => $job->id,
            'cancelled_by' => $cancelledBy,
        ]);
    }

    /**
     * Notify worker of job cancellation.
     */
    protected function notifyWorkerOfCancellation(JobPost $job, string $reason): void
    {
        $worker = $job->assignedWorker;
        $user = $worker?->user;

        if (!$user || !$user->phone) {
            return;
        }

        $message = implode("\n", [
            "❌ *Job Cancelled*",
            "*ജോലി റദ്ദാക്കി*",
            "",
            "{$job->category->icon} *{$job->title}*",
            "",
            "📝 Reason: {$reason}",
            "",
            "_We apologize for the inconvenience._",
            "_അസൗകര്യത്തിൽ ക്ഷമിക്കുക._",
        ]);

        $buttons = [
            [
                'id' => 'browse_jobs',
                'title' => '🔍 Find Other Jobs',
            ],
            [
                'id' => 'main_menu',
                'title' => '🏠 Main Menu',
            ],
        ];

        SendWhatsAppMessage::dispatch(
            $user->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');
    }

    /**
     * Notify poster of worker cancellation.
     */
    protected function notifyPosterOfCancellation(JobPost $job, string $reason): void
    {
        $poster = $job->poster;

        if (!$poster || !$poster->phone) {
            return;
        }

        $message = implode("\n", [
            "❌ *Worker Cancelled*",
            "*തൊഴിലാളി റദ്ദാക്കി*",
            "",
            "{$job->category->icon} *{$job->title}*",
            "",
            "📝 Reason: {$reason}",
            "",
            "_You can select another applicant or repost the job._",
        ]);

        $buttons = [
            [
                'id' => 'view_applicants_' . $job->id,
                'title' => '👥 Other Applicants',
            ],
            [
                'id' => 'repost_job_' . $job->id,
                'title' => '🔄 Repost Job',
            ],
        ];

        SendWhatsAppMessage::dispatch(
            $poster->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');
    }

    /**
     * Notify all pending applicants of job cancellation.
     */
    protected function notifyApplicantsOfCancellation(JobPost $job): void
    {
        $applications = $job->applications()->pending()->with('worker.user')->get();

        foreach ($applications as $application) {
            $user = $application->worker?->user;

            if (!$user || !$user->phone) {
                continue;
            }

            $message = implode("\n", [
                "📋 *Job Update*",
                "",
                "The job *{$job->title}* you applied for has been cancelled.",
                "",
                "നിങ്ങൾ അപേക്ഷിച്ച *{$job->title}* ജോലി റദ്ദാക്കി.",
            ]);

            SendWhatsAppMessage::dispatch(
                $user->phone,
                $message,
                'text'
            )->onQueue('job-notifications');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Earnings & Badge Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Send weekly earnings summary to worker.
     */
    public function sendWeeklyEarnings(JobWorker $worker): void
    {
        $user = $worker->user;

        if (!$user || !$user->phone) {
            return;
        }

        // Get this week's earnings
        $earning = WorkerEarning::getOrCreateForWeek($worker);

        if ($earning->total_earned <= 0 && $earning->jobs_completed <= 0) {
            // No activity, skip notification
            return;
        }

        $message = $this->buildWeeklyEarningsMessage($worker, $earning);

        $buttons = [
            [
                'id' => 'view_stats',
                'title' => '📊 Full Stats',
            ],
            [
                'id' => 'browse_jobs',
                'title' => '🔍 Find Jobs',
            ],
            [
                'id' => 'share_stats',
                'title' => '📤 Share',
            ],
        ];

        SendWhatsAppMessage::dispatch(
            $user->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');

        Log::info('Weekly earnings summary sent', [
            'worker_id' => $worker->id,
            'total_earned' => $earning->total_earned,
            'jobs_completed' => $earning->jobs_completed,
        ]);
    }

    /**
     * Build weekly earnings summary message.
     */
    protected function buildWeeklyEarningsMessage(JobWorker $worker, WorkerEarning $earning): string
    {
        $lines = [
            "📊 *Weekly Earnings Summary*",
            "*ആഴ്ചയിലെ വരുമാന സംഗ്രഹം*",
            "",
            "👋 Hi {$worker->name}!",
            "",
            "This week you earned:",
            "",
            "💰 *₹" . number_format($earning->total_earned) . "*",
            "",
            "📋 Jobs completed: {$earning->jobs_completed}",
            "⏱️ Hours worked: " . round($earning->total_hours, 1),
        ];

        if ($earning->average_rating > 0) {
            $lines[] = "⭐ Average rating: " . round($earning->average_rating, 1) . "/5";
        }

        if ($earning->on_time_jobs > 0) {
            $onTimeRate = round(($earning->on_time_jobs / $earning->jobs_completed) * 100);
            $lines[] = "⏰ On-time rate: {$onTimeRate}%";
        }

        // Add comparison to last week
        $lastWeek = WorkerEarning::byWorker($worker->id)
            ->where('week_start', '<', $earning->week_start)
            ->orderBy('week_start', 'desc')
            ->first();

        if ($lastWeek && $lastWeek->total_earned > 0) {
            $change = $earning->total_earned - $lastWeek->total_earned;
            $percentChange = round(($change / $lastWeek->total_earned) * 100);

            if ($change > 0) {
                $lines[] = "";
                $lines[] = "📈 *+₹" . number_format($change) . "* (+{$percentChange}%) from last week!";
            } elseif ($change < 0) {
                $lines[] = "";
                $lines[] = "📉 ₹" . number_format(abs($change)) . " ({$percentChange}%) less than last week";
            }
        }

        $lines[] = "";
        $lines[] = "_Keep up the great work! 💪_";
        $lines[] = "_മികച്ച പ്രവർത്തനം തുടരുക! 💪_";

        return implode("\n", $lines);
    }

    /**
     * Send badge earned notification.
     */
    public function sendBadgeEarned(JobWorker $worker, BadgeType $badge): void
    {
        $user = $worker->user;

        if (!$user || !$user->phone) {
            return;
        }

        $message = $this->buildBadgeEarnedMessage($worker, $badge);

        $buttons = [
            [
                'id' => 'view_badges',
                'title' => '🏆 My Badges',
            ],
            [
                'id' => 'share_badge_' . $badge->value,
                'title' => '📤 Share',
            ],
            [
                'id' => 'browse_jobs',
                'title' => '🔍 Find Jobs',
            ],
        ];

        SendWhatsAppMessage::dispatch(
            $user->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');

        Log::info('Badge earned notification sent', [
            'worker_id' => $worker->id,
            'badge' => $badge->value,
        ]);
    }

    /**
     * Build badge earned message.
     */
    protected function buildBadgeEarnedMessage(JobWorker $worker, BadgeType $badge): string
    {
        $totalBadges = WorkerBadge::byWorker($worker->id)->count();

        return implode("\n", [
            "🏆 *New Badge Earned!*",
            "*പുതിയ ബാഡ്ജ് നേടി!*",
            "",
            "Congratulations {$worker->name}! 🎉",
            "",
            "{$badge->emoji()} *{$badge->label()}*",
            "",
            $badge->description(),
            "",
            "You now have *{$totalBadges} badges*!",
            "",
            "_Share your achievement with friends!_",
            "_നിങ്ങളുടെ നേട്ടം സുഹൃത്തുക്കളുമായി പങ്കിടുക!_",
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Completion Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify worker of job completion with earnings summary.
     */
    public function notifyJobCompleted(JobPost $job): void
    {
        $worker = $job->assignedWorker;
        $user = $worker?->user;

        if (!$user || !$user->phone) {
            return;
        }

        $message = $this->buildCompletionMessage($job, $worker);

        $buttons = [
            [
                'id' => 'view_earnings',
                'title' => '💰 My Earnings',
            ],
            [
                'id' => 'browse_jobs',
                'title' => '🔍 More Jobs',
            ],
            [
                'id' => 'share_completion',
                'title' => '📤 Share',
            ],
        ];

        SendWhatsAppMessage::dispatch(
            $user->phone,
            $message,
            'buttons',
            $buttons
        )->onQueue('job-notifications');
    }

    /**
     * Build job completion message.
     */
    protected function buildCompletionMessage(JobPost $job, JobWorker $worker): string
    {
        $earning = WorkerEarning::getOrCreateForWeek($worker);

        $lines = [
            "✅ *Job Completed!*",
            "*ജോലി പൂർത്തിയായി!*",
            "",
            "{$job->category->icon} *{$job->title}*",
            "",
            "💰 Earned: *₹" . number_format($job->final_amount ?? $job->amount) . "*",
        ];

        if ($job->worker_rating) {
            $lines[] = "⭐ Your rating: {$job->worker_rating}/5";
        }

        $lines[] = "";
        $lines[] = "📊 *This Week's Progress:*";
        $lines[] = "💰 Total: ₹" . number_format($earning->total_earned);
        $lines[] = "📋 Jobs: {$earning->jobs_completed}";

        $lines[] = "";
        $lines[] = "_Great work! Keep it up! 💪_";

        return implode("\n", $lines);
    }

    /*
    |--------------------------------------------------------------------------
    | Bulk Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Send weekly summaries to all active workers.
     */
    public function sendAllWeeklySummaries(): int
    {
        $workers = JobWorker::verified()
            ->active()
            ->whereHas('earnings', function ($q) {
                $q->thisWeek()->where('jobs_completed', '>', 0);
            })
            ->get();

        $count = 0;

        foreach ($workers as $worker) {
            SendJobNotificationJob::dispatch('weekly_earnings', $worker->id)
                ->onQueue('job-notifications')
                ->delay(now()->addSeconds($count * 2)); // Stagger to avoid rate limits

            $count++;
        }

        Log::info('Weekly summaries queued', ['count' => $count]);

        return $count;
    }

    /**
     * Process all pending job reminders.
     */
    public function processJobReminders(): int
    {
        $jobs = $this->getJobsNeedingReminders();
        $count = 0;

        foreach ($jobs as $job) {
            $this->sendJobReminder($job);
            $this->markReminderSent($job);
            $count++;
        }

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | Payment Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify worker of payment confirmation.
     */
    public function notifyPaymentReceived(JobPost $job, PaymentMethod $method): void
    {
        $worker = $job->assignedWorker;
        $user = $worker?->user;

        if (!$user || !$user->phone) {
            return;
        }

        $message = implode("\n", [
            "💰 *Payment Confirmed!*",
            "*പേയ്മെന്റ് സ്ഥിരീകരിച്ചു!*",
            "",
            "{$job->category->icon} *{$job->title}*",
            "",
            "Amount: *₹" . number_format($job->final_amount ?? $job->amount) . "*",
            "Method: {$method->label()}",
            "",
            "_Thank you for your work!_",
            "_നിങ്ങളുടെ പ്രവർത്തനത്തിന് നന്ദി!_",
        ]);

        SendWhatsAppMessage::dispatch(
            $user->phone,
            $message,
            'text'
        )->onQueue('job-notifications');
    }

    /*
    |--------------------------------------------------------------------------
    | Cleanup
    |--------------------------------------------------------------------------
    */

    /**
     * Get notification statistics.
     */
    public function getNotificationStats(Carbon $from, Carbon $to): array
    {
        // This would typically query a notifications table
        // For now, return placeholder stats
        return [
            'total_sent' => 0,
            'job_alerts' => 0,
            'reminders' => 0,
            'weekly_summaries' => 0,
            'badge_notifications' => 0,
            'period' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
            ],
        ];
    }
}