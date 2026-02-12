<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Job Verification Model - Execution tracking for jobs.
 *
 * Tracks the entire job execution lifecycle:
 * - Worker arrival with photo/location (NP-022, NP-023)
 * - Handover confirmation for queue jobs (NP-024)
 * - Mutual completion confirmation (NP-025)
 * - Rating and review (NP-026)
 * - Payment method selection (NP-027)
 * - Stats update triggers (NP-028)
 *
 * @property int $id
 * @property int $job_post_id
 * @property int $worker_id
 * @property string|null $arrival_photo_url
 * @property \Carbon\Carbon|null $arrival_at
 * @property float|null $arrival_latitude
 * @property float|null $arrival_longitude
 * @property bool $arrival_confirmed_by_poster
 * @property \Carbon\Carbon|null $arrival_confirmed_at
 * @property bool $handover_confirmed_by_worker
 * @property \Carbon\Carbon|null $handover_worker_at
 * @property bool $handover_confirmed_by_poster
 * @property \Carbon\Carbon|null $handover_poster_at
 * @property bool $completion_confirmed_by_worker
 * @property \Carbon\Carbon|null $worker_completed_at
 * @property bool $completion_confirmed_by_poster
 * @property \Carbon\Carbon|null $poster_completed_at
 * @property PaymentMethod|null $payment_method
 * @property \Carbon\Carbon|null $payment_confirmed_at
 * @property string|null $payment_reference
 * @property int|null $rating
 * @property string|null $rating_comment
 * @property \Carbon\Carbon|null $rated_at
 * @property bool $has_dispute
 * @property string|null $dispute_reason
 * @property \Carbon\Carbon|null $disputed_at
 * @property string|null $dispute_resolution
 * @property \Carbon\Carbon|null $resolved_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read JobPost $jobPost
 * @property-read JobPost $job
 * @property-read JobWorker $worker
 *
 * @srs-ref NP-022 to NP-028
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobVerification extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'job_verifications';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'job_post_id',
        'worker_id',
        // Arrival (NP-022, NP-023)
        'arrival_photo_url',
        'arrival_at',
        'arrival_latitude',
        'arrival_longitude',
        'arrival_confirmed_by_poster',
        'arrival_confirmed_at',
        // Handover (NP-024)
        'handover_confirmed_by_worker',
        'handover_worker_at',
        'handover_confirmed_by_poster',
        'handover_poster_at',
        // Completion (NP-025)
        'completion_confirmed_by_worker',
        'worker_completed_at',
        'completion_confirmed_by_poster',
        'poster_completed_at',
        // Payment (NP-027)
        'payment_method',
        'payment_confirmed_at',
        'payment_reference',
        // Rating (NP-026)
        'rating',
        'rating_comment',
        'rated_at',
        // Dispute
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
        'arrival_at' => 'datetime',
        'arrival_latitude' => 'decimal:8',
        'arrival_longitude' => 'decimal:8',
        'arrival_confirmed_by_poster' => 'boolean',
        'arrival_confirmed_at' => 'datetime',
        'handover_confirmed_by_worker' => 'boolean',
        'handover_worker_at' => 'datetime',
        'handover_confirmed_by_poster' => 'boolean',
        'handover_poster_at' => 'datetime',
        'completion_confirmed_by_worker' => 'boolean',
        'worker_completed_at' => 'datetime',
        'completion_confirmed_by_poster' => 'boolean',
        'poster_completed_at' => 'datetime',
        'payment_confirmed_at' => 'datetime',
        'rating' => 'integer',
        'rated_at' => 'datetime',
        'has_dispute' => 'boolean',
        'disputed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'arrival_confirmed_by_poster' => false,
        'handover_confirmed_by_worker' => false,
        'handover_confirmed_by_poster' => false,
        'completion_confirmed_by_worker' => false,
        'completion_confirmed_by_poster' => false,
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
     * Scope to filter where worker has arrived.
     */
    public function scopeArrived(Builder $query): Builder
    {
        return $query->whereNotNull('arrival_at');
    }

    /**
     * Scope to filter fully completed jobs.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('completion_confirmed_by_worker', true)
            ->where('completion_confirmed_by_poster', true);
    }

    /**
     * Scope to filter paid jobs.
     */
    public function scopePaid(Builder $query): Builder
    {
        return $query->whereNotNull('payment_confirmed_at');
    }

    /**
     * Scope to filter rated jobs.
     */
    public function scopeRated(Builder $query): Builder
    {
        return $query->whereNotNull('rating');
    }

    /**
     * Scope to filter jobs with disputes.
     */
    public function scopeDisputed(Builder $query): Builder
    {
        return $query->where('has_dispute', true);
    }

    /**
     * Scope by worker.
     */
    public function scopeByWorker(Builder $query, int $workerId): Builder
    {
        return $query->where('worker_id', $workerId);
    }

    /**
     * Scope for pending poster confirmation.
     */
    public function scopePendingPosterConfirmation(Builder $query): Builder
    {
        return $query->where('completion_confirmed_by_worker', true)
            ->where('completion_confirmed_by_poster', false);
    }

    /**
     * Scope for pending payment.
     */
    public function scopePendingPayment(Builder $query): Builder
    {
        return $query->where('completion_confirmed_by_worker', true)
            ->where('completion_confirmed_by_poster', true)
            ->whereNull('payment_confirmed_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Check if worker has arrived.
     */
    public function getHasArrivedAttribute(): bool
    {
        return $this->arrival_at !== null;
    }

    /**
     * Check if arrival is confirmed by poster.
     */
    public function getIsArrivalConfirmedAttribute(): bool
    {
        return $this->arrival_confirmed_by_poster === true;
    }

    /**
     * Check if handover is complete (both confirmed).
     */
    public function getIsHandoverCompleteAttribute(): bool
    {
        return $this->handover_confirmed_by_worker && $this->handover_confirmed_by_poster;
    }

    /**
     * Check if worker has confirmed completion.
     */
    public function getIsWorkerConfirmedAttribute(): bool
    {
        return $this->completion_confirmed_by_worker === true;
    }

    /**
     * Check if poster has confirmed completion.
     */
    public function getIsPosterConfirmedAttribute(): bool
    {
        return $this->completion_confirmed_by_poster === true;
    }

    /**
     * Check if mutually confirmed (NP-025).
     */
    public function getIsMutuallyConfirmedAttribute(): bool
    {
        return $this->is_worker_confirmed && $this->is_poster_confirmed;
    }

    /**
     * Check if payment is confirmed.
     */
    public function getIsPaymentConfirmedAttribute(): bool
    {
        return $this->payment_confirmed_at !== null;
    }

    /**
     * Check if fully verified (confirmed + paid).
     */
    public function getIsFullyVerifiedAttribute(): bool
    {
        return $this->is_mutually_confirmed && $this->is_payment_confirmed;
    }

    /**
     * Check if rated.
     */
    public function getIsRatedAttribute(): bool
    {
        return $this->rating !== null;
    }

    /**
     * Get rating display with stars.
     */
    public function getRatingDisplayAttribute(): ?string
    {
        if (!$this->rating) {
            return null;
        }

        return str_repeat('⭐', $this->rating) . " ({$this->rating}/5)";
    }

    /**
     * Get rating stars only.
     */
    public function getRatingStarsAttribute(): ?string
    {
        if (!$this->rating) {
            return null;
        }

        return str_repeat('⭐', $this->rating);
    }

    /**
     * Get payment method display.
     */
    public function getPaymentMethodDisplayAttribute(): ?string
    {
        return $this->payment_method?->label();
    }

    /**
     * Get time since arrival.
     */
    public function getTimeSinceArrivalAttribute(): ?string
    {
        if (!$this->arrival_at) {
            return null;
        }

        return $this->arrival_at->diffForHumans();
    }

    /**
     * Get arrival time formatted.
     */
    public function getArrivalTimeFormattedAttribute(): ?string
    {
        if (!$this->arrival_at) {
            return null;
        }

        return $this->arrival_at->format('g:i A');
    }

    /**
     * Get verification progress percentage.
     */
    public function getProgressAttribute(): int
    {
        $progress = 0;

        if ($this->has_arrived) $progress += 20;
        if ($this->is_arrival_confirmed) $progress += 10;
        if ($this->is_worker_confirmed) $progress += 20;
        if ($this->is_poster_confirmed) $progress += 20;
        if ($this->is_payment_confirmed) $progress += 20;
        if ($this->is_rated) $progress += 10;

        return $progress;
    }

    /*
    |--------------------------------------------------------------------------
    | Arrival Methods (NP-022, NP-023)
    |--------------------------------------------------------------------------
    */

    /**
     * Record worker arrival with optional photo and location.
     *
     * @param string|null $photoUrl
     * @param float|null $latitude
     * @param float|null $longitude
     */
    public function recordArrival(
        ?string $photoUrl = null,
        ?float $latitude = null,
        ?float $longitude = null
    ): void {
        $this->update([
            'arrival_photo_url' => $photoUrl,
            'arrival_at' => now(),
            'arrival_latitude' => $latitude,
            'arrival_longitude' => $longitude,
        ]);

        // Start the job (transition to in_progress)
        $this->jobPost->start();
    }

    /**
     * Poster confirms worker arrival (NP-023).
     */
    public function confirmArrival(): void
    {
        $this->update([
            'arrival_confirmed_by_poster' => true,
            'arrival_confirmed_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Handover Methods (NP-024)
    |--------------------------------------------------------------------------
    */

    /**
     * Worker confirms handover.
     */
    public function confirmHandoverByWorker(): void
    {
        $this->update([
            'handover_confirmed_by_worker' => true,
            'handover_worker_at' => now(),
        ]);
    }

    /**
     * Poster confirms handover.
     */
    public function confirmHandoverByPoster(): void
    {
        $this->update([
            'handover_confirmed_by_poster' => true,
            'handover_poster_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Completion Methods (NP-025)
    |--------------------------------------------------------------------------
    */

    /**
     * Worker confirms completion.
     */
    public function confirmByWorker(): void
    {
        $this->update([
            'completion_confirmed_by_worker' => true,
            'worker_completed_at' => now(),
        ]);

        $this->checkAndComplete();
    }

    /**
     * Poster confirms completion.
     */
    public function confirmByPoster(): void
    {
        $this->update([
            'completion_confirmed_by_poster' => true,
            'poster_completed_at' => now(),
        ]);

        $this->checkAndComplete();
    }

    /**
     * Check if both confirmed and trigger completion.
     */
    protected function checkAndComplete(): void
    {
        // Refresh to get latest values
        $this->refresh();

        // Don't auto-complete - wait for payment
        // Job completion happens after payment confirmation
    }

    /*
    |--------------------------------------------------------------------------
    | Payment Methods (NP-027)
    |--------------------------------------------------------------------------
    */

    /**
     * Confirm payment.
     *
     * @param PaymentMethod $method
     * @param string|null $reference
     */
    public function confirmPayment(PaymentMethod $method, ?string $reference = null): void
    {
        $this->update([
            'payment_method' => $method,
            'payment_confirmed_at' => now(),
            'payment_reference' => $reference,
        ]);

        // Now we can complete the job
        if ($this->is_mutually_confirmed) {
            $this->jobPost->complete();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Rating Methods (NP-026)
    |--------------------------------------------------------------------------
    */

    /**
     * Rate the worker.
     *
     * @param int $rating 1-5 stars
     * @param string|null $comment Optional review
     */
    public function rateWorker(int $rating, ?string $comment = null): void
    {
        $rating = max(1, min(5, $rating));

        $this->update([
            'rating' => $rating,
            'rating_comment' => $comment,
            'rated_at' => now(),
        ]);

        // Update worker's overall rating (NP-028)
        $this->worker?->updateRating($rating);
    }

    /*
    |--------------------------------------------------------------------------
    | Dispute Methods
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if fully verified.
     */
    public function isFullyVerified(): bool
    {
        return $this->is_fully_verified;
    }

    /**
     * Check if this is a handover job (queue standing).
     */
    public function isHandoverJob(): bool
    {
        $category = $this->jobPost?->category;
        
        if (!$category) {
            return false;
        }

        // Queue standing jobs require handover
        $handoverCategories = ['queue_standing', 'queue'];
        
        return in_array($category->slug ?? '', $handoverCategories) ||
            str_contains(strtolower($category->name_en ?? ''), 'queue');
    }

    /**
     * Convert to status summary.
     */
    public function toStatusSummary(): array
    {
        return [
            'arrived' => $this->has_arrived,
            'arrival_confirmed' => $this->is_arrival_confirmed,
            'arrival_time' => $this->arrival_time_formatted,
            'handover_worker' => $this->handover_confirmed_by_worker,
            'handover_poster' => $this->handover_confirmed_by_poster,
            'worker_confirmed' => $this->is_worker_confirmed,
            'poster_confirmed' => $this->is_poster_confirmed,
            'mutually_confirmed' => $this->is_mutually_confirmed,
            'payment_confirmed' => $this->is_payment_confirmed,
            'payment_method' => $this->payment_method?->value,
            'rating' => $this->rating,
            'has_dispute' => $this->has_dispute,
            'progress' => $this->progress,
            'is_complete' => $this->is_fully_verified,
        ];
    }

    /**
     * Get summary for WhatsApp message.
     */
    public function toMessageSummary(): string
    {
        $lines = [];

        if ($this->has_arrived) {
            $lines[] = "✅ Arrived: {$this->arrival_time_formatted}";
        }

        if ($this->is_worker_confirmed) {
            $lines[] = "✅ Worker confirmed";
        }

        if ($this->is_poster_confirmed) {
            $lines[] = "✅ Poster confirmed";
        }

        if ($this->is_payment_confirmed) {
            $lines[] = "💰 Paid: {$this->payment_method_display}";
        }

        if ($this->is_rated) {
            $lines[] = "⭐ Rating: {$this->rating}/5";
        }

        return implode("\n", $lines);
    }
}