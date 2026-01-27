<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\JobStatus;
use App\Enums\VehicleType;
use App\Enums\WorkerAvailability;
use App\Enums\WorkerVerificationStatus;
use App\Models\JobCategory;
use App\Models\JobPost;
use App\Models\JobWorker;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for intelligent worker-job matching.
 *
 * Handles:
 * - Location-based matching using Haversine formula
 * - Multi-factor worker scoring
 * - Skill and availability matching
 * - Vehicle requirement matching
 * - Historical performance analysis
 *
 * @srs-ref Section 3.3 - Job Matching Algorithm
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobMatchingService
{
    /**
     * Earth radius in kilometers for Haversine formula.
     */
    protected const EARTH_RADIUS_KM = 6371;

    /**
     * Default search radius in kilometers.
     */
    public const DEFAULT_RADIUS_KM = 5;

    /**
     * Maximum search radius in kilometers.
     */
    public const MAX_RADIUS_KM = 25;

    /**
     * Scoring weights for worker matching.
     */
    protected const SCORING_WEIGHTS = [
        'distance' => 0.25,        // Closer is better
        'rating' => 0.25,          // Higher rating is better
        'experience' => 0.15,      // More jobs completed is better
        'availability' => 0.15,    // Matching availability is better
        'response_rate' => 0.10,   // Higher acceptance rate is better
        'reliability' => 0.10,     // Lower cancellation rate is better
    ];

    /**
     * Minimum score threshold for recommendations.
     */
    protected const MIN_RECOMMENDATION_SCORE = 0.3;

    /*
    |--------------------------------------------------------------------------
    | Primary Matching Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Find all workers matching a job's requirements.
     *
     * @param JobPost $job The job to match
     * @param int $radiusKm Search radius in kilometers
     * @return Collection Collection of matching JobWorker models
     */
    public function findMatchingWorkers(JobPost $job, int $radiusKm = self::DEFAULT_RADIUS_KM): Collection
    {
        $radiusKm = min($radiusKm, self::MAX_RADIUS_KM);

        $jobLat = $job->latitude;
        $jobLng = $job->longitude;
        $categoryId = $job->category_id;
        $vehicleRequired = $job->vehicle_required;
        $scheduledDate = $job->scheduled_date;
        $timeSlot = $job->time_slot;

        // Build base query with location filter
        $query = JobWorker::query()
            ->active()
            ->verified()
            // Within radius using Haversine formula
            ->whereRaw(
                "(? * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?",
                [self::EARTH_RADIUS_KM, $jobLat, $jobLng, $jobLat, $radiusKm]
            );

        // Filter by category if specified
        if ($categoryId) {
            $query->where(function ($q) use ($categoryId) {
                $q->whereJsonContains('category_ids', $categoryId)
                    ->orWhereNull('category_ids');
            });
        }

        // Filter by vehicle if required
        if ($vehicleRequired && $job->vehicle_type) {
            $query->where(function ($q) use ($job) {
                $q->where('has_vehicle', true)
                    ->where('vehicle_type', $job->vehicle_type);
            });
        }

        // Filter by availability if time slot specified
        if ($timeSlot) {
            $query->where(function ($q) use ($timeSlot) {
                $q->whereJsonContains('availability', WorkerAvailability::FLEXIBLE->value)
                    ->orWhereJsonContains('availability', $timeSlot->value);
            });
        }

        // Exclude workers already assigned to jobs on same date
        $query->whereDoesntHave('assignedJobs', function ($q) use ($scheduledDate) {
            $q->whereDate('scheduled_date', $scheduledDate)
                ->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS]);
        });

        // Exclude workers who already applied to this job
        $query->whereDoesntHave('applications', function ($q) use ($job) {
            $q->where('job_post_id', $job->id);
        });

        return $query->with(['user', 'badges'])->get();
    }

    /**
     * Get recommended workers for a job, sorted by score.
     *
     * @param JobPost $job The job to match
     * @param int $limit Maximum number of workers to return
     * @return Collection Collection of workers with scores
     */
    public function getRecommendedWorkers(JobPost $job, int $limit = 10): Collection
    {
        // Start with larger radius and filter down
        $workers = $this->findMatchingWorkers($job, self::MAX_RADIUS_KM);

        if ($workers->isEmpty()) {
            return collect();
        }

        // Score each worker
        $scoredWorkers = $workers->map(function ($worker) use ($job) {
            $score = $this->scoreWorker($worker, $job);
            $worker->match_score = $score;
            $worker->match_distance = $this->calculateDistance(
                $worker->latitude,
                $worker->longitude,
                $job->latitude,
                $job->longitude
            );
            return $worker;
        });

        // Filter by minimum score and sort
        return $scoredWorkers
            ->filter(fn($w) => $w->match_score >= self::MIN_RECOMMENDATION_SCORE)
            ->sortByDesc('match_score')
            ->take($limit)
            ->values();
    }

    /**
     * Calculate a worker's match score for a job.
     *
     * @param JobWorker $worker The worker to score
     * @param JobPost $job The job to match against
     * @return float Score between 0 and 1
     */
    public function scoreWorker(JobWorker $worker, JobPost $job): float
    {
        $scores = [];

        // Distance score (0-1, closer is better)
        $distance = $this->calculateDistance(
            $worker->latitude,
            $worker->longitude,
            $job->latitude,
            $job->longitude
        );
        $scores['distance'] = $this->normalizeDistanceScore($distance, self::MAX_RADIUS_KM);

        // Rating score (0-1)
        $scores['rating'] = $this->normalizeRatingScore($worker->rating ?? 0, $worker->rating_count ?? 0);

        // Experience score (0-1)
        $scores['experience'] = $this->normalizeExperienceScore($worker->jobs_completed ?? 0);

        // Availability match score (0-1)
        $scores['availability'] = $this->calculateAvailabilityScore($worker, $job);

        // Response rate score (0-1)
        $scores['response_rate'] = $this->normalizeResponseRate($worker);

        // Reliability score (0-1)
        $scores['reliability'] = $this->calculateReliabilityScore($worker);

        // Calculate weighted total
        $totalScore = 0;
        foreach (self::SCORING_WEIGHTS as $factor => $weight) {
            $totalScore += ($scores[$factor] ?? 0) * $weight;
        }

        return round($totalScore, 3);
    }

    /**
     * Check if a worker can do a specific job.
     *
     * @param JobWorker $worker The worker to check
     * @param JobPost $job The job to check against
     * @return bool True if worker can do the job
     */
    public function canWorkerDoJob(JobWorker $worker, JobPost $job): bool
    {
        // Must be active and verified
        if (!$worker->is_active || $worker->verification_status !== WorkerVerificationStatus::VERIFIED) {
            return false;
        }

        // Check distance
        $distance = $this->calculateDistance(
            $worker->latitude,
            $worker->longitude,
            $job->latitude,
            $job->longitude
        );

        if ($distance > self::MAX_RADIUS_KM) {
            return false;
        }

        // Check category match
        if ($job->category_id && $worker->category_ids) {
            if (!in_array($job->category_id, $worker->category_ids)) {
                return false;
            }
        }

        // Check vehicle requirement
        if ($job->vehicle_required && $job->vehicle_type) {
            if (!$worker->has_vehicle || $worker->vehicle_type !== $job->vehicle_type) {
                return false;
            }
        }

        // Check availability
        if ($job->time_slot) {
            $workerAvailability = $worker->availability ?? [];
            $hasFlexible = in_array(WorkerAvailability::FLEXIBLE->value, $workerAvailability);
            $hasMatchingSlot = in_array($job->time_slot->value, $workerAvailability);
            
            if (!$hasFlexible && !$hasMatchingSlot) {
                return false;
            }
        }

        // Check not already assigned on same date
        $hasConflict = $worker->assignedJobs()
            ->whereDate('scheduled_date', $job->scheduled_date)
            ->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])
            ->exists();

        if ($hasConflict) {
            return false;
        }

        return true;
    }

    /**
     * Get available jobs for a worker within radius.
     *
     * @param JobWorker $worker The worker to find jobs for
     * @param int $radiusKm Search radius
     * @return Collection Collection of matching jobs
     */
    public function getAvailableJobsForWorker(JobWorker $worker, int $radiusKm = self::DEFAULT_RADIUS_KM): Collection
    {
        $radiusKm = min($radiusKm, self::MAX_RADIUS_KM);

        $workerLat = $worker->latitude;
        $workerLng = $worker->longitude;
        $categoryIds = $worker->category_ids ?? [];

        $query = JobPost::query()
            ->where('status', JobStatus::OPEN)
            ->whereDate('scheduled_date', '>=', today())
            // Within radius
            ->whereRaw(
                "(? * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?",
                [self::EARTH_RADIUS_KM, $workerLat, $workerLng, $workerLat, $radiusKm]
            );

        // Filter by worker's categories
        if (!empty($categoryIds)) {
            $query->whereIn('category_id', $categoryIds);
        }

        // Filter by availability match
        $workerAvailability = $worker->availability ?? [];
        $hasFlexible = in_array(WorkerAvailability::FLEXIBLE->value, $workerAvailability);
        
        if (!empty($workerAvailability) && !$hasFlexible) {
            $query->where(function ($q) use ($workerAvailability) {
                $q->whereIn('time_slot', $workerAvailability)
                    ->orWhereNull('time_slot');
            });
        }

        // Filter by vehicle match if job requires
        $query->where(function ($q) use ($worker) {
            $q->where('vehicle_required', false)
                ->orWhere(function ($q2) use ($worker) {
                    if ($worker->has_vehicle && $worker->vehicle_type) {
                        $q2->where('vehicle_required', true)
                            ->where('vehicle_type', $worker->vehicle_type);
                    } else {
                        $q2->where('vehicle_required', false);
                    }
                });
        });

        // Exclude jobs worker already applied to
        $query->whereDoesntHave('applications', function ($q) use ($worker) {
            $q->where('worker_id', $worker->id);
        });

        // Exclude jobs on dates worker is already assigned
        $assignedDates = $worker->assignedJobs()
            ->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])
            ->pluck('scheduled_date')
            ->map(fn($d) => $d->toDateString())
            ->toArray();

        if (!empty($assignedDates)) {
            $query->whereNotIn(DB::raw('DATE(scheduled_date)'), $assignedDates);
        }

        // Add distance calculation
        $query->selectRaw("*, (? * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) as distance_km", [
            self::EARTH_RADIUS_KM, $workerLat, $workerLng, $workerLat
        ]);

        return $query
            ->with(['category', 'user'])
            ->orderBy('distance_km')
            ->orderBy('scheduled_date')
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

        return self::EARTH_RADIUS_KM * $c;
    }

    /**
     * Format distance for display.
     *
     * @param float $distanceKm Distance in kilometers
     * @return string Formatted distance string
     */
    public function formatDistance(float $distanceKm): string
    {
        if ($distanceKm < 1) {
            return round($distanceKm * 1000) . 'm';
        }

        return round($distanceKm, 1) . ' km';
    }

    /**
     * Check if location is within radius.
     *
     * @param float $centerLat Center latitude
     * @param float $centerLng Center longitude
     * @param float $pointLat Point latitude
     * @param float $pointLng Point longitude
     * @param float $radiusKm Radius in kilometers
     * @return bool True if within radius
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
    | Score Normalization Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize distance to a 0-1 score (closer = higher).
     */
    protected function normalizeDistanceScore(float $distance, float $maxDistance): float
    {
        if ($distance >= $maxDistance) {
            return 0;
        }

        // Exponential decay for distance preference
        return exp(-$distance / ($maxDistance / 3));
    }

    /**
     * Normalize rating to a 0-1 score.
     */
    protected function normalizeRatingScore(float $rating, int $ratingCount): float
    {
        if ($ratingCount === 0) {
            return 0.5; // Neutral score for new workers
        }

        // Weight rating by confidence (more ratings = more confident)
        $confidence = min($ratingCount / 10, 1); // Full confidence at 10+ ratings
        $normalizedRating = $rating / 5;

        // Blend with neutral rating based on confidence
        return ($normalizedRating * $confidence) + (0.5 * (1 - $confidence));
    }

    /**
     * Normalize experience (jobs completed) to a 0-1 score.
     */
    protected function normalizeExperienceScore(int $jobsCompleted): float
    {
        // Logarithmic scale - diminishing returns after ~50 jobs
        if ($jobsCompleted === 0) {
            return 0;
        }

        return min(log10($jobsCompleted + 1) / log10(51), 1);
    }

    /**
     * Calculate availability match score.
     */
    protected function calculateAvailabilityScore(JobWorker $worker, JobPost $job): float
    {
        // If no time slot specified, full score
        if (!$job->time_slot) {
            return 1.0;
        }

        $workerAvailability = $worker->availability ?? [];
        
        // Empty availability means flexible (full score)
        if (empty($workerAvailability)) {
            return 1.0;
        }

        // Flexible workers get full score
        if (in_array(WorkerAvailability::FLEXIBLE->value, $workerAvailability)) {
            return 1.0;
        }

        // Exact match
        if (in_array($job->time_slot->value, $workerAvailability)) {
            return 1.0;
        }

        // Adjacent time slots get partial score
        $slots = [
            WorkerAvailability::MORNING->value => 0,
            WorkerAvailability::AFTERNOON->value => 1,
            WorkerAvailability::EVENING->value => 2,
        ];

        $jobSlot = $slots[$job->time_slot->value] ?? null;
        
        if ($jobSlot === null) {
            return 0.5;
        }

        // Check if any worker availability is adjacent
        $bestScore = 0;
        foreach ($workerAvailability as $avail) {
            $workerSlot = $slots[$avail] ?? null;
            if ($workerSlot !== null) {
                $diff = abs($workerSlot - $jobSlot);
                $score = max(0, 1 - ($diff * 0.5));
                $bestScore = max($bestScore, $score);
            }
        }

        return $bestScore > 0 ? $bestScore : 0.5;
    }

    /**
     * Normalize response rate to a 0-1 score.
     */
    protected function normalizeResponseRate(JobWorker $worker): float
    {
        $totalApplications = $worker->applications()->count();

        if ($totalApplications < 5) {
            return 0.5; // Neutral for workers with few applications
        }

        $acceptedApplications = $worker->applications()
            ->where('status', 'accepted')
            ->count();

        return $acceptedApplications / $totalApplications;
    }

    /**
     * Calculate reliability score based on cancellation history.
     */
    protected function calculateReliabilityScore(JobWorker $worker): float
    {
        $totalAssigned = $worker->assignedJobs()->count();

        if ($totalAssigned < 3) {
            return 0.5; // Neutral for workers with few jobs
        }

        $cancelled = $worker->assignedJobs()
            ->where('status', JobStatus::CANCELLED)
            ->where('cancelled_by', 'worker')
            ->count();

        $cancellationRate = $cancelled / $totalAssigned;

        // Lower cancellation rate = higher score
        return max(0, 1 - ($cancellationRate * 2));
    }

    /*
    |--------------------------------------------------------------------------
    | Analytics & Insights
    |--------------------------------------------------------------------------
    */

    /**
     * Get worker density heatmap for an area.
     *
     * @param float $minLat Minimum latitude
     * @param float $maxLat Maximum latitude
     * @param float $minLng Minimum longitude
     * @param float $maxLng Maximum longitude
     * @param float $gridSize Grid size in degrees (~0.01 = 1km)
     * @return array Heatmap data
     */
    public function getWorkerHeatmap(
        float $minLat,
        float $maxLat,
        float $minLng,
        float $maxLng,
        float $gridSize = 0.01
    ): array {
        $heatmap = [];

        $workers = JobWorker::active()
            ->verified()
            ->whereBetween('latitude', [$minLat, $maxLat])
            ->whereBetween('longitude', [$minLng, $maxLng])
            ->get();

        foreach ($workers as $worker) {
            $gridLat = round($worker->latitude / $gridSize) * $gridSize;
            $gridLng = round($worker->longitude / $gridSize) * $gridSize;
            $key = "{$gridLat},{$gridLng}";

            if (!isset($heatmap[$key])) {
                $heatmap[$key] = [
                    'lat' => $gridLat,
                    'lng' => $gridLng,
                    'count' => 0,
                    'avg_rating' => 0,
                    'total_jobs' => 0,
                ];
            }

            $heatmap[$key]['count']++;
            $heatmap[$key]['avg_rating'] = (
                ($heatmap[$key]['avg_rating'] * ($heatmap[$key]['count'] - 1)) +
                ($worker->rating ?? 0)
            ) / $heatmap[$key]['count'];
            $heatmap[$key]['total_jobs'] += $worker->jobs_completed ?? 0;
        }

        return array_values($heatmap);
    }

    /**
     * Count available workers in an area.
     *
     * @param float $latitude Center latitude
     * @param float $longitude Center longitude
     * @param float $radiusKm Search radius
     * @param int|null $categoryId Optional category filter
     * @return int Worker count
     */
    public function countAvailableWorkers(
        float $latitude,
        float $longitude,
        float $radiusKm = self::DEFAULT_RADIUS_KM,
        ?int $categoryId = null
    ): int {
        $query = JobWorker::active()
            ->verified()
            ->whereRaw(
                "(? * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?",
                [self::EARTH_RADIUS_KM, $latitude, $longitude, $latitude, $radiusKm]
            );

        if ($categoryId) {
            $query->where(function ($q) use ($categoryId) {
                $q->whereJsonContains('category_ids', $categoryId)
                    ->orWhereNull('category_ids');
            });
        }

        return $query->count();
    }

    /**
     * Get popular categories in an area.
     *
     * @param float $latitude Center latitude
     * @param float $longitude Center longitude
     * @param float $radiusKm Search radius
     * @param int $limit Number of categories to return
     * @return Collection Top categories with worker counts
     */
    public function getPopularCategoriesInArea(
        float $latitude,
        float $longitude,
        float $radiusKm = self::DEFAULT_RADIUS_KM,
        int $limit = 10
    ): Collection {
        $workers = JobWorker::active()
            ->verified()
            ->whereRaw(
                "(? * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?",
                [self::EARTH_RADIUS_KM, $latitude, $longitude, $latitude, $radiusKm]
            )
            ->whereNotNull('category_ids')
            ->get();

        $categoryCounts = [];

        foreach ($workers as $worker) {
            foreach ($worker->category_ids ?? [] as $categoryId) {
                $categoryCounts[$categoryId] = ($categoryCounts[$categoryId] ?? 0) + 1;
            }
        }

        arsort($categoryCounts);
        $topIds = array_slice(array_keys($categoryCounts), 0, $limit);

        return JobCategory::whereIn('id', $topIds)
            ->active()
            ->get()
            ->map(function ($category) use ($categoryCounts) {
                $category->worker_count = $categoryCounts[$category->id] ?? 0;
                return $category;
            })
            ->sortByDesc('worker_count')
            ->values();
    }

    /**
     * Suggest optimal location for posting a job.
     *
     * @param float $currentLat Current latitude
     * @param float $currentLng Current longitude
     * @param int|null $categoryId Optional category
     * @param float $searchRadius Search radius
     * @return array Location suggestion with worker density
     */
    public function suggestJobLocation(
        float $currentLat,
        float $currentLng,
        ?int $categoryId = null,
        float $searchRadius = 5
    ): array {
        $heatmap = $this->getWorkerHeatmap(
            $currentLat - ($searchRadius / 111),
            $currentLat + ($searchRadius / 111),
            $currentLng - ($searchRadius / (111 * cos(deg2rad($currentLat)))),
            $currentLng + ($searchRadius / (111 * cos(deg2rad($currentLat)))),
            0.005
        );

        if (empty($heatmap)) {
            return [
                'current_lat' => $currentLat,
                'current_lng' => $currentLng,
                'suggested_lat' => $currentLat,
                'suggested_lng' => $currentLng,
                'potential_workers' => 0,
                'message' => 'No workers found nearby',
            ];
        }

        usort($heatmap, fn($a, $b) => $b['count'] <=> $a['count']);
        $best = $heatmap[0];

        $distance = $this->calculateDistance($currentLat, $currentLng, $best['lat'], $best['lng']);

        return [
            'current_lat' => $currentLat,
            'current_lng' => $currentLng,
            'suggested_lat' => $best['lat'],
            'suggested_lng' => $best['lng'],
            'potential_workers' => $best['count'],
            'avg_rating' => round($best['avg_rating'], 1),
            'distance_km' => round($distance, 2),
            'message' => $distance < 0.5
                ? 'Good worker availability at your location!'
                : "Moving {$this->formatDistance($distance)} could increase worker availability to {$best['count']} workers",
        ];
    }

    /**
     * Get matching statistics for a job.
     *
     * @param JobPost $job The job to analyze
     * @return array Matching statistics
     */
    public function getMatchingStats(JobPost $job): array
    {
        $allWorkers = $this->findMatchingWorkers($job, self::MAX_RADIUS_KM);

        $within5km = $allWorkers->filter(function ($worker) use ($job) {
            return $this->calculateDistance(
                $worker->latitude,
                $worker->longitude,
                $job->latitude,
                $job->longitude
            ) <= 5;
        });

        $within10km = $allWorkers->filter(function ($worker) use ($job) {
            return $this->calculateDistance(
                $worker->latitude,
                $worker->longitude,
                $job->latitude,
                $job->longitude
            ) <= 10;
        });

        return [
            'total_matching' => $allWorkers->count(),
            'within_5km' => $within5km->count(),
            'within_10km' => $within10km->count(),
            'avg_rating' => round($allWorkers->avg('rating') ?? 0, 1),
            'avg_experience' => round($allWorkers->avg('jobs_completed') ?? 0),
            'with_vehicle' => $allWorkers->where('has_vehicle', true)->count(),
            'verified' => $allWorkers->where('verification_status', WorkerVerificationStatus::VERIFIED)->count(),
        ];
    }
}