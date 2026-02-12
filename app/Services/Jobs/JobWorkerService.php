<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\UserType;
use App\Models\ConversationSession;
use App\Models\JobWorker;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job Worker Service.
 *
 * Handles worker registration and profile management.
 *
 * Supports TWO registration paths:
 * 1. Existing users (customers/shops) → adds worker profile
 * 2. New users → creates user + worker together
 *
 * @srs-ref NP-001 to NP-005: Worker Registration
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobWorkerService
{
    /**
     * Register existing user as worker.
     *
     * User keeps their type (CUSTOMER/SHOP) but gains worker profile.
     *
     * @throws \InvalidArgumentException
     */
    public function registerExistingUserAsWorker(User $user, array $data): JobWorker
    {
        if (!$user->registered_at) {
            throw new \InvalidArgumentException('User must be registered first.');
        }

        if ($user->jobWorker) {
            throw new \InvalidArgumentException('User is already a worker.');
        }

        $this->validateWorkerData($data);

        return DB::transaction(function () use ($user, $data) {
            $worker = JobWorker::create([
                'user_id' => $user->id,
                'name' => trim($data['name'] ?? $user->name),
                'photo_url' => $data['photo_url'] ?? null,
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'address' => $data['address'] ?? null,
                'vehicle_type' => $this->normalizeVehicleType($data['vehicle_type'] ?? 'none'),
                'job_types' => $this->ensureArray($data['job_types'] ?? []),
                'availability' => $this->ensureArray($data['availability'] ?? ['flexible']),
                // NP-005: Initialize with defaults
                'rating' => 0,
                'rating_count' => 0,
                'jobs_completed' => 0,
                'total_earnings' => 0,
                'is_available' => true,
                'is_verified' => false,
                'last_active_at' => now(),
            ]);

            Log::info('Existing user registered as worker', [
                'worker_id' => $worker->id,
                'user_id' => $user->id,
                'user_type' => $user->type->value,
            ]);

            return $worker;
        });
    }

    /**
     * Create new user and worker together.
     *
     * For unregistered users who want to become workers.
     *
     * @throws \InvalidArgumentException
     */
    public function createUserAndWorker(array $data): User
    {
        if (empty($data['phone'])) {
            throw new \InvalidArgumentException('Phone number is required.');
        }

        $this->validateWorkerData($data);

        return DB::transaction(function () use ($data) {
            // Create user
            $user = User::create([
                'phone' => $data['phone'],
                'name' => trim($data['name']),
                'type' => UserType::CUSTOMER,
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'address' => $data['address'] ?? null,
                'registered_at' => now(),
            ]);

            // Create worker profile (NP-005: defaults)
            $worker = JobWorker::create([
                'user_id' => $user->id,
                'name' => trim($data['name']),
                'photo_url' => $data['photo_url'] ?? null,
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'address' => $data['address'] ?? null,
                'vehicle_type' => $this->normalizeVehicleType($data['vehicle_type'] ?? 'none'),
                'job_types' => $this->ensureArray($data['job_types'] ?? []),
                'availability' => $this->ensureArray($data['availability'] ?? ['flexible']),
                'rating' => 0,
                'rating_count' => 0,
                'jobs_completed' => 0,
                'total_earnings' => 0,
                'is_available' => true,
                'is_verified' => false,
                'last_active_at' => now(),
            ]);

            Log::info('New user and worker created', [
                'user_id' => $user->id,
                'worker_id' => $worker->id,
            ]);

            return $user->load('jobWorker');
        });
    }

    /**
     * Update worker profile.
     */
    public function updateWorker(JobWorker $worker, array $data): JobWorker
    {
        $update = [];

        if (isset($data['name']) && $this->isValidName($data['name'])) {
            $update['name'] = trim($data['name']);
        }

        if (array_key_exists('photo_url', $data)) {
            $update['photo_url'] = $data['photo_url'];
        }

        if (isset($data['latitude'], $data['longitude'])) {
            if ($this->isValidCoordinates($data['latitude'], $data['longitude'])) {
                $update['latitude'] = (float) $data['latitude'];
                $update['longitude'] = (float) $data['longitude'];
                $update['address'] = $data['address'] ?? null;
            }
        }

        if (isset($data['vehicle_type'])) {
            $update['vehicle_type'] = $this->normalizeVehicleType($data['vehicle_type']);
        }

        if (isset($data['job_types'])) {
            $update['job_types'] = $this->ensureArray($data['job_types']);
        }

        if (isset($data['availability'])) {
            $update['availability'] = $this->ensureArray($data['availability']);
        }

        if (isset($data['is_available'])) {
            $update['is_available'] = (bool) $data['is_available'];
        }

        if (!empty($update)) {
            $worker->update($update);
            Log::info('Worker updated', ['worker_id' => $worker->id, 'fields' => array_keys($update)]);
        }

        return $worker->fresh();
    }

    /**
     * Get worker by user.
     */
    public function getWorkerByUser(User $user): ?JobWorker
    {
        return $user->jobWorker;
    }

    /**
     * Check if user is a worker.
     */
    public function isUserWorker(User $user): bool
    {
        return $user->jobWorker !== null;
    }

    /**
     * Toggle worker availability.
     */
    public function toggleAvailability(JobWorker $worker): bool
    {
        return $worker->toggleAvailability();
    }

    /**
     * Set worker available.
     */
    public function setAvailable(JobWorker $worker): void
    {
        $worker->update(['is_available' => true]);
    }

    /**
     * Set worker unavailable.
     */
    public function setUnavailable(JobWorker $worker): void
    {
        $worker->update(['is_available' => false]);
    }

    /**
     * Verify worker (admin action).
     */
    public function verifyWorker(JobWorker $worker): void
    {
        $worker->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);
        Log::info('Worker verified', ['worker_id' => $worker->id]);
    }

    /**
     * Update last active.
     */
    public function updateLastActive(JobWorker $worker): void
    {
        $worker->touchLastActive();
    }

    /**
     * Link session to user.
     */
    public function linkSessionToUser(ConversationSession $session, User $user): void
    {
        $session->update(['user_id' => $user->id]);
    }

    /**
     * Find workers near location.
     */
    public function findNearby(float $lat, float $lng, float $radiusKm = 5, array $filters = []): Collection
    {
        $query = JobWorker::available()
            ->nearLocation($lat, $lng, $radiusKm)
            ->withDistanceFrom($lat, $lng);

        if (!empty($filters['job_type_id'])) {
            $query->canDoJob($filters['job_type_id']);
        }

        if (!empty($filters['vehicle_type'])) {
            $query->withVehicle($filters['vehicle_type']);
        }

        if (!empty($filters['availability'])) {
            $query->availableAt($filters['availability']);
        }

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
     * Get worker stats.
     */
    public function getStats(JobWorker $worker): array
    {
        return [
            'total_jobs' => $worker->jobs_completed,
            'total_earnings' => $worker->total_earnings,
            'rating' => $worker->rating,
            'rating_count' => $worker->rating_count,
            'is_available' => $worker->is_available,
            'is_verified' => $worker->is_verified,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate worker data.
     *
     * @throws \InvalidArgumentException
     */
    protected function validateWorkerData(array $data): void
    {
        if (isset($data['name']) && !$this->isValidName($data['name'])) {
            throw new \InvalidArgumentException('Invalid name (2-100 characters required).');
        }

        if (!isset($data['latitude']) || !isset($data['longitude'])) {
            throw new \InvalidArgumentException('Location is required.');
        }

        if (!$this->isValidCoordinates($data['latitude'], $data['longitude'])) {
            throw new \InvalidArgumentException('Invalid location coordinates.');
        }
    }

    /**
     * Validate name.
     */
    public function isValidName(string $name): bool
    {
        $len = mb_strlen(trim($name));
        return $len >= 2 && $len <= 100;
    }

    /**
     * Validate coordinates (India bounds).
     */
    public function isValidCoordinates(float $lat, float $lng): bool
    {
        // India: Lat 6-36, Lng 68-98
        return $lat >= 6.0 && $lat <= 36.0 && $lng >= 68.0 && $lng <= 98.0;
    }

    /**
     * Normalize vehicle type (NP-003).
     */
    protected function normalizeVehicleType(string $type): string
    {
        return match ($type) {
            'two_wheeler', 'bike', 'scooter' => 'two_wheeler',
            'four_wheeler', 'car', 'auto' => 'four_wheeler',
            default => 'none',
        };
    }

    /**
     * Ensure value is array.
     */
    protected function ensureArray(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}