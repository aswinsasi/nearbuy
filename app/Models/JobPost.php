<?php

namespace App\Models;

use App\Enums\JobStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Job Post Model - Posted jobs and tasks.
 *
 * @property int $id
 * @property int $poster_user_id
 * @property int $job_category_id
 * @property string $job_number
 * @property string $title
 * @property string|null $description
 * @property string|null $location_name
 * @property float|null $latitude
 * @property float|null $longitude
 * @property \Carbon\Carbon $job_date
 * @property string|null $job_time
 * @property float|null $duration_hours
 * @property float $pay_amount
 * @property string|null $special_instructions
 * @property JobStatus $status
 * @property int|null $assigned_worker_id
 * @property int $applications_count
 * @property \Carbon\Carbon|null $posted_at
 * @property \Carbon\Carbon|null $assigned_at
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon|null $expires_at
 *
 * @srs-ref Section 3.3 - Job Posts
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobPost extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'poster_user_id',
        'job_category_id',
        'job_number',
        'title',
        'description',
        'location_name',
        'latitude',
        'longitude',
        'job_date',
        'job_time',
        'duration_hours',
        'pay_amount',
        'special_instructions',
        'status',
        'assigned_worker_id',
        'applications_count',
        'posted_at',
        'assigned_at',
        'started_at',
        'completed_at',
        'expires_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'status' => JobStatus::class,
        'job_date' => 'date',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'duration_hours' => 'decimal:1',
        'pay_amount' => 'decimal:2',
        'posted_at' => 'datetime',
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Default expiry hours for open jobs.
     */
    public const DEFAULT_EXPIRY_HOURS = 48;

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
        return $this->hasMany(JobApplication::class);
    }

    /**
     * Get pending applications.
     */
    public function pendingApplications(): HasMany
    {
        return $this->applications()->where('status', 'pending');
    }

    /**
     * Get the verification record.
     */
    public function verification(): HasOne
    {
        return $this->hasOne(JobVerification::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter draft jobs.
     */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->where('status', JobStatus::DRAFT);
    }

    /**
     * Scope to filter open jobs.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', JobStatus::OPEN);
    }

    /**
     * Scope to filter assigned jobs.
     */
    public function scopeAssigned(Builder $query): Builder
    {
        return $query->where('status', JobStatus::ASSIGNED);
    }

    /**
     * Scope to filter in-progress jobs.
     */
    public function scopeInProgress(Builder $query): Builder
    {
        return $query->where('status', JobStatus::IN_PROGRESS);
    }

    /**
     * Scope to filter completed jobs.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', JobStatus::COMPLETED);
    }

    /**
     * Scope to filter cancelled jobs.
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', JobStatus::CANCELLED);
    }

    /**
     * Scope to filter expired jobs.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('status', JobStatus::EXPIRED)
                ->orWhere(function ($q2) {
                    $q2->where('status', JobStatus::OPEN)
                        ->where('expires_at', '<=', now());
                });
        });
    }

    /**
     * Scope to filter active jobs (not completed/cancelled/expired).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            JobStatus::OPEN,
            JobStatus::ASSIGNED,
            JobStatus::IN_PROGRESS,
        ]);
    }

    /**
     * Scope to filter browsable jobs (open and not expired).
     */
    public function scopeBrowsable(Builder $query): Builder
    {
        return $query->where('status', JobStatus::OPEN)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope to filter by category.
     */
    public function scopeForCategory(Builder $query, int $categoryId): Builder
    {
        return $query->where('job_category_id', $categoryId);
    }

    /**
     * Scope to filter jobs for today.
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('job_date', today());
    }

    /**
     * Scope to filter upcoming jobs.
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('job_date', '>=', today())
            ->orderBy('job_date')
            ->orderBy('job_time');
    }

    /**
     * Scope to filter jobs by poster.
     */
    public function scopeByPoster(Builder $query, int $userId): Builder
    {
        return $query->where('poster_user_id', $userId);
    }

    /**
     * Scope to find jobs near a location.
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
     * Scope for browsing jobs.
     */
    public function scopeForBrowse(Builder $query): Builder
    {
        return $query->with(['poster', 'category'])
            ->browsable()
            ->upcoming();
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get display title with category icon.
     */
    public function getDisplayTitleAttribute(): string
    {
        $icon = $this->category?->icon ?? '💼';
        return $icon . ' ' . $this->title;
    }

    /**
     * Get pay display.
     */
    public function getPayDisplayAttribute(): string
    {
        return '₹' . number_format($this->pay_amount);
    }

    /**
     * Get duration display.
     */
    public function getDurationDisplayAttribute(): ?string
    {
        if (!$this->duration_hours) {
            return null;
        }

        $hours = $this->duration_hours;
        if ($hours < 1) {
            return (int)($hours * 60) . ' mins';
        }

        return $hours . ' hrs';
    }

    /**
     * Get formatted date time.
     */
    public function getFormattedDateTimeAttribute(): string
    {
        $date = $this->job_date->format('D, M j');

        if ($this->job_time) {
            return $date . ' at ' . $this->job_time;
        }

        return $date;
    }

    /**
     * Get location display.
     */
    public function getLocationDisplayAttribute(): string
    {
        return $this->location_name ?? 'Location to be shared';
    }

    /**
     * Get status display.
     */
    public function getStatusDisplayAttribute(): string
    {
        return $this->status->display();
    }

    /**
     * Check if job is expired.
     */
    public function getIsExpiredAttribute(): bool
    {
        if ($this->status === JobStatus::EXPIRED) {
            return true;
        }

        if ($this->expires_at && $this->expires_at <= now()) {
            return true;
        }

        return false;
    }

    /**
     * Check if job can be edited.
     */
    public function getCanEditAttribute(): bool
    {
        return $this->status->canEdit();
    }

    /**
     * Check if job can be cancelled.
     */
    public function getCanCancelAttribute(): bool
    {
        return $this->status->canCancel();
    }

    /**
     * Check if job accepts applications.
     */
    public function getAcceptsApplicationsAttribute(): bool
    {
        return $this->status->acceptsApplications() && !$this->is_expired;
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Generate unique job number.
     */
    public static function generateJobNumber(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(4));
        $number = "JP-{$date}-{$random}";

        while (self::where('job_number', $number)->exists()) {
            $random = strtoupper(Str::random(4));
            $number = "JP-{$date}-{$random}";
        }

        return $number;
    }

    /**
     * Get distance from a location in km.
     */
    public function getDistanceFrom(float $latitude, float $longitude): ?float
    {
        if (!$this->latitude || !$this->longitude) {
            return null;
        }

        $earthRadius = 6371;

        $latDiff = deg2rad($latitude - $this->latitude);
        $lngDiff = deg2rad($longitude - $this->longitude);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    /**
     * Publish the job (move from draft to open).
     */
    public function publish(): bool
    {
        if ($this->status !== JobStatus::DRAFT) {
            return false;
        }

        $this->update([
            'status' => JobStatus::OPEN,
            'posted_at' => now(),
            'expires_at' => now()->addHours(self::DEFAULT_EXPIRY_HOURS),
        ]);

        return true;
    }

    /**
     * Assign job to a worker.
     */
    public function assign(JobWorker $worker): bool
    {
        if (!$this->status->canTransitionTo(JobStatus::ASSIGNED)) {
            return false;
        }

        $this->update([
            'status' => JobStatus::ASSIGNED,
            'assigned_worker_id' => $worker->id,
            'assigned_at' => now(),
        ]);

        // Reject all other pending applications
        $this->applications()
            ->where('worker_id', '!=', $worker->id)
            ->where('status', 'pending')
            ->update(['status' => 'rejected', 'responded_at' => now()]);

        return true;
    }

    /**
     * Start the job.
     */
    public function start(): bool
    {
        if (!$this->status->canTransitionTo(JobStatus::IN_PROGRESS)) {
            return false;
        }

        $this->update([
            'status' => JobStatus::IN_PROGRESS,
            'started_at' => now(),
        ]);

        return true;
    }

    /**
     * Complete the job.
     */
    public function complete(): bool
    {
        if (!$this->status->canTransitionTo(JobStatus::COMPLETED)) {
            return false;
        }

        $this->update([
            'status' => JobStatus::COMPLETED,
            'completed_at' => now(),
        ]);

        // Update worker stats
        if ($this->assignedWorker) {
            $this->assignedWorker->incrementJobsCompleted($this->pay_amount);
        }

        return true;
    }

    /**
     * Cancel the job.
     */
    public function cancel(): bool
    {
        if (!$this->status->canCancel()) {
            return false;
        }

        $this->update(['status' => JobStatus::CANCELLED]);

        // Withdraw all pending applications
        $this->applications()
            ->where('status', 'pending')
            ->update(['status' => 'withdrawn']);

        return true;
    }

    /**
     * Mark as expired.
     */
    public function markExpired(): void
    {
        $this->update(['status' => JobStatus::EXPIRED]);
    }

    /**
     * Unassign worker (return to open).
     */
    public function unassign(): bool
    {
        if ($this->status !== JobStatus::ASSIGNED) {
            return false;
        }

        $this->update([
            'status' => JobStatus::OPEN,
            'assigned_worker_id' => null,
            'assigned_at' => null,
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
        $this->decrement('applications_count');
    }

    /**
     * Check if a worker has already applied.
     */
    public function hasApplied(JobWorker $worker): bool
    {
        return $this->applications()->where('worker_id', $worker->id)->exists();
    }

    /**
     * Get formatted date and time.
     */
    public function getFormattedDateTime(): string
    {
        return $this->formatted_date_time;
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        $description = $this->pay_display . ' • ' . $this->formatted_date_time;
        if ($this->location_name) {
            $description .= ' • ' . $this->location_name;
        }

        return [
            'id' => 'job_' . $this->id,
            'title' => substr($this->display_title, 0, 24),
            'description' => substr($description, 0, 72),
        ];
    }

    /**
     * Convert to detail format for messages.
     */
    public function toDetailFormat(): array
    {
        return [
            'job_number' => $this->job_number,
            'title' => $this->title,
            'category' => $this->category?->display_name,
            'description' => $this->description,
            'location' => $this->location_display,
            'date_time' => $this->formatted_date_time,
            'duration' => $this->duration_display,
            'pay' => $this->pay_display,
            'instructions' => $this->special_instructions,
            'status' => $this->status_display,
            'poster_name' => $this->poster?->display_name,
            'applications_count' => $this->applications_count,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Boot
    |--------------------------------------------------------------------------
    */

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->job_number)) {
                $model->job_number = self::generateJobNumber();
            }

            if (empty($model->status)) {
                $model->status = JobStatus::DRAFT;
            }
        });
    }
}