<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Job Worker model for the Njaanum Panikkar (Jobs Marketplace) module.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $name
 * @property string|null $photo_url
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $address
 * @property string|null $vehicle_type (none, two_wheeler, four_wheeler)
 * @property array|null $job_types Array of category IDs worker can do
 * @property array|null $availability Array: morning, afternoon, evening, flexible
 * @property float $rating Average rating out of 5
 * @property int $rating_count
 * @property int $jobs_completed
 * @property float $total_earnings Lifetime earnings in INR
 * @property bool $is_available Currently accepting jobs
 * @property bool $is_verified
 * @property string|null $verification_photo_url ID verification photo
 * @property \Carbon\Carbon|null $verified_at
 * @property \Carbon\Carbon|null $last_active_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $deleted_at
 *
 * @property-read User $user
 * @property-read \Illuminate\Database\Eloquent\Collection|JobApplication[] $applications
 * @property-read \Illuminate\Database\Eloquent\Collection|JobPost[] $assignedJobs
 *
 * @srs-ref Section 3.4 - Worker Registration
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobWorker extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'job_workers';

    /**
     * The attributes that are mass assignable.
     */
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
        'verification_photo_url',
        'verified_at',
        'last_active_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'job_types' => 'array',
        'availability' => 'array',
        'rating' => 'decimal:2',
        'rating_count' => 'integer',
        'jobs_completed' => 'integer',
        'total_earnings' => 'decimal:2',
        'is_available' => 'boolean',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'last_active_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'rating' => 0,
        'rating_count' => 0,
        'jobs_completed' => 0,
        'total_earnings' => 0,
        'is_available' => true,
        'is_verified' => false,
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the user that owns this worker profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all job applications by this worker.
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'worker_id');
    }

    /**
     * Get all jobs assigned to this worker.
     */
    public function assignedJobs(): HasMany
    {
        return $this->hasMany(JobPost::class, 'assigned_worker_id');
    }

    public function verifications(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(JobVerification::class, 'worker_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Check if worker can accept jobs in this category.
     */
    public function canAcceptJob(JobCategory $category): bool
    {
        // If worker has no job types set, allow all
        if (empty($this->job_types) || !is_array($this->job_types)) {
            return true;
        }
        
        // Check if category ID is in worker's registered job types
        return in_array($category->id, $this->job_types);
    }

    /**
     * Get vehicle display text.
     */
    public function getVehicleDisplayAttribute(): string
    {
        return match($this->vehicle_type) {
            'none' => '🚶 Walking Only',
            'two_wheeler' => '🛵 Two Wheeler',
            'four_wheeler' => '🚗 Four Wheeler',
            default => 'Not specified',
        };
    }

    /**
     * Get availability display text.
     */
    public function getAvailabilityDisplayAttribute(): string
    {
        if (empty($this->availability)) {
            return 'Flexible';
        }

        $labels = [
            'morning' => '🌅 Morning',
            'afternoon' => '☀️ Afternoon',
            'evening' => '🌆 Evening',
            'flexible' => '🔄 Flexible',
        ];

        return collect($this->availability)
            ->map(fn($slot) => $labels[$slot] ?? $slot)
            ->implode(', ');
    }

    /**
     * Get formatted rating.
     */
    public function getFormattedRatingAttribute(): string
    {
        if ($this->rating_count === 0) {
            return 'No ratings yet';
        }

        return "⭐ {$this->rating}/5 ({$this->rating_count} reviews)";
    }

    /**
     * Update the last active timestamp.
     */
    public function touchLastActive(): void
    {
        $this->update(['last_active_at' => now()]);
    }

    /**
     * Get formatted earnings.
     */
    public function getFormattedEarningsAttribute(): string
    {
        return '₹' . number_format($this->total_earnings, 0);
    }

    /**
     * Check if worker has location set.
     */
    public function getHasLocationAttribute(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope for available workers.
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_available', true);
    }

    /**
     * Scope for verified workers.
     */
    public function scopeVerified($query)
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope for workers with specific vehicle type.
     */
    public function scopeWithVehicle($query, string $vehicleType)
    {
        return $query->where('vehicle_type', $vehicleType);
    }

    /**
     * Scope for workers who can do specific job type.
     */
    public function scopeCanDoJobType($query, int $categoryId)
    {
        return $query->whereJsonContains('job_types', $categoryId);
    }

    /**
     * Scope for nearby workers.
     */
    public function scopeNearby($query, float $latitude, float $longitude, int $radiusKm = 10)
    {
        $haversine = "(6371 * acos(cos(radians(?)) 
                     * cos(radians(latitude)) 
                     * cos(radians(longitude) - radians(?)) 
                     + sin(radians(?)) 
                     * sin(radians(latitude))))";

        return $query->select('*')
            ->selectRaw("{$haversine} AS distance", [$latitude, $longitude, $latitude])
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->having('distance', '<', $radiusKm)
            ->orderBy('distance');
    }

    /**
     * Scope for recently active workers.
     */
    public function scopeRecentlyActive($query, int $hours = 24)
    {
        return $query->where('last_active_at', '>=', now()->subHours($hours));
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    /**
     * Update worker's last active timestamp.
     */
    public function updateLastActive(): void
    {
        $this->update(['last_active_at' => now()]);
    }

    /**
     * Toggle availability status.
     */
    public function toggleAvailability(): bool
    {
        $this->is_available = !$this->is_available;
        $this->save();

        return $this->is_available;
    }

    /**
     * Add a rating.
     */
    public function addRating(float $newRating): void
    {
        $totalRating = ($this->rating * $this->rating_count) + $newRating;
        $this->rating_count += 1;
        $this->rating = round($totalRating / $this->rating_count, 2);
        $this->save();
    }

    /**
     * Record a completed job.
     */
    public function recordCompletedJob(float $earnings): void
    {
        $this->jobs_completed += 1;
        $this->total_earnings += $earnings;
        $this->save();
    }

    /**
     * Get the distance from a location in kilometers.
     */
    public function distanceFrom(float $latitude, float $longitude): ?float
    {
        if (!$this->has_location) {
            return null;
        }

        $earthRadius = 6371; // km

        $latDelta = deg2rad($latitude - $this->latitude);
        $lonDelta = deg2rad($longitude - $this->longitude);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }
}