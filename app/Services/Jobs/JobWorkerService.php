<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\VehicleType;
use App\Enums\UserType;
use App\Models\ConversationSession;
use App\Models\JobWorker;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service class for Job Worker operations.
 *
 * Handles worker registration, profile management, and related operations
 * for the Njaanum Panikkar (Basic Jobs Marketplace) module.
 *
 * IMPORTANT: Supports TWO registration paths:
 * 1. Existing registered users (customers/shops) - adds worker profile
 * 2. New unregistered users - creates new user with worker profile
 *
 * @srs-ref Section 3.2 - Job Worker Management
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobWorkerService
{
    /**
     * Create a new job worker profile for an existing registered user.
     *
     * This allows customers and shop owners to also become job workers
     * without changing their primary user type.
     *
     * @param User $user The registered user to add worker profile to
     * @param array $data Worker registration data
     * @return JobWorker
     * @throws \InvalidArgumentException If validation fails or user already a worker
     */
    public function registerExistingUserAsWorker(User $user, array $data): JobWorker
    {
        // Validate user is registered
        if (!$user->registered_at) {
            throw new \InvalidArgumentException('User must be registered first.');
        }

        // Check if user already has a worker profile
        if ($user->jobWorker) {
            throw new \InvalidArgumentException('User is already registered as a worker.');
        }

        // Validate required fields
        $this->validateWorkerData($data);

        return DB::transaction(function () use ($user, $data) {
            $worker = JobWorker::create([
                'user_id' => $user->id,
                'name' => trim($data['name']),
                'photo_url' => $data['photo_url'] ?? null,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'address' => $data['address'] ?? null,
                'vehicle_type' => $this->parseVehicleType($data['vehicle_type'] ?? 'none'),
                'job_types' => $data['job_types'] ?? [],
                'availability' => $data['availability'] ?? ['flexible'],
                'is_available' => true,
                'is_verified' => false,
                'last_active_at' => now(),
            ]);

            Log::info('Existing user registered as job worker', [
                'worker_id' => $worker->id,
                'user_id' => $user->id,
                'user_type' => $user->type->value,
                'name' => $worker->name,
            ]);

            return $worker;
        });
    }

    /**
     * Create a new user and worker profile together (for unregistered users).
     *
     * @param array $data Combined user and worker data (must include 'phone')
     * @return User User with jobWorker relation loaded
     * @throws \InvalidArgumentException If validation fails
     */
    public function createUserAndWorker(array $data): User
    {
        if (empty($data['phone'])) {
            throw new \InvalidArgumentException('Phone number is required.');
        }

        // Validate worker data
        $this->validateWorkerData($data);

        return DB::transaction(function () use ($data) {
            // Create user with CUSTOMER type (default for new users)
            $user = User::create([
                'phone' => $data['phone'],
                'name' => trim($data['name']),
                'type' => UserType::CUSTOMER,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'address' => $data['address'] ?? null,
                'registered_at' => now(),
            ]);

            // Create worker profile
            $worker = JobWorker::create([
                'user_id' => $user->id,
                'name' => trim($data['name']),
                'photo_url' => $data['photo_url'] ?? null,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'address' => $data['address'] ?? null,
                'vehicle_type' => $this->parseVehicleType($data['vehicle_type'] ?? 'none'),
                'job_types' => $data['job_types'] ?? [],
                'availability' => $data['availability'] ?? ['flexible'],
                'is_available' => true,
                'is_verified' => false,
                'last_active_at' => now(),
            ]);

            Log::info('New user and job worker created', [
                'user_id' => $user->id,
                'worker_id' => $worker->id,
                'phone' => substr($data['phone'], 0, 4) . '****',
            ]);

            return $user->load('jobWorker');
        });
    }

    /**
     * Update worker profile.
     *
     * @param JobWorker $worker
     * @param array $data
     * @return JobWorker
     */
    public function updateWorker(JobWorker $worker, array $data): JobWorker
    {
        $updateData = [];

        if (isset($data['name']) && $this->isValidName($data['name'])) {
            $updateData['name'] = trim($data['name']);
        }

        if (array_key_exists('photo_url', $data)) {
            $updateData['photo_url'] = $data['photo_url'];
        }

        if (isset($data['latitude']) && isset($data['longitude'])) {
            if ($this->isValidCoordinates($data['latitude'], $data['longitude'])) {
                $updateData['latitude'] = $data['latitude'];
                $updateData['longitude'] = $data['longitude'];
                if (isset($data['address'])) {
                    $updateData['address'] = $data['address'];
                }
            }
        }

        if (isset($data['vehicle_type'])) {
            $updateData['vehicle_type'] = $this->parseVehicleType($data['vehicle_type']);
        }

        if (isset($data['job_types']) && is_array($data['job_types'])) {
            $updateData['job_types'] = array_values(array_unique($data['job_types']));
        }

        if (isset($data['availability']) && is_array($data['availability'])) {
            $updateData['availability'] = array_values(array_unique($data['availability']));
        }

        if (isset($data['is_available'])) {
            $updateData['is_available'] = (bool) $data['is_available'];
        }

        if (!empty($updateData)) {
            $worker->update($updateData);

            Log::info('Job worker updated', [
                'worker_id' => $worker->id,
                'fields' => array_keys($updateData),
            ]);
        }

        return $worker->fresh();
    }

    /**
     * Get worker profile by user.
     *
     * @param User $user
     * @return JobWorker|null
     */
    public function getWorkerByUser(User $user): ?JobWorker
    {
        return $user->jobWorker;
    }

    /**
     * Get worker profile by user ID.
     *
     * @param int $userId
     * @return JobWorker|null
     */
    public function getWorkerByUserId(int $userId): ?JobWorker
    {
        return JobWorker::where('user_id', $userId)->first();
    }

    /**
     * Check if user is registered as a worker.
     *
     * @param User $user
     * @return bool
     */
    public function isUserWorker(User $user): bool
    {
        return $user->jobWorker !== null;
    }

    /**
     * Check if phone number is registered as a worker.
     *
     * @param string $phone
     * @return bool
     */
    public function isPhoneRegisteredAsWorker(string $phone): bool
    {
        $user = User::where('phone', $phone)->first();

        if (!$user) {
            return false;
        }

        return $this->isUserWorker($user);
    }

    /**
     * Update worker's last active timestamp.
     *
     * @param JobWorker $worker
     * @return void
     */
    public function updateLastActive(JobWorker $worker): void
    {
        $worker->touchLastActive();
    }

    /**
     * Toggle worker availability status.
     *
     * @param JobWorker $worker
     * @return bool New availability status
     */
    public function toggleAvailability(JobWorker $worker): bool
    {
        $worker->toggleAvailability();

        Log::info('Worker availability toggled', [
            'worker_id' => $worker->id,
            'is_available' => $worker->is_available,
        ]);

        return $worker->is_available;
    }

    /**
     * Set worker as available.
     *
     * @param JobWorker $worker
     * @return void
     */
    public function setAvailable(JobWorker $worker): void
    {
        $worker->update(['is_available' => true]);
    }

    /**
     * Set worker as unavailable.
     *
     * @param JobWorker $worker
     * @return void
     */
    public function setUnavailable(JobWorker $worker): void
    {
        $worker->update(['is_available' => false]);
    }

    /**
     * Verify a worker (admin action).
     *
     * @param JobWorker $worker
     * @param string|null $verificationPhotoUrl
     * @return void
     */
    public function verifyWorker(JobWorker $worker, ?string $verificationPhotoUrl = null): void
    {
        $worker->update([
            'is_verified' => true,
            'verified_at' => now(),
            'verification_photo_url' => $verificationPhotoUrl,
        ]);

        Log::info('Worker verified', ['worker_id' => $worker->id]);
    }

    /**
     * Add job types to worker's profile.
     *
     * @param JobWorker $worker
     * @param array $jobTypeIds
     * @return JobWorker
     */
    public function addJobTypes(JobWorker $worker, array $jobTypeIds): JobWorker
    {
        $currentTypes = $worker->job_types ?? [];
        $mergedTypes = array_values(array_unique(array_merge($currentTypes, $jobTypeIds)));

        $worker->update(['job_types' => $mergedTypes]);

        return $worker->fresh();
    }

    /**
     * Remove job types from worker's profile.
     *
     * @param JobWorker $worker
     * @param array $jobTypeIds
     * @return JobWorker
     */
    public function removeJobTypes(JobWorker $worker, array $jobTypeIds): JobWorker
    {
        $currentTypes = $worker->job_types ?? [];
        $filteredTypes = array_values(array_diff($currentTypes, $jobTypeIds));

        $worker->update(['job_types' => $filteredTypes]);

        return $worker->fresh();
    }

    /**
     * Update worker's availability slots.
     *
     * @param JobWorker $worker
     * @param array $availability
     * @return JobWorker
     */
    public function updateAvailability(JobWorker $worker, array $availability): JobWorker
    {
        $validSlots = ['morning', 'afternoon', 'evening', 'flexible'];
        $filteredAvailability = array_values(array_intersect($availability, $validSlots));

        if (empty($filteredAvailability)) {
            $filteredAvailability = ['flexible'];
        }

        $worker->update(['availability' => $filteredAvailability]);

        return $worker->fresh();
    }

    /**
     * Link conversation session to user.
     *
     * @param ConversationSession $session
     * @param User $user
     * @return void
     */
    public function linkSessionToUser(ConversationSession $session, User $user): void
    {
        $session->update(['user_id' => $user->id]);
    }

    /**
     * Get workers near a location.
     *
     * @param float $latitude
     * @param float $longitude
     * @param float $radiusKm
     * @param array $filters Optional filters (job_type_id, requires_vehicle, availability, min_rating)
     * @return \Illuminate\Database\Eloquent\Collection
     */
    public function getWorkersNearLocation(
        float $latitude,
        float $longitude,
        float $radiusKm = 5,
        array $filters = []
    ): \Illuminate\Database\Eloquent\Collection {
        $query = JobWorker::available()
            ->nearLocation($latitude, $longitude, $radiusKm)
            ->withDistanceFrom($latitude, $longitude);

        // Filter by job type
        if (!empty($filters['job_type_id'])) {
            $query->canDoJob($filters['job_type_id']);
        }

        // Filter by vehicle type
        if (!empty($filters['requires_vehicle'])) {
            $query->withVehicle();
        }

        // Filter by availability
        if (!empty($filters['availability'])) {
            $query->availableAt($filters['availability']);
        }

        // Filter by minimum rating
        if (!empty($filters['min_rating'])) {
            $query->minRating($filters['min_rating']);
        }

        return $query
            ->orderBy('distance_km')
            ->orderByDesc('rating')
            ->limit($filters['limit'] ?? 50)
            ->get();
    }

    /**
     * Get worker statistics.
     *
     * @param JobWorker $worker
     * @return array
     */
    public function getWorkerStats(JobWorker $worker): array
    {
        $thisWeek = $worker->earnings()
            ->thisWeek()
            ->first();

        $thisMonth = $worker->earnings()
            ->thisMonth()
            ->get();

        $monthlyEarnings = $thisMonth->sum('total_earnings');
        $monthlyJobs = $thisMonth->sum('total_jobs');

        return [
            'total_jobs' => $worker->jobs_completed,
            'total_earnings' => $worker->total_earnings,
            'rating' => $worker->rating,
            'rating_count' => $worker->rating_count,
            'this_week_earnings' => $thisWeek?->total_earnings ?? 0,
            'this_week_jobs' => $thisWeek?->total_jobs ?? 0,
            'this_month_earnings' => $monthlyEarnings,
            'this_month_jobs' => $monthlyJobs,
            'badges_count' => $worker->badges()->count(),
            'active_jobs_count' => $worker->activeJobs()->count(),
            'pending_applications' => $worker->pendingApplications()->count(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Validate worker registration data.
     *
     * @param array $data
     * @throws \InvalidArgumentException
     */
    protected function validateWorkerData(array $data): void
    {
        // Name validation
        if (empty($data['name']) || !$this->isValidName($data['name'])) {
            throw new \InvalidArgumentException('Valid name is required (2-100 characters).');
        }

        // Location validation
        if (!isset($data['latitude']) || !isset($data['longitude'])) {
            throw new \InvalidArgumentException('Location coordinates are required.');
        }

        if (!$this->isValidCoordinates($data['latitude'], $data['longitude'])) {
            throw new \InvalidArgumentException('Invalid location coordinates.');
        }

        // Job types validation (optional but should be array if provided)
        if (isset($data['job_types']) && !is_array($data['job_types'])) {
            throw new \InvalidArgumentException('Job types must be an array.');
        }

        // Availability validation (optional but should be array if provided)
        if (isset($data['availability']) && !is_array($data['availability'])) {
            throw new \InvalidArgumentException('Availability must be an array.');
        }
    }

    /**
     * Validate name.
     *
     * @param string $name
     * @return bool
     */
    public function isValidName(string $name): bool
    {
        $length = mb_strlen(trim($name));
        return $length >= 2 && $length <= 100;
    }

    /**
     * Validate coordinates.
     *
     * Kerala/India bounds check for location validation.
     *
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    public function isValidCoordinates(float $latitude, float $longitude): bool
    {
        // Basic range check
        if ($latitude < -90 || $latitude > 90) {
            return false;
        }

        if ($longitude < -180 || $longitude > 180) {
            return false;
        }

        // India approximate bounds (loose check)
        // India: Lat 6.0-36.0, Lon 68.0-98.0
        if ($latitude < 6.0 || $latitude > 36.0) {
            return false;
        }

        if ($longitude < 68.0 || $longitude > 98.0) {
            return false;
        }

        return true;
    }

    /**
     * Parse vehicle type from string to enum.
     *
     * @param string $vehicleType
     * @return VehicleType
     */
    protected function parseVehicleType(string $vehicleType): VehicleType
    {
        return match ($vehicleType) {
            'two_wheeler', 'bike', 'scooter', 'motorcycle' => VehicleType::TWO_WHEELER,
            'four_wheeler', 'car', 'auto', 'van' => VehicleType::FOUR_WHEELER,
            default => VehicleType::NONE,
        };
    }
}