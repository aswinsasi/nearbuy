<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\JobApplicationStatus;
use App\Enums\JobStatus;
use App\Models\JobApplication;
use App\Models\JobPost;
use App\Models\JobWorker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing job applications.
 *
 * Handles:
 * - Worker applications to jobs
 * - Application acceptance/rejection
 * - Application queries and statistics
 *
 * @srs-ref Section 3.4 - Job Applications
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobApplicationService
{
    /**
     * Maximum applications per job.
     */
    public const MAX_APPLICATIONS_PER_JOB = 50;

    /**
     * Maximum pending applications per worker.
     */
    public const MAX_PENDING_PER_WORKER = 10;

    /*
    |--------------------------------------------------------------------------
    | Application Creation
    |--------------------------------------------------------------------------
    */

    /**
     * Apply to a job.
     *
     * @param JobWorker $worker The worker applying
     * @param JobPost $job The job to apply for
     * @param string|null $message Optional message to poster
     * @param float|null $proposedAmount Optional proposed amount (different from posted)
     * @return JobApplication
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function applyToJob(
        JobWorker $worker,
        JobPost $job,
        ?string $message = null,
        ?float $proposedAmount = null
    ): JobApplication {
        // Validate application is possible
        $this->validateApplication($worker, $job);

        return DB::transaction(function () use ($worker, $job, $message, $proposedAmount) {
            // Create application
            $application = JobApplication::create([
                'job_post_id' => $job->id,
                'worker_id' => $worker->id,
                'message' => $message ? mb_substr(trim($message), 0, 300) : null,
                'proposed_amount' => $proposedAmount,
                'status' => JobApplicationStatus::PENDING,
                'applied_at' => now(),
            ]);

            // Note: applications_count is automatically incremented in JobApplication::boot()

            Log::info('Job application created', [
                'application_id' => $application->id,
                'job_id' => $job->id,
                'worker_id' => $worker->id,
                'proposed_amount' => $proposedAmount,
            ]);

            return $application;
        });
    }

    /**
     * Validate that a worker can apply to a job.
     *
     * @throws \InvalidArgumentException
     */
    protected function validateApplication(JobWorker $worker, JobPost $job): void
    {
        // Check job is open
        if ($job->status !== JobStatus::OPEN) {
            throw new \InvalidArgumentException(
                'This job is no longer accepting applications.'
            );
        }

        // Check job hasn't expired
        if ($job->is_expired) {
            throw new \InvalidArgumentException(
                'This job has expired.'
            );
        }

        // Check worker hasn't already applied
        if ($this->hasWorkerApplied($worker, $job)) {
            throw new \InvalidArgumentException(
                'You have already applied for this job.'
            );
        }

        // Check worker doesn't have too many pending applications
        $pendingCount = $this->getWorkerPendingApplicationsCount($worker);
        if ($pendingCount >= self::MAX_PENDING_PER_WORKER) {
            throw new \InvalidArgumentException(
                'You have too many pending applications. Please wait for responses.'
            );
        }

        // Check job hasn't reached max applications
        if ($job->applications_count >= self::MAX_APPLICATIONS_PER_JOB) {
            throw new \InvalidArgumentException(
                'This job has reached maximum applications.'
            );
        }

        // Check worker is available for job time
        $activeJob = $this->getWorkerActiveJob($worker);
        if ($activeJob && $this->hasTimeConflict($activeJob, $job)) {
            throw new \InvalidArgumentException(
                'You have another job scheduled at this time.'
            );
        }

        // Check worker can do this job type
        $category = $job->category;
        if ($category && !$worker->canAcceptJob($category)) {
            throw new \InvalidArgumentException(
                'This job type is not in your registered skills.'
            );
        }
    }

    /**
     * Check if two jobs have overlapping times.
     */
    protected function hasTimeConflict(JobPost $existingJob, JobPost $newJob): bool
    {
        // Different days - no conflict
        if (!$existingJob->job_date->isSameDay($newJob->job_date)) {
            return false;
        }

        // If either doesn't have specific time, assume potential conflict
        if (!$existingJob->job_time || !$newJob->job_time) {
            return true;
        }

        // TODO: Implement proper time range overlap check
        // For now, same day = potential conflict
        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Application Status Management
    |--------------------------------------------------------------------------
    */

    /**
     * Accept an application and assign worker to job.
     *
     * @param JobApplication $application
     * @throws \Exception
     */
    public function acceptApplication(JobApplication $application): void
    {
        if ($application->status !== JobApplicationStatus::PENDING) {
            throw new \InvalidArgumentException(
                'Only pending applications can be accepted.'
            );
        }

        $job = $application->jobPost;

        if ($job->status !== JobStatus::OPEN) {
            throw new \InvalidArgumentException(
                'This job is no longer open for selection.'
            );
        }

        DB::transaction(function () use ($application, $job) {
            // Accept this application
            $application->update([
                'status' => JobApplicationStatus::ACCEPTED,
                'responded_at' => now(),
            ]);

            // Assign worker to job
            $job->assign($application->worker);

            // Reject all other pending applications
            $this->rejectAllOtherApplications($job, $application);

            Log::info('Application accepted', [
                'application_id' => $application->id,
                'job_id' => $job->id,
                'worker_id' => $application->worker_id,
            ]);
        });
    }

    /**
     * Reject an application.
     *
     * @param JobApplication $application
     * @param string|null $reason Optional rejection reason
     */
    public function rejectApplication(JobApplication $application, ?string $reason = null): void
    {
        if ($application->status !== JobApplicationStatus::PENDING) {
            throw new \InvalidArgumentException(
                'Only pending applications can be rejected.'
            );
        }

        $application->update([
            'status' => JobApplicationStatus::REJECTED,
            'responded_at' => now(),
        ]);

        Log::info('Application rejected', [
            'application_id' => $application->id,
            'job_id' => $application->job_post_id,
            'worker_id' => $application->worker_id,
            'reason' => $reason,
        ]);
    }

    /**
     * Reject all pending applications for a job except one.
     *
     * @param JobPost $job
     * @param JobApplication $except The application to keep
     */
    public function rejectAllOtherApplications(JobPost $job, JobApplication $except): void
    {
        $updated = JobApplication::where('job_post_id', $job->id)
            ->where('id', '!=', $except->id)
            ->where('status', JobApplicationStatus::PENDING)
            ->update([
                'status' => JobApplicationStatus::REJECTED,
                'responded_at' => now(),
            ]);

        Log::info('Other applications rejected', [
            'job_id' => $job->id,
            'accepted_application_id' => $except->id,
            'rejected_count' => $updated,
        ]);
    }

    /**
     * Withdraw an application (worker initiated).
     *
     * @param JobApplication $application
     */
    public function withdrawApplication(JobApplication $application): void
    {
        if ($application->status !== JobApplicationStatus::PENDING) {
            throw new \InvalidArgumentException(
                'Only pending applications can be withdrawn.'
            );
        }

        DB::transaction(function () use ($application) {
            $application->update([
                'status' => JobApplicationStatus::WITHDRAWN,
                'responded_at' => now(),
            ]);

            // Decrement job applications count
            $application->jobPost->decrementApplicationsCount();
        });

        Log::info('Application withdrawn', [
            'application_id' => $application->id,
            'job_id' => $application->job_post_id,
            'worker_id' => $application->worker_id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Application Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Get all applications for a job.
     *
     * @param JobPost $job
     * @return Collection<JobApplication>
     */
    public function getApplicationsForJob(JobPost $job): Collection
    {
        return JobApplication::where('job_post_id', $job->id)
            ->with(['worker.user'])
            ->orderBy('applied_at', 'desc')
            ->get();
    }

    /**
     * Get pending applications for a job.
     *
     * @param JobPost $job
     * @return Collection<JobApplication>
     */
    public function getPendingApplications(JobPost $job): Collection
    {
        return JobApplication::where('job_post_id', $job->id)
            ->where('status', JobApplicationStatus::PENDING)
            ->with(['worker.user'])
            ->orderBy('applied_at', 'asc')
            ->get();
    }

    /**
     * Get pending applications count for a job.
     */
    public function getPendingApplicationsCount(JobPost $job): int
    {
        return JobApplication::where('job_post_id', $job->id)
            ->where('status', JobApplicationStatus::PENDING)
            ->count();
    }

    /**
     * Get the accepted application for a job.
     */
    public function getAcceptedApplication(JobPost $job): ?JobApplication
    {
        return JobApplication::where('job_post_id', $job->id)
            ->where('status', JobApplicationStatus::ACCEPTED)
            ->with(['worker'])
            ->first();
    }

    /**
     * Check if a worker has applied to a job.
     */
    public function hasWorkerApplied(JobWorker $worker, JobPost $job): bool
    {
        return JobApplication::where('job_post_id', $job->id)
            ->where('worker_id', $worker->id)
            ->exists();
    }

    /**
     * Get a worker's application for a job.
     */
    public function getWorkerApplication(JobWorker $worker, JobPost $job): ?JobApplication
    {
        return JobApplication::where('job_post_id', $job->id)
            ->where('worker_id', $worker->id)
            ->first();
    }

    /**
     * Get worker's currently active application (accepted but job not completed).
     */
    public function getWorkerActiveApplication(JobWorker $worker): ?JobApplication
    {
        return JobApplication::where('worker_id', $worker->id)
            ->where('status', JobApplicationStatus::ACCEPTED)
            ->whereHas('jobPost', function ($q) {
                $q->whereIn('status', [
                    JobStatus::ASSIGNED,
                    JobStatus::IN_PROGRESS,
                ]);
            })
            ->with(['jobPost.category'])
            ->first();
    }

    /**
     * Get worker's active job (assigned or in progress).
     */
    public function getWorkerActiveJob(JobWorker $worker): ?JobPost
    {
        return JobPost::where('assigned_worker_id', $worker->id)
            ->whereIn('status', [
                JobStatus::ASSIGNED,
                JobStatus::IN_PROGRESS,
            ])
            ->first();
    }

    /**
     * Get worker's pending applications count.
     */
    public function getWorkerPendingApplicationsCount(JobWorker $worker): int
    {
        return JobApplication::where('worker_id', $worker->id)
            ->where('status', JobApplicationStatus::PENDING)
            ->count();
    }

    /**
     * Get worker's pending applications.
     *
     * @return Collection<JobApplication>
     */
    public function getWorkerPendingApplications(JobWorker $worker): Collection
    {
        return JobApplication::where('worker_id', $worker->id)
            ->where('status', JobApplicationStatus::PENDING)
            ->with(['jobPost.category', 'jobPost.poster'])
            ->orderBy('applied_at', 'desc')
            ->get();
    }

    /**
     * Get worker's application history.
     *
     * @param JobWorker $worker
     * @param int $limit
     * @return Collection<JobApplication>
     */
    public function getWorkerApplicationHistory(JobWorker $worker, int $limit = 20): Collection
    {
        return JobApplication::where('worker_id', $worker->id)
            ->with(['jobPost.category', 'jobPost.poster'])
            ->orderBy('applied_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get position of an application in queue.
     *
     * @param JobApplication $application
     * @return int Position (1-based)
     */
    public function getApplicationPosition(JobApplication $application): int
    {
        $earlierApplications = JobApplication::where('job_post_id', $application->job_post_id)
            ->where('applied_at', '<', $application->applied_at)
            ->count();

        return $earlierApplications + 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get application statistics for a job.
     */
    public function getJobApplicationStats(JobPost $job): array
    {
        $applications = JobApplication::where('job_post_id', $job->id)->get();

        return [
            'total' => $applications->count(),
            'pending' => $applications->where('status', JobApplicationStatus::PENDING)->count(),
            'accepted' => $applications->where('status', JobApplicationStatus::ACCEPTED)->count(),
            'rejected' => $applications->where('status', JobApplicationStatus::REJECTED)->count(),
            'withdrawn' => $applications->where('status', JobApplicationStatus::WITHDRAWN)->count(),
            'average_wait_time' => $this->calculateAverageWaitTime($applications),
        ];
    }

    /**
     * Get application statistics for a worker.
     */
    public function getWorkerApplicationStats(JobWorker $worker): array
    {
        $applications = JobApplication::where('worker_id', $worker->id)->get();

        $accepted = $applications->where('status', JobApplicationStatus::ACCEPTED);
        $rejected = $applications->where('status', JobApplicationStatus::REJECTED);

        $totalResponded = $accepted->count() + $rejected->count();
        $acceptanceRate = $totalResponded > 0
            ? round(($accepted->count() / $totalResponded) * 100, 1)
            : 0;

        return [
            'total_applications' => $applications->count(),
            'pending' => $applications->where('status', JobApplicationStatus::PENDING)->count(),
            'accepted' => $accepted->count(),
            'rejected' => $rejected->count(),
            'withdrawn' => $applications->where('status', JobApplicationStatus::WITHDRAWN)->count(),
            'acceptance_rate' => $acceptanceRate,
        ];
    }

    /**
     * Calculate average wait time for applications.
     */
    protected function calculateAverageWaitTime(Collection $applications): ?float
    {
        $respondedApplications = $applications->filter(function ($app) {
            return $app->responded_at !== null;
        });

        if ($respondedApplications->isEmpty()) {
            return null;
        }

        $totalMinutes = $respondedApplications->sum(function ($app) {
            return $app->applied_at->diffInMinutes($app->responded_at);
        });

        return round($totalMinutes / $respondedApplications->count(), 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Cleanup & Maintenance
    |--------------------------------------------------------------------------
    */

    /**
     * Auto-withdraw stale pending applications.
     *
     * Called by scheduled command.
     *
     * @param int $hoursOld Applications older than this are withdrawn
     * @return int Number of applications withdrawn
     */
    public function withdrawStaleApplications(int $hoursOld = 72): int
    {
        $cutoff = now()->subHours($hoursOld);

        $staleApplications = JobApplication::where('status', JobApplicationStatus::PENDING)
            ->where('applied_at', '<', $cutoff)
            ->whereHas('jobPost', function ($q) {
                $q->where('status', JobStatus::OPEN);
            })
            ->get();

        $count = 0;
        foreach ($staleApplications as $application) {
            try {
                $this->withdrawApplication($application);
                $count++;
            } catch (\Exception $e) {
                Log::error('Failed to withdraw stale application', [
                    'application_id' => $application->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Stale applications withdrawn', [
            'withdrawn_count' => $count,
            'hours_old' => $hoursOld,
        ]);

        return $count;
    }

    /**
     * Get applications that need poster reminder.
     *
     * Returns applications that have been pending for X hours
     * and poster hasn't responded to any applications.
     *
     * @param int $hoursOld
     * @return Collection<JobApplication>
     */
    public function getApplicationsNeedingReminder(int $hoursOld = 4): Collection
    {
        $cutoff = now()->subHours($hoursOld);

        return JobApplication::where('status', JobApplicationStatus::PENDING)
            ->where('applied_at', '<', $cutoff)
            ->whereHas('jobPost', function ($q) {
                $q->where('status', JobStatus::OPEN)
                    ->whereDoesntHave('applications', function ($q2) {
                        $q2->whereIn('status', [
                            JobApplicationStatus::ACCEPTED,
                            JobApplicationStatus::REJECTED,
                        ]);
                    });
            })
            ->with(['jobPost.poster', 'worker'])
            ->get()
            ->unique('job_post_id'); // One reminder per job
    }
}