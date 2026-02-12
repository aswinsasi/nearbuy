<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\JobStatus;
use App\Models\JobPost;
use App\Models\JobWorker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for intelligent worker-job matching.
 *
 * NP-014 Requirements:
 * - Match by: distance (within 5km) + job_type preference + availability
 * - If < 3 workers within 5km → expand to 10km
 * - Sort: distance first, then rating
 * - Exclude: workers currently on a job (is_available=false)
 *
 * @srs-ref NP-014 - Notify matching workers within 5km radius
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobMatchingService
{
    /**
     * Earth radius in kilometers for Haversine formula.
     */
    protected const EARTH_RADIUS_KM = 6371;

    /**
     * Primary search radius (NP-014).
     */
    public const PRIMARY_RADIUS_KM = 5;

    /**
     * Extended radius if < 3 workers found.
     */
    public const EXTENDED_RADIUS_KM = 10;

    /**
     * Minimum workers before extending radius.
     */
    public const MIN_WORKERS_THRESHOLD = 3;

    /**
     * Maximum search radius.
     */
    public const MAX_RADIUS_KM = 25;

    /*
    |--------------------------------------------------------------------------
    | Primary Matching (NP-014)
    |--------------------------------------------------------------------------
    */

    /**
     * Find matching workers for a job.
     *
     * NP-014: Match within 5km, expand to 10km if <3 workers.
     * Sort by distance first, then rating.
     * Exclude workers with is_available=false.
     *
     * @param JobPost $job The job to match
     * @param int|null $radiusKm Optional override radius
     * @return Collection Collection of matching JobWorker models with distance
     */
    public function findMatchingWorkers(JobPost $job, ?int $radiusKm = null): Collection
    {
        // Start with primary radius (5km)
        $radius = $radiusKm ?? self::PRIMARY_RADIUS_KM;
        
        $workers = $this->queryMatchingWorkers($job, $radius);

        // NP-014: If < 3 workers, expand to 10km
        if ($workers->count() < self::MIN_WORKERS_THRESHOLD && $radius < self::EXTENDED_RADIUS_KM) {
            Log::info('Expanding search radius', [
                'job_id' => $job->id,
                'found' => $workers->count(),
                'from_radius' => $radius,
                'to_radius' => self::EXTENDED_RADIUS_KM,
            ]);
            
            $workers = $this->queryMatchingWorkers($job, self::EXTENDED_RADIUS_KM);
        }

        // Sort by distance first, then rating (NP-014)
        return $workers->sortBy([
            ['distance_km', 'asc'],
            ['rating', 'desc'],
        ])->values();
    }

    /**
     * Query matching workers within radius.
     *
     * @param JobPost $job
     * @param int $radiusKm
     * @return Collection
     */
    protected function queryMatchingWorkers(JobPost $job, int $radiusKm): Collection
    {
        $jobLat = (float) $job->latitude;
        $jobLng = (float) $job->longitude;
        $categoryId = $job->job_category_id;

        // Haversine formula for distance calculation
        $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))";

        $query = JobWorker::query()
            // Select with distance calculation
            ->selectRaw("*, {$haversine} as distance_km", [$jobLat, $jobLng, $jobLat])
            // Must be available (NP-014: exclude is_available=false)
            ->where('is_available', true)
            // Must be verified
            ->where(function ($q) {
                $q->where('verification_status', 'verified')
                    ->orWhereNull('verification_status');
            })
            // Within radius
            ->whereRaw("{$haversine} <= ?", [$jobLat, $jobLng, $jobLat, $radiusKm])
            // Has valid coordinates
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        // Filter by job type preference if specified
        if ($categoryId) {
            $query->where(function ($q) use ($categoryId) {
                // Worker accepts this job type OR accepts all types (null/empty)
                $q->whereJsonContains('job_types', $categoryId)
                    ->orWhereNull('job_types')
                    ->orWhereRaw("JSON_LENGTH(job_types) = 0");
            });
        }

        // Exclude workers currently on a job
        $query->whereDoesntHave('assignedJobs', function ($q) {
            $q->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS]);
        });

        // Exclude workers who already applied to this job
        $query->whereDoesntHave('applications', function ($q) use ($job) {
            $q->where('job_post_id', $job->id);
        });

        // Load relationships
        $query->with(['user']);

        return $query->get();
    }

    /**
     * Get workers count for a job at different radii.
     *
     * Useful for UI to show "X workers within 5km, Y within 10km"
     *
     * @param JobPost $job
     * @return array ['5km' => count, '10km' => count]
     */
    public function getWorkersCountByRadius(JobPost $job): array
    {
        $workers5km = $this->queryMatchingWorkers($job, self::PRIMARY_RADIUS_KM)->count();
        $workers10km = $this->queryMatchingWorkers($job, self::EXTENDED_RADIUS_KM)->count();

        return [
            '5km' => $workers5km,
            '10km' => $workers10km,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Available Jobs for Worker
    |--------------------------------------------------------------------------
    */

    /**
     * Get available jobs for a worker within radius.
     *
     * @param JobWorker $worker The worker to find jobs for
     * @param int $radiusKm Search radius (default 5km)
     * @return Collection Collection of matching jobs with distance
     */
    public function getAvailableJobsForWorker(JobWorker $worker, int $radiusKm = self::PRIMARY_RADIUS_KM): Collection
    {
        $workerLat = (float) $worker->latitude;
        $workerLng = (float) $worker->longitude;
        $jobTypes = $worker->job_types ?? [];

        // Haversine formula
        $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))";

        $query = JobPost::query()
            ->selectRaw("*, {$haversine} as distance_km", [$workerLat, $workerLng, $workerLat])
            // Open jobs only
            ->where('status', JobStatus::OPEN)
            // Future or today
            ->where(function ($q) {
                $q->whereNull('job_date')
                    ->orWhere('job_date', '>=', today());
            })
            // Within radius
            ->whereRaw("{$haversine} <= ?", [$workerLat, $workerLng, $workerLat, $radiusKm])
            // Has coordinates
            ->whereNotNull('latitude')
            ->whereNotNull('longitude');

        // Filter by worker's job type preferences
        if (!empty($jobTypes)) {
            $query->where(function ($q) use ($jobTypes) {
                $q->whereIn('job_category_id', $jobTypes)
                    ->orWhereNull('job_category_id');
            });
        }

        // Exclude jobs worker already applied to
        $query->whereDoesntHave('applications', function ($q) use ($worker) {
            $q->where('worker_id', $worker->id);
        });

        // Load relationships
        $query->with(['category', 'poster']);

        // Sort by distance, then pay amount
        return $query->orderBy('distance_km')
            ->orderByDesc('pay_amount')
            ->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Distance Calculations
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate distance between two points using Haversine formula.
     *
     * @param float $lat1 First latitude
     * @param float $lng1 First longitude
     * @param float $lat2 Second latitude
     * @param float $lng2 Second longitude
     * @return float Distance in kilometers
     */
    public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round(self::EARTH_RADIUS_KM * $c, 2);
    }

    /**
     * Format distance for display.
     *
     * @param float $distanceKm Distance in kilometers
     * @return string Formatted distance (e.g., "500m" or "2.5km")
     */
    public function formatDistance(float $distanceKm): string
    {
        if ($distanceKm < 1) {
            return round($distanceKm * 1000) . 'm';
        }

        return round($distanceKm, 1) . 'km';
    }

    /**
     * Check if location is within radius.
     *
     * @param float $centerLat
     * @param float $centerLng
     * @param float $pointLat
     * @param float $pointLng
     * @param float $radiusKm
     * @return bool
     */
    public function isWithinRadius(
        float $centerLat,
        float $centerLng,
        float $pointLat,
        float $pointLng,
        float $radiusKm
    ): bool {
        return $this->calculateDistance($centerLat, $centerLng, $pointLat, $pointLng) <= $radiusKm;
    }

    /*
    |--------------------------------------------------------------------------
    | Matching Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get matching statistics for a job.
     *
     * @param JobPost $job
     * @return array
     */
    public function getMatchingStats(JobPost $job): array
    {
        $within5km = $this->queryMatchingWorkers($job, 5);
        $within10km = $this->queryMatchingWorkers($job, 10);

        return [
            'within_5km' => $within5km->count(),
            'within_10km' => $within10km->count(),
            'avg_rating_5km' => round($within5km->avg('rating') ?? 0, 1),
            'avg_rating_10km' => round($within10km->avg('rating') ?? 0, 1),
            'with_vehicle_5km' => $within5km->where('vehicle_type', '!=', 'none')->count(),
            'closest_worker_km' => $within10km->min('distance_km'),
        ];
    }

    /**
     * Count available workers in an area.
     *
     * @param float $latitude
     * @param float $longitude
     * @param float $radiusKm
     * @param int|null $categoryId Optional category filter
     * @return int
     */
    public function countAvailableWorkers(
        float $latitude,
        float $longitude,
        float $radiusKm = self::PRIMARY_RADIUS_KM,
        ?int $categoryId = null
    ): int {
        $haversine = "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude))))";

        $query = JobWorker::where('is_available', true)
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereRaw("{$haversine} <= ?", [$latitude, $longitude, $latitude, $radiusKm]);

        if ($categoryId) {
            $query->where(function ($q) use ($categoryId) {
                $q->whereJsonContains('job_types', $categoryId)
                    ->orWhereNull('job_types')
                    ->orWhereRaw("JSON_LENGTH(job_types) = 0");
            });
        }

        return $query->count();
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Scoring
    |--------------------------------------------------------------------------
    */

    /**
     * Score a worker for a job (for ranking).
     *
     * Factors:
     * - Distance (closer = higher score)
     * - Rating (higher = higher score)
     * - Jobs completed (more = higher score)
     *
     * @param JobWorker $worker
     * @param JobPost $job
     * @return float Score between 0 and 100
     */
    public function scoreWorker(JobWorker $worker, JobPost $job): float
    {
        $distance = $this->calculateDistance(
            (float) $worker->latitude,
            (float) $worker->longitude,
            (float) $job->latitude,
            (float) $job->longitude
        );

        // Distance score (0-40 points, closer = higher)
        $distanceScore = max(0, 40 - ($distance * 4));

        // Rating score (0-30 points)
        $ratingScore = (($worker->rating ?? 0) / 5) * 30;

        // Experience score (0-20 points, max at 50 jobs)
        $jobsCompleted = $worker->jobs_completed ?? 0;
        $experienceScore = min(20, ($jobsCompleted / 50) * 20);

        // Availability bonus (10 points if immediately available)
        $availabilityScore = $worker->is_available ? 10 : 0;

        return round($distanceScore + $ratingScore + $experienceScore + $availabilityScore, 1);
    }

    /**
     * Get recommended workers for a job, sorted by score.
     *
     * @param JobPost $job
     * @param int $limit
     * @return Collection
     */
    public function getRecommendedWorkers(JobPost $job, int $limit = 10): Collection
    {
        $workers = $this->findMatchingWorkers($job);

        return $workers->map(function ($worker) use ($job) {
            $worker->match_score = $this->scoreWorker($worker, $job);
            return $worker;
        })
        ->sortByDesc('match_score')
        ->take($limit)
        ->values();
    }

    /**
     * Check if a worker can do a specific job.
     *
     * @param JobWorker $worker
     * @param JobPost $job
     * @return bool
     */
    public function canWorkerDoJob(JobWorker $worker, JobPost $job): bool
    {
        // Must be available
        if (!$worker->is_available) {
            return false;
        }

        // Check distance
        $distance = $this->calculateDistance(
            (float) $worker->latitude,
            (float) $worker->longitude,
            (float) $job->latitude,
            (float) $job->longitude
        );

        if ($distance > self::MAX_RADIUS_KM) {
            return false;
        }

        // Check job type preference
        $workerJobTypes = $worker->job_types ?? [];
        $jobCategoryId = $job->job_category_id;

        if (!empty($workerJobTypes) && $jobCategoryId) {
            if (!in_array($jobCategoryId, $workerJobTypes)) {
                return false;
            }
        }

        // Check not already assigned to a job
        $hasActiveJob = JobPost::where('assigned_worker_id', $worker->id)
            ->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])
            ->exists();

        if ($hasActiveJob) {
            return false;
        }

        return true;
    }
}