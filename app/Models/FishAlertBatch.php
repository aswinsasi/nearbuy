<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FishAlertFrequency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Fish Alert Batch Model.
 *
 * Tracks batched alerts for subscribers with scheduled preferences
 * (Early Morning, Morning, Twice Daily).
 *
 * @property int $id
 * @property string $batch_id - Unique batch identifier
 * @property int $fish_subscription_id
 * @property int $user_id
 * @property FishAlertFrequency $frequency
 * @property \Carbon\Carbon $scheduled_for
 * @property array|null $catch_ids - Array of catch IDs in batch
 * @property int $alert_count - Total alerts in batch
 * @property int $sent_count - Successfully sent
 * @property int $failed_count - Failed to send
 * @property string $status - pending, processing, sent, failed
 * @property \Carbon\Carbon|null $sent_at
 * @property string|null $error_message
 *
 * @srs-ref PM-020 Respect alert time preferences
 */
class FishAlertBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'fish_subscription_id',
        'user_id',
        'frequency',
        'scheduled_for',
        'catch_ids',
        'alert_count',
        'sent_count',
        'failed_count',
        'status',
        'sent_at',
        'error_message',
    ];

    protected $casts = [
        'frequency' => FishAlertFrequency::class,
        'scheduled_for' => 'datetime',
        'catch_ids' => 'array',
        'sent_at' => 'datetime',
    ];

    /**
     * Status constants.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(FishSubscription::class, 'fish_subscription_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(FishAlert::class, 'batch_id');
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

    public function scopeReadyToSend(Builder $query): Builder
    {
        return $query->pending()
            ->where('scheduled_for', '<=', now())
            ->where('alert_count', '>', 0);
    }

    public function scopeForFrequency(Builder $query, FishAlertFrequency $frequency): Builder
    {
        return $query->where('frequency', $frequency);
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

    public function getStatusDisplayAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => '⏳ Pending',
            self::STATUS_PROCESSING => '🔄 Processing',
            self::STATUS_SENT => '✅ Sent',
            self::STATUS_FAILED => '❌ Failed',
            default => '❓ Unknown',
        };
    }

    public function getCatchCountAttribute(): int
    {
        return count($this->catch_ids ?? []);
    }

    public function getSuccessRateAttribute(): float
    {
        if ($this->alert_count === 0) return 0;
        return round(($this->sent_count / $this->alert_count) * 100, 1);
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
        $ids = $this->catch_ids ?? [];
        if (!in_array($catchId, $ids)) {
            $ids[] = $catchId;
            $this->update([
                'catch_ids' => $ids,
                'alert_count' => count($ids),
            ]);
        }
    }

    /**
     * Mark as processing.
     */
    public function markProcessing(): void
    {
        $this->update(['status' => self::STATUS_PROCESSING]);
    }

    /**
     * Mark as sent.
     */
    public function markSent(int $sentCount = null, int $failedCount = null): void
    {
        $this->update([
            'status' => self::STATUS_SENT,
            'sent_at' => now(),
            'sent_count' => $sentCount ?? $this->alert_count,
            'failed_count' => $failedCount ?? 0,
        ]);
    }

    /**
     * Mark as failed.
     */
    public function markFailed(string $error): void
    {
        $this->update([
            'status' => self::STATUS_FAILED,
            'error_message' => substr($error, 0, 500),
            'failed_count' => $this->alert_count,
        ]);
    }

    /**
     * Get catches in this batch.
     */
    public function getCatches(): \Illuminate\Support\Collection
    {
        if (empty($this->catch_ids)) {
            return collect();
        }
        return FishCatch::whereIn('id', $this->catch_ids)
            ->with(['fishType', 'seller'])
            ->get();
    }

    /**
     * Get or create pending batch for subscription.
     */
    public static function getOrCreateForSubscription(FishSubscription $subscription): self
    {
        // Find existing pending batch
        $batch = self::query()
            ->where('fish_subscription_id', $subscription->id)
            ->where('frequency', $subscription->alert_frequency)
            ->pending()
            ->first();

        if ($batch) {
            return $batch;
        }

        // Create new batch
        return self::create([
            'batch_id' => 'BAT-' . strtoupper(Str::random(8)),
            'fish_subscription_id' => $subscription->id,
            'user_id' => $subscription->user_id,
            'frequency' => $subscription->alert_frequency,
            'scheduled_for' => $subscription->alert_frequency->nextScheduledTime(),
            'catch_ids' => [],
            'alert_count' => 0,
            'sent_count' => 0,
            'failed_count' => 0,
            'status' => self::STATUS_PENDING,
        ]);
    }

    /**
     * Build summary message.
     */
    public function buildSummaryMessage(): string
    {
        $catches = $this->getCatches();
        
        if ($catches->isEmpty()) {
            return "No fresh fish available.";
        }

        $lines = ["🐟 *{$catches->count()} Fresh Catches!*\n"];

        foreach ($catches->take(5) as $catch) {
            $fish = $catch->fishType?->display_name ?? '🐟 Fish';
            $price = $catch->price_per_kg ? '₹' . (int) $catch->price_per_kg . '/kg' : '';
            $seller = $catch->seller?->business_name ?? '';
            $lines[] = "• {$fish} {$price}\n  📍 {$seller}";
        }

        if ($catches->count() > 5) {
            $lines[] = "\n_+" . ($catches->count() - 5) . " more..._";
        }

        return implode("\n", $lines);
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
            if (empty($model->batch_id)) {
                $model->batch_id = 'BAT-' . strtoupper(Str::random(8));
            }
        });
    }
}