<?php

namespace App\Models;

use App\Enums\NotificationFrequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * NotificationBatch Model
 *
 * Represents a batch of notifications to be sent to a shop.
 *
 * @property int $id
 * @property int $shop_id
 * @property NotificationFrequency $frequency
 * @property string $status pending|sent|skipped|failed
 * @property array $items JSON array of notification items
 * @property \Carbon\Carbon $scheduled_for
 * @property \Carbon\Carbon|null $sent_at
 * @property string|null $error
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read Shop $shop
 */
class NotificationBatch extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'shop_id',
        'frequency',
        'status',
        'items',
        'scheduled_for',
        'sent_at',
        'error',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'frequency' => NotificationFrequency::class,
        'items' => 'array',
        'scheduled_for' => 'datetime',
        'sent_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'items' => '[]',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the shop this batch belongs to.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Accessors & Mutators
    |--------------------------------------------------------------------------
    */

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

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Add an item to the batch.
     */
    public function addItem(array $item): self
    {
        $items = $this->items ?? [];
        $items[] = array_merge($item, [
            'added_at' => now()->toIso8601String(),
        ]);

        $this->update(['items' => $items]);

        return $this;
    }

    /**
     * Mark batch as sent.
     */
    public function markAsSent(): self
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

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
}