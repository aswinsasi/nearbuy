<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fish Alert Model - Track alerts sent to customers.
 *
 * @property int $id
 * @property int $fish_catch_id
 * @property int $fish_subscription_id
 * @property int $user_id
 * @property string $alert_type - new_catch, low_stock, sold_out
 * @property string $status - pending, queued, sent, delivered, failed
 * @property float|null $distance_km - Distance from customer (for sorting)
 * @property \Carbon\Carbon|null $scheduled_for - PM-020 time preference
 * @property \Carbon\Carbon|null $queued_at
 * @property \Carbon\Carbon|null $sent_at
 * @property \Carbon\Carbon|null $failed_at
 * @property string|null $failure_reason
 * @property string|null $whatsapp_message_id
 * @property string|null $click_action - coming, message, location, dismiss
 * @property \Carbon\Carbon|null $clicked_at
 *
 * @srs-ref PM-016 to PM-020 Alert requirements
 */
class FishAlert extends Model
{
    use HasFactory;

    protected $fillable = [
        'fish_catch_id',
        'fish_subscription_id',
        'user_id',
        'alert_type',
        'status',
        'distance_km',
        'scheduled_for',
        'queued_at',
        'sent_at',
        'failed_at',
        'failure_reason',
        'whatsapp_message_id',
        'click_action',
        'clicked_at',
        'batch_id',
    ];

    protected $casts = [
        'distance_km' => 'decimal:2',
        'scheduled_for' => 'datetime',
        'queued_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
        'clicked_at' => 'datetime',
    ];

    // Alert types
    public const TYPE_NEW_CATCH = 'new_catch';
    public const TYPE_LOW_STOCK = 'low_stock';
    public const TYPE_SOLD_OUT = 'sold_out';

    // Statuses
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_FAILED = 'failed';

    // Click actions (PM-018)
    public const ACTION_COMING = 'coming';
    public const ACTION_MESSAGE = 'message';
    public const ACTION_LOCATION = 'location';
    public const ACTION_DISMISS = 'dismiss';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function catch(): BelongsTo
    {
        return $this->belongsTo(FishCatch::class, 'fish_catch_id');
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(FishSubscription::class, 'fish_subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function scopeQueued(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_QUEUED);
    }

    public function scopeSent(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    /**
     * Ready to send - queued and scheduled time passed.
     * @srs-ref PM-020 Respect time preferences
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
     * Order by nearest first (for priority delivery).
     */
    public function scopeNearestFirst(Builder $query): Builder
    {
        return $query->orderBy('distance_km', 'asc');
    }

    public function scopeForCatch(Builder $query, int $catchId): Builder
    {
        return $query->where('fish_catch_id', $catchId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getDistanceDisplayAttribute(): string
    {
        if (!$this->distance_km) return '';

        return $this->distance_km < 1
            ? round($this->distance_km * 1000) . 'm'
            : round($this->distance_km, 1) . ' km';
    }

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
     * Mark as queued with optional scheduled time.
     * @srs-ref PM-020 Queue for preferred time window
     */
    public function markQueued(?\Carbon\Carbon $scheduledFor = null): void
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
    public function markSent(?string $messageId = null): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
            'whatsapp_message_id' => $messageId,
        ]);

        // Increment catch alert count
        $this->catch?->incrementAlertsSent();
    }

    /**
     * Mark as failed.
     */
    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'failed_at' => now(),
            'failure_reason' => substr($reason, 0, 255),
        ]);
    }

    /**
     * Record click action.
     * @srs-ref PM-018 Button actions
     * @srs-ref PM-019 Increment coming count for social proof
     */
    public function recordClick(string $action): void
    {
        $this->update([
            'click_action' => $action,
            'clicked_at' => now(),
        ]);

        // PM-019: Increment coming count for social proof
        if ($action === self::ACTION_COMING) {
            $this->catch?->incrementComing();
        }
    }

    /**
     * Create alert for catch and subscription.
     */
    public static function createForCatch(
        FishCatch $catch,
        FishSubscription $subscription,
        string $type = self::TYPE_NEW_CATCH,
        ?\Carbon\Carbon $scheduledFor = null
    ): self {
        // Calculate distance for priority sorting
        $distance = null;
        if ($subscription->latitude && $subscription->longitude) {
            $catchLat = $catch->seller?->latitude ?? 0;
            $catchLng = $catch->seller?->longitude ?? 0;
            $distance = self::calculateDistance(
                $subscription->latitude,
                $subscription->longitude,
                $catchLat,
                $catchLng
            );
        }

        return self::create([
            'fish_catch_id' => $catch->id,
            'fish_subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'alert_type' => $type,
            'status' => self::STATUS_QUEUED,
            'queued_at' => now(),
            'distance_km' => $distance,
            'scheduled_for' => $scheduledFor,
        ]);
    }

    /**
     * Check if alert exists for catch + user.
     */
    public static function existsForCatchAndUser(int $catchId, int $userId): bool
    {
        return self::where('fish_catch_id', $catchId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Calculate distance between two points (Haversine formula).
     */
    protected static function calculateDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $earthRadius = 6371; // km

        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) * sin($dLng / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}