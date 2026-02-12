<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkerAvailability;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Job Worker Model.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $photo_url
 * @property float $latitude
 * @property float $longitude
 * @property string|null $address
 * @property string $vehicle_type - none, two_wheeler, four_wheeler (NP-003)
 * @property array $job_types - JSON array of job type IDs (NP-002)
 * @property array $availability - JSON array: morning, afternoon, evening, flexible (NP-004)
 * @property float $rating - Default 0 (NP-005)
 * @property int $rating_count
 * @property int $jobs_completed - Default 0 (NP-005)
 * @property float $total_earnings
 * @property bool $is_available - Default true (NP-005)
 * @property bool $is_verified
 * @property \Carbon\Carbon|null $verified_at
 * @property \Carbon\Carbon|null $last_active_at
 *
 * @srs-ref Section 5.2.1 job_workers table
 * @srs-ref NP-001 to NP-005: Worker Registration
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobWorker extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'job_workers';

    protected $fillable = [
        'user_id',
        'name',
        'photo_url',
        'latitude',
        'longitude',
        'address',
        'vehicle_type',
        'job_types',
        'availability',
        'rating',
        'rating_count',
        'jobs_completed',
        'total_earnings',
        'is_available',
        'is_verified',
        'verified_at',
        'last_active_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'job_types' => 'array',
        'availability' => 'array',
        'rating' => 'decimal:2',
        'total_earnings' => 'decimal:2',
        'is_available' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'last_active_at' => 'datetime',
    ];

    /**
     * NP-005: Default values - 0 rating, 0 jobs, available.
     */
    protected $attributes = [
        'rating' => 0,
        'rating_count' => 0,
        'jobs_completed' => 0,
        'total_earnings' => 0,
        'is_available' => true,
        'is_verified' => false,
    ];

    /**
     * Vehicle type constants (NP-003).
     */
    public const VEHICLE_NONE = 'none';
    public const VEHICLE_TWO_WHEELER = 'two_wheeler';
    public const VEHICLE_FOUR_WHEELER = 'four_wheeler';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'worker_id');
    }

    public function assignedJobs(): HasMany
    {
        return $this->hasMany(JobPost::class, 'assigned_worker_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Available workers only.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /**
     * Verified workers only.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }

    /**
     * With specific vehicle type (NP-003).
     */
    public function scopeWithVehicle(Builder $query, ?string $type = null): Builder
    {
        if ($type) {
            return $query->where('vehicle_type', $type);
        }
        return $query->whereIn('vehicle_type', [self::VEHICLE_TWO_WHEELER, self::VEHICLE_FOUR_WHEELER]);
    }

    /**
     * Can do specific job type (NP-002).
     */
    public function scopeCanDoJob(Builder $query, string $jobTypeId): Builder
    {
        return $query->where(function ($q) use ($jobTypeId) {
            $q->whereJsonContains('job_types', $jobTypeId)
                ->orWhereJsonContains('job_types', 'all');
        });
    }

    /**
     * Available at time slot (NP-004).
     */
    public function scopeAvailableAt(Builder $query, string $slot): Builder
    {
        return $query->where(function ($q) use ($slot) {
            $q->whereJsonContains('availability', $slot)
                ->orWhereJsonContains('availability', 'flexible');
        });
    }

    /**
     * Minimum rating filter.
     */
    public function scopeMinRating(Builder $query, float $rating): Builder
    {
        return $query->where('rating', '>=', $rating);
    }

    /**
     * Near location (Haversine).
     */
    public function scopeNearLocation(Builder $query, float $lat, float $lng, float $radiusKm = 5): Builder
    {
        return $query->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereRaw(
                "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?",
                [$lat, $lng, $lat, $radiusKm]
            );
    }

    /**
     * Add distance from location.
     */
    public function scopeWithDistanceFrom(Builder $query, float $lat, float $lng): Builder
    {
        return $query->selectRaw(
            '*, (6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) as distance_km',
            [$lat, $lng, $lat]
        );
    }

    /**
     * Recently active.
     */
    public function scopeRecentlyActive(Builder $query, int $hours = 24): Builder
    {
        return $query->where('last_active_at', '>=', now()->subHours($hours));
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Vehicle display (NP-003).
     */
    public function getVehicleDisplayAttribute(): string
    {
        return match ($this->vehicle_type) {
            self::VEHICLE_NONE => '🚶 No/Walking',
            self::VEHICLE_TWO_WHEELER => '🏍️ Two Wheeler',
            self::VEHICLE_FOUR_WHEELER => '🚗 Four Wheeler',
            default => '🚶 Walking',
        };
    }

    /**
     * Availability display (NP-004).
     */
    public function getAvailabilityDisplayAttribute(): string
    {
        if (empty($this->availability)) {
            return '🔄 Flexible';
        }

        if (in_array('flexible', $this->availability)) {
            return '🔄 Flexible';
        }

        return collect($this->availability)
            ->map(fn($slot) => WorkerAvailability::tryFrom($slot)?->buttonTitle() ?? $slot)
            ->join(', ');
    }

    /**
     * Rating display (NP-005: starts at 0).
     */
    public function getRatingDisplayAttribute(): string
    {
        if ($this->rating_count === 0) {
            return '⭐ New';
        }
        return '⭐ ' . number_format($this->rating, 1) . ' (' . $this->rating_count . ')';
    }

    /**
     * Short rating for lists.
     */
    public function getShortRatingAttribute(): string
    {
        if ($this->rating_count === 0) {
            return '⭐ New';
        }
        return '⭐ ' . number_format($this->rating, 1);
    }

    /**
     * Jobs display.
     */
    public function getJobsDisplayAttribute(): string
    {
        if ($this->jobs_completed === 0) {
            return '0 jobs';
        }
        return $this->jobs_completed . ' jobs';
    }

    /**
     * Earnings display.
     */
    public function getEarningsDisplayAttribute(): string
    {
        return '₹' . number_format($this->total_earnings, 0);
    }

    /**
     * Status display.
     */
    public function getStatusDisplayAttribute(): string
    {
        if (!$this->is_available) {
            return '🔴 Unavailable';
        }
        if ($this->is_verified) {
            return '✅ Verified';
        }
        return '🟢 Available';
    }

    /**
     * Has location.
     */
    public function getHasLocationAttribute(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /**
     * Job types display.
     */
    public function getJobTypesDisplayAttribute(): string
    {
        if (empty($this->job_types)) {
            return 'All jobs';
        }
        if (in_array('all', $this->job_types)) {
            return 'All jobs ✅';
        }
        return count($this->job_types) . ' job types';
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Can do job type? (NP-002)
     */
    public function canDoJobType(string $typeId): bool
    {
        if (empty($this->job_types) || in_array('all', $this->job_types)) {
            return true;
        }
        return in_array($typeId, $this->job_types);
    }

    /**
     * Is available at time slot? (NP-004)
     */
    public function isAvailableAt(string $slot): bool
    {
        if (empty($this->availability) || in_array('flexible', $this->availability)) {
            return true;
        }
        return in_array($slot, $this->availability);
    }

    /**
     * Update last active.
     */
    public function touchLastActive(): void
    {
        $this->update(['last_active_at' => now()]);
    }

    /**
     * Toggle availability.
     */
    public function toggleAvailability(): bool
    {
        $this->is_available = !$this->is_available;
        $this->save();
        return $this->is_available;
    }

    /**
     * Add rating (NP-005: starts from 0).
     */
    public function addRating(float $newRating): void
    {
        $total = ($this->rating * $this->rating_count) + $newRating;
        $this->rating_count++;
        $this->rating = round($total / $this->rating_count, 2);
        $this->save();
    }

    /**
     * Record completed job.
     */
    public function recordCompletedJob(float $earnings): void
    {
        $this->jobs_completed++;
        $this->total_earnings += $earnings;
        $this->save();
    }

    /**
     * Calculate distance from location.
     */
    public function distanceFrom(float $lat, float $lng): ?float
    {
        if (!$this->has_location) {
            return null;
        }

        $earthRadius = 6371;
        $dLat = deg2rad($lat - $this->latitude);
        $dLng = deg2rad($lng - $this->longitude);

        $a = sin($dLat / 2) ** 2 +
            cos(deg2rad($this->latitude)) * cos(deg2rad($lat)) *
            sin($dLng / 2) ** 2;

        return round($earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a)), 2);
    }

    /**
     * Get profile summary.
     */
    public function getProfileSummary(): string
    {
        return "👷 *{$this->name}*\n" .
            "{$this->rating_display} | {$this->jobs_display}\n" .
            "{$this->vehicle_display}\n" .
            "{$this->status_display}";
    }
}