<?php

namespace App\Models;

use App\Enums\FishAlertFrequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fish Alert Batch Model - Batched alert management.
 *
 * For users who prefer morning_only, twice_daily, or weekly_digest alerts.
 *
 * @property int $id
 * @property int $fish_subscription_id
 * @property int $user_id
 * @property FishAlertFrequency $frequency
 * @property \Carbon\Carbon $scheduled_for
 * @property array $catch_ids
 * @property int $catch_count
 * @property string $status
 * @property \Carbon\Carbon|null $sent_at
 * @property \Carbon\Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property string|null $whatsapp_message_id
 * @property bool $was_opened
 * @property \Carbon\Carbon|null $opened_at
 * @property int $clicks_count
 */
class FishAlertBatch extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'fish_subscription_id',
        'user_id',
        'frequency',
        'scheduled_for',
        'catch_ids',
        'catch_count',
        'status',
        'sent_at',
        'failed_at',
        'failure_reason',
        'whatsapp_message_id',
        'was_opened',
        'opened_at',
        'clicks_count',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'frequency' => FishAlertFrequency::class,
        'scheduled_for' => 'datetime',
        'catch_ids' => 'array',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'was_opened' => 'boolean',
        'opened_at' => 'datetime',
    ];

    /**
     * Batch statuses.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the subscription this batch belongs to.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(FishSubscription::class, 'fish_subscription_id');
    }

    /**
     * Get the user who will receive this batch.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the alerts in this batch.
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(FishAlert::class, 'batch_id');
    }

    /**
     * Get the catches in this batch.
     */
    public function catches()
    {
        return FishCatch::whereIn('id', $this->catch_ids ?? [])->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter pending batches.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to filter sent batches.
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_SENT);
    }

    /**
     * Scope to filter batches ready to send.
     */
    public function scopeReadyToSend(Builder $query): Builder
    {
        return $query->pending()
            ->where('scheduled_for', '<=', now())
            ->where('catch_count', '>', 0);
    }

    /**
     * Scope to filter by frequency.
     */
    public function scopeOfFrequency(Builder $query, FishAlertFrequency $frequency): Builder
    {
        return $query->where('frequency', $frequency);
    }

    /**
     * Scope to filter by user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by subscription.
     */
    public function scopeForSubscription(Builder $query, int $subscriptionId): Builder
    {
        return $query->where('fish_subscription_id', $subscriptionId);
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
        return match ($this->status) {
            self::STATUS_PENDING => '⏳ Pending',
            self::STATUS_SENT => '✅ Sent',
            self::STATUS_FAILED => '❌ Failed',
            default => '❓ Unknown',
        };
    }

    /**
     * Get frequency display.
     */
    public function getFrequencyDisplayAttribute(): string
    {
        return $this->frequency->emoji() . ' ' . $this->frequency->label();
    }

    /**
     * Get catches summary.
     */
    public function getCatchesSummaryAttribute(): string
    {
        return $this->catch_count . ' fresh catch' . ($this->catch_count > 1 ? 'es' : '');
    }

    /**
     * Check if batch has content.
     */
    public function getHasContentAttribute(): bool
    {
        return $this->catch_count > 0;
    }

    /**
     * Get click rate.
     */
    public function getClickRateAttribute(): float
    {
        if (!$this->was_opened) {
            return 0;
        }

        if ($this->catch_count === 0) {
            return 0;
        }

        return round(($this->clicks_count / $this->catch_count) * 100, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Add catch to batch.
     */
    public function addCatch(int $catchId): void
    {
        $catchIds = $this->catch_ids ?? [];

        if (!in_array($catchId, $catchIds)) {
            $catchIds[] = $catchId;
            $this->update([
                'catch_ids' => $catchIds,
                'catch_count' => count($catchIds),
            ]);
        }
    }

    /**
     * Remove catch from batch.
     */
    public function removeCatch(int $catchId): void
    {
        $catchIds = $this->catch_ids ?? [];
        $catchIds = array_values(array_diff($catchIds, [$catchId]));

        $this->update([
            'catch_ids' => $catchIds,
            'catch_count' => count($catchIds),
        ]);
    }

    /**
     * Mark as sent.
     */
    public function markSent(string $whatsappMessageId = null): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
            'whatsapp_message_id' => $whatsappMessageId,
        ]);

        // Update associated alerts
        $this->alerts()->pending()->update([
            'status' => FishAlert::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);

        // Update associated alerts
        $this->alerts()->pending()->update([
            'status' => FishAlert::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => $reason,
        ]);
    }

    /**
     * Record batch opened.
     */
    public function recordOpened(): void
    {
        if (!$this->was_opened) {
            $this->update([
                'was_opened' => true,
                'opened_at' => now(),
            ]);
        }
    }

    /**
     * Record click on batch content.
     */
    public function recordClick(): void
    {
        $this->increment('clicks_count');
    }

    /**
     * Get or create pending batch for subscription.
     */
    public static function getOrCreatePending(FishSubscription $subscription): self
    {
        // Find existing pending batch
        $batch = self::forSubscription($subscription->id)
            ->pending()
            ->ofFrequency($subscription->alert_frequency)
            ->first();

        if ($batch) {
            return $batch;
        }

        // Calculate next scheduled time based on frequency
        $scheduledFor = self::calculateNextScheduledTime($subscription->alert_frequency);

        return self::create([
            'fish_subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'frequency' => $subscription->alert_frequency,
            'scheduled_for' => $scheduledFor,
            'catch_ids' => [],
            'catch_count' => 0,
            'status' => self::STATUS_PENDING,
        ]);
    }

    /**
     * Calculate next scheduled time based on frequency.
     */
    protected static function calculateNextScheduledTime(FishAlertFrequency $frequency): \Carbon\Carbon
    {
        $now = now();

        return match ($frequency) {
            FishAlertFrequency::MORNING_ONLY => self::getNextMorningTime($now),
            FishAlertFrequency::TWICE_DAILY => self::getNextTwiceDailyTime($now),
            FishAlertFrequency::WEEKLY_DIGEST => self::getNextWeeklyTime($now),
            default => $now,
        };
    }

    /**
     * Get next morning time (6 AM).
     */
    protected static function getNextMorningTime(\Carbon\Carbon $now): \Carbon\Carbon
    {
        $morning = $now->copy()->setTime(6, 0);

        if ($now->gte($morning)) {
            $morning->addDay();
        }

        return $morning;
    }

    /**
     * Get next twice daily time (6 AM or 4 PM).
     */
    protected static function getNextTwiceDailyTime(\Carbon\Carbon $now): \Carbon\Carbon
    {
        $morning = $now->copy()->setTime(6, 0);
        $afternoon = $now->copy()->setTime(16, 0);

        if ($now->lt($morning)) {
            return $morning;
        }

        if ($now->lt($afternoon)) {
            return $afternoon;
        }

        return $morning->addDay();
    }

    /**
     * Get next weekly time (Sunday 8 AM).
     */
    protected static function getNextWeeklyTime(\Carbon\Carbon $now): \Carbon\Carbon
    {
        $sunday = $now->copy()->next('Sunday')->setTime(8, 0);

        if ($now->isSunday() && $now->lt($now->copy()->setTime(8, 0))) {
            return $now->copy()->setTime(8, 0);
        }

        return $sunday;
    }

    /**
     * Build summary message for batch.
     */
    public function buildSummaryMessage(): string
    {
        $catches = $this->catches();
        $lines = [];

        foreach ($catches as $catch) {
            $fishName = $catch->fishType?->display_name ?? '🐟 Fish';
            $price = $catch->price_display;
            $seller = $catch->seller?->business_name ?? 'Seller';
            $freshness = $catch->freshness_display;

            $lines[] = "• {$fishName} @ {$price}\n  📍 {$seller} • {$freshness}";
        }

        return implode("\n\n", $lines);
    }
}
