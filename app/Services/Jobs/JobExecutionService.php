<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\BadgeType;
use App\Enums\JobStatus;
use App\Enums\PaymentMethod;
use App\Models\JobPost;
use App\Models\JobVerification;
use App\Models\JobWorker;
use App\Models\WorkerBadge;
use App\Models\WorkerEarning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing job execution and verification.
 *
 * Handles:
 * - Starting job execution
 * - Recording arrival and completion
 * - Mutual confirmation from worker and poster
 * - Payment confirmation
 * - Rating and badge management
 * - Worker statistics updates
 *
 * @srs-ref Section 3.5 - Job Verification & Completion
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
        // Validate job can be started
        if (!$this->isJobReadyToStart($job)) {
            throw new \InvalidArgumentException(
                sprintf(
                    'This job cannot be started. Current status: %s',
                    (string) ($job->status?->value ?? 'unknown')
                )
            );
        }

        return DB::transaction(function () use ($job) {
            // Check if verification already exists
            $verification = $job->verification;

            if (!$verification) {
                // Create verification record
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
     * Check if a job is ready to start.
     *
     * @param JobPost $job
     * @return bool
     */
    public function isJobReadyToStart(JobPost $job): bool
    {
        // Get status value (handle both enum and string)
        $statusValue = $job->status instanceof JobStatus 
            ? $job->status->value 
            : (string) $job->status;

        Log::debug('isJobReadyToStart check', [
            'job_id' => $job->id,
            'status_raw' => $job->status,
            'status_value' => $statusValue,
            'assigned_worker_id' => $job->assigned_worker_id,
            'job_date' => $job->job_date,
        ]);

        // Must be assigned or in progress
        if (!in_array($statusValue, ['assigned', 'in_progress'])) {
            Log::debug('Job failed status check', ['status' => $statusValue]);
            return false;
        }

        // Must have an assigned worker
        if (!$job->assigned_worker_id) {
            Log::debug('Job has no assigned worker');
            return false;
        }

        // Job date should be today or past (allow starting on job day)
        // Skip this check if job_date is null (flexible timing)
        if ($job->job_date && $job->job_date->isFuture() && !$job->job_date->isToday()) {
            Log::debug('Job date is in the future', ['job_date' => $job->job_date]);
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Arrival Recording
    |--------------------------------------------------------------------------
    */

    /**
     * Record worker arrival at job location.
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
            if ($worker) {
                $worker->touchLastActive();
            }

            Log::info('Worker arrival recorded', [
                'verification_id' => $verification->id,
                'job_id' => $verification->job_post_id,
                'has_photo' => !empty($photoUrl),
                'has_location' => !empty($latitude),
            ]);
        });
    }

    /**
     * Notify poster of worker arrival.
     *
     * @param JobPost $job
     * @return void
     */
    public function notifyPosterOfArrival(JobPost $job): void
    {
        // This is handled by the flow handler for immediate WhatsApp notification
        // This method can be used for additional notifications (push, email, etc.)
        Log::info('Poster notified of arrival', [
            'job_id' => $job->id,
            'poster_id' => $job->poster_user_id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Handover (for queue-standing jobs)
    |--------------------------------------------------------------------------
    */

    /**
     * Confirm handover for queue-standing jobs.
     *
     * @param JobVerification $verification
     * @param string $confirmedBy 'worker' or 'poster'
     */
    public function confirmHandover(JobVerification $verification, string $confirmedBy): void
    {
        // For queue-standing jobs, handover means worker took over the position
        // This is tracked similarly to arrival confirmation
        
        if ($confirmedBy === 'worker') {
            $verification->update(['worker_confirmed_at' => now()]);
        } else {
            $verification->update(['poster_confirmed_at' => now()]);
        }

        Log::info('Handover confirmed', [
            'verification_id' => $verification->id,
            'confirmed_by' => $confirmedBy,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Completion Recording
    |--------------------------------------------------------------------------
    */

    /**
     * Record job completion with optional photo.
     *
     * @param JobVerification $verification
     * @param string|null $photoUrl Completion photo URL
     */
    public function recordCompletion(JobVerification $verification, ?string $photoUrl = null): void
    {
        $verification->recordCompletion($photoUrl);

        Log::info('Job completion recorded', [
            'verification_id' => $verification->id,
            'job_id' => $verification->job_post_id,
            'has_photo' => !empty($photoUrl),
        ]);
    }

    /**
     * Confirm completion by worker or poster.
     *
     * @param JobVerification $verification
     * @param string $confirmedBy 'worker' or 'poster'
     * @throws \InvalidArgumentException
     */
    public function confirmCompletion(JobVerification $verification, string $confirmedBy): void
    {
        if (!in_array($confirmedBy, ['worker', 'poster'])) {
            throw new \InvalidArgumentException('confirmedBy must be "worker" or "poster"');
        }

        if ($confirmedBy === 'worker') {
            $verification->confirmByWorker();
            Log::info('Worker confirmed completion', [
                'verification_id' => $verification->id,
                'job_id' => $verification->job_post_id,
            ]);
        } else {
            $verification->confirmByPoster();
            Log::info('Poster confirmed completion', [
                'verification_id' => $verification->id,
                'job_id' => $verification->job_post_id,
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Rating
    |--------------------------------------------------------------------------
    */

    /**
     * Rate the worker (by poster).
     *
     * @param JobPost $job
     * @param int $rating 1-5
     * @param string|null $comment Optional comment
     */
    public function rateWorker(JobPost $job, int $rating, ?string $comment = null): void
    {
        $rating = max(1, min(5, $rating));

        $verification = $job->verification;

        if (!$verification) {
            throw new \InvalidArgumentException('Job verification not found');
        }

        DB::transaction(function () use ($verification, $rating, $comment) {
            // Save rating to verification
            $verification->rateWorker($rating, $comment);

            // Update worker's overall rating
            $worker = $verification->worker;
            if ($worker) {
                $this->calculateNewRating($worker);
            }

            Log::info('Worker rated', [
                'verification_id' => $verification->id,
                'worker_id' => $verification->worker_id,
                'rating' => $rating,
            ]);
        });
    }

    /**
     * Rate the poster (by worker).
     *
     * @param JobPost $job
     * @param int $rating 1-5
     * @param string|null $feedback Optional feedback
     */
    public function ratePoster(JobPost $job, int $rating, ?string $feedback = null): void
    {
        $rating = max(1, min(5, $rating));

        $verification = $job->verification;

        if (!$verification) {
            throw new \InvalidArgumentException('Job verification not found');
        }

        $verification->ratePoster($rating, $feedback);

        Log::info('Poster rated by worker', [
            'verification_id' => $verification->id,
            'job_id' => $job->id,
            'rating' => $rating,
        ]);
    }

    /**
     * Calculate and update worker's rating.
     *
     * @param JobWorker $worker
     * @return float New rating
     */
    public function calculateNewRating(JobWorker $worker): float
    {
        $worker->recalculateRating();
        return $worker->rating;
    }

    /*
    |--------------------------------------------------------------------------
    | Payment
    |--------------------------------------------------------------------------
    */

    /**
     * Confirm payment from poster.
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

            Log::info('Payment confirmed', [
                'verification_id' => $verification->id,
                'job_id' => $verification->job_post_id,
                'method' => $method->value,
            ]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Job Completion
    |--------------------------------------------------------------------------
    */

    /**
     * Complete a job and update all related records.
     *
     * @param JobPost $job
     * @throws \Exception
     */
    public function completeJob(JobPost $job): void
    {
        // Check if already completed
        if ($job->status === JobStatus::COMPLETED) {
            return;
        }

        DB::transaction(function () use ($job) {
            // Complete the job
            $job->complete();

            // Record worker earnings
            $this->recordWorkerEarnings($job);

            // Award badges if earned
            $worker = $job->assignedWorker;
            if ($worker) {
                $this->checkAndAwardBadges($worker);
            }

            Log::info('Job completed', [
                'job_id' => $job->id,
                'worker_id' => $job->assigned_worker_id,
                'amount' => $job->pay_amount,
            ]);
        });
    }

    /**
     * Record worker earnings for completed job.
     *
     * @param JobPost $job
     */
    protected function recordWorkerEarnings(JobPost $job): void
    {
        $worker = $job->assignedWorker;

        if (!$worker) {
            return;
        }

        // Get or create this week's earnings record using existing method
        $earning = WorkerEarning::getOrCreateForWeek($worker);

        // Calculate hours worked
        $hours = $job->duration_hours ?? 1;
        
        // Check if on time (using verification arrival time)
        $onTime = true;
        $verification = $job->verification;
        if ($verification && $verification->arrival_verified_at && $job->job_time) {
            $expectedTime = $job->job_date->setTimeFromTimeString($job->job_time);
            $graceTime = $expectedTime->copy()->addMinutes(15);
            $onTime = $verification->arrival_verified_at->lte($graceTime);
        }

        // Record the completed job using existing method
        $earning->recordCompletedJob(
            $job->pay_amount,
            $hours,
            $job->job_category_id,
            $onTime
        );

        // Update rating if available
        if ($verification && $verification->rating) {
            $earning->updateAverageRating($verification->rating);
        }

        Log::info('Worker earnings recorded', [
            'worker_id' => $worker->id,
            'job_id' => $job->id,
            'amount' => $job->pay_amount,
            'week_total' => $earning->total_earnings,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Badge Management
    |--------------------------------------------------------------------------
    */

    /**
     * Check and award badges for a worker.
     *
     * @param JobWorker $worker
     * @return array Array of badge names awarded
     */
    public function checkAndAwardBadges(JobWorker $worker): array
    {
        $awardedBadges = [];

        // Refresh worker data
        $worker->refresh();

        // Check milestone badges (FIRST_JOB, TEN_JOBS, FIFTY_JOBS, HUNDRED_JOBS) using existing method
        $milestoneBadges = WorkerBadge::checkMilestoneBadges($worker);
        foreach ($milestoneBadges as $badge) {
            $awardedBadges[] = $badge->display;
        }

        // Check performance badges
        $performanceBadges = $this->checkPerformanceBadges($worker);
        $awardedBadges = array_merge($awardedBadges, $performanceBadges);

        // Check reliability badges
        $reliabilityBadges = $this->checkReliabilityBadges($worker);
        $awardedBadges = array_merge($awardedBadges, $reliabilityBadges);

        if (!empty($awardedBadges)) {
            Log::info('Badges awarded', [
                'worker_id' => $worker->id,
                'badges' => $awardedBadges,
            ]);
        }

        return $awardedBadges;
    }

    /**
     * Check and award performance badges.
     */
    protected function checkPerformanceBadges(JobWorker $worker): array
    {
        $awarded = [];

        // FIVE_STAR badge - 5.0 rating for 20+ jobs
        if ($worker->rating_count >= 20 && $worker->rating >= 5.0) {
            $badge = WorkerBadge::checkAndAwardBadge($worker, BadgeType::FIVE_STAR);
            if ($badge) {
                $awarded[] = $badge->display;
            }
        }

        // TOP_EARNER badge - ₹10,000+ in a week
        $weeklyEarning = $this->getWeeklyEarnings($worker);
        if ($weeklyEarning && $weeklyEarning->total_earnings >= 10000) {
            $badge = WorkerBadge::checkAndAwardBadge($worker, BadgeType::TOP_EARNER);
            if ($badge) {
                $awarded[] = $badge->display;
            }
        }

        // EARLY_BIRD badge - check if applicable
        $badge = WorkerBadge::checkAndAwardBadge($worker, BadgeType::EARLY_BIRD);
        if ($badge) {
            $awarded[] = $badge->display;
        }

        return $awarded;
    }

    /**
     * Check and award reliability badges.
     */
    protected function checkReliabilityBadges(JobWorker $worker): array
    {
        $awarded = [];

        // TRUSTED badge - verified with 50+ jobs and 4.5+ rating
        if ($worker->is_verified && $worker->jobs_completed >= 50 && $worker->rating >= 4.5) {
            $badge = WorkerBadge::checkAndAwardBadge($worker, BadgeType::TRUSTED);
            if ($badge) {
                $awarded[] = $badge->display;
            }
        }

        // RELIABLE badge - never cancelled 30+ jobs
        $badge = WorkerBadge::checkAndAwardBadge($worker, BadgeType::RELIABLE);
        if ($badge) {
            $awarded[] = $badge->display;
        }

        // PUNCTUAL badge - on time for 20 consecutive jobs
        $badge = WorkerBadge::checkAndAwardBadge($worker, BadgeType::PUNCTUAL);
        if ($badge) {
            $awarded[] = $badge->display;
        }

        return $awarded;
    }

    /*
    |--------------------------------------------------------------------------
    | Query Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get active job for a worker.
     *
     * @param JobWorker $worker
     * @return JobPost|null
     */
    public function getActiveJobForWorker(JobWorker $worker): ?JobPost
    {
        return JobPost::where('assigned_worker_id', $worker->id)
            ->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])
            ->with(['category', 'poster', 'verification'])
            ->first();
    }

    /**
     * Get today's jobs for a worker that need to be started.
     *
     * @param JobWorker $worker
     * @return \Illuminate\Support\Collection
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
     * Get jobs pending confirmation for a poster.
     *
     * @param int $posterUserId
     * @return \Illuminate\Support\Collection
     */
    public function getJobsPendingPosterConfirmation(int $posterUserId): \Illuminate\Support\Collection
    {
        return JobPost::where('poster_user_id', $posterUserId)
            ->where('status', JobStatus::IN_PROGRESS)
            ->whereHas('verification', function ($q) {
                $q->whereNotNull('worker_confirmed_at')
                    ->whereNull('poster_confirmed_at');
            })
            ->with(['assignedWorker', 'category', 'verification'])
            ->get();
    }

    /**
     * Get jobs pending payment for a poster.
     *
     * @param int $posterUserId
     * @return \Illuminate\Support\Collection
     */
    public function getJobsPendingPayment(int $posterUserId): \Illuminate\Support\Collection
    {
        return JobPost::where('poster_user_id', $posterUserId)
            ->where('status', JobStatus::IN_PROGRESS)
            ->whereHas('verification', function ($q) {
                $q->whereNotNull('worker_confirmed_at')
                    ->whereNotNull('poster_confirmed_at')
                    ->whereNull('payment_confirmed_at');
            })
            ->with(['assignedWorker', 'category', 'verification'])
            ->get();
    }

    /**
     * Get worker's weekly earnings.
     *
     * @param JobWorker $worker
     * @return WorkerEarning|null
     */
    public function getWeeklyEarnings(JobWorker $worker): ?WorkerEarning
    {
        return WorkerEarning::byWorker($worker->id)
            ->thisWeek()
            ->first();
    }

    /**
     * Get worker's earnings history.
     *
     * @param JobWorker $worker
     * @param int $weeks Number of weeks to retrieve
     * @return \Illuminate\Support\Collection
     */
    public function getEarningsHistory(JobWorker $worker, int $weeks = 12): \Illuminate\Support\Collection
    {
        return WorkerEarning::byWorker($worker->id)
            ->latest()
            ->limit($weeks)
            ->get();
    }

    /**
     * Get monthly summary for a worker.
     *
     * @param JobWorker $worker
     * @param mixed $month
     * @return array
     */
    public function getMonthlySummary(JobWorker $worker, $month = null): array
    {
        return WorkerEarning::getMonthlySummary($worker, $month);
    }

    /**
     * Get worker's badges.
     *
     * @param JobWorker $worker
     * @return \Illuminate\Support\Collection
     */
    public function getWorkerBadges(JobWorker $worker): \Illuminate\Support\Collection
    {
        return WorkerBadge::byWorker($worker->id)
            ->latest()
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Reminders & Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Get jobs that need start reminder (approaching job time).
     *
     * @param int $minutesBefore Minutes before job time
     * @return \Illuminate\Support\Collection
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
                $jobTime = $job->job_date->setTimeFromTimeString($job->job_time);
                return $jobTime->isBetween($now, $reminderTime);
            });
    }

    /**
     * Get verifications that need completion reminder.
     *
     * @param int $hoursOld Hours since arrival without completion
     * @return \Illuminate\Support\Collection
     */
    public function getVerificationsNeedingCompletionReminder(int $hoursOld = 2): \Illuminate\Support\Collection
    {
        $cutoff = now()->subHours($hoursOld);

        return JobVerification::whereNotNull('arrival_verified_at')
            ->where('arrival_verified_at', '<=', $cutoff)
            ->whereNull('worker_confirmed_at')
            ->whereHas('jobPost', function ($q) {
                $q->where('status', JobStatus::IN_PROGRESS);
            })
            ->with(['jobPost.category', 'worker.user', 'jobPost.poster'])
            ->get();
    }

    /**
     * Get verifications pending poster confirmation.
     *
     * @param int $hoursOld Hours since worker confirmed
     * @return \Illuminate\Support\Collection
     */
    public function getVerificationsPendingPosterAction(int $hoursOld = 1): \Illuminate\Support\Collection
    {
        $cutoff = now()->subHours($hoursOld);

        return JobVerification::whereNotNull('worker_confirmed_at')
            ->where('worker_confirmed_at', '<=', $cutoff)
            ->whereNull('poster_confirmed_at')
            ->whereHas('jobPost', function ($q) {
                $q->where('status', JobStatus::IN_PROGRESS);
            })
            ->with(['jobPost.category', 'worker', 'jobPost.poster'])
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get execution statistics for a job.
     *
     * @param JobPost $job
     * @return array
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

        $arrivalTime = $verification->arrival_verified_at;
        $completionTime = $verification->completion_verified_at ?? $verification->worker_confirmed_at;

        return [
            'started' => true,
            'arrival_time' => $arrivalTime?->format('H:i'),
            'completion_time' => $completionTime?->format('H:i'),
            'duration_minutes' => $arrivalTime && $completionTime
                ? $arrivalTime->diffInMinutes($completionTime)
                : null,
            'worker_confirmed' => $verification->is_worker_confirmed,
            'poster_confirmed' => $verification->is_poster_confirmed,
            'payment_confirmed' => $verification->is_payment_confirmed,
            'payment_method' => $verification->payment_method?->value,
            'rating' => $verification->rating,
            'has_dispute' => $verification->has_dispute,
            'progress' => $verification->progress,
        ];
    }

    /**
     * Get worker performance statistics.
     *
     * @param JobWorker $worker
     * @return array
     */
    public function getWorkerPerformanceStats(JobWorker $worker): array
    {
        $verifications = $worker->verifications()
            ->whereHas('jobPost', function ($q) {
                $q->where('status', JobStatus::COMPLETED);
            })
            ->get();

        $completedCount = $verifications->count();
        $averageRating = $verifications->whereNotNull('rating')->avg('rating');
        $fiveStarCount = $verifications->where('rating', 5)->count();

        // Calculate average completion time
        $durations = $verifications
            ->filter(fn($v) => $v->arrival_verified_at && $v->worker_confirmed_at)
            ->map(fn($v) => $v->arrival_verified_at->diffInMinutes($v->worker_confirmed_at));

        $averageDuration = $durations->isNotEmpty() ? $durations->avg() : null;

        // On-time rate (arrived within job time + 15 min grace period)
        $onTimeCount = 0;
        foreach ($verifications as $verification) {
            $job = $verification->jobPost;
            if ($job->job_time && $verification->arrival_verified_at) {
                $expectedTime = $job->job_date->setTimeFromTimeString($job->job_time);
                $graceTime = $expectedTime->copy()->addMinutes(15);
                if ($verification->arrival_verified_at->lte($graceTime)) {
                    $onTimeCount++;
                }
            } else {
                $onTimeCount++; // No specific time = on time by default
            }
        }

        $onTimeRate = $completedCount > 0 
            ? round(($onTimeCount / $completedCount) * 100, 1) 
            : 100;

        return [
            'total_jobs' => $worker->jobs_completed,
            'total_earnings' => $worker->total_earnings,
            'average_rating' => round($averageRating ?? 0, 1),
            'rating_count' => $worker->rating_count,
            'five_star_count' => $fiveStarCount,
            'five_star_rate' => $completedCount > 0 
                ? round(($fiveStarCount / $completedCount) * 100, 1) 
                : 0,
            'average_duration_minutes' => $averageDuration ? round($averageDuration) : null,
            'on_time_rate' => $onTimeRate,
            'badges_count' => $worker->badges()->count(),
        ];
    }
}