<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\JobStatus;
use App\Enums\VehicleType;
use App\Models\JobCategory;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\JobMessages;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for job posting operations.
 *
 * Handles:
 * - Creating job posts
 * - Finding matching workers
 * - Notifying workers
 * - Job lifecycle management
 *
 * @srs-ref Section 3.3 - Job Posting
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobPostingService
{
    /**
     * Default search radius in kilometers.
     */
    protected const DEFAULT_RADIUS_KM = 5;

    /**
     * Maximum search radius in kilometers.
     */
    protected const MAX_RADIUS_KM = 20;

    /**
     * Default expiry hours for open jobs.
     */
    protected const DEFAULT_EXPIRY_HOURS = 48;

    /**
     * Maximum workers to notify per job.
     */
    protected const MAX_WORKERS_TO_NOTIFY = 50;

    public function __construct(
        protected WhatsAppService $whatsApp
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Job Creation
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new job post.
     *
     * @param User $poster The user posting the job
     * @param array $data Job data
     * @return JobPost
     */
    public function createJobPost(User $poster, array $data): JobPost
    {
        // Validate required fields
        $this->validateJobData($data);

        return DB::transaction(function () use ($poster, $data) {
            $job = new JobPost();
            $job->poster_user_id = $poster->id;
            $job->job_category_id = $data['job_category_id'];
            $job->title = trim($data['title']);
            $job->description = isset($data['description']) ? trim($data['description']) : null;
            $job->location_name = trim($data['location_name']);
            $job->latitude = $data['latitude'] ?? null;
            $job->longitude = $data['longitude'] ?? null;
            $job->job_date = $this->parseJobDate($data['job_date']);
            $job->job_time = $data['job_time'] ?? null;
            $job->duration_hours = $data['duration_hours'] ?? null;
            $job->pay_amount = $data['pay_amount'];
            $job->special_instructions = isset($data['special_instructions']) ? trim($data['special_instructions']) : null;
            $job->status = JobStatus::DRAFT;
            $job->applications_count = 0;

            $job->save();

            Log::info('Job post created', [
                'job_id' => $job->id,
                'job_number' => $job->job_number,
                'poster_id' => $poster->id,
                'category_id' => $job->job_category_id,
            ]);

            return $job;
        });
    }

    /**
     * Validate job data.
     *
     * @throws \InvalidArgumentException
     */
    protected function validateJobData(array $data): void
    {
        if (empty($data['job_category_id'])) {
            throw new \InvalidArgumentException('Job category is required');
        }

        if (empty($data['title'])) {
            throw new \InvalidArgumentException('Job title is required');
        }

        if (empty($data['location_name'])) {
            throw new \InvalidArgumentException('Location is required');
        }

        if (empty($data['job_date'])) {
            throw new \InvalidArgumentException('Job date is required');
        }

        if (empty($data['pay_amount']) || $data['pay_amount'] < 0) {
            throw new \InvalidArgumentException('Valid pay amount is required');
        }
    }

    /**
     * Parse job date from various formats.
     */
    protected function parseJobDate($date): Carbon
    {
        if ($date instanceof Carbon) {
            return $date;
        }

        if (is_string($date)) {
            return Carbon::parse($date);
        }

        return Carbon::today();
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Matching & Notification
    |--------------------------------------------------------------------------
    */

    /**
     * Find workers matching job criteria.
     *
     * @param JobPost $job The job post
     * @param int $radiusKm Search radius in kilometers
     * @return Collection<JobWorker>
     */
    public function findMatchingWorkers(JobPost $job, int $radiusKm = self::DEFAULT_RADIUS_KM): Collection
    {
        $query = JobWorker::query()
            ->where('is_available', true)
            ->where('is_active', true);

        // Filter by job category
        $categoryId = $job->job_category_id;
        $query->whereJsonContains('job_types', $categoryId);

        // Filter by vehicle requirement
        $category = $job->category;
        if ($category && $category->requires_vehicle) {
            $query->whereIn('vehicle_type', [
                VehicleType::TWO_WHEELER->value,
                VehicleType::FOUR_WHEELER->value,
            ]);
        }

        // Filter by location if coordinates available
        if ($job->latitude && $job->longitude) {
            $query->whereNotNull('latitude')
                ->whereNotNull('longitude')
                ->whereRaw(
                    "ST_Distance_Sphere(
                        POINT(longitude, latitude),
                        POINT(?, ?)
                    ) <= ?",
                    [$job->longitude, $job->latitude, $radiusKm * 1000]
                )
                ->selectRaw(
                    "*, ST_Distance_Sphere(
                        POINT(longitude, latitude),
                        POINT(?, ?)
                    ) / 1000 as distance_km",
                    [$job->longitude, $job->latitude]
                )
                ->orderBy('distance_km');
        }

        // Exclude workers who already have an active job at the same time
        $query->whereDoesntHave('activeJobs', function ($q) use ($job) {
            $q->whereDate('job_date', $job->job_date)
                ->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS]);
        });

        // Order by rating and completed jobs
        $query->orderByDesc('rating')
            ->orderByDesc('jobs_completed');

        // Limit results
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
        $workers = $this->findMatchingWorkers($job);

        if ($workers->isEmpty()) {
            Log::info('No matching workers found for job', [
                'job_id' => $job->id,
                'job_number' => $job->job_number,
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
            'job_number' => $job->job_number,
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
        $distanceKm = 0;
        if ($job->latitude && $job->longitude && $worker->latitude && $worker->longitude) {
            $distanceKm = $job->getDistanceFrom($worker->latitude, $worker->longitude) ?? 0;
        }

        // Generate notification message
        $message = JobMessages::newJobNotification($job, $distanceKm);

        // Send via WhatsApp
        $this->sendJobMessage($phone, $message);

        Log::debug('Sent job notification to worker', [
            'worker_id' => $worker->id,
            'job_id' => $job->id,
            'distance_km' => $distanceKm,
        ]);
    }

    /**
     * Send a JobMessages response via WhatsApp.
     */
    protected function sendJobMessage(string $phone, array $response): void
    {
        $type = $response['type'] ?? 'text';

        switch ($type) {
            case 'text':
                $this->whatsApp->sendText($phone, $response['text']);
                break;

            case 'buttons':
                $this->whatsApp->sendButtons(
                    $phone,
                    $response['body'] ?? $response['text'] ?? '',
                    $response['buttons'] ?? [],
                    $response['header'] ?? null,
                    $response['footer'] ?? null
                );
                break;

            case 'list':
                $this->whatsApp->sendList(
                    $phone,
                    $response['body'] ?? '',
                    $response['button'] ?? 'Select',
                    $response['sections'] ?? [],
                    $response['header'] ?? null,
                    $response['footer'] ?? null
                );
                break;

            default:
                $this->whatsApp->sendText($phone, $response['text'] ?? 'Message sent.');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Job Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Get jobs posted by a user.
     *
     * @param User $user The user
     * @param array $filters Optional filters (status, limit)
     * @return Collection<JobPost>
     */
    public function getJobsByPoster(User $user, array $filters = []): Collection
    {
        $query = JobPost::query()
            ->where('poster_user_id', $user->id)
            ->with(['category', 'assignedWorker'])
            ->orderByDesc('created_at');

        // Apply status filter
        if (!empty($filters['status'])) {
            if (is_array($filters['status'])) {
                $query->whereIn('status', $filters['status']);
            } else {
                $query->where('status', $filters['status']);
            }
        }

        // Apply date filter
        if (!empty($filters['from_date'])) {
            $query->whereDate('job_date', '>=', $filters['from_date']);
        }

        if (!empty($filters['to_date'])) {
            $query->whereDate('job_date', '<=', $filters['to_date']);
        }

        // Apply limit
        $limit = $filters['limit'] ?? 20;
        return $query->limit($limit)->get();
    }

    /**
     * Get count of active jobs for a user.
     *
     * @param User $user The user
     * @return int
     */
    public function getActiveJobsCount(User $user): int
    {
        return JobPost::query()
            ->where('poster_user_id', $user->id)
            ->whereIn('status', [
                JobStatus::DRAFT,
                JobStatus::OPEN,
                JobStatus::ASSIGNED,
                JobStatus::IN_PROGRESS,
            ])
            ->count();
    }

    /**
     * Get browsable jobs for workers.
     *
     * @param float|null $latitude Worker latitude
     * @param float|null $longitude Worker longitude
     * @param int $radiusKm Search radius
     * @param array $filters Optional filters
     * @return Collection<JobPost>
     */
    public function getBrowsableJobs(
        ?float $latitude = null,
        ?float $longitude = null,
        int $radiusKm = self::DEFAULT_RADIUS_KM,
        array $filters = []
    ): Collection {
        $query = JobPost::query()
            ->with(['category', 'poster'])
            ->browsable()
            ->upcoming();

        // Filter by category
        if (!empty($filters['category_id'])) {
            $query->where('job_category_id', $filters['category_id']);
        }

        // Filter by date
        if (!empty($filters['date'])) {
            $query->whereDate('job_date', $filters['date']);
        }

        // Filter by location
        if ($latitude && $longitude) {
            $query->nearLocation($latitude, $longitude, $radiusKm)
                ->withDistanceFrom($latitude, $longitude);
        }

        // Filter by minimum pay
        if (!empty($filters['min_pay'])) {
            $query->where('pay_amount', '>=', $filters['min_pay']);
        }

        // Apply limit
        $limit = $filters['limit'] ?? 20;
        return $query->limit($limit)->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Job Lifecycle Management
    |--------------------------------------------------------------------------
    */

    /**
     * Cancel a job post.
     *
     * @param JobPost $job The job to cancel
     * @param string|null $reason Cancellation reason
     * @return bool
     */
    public function cancelJob(JobPost $job, ?string $reason = null): bool
    {
        if (!$job->can_cancel) {
            Log::warning('Cannot cancel job', [
                'job_id' => $job->id,
                'status' => $job->status->value,
            ]);
            return false;
        }

        DB::transaction(function () use ($job, $reason) {
            $job->cancel();

            // Notify assigned worker if any
            if ($job->assignedWorker) {
                $this->notifyWorkerJobCancelled($job->assignedWorker, $job, $reason);
            }

            // Notify pending applicants
            $pendingApplications = $job->applications()
                ->where('status', 'pending')
                ->with('worker.user')
                ->get();

            foreach ($pendingApplications as $application) {
                if ($application->worker?->user?->phone) {
                    $this->notifyWorkerJobCancelled($application->worker, $job, $reason);
                }
            }

            Log::info('Job cancelled', [
                'job_id' => $job->id,
                'job_number' => $job->job_number,
                'reason' => $reason,
            ]);
        });

        return true;
    }

    /**
     * Notify worker that a job was cancelled.
     */
    protected function notifyWorkerJobCancelled(JobWorker $worker, JobPost $job, ?string $reason): void
    {
        $phone = $worker->user?->phone;
        if (!$phone) {
            return;
        }

        $reasonText = $reason ? "\n\nReason: {$reason}" : "";

        $this->whatsApp->sendButtons(
            $phone,
            "❌ *Job Cancelled*\n\n" .
            "{$job->category->icon} {$job->title}\n\n" .
            "This job has been cancelled by the poster." .
            $reasonText . "\n\n" .
            "_Browse more jobs below_",
            [
                ['id' => 'browse_jobs', 'title' => '🔍 Browse Jobs'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            '📋 Job Cancelled'
        );
    }

    /**
     * Expire old jobs (for scheduled command).
     *
     * @return int Number of jobs expired
     */
    public function expireOldJobs(): int
    {
        $expiredCount = 0;

        // Find jobs that should be expired
        $jobsToExpire = JobPost::query()
            ->where('status', JobStatus::OPEN)
            ->where(function ($query) {
                // Jobs past their expiry time
                $query->where('expires_at', '<=', now())
                    // Or jobs past their job date
                    ->orWhere('job_date', '<', Carbon::today());
            })
            ->get();

        foreach ($jobsToExpire as $job) {
            $job->markExpired();
            $expiredCount++;

            Log::info('Job expired', [
                'job_id' => $job->id,
                'job_number' => $job->job_number,
                'job_date' => $job->job_date,
                'expires_at' => $job->expires_at,
            ]);
        }

        if ($expiredCount > 0) {
            Log::info('Expired old jobs', ['count' => $expiredCount]);
        }

        return $expiredCount;
    }

    /**
     * Update job post.
     *
     * @param JobPost $job The job to update
     * @param array $data Update data
     * @return JobPost
     */
    public function updateJob(JobPost $job, array $data): JobPost
    {
        if (!$job->can_edit) {
            throw new \InvalidArgumentException('Cannot edit this job');
        }

        $allowedFields = [
            'title', 'description', 'location_name', 'latitude', 'longitude',
            'job_date', 'job_time', 'duration_hours', 'pay_amount', 'special_instructions',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $job->{$field} = $data[$field];
            }
        }

        $job->save();

        Log::info('Job updated', [
            'job_id' => $job->id,
            'job_number' => $job->job_number,
        ]);

        return $job;
    }

    /*
    |--------------------------------------------------------------------------
    | Pay Calculation
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate suggested pay for a job.
     *
     * @param JobCategory|null $category The job category
     * @param float $durationHours Duration in hours
     * @return array{min: int, max: int}
     */
    public function calculateSuggestedPay(?JobCategory $category, float $durationHours): array
    {
        // Default pay range if no category
        if (!$category) {
            $basePay = 200;
            return [
                'min' => (int) round($basePay * max(1, $durationHours), -1),
                'max' => (int) round($basePay * 1.5 * max(1, $durationHours), -1),
            ];
        }

        $payRange = $category->getSuggestedPayRange();
        $minPay = $payRange['min'] ?: 100;
        $maxPay = $payRange['max'] ?: 500;

        // Adjust based on duration
        $typicalDuration = $category->typical_duration_hours ?: 1;
        $multiplier = max(1, $durationHours / $typicalDuration);

        return [
            'min' => (int) round($minPay * $multiplier, -1),
            'max' => (int) round($maxPay * $multiplier, -1),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Validate title.
     *
     * @param string $title The title to validate
     * @return bool
     */
    public function isValidTitle(string $title): bool
    {
        $length = mb_strlen(trim($title));
        return $length >= 5 && $length <= 100;
    }

    /**
     * Validate coordinates.
     *
     * @param float $latitude Latitude
     * @param float $longitude Longitude
     * @return bool
     */
    public function isValidCoordinates(float $latitude, float $longitude): bool
    {
        // Basic range validation
        if ($latitude < -90 || $latitude > 90) {
            return false;
        }
        if ($longitude < -180 || $longitude > 180) {
            return false;
        }

        // India bounding box (approximate)
        $inIndia = (
            $latitude >= 6 && $latitude <= 36 &&
            $longitude >= 68 && $longitude <= 98
        );

        return $inIndia;
    }

    /**
     * Validate pay amount.
     *
     * @param float $amount The amount to validate
     * @return bool
     */
    public function isValidPayAmount(float $amount): bool
    {
        return $amount >= 50 && $amount <= 100000;
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get poster statistics.
     *
     * @param User $user The user
     * @return array
     */
    public function getPosterStats(User $user): array
    {
        $jobs = JobPost::query()
            ->where('poster_user_id', $user->id);

        $totalJobs = $jobs->count();
        $completedJobs = (clone $jobs)->where('status', JobStatus::COMPLETED)->count();
        $activeJobs = (clone $jobs)->whereIn('status', [
            JobStatus::OPEN, JobStatus::ASSIGNED, JobStatus::IN_PROGRESS
        ])->count();
        $totalSpent = (clone $jobs)->where('status', JobStatus::COMPLETED)->sum('pay_amount');

        return [
            'total_jobs' => $totalJobs,
            'completed_jobs' => $completedJobs,
            'active_jobs' => $activeJobs,
            'total_spent' => $totalSpent,
            'completion_rate' => $totalJobs > 0 ? round(($completedJobs / $totalJobs) * 100, 1) : 0,
        ];
    }
}