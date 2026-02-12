<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\JobStatus;
use App\Models\JobCategory;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for job posting operations.
 *
 * Handles:
 * - Creating job posts
 * - Finding matching workers (NP-014)
 * - Notifying workers within 5km
 * - Job lifecycle management
 *
 * @srs-ref NP-006 to NP-014: Job Posting
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobPostingService
{
    /**
     * Default search radius in kilometers (NP-014).
     */
    protected const DEFAULT_RADIUS_KM = 5;

    /**
     * Maximum workers to notify per job.
     */
    protected const MAX_WORKERS_TO_NOTIFY = 50;

    public function __construct(
        protected WhatsAppService $whatsApp
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Worker Matching & Notification (NP-014)
    |--------------------------------------------------------------------------
    */

    /**
     * Find workers matching job criteria within 5km.
     *
     * @param JobPost $job The job post
     * @param int $radiusKm Search radius in kilometers
     * @return Collection<JobWorker>
     */
    public function findMatchingWorkers(JobPost $job, int $radiusKm = self::DEFAULT_RADIUS_KM): Collection
    {
        $query = JobWorker::query()
            ->where('is_available', true)
            ->with('user');

        // Filter by job type if category exists
        if ($job->job_category_id) {
            $query->where(function ($q) use ($job) {
                $q->whereJsonContains('job_types', (string) $job->job_category_id)
                    ->orWhereJsonContains('job_types', $job->job_category_id)
                    ->orWhereJsonContains('job_types', 'all');
            });
        }

        // Filter by location if coordinates available
        if ($job->latitude && $job->longitude) {
            $query->whereNotNull('latitude')
                ->whereNotNull('longitude');
            
            // Use Haversine formula for distance
            $lat = $job->latitude;
            $lng = $job->longitude;
            
            $query->selectRaw("
                job_workers.*,
                (6371 * acos(
                    cos(radians(?)) * 
                    cos(radians(latitude)) * 
                    cos(radians(longitude) - radians(?)) + 
                    sin(radians(?)) * 
                    sin(radians(latitude))
                )) AS distance_km
            ", [$lat, $lng, $lat])
            ->having('distance_km', '<=', $radiusKm)
            ->orderBy('distance_km');
        }

        // Order by rating and completed jobs
        $query->orderByDesc('rating')
            ->orderByDesc('jobs_completed');

        return $query->limit(self::MAX_WORKERS_TO_NOTIFY)->get();
    }

    /**
     * Notify matching workers about a new job.
     *
     * @param JobPost $job The job post
     * @return int Number of workers notified
     */
    public function notifyMatchingWorkers(JobPost $job): int
    {
        try {
            $workers = $this->findMatchingWorkers($job);
        } catch (\Exception $e) {
            Log::warning('Error finding matching workers', [
                'job_id' => $job->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }

        if ($workers->isEmpty()) {
            Log::info('No matching workers found for job', [
                'job_id' => $job->id,
                'job_number' => $job->job_number ?? 'N/A',
            ]);
            return 0;
        }

        $notifiedCount = 0;

        foreach ($workers as $worker) {
            try {
                $this->notifyWorkerAboutJob($worker, $job);
                $notifiedCount++;
            } catch (\Exception $e) {
                Log::warning('Failed to notify worker about job', [
                    'worker_id' => $worker->id,
                    'job_id' => $job->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Workers notified about job', [
            'job_id' => $job->id,
            'job_number' => $job->job_number ?? 'N/A',
            'workers_found' => $workers->count(),
            'workers_notified' => $notifiedCount,
        ]);

        return $notifiedCount;
    }

    /**
     * Notify a specific worker about a job.
     */
    protected function notifyWorkerAboutJob(JobWorker $worker, JobPost $job): void
    {
        $phone = $worker->user?->phone;
        if (!$phone) {
            return;
        }

        // Calculate distance
        $distanceKm = $worker->distance_km ?? 0;
        if (!$distanceKm && $job->latitude && $job->longitude && $worker->latitude && $worker->longitude) {
            $distanceKm = $this->calculateDistance(
                $job->latitude, $job->longitude,
                $worker->latitude, $worker->longitude
            );
        }
        $distanceDisplay = $distanceKm > 0 ? round($distanceKm, 1) . ' km away' : 'Nearby';

        // Get category info
        $catIcon = '📋';
        $catName = $job->custom_category_text ?? 'Job';
        if ($job->category) {
            $catIcon = $job->category->icon ?? '📋';
            $catName = $job->category->name_en ?? $job->category->name ?? $catName;
        }

        // Format date/time
        $dateDisplay = $job->job_date ? Carbon::parse($job->job_date)->format('d M') : 'TBD';
        $timeDisplay = $job->job_time ? Carbon::parse($job->job_time)->format('g:i A') : 'TBD';
        $payDisplay = '₹' . number_format($job->pay_amount ?? 0);

        // Build message
        $message = "🔔 *New Job Alert!*\n" .
            "*പുതിയ ജോലി!*\n\n" .
            "{$catIcon} *{$catName}*\n" .
            "📍 {$job->location_name}\n" .
            "🚶 {$distanceDisplay}\n" .
            "📅 {$dateDisplay} ⏰ {$timeDisplay}\n" .
            "💰 *{$payDisplay}*\n\n" .
            "Interested? Apply now! 👇";

        $this->whatsApp->sendButtons(
            $phone,
            $message,
            [
                ['id' => 'apply_job_' . $job->id, 'title' => '✅ Apply'],
                ['id' => 'view_job_' . $job->id, 'title' => '📋 Details'],
                ['id' => 'skip_job_' . $job->id, 'title' => '❌ Not Now'],
            ],
            '🔔 Job Alert'
        );

        Log::debug('Sent job notification to worker', [
            'worker_id' => $worker->id,
            'job_id' => $job->id,
            'distance_km' => $distanceKm,
        ]);
    }

    /**
     * Calculate distance between two coordinates using Haversine formula.
     */
    protected function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /*
    |--------------------------------------------------------------------------
    | Job Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Get jobs posted by a user.
     */
    public function getJobsByPoster(User $user, array $filters = []): Collection
    {
        $query = JobPost::query()
            ->where('poster_user_id', $user->id)
            ->with(['category', 'assignedWorker'])
            ->orderByDesc('created_at');

        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('status', $filters['status']);
            } else {
                $query->where('status', $filters['status']);
            }
        }

        $limit = $filters['limit'] ?? 20;
        return $query->limit($limit)->get();
    }

    /**
     * Get browsable jobs for workers.
     */
    public function getBrowsableJobs(
        ?float $latitude = null,
        ?float $longitude = null,
        int $radiusKm = self::DEFAULT_RADIUS_KM,
        array $filters = []
    ): Collection {
        $query = JobPost::query()
            ->with(['category', 'poster'])
            ->where('status', 'open')
            ->where('job_date', '>=', Carbon::today());

        // Filter by category
        if (!empty($filters['category_id'])) {
            $query->where('job_category_id', $filters['category_id']);
        }

        // Filter by location
        if ($latitude && $longitude) {
            $query->selectRaw("
                job_posts.*,
                (6371 * acos(
                    cos(radians(?)) * 
                    cos(radians(latitude)) * 
                    cos(radians(longitude) - radians(?)) + 
                    sin(radians(?)) * 
                    sin(radians(latitude))
                )) AS distance_km
            ", [$latitude, $longitude, $latitude])
            ->having('distance_km', '<=', $radiusKm)
            ->orderBy('distance_km');
        }

        $limit = $filters['limit'] ?? 20;
        return $query->limit($limit)->get();
    }

    /**
     * Get count of active jobs for a user.
     */
    public function getActiveJobsCount(User $user): int
    {
        return JobPost::query()
            ->where('poster_user_id', $user->id)
            ->whereIn('status', ['open', 'assigned', 'in_progress'])
            ->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Job Lifecycle
    |--------------------------------------------------------------------------
    */

    /**
     * Cancel a job post.
     */
    public function cancelJob(JobPost $job, ?string $reason = null): bool
    {
        if (!in_array($job->status, ['open', 'assigned'])) {
            return false;
        }

        DB::transaction(function () use ($job, $reason) {
            $job->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            // Notify assigned worker if any
            if ($job->assignedWorker?->user?->phone) {
                $this->notifyJobCancelled($job->assignedWorker->user->phone, $job, $reason);
            }
        });

        Log::info('Job cancelled', [
            'job_id' => $job->id,
            'reason' => $reason,
        ]);

        return true;
    }

    /**
     * Notify about job cancellation.
     */
    protected function notifyJobCancelled(string $phone, JobPost $job, ?string $reason): void
    {
        $catIcon = $job->category?->icon ?? '📋';
        $reasonText = $reason ? "\n\nReason: {$reason}" : '';

        $this->whatsApp->sendButtons(
            $phone,
            "❌ *Job Cancelled*\n\n" .
            "{$catIcon} *{$job->title}*{$reasonText}\n\n" .
            "_Browse more jobs below_",
            [
                ['id' => 'browse_jobs', 'title' => '🔍 Browse Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /**
     * Expire old jobs.
     */
    public function expireOldJobs(): int
    {
        $expired = JobPost::query()
            ->where('status', 'open')
            ->where(function ($q) {
                $q->where('job_date', '<', Carbon::today())
                    ->orWhere('expires_at', '<=', now());
            })
            ->update(['status' => 'expired']);

        if ($expired > 0) {
            Log::info('Expired old jobs', ['count' => $expired]);
        }

        return $expired;
    }

    /*
    |--------------------------------------------------------------------------
    | Pay Calculation
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate suggested pay for a job.
     */
    public function calculateSuggestedPay(?JobCategory $category, float $durationHours): array
    {
        $basePay = 200;

        if ($category) {
            $minPay = $category->typical_pay_min ?? 100;
            $maxPay = $category->typical_pay_max ?? 500;
        } else {
            $minPay = 100;
            $maxPay = 500;
        }

        // Adjust based on duration
        $multiplier = max(1, $durationHours / 2);

        return [
            'min' => (int) round($minPay * $multiplier, -1),
            'max' => (int) round($maxPay * $multiplier, -1),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get poster statistics.
     */
    public function getPosterStats(User $user): array
    {
        $jobs = JobPost::query()->where('poster_user_id', $user->id);

        $totalJobs = $jobs->count();
        $completedJobs = (clone $jobs)->where('status', 'completed')->count();
        $activeJobs = (clone $jobs)->whereIn('status', ['open', 'assigned', 'in_progress'])->count();
        $totalSpent = (clone $jobs)->where('status', 'completed')->sum('pay_amount');

        return [
            'total_jobs' => $totalJobs,
            'completed_jobs' => $completedJobs,
            'active_jobs' => $activeJobs,
            'total_spent' => $totalSpent,
            'completion_rate' => $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate coordinates (India bounds).
     */
    public function isValidCoordinates(float $latitude, float $longitude): bool
    {
        return $latitude >= 6 && $latitude <= 36 &&
               $longitude >= 68 && $longitude <= 98;
    }

    /**
     * Validate pay amount.
     */
    public function isValidPayAmount(float $amount): bool
    {
        return $amount >= 50 && $amount <= 100000;
    }
}