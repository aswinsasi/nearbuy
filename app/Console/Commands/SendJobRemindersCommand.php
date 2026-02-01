<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Models\JobPost;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Send reminders for jobs starting soon.
 *
 * This command sends reminder notifications to workers and posters
 * for jobs starting within the next hour.
 *
 * @example
 * php artisan jobs:send-reminders
 * php artisan jobs:send-reminders --minutes=30
 * php artisan jobs:send-reminders --dry-run
 *
 * @srs-ref Njaanum Panikkar Module - Job Reminders
 */
class SendJobRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'jobs:send-reminders
                            {--minutes=60 : Minutes before job to send reminder}
                            {--dry-run : Show what would be sent without sending}';

    /**
     * The console command description.
     */
    protected $description = 'Send reminders for jobs starting within the next hour';

    /**
     * Cache key prefix for tracking sent reminders.
     */
    protected const REMINDER_CACHE_PREFIX = 'job_reminder_sent_';

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppService $whatsApp): int
    {
        $minutes = (int) $this->option('minutes');
        $this->info("Checking for jobs starting within {$minutes} minutes...");

        // Find assigned jobs starting soon
        $upcomingJobs = JobPost::query()
            ->where('status', JobStatus::ASSIGNED)
            ->where('job_date', now()->toDateString())
            ->whereNotNull('job_time')
            ->whereBetween('job_time', [
                now()->format('H:i:s'),
                now()->addMinutes($minutes)->format('H:i:s'),
            ])
            ->with(['poster', 'assignedWorker.user', 'category'])
            ->get();

        // Also check jobs scheduled for today that don't have specific time
        // but haven't had reminders sent yet
        $todayJobs = JobPost::query()
            ->where('status', JobStatus::ASSIGNED)
            ->where('job_date', now()->toDateString())
            ->whereNull('job_time')
            ->where('created_at', '<', now()->subHours(2)) // Give some buffer after assignment
            ->with(['poster', 'assignedWorker.user', 'category'])
            ->get();

        $allJobs = $upcomingJobs->merge($todayJobs);

        // Filter out jobs that already had reminders sent
        $jobsNeedingReminder = $allJobs->filter(function ($job) {
            $cacheKey = self::REMINDER_CACHE_PREFIX . $job->id;
            return !Cache::has($cacheKey);
        });

        if ($jobsNeedingReminder->isEmpty()) {
            $this->info('No jobs need reminders at this time.');
            return self::SUCCESS;
        }

        $this->info("Found {$jobsNeedingReminder->count()} job(s) needing reminders.");

        if ($this->option('dry-run')) {
            return $this->showJobs($jobsNeedingReminder);
        }

        $workersNotified = 0;
        $postersNotified = 0;

        foreach ($jobsNeedingReminder as $job) {
            try {
                // Send reminder to worker
                $workersNotified += $this->sendWorkerReminder($whatsApp, $job);

                // Send reminder to poster
                $postersNotified += $this->sendPosterReminder($whatsApp, $job);

                // Mark reminder as sent (cache for 24 hours)
                $cacheKey = self::REMINDER_CACHE_PREFIX . $job->id;
                Cache::put($cacheKey, true, now()->addHours(24));

                Log::info('Job reminders sent', [
                    'job_id' => $job->id,
                    'title' => $job->title,
                ]);

            } catch (\Exception $e) {
                $this->error("Failed to send reminders for job {$job->id}: {$e->getMessage()}");
                Log::error('Failed to send job reminders', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("✅ Sent reminders: {$workersNotified} worker(s), {$postersNotified} poster(s).");

        return self::SUCCESS;
    }

    /**
     * Send reminder to worker.
     */
    protected function sendWorkerReminder(WhatsAppService $whatsApp, JobPost $job): int
    {
        $worker = $job->assignedWorker;

        if (!$worker || !$worker->user || !$worker->user->phone) {
            return 0;
        }

        try {
            $timeDisplay = $job->job_time
                ? $job->formatted_time
                : 'Today (time flexible)';

            $message = "⏰ *Job Reminder*\n" .
                "*ജോലി ഓർമ്മപ്പെടുത്തൽ*\n\n" .
                "{$job->category->icon} *{$job->title}*\n\n" .
                "📅 *When:* {$timeDisplay}\n" .
                "📍 *Where:* {$job->location_display}\n" .
                "💰 *Pay:* {$job->pay_display}\n\n" .
                "👤 *Posted by:* {$job->poster->display_name}\n";

            if ($job->poster->phone) {
                $formattedPhone = preg_replace('/^91/', '', $job->poster->phone);
                $message .= "📞 Contact: {$formattedPhone}\n";
            }

            $message .= "\n_Don't forget to click 'Start Job' when you arrive!_\n" .
                "_എത്തുമ്പോൾ 'Start Job' അമർത്താൻ മറക്കരുത്!_";

            $buttons = [
                ['id' => 'start_job_' . $job->id, 'title' => '▶️ Start Job'],
                ['id' => 'get_directions_' . $job->id, 'title' => '📍 Get Directions'],
            ];

            // Add call button if poster has phone
            if ($job->poster->phone) {
                $buttons[] = ['id' => 'call_poster_' . $job->poster_user_id, 'title' => '📞 Call'];
            }

            $whatsApp->sendButtons(
                $worker->user->phone,
                $message,
                array_slice($buttons, 0, 3), // WhatsApp allows max 3 buttons
                '⏰ Job Reminder'
            );

            return 1;

        } catch (\Exception $e) {
            Log::error('Failed to send worker reminder', [
                'job_id' => $job->id,
                'worker_id' => $worker->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Send reminder to poster.
     */
    protected function sendPosterReminder(WhatsAppService $whatsApp, JobPost $job): int
    {
        $poster = $job->poster;

        if (!$poster || !$poster->phone) {
            return 0;
        }

        $worker = $job->assignedWorker;

        if (!$worker) {
            return 0;
        }

        try {
            $timeDisplay = $job->job_time
                ? $job->formatted_time
                : 'Today (time flexible)';

            $message = "⏰ *Worker Arriving Soon*\n" .
                "*പണിക്കാരൻ ഉടൻ വരും*\n\n" .
                "{$job->category->icon} *{$job->title}*\n\n" .
                "👤 *Worker:* {$worker->name}\n" .
                "⭐ Rating: {$worker->short_rating}\n" .
                "📅 *When:* {$timeDisplay}\n" .
                "💰 *Pay:* {$job->pay_display}\n\n";

            if ($worker->user && $worker->user->phone) {
                $formattedPhone = preg_replace('/^91/', '', $worker->user->phone);
                $message .= "📞 Worker contact: {$formattedPhone}\n\n";
            }

            $message .= "_You'll be notified when the worker arrives._\n" .
                "_പണിക്കാരൻ എത്തുമ്പോൾ അറിയിക്കും._";

            $buttons = [
                ['id' => 'view_job_' . $job->id, 'title' => '📋 View Details'],
            ];

            if ($worker->user && $worker->user->phone) {
                $buttons[] = ['id' => 'call_worker_' . $worker->id, 'title' => '📞 Call Worker'];
            }

            $buttons[] = ['id' => 'main_menu', 'title' => '🏠 Menu'];

            $whatsApp->sendButtons(
                $poster->phone,
                $message,
                array_slice($buttons, 0, 3),
                '⏰ Reminder'
            );

            return 1;

        } catch (\Exception $e) {
            Log::error('Failed to send poster reminder', [
                'job_id' => $job->id,
                'poster_id' => $poster->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Show jobs that would get reminders (dry run).
     */
    protected function showJobs($jobs): int
    {
        $headers = ['ID', 'Title', 'Worker', 'Poster', 'Date', 'Time'];
        $rows = [];

        foreach ($jobs as $job) {
            $rows[] = [
                $job->id,
                mb_substr($job->title, 0, 20),
                $job->assignedWorker?->name ?? 'Unknown',
                $job->poster?->name ?? 'Unknown',
                $job->job_date->format('Y-m-d'),
                $job->job_time ?? 'Flexible',
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}