<?php

namespace App\Models;

use App\Enums\NotificationFrequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * NotificationBatch Model
 *
 * Represents a batch of notifications to be sent to a shop.
 *
 * UPDATED: Added tracking fields for better statistics and debugging:
 * - batch_type, total_items, sent_count, failed_count
 * - message_id, duration_ms
 *
 * @property int $id
 * @property int $shop_id
 * @property NotificationFrequency $frequency
 * @property string|null $batch_type
 * @property string $status pending|processing|sent|skipped|failed
 * @property array $items JSON array of notification items
 * @property int $total_items
 * @property int $sent_count
 * @property int $failed_count
 * @property Carbon $scheduled_for
 * @property Carbon|null $sent_at
 * @property string|null $message_id
 * @property float|null $duration_ms
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Shop $shop
 * @property-read int $item_count
 * @property-read bool $is_pending
 * @property-read bool $is_sent
 * @property-read float $success_rate
 */
class NotificationBatch extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'shop_id',
        'frequency',
        'batch_type',
        'status',
        'items',
        'total_items',
        'sent_count',
        'failed_count',
        'scheduled_for',
        'sent_at',
        'message_id',
        'duration_ms',
        'error',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'frequency' => NotificationFrequency::class,
        'items' => 'array',
        'total_items' => 'integer',
        'sent_count' => 'integer',
        'failed_count' => 'integer',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
        'duration_ms' => 'float',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'status' => 'pending',
        'items' => '[]',
        'total_items' => 0,
        'sent_count' => 0,
        'failed_count' => 0,
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the shop this batch belongs to.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Scope to pending batches.
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope to batches ready to send.
     */
    public function scopeReadyToSend($query)
    {
        return $query->pending()
            ->where('scheduled_for', '<=', now());
    }

    /**
     * Scope by frequency.
     */
    public function scopeForFrequency($query, NotificationFrequency $frequency)
    {
        return $query->where('frequency', $frequency);
    }

    /**
     * Scope by shop.
     */
    public function scopeForShop($query, int $shopId)
    {
        return $query->where('shop_id', $shopId);
    }

    /**
     * Scope to sent batches.
     */
    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    /**
     * Scope to failed batches.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to today's batches.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Get the item count.
     */
    public function getItemCountAttribute(): int
    {
        return count($this->items ?? []);
    }

    /**
     * Check if batch is pending.
     */
    public function getIsPendingAttribute(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if batch was sent.
     */
    public function getIsSentAttribute(): bool
    {
        return $this->status === 'sent';
    }

    /**
     * Check if batch failed.
     */
    public function getIsFailedAttribute(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Get success rate.
     */
    public function getSuccessRateAttribute(): float
    {
        $total = $this->total_items ?: $this->item_count;
        if ($total === 0) {
            return 0;
        }
        return round(($this->sent_count / $total) * 100, 2);
    }

    /**
     * Get status with emoji.
     */
    public function getStatusDisplayAttribute(): string
    {
        return match ($this->status) {
            'pending' => '⏳ Pending',
            'processing' => '🔄 Processing',
            'sent' => '✅ Sent',
            'skipped' => '⏭️ Skipped',
            'failed' => '❌ Failed',
            default => '❓ Unknown',
        };
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    /**
     * Add an item to the batch.
     */
    public function addItem(array $item): self
    {
        $items = $this->items ?? [];
        $items[] = array_merge($item, [
            'added_at' => now()->toIso8601String(),
        ]);

        $this->update([
            'items' => $items,
            'total_items' => count($items),
        ]);

        return $this;
    }

    /**
     * Add multiple items.
     */
    public function addItems(array $newItems): self
    {
        $items = $this->items ?? [];

        foreach ($newItems as $item) {
            $items[] = array_merge($item, [
                'added_at' => now()->toIso8601String(),
            ]);
        }

        $this->update([
            'items' => $items,
            'total_items' => count($items),
        ]);

        return $this;
    }

    /**
     * Mark batch as processing.
     */
    public function markAsProcessing(): self
    {
        $this->update(['status' => 'processing']);
        return $this;
    }

    /**
     * Mark batch as sent.
     */
    public function markAsSent(?string $messageId = null, ?float $durationMs = null): self
    {
        $data = [
            'status' => 'sent',
            'sent_at' => now(),
        ];

        // Add new tracking fields if provided
        if ($messageId !== null) {
            $data['message_id'] = $messageId;
        }
        if ($durationMs !== null) {
            $data['duration_ms'] = $durationMs;
        }

        // Set sent_count to total if not already set
        $total = $this->total_items ?: $this->item_count;
        $data['total_items'] = $total;
        $data['sent_count'] = $total;
        $data['failed_count'] = 0;

        $this->update($data);

        return $this;
    }

    /**
     * Mark batch as failed.
     */
    public function markAsFailed(string $error): self
    {
        $this->update([
            'status' => 'failed',
            'error' => $error,
            'failed_count' => ($this->failed_count ?? 0) + 1,
        ]);

        return $this;
    }

    /**
     * Mark batch as skipped.
     */
    public function markAsSkipped(string $reason): self
    {
        $this->update([
            'status' => 'skipped',
            'error' => $reason,
        ]);

        return $this;
    }

    /**
     * Update statistics.
     */
    public function updateStats(int $sent, int $failed): self
    {
        $this->update([
            'sent_count' => $sent,
            'failed_count' => $failed,
        ]);

        return $this;
    }

    // =========================================================================
    // STATIC FACTORY METHODS
    // =========================================================================

    /**
     * Create or get pending batch for a shop.
     */
    public static function getOrCreateForShop(
        int $shopId,
        NotificationFrequency $frequency
    ): self {
        $batch = self::where('shop_id', $shopId)
            ->where('frequency', $frequency)
            ->where('status', 'pending')
            ->first();

        if ($batch) {
            return $batch;
        }

        return self::create([
            'shop_id' => $shopId,
            'frequency' => $frequency,
            'status' => 'pending',
            'scheduled_for' => self::calculateScheduledTime($frequency),
        ]);
    }

    /**
     * Calculate scheduled time based on frequency.
     */
    public static function calculateScheduledTime(NotificationFrequency $frequency): Carbon
    {
        return match ($frequency) {
            NotificationFrequency::IMMEDIATE => now(),
            NotificationFrequency::EVERY_2_HOURS => self::nextEvenHour(),
            NotificationFrequency::TWICE_DAILY => self::nextScheduledTime([9, 17]),
            NotificationFrequency::DAILY => self::nextScheduledTime([9]),
            default => now(),
        };
    }

    /**
     * Get next even hour.
     */
    protected static function nextEvenHour(): Carbon
    {
        $next = now()->copy()->addHour()->startOfHour();
        if ($next->hour % 2 !== 0) {
            $next->addHour();
        }
        return $next;
    }

    /**
     * Get next scheduled time from array of hours.
     */
    protected static function nextScheduledTime(array $hours): Carbon
    {
        $now = now();
        $currentHour = (int) $now->format('H');

        foreach ($hours as $hour) {
            if ($currentHour < $hour) {
                return $now->copy()->setTime($hour, 0, 0);
            }
        }

        return $now->copy()->addDay()->setTime($hours[0], 0, 0);
    }

    // =========================================================================
    // STATISTICS
    // =========================================================================

    /**
     * Get statistics for a shop.
     */
    public static function getShopStats(int $shopId): array
    {
        $query = self::where('shop_id', $shopId);

        return [
            'total_batches' => $query->count(),
            'sent' => (clone $query)->sent()->count(),
            'failed' => (clone $query)->failed()->count(),
            'pending' => (clone $query)->pending()->count(),
            'total_items_sent' => (clone $query)->sent()->sum('sent_count'),
            'avg_duration_ms' => (clone $query)->sent()->avg('duration_ms'),
        ];
    }

    /**
     * Get global statistics.
     */
    public static function getGlobalStats(): array
    {
        return [
            'total_batches' => self::count(),
            'sent_today' => self::today()->sent()->count(),
            'failed_today' => self::today()->failed()->count(),
            'pending' => self::pending()->count(),
            'by_frequency' => self::selectRaw('frequency, COUNT(*) as count')
                ->groupBy('frequency')
                ->pluck('count', 'frequency')
                ->toArray(),
        ];
    }
}