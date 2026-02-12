<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\JobApplicationStatus;
use App\Enums\JobStatus;
use App\Models\JobApplication;
use App\Models\JobPost;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Expire OPEN jobs that have been open for more than 24 hours without assignment.
 *
 * Schedule: Run every hour
 *
 * @example
 * php artisan jobs:expire
 * php artisan jobs:expire --hours=24
 * php artisan jobs:expire --notify
 * php artisan jobs:expire --dry-run
 *
 * @srs-ref Njaanum Panikkar Module - Job Lifecycle / Auto-Expiration
 * @schedule Run hourly: $schedule->command('jobs:expire --notify')->hourly();
 */
class ExpireJobsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'jobs:expire
                            {--hours=24 : Hours after which OPEN jobs expire}
                            {--notify : Notify posters and applicants of expired jobs}
                            {--dry-run : Show what would expire without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Expire OPEN jobs that have been open for 24+ hours without assignment';

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppService $whatsApp): int
    {
        $hours = (int) $this->option('hours');

        $this->info("⏰ Checking for OPEN jobs older than {$hours} hours...");

        // Find OPEN jobs to expire (created more than X hours ago, not assigned)
        $jobsToExpire = JobPost::query()
            ->where('status', JobStatus::OPEN)
            ->where('created_at', '<', now()->subHours($hours))
            ->with([
                'poster',
                'category',
                'applications' => fn($q) => $q->where('status', JobApplicationStatus::PENDING),
                'applications.worker.user',
            ])
            ->get();

        if ($jobsToExpire->isEmpty()) {
            $this->info('✓ No jobs to expire.');
            return self::SUCCESS;
        }

        $this->info("Found {$jobsToExpire->count()} job(s) to expire.");

        if ($this->option('dry-run')) {
            return $this->showJobs($jobsToExpire);
        }

        $expired = 0;
        $postersNotified = 0;
        $applicantsNotified = 0;

        foreach ($jobsToExpire as $job) {
            try {
                DB::transaction(function () use ($job, $whatsApp, &$expired, &$postersNotified, &$applicantsNotified) {
                    // Update job status to EXPIRED
                    $job->update([
                        'status' => JobStatus::EXPIRED,
                        'expired_at' => now(),
                    ]);
                    $expired++;

                    // Get pending applications before rejecting them
                    $pendingApplications = $job->applications->where('status', JobApplicationStatus::PENDING);

                    // Reject all pending applications
                    JobApplication::where('job_id', $job->id)
                        ->where('status', JobApplicationStatus::PENDING)
                        ->update(['status' => JobApplicationStatus::REJECTED]);

                    // Notify if requested
                    if ($this->option('notify')) {
                        $postersNotified += $this->notifyPoster($whatsApp, $job);
                        $applicantsNotified += $this->notifyApplicants($whatsApp, $job, $pendingApplications);
                    }

                    Log::info('Job expired', [
                        'job_id' => $job->id,
                        'title' => $job->title,
                        'hours_open' => $job->created_at->diffInHours(now()),
                        'applications_rejected' => $pendingApplications->count(),
                    ]);
                });

            } catch (\Exception $e) {
                $this->error("Failed to expire job {$job->id}: {$e->getMessage()}");
                Log::error('Failed to expire job', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->newLine();
        $this->info("✅ Expired {$expired} job(s).");

        if ($this->option('notify')) {
            $this->info("📨 Notified {$postersNotified} poster(s) and {$applicantsNotified} applicant(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Notify poster that their job expired.
     *
     * Message: "⏰ Job expired (24hrs). [🔄 Repost] [❌ Cancel]"
     */
    protected function notifyPoster(WhatsAppService $whatsApp, JobPost $job): int
    {
        $poster = $job->poster;

        if (!$poster || !$poster->phone) {
            return 0;
        }

        try {
            $applicationsCount = $job->applications->count();
            $hoursOpen = $job->created_at->diffInHours(now());

            // Build message based on whether there were applications
            $message = "⏰ *Job Expired*\n" .
                "*ജോലി കാലഹരണപ്പെട്ടു*\n\n" .
                "{$job->category->icon} *{$job->title}*\n" .
                "💰 {$job->pay_display}\n" .
                "📍 {$job->location_name}\n\n";

            if ($applicationsCount > 0) {
                $message .= "👷 {$applicationsCount} worker(s) had applied.\n" .
                    "{$applicationsCount} പേർ അപേക്ഷിച്ചിരുന്നു.\n\n";
            } else {
                $message .= "No workers applied for this job.\n" .
                    "ഈ ജോലിക്ക് ആരും അപേക്ഷിച്ചില്ല.\n\n";
            }

            $message .= "Your job was open for {$hoursOpen} hours without assignment.\n" .
                "നിങ്ങളുടെ ജോലി {$hoursOpen} മണിക്കൂർ നിയമനമില്ലാതെ തുറന്നിരുന്നു.\n\n" .
                "_Want to try again? Repost the job._\n" .
                "_വീണ്ടും ശ്രമിക്കണോ? ജോലി വീണ്ടും പോസ്റ്റ് ചെയ്യുക._";

            $whatsApp->sendButtons(
                $poster->phone,
                $message,
                [
                    ['id' => 'repost_job_' . $job->id, 'title' => '🔄 Repost Job'],
                    ['id' => 'post_new_job', 'title' => '📝 New Job'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '⏰ Job Expired (24hrs)'
            );

            return 1;

        } catch (\Exception $e) {
            Log::error('Failed to notify poster of expired job', [
                'job_id' => $job->id,
                'poster_id' => $poster->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Notify applicants that the job expired.
     */
    protected function notifyApplicants(WhatsAppService $whatsApp, JobPost $job, $applications): int
    {
        $notified = 0;

        foreach ($applications as $application) {
            $worker = $application->worker;

            if (!$worker || !$worker->user || !$worker->user->phone) {
                continue;
            }

            try {
                $message = "ℹ️ *Job No Longer Available*\n" .
                    "*ജോലി ഇപ്പോൾ ലഭ്യമല്ല*\n\n" .
                    "{$job->category->icon} *{$job->title}*\n" .
                    "💰 {$job->pay_display}\n\n" .
                    "This job has expired without selection.\n" .
                    "ഈ ജോലി തിരഞ്ഞെടുക്കാതെ കാലഹരണപ്പെട്ടു.\n\n" .
                    "Don't worry - more opportunities await!\n" .
                    "വിഷമിക്കേണ്ട - കൂടുതൽ അവസരങ്ങൾ ഉണ്ട്!";

                $whatsApp->sendButtons(
                    $worker->user->phone,
                    $message,
                    [
                        ['id' => 'browse_jobs', 'title' => '🔍 Find Jobs'],
                        ['id' => 'my_applications', 'title' => '📋 My Applications'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                    'ℹ️ Job Expired'
                );

                $notified++;

            } catch (\Exception $e) {
                Log::error('Failed to notify applicant of expired job', [
                    'job_id' => $job->id,
                    'worker_id' => $worker->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $notified;
    }

    /**
     * Show jobs that would be expired (dry run).
     */
    protected function showJobs($jobs): int
    {
        $this->info('[DRY RUN] Would expire these jobs:');
        $this->newLine();

        $headers = ['ID', 'Title', 'Poster', 'Pay', 'Applications', 'Hours Open', 'Created At'];
        $rows = [];

        foreach ($jobs as $job) {
            $rows[] = [
                $job->id,
                mb_substr($job->title, 0, 25),
                $job->poster?->name ?? 'Unknown',
                $job->pay_display,
                $job->applications->count(),
                $job->created_at->diffInHours(now()),
                $job->created_at->format('M d H:i'),
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}