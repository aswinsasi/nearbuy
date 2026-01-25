<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fish Alert Model - Track alerts sent to customers.
 *
 * @property int $id
 * @property int $fish_catch_id
 * @property int $fish_subscription_id
 * @property int $user_id
 * @property string $alert_type
 * @property string $status
 * @property \Carbon\Carbon|null $queued_at
 * @property \Carbon\Carbon|null $sent_at
 * @property \Carbon\Carbon|null $delivered_at
 * @property \Carbon\Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property string|null $whatsapp_message_id
 * @property int|null $batch_id
 * @property bool $is_batched
 * @property bool $was_clicked
 * @property \Carbon\Carbon|null $clicked_at
 * @property string|null $click_action
 * @property float|null $distance_km
 * @property \Carbon\Carbon|null $scheduled_for
 *
 * @srs-ref Section 2.3.4 - Alert Delivery
 */
class FishAlert extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'fish_catch_id',
        'fish_subscription_id',
        'user_id',
        'alert_type',
        'status',
        'queued_at',
        'sent_at',
        'delivered_at',
        'failed_at',
        'failure_reason',
        'whatsapp_message_id',
        'batch_id',
        'is_batched',
        'was_clicked',
        'clicked_at',
        'click_action',
        'distance_km',
        'scheduled_for',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'failed_at' => 'datetime',
        'is_batched' => 'boolean',
        'was_clicked' => 'boolean',
        'clicked_at' => 'datetime',
        'distance_km' => 'decimal:2',
        'scheduled_for' => 'datetime',
    ];

    /**
     * Alert types.
     */
    public const TYPE_NEW_CATCH = 'new_catch';
    public const TYPE_LOW_STOCK = 'low_stock';
    public const TYPE_PRICE_DROP = 'price_drop';

    /**
     * Alert statuses.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    /**
     * Click actions.
     */
    public const ACTION_COMING = 'coming';
    public const ACTION_MESSAGE = 'message';
    public const ACTION_LOCATION = 'location';
    public const ACTION_DISMISS = 'dismiss';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the catch this alert is for.
     */
    public function catch(): BelongsTo
    {
        return $this->belongsTo(FishCatch::class, 'fish_catch_id');
    }

    /**
     * Get the subscription that triggered this alert.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(FishSubscription::class, 'fish_subscription_id');
    }

    /**
     * Get the user who received this alert.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the batch this alert belongs to.
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(FishAlertBatch::class, 'batch_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter pending alerts.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    /**
     * Scope to filter queued alerts.
     */
    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    /**
     * Scope to filter sent alerts.
     */
    public function scopeSent(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    /**
     * Scope to filter failed alerts.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    /**
     * Scope to filter scheduled alerts ready to send.
     */
    public function scopeReadyToSend(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED)
            ->where(function ($q) {
                $q->whereNull('scheduled_for')
                    ->orWhere('scheduled_for', '<=', now());
            });
    }

    /**
     * Scope to filter by alert type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('alert_type', $type);
    }

    /**
     * Scope to filter by user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter by catch.
     */
    public function scopeForCatch(Builder $query, int $catchId): Builder
    {
        return $query->where('fish_catch_id', $catchId);
    }

    /**
     * Scope to filter clicked alerts.
     */
    public function scopeClicked(Builder $query): Builder
    {
        return $query->where('was_clicked', true);
    }

    /**
     * Scope to filter non-batched alerts.
     */
    public function scopeImmediate(Builder $query): Builder
    {
        return $query->where('is_batched', false);
    }

    /**
     * Scope to filter batched alerts.
     */
    public function scopeBatched(Builder $query): Builder
    {
        return $query->where('is_batched', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get alert type display.
     */
    public function getTypeDisplayAttribute(): string
    {
        return match ($this->alert_type) {
            self::TYPE_NEW_CATCH => '🐟 New Catch',
            self::TYPE_LOW_STOCK => '⚠️ Low Stock',
            self::TYPE_PRICE_DROP => '💰 Price Drop',
            default => '📢 Alert',
        };
    }

    /**
     * Get status display.
     */
    public function getStatusDisplayAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => '⏳ Pending',
            self::STATUS_QUEUED => '📤 Queued',
            self::STATUS_SENT => '✅ Sent',
            self::STATUS_DELIVERED => '✅ Delivered',
            self::STATUS_FAILED => '❌ Failed',
            default => '❓ Unknown',
        };
    }

    /**
     * Get click action display.
     */
    public function getClickActionDisplayAttribute(): ?string
    {
        if (!$this->click_action) {
            return null;
        }

        return match ($this->click_action) {
            self::ACTION_COMING => '🏃 Coming',
            self::ACTION_MESSAGE => '💬 Messaged',
            self::ACTION_LOCATION => '📍 Got Location',
            self::ACTION_DISMISS => '❌ Dismissed',
            default => $this->click_action,
        };
    }

    /**
     * Get distance display.
     */
    public function getDistanceDisplayAttribute(): ?string
    {
        if (!$this->distance_km) {
            return null;
        }

        if ($this->distance_km < 1) {
            return round($this->distance_km * 1000) . 'm';
        }

        return round($this->distance_km, 1) . ' km';
    }

    /**
     * Check if alert was successful.
     */
    public function getIsSuccessfulAttribute(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Mark as queued.
     */
    public function markQueued(\Carbon\Carbon $scheduledFor = null): void
    {
        $this->update([
            'status' => self::STATUS_QUEUED,
            'queued_at' => now(),
            'scheduled_for' => $scheduledFor,
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

        $this->subscription?->recordAlertReceived();
        $this->catch?->incrementAlertsSent();
    }

    /**
     * Mark as delivered.
     */
    public function markDelivered(): void
    {
        $this->update([
            'status' => self::STATUS_DELIVERED,
            'delivered_at' => now(),
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
    }

    /**
     * Record click action.
     */
    public function recordClick(string $action): void
    {
        $this->update([
            'was_clicked' => true,
            'clicked_at' => now(),
            'click_action' => $action,
        ]);

        $this->subscription?->recordAlertClicked();

        // Update catch stats based on action
        if ($action === self::ACTION_COMING) {
            $this->catch?->incrementComingCount();
        } elseif ($action === self::ACTION_MESSAGE) {
            $this->catch?->incrementMessageCount();
        }
    }

    /**
     * Create alert for a catch and subscription.
     */
    public static function createForCatch(
        FishCatch $catch,
        FishSubscription $subscription,
        string $alertType = self::TYPE_NEW_CATCH,
        bool $isBatched = false,
        \Carbon\Carbon $scheduledFor = null
    ): self {
        $distance = $subscription->calculateDistanceTo(
            $catch->catch_latitude,
            $catch->catch_longitude
        );

        return self::create([
            'fish_catch_id' => $catch->id,
            'fish_subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'alert_type' => $alertType,
            'status' => $isBatched ? self::STATUS_PENDING : self::STATUS_QUEUED,
            'queued_at' => $isBatched ? null : now(),
            'is_batched' => $isBatched,
            'distance_km' => $distance,
            'scheduled_for' => $scheduledFor,
        ]);
    }

    /**
     * Check if alert already exists for catch and subscription.
     */
    public static function existsForCatchAndSubscription(int $catchId, int $subscriptionId): bool
    {
        return self::where('fish_catch_id', $catchId)
            ->where('fish_subscription_id', $subscriptionId)
            ->exists();
    }
}
