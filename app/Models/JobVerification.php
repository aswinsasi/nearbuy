<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Job Verification Model - Execution tracking for jobs.
 *
 * @property int $id
 * @property int $job_post_id
 * @property int $worker_id
 * @property string|null $arrival_photo_url
 * @property \Carbon\Carbon|null $arrival_verified_at
 * @property float|null $arrival_latitude
 * @property float|null $arrival_longitude
 * @property string|null $completion_photo_url
 * @property \Carbon\Carbon|null $completion_verified_at
 * @property \Carbon\Carbon|null $worker_confirmed_at
 * @property \Carbon\Carbon|null $poster_confirmed_at
 * @property PaymentMethod|null $payment_method
 * @property \Carbon\Carbon|null $payment_confirmed_at
 * @property string|null $payment_reference
 * @property int|null $rating
 * @property string|null $rating_comment
 * @property \Carbon\Carbon|null $rated_at
 * @property int|null $worker_rating
 * @property string|null $worker_feedback
 * @property bool $has_dispute
 * @property string|null $dispute_reason
 * @property \Carbon\Carbon|null $disputed_at
 * @property string|null $dispute_resolution
 * @property \Carbon\Carbon|null $resolved_at
 *
 * @srs-ref Section 3.5 - Job Verification & Completion
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobVerification extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'job_post_id',
        'worker_id',
        'arrival_photo_url',
        'arrival_verified_at',
        'arrival_latitude',
        'arrival_longitude',
        'completion_photo_url',
        'completion_verified_at',
        'worker_confirmed_at',
        'poster_confirmed_at',
        'payment_method',
        'payment_confirmed_at',
        'payment_reference',
        'rating',
        'rating_comment',
        'rated_at',
        'worker_rating',
        'worker_feedback',
        'has_dispute',
        'dispute_reason',
        'disputed_at',
        'dispute_resolution',
        'resolved_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'payment_method' => PaymentMethod::class,
        'arrival_verified_at' => 'datetime',
        'arrival_latitude' => 'decimal:8',
        'arrival_longitude' => 'decimal:8',
        'completion_verified_at' => 'datetime',
        'worker_confirmed_at' => 'datetime',
        'poster_confirmed_at' => 'datetime',
        'payment_confirmed_at' => 'datetime',
        'rated_at' => 'datetime',
        'has_dispute' => 'boolean',
        'disputed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'has_dispute' => false,
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
     * Get the worker.
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
     * Scope to filter verified arrivals.
     */
    public function scopeArrivalVerified(Builder $query): Builder
    {
        return $query->whereNotNull('arrival_verified_at');
    }

    /**
     * Scope to filter completed verifications.
     */
    public function scopeCompletionVerified(Builder $query): Builder
    {
        return $query->whereNotNull('completion_verified_at');
    }

    /**
     * Scope to filter fully verified jobs.
     */
    public function scopeFullyVerified(Builder $query): Builder
    {
        return $query->whereNotNull('worker_confirmed_at')
            ->whereNotNull('poster_confirmed_at')
            ->whereNotNull('payment_confirmed_at');
    }

    /**
     * Scope to filter jobs with disputes.
     */
    public function scopeDisputed(Builder $query): Builder
    {
        return $query->where('has_dispute', true);
    }

    /**
     * Scope to filter resolved disputes.
     */
    public function scopeResolved(Builder $query): Builder
    {
        return $query->whereNotNull('resolved_at');
    }

    /**
     * Scope to filter rated verifications.
     */
    public function scopeRated(Builder $query): Builder
    {
        return $query->whereNotNull('rating');
    }

    /**
     * Scope by worker.
     */
    public function scopeByWorker(Builder $query, int $workerId): Builder
    {
        return $query->where('worker_id', $workerId);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Check if arrival is verified.
     */
    public function getIsArrivalVerifiedAttribute(): bool
    {
        return $this->arrival_verified_at !== null;
    }

    /**
     * Check if completion is verified.
     */
    public function getIsCompletionVerifiedAttribute(): bool
    {
        return $this->completion_verified_at !== null;
    }

    /**
     * Check if worker has confirmed.
     */
    public function getIsWorkerConfirmedAttribute(): bool
    {
        return $this->worker_confirmed_at !== null;
    }

    /**
     * Check if poster has confirmed.
     */
    public function getIsPosterConfirmedAttribute(): bool
    {
        return $this->poster_confirmed_at !== null;
    }

    /**
     * Check if payment is confirmed.
     */
    public function getIsPaymentConfirmedAttribute(): bool
    {
        return $this->payment_confirmed_at !== null;
    }

    /**
     * Check if fully verified.
     */
    public function getIsFullyVerifiedAttribute(): bool
    {
        return $this->is_worker_confirmed &&
            $this->is_poster_confirmed &&
            $this->is_payment_confirmed;
    }

    /**
     * Get rating display.
     */
    public function getRatingDisplayAttribute(): ?string
    {
        if (!$this->rating) {
            return null;
        }

        return str_repeat('⭐', $this->rating) . ' (' . $this->rating . '/5)';
    }

    /**
     * Get payment method display.
     */
    public function getPaymentMethodDisplayAttribute(): ?string
    {
        return $this->payment_method?->display();
    }

    /**
     * Get verification progress percentage.
     */
    public function getProgressAttribute(): int
    {
        $steps = 0;
        $completed = 0;

        // Arrival (optional)
        if ($this->is_arrival_verified) $completed++;
        
        // Completion (optional)
        if ($this->is_completion_verified) $completed++;
        
        // Worker confirmed
        $steps++;
        if ($this->is_worker_confirmed) $completed++;
        
        // Poster confirmed
        $steps++;
        if ($this->is_poster_confirmed) $completed++;
        
        // Payment
        $steps++;
        if ($this->is_payment_confirmed) $completed++;

        return $steps > 0 ? (int) (($completed / ($steps + 2)) * 100) : 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Record worker arrival.
     */
    public function recordArrival(?string $photoUrl = null, ?float $latitude = null, ?float $longitude = null): void
    {
        $this->update([
            'arrival_photo_url' => $photoUrl,
            'arrival_verified_at' => now(),
            'arrival_latitude' => $latitude,
            'arrival_longitude' => $longitude,
        ]);

        // Start the job
        $this->jobPost->start();
    }

    /**
     * Record job completion.
     */
    public function recordCompletion(?string $photoUrl = null): void
    {
        $this->update([
            'completion_photo_url' => $photoUrl,
            'completion_verified_at' => now(),
        ]);
    }

    /**
     * Worker confirms completion.
     */
    public function confirmByWorker(): void
    {
        $this->update(['worker_confirmed_at' => now()]);
        $this->checkAndComplete();
    }

    /**
     * Poster confirms completion.
     */
    public function confirmByPoster(): void
    {
        $this->update(['poster_confirmed_at' => now()]);
        $this->checkAndComplete();
    }

    /**
     * Confirm payment.
     */
    public function confirmPayment(PaymentMethod $method, ?string $reference = null): void
    {
        $this->update([
            'payment_method' => $method,
            'payment_confirmed_at' => now(),
            'payment_reference' => $reference,
        ]);
        $this->checkAndComplete();
    }

    /**
     * Check if all confirmations are done and complete the job.
     */
    protected function checkAndComplete(): void
    {
        if ($this->is_fully_verified) {
            $this->jobPost->complete();
        }
    }

    /**
     * Rate the worker (by poster).
     */
    public function rateWorker(int $rating, ?string $comment = null): void
    {
        $this->update([
            'rating' => min(5, max(1, $rating)),
            'rating_comment' => $comment,
            'rated_at' => now(),
        ]);

        // Update worker's rating
        $this->worker->updateRating($rating);
    }

    /**
     * Rate the poster (by worker).
     */
    public function ratePoster(int $rating, ?string $feedback = null): void
    {
        $this->update([
            'worker_rating' => min(5, max(1, $rating)),
            'worker_feedback' => $feedback,
        ]);
    }

    /**
     * Check if fully verified.
     */
    public function isFullyVerified(): bool
    {
        return $this->is_fully_verified;
    }

    /**
     * Raise a dispute.
     */
    public function raiseDispute(string $reason): void
    {
        $this->update([
            'has_dispute' => true,
            'dispute_reason' => $reason,
            'disputed_at' => now(),
        ]);
    }

    /**
     * Resolve dispute.
     */
    public function resolveDispute(string $resolution): void
    {
        $this->update([
            'dispute_resolution' => $resolution,
            'resolved_at' => now(),
        ]);
    }

    /**
     * Convert to status summary.
     */
    public function toStatusSummary(): array
    {
        return [
            'arrival_verified' => $this->is_arrival_verified,
            'completion_verified' => $this->is_completion_verified,
            'worker_confirmed' => $this->is_worker_confirmed,
            'poster_confirmed' => $this->is_poster_confirmed,
            'payment_confirmed' => $this->is_payment_confirmed,
            'fully_verified' => $this->is_fully_verified,
            'rating' => $this->rating,
            'has_dispute' => $this->has_dispute,
            'progress' => $this->progress,
        ];
    }
}