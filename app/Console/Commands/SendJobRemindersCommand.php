<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\JobApplicationStatus;
use App\Enums\JobStatus;
use App\Models\JobApplication;
use App\Models\JobPost;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Send reminders for jobs - two types:
 * 1. Poster reminder: When applications pending > 2 hours
 * 2. Worker reminder: 1 hour before job starts
 *
 * Schedule: Run every 2 hours
 *
 * @example
 * php artisan jobs:send-reminders
 * php artisan jobs:send-reminders --type=poster
 * php artisan jobs:send-reminders --type=worker
 * php artisan jobs:send-reminders --dry-run
 *
 * @srs-ref Njaanum Panikkar Module - Job Reminders
 * @schedule Run every 2 hours: $schedule->command('jobs:send-reminders')->everyTwoHours();
 */
class SendJobRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'jobs:send-reminders
                            {--type= : Type of reminder (poster|worker|all)}
                            {--dry-run : Show what would be sent without sending}';

    /**
     * The console command description.
     */
    protected $description = 'Send job reminders to posters (pending applications) and workers (upcoming jobs)';

    /**
     * Cache key prefixes for tracking sent reminders.
     */
    protected const POSTER_REMINDER_PREFIX = 'job_poster_reminder_';
    protected const WORKER_REMINDER_PREFIX = 'job_worker_reminder_';

    /**
     * Reminder thresholds.
     */
    protected const APPLICATIONS_PENDING_HOURS = 2;
    protected const WORKER_REMINDER_MINUTES = 60;

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppService $whatsApp): int
    {
        $type = $this->option('type') ?? 'all';

        $this->info('🔔 Running job reminders...');
        $this->newLine();

        $postersNotified = 0;
        $workersNotified = 0;

        // Send poster reminders
        if (in_array($type, ['all', 'poster'])) {
            $postersNotified = $this->sendPosterReminders($whatsApp);
        }

        // Send worker reminders
        if (in_array($type, ['all', 'worker'])) {
            $workersNotified = $this->sendWorkerReminders($whatsApp);
        }

        $this->newLine();
        $this->info("✅ Reminders complete: {$postersNotified} poster(s), {$workersNotified} worker(s).");

        return self::SUCCESS;
    }

    /**
     * Send reminders to posters with pending applications > 2 hours old.
     *
     * Message: "👷 [X] workers applied! Select one: [View]"
     */
    protected function sendPosterReminders(WhatsAppService $whatsApp): int
    {
        $this->info('📋 Checking for jobs with pending applications > 2 hours...');

        // Find OPEN jobs with applications that have been pending for > 2 hours
        $jobsWithPendingApplications = JobPost::query()
            ->where('status', JobStatus::OPEN)
            ->whereHas('applications', function ($query) {
                $query->where('status', JobApplicationStatus::PENDING)
                    ->where('created_at', '<=', now()->subHours(self::APPLICATIONS_PENDING_HOURS));
            })
            ->with([
                'poster',
                'category',
                'applications' => fn($q) => $q->where('status', JobApplicationStatus::PENDING),
            ])
            ->get();

        // Filter out jobs that already had reminders sent today
        $jobsNeedingReminder = $jobsWithPendingApplications->filter(function ($job) {
            $cacheKey = self::POSTER_REMINDER_PREFIX . $job->id . '_' . now()->toDateString();
            return !Cache::has($cacheKey);
        });

        if ($jobsNeedingReminder->isEmpty()) {
            $this->info('  No posters need reminders at this time.');
            return 0;
        }

        $this->info("  Found {$jobsNeedingReminder->count()} job(s) with pending applications.");

        if ($this->option('dry-run')) {
            $this->showPosterJobs($jobsNeedingReminder);
            return 0;
        }

        $notified = 0;

        foreach ($jobsNeedingReminder as $job) {
            try {
                $applicationCount = $job->applications->count();
                $poster = $job->poster;

                if (!$poster || !$poster->phone) {
                    continue;
                }

                // Build message
                $message = "👷 *{$applicationCount} worker(s) applied!*\n" .
                    "*{$applicationCount} പേർ അപേക്ഷിച്ചു!*\n\n" .
                    "{$job->category->icon} *{$job->title}*\n" .
                    "💰 {$job->pay_display}\n" .
                    "📍 {$job->location_name}\n\n" .
                    "Select a worker to get started!\n" .
                    "തുടങ്ങാൻ ഒരു പണിക്കാരനെ തിരഞ്ഞെടുക്കുക!";

                $whatsApp->sendButtons(
                    $poster->phone,
                    $message,
                    [
                        ['id' => 'view_applications_' . $job->id, 'title' => '👁️ View Workers'],
                        ['id' => 'edit_job_' . $job->id, 'title' => '✏️ Edit Job'],
                        ['id' => 'cancel_job_' . $job->id, 'title' => '❌ Cancel'],
                    ],
                    '👷 Workers Applied!'
                );

                // Mark reminder as sent for today
                $cacheKey = self::POSTER_REMINDER_PREFIX . $job->id . '_' . now()->toDateString();
                Cache::put($cacheKey, true, now()->endOfDay());

                $notified++;

                Log::info('Poster reminder sent', [
                    'job_id' => $job->id,
                    'poster_id' => $poster->id,
                    'applications_count' => $applicationCount,
                ]);

            } catch (\Exception $e) {
                $this->error("  Failed to notify poster for job {$job->id}: {$e->getMessage()}");
                Log::error('Failed to send poster reminder', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("  📨 Notified {$notified} poster(s).");
        return $notified;
    }

    /**
     * Send reminders to workers 1 hour before job starts.
     *
     * Message: "⏰ Job in 1 hour! [Location]. Ready? [✅ Yes] [❌ Can't Make It]"
     */
    protected function sendWorkerReminders(WhatsAppService $whatsApp): int
    {
        $this->info('⏰ Checking for assigned jobs starting within 1 hour...');

        // Calculate the time window: jobs starting in ~1 hour (55-65 minutes from now)
        $reminderWindowStart = now()->addMinutes(55);
        $reminderWindowEnd = now()->addMinutes(65);

        // Find ASSIGNED jobs starting within the reminder window
        $upcomingJobs = JobPost::query()
            ->where('status', JobStatus::ASSIGNED)
            ->where('job_date', now()->toDateString())
            ->whereNotNull('job_time')
            ->whereRaw("CONCAT(job_date, ' ', job_time) BETWEEN ? AND ?", [
                $reminderWindowStart->toDateTimeString(),
                $reminderWindowEnd->toDateTimeString(),
            ])
            ->with(['poster', 'assignedWorker.user', 'category'])
            ->get();

        // Also find jobs scheduled for today without specific time (morning reminder for afternoon jobs)
        $flexibleTimeJobs = collect();

        if (now()->hour >= 6 && now()->hour < 8) {
            // Morning batch: remind for flexible-time jobs scheduled today
            $flexibleTimeJobs = JobPost::query()
                ->where('status', JobStatus::ASSIGNED)
                ->where('job_date', now()->toDateString())
                ->whereNull('job_time')
                ->with(['poster', 'assignedWorker.user', 'category'])
                ->get();
        }

        $allJobs = $upcomingJobs->merge($flexibleTimeJobs);

        // Filter out jobs that already had reminders sent
        $jobsNeedingReminder = $allJobs->filter(function ($job) {
            $cacheKey = self::WORKER_REMINDER_PREFIX . $job->id;
            return !Cache::has($cacheKey);
        });

        if ($jobsNeedingReminder->isEmpty()) {
            $this->info('  No workers need reminders at this time.');
            return 0;
        }

        $this->info("  Found {$jobsNeedingReminder->count()} upcoming job(s).");

        if ($this->option('dry-run')) {
            $this->showWorkerJobs($jobsNeedingReminder);
            return 0;
        }

        $notified = 0;

        foreach ($jobsNeedingReminder as $job) {
            try {
                $worker = $job->assignedWorker;

                if (!$worker || !$worker->user || !$worker->user->phone) {
                    continue;
                }

                // Calculate time until job
                $timeDisplay = $job->job_time
                    ? Carbon::parse($job->job_date . ' ' . $job->job_time)->diffForHumans()
                    : 'Today (flexible time)';

                // Build message
                $message = "⏰ *Job Reminder*\n" .
                    "*ജോലി ഓർമ്മപ്പെടുത്തൽ*\n\n" .
                    "{$job->category->icon} *{$job->title}*\n\n" .
                    "🕐 *When:* {$timeDisplay}\n" .
                    "📍 *Where:* {$job->location_name}\n" .
                    "💰 *Pay:* {$job->pay_display}\n\n" .
                    "Ready to go?\n" .
                    "പോകാൻ തയ്യാറാണോ?";

                $whatsApp->sendButtons(
                    $worker->user->phone,
                    $message,
                    [
                        ['id' => 'job_ready_' . $job->id, 'title' => "✅ Yes, I'm Ready"],
                        ['id' => 'job_cant_make_' . $job->id, 'title' => "❌ Can't Make It"],
                        ['id' => 'get_directions_' . $job->id, 'title' => '📍 Directions'],
                    ],
                    '⏰ Job in 1 Hour!'
                );

                // Mark reminder as sent (cache for 24 hours)
                $cacheKey = self::WORKER_REMINDER_PREFIX . $job->id;
                Cache::put($cacheKey, true, now()->addHours(24));

                $notified++;

                // Also notify poster that reminder was sent to worker
                $this->notifyPosterOfWorkerReminder($whatsApp, $job);

                Log::info('Worker reminder sent', [
                    'job_id' => $job->id,
                    'worker_id' => $worker->id,
                ]);

            } catch (\Exception $e) {
                $this->error("  Failed to notify worker for job {$job->id}: {$e->getMessage()}");
                Log::error('Failed to send worker reminder', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("  📨 Notified {$notified} worker(s).");
        return $notified;
    }

    /**
     * Notify poster that worker has been reminded.
     */
    protected function notifyPosterOfWorkerReminder(WhatsAppService $whatsApp, JobPost $job): void
    {
        $poster = $job->poster;
        $worker = $job->assignedWorker;

        if (!$poster || !$poster->phone || !$worker) {
            return;
        }

        try {
            $timeDisplay = $job->job_time
                ? $job->formatted_time
                : 'Today (flexible time)';

            $message = "ℹ️ *Worker Reminder Sent*\n" .
                "*പണിക്കാരന് ഓർമ്മപ്പെടുത്തൽ അയച്ചു*\n\n" .
                "{$job->category->icon} *{$job->title}*\n" .
                "👤 Worker: {$worker->name}\n" .
                "🕐 Scheduled: {$timeDisplay}\n\n" .
                "_We'll notify you when the worker arrives._\n" .
                "_പണിക്കാരൻ എത്തുമ്പോൾ അറിയിക്കും._";

            $whatsApp->sendText($poster->phone, $message);

        } catch (\Exception $e) {
            Log::warning('Failed to notify poster of worker reminder', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Show poster jobs that would get reminders (dry run).
     */
    protected function showPosterJobs($jobs): void
    {
        $this->info('  [DRY RUN] Would send reminders for these jobs:');

        $headers = ['ID', 'Title', 'Poster', 'Applications', 'Oldest App'];
        $rows = [];

        foreach ($jobs as $job) {
            $oldestApp = $job->applications->sortBy('created_at')->first();
            $rows[] = [
                $job->id,
                mb_substr($job->title, 0, 25),
                $job->poster?->name ?? 'Unknown',
                $job->applications->count(),
                $oldestApp?->created_at->diffForHumans() ?? '-',
            ];
        }

        $this->table($headers, $rows);
    }

    /**
     * Show worker jobs that would get reminders (dry run).
     */
    protected function showWorkerJobs($jobs): void
    {
        $this->info('  [DRY RUN] Would send reminders for these jobs:');

        $headers = ['ID', 'Title', 'Worker', 'Date', 'Time', 'Location'];
        $rows = [];

        foreach ($jobs as $job) {
            $rows[] = [
                $job->id,
                mb_substr($job->title, 0, 20),
                $job->assignedWorker?->name ?? 'Unknown',
                $job->job_date->format('M d'),
                $job->job_time ?? 'Flexible',
                mb_substr($job->location_name, 0, 20),
            ];
        }

        $this->table($headers, $rows);
    }
}