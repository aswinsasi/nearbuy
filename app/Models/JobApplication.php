<?php

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
 * @property \Carbon\Carbon $applied_at
 * @property \Carbon\Carbon|null $responded_at
 *
 * @srs-ref Section 3.4 - Job Applications
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobApplication extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'job_post_id',
        'worker_id',
        'message',
        'proposed_amount',
        'status',
        'applied_at',
        'responded_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'status' => JobApplicationStatus::class,
        'proposed_amount' => 'decimal:2',
        'applied_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'status' => JobApplicationStatus::PENDING,
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
        return $this->belongsTo(JobPost::class);
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
     * Scope for pending applications with worker details.
     */
    public function scopeForReview(Builder $query): Builder
    {
        return $query->with('worker')
            ->pending()
            ->latest();
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get status display.
     */
    public function getStatusDisplayAttribute(): string
    {
        return $this->status->display();
    }

    /**
     * Get time since application.
     */
    public function getTimeSinceAppliedAttribute(): string
    {
        return $this->applied_at->diffForHumans();
    }

    /**
     * Check if application is pending.
     */
    public function getIsPendingAttribute(): bool
    {
        return $this->status === JobApplicationStatus::PENDING;
    }

    /**
     * Check if application was accepted.
     */
    public function getIsAcceptedAttribute(): bool
    {
        return $this->status === JobApplicationStatus::ACCEPTED;
    }

    /**
     * Get proposed amount display.
     */
    public function getProposedAmountDisplayAttribute(): ?string
    {
        if (!$this->proposed_amount) {
            return null;
        }

        return '₹' . number_format($this->proposed_amount);
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
        if (!$this->status->canTransitionTo(JobApplicationStatus::ACCEPTED)) {
            return false;
        }

        $this->update([
            'status' => JobApplicationStatus::ACCEPTED,
            'responded_at' => now(),
        ]);

        // Assign worker to job
        $this->jobPost->assign($this->worker);

        return true;
    }

    /**
     * Reject this application.
     */
    public function reject(): bool
    {
        if (!$this->status->canTransitionTo(JobApplicationStatus::REJECTED)) {
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
        if (!$this->status->canTransitionTo(JobApplicationStatus::WITHDRAWN)) {
            return false;
        }

        $this->update([
            'status' => JobApplicationStatus::WITHDRAWN,
            'responded_at' => now(),
        ]);

        // Decrement applications count
        $this->jobPost->decrementApplicationsCount();

        return true;
    }

    /**
     * Convert to list item for display.
     */
    public function toListItem(): array
    {
        $worker = $this->worker;
        $description = $worker?->short_rating ?? 'New Worker';

        if ($this->proposed_amount) {
            $description .= ' • Proposed: ' . $this->proposed_amount_display;
        }

        $description .= ' • ' . $this->time_since_applied;

        return [
            'id' => 'app_' . $this->id,
            'title' => substr($worker?->name ?? 'Unknown Worker', 0, 24),
            'description' => substr($description, 0, 72),
        ];
    }

    /**
     * Convert to detail format.
     */
    public function toDetailFormat(): array
    {
        return [
            'id' => $this->id,
            'worker' => $this->worker?->toSummary(),
            'message' => $this->message,
            'proposed_amount' => $this->proposed_amount,
            'proposed_amount_display' => $this->proposed_amount_display,
            'status' => $this->status->value,
            'status_display' => $this->status_display,
            'applied_at' => $this->applied_at->format('M j, Y h:i A'),
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
        });

        static::created(function ($model) {
            // Increment applications count on job
            $model->jobPost->incrementApplicationsCount();
        });
    }
}