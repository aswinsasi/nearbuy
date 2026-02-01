<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\JobApplicationStatus;
use App\Enums\JobStatus;
use App\Models\JobApplication;
use App\Models\JobPost;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\JobMessages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Expire jobs that have passed their expires_at timestamp.
 *
 * This command marks open jobs as expired and notifies relevant parties.
 *
 * @example
 * php artisan jobs:expire
 * php artisan jobs:expire --notify
 * php artisan jobs:expire --dry-run
 *
 * @srs-ref Njaanum Panikkar Module - Job Lifecycle
 */
class ExpireJobsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'jobs:expire
                            {--notify : Notify posters and applicants of expired jobs}
                            {--dry-run : Show what would expire without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Expire open jobs that have passed their expiration time';

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppService $whatsApp): int
    {
        $this->info('Checking for expired jobs...');

        // Find jobs to expire
        $jobsToExpire = JobPost::query()
            ->where('status', JobStatus::OPEN)
            ->where('expires_at', '<', now())
            ->with(['poster', 'applications.worker.user', 'category'])
            ->get();

        if ($jobsToExpire->isEmpty()) {
            $this->info('No jobs to expire.');
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
                // Update job status
                $job->update(['status' => JobStatus::EXPIRED]);
                $expired++;

                // Reject all pending applications
                $pendingApplications = $job->applications()
                    ->where('status', JobApplicationStatus::PENDING)
                    ->get();

                foreach ($pendingApplications as $application) {
                    $application->update(['status' => JobApplicationStatus::REJECTED]);
                }

                // Notify poster and applicants if requested
                if ($this->option('notify')) {
                    $postersNotified += $this->notifyPoster($whatsApp, $job);
                    $applicantsNotified += $this->notifyApplicants($whatsApp, $job, $pendingApplications);
                }

                Log::info('Job expired', [
                    'job_id' => $job->id,
                    'title' => $job->title,
                    'applications_rejected' => $pendingApplications->count(),
                ]);

            } catch (\Exception $e) {
                $this->error("Failed to expire job {$job->id}: {$e->getMessage()}");
                Log::error('Failed to expire job', [
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("✅ Expired {$expired} job(s).");

        if ($this->option('notify')) {
            $this->info("📨 Notified {$postersNotified} poster(s) and {$applicantsNotified} applicant(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Notify poster that their job expired.
     */
    protected function notifyPoster(WhatsAppService $whatsApp, JobPost $job): int
    {
        $poster = $job->poster;

        if (!$poster || !$poster->phone) {
            return 0;
        }

        try {
            $message = "⏰ *Job Expired*\n" .
                "*ജോലി കാലഹരണപ്പെട്ടു*\n\n" .
                "{$job->category->icon} *{$job->title}*\n" .
                "💰 {$job->pay_display}\n\n";

            if ($job->applications_count > 0) {
                $message .= "👥 {$job->applications_count} worker(s) had applied.\n\n";
            } else {
                $message .= "No workers applied for this job.\n\n";
            }

            $message .= "You can post this job again if needed.\n" .
                "ആവശ്യമെങ്കിൽ വീണ്ടും പോസ്റ്റ് ചെയ്യാം.";

            $whatsApp->sendButtons(
                $poster->phone,
                $message,
                [
                    ['id' => 'job_post', 'title' => '📝 Post Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
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
     * Notify applicants that the job was cancelled/expired.
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
                    "{$job->category->icon} *{$job->title}*\n\n" .
                    "This job has expired. Don't worry - there are more opportunities!\n" .
                    "ഈ ജോലി കാലഹരണപ്പെട്ടു. വിഷമിക്കേണ്ട - കൂടുതൽ അവസരങ്ങൾ ഉണ്ട്!";

                $whatsApp->sendButtons(
                    $worker->user->phone,
                    $message,
                    [
                        ['id' => 'job_browse', 'title' => '🔍 Find Jobs'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ]
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
        $headers = ['ID', 'Title', 'Poster', 'Pay', 'Applications', 'Expired At'];
        $rows = [];

        foreach ($jobs as $job) {
            $rows[] = [
                $job->id,
                mb_substr($job->title, 0, 25),
                $job->poster?->name ?? 'Unknown',
                $job->pay_display,
                $job->applications_count,
                $job->expires_at->format('Y-m-d H:i'),
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}