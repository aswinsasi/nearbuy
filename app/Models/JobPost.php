<?php

declare(strict_types=1);

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
 * SRS 5.2.2 job_posts Table Fields:
 * - id: INT PK
 * - poster_user_id: INT FK → Task giver reference
 * - job_type: VARCHAR(50) → Category of job
 * - title, description: VARCHAR/TEXT → Job details
 * - location_name: VARCHAR(200) → Human-readable location
 * - latitude, longitude: DECIMAL → Job location coordinates
 * - job_date, job_time: DATE/TIME → When job should be done
 * - duration_hours: DECIMAL(3,1) → Estimated duration
 * - pay_amount: DECIMAL(8,2) → Payment offered
 * - status: ENUM → open, assigned, in_progress, completed, cancelled
 * - assigned_worker_id: INT FK → Selected worker reference
 *
 * @property int $id
 * @property string $job_number
 * @property int $poster_user_id
 * @property int|null $job_category_id
 * @property string|null $custom_category_text
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
 * @property JobStatus $status
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
 * @property-read JobVerification|null $verification
 *
 * @srs-ref SRS 5.2.2 job_posts Table
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
                $job->status = JobStatus::OPEN;
            }
            if (empty($job->expires_at)) {
                $job->expires_at = now()->addDays(7);
            }
            if (empty($job->posted_at)) {
                $job->posted_at = now();
            }
            if (!isset($job->applications_count)) {
                $job->applications_count = 0;
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
        return $this->hasOne(JobVerification::class, 'job_post_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get the category display name.
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

    /**
     * Get formatted pay amount.
     */
    public function getFormattedPayAttribute(): string
    {
        return '₹' . number_format((float) $this->pay_amount, 0);
    }

    /**
     * Alias for formatted_pay.
     */
    public function getPayDisplayAttribute(): string
    {
        return $this->formatted_pay;
    }

    /**
     * Get formatted job date.
     */
    public function getFormattedDateAttribute(): string
    {
        if (!$this->job_date) {
            return 'TBD';
        }

        if ($this->job_date->isToday()) {
            return 'Innu';
        }

        if ($this->job_date->isTomorrow()) {
            return 'Nale';
        }

        return $this->job_date->format('d M');
    }

    /**
     * Get formatted time.
     */
    public function getFormattedTimeAttribute(): ?string
    {
        if (!$this->job_time) {
            return null;
        }

        try {
            return \Carbon\Carbon::createFromFormat('H:i:s', $this->job_time)->format('g:i A');
        } catch (\Exception $e) {
            return $this->job_time;
        }
    }

    /**
     * Get formatted date and time.
     */
    public function getFormattedDateTimeAttribute(): string
    {
        $date = $this->formatted_date;
        $time = $this->formatted_time;

        if ($time) {
            return "{$date} {$time}";
        }

        return $date;
    }

    /**
     * Get formatted duration.
     */
    public function getFormattedDurationAttribute(): string
    {
        if (!$this->duration_hours) {
            return 'Flexible';
        }

        $hours = (float) $this->duration_hours;

        if ($hours < 1) {
            return (int)($hours * 60) . ' mins';
        }

        if ($hours == 1) {
            return '1 hr';
        }

        return $hours . ' hrs';
    }

    /**
     * Alias for formatted_duration.
     */
    public function getDurationDisplayAttribute(): string
    {
        return $this->formatted_duration;
    }

    /**
     * Get location display string.
     */
    public function getLocationDisplayAttribute(): string
    {
        return $this->location_name ?? 'Location not specified';
    }

    /**
     * Check if job is open for applications.
     */
    public function getIsOpenAttribute(): bool
    {
        $status = $this->status instanceof JobStatus 
            ? $this->status 
            : JobStatus::tryFrom($this->status);

        return $status === JobStatus::OPEN && 
            ($this->expires_at === null || $this->expires_at->isFuture());
    }

    /**
     * Check if job is active (open, assigned, or in_progress).
     */
    public function getIsActiveAttribute(): bool
    {
        $status = $this->status instanceof JobStatus 
            ? $this->status 
            : JobStatus::tryFrom($this->status);

        return in_array($status, [
            JobStatus::OPEN,
            JobStatus::ASSIGNED,
            JobStatus::IN_PROGRESS,
        ]);
    }

    /**
     * Check if job is expired.
     */
    public function getIsExpiredAttribute(): bool
    {
        $status = $this->status instanceof JobStatus 
            ? $this->status 
            : JobStatus::tryFrom($this->status);

        return $status === JobStatus::EXPIRED || 
            ($status === JobStatus::OPEN && $this->expires_at && $this->expires_at->isPast());
    }

    /**
     * Check if job accepts applications.
     */
    public function getAcceptsApplicationsAttribute(): bool
    {
        if (!$this->is_open) {
            return false;
        }

        // Job date must be today or future
        if ($this->job_date && $this->job_date->lt(now()->startOfDay())) {
            return false;
        }

        return true;
    }

    /**
     * Get pending applications count.
     */
    public function getPendingApplicationsCountAttribute(): int
    {
        return $this->applications()->where('status', 'pending')->count();
    }

    /**
     * Check if this is a handover job (queue standing).
     */
    public function getIsHandoverJobAttribute(): bool
    {
        $category = $this->category;
        
        if (!$category) {
            return false;
        }

        $handoverCategories = ['queue_standing', 'queue'];
        
        return in_array($category->slug ?? '', $handoverCategories) ||
            str_contains(strtolower($category->name_en ?? ''), 'queue');
    }

    /**
     * Get status display name.
     */
    public function getStatusDisplayAttribute(): string
    {
        $status = $this->status instanceof JobStatus 
            ? $this->status 
            : JobStatus::tryFrom($this->status);

        return $status?->label() ?? 'Unknown';
    }

    /**
     * Get status icon.
     */
    public function getStatusIconAttribute(): string
    {
        $status = $this->status instanceof JobStatus 
            ? $this->status 
            : JobStatus::tryFrom($this->status);

        return match ($status) {
            JobStatus::OPEN => '🟢',
            JobStatus::ASSIGNED => '🟡',
            JobStatus::IN_PROGRESS => '🔵',
            JobStatus::COMPLETED => '✅',
            JobStatus::CANCELLED => '❌',
            JobStatus::EXPIRED => '⏰',
            default => '⚪',
        };
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
        return $query->where('status', JobStatus::OPEN)
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
        return $query->whereIn('status', [
            JobStatus::OPEN,
            JobStatus::ASSIGNED,
            JobStatus::IN_PROGRESS,
        ]);
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
     * Scope for nearby jobs using Haversine formula.
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
     * Scope for today's jobs.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('job_date', today());
    }

    /**
     * Scope for assigned to worker.
     */
    public function scopeAssignedTo($query, int $workerId)
    {
        return $query->where('assigned_worker_id', $workerId);
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
        if (!$this->is_open) {
            return false;
        }

        $this->update([
            'status' => JobStatus::ASSIGNED,
            'assigned_worker_id' => $worker->id,
            'assigned_at' => now(),
        ]);

        return true;
    }

    /**
     * Alias for assignWorker.
     */
    public function assign(int|JobWorker $worker): void
    {
        $workerId = $worker instanceof JobWorker ? $worker->id : $worker;
        
        $this->update([
            'assigned_worker_id' => $workerId,
            'status' => JobStatus::ASSIGNED,
            'assigned_at' => now(),
        ]);
    }

    /**
     * Start the job execution.
     */
    public function start(): bool
    {
        $status = $this->status instanceof JobStatus 
            ? $this->status 
            : JobStatus::tryFrom($this->status);

        if ($status !== JobStatus::ASSIGNED) {
            return false;
        }

        $this->update([
            'status' => JobStatus::IN_PROGRESS,
            'started_at' => now(),
        ]);

        return true;
    }

    /**
     * Mark the job as completed.
     */
    public function complete(): bool
    {
        $status = $this->status instanceof JobStatus 
            ? $this->status 
            : JobStatus::tryFrom($this->status);

        if ($status !== JobStatus::IN_PROGRESS) {
            return false;
        }

        $this->update([
            'status' => JobStatus::COMPLETED,
            'completed_at' => now(),
        ]);

        return true;
    }

    /**
     * Cancel the job.
     */
    public function cancel(?string $reason = null): bool
    {
        $status = $this->status instanceof JobStatus 
            ? $this->status 
            : JobStatus::tryFrom($this->status);

        if (in_array($status, [JobStatus::COMPLETED, JobStatus::CANCELLED])) {
            return false;
        }

        $this->update([
            'status' => JobStatus::CANCELLED,
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
        $status = $this->status instanceof JobStatus 
            ? $this->status 
            : JobStatus::tryFrom($this->status);

        if ($status !== JobStatus::OPEN) {
            return false;
        }

        $this->update([
            'status' => JobStatus::EXPIRED,
        ]);

        return true;
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

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate distance from a location in kilometers.
     */
    public function distanceFrom(float|string $latitude, float|string $longitude): ?float
    {
        if (!$this->latitude || !$this->longitude) {
            return null;
        }

        $latitude = (float) $latitude;
        $longitude = (float) $longitude;

        $earthRadius = 6371; // km

        $latDelta = deg2rad($latitude - (float) $this->latitude);
        $lonDelta = deg2rad($longitude - (float) $this->longitude);

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
            cos(deg2rad((float) $this->latitude)) * cos(deg2rad($latitude)) *
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
            return '?';
        }

        if ($distance < 1) {
            return round($distance * 1000) . 'm';
        }

        return round($distance, 1) . 'km';
    }

    /**
     * Get summary for list display.
     */
    public function toListSummary(): string
    {
        $icon = $this->category_icon;
        $title = $this->title;
        $pay = $this->pay_display;
        $date = $this->formatted_date;
        $duration = $this->formatted_duration;

        return "{$icon} {$title}\n💰 {$pay} • {$date} • {$duration}";
    }

    /**
     * Get full job card for WhatsApp message.
     */
    public function toJobCard(?float $workerLat = null, ?float $workerLon = null): string
    {
        $lines = [
            "{$this->category_icon} *{$this->title}*",
            "",
            "📍 {$this->location_display}",
            "📅 {$this->formatted_date_time}",
            "⏱️ {$this->duration_display}",
            "💰 {$this->pay_display}",
        ];

        if ($workerLat && $workerLon) {
            $distance = $this->formattedDistanceFrom($workerLat, $workerLon);
            $lines[] = "📏 {$distance} away";
        }

        if ($this->description) {
            $lines[] = "";
            $lines[] = $this->description;
        }

        if ($this->special_instructions) {
            $lines[] = "";
            $lines[] = "📝 {$this->special_instructions}";
        }

        return implode("\n", $lines);
    }
}