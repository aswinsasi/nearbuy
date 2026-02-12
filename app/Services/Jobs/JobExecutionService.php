<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Models\JobPost;
use App\Models\JobVerification;
use App\Models\JobWorker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing job execution and verification.
 *
 * Handles all SRS requirements NP-022 to NP-028:
 * - NP-022: Worker sends arrival photo
 * - NP-023: Notify task giver with photo and timestamp
 * - NP-024: Handover confirmation for queue jobs
 * - NP-025: Mutual completion confirmation
 * - NP-026: Rating (1-5 stars) + optional review
 * - NP-027: Payment method selection (Cash/UPI)
 * - NP-028: Update worker stats (jobs_completed++, recalculate rating)
 *
 * @srs-ref NP-022 to NP-028
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobExecutionService
{
    /*
    |--------------------------------------------------------------------------
    | Job Execution Start
    |--------------------------------------------------------------------------
    */

    /**
     * Start job execution and create verification record.
     *
     * @param JobPost $job
     * @return JobVerification
     * @throws \InvalidArgumentException
     */
    public function startJobExecution(JobPost $job): JobVerification
    {
        if (!$this->canStartJob($job)) {
            throw new \InvalidArgumentException(
                "Job cannot be started. Status: {$job->status_display}"
            );
        }

        return DB::transaction(function () use ($job) {
            // Check if verification already exists
            $verification = $job->verification;

            if (!$verification) {
                $verification = JobVerification::create([
                    'job_post_id' => $job->id,
                    'worker_id' => $job->assigned_worker_id,
                ]);

                Log::info('Job execution started', [
                    'job_id' => $job->id,
                    'verification_id' => $verification->id,
                    'worker_id' => $job->assigned_worker_id,
                ]);
            }

            return $verification;
        });
    }

    /**
     * Check if a job can be started.
     */
    public function canStartJob(JobPost $job): bool
    {
        $status = $job->status instanceof JobStatus 
            ? $job->status 
            : JobStatus::tryFrom($job->status);

        // Must be assigned or already in progress
        if (!in_array($status, [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])) {
            return false;
        }

        // Must have an assigned worker
        if (!$job->assigned_worker_id) {
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Arrival Recording (NP-022, NP-023)
    |--------------------------------------------------------------------------
    */

    /**
     * Record worker arrival with photo.
     *
     * NP-022: Worker sends arrival photo when reaching job location
     *
     * @param JobVerification $verification
     * @param string|null $photoUrl Arrival photo URL
     * @param float|null $latitude Worker's arrival latitude
     * @param float|null $longitude Worker's arrival longitude
     */
    public function recordArrival(
        JobVerification $verification,
        ?string $photoUrl = null,
        ?float $latitude = null,
        ?float $longitude = null
    ): void {
        DB::transaction(function () use ($verification, $photoUrl, $latitude, $longitude) {
            $verification->recordArrival($photoUrl, $latitude, $longitude);

            // Update worker's last active
            $worker = $verification->worker;
            if ($worker && method_exists($worker, 'touchLastActive')) {
                $worker->touchLastActive();
            }

            Log::info('Worker arrival recorded (NP-022)', [
                'verification_id' => $verification->id,
                'job_id' => $verification->job_post_id,
                'has_photo' => !empty($photoUrl),
                'has_location' => !empty($latitude),
            ]);
        });
    }

    /**
     * Poster confirms worker arrival.
     *
     * NP-023: Notify task giver with photo and timestamp
     *
     * @param JobVerification $verification
     */
    public function confirmArrival(JobVerification $verification): void
    {
        $verification->confirmArrival();

        Log::info('Poster confirmed arrival (NP-023)', [
            'verification_id' => $verification->id,
            'job_id' => $verification->job_post_id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Handover (NP-024)
    |--------------------------------------------------------------------------
    */

    /**
     * Check if job requires handover confirmation.
     *
     * NP-024: For handover jobs (queue standing), BOTH confirm handover
     */
    public function requiresHandover(JobPost $job): bool
    {
        return $job->is_handover_job;
    }

    /**
     * Worker confirms handover.
     *
     * @param JobVerification $verification
     */
    public function confirmHandoverByWorker(JobVerification $verification): void
    {
        $verification->confirmHandoverByWorker();

        Log::info('Worker confirmed handover (NP-024)', [
            'verification_id' => $verification->id,
            'job_id' => $verification->job_post_id,
        ]);
    }

    /**
     * Poster confirms handover.
     *
     * @param JobVerification $verification
     */
    public function confirmHandoverByPoster(JobVerification $verification): void
    {
        $verification->confirmHandoverByPoster();

        Log::info('Poster confirmed handover (NP-024)', [
            'verification_id' => $verification->id,
            'job_id' => $verification->job_post_id,
        ]);
    }

    /**
     * Check if handover is complete (both confirmed).
     */
    public function isHandoverComplete(JobVerification $verification): bool
    {
        return $verification->is_handover_complete;
    }

    /*
    |--------------------------------------------------------------------------
    | Completion Confirmation (NP-025)
    |--------------------------------------------------------------------------
    */

    /**
     * Worker confirms job completion.
     *
     * NP-025: MUTUAL confirmation to mark completed
     *
     * @param JobVerification $verification
     */
    public function confirmCompletionByWorker(JobVerification $verification): void
    {
        $verification->confirmByWorker();

        Log::info('Worker confirmed completion (NP-025)', [
            'verification_id' => $verification->id,
            'job_id' => $verification->job_post_id,
        ]);
    }

    /**
     * Poster confirms job completion.
     *
     * NP-025: MUTUAL confirmation to mark completed
     *
     * @param JobVerification $verification
     */
    public function confirmCompletionByPoster(JobVerification $verification): void
    {
        $verification->confirmByPoster();

        Log::info('Poster confirmed completion (NP-025)', [
            'verification_id' => $verification->id,
            'job_id' => $verification->job_post_id,
        ]);
    }

    /**
     * Check if job is mutually confirmed.
     */
    public function isMutuallyConfirmed(JobVerification $verification): bool
    {
        return $verification->is_mutually_confirmed;
    }

    /*
    |--------------------------------------------------------------------------
    | Rating (NP-026)
    |--------------------------------------------------------------------------
    */

    /**
     * Rate the worker.
     *
     * NP-026: Task giver rates worker (1-5 stars) + optional review
     *
     * @param JobVerification $verification
     * @param int $rating 1-5 stars
     * @param string|null $comment Optional review
     */
    public function rateWorker(
        JobVerification $verification,
        int $rating,
        ?string $comment = null
    ): void {
        $rating = max(1, min(5, $rating));

        DB::transaction(function () use ($verification, $rating, $comment) {
            $verification->rateWorker($rating, $comment);

            Log::info('Worker rated (NP-026)', [
                'verification_id' => $verification->id,
                'worker_id' => $verification->worker_id,
                'rating' => $rating,
                'has_comment' => !empty($comment),
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Payment (NP-027)
    |--------------------------------------------------------------------------
    */

    /**
     * Confirm payment.
     *
     * NP-027: Task giver confirms payment method: Cash or UPI
     *
     * @param JobVerification $verification
     * @param PaymentMethod $method
     * @param string|null $reference Optional payment reference
     */
    public function confirmPayment(
        JobVerification $verification,
        PaymentMethod $method,
        ?string $reference = null
    ): void {
        DB::transaction(function () use ($verification, $method, $reference) {
            $verification->confirmPayment($method, $reference);

            Log::info('Payment confirmed (NP-027)', [
                'verification_id' => $verification->id,
                'job_id' => $verification->job_post_id,
                'method' => $method->value,
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Job Completion (NP-028)
    |--------------------------------------------------------------------------
    */

    /**
     * Complete job and update worker stats.
     *
     * NP-028: Update worker stats: jobs_completed++, recalculate rating
     *
     * @param JobPost $job
     */
    public function completeJob(JobPost $job): void
    {
        $status = $job->status instanceof JobStatus 
            ? $job->status 
            : JobStatus::tryFrom($job->status);

        // Already completed
        if ($status === JobStatus::COMPLETED) {
            return;
        }

        DB::transaction(function () use ($job) {
            // Complete the job
            $job->complete();

            // Update worker stats (NP-028)
            $this->updateWorkerStats($job);

            Log::info('Job completed (NP-028)', [
                'job_id' => $job->id,
                'worker_id' => $job->assigned_worker_id,
                'amount' => $job->pay_amount,
            ]);
        });
    }

    /**
     * Update worker statistics after job completion.
     *
     * NP-028: jobs_completed++, recalculate rating
     *
     * @param JobPost $job
     */
    protected function updateWorkerStats(JobPost $job): void
    {
        $worker = $job->assignedWorker;

        if (!$worker) {
            return;
        }

        // Increment jobs completed
        $worker->increment('jobs_completed');

        // Add to total earnings
        if (method_exists($worker, 'addEarnings')) {
            $worker->addEarnings((float) $job->pay_amount);
        } else {
            $worker->increment('total_earnings', (float) $job->pay_amount);
        }

        // Recalculate rating if verification has rating
        $verification = $job->verification;
        if ($verification && $verification->rating) {
            $this->recalculateWorkerRating($worker);
        }

        // Update last active
        if (method_exists($worker, 'touchLastActive')) {
            $worker->touchLastActive();
        }

        Log::info('Worker stats updated (NP-028)', [
            'worker_id' => $worker->id,
            'jobs_completed' => $worker->jobs_completed,
            'total_earnings' => $worker->total_earnings ?? null,
            'rating' => $worker->rating ?? null,
        ]);
    }

    /**
     * Recalculate worker's average rating.
     *
     * @param JobWorker $worker
     */
    protected function recalculateWorkerRating(JobWorker $worker): void
    {
        if (method_exists($worker, 'recalculateRating')) {
            $worker->recalculateRating();
        } else {
            // Manual calculation
            $avgRating = JobVerification::where('worker_id', $worker->id)
                ->whereNotNull('rating')
                ->avg('rating');

            $ratingCount = JobVerification::where('worker_id', $worker->id)
                ->whereNotNull('rating')
                ->count();

            $worker->update([
                'rating' => $avgRating ? round($avgRating, 1) : null,
                'rating_count' => $ratingCount,
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Query Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get active job for a worker.
     */
    public function getActiveJobForWorker(JobWorker $worker): ?JobPost
    {
        return JobPost::where('assigned_worker_id', $worker->id)
            ->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])
            ->with(['category', 'poster', 'verification'])
            ->first();
    }

    /**
     * Get today's jobs for a worker.
     */
    public function getTodaysJobsForWorker(JobWorker $worker): \Illuminate\Support\Collection
    {
        return JobPost::where('assigned_worker_id', $worker->id)
            ->where('status', JobStatus::ASSIGNED)
            ->whereDate('job_date', today())
            ->with(['category', 'poster'])
            ->orderBy('job_time')
            ->get();
    }

    /**
     * Get jobs pending poster confirmation.
     */
    public function getJobsPendingPosterConfirmation(int $posterUserId): \Illuminate\Support\Collection
    {
        return JobPost::where('poster_user_id', $posterUserId)
            ->where('status', JobStatus::IN_PROGRESS)
            ->whereHas('verification', function ($q) {
                $q->where('completion_confirmed_by_worker', true)
                    ->where('completion_confirmed_by_poster', false);
            })
            ->with(['assignedWorker', 'category', 'verification'])
            ->get();
    }

    /**
     * Get jobs pending payment.
     */
    public function getJobsPendingPayment(int $posterUserId): \Illuminate\Support\Collection
    {
        return JobPost::where('poster_user_id', $posterUserId)
            ->where('status', JobStatus::IN_PROGRESS)
            ->whereHas('verification', function ($q) {
                $q->where('completion_confirmed_by_worker', true)
                    ->where('completion_confirmed_by_poster', true)
                    ->whereNull('payment_confirmed_at');
            })
            ->with(['assignedWorker', 'category', 'verification'])
            ->get();
    }

    /**
     * Get verification for job.
     */
    public function getVerification(JobPost $job): ?JobVerification
    {
        return $job->verification;
    }

    /**
     * Get or create verification for job.
     */
    public function getOrCreateVerification(JobPost $job): JobVerification
    {
        $verification = $job->verification;

        if (!$verification) {
            $verification = $this->startJobExecution($job);
        }

        return $verification;
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get worker's completion summary for message.
     *
     * Used for NP-028 completion message:
     * "✅ Job complete! 💰 ₹[Amount] earned!
     *  ⭐ Rating: [X]/5
     *  Total: [Y] jobs | ⭐ [Avg rating]"
     */
    public function getWorkerCompletionSummary(JobPost $job): array
    {
        $worker = $job->assignedWorker;
        $verification = $job->verification;

        return [
            'amount_earned' => $job->pay_display,
            'rating_received' => $verification?->rating,
            'rating_stars' => $verification?->rating_stars,
            'total_jobs' => $worker?->jobs_completed ?? 0,
            'avg_rating' => $worker?->rating ?? 0,
            'total_earnings' => $worker?->total_earnings ?? 0,
        ];
    }

    /**
     * Get job execution statistics.
     */
    public function getJobExecutionStats(JobPost $job): array
    {
        $verification = $job->verification;

        if (!$verification) {
            return [
                'started' => false,
                'progress' => 0,
            ];
        }

        return [
            'started' => true,
            'arrived' => $verification->has_arrived,
            'arrival_time' => $verification->arrival_time_formatted,
            'arrival_confirmed' => $verification->is_arrival_confirmed,
            'handover_worker' => $verification->handover_confirmed_by_worker,
            'handover_poster' => $verification->handover_confirmed_by_poster,
            'worker_confirmed' => $verification->is_worker_confirmed,
            'poster_confirmed' => $verification->is_poster_confirmed,
            'mutually_confirmed' => $verification->is_mutually_confirmed,
            'payment_confirmed' => $verification->is_payment_confirmed,
            'payment_method' => $verification->payment_method?->value,
            'rating' => $verification->rating,
            'has_dispute' => $verification->has_dispute,
            'progress' => $verification->progress,
        ];
    }

    /**
     * Get worker performance statistics.
     */
    public function getWorkerPerformanceStats(JobWorker $worker): array
    {
        $verifications = JobVerification::where('worker_id', $worker->id)
            ->whereHas('jobPost', function ($q) {
                $q->where('status', JobStatus::COMPLETED);
            })
            ->get();

        $completedCount = $verifications->count();
        $averageRating = $verifications->whereNotNull('rating')->avg('rating');
        $fiveStarCount = $verifications->where('rating', 5)->count();

        return [
            'total_jobs' => $worker->jobs_completed,
            'total_earnings' => $worker->total_earnings ?? 0,
            'average_rating' => round($averageRating ?? 0, 1),
            'rating_count' => $worker->rating_count ?? 0,
            'five_star_count' => $fiveStarCount,
            'five_star_rate' => $completedCount > 0 
                ? round(($fiveStarCount / $completedCount) * 100, 1) 
                : 0,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Reminder Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get jobs needing start reminder.
     */
    public function getJobsNeedingStartReminder(int $minutesBefore = 30): \Illuminate\Support\Collection
    {
        $now = now();
        $reminderTime = $now->copy()->addMinutes($minutesBefore);

        return JobPost::where('status', JobStatus::ASSIGNED)
            ->whereDate('job_date', today())
            ->whereNotNull('job_time')
            ->whereDoesntHave('verification')
            ->with(['assignedWorker.user', 'category', 'poster'])
            ->get()
            ->filter(function ($job) use ($now, $reminderTime) {
                if (!$job->job_time) return false;
                
                try {
                    $jobTime = $job->job_date->setTimeFromTimeString($job->job_time);
                    return $jobTime->isBetween($now, $reminderTime);
                } catch (\Exception $e) {
                    return false;
                }
            });
    }

    /**
     * Get verifications needing completion reminder.
     */
    public function getVerificationsNeedingCompletionReminder(int $hoursOld = 2): \Illuminate\Support\Collection
    {
        $cutoff = now()->subHours($hoursOld);

        return JobVerification::whereNotNull('arrival_at')
            ->where('arrival_at', '<=', $cutoff)
            ->where('completion_confirmed_by_worker', false)
            ->whereHas('jobPost', function ($q) {
                $q->where('status', JobStatus::IN_PROGRESS);
            })
            ->with(['jobPost.category', 'worker.user', 'jobPost.poster'])
            ->get();
    }

    /**
     * Get verifications pending poster action.
     */
    public function getVerificationsPendingPosterAction(int $hoursOld = 1): \Illuminate\Support\Collection
    {
        $cutoff = now()->subHours($hoursOld);

        return JobVerification::where('completion_confirmed_by_worker', true)
            ->where('worker_completed_at', '<=', $cutoff)
            ->where('completion_confirmed_by_poster', false)
            ->whereHas('jobPost', function ($q) {
                $q->where('status', JobStatus::IN_PROGRESS);
            })
            ->with(['jobPost.category', 'worker', 'jobPost.poster'])
            ->get();
    }
}