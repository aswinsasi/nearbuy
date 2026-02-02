<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Job Post model for the Njaanum Panikkar (Jobs Marketplace) module.
 *
 * @property int $id
 * @property string $job_number
 * @property int $poster_user_id
 * @property int $job_category_id
 * @property string|null $custom_category_text Custom job type when "Other" category is selected
 * @property string $title
 * @property string|null $description
 * @property string|null $special_instructions
 * @property string|null $location_name
 * @property float|null $latitude
 * @property float|null $longitude
 * @property \Carbon\Carbon $job_date
 * @property string|null $job_time
 * @property float|null $duration_hours
 * @property float $pay_amount
 * @property string $status
 * @property int|null $assigned_worker_id
 * @property int $applications_count
 * @property \Carbon\Carbon|null $posted_at
 * @property \Carbon\Carbon|null $assigned_at
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property \Carbon\Carbon|null $expires_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read User $poster
 * @property-read JobCategory|null $category
 * @property-read JobWorker|null $assignedWorker
 * @property-read \Illuminate\Database\Eloquent\Collection|JobApplication[] $applications
 *
 * @srs-ref Section 3.3 - Job Posting
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobPost extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'job_posts';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'job_number',
        'poster_user_id',
        'job_category_id',
        'custom_category_text',
        'title',
        'description',
        'special_instructions',
        'location_name',
        'latitude',
        'longitude',
        'job_date',
        'job_time',
        'duration_hours',
        'pay_amount',
        'status',
        'assigned_worker_id',
        'applications_count',
        'posted_at',
        'assigned_at',
        'started_at',
        'completed_at',
        'cancelled_at',
        'cancellation_reason',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'job_date' => 'date',
        'pay_amount' => 'decimal:2',
        'duration_hours' => 'decimal:1',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'applications_count' => 'integer',
        'posted_at' => 'datetime',
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'expires_at' => 'datetime',
        'status' => JobStatus::class,
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (JobPost $job) {
            if (empty($job->job_number)) {
                $job->job_number = self::generateJobNumber();
            }
            if (empty($job->status)) {
                $job->status = 'open';
            }
            if (empty($job->expires_at)) {
                $job->expires_at = now()->addDays(7);
            }
            if (empty($job->posted_at) && $job->status === 'open') {
                $job->posted_at = now();
            }
        });
    }

    /**
     * Generate a unique job number.
     */
    public static function generateJobNumber(): string
    {
        $prefix = 'JP';
        $date = now()->format('Ymd');
        $random = strtoupper(substr(uniqid(), -4));
        
        return "{$prefix}-{$date}-{$random}";
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the user who posted this job.
     */
    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'poster_user_id');
    }

    /**
     * Get the job category.
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(JobCategory::class, 'job_category_id');
    }

    /**
     * Get the assigned worker.
     */
    public function assignedWorker(): BelongsTo
    {
        return $this->belongsTo(JobWorker::class, 'assigned_worker_id');
    }

    /**
     * Get all applications for this job.
     */
    public function applications(): HasMany
    {
        return $this->hasMany(JobApplication::class, 'job_post_id');
    }

    /**
     * Get the accepted application.
     */
    public function acceptedApplication(): HasOne
    {
        return $this->hasOne(JobApplication::class, 'job_post_id')
            ->where('status', 'accepted');
    }

    /**
     * Get the job verification record.
     */
    public function verification(): HasOne
    {
        return $this->hasOne(\App\Models\JobVerification::class, 'job_post_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Mutators
    |--------------------------------------------------------------------------
    */

    /**
     * Get the display name for the job category.
     * Uses custom_category_text if set, otherwise falls back to category name.
     */
    public function getCategoryDisplayNameAttribute(): string
    {
        if (!empty($this->custom_category_text)) {
            return $this->custom_category_text;
        }

        return $this->category?->name ?? 'Unknown';
    }

    /**
     * Check if this job uses a custom category.
     */
    public function getIsCustomCategoryAttribute(): bool
    {
        return !empty($this->custom_category_text);
    }

    /**
     * Get formatted pay amount.
     */
    public function getFormattedPayAttribute(): string
    {
        return '₹' . number_format($this->pay_amount, 0);
    }

    /**
     * Get formatted job date.
     */
    public function getFormattedDateAttribute(): string
    {
        return $this->job_date?->format('d M Y') ?? 'TBD';
    }
    
    /**
     * Get formatted duration.
     */
    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration_hours) {
            return 'Not specified';
        }
        
        if ($this->duration_hours < 1) {
            return (int)($this->duration_hours * 60) . ' minutes';
        }
        
        if ($this->duration_hours == 1) {
            return '1 hour';
        }
        
        return $this->duration_hours . ' hours';
    }

    /**
     * Check if job is open for applications.
     */
    public function getIsOpenAttribute(): bool
    {
        return $this->status === 'open' && 
            ($this->expires_at === null || $this->expires_at > now());
    }

    /**
     * Check if job is active (open or assigned).
     */
    public function getIsActiveAttribute(): bool
    {
        return in_array($this->status, ['open', 'assigned', 'in_progress']);
    }

    /**
     * Check if job is expired.
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->status === 'expired' || 
            ($this->status === 'open' && $this->expires_at && $this->expires_at <= now());
    }

    /**
     * Get pending applications count.
     */
    public function getPendingApplicationsCountAttribute(): int
    {
        return $this->applications()->where('status', 'pending')->count();
    }

    /**
     * Check if job accepts applications.
     * 
     * Job accepts applications when:
     * - Status is 'open'
     * - Job date is today or in the future
     * - Not expired (expires_at is null or in the future)
     */
    public function getAcceptsApplicationsAttribute(): bool
    {
        // Must be open status
       if ($this->status !== JobStatus::OPEN) {
            return false;
        }

        // Job date must be today or future
        if ($this->job_date && $this->job_date->lt(now()->startOfDay())) {
            return false;
        }

        // Check expires_at if set
        if ($this->expires_at && $this->expires_at->lte(now())) {
            return false;
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope for open jobs.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope for active jobs.
     */
    public function scopeActive($query)
    {
        return $query->whereIn('status', ['open', 'assigned', 'in_progress']);
    }

    /**
     * Scope for jobs by poster.
     */
    public function scopeByPoster($query, int $posterUserId)
    {
        return $query->where('poster_user_id', $posterUserId);
    }

    /**
     * Scope for jobs by category.
     */
    public function scopeByCategory($query, int $categoryId)
    {
        return $query->where('job_category_id', $categoryId);
    }

    /**
     * Scope for jobs with custom category.
     */
    public function scopeWithCustomCategory($query)
    {
        return $query->whereNotNull('custom_category_text');
    }

    /**
     * Scope for nearby jobs.
     */
    public function scopeNearby($query, float $latitude, float $longitude, int $radiusKm = 10)
    {
        // Haversine formula for distance calculation
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
     * Scope for jobs expiring soon.
     */
    public function scopeExpiringSoon($query, int $hours = 24)
    {
        return $query->where('status', 'open')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addHours($hours))
            ->where('expires_at', '>', now());
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    /**
     * Assign a worker to this job.
     */
    public function assignWorker(JobWorker $worker): bool
    {
        if ($this->status !== 'open') {
            return false;
        }

        $this->update([
            'status' => 'assigned',
            'assigned_worker_id' => $worker->id,
            'assigned_at' => now(),
        ]);

        return true;
    }

    /**
     * Start the job execution.
     */
    public function start(): bool
    {
        if ($this->status !== 'assigned') {
            return false;
        }

        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);

        return true;
    }

    /**
     * Mark the job as completed.
     */
    public function complete(): bool
    {
        if ($this->status !== 'in_progress') {
            return false;
        }

        $this->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        return true;
    }

    /**
     * Cancel the job.
     */
    public function cancel(string $reason = null): bool
    {
        if (in_array($this->status, ['completed', 'cancelled'])) {
            return false;
        }

        $this->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return true;
    }

    /**
     * Mark the job as expired.
     */
    public function markExpired(): bool
    {
        if ($this->status !== 'open') {
            return false;
        }

        $this->update([
            'status' => 'expired',
        ]);

        return true;
    }

    /**
     * Repost the job (create a copy with new dates).
     */
    public function repost(): JobPost
    {
        return self::create([
            'poster_user_id' => $this->poster_user_id,
            'job_category_id' => $this->job_category_id,
            'custom_category_text' => $this->custom_category_text,
            'title' => $this->title,
            'description' => $this->description,
            'special_instructions' => $this->special_instructions,
            'location_name' => $this->location_name,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'job_date' => now()->addDay(),
            'job_time' => $this->job_time,
            'duration_hours' => $this->duration_hours,
            'pay_amount' => $this->pay_amount,
            'status' => 'open',
            'posted_at' => now(),
            'expires_at' => now()->addDays(7),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

   /**
     * Get the distance from a location in kilometers.
     */
    public function distanceFrom(float|string $latitude, float|string $longitude): ?float
    {
        if (!$this->latitude || !$this->longitude) {
            return null;
        }

        // Cast to float to handle string values from database
        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        $earthRadius = 6371; // km

        $latDelta = deg2rad($latitude - $this->latitude);
        $lonDelta = deg2rad($longitude - $this->longitude);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) *
            sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    /**
     * Get formatted distance string.
     */
    public function formattedDistanceFrom(float $latitude, float $longitude): string
    {
        $distance = $this->distanceFrom($latitude, $longitude);

        if ($distance === null) {
            return 'Unknown distance';
        }

        if ($distance < 1) {
            return round($distance * 1000) . ' m';
        }

        return round($distance, 1) . ' km';
    }

    /**
     * Get location display string.
     */
    public function getLocationDisplayAttribute(): string
    {
        return $this->location_name ?? 'Location not specified';
    }

    /**
     * Get formatted date and time display.
     */
    public function getFormattedDateTimeAttribute(): string
    {
        $date = $this->job_date?->format('d M Y') ?? 'TBD';
        
        if ($this->job_time) {
            try {
                $time = \Carbon\Carbon::createFromFormat('H:i:s', $this->job_time)->format('g:i A');
                return "{$date} at {$time}";
            } catch (\Exception $e) {
                return "{$date} at {$this->job_time}";
            }
        }
        
        return $date;
    }

    /**
     * Get duration display (alias for formatted_duration).
     */
    public function getDurationDisplayAttribute(): string
    {
        return $this->formatted_duration;
    }

    /**
     * Get pay display (alias for formatted_pay).
     */
    public function getPayDisplayAttribute(): string
    {
        return $this->formatted_pay;
    }

    /**
     * Increment applications count.
     */
    public function incrementApplicationsCount(): void
    {
        $this->increment('applications_count');
    }

    /**
     * Decrement applications count.
     */
    public function decrementApplicationsCount(): void
    {
        if ($this->applications_count > 0) {
            $this->decrement('applications_count');
        }
    }

    /**
     * Assign this job to a worker.
     *
     * @param int|JobWorker $worker Worker ID or JobWorker model
     * @return void
     */
    public function assign(int|JobWorker $worker): void
    {
        $workerId = $worker instanceof JobWorker ? $worker->id : $worker;
        
        $this->update([
            'assigned_worker_id' => $workerId,
            'status' => \App\Enums\JobStatus::ASSIGNED,
            'assigned_at' => now(),
        ]);
    }

    /**
     * Get the category display name (handles custom categories).
     */
    public function getCategoryDisplayAttribute(): string
    {
        if ($this->custom_category_text) {
            return $this->custom_category_text;
        }
        
        return $this->category?->name_en ?? $this->category?->name_ml ?? 'Other';
    }

    /**
     * Get the category icon.
     */
    public function getCategoryIconAttribute(): string
    {
        return $this->category?->icon ?? '📋';
    }
}