<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\JobApplicationStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Job Application Model - Worker applications for jobs.
 *
 * @property int $id
 * @property int $job_post_id
 * @property int $worker_id
 * @property string|null $message
 * @property float|null $proposed_amount
 * @property JobApplicationStatus $status
 * @property float|null $distance_km Distance from worker to job location
 * @property \Carbon\Carbon $applied_at
 * @property \Carbon\Carbon|null $responded_at
 * @property \Carbon\Carbon|null $created_at
 * @property \Carbon\Carbon|null $updated_at
 *
 * @property-read JobPost $jobPost
 * @property-read JobWorker $worker
 * @property-read string $status_display
 * @property-read string $time_since_applied
 * @property-read bool $is_pending
 * @property-read bool $is_accepted
 * @property-read string|null $proposed_amount_display
 * @property-read string $distance_display
 *
 * @srs-ref NP-015 to NP-021
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobApplication extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'job_applications';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'job_post_id',
        'worker_id',
        'message',
        'proposed_amount',
        'status',
        'distance_km',
        'applied_at',
        'responded_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'status' => JobApplicationStatus::class,
        'proposed_amount' => 'decimal:2',
        'distance_km' => 'decimal:2',
        'applied_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the job post.
     */
    public function jobPost(): BelongsTo
    {
        return $this->belongsTo(JobPost::class, 'job_post_id');
    }

    /**
     * Alias for jobPost.
     */
    public function job(): BelongsTo
    {
        return $this->jobPost();
    }

    /**
     * Get the worker who applied.
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(JobWorker::class, 'worker_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter pending applications.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', JobApplicationStatus::PENDING);
    }

    /**
     * Scope to filter accepted applications.
     */
    public function scopeAccepted(Builder $query): Builder
    {
        return $query->where('status', JobApplicationStatus::ACCEPTED);
    }

    /**
     * Scope to filter rejected applications.
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', JobApplicationStatus::REJECTED);
    }

    /**
     * Scope to filter withdrawn applications.
     */
    public function scopeWithdrawn(Builder $query): Builder
    {
        return $query->where('status', JobApplicationStatus::WITHDRAWN);
    }

    /**
     * Scope to filter by worker.
     */
    public function scopeByWorker(Builder $query, int $workerId): Builder
    {
        return $query->where('worker_id', $workerId);
    }

    /**
     * Scope to filter by job post.
     */
    public function scopeForJob(Builder $query, int $jobPostId): Builder
    {
        return $query->where('job_post_id', $jobPostId);
    }

    /**
     * Scope to order by most recent.
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('applied_at', 'desc');
    }

    /**
     * Scope to order by earliest (FIFO).
     */
    public function scopeEarliest(Builder $query): Builder
    {
        return $query->orderBy('applied_at', 'asc');
    }

    /**
     * Scope for pending applications with worker details (for review).
     */
    public function scopeForReview(Builder $query): Builder
    {
        return $query->with(['worker.user'])
            ->pending()
            ->earliest();
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get status display text.
     */
    public function getStatusDisplayAttribute(): string
    {
        $status = $this->status instanceof JobApplicationStatus
            ? $this->status
            : JobApplicationStatus::tryFrom($this->status);

        return $status?->label() ?? 'Unknown';
    }

    /**
     * Get status icon.
     */
    public function getStatusIconAttribute(): string
    {
        $status = $this->status instanceof JobApplicationStatus
            ? $this->status
            : JobApplicationStatus::tryFrom($this->status);

        return match ($status) {
            JobApplicationStatus::PENDING => '🟡',
            JobApplicationStatus::ACCEPTED => '✅',
            JobApplicationStatus::REJECTED => '❌',
            JobApplicationStatus::WITHDRAWN => '⬜',
            default => '🔵',
        };
    }

    /**
     * Get time since application.
     */
    public function getTimeSinceAppliedAttribute(): string
    {
        if (!$this->applied_at) {
            return 'just now';
        }
        return $this->applied_at->diffForHumans();
    }

    /**
     * Check if application is pending.
     */
    public function getIsPendingAttribute(): bool
    {
        $status = $this->status instanceof JobApplicationStatus
            ? $this->status
            : JobApplicationStatus::tryFrom($this->status);

        return $status === JobApplicationStatus::PENDING;
    }

    /**
     * Check if application was accepted.
     */
    public function getIsAcceptedAttribute(): bool
    {
        $status = $this->status instanceof JobApplicationStatus
            ? $this->status
            : JobApplicationStatus::tryFrom($this->status);

        return $status === JobApplicationStatus::ACCEPTED;
    }

    /**
     * Check if application was rejected.
     */
    public function getIsRejectedAttribute(): bool
    {
        $status = $this->status instanceof JobApplicationStatus
            ? $this->status
            : JobApplicationStatus::tryFrom($this->status);

        return $status === JobApplicationStatus::REJECTED;
    }

    /**
     * Get proposed amount display.
     */
    public function getProposedAmountDisplayAttribute(): ?string
    {
        if (!$this->proposed_amount) {
            return null;
        }

        return '₹' . number_format((float) $this->proposed_amount);
    }

    /**
     * Get distance display.
     */
    public function getDistanceDisplayAttribute(): string
    {
        if (!$this->distance_km) {
            return 'N/A';
        }

        if ($this->distance_km < 1) {
            return round($this->distance_km * 1000) . 'm';
        }

        return round($this->distance_km, 1) . 'km';
    }

    /**
     * Get effective amount (proposed or job posted amount).
     */
    public function getEffectiveAmountAttribute(): float
    {
        return $this->proposed_amount ?? $this->jobPost?->pay_amount ?? 0;
    }

    /**
     * Get effective amount display.
     */
    public function getEffectiveAmountDisplayAttribute(): string
    {
        $amount = $this->effective_amount;
        $display = '₹' . number_format($amount);

        if ($this->proposed_amount && $this->jobPost) {
            if ($this->proposed_amount != $this->jobPost->pay_amount) {
                $display .= ' (proposed)';
            }
        }

        return $display;
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Accept this application.
     */
    public function accept(): bool
    {
        $status = $this->status instanceof JobApplicationStatus
            ? $this->status
            : JobApplicationStatus::tryFrom($this->status);

        if ($status !== JobApplicationStatus::PENDING) {
            return false;
        }

        $this->update([
            'status' => JobApplicationStatus::ACCEPTED,
            'responded_at' => now(),
        ]);

        return true;
    }

    /**
     * Reject this application.
     */
    public function reject(): bool
    {
        $status = $this->status instanceof JobApplicationStatus
            ? $this->status
            : JobApplicationStatus::tryFrom($this->status);

        if ($status !== JobApplicationStatus::PENDING) {
            return false;
        }

        $this->update([
            'status' => JobApplicationStatus::REJECTED,
            'responded_at' => now(),
        ]);

        return true;
    }

    /**
     * Withdraw this application.
     */
    public function withdraw(): bool
    {
        $status = $this->status instanceof JobApplicationStatus
            ? $this->status
            : JobApplicationStatus::tryFrom($this->status);

        if ($status !== JobApplicationStatus::PENDING) {
            return false;
        }

        $this->update([
            'status' => JobApplicationStatus::WITHDRAWN,
            'responded_at' => now(),
        ]);

        // Decrement applications count on job
        $this->jobPost?->decrementApplicationsCount();

        return true;
    }

    /**
     * Calculate distance from worker to job.
     */
    public function calculateDistance(): ?float
    {
        $job = $this->jobPost;
        $worker = $this->worker;

        if (!$job || !$worker) {
            return null;
        }

        if (!$job->latitude || !$job->longitude || !$worker->latitude || !$worker->longitude) {
            return null;
        }

        // Haversine formula
        $earthRadius = 6371; // km

        $latFrom = deg2rad((float) $worker->latitude);
        $lonFrom = deg2rad((float) $worker->longitude);
        $latTo = deg2rad((float) $job->latitude);
        $lonTo = deg2rad((float) $job->longitude);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) ** 2 +
            cos($latFrom) * cos($latTo) * sin($lonDelta / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return round($earthRadius * $c, 2);
    }

    /**
     * Convert to list item for display.
     */
    public function toListItem(): array
    {
        $worker = $this->worker;
        $rating = $worker?->rating ? "⭐{$worker->rating}" : '🆕';
        $jobs = $worker?->jobs_completed ?? 0;

        $description = "{$rating} • {$jobs} jobs";

        if ($this->distance_km) {
            $description .= " • {$this->distance_display}";
        }

        return [
            'id' => 'view_applicant_' . $this->id,
            'title' => mb_substr($worker?->name ?? 'Worker', 0, 24),
            'description' => mb_substr($description, 0, 72),
        ];
    }

    /**
     * Convert to summary format for notifications.
     */
    public function toSummary(): array
    {
        $worker = $this->worker;

        return [
            'id' => $this->id,
            'worker_name' => $worker?->name ?? 'Worker',
            'worker_rating' => $worker?->rating,
            'worker_jobs_completed' => $worker?->jobs_completed ?? 0,
            'worker_photo_url' => $worker?->photo_url,
            'message' => $this->message,
            'proposed_amount' => $this->proposed_amount,
            'distance_km' => $this->distance_km,
            'distance_display' => $this->distance_display,
            'applied_at' => $this->applied_at?->format('M j, h:i A'),
            'time_since' => $this->time_since_applied,
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
            if (empty($model->applied_at)) {
                $model->applied_at = now();
            }

            if (empty($model->status)) {
                $model->status = JobApplicationStatus::PENDING;
            }

            // Calculate distance if not set
            if (empty($model->distance_km)) {
                $model->distance_km = $model->calculateDistance();
            }
        });

        static::created(function ($model) {
            // Increment applications count on job
            $model->jobPost?->incrementApplicationsCount();
        });
    }
}