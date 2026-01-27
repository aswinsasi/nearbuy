<?php

namespace App\Models;

use App\Enums\VehicleType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Job Worker Model - Worker profiles for job marketplace.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string|null $photo_url
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $address
 * @property VehicleType $vehicle_type
 * @property array|null $job_types
 * @property array|null $availability
 * @property float $rating
 * @property int $rating_count
 * @property int $jobs_completed
 * @property float $total_earnings
 * @property bool $is_available
 * @property bool $is_verified
 * @property string|null $verification_photo_url
 * @property \Carbon\Carbon|null $verified_at
 * @property \Carbon\Carbon|null $last_active_at
 *
 * @srs-ref Section 3.2 - Job Workers
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobWorker extends Model
{
    use HasFactory, SoftDeletes;

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
        'vehicle_type' => VehicleType::class,
        'job_types' => 'array',
        'availability' => 'array',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'rating' => 'decimal:1',
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
        'rating' => 0.0,
        'rating_count' => 0,
        'jobs_completed' => 0,
        'total_earnings' => 0.00,
        'is_available' => true,
        'is_verified' => false,
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the user who owns this worker profile.
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
     * Get pending applications.
     */
    public function pendingApplications(): HasMany
    {
        return $this->applications()->where('status', 'pending');
    }

    /**
     * Get accepted applications.
     */
    public function acceptedApplications(): HasMany
    {
        return $this->applications()->where('status', 'accepted');
    }

    /**
     * Get jobs assigned to this worker.
     */
    public function assignedJobs(): HasMany
    {
        return $this->hasMany(JobPost::class, 'assigned_worker_id');
    }

    /**
     * Get active assigned jobs.
     */
    public function activeJobs(): HasMany
    {
        return $this->assignedJobs()->whereIn('status', ['assigned', 'in_progress']);
    }

    /**
     * Get completed jobs.
     */
    public function completedJobs(): HasMany
    {
        return $this->assignedJobs()->where('status', 'completed');
    }

    /**
     * Get job verifications for this worker.
     */
    public function verifications(): HasMany
    {
        return $this->hasMany(JobVerification::class, 'worker_id');
    }

    /**
     * Get badges earned by this worker.
     */
    public function badges(): HasMany
    {
        return $this->hasMany(WorkerBadge::class, 'worker_id');
    }

    /**
     * Get earnings records.
     */
    public function earnings(): HasMany
    {
        return $this->hasMany(WorkerEarning::class, 'worker_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter available workers.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /**
     * Scope to filter verified workers.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope to filter workers with vehicle.
     */
    public function scopeWithVehicle(Builder $query): Builder
    {
        return $query->where('vehicle_type', '!=', VehicleType::NONE);
    }

    /**
     * Scope to filter workers with specific vehicle type.
     */
    public function scopeWithVehicleType(Builder $query, VehicleType $type): Builder
    {
        return $query->where('vehicle_type', $type);
    }

    /**
     * Scope to filter workers who can do a specific job category.
     */
    public function scopeCanDoJob(Builder $query, int $categoryId): Builder
    {
        return $query->whereJsonContains('job_types', $categoryId);
    }

    /**
     * Scope to filter workers available at a specific time.
     */
    public function scopeAvailableAt(Builder $query, string $availability): Builder
    {
        return $query->where(function ($q) use ($availability) {
            $q->whereJsonContains('availability', $availability)
                ->orWhereJsonContains('availability', 'flexible');
        });
    }

    /**
     * Scope to find workers near a location.
     */
    public function scopeNearLocation(Builder $query, float $latitude, float $longitude, float $radiusKm = 5): Builder
    {
        return $query
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereRaw(
                "ST_Distance_Sphere(
                    POINT(longitude, latitude),
                    POINT(?, ?)
                ) <= ?",
                [$longitude, $latitude, $radiusKm * 1000]
            );
    }

    /**
     * Scope to select with distance from a point.
     */
    public function scopeWithDistanceFrom(Builder $query, float $latitude, float $longitude): Builder
    {
        return $query->selectRaw(
            "*, ST_Distance_Sphere(
                POINT(longitude, latitude),
                POINT(?, ?)
            ) / 1000 as distance_km",
            [$longitude, $latitude]
        );
    }

    /**
     * Scope to filter by minimum rating.
     */
    public function scopeMinRating(Builder $query, float $minRating): Builder
    {
        return $query->where('rating', '>=', $minRating);
    }

    /**
     * Scope to order by rating.
     */
    public function scopeTopRated(Builder $query): Builder
    {
        return $query->orderBy('rating', 'desc')
            ->orderBy('jobs_completed', 'desc');
    }

    /**
     * Scope to filter recently active workers.
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
     * Get display name.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name;
    }

    /**
     * Get formatted phone.
     */
    public function getFormattedPhoneAttribute(): string
    {
        return $this->user?->formatted_phone ?? '';
    }

    /**
     * Get rating display with stars.
     */
    public function getRatingDisplayAttribute(): string
    {
        if ($this->rating_count === 0) {
            return 'New Worker';
        }

        $stars = str_repeat('⭐', (int) round($this->rating));
        return $stars . ' ' . number_format($this->rating, 1) .
            ' (' . $this->rating_count . ' ratings)';
    }

    /**
     * Get short rating display.
     */
    public function getShortRatingAttribute(): string
    {
        if ($this->rating_count === 0) {
            return 'New';
        }

        return '⭐ ' . number_format($this->rating, 1) .
            ' (' . $this->rating_count . ')';
    }

    /**
     * Get vehicle display.
     */
    public function getVehicleDisplayAttribute(): string
    {
        return $this->vehicle_type->display();
    }

    /**
     * Get location display.
     */
    public function getLocationDisplayAttribute(): string
    {
        return $this->address ?? 'Location available';
    }

    /**
     * Get earnings display.
     */
    public function getEarningsDisplayAttribute(): string
    {
        return '₹' . number_format($this->total_earnings);
    }

    /**
     * Check if worker has a vehicle.
     */
    public function getHasVehicleAttribute(): bool
    {
        return $this->vehicle_type !== VehicleType::NONE;
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if worker can accept a job.
     */
    public function canAcceptJob(JobCategory $category): bool
    {
        // Check if available
        if (!$this->is_available) {
            return false;
        }

        // Check if worker does this job type
        if (!in_array($category->id, $this->job_types ?? [])) {
            return false;
        }

        // Check vehicle requirement
        if ($category->requires_vehicle && !$this->has_vehicle) {
            return false;
        }

        return true;
    }

    /**
     * Get distance from a location in km.
     */
    public function getDistanceFrom(float $latitude, float $longitude): ?float
    {
        if (!$this->latitude || !$this->longitude) {
            return null;
        }

        // Haversine formula
        $earthRadius = 6371; // km

        $latDiff = deg2rad($latitude - $this->latitude);
        $lngDiff = deg2rad($longitude - $this->longitude);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    /**
     * Update rating based on new rating.
     */
    public function updateRating(int $newRating): void
    {
        $totalRating = ($this->rating * $this->rating_count) + $newRating;
        $newCount = $this->rating_count + 1;

        $this->update([
            'rating' => round($totalRating / $newCount, 1),
            'rating_count' => $newCount,
        ]);
    }

    /**
     * Recalculate rating from completed jobs.
     */
    public function recalculateRating(): void
    {
        $ratings = $this->verifications()
            ->whereNotNull('rating')
            ->pluck('rating');

        if ($ratings->isEmpty()) {
            return;
        }

        $this->update([
            'rating' => round($ratings->avg(), 1),
            'rating_count' => $ratings->count(),
        ]);
    }

    /**
     * Increment jobs completed and add earnings.
     */
    public function incrementJobsCompleted(float $amount): void
    {
        $this->increment('jobs_completed');
        $this->addEarnings($amount);
    }

    /**
     * Add earnings.
     */
    public function addEarnings(float $amount): void
    {
        $this->increment('total_earnings', $amount);
    }

    /**
     * Mark as verified.
     */
    public function verify(): void
    {
        $this->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }

    /**
     * Update last active timestamp.
     */
    public function touchLastActive(): void
    {
        $this->update(['last_active_at' => now()]);
    }

    /**
     * Toggle availability.
     */
    public function toggleAvailability(): void
    {
        $this->update(['is_available' => !$this->is_available]);
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        $description = $this->short_rating;
        if ($this->jobs_completed > 0) {
            $description .= ' • ' . $this->jobs_completed . ' jobs done';
        }

        return [
            'id' => 'worker_' . $this->id,
            'title' => substr($this->name, 0, 24),
            'description' => substr($description, 0, 72),
        ];
    }

    /**
     * Convert to summary format.
     */
    public function toSummary(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'photo_url' => $this->photo_url,
            'rating' => $this->short_rating,
            'jobs_completed' => $this->jobs_completed,
            'vehicle' => $this->vehicle_display,
            'is_verified' => $this->is_verified,
        ];
    }
}