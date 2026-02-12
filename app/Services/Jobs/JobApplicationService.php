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
 * - Worker applications to jobs (NP-017)
 * - Application acceptance/rejection (NP-019, NP-020, NP-021)
 * - Application queries and statistics
 * - Notification helpers
 *
 * @srs-ref NP-015 to NP-021
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
    | Application Creation (NP-017)
    |--------------------------------------------------------------------------
    */

    /**
     * Apply to a job (instant apply without message).
     *
     * @srs-ref NP-017 - Worker can apply with optional message
     *
     * @param JobWorker $worker The worker applying
     * @param JobPost $job The job to apply for
     * @return JobApplication
     * @throws \InvalidArgumentException
     */
    public function applyToJob(JobWorker $worker, JobPost $job): JobApplication
    {
        return $this->applyToJobWithMessage($worker, $job, null);
    }

    /**
     * Apply to a job with optional message.
     *
     * @srs-ref NP-017 - Worker can apply with optional message (availability/experience)
     *
     * @param JobWorker $worker The worker applying
     * @param JobPost $job The job to apply for
     * @param string|null $message Optional message to poster
     * @param float|null $proposedAmount Optional proposed amount
     * @return JobApplication
     * @throws \InvalidArgumentException
     */
    public function applyToJobWithMessage(
        JobWorker $worker,
        JobPost $job,
        ?string $message = null,
        ?float $proposedAmount = null
    ): JobApplication {
        // Validate application is possible
        $this->validateApplication($worker, $job);

        return DB::transaction(function () use ($worker, $job, $message, $proposedAmount) {
            // Calculate distance
            $distanceKm = $this->calculateDistance($worker, $job);

            // Create application
            $application = JobApplication::create([
                'job_post_id' => $job->id,
                'worker_id' => $worker->id,
                'message' => $message ? mb_substr(trim($message), 0, 300) : null,
                'proposed_amount' => $proposedAmount,
                'distance_km' => $distanceKm,
                'status' => JobApplicationStatus::PENDING,
                'applied_at' => now(),
            ]);

            Log::info('Job application created', [
                'application_id' => $application->id,
                'job_id' => $job->id,
                'worker_id' => $worker->id,
                'has_message' => !empty($message),
                'distance_km' => $distanceKm,
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
                'Ee job ippo applications accept cheyyunnilla.'
            );
        }

        // Check job hasn't expired
        if ($job->is_expired) {
            throw new \InvalidArgumentException(
                'Ee job expire aayittund.'
            );
        }

        // Check worker is not the poster
        if ($worker->user_id === $job->poster_user_id) {
            throw new \InvalidArgumentException(
                'Swantham job-inu apply cheyyaan pattilla.'
            );
        }

        // Check worker hasn't already applied
        if ($this->hasWorkerApplied($worker, $job)) {
            throw new \InvalidArgumentException(
                'Ningal ee job-inu already apply cheythittund.'
            );
        }

        // Check worker doesn't have too many pending applications
        $pendingCount = $this->getWorkerPendingApplicationsCount($worker);
        if ($pendingCount >= self::MAX_PENDING_PER_WORKER) {
            throw new \InvalidArgumentException(
                'Too many pending applications. Response varunnathu vare wait cheyyuka.'
            );
        }

        // Check job hasn't reached max applications
        if ($job->applications_count >= self::MAX_APPLICATIONS_PER_JOB) {
            throw new \InvalidArgumentException(
                'Ee job maximum applications-il ethi.'
            );
        }

        // Check worker is available (no active job at conflicting time)
        $activeJob = $this->getWorkerActiveJob($worker);
        if ($activeJob && $this->hasTimeConflict($activeJob, $job)) {
            throw new \InvalidArgumentException(
                'Ee time-nu vere job und. Time conflict.'
            );
        }
    }

    /**
     * Calculate distance between worker and job location.
     */
    protected function calculateDistance(JobWorker $worker, JobPost $job): ?float
    {
        if (!$job->latitude || !$job->longitude) {
            return null;
        }

        if (!$worker->latitude || !$worker->longitude) {
            return null;
        }

        // Haversine formula
        $earthRadius = 6371; // km

        $latFrom = deg2rad((float) $worker->latitude);
        $lonFrom = deg2rad((float) $worker->longitude);
        $latTo = deg2rad((float) $job->latitude);
        $lonTo = deg2rad((float) $job->longitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) ** 2 +
            cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    /**
     * Check if two jobs have overlapping times.
     */
    protected function hasTimeConflict(JobPost $existingJob, JobPost $newJob): bool
    {
        // Different days - no conflict
        if (!$existingJob->job_date || !$newJob->job_date) {
            return false;
        }

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
    | Application Status Management (NP-019, NP-020, NP-021)
    |--------------------------------------------------------------------------
    */

    /**
     * Accept an application and assign worker to job.
     *
     * @srs-ref NP-019 - Task giver selects ONE worker via button
     * @srs-ref NP-020 - Notify selected worker with confirmation + contact
     *
     * @param JobApplication $application
     * @throws \Exception
     */
    public function acceptApplication(JobApplication $application): void
    {
        $status = $application->status instanceof JobApplicationStatus
            ? $application->status
            : JobApplicationStatus::tryFrom($application->status);

        if ($status !== JobApplicationStatus::PENDING) {
            throw new \InvalidArgumentException(
                'Pending applications mathrame accept cheyyaan pattullu.'
            );
        }

        $job = $application->jobPost;

        if ($job->status !== JobStatus::OPEN) {
            throw new \InvalidArgumentException(
                'Ee job ippo open alla.'
            );
        }

        DB::transaction(function () use ($application, $job) {
            // Accept this application
            $application->update([
                'status' => JobApplicationStatus::ACCEPTED,
                'responded_at' => now(),
            ]);

            // Assign worker to job
            $job->update([
                'status' => JobStatus::ASSIGNED,
                'assigned_worker_id' => $application->worker_id,
            ]);

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
        $status = $application->status instanceof JobApplicationStatus
            ? $application->status
            : JobApplicationStatus::tryFrom($application->status);

        if ($status !== JobApplicationStatus::PENDING) {
            throw new \InvalidArgumentException(
                'Pending applications mathrame reject cheyyaan pattullu.'
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
     * @srs-ref NP-021 - Notify REJECTED workers that position filled
     *
     * @param JobPost $job
     * @param JobApplication $except The application to keep
     * @return int Number of applications rejected
     */
    public function rejectAllOtherApplications(JobPost $job, JobApplication $except): int
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

        return $updated;
    }

    /**
     * Get rejected applications for notification.
     *
     * @srs-ref NP-021 - For notifying rejected workers
     *
     * @return Collection<JobApplication>
     */
    public function getRejectedApplicationsForNotification(JobPost $job, JobApplication $except): Collection
    {
        return JobApplication::where('job_post_id', $job->id)
            ->where('id', '!=', $except->id)
            ->where('status', JobApplicationStatus::REJECTED)
            ->whereNotNull('responded_at')
            ->where('responded_at', '>=', now()->subMinutes(5)) // Recently rejected
            ->with(['worker.user'])
            ->get();
    }

    /**
     * Withdraw an application (worker initiated).
     *
     * @param JobApplication $application
     */
    public function withdrawApplication(JobApplication $application): void
    {
        $status = $application->status instanceof JobApplicationStatus
            ? $application->status
            : JobApplicationStatus::tryFrom($application->status);

        if ($status !== JobApplicationStatus::PENDING) {
            throw new \InvalidArgumentException(
                'Pending applications mathrame withdraw cheyyaan pattullu.'
            );
        }

        DB::transaction(function () use ($application) {
            $application->update([
                'status' => JobApplicationStatus::WITHDRAWN,
                'responded_at' => now(),
            ]);

            // Decrement job applications count
            $application->jobPost?->decrementApplicationsCount();
        });

        Log::info('Application withdrawn', [
            'application_id' => $application->id,
            'job_id' => $application->job_post_id,
            'worker_id' => $application->worker_id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Application Queries (NP-015, NP-016, NP-018)
    |--------------------------------------------------------------------------
    */

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
     * Get all applications for a job.
     *
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
     * Get pending applications for a job (for task giver review).
     *
     * @srs-ref NP-018 - Show task giver ALL applications
     *
     * @param JobPost $job
     * @return Collection<JobApplication>
     */
    public function getPendingApplications(JobPost $job): Collection
    {
        return JobApplication::where('job_post_id', $job->id)
            ->where('status', JobApplicationStatus::PENDING)
            ->with(['worker.user'])
            ->orderBy('applied_at', 'asc') // FIFO order
            ->get();
    }

    /**
     * Get pending applications count.
     *
     * @srs-ref NP-016 - Social proof "X workers already applied"
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
            ->with(['worker.user'])
            ->first();
    }

    /**
     * Get application by ID with relationships.
     */
    public function getApplicationById(int $applicationId): ?JobApplication
    {
        return JobApplication::with(['worker.user', 'jobPost.category', 'jobPost.poster'])
            ->find($applicationId);
    }

    /**
     * Get the position of an application in queue.
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

    /**
     * Get next pending application for review (one-at-a-time viewing).
     *
     * @srs-ref NP-018 - Show one applicant at a time
     */
    public function getNextPendingApplication(JobPost $job, ?int $afterApplicationId = null): ?JobApplication
    {
        $query = JobApplication::where('job_post_id', $job->id)
            ->where('status', JobApplicationStatus::PENDING)
            ->with(['worker.user'])
            ->orderBy('applied_at', 'asc');

        if ($afterApplicationId) {
            $currentApp = JobApplication::find($afterApplicationId);
            if ($currentApp) {
                $query->where('applied_at', '>', $currentApp->applied_at);
            }
        }

        return $query->first();
    }

    /**
     * Get first pending application (for starting review).
     */
    public function getFirstPendingApplication(JobPost $job): ?JobApplication
    {
        return JobApplication::where('job_post_id', $job->id)
            ->where('status', JobApplicationStatus::PENDING)
            ->with(['worker.user'])
            ->orderBy('applied_at', 'asc')
            ->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Get worker's currently active job (assigned but not completed).
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
        ];
    }

    /**
     * Get application statistics for a worker.
     */
    public function getWorkerApplicationStats(JobWorker $worker): array
    {
        $applications = JobApplication::where('worker_id', $worker->id)->get();

        $accepted = $applications->where('status', JobApplicationStatus::ACCEPTED)->count();
        $rejected = $applications->where('status', JobApplicationStatus::REJECTED)->count();

        $totalResponded = $accepted + $rejected;
        $acceptanceRate = $totalResponded > 0
            ? round(($accepted / $totalResponded) * 100, 1)
            : 0;

        return [
            'total_applications' => $applications->count(),
            'pending' => $applications->where('status', JobApplicationStatus::PENDING)->count(),
            'accepted' => $accepted,
            'rejected' => $rejected,
            'withdrawn' => $applications->where('status', JobApplicationStatus::WITHDRAWN)->count(),
            'acceptance_rate' => $acceptanceRate,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance
    |--------------------------------------------------------------------------
    */

    /**
     * Auto-withdraw stale pending applications.
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
}