<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Model for tracking processed webhook messages.
 *
 * Used for deduplication — WhatsApp sometimes sends the same
 * webhook twice, and we need to skip duplicates.
 *
 * @property int $id
 * @property string $message_id
 * @property \Carbon\Carbon $processed_at
 * @property \Carbon\Carbon $created_at
 */
class ProcessedWebhook extends Model
{
    /**
     * Disable updated_at since we only create records.
     */
    public const UPDATED_AT = null;

    /**
     * The table associated with the model.
     */
    protected $table = 'processed_webhooks';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'message_id',
        'processed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'processed_at' => 'datetime',
    ];

    /**
     * Check if a message has already been processed.
     */
    public static function isProcessed(string $messageId): bool
    {
        return static::where('message_id', $messageId)->exists();
    }

    /**
     * Mark a message as processed.
     */
    public static function markProcessed(string $messageId): static
    {
        return static::create([
            'message_id' => $messageId,
            'processed_at' => now(),
        ]);
    }

    /**
     * Clean up old records (older than given days).
     */
    public static function cleanup(int $daysOld = 7): int
    {
        return static::where('created_at', '<', now()->subDays($daysOld))->delete();
    }
}