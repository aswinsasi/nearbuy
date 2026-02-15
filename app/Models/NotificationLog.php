<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * NotificationLog Model - Tracks ALL WhatsApp message send attempts.
 *
 * Logs every send attempt with:
 * - Success/failure status
 * - Duration in milliseconds
 * - Retry attempt number
 * - Queue used
 * - Error details if failed
 *
 * Used for:
 * - Debugging delivery issues
 * - Analytics and reporting
 * - Rate limit monitoring
 * - SLA compliance tracking
 *
 * @srs-ref NFR-R-02 - Track retry attempts
 * @module Notifications
 *
 * @property int $id
 * @property string $phone
 * @property string $type Message type (text, buttons, list, etc.)
 * @property string|null $notification_type Business type (flash_deal, product_request, etc.)
 * @property string $status sent|failed|failed_permanently
 * @property string|null $message_id WhatsApp message ID
 * @property string|null $error Error message
 * @property float|null $duration_ms Send duration
 * @property int $attempt Retry attempt number
 * @property string|null $queue Queue used
 * @property array|null $context Additional context data
 * @property Carbon $created_at
 */
class NotificationLog extends Model
{
    use HasFactory;

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * Disable updated_at since logs are immutable.
     */
    const UPDATED_AT = null;

    protected $fillable = [
        'phone',
        'type',
        'notification_type',
        'status',
        'message_id',
        'error',
        'duration_ms',
        'attempt',
        'queue',
        'context',
    ];

    protected $casts = [
        'duration_ms' => 'float',
        'attempt' => 'integer',
        'context' => 'array',
    ];

    // =========================================================================
    // SCOPES
    // =========================================================================

    /**
     * Successful sends.
     */
    public function scopeSent($query)
    {
        return $query->where('status', 'sent');
    }

    /**
     * Failed sends.
     */
    public function scopeFailed($query)
    {
        return $query->whereIn('status', ['failed', 'failed_permanently']);
    }

    /**
     * Permanently failed.
     */
    public function scopeFailedPermanently($query)
    {
        return $query->where('status', 'failed_permanently');
    }

    /**
     * By phone.
     */
    public function scopeForPhone($query, string $phone)
    {
        return $query->where('phone', $phone);
    }

    /**
     * By notification type.
     */
    public function scopeOfType($query, string $type)
    {
        return $query->where('notification_type', $type);
    }

    /**
     * By queue.
     */
    public function scopeOnQueue($query, string $queue)
    {
        return $query->where('queue', $queue);
    }

    /**
     * Today's logs.
     */
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    /**
     * Last N hours.
     */
    public function scopeLastHours($query, int $hours)
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /**
     * Slow sends (> threshold ms).
     */
    public function scopeSlow($query, float $thresholdMs = 5000)
    {
        return $query->where('duration_ms', '>', $thresholdMs);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Get masked phone.
     */
    public function getMaskedPhoneAttribute(): string
    {
        $phone = $this->phone;
        return strlen($phone) < 6 ? $phone : substr($phone, 0, 3) . '****' . substr($phone, -3);
    }

    /**
     * Get status with emoji.
     */
    public function getStatusDisplayAttribute(): string
    {
        return match ($this->status) {
            'sent' => '✅ Sent',
            'failed' => '⚠️ Failed',
            'failed_permanently' => '❌ Failed',
            default => '❓ Unknown',
        };
    }

    /**
     * Get duration display.
     */
    public function getDurationDisplayAttribute(): string
    {
        if (!$this->duration_ms) {
            return 'N/A';
        }

        if ($this->duration_ms < 1000) {
            return round($this->duration_ms) . ' ms';
        }

        return round($this->duration_ms / 1000, 2) . ' s';
    }

    // =========================================================================
    // STATISTICS
    // =========================================================================

    /**
     * Get statistics for a time period.
     */
    public static function getStats(?Carbon $from = null, ?Carbon $to = null): array
    {
        $query = self::query();

        if ($from) {
            $query->where('created_at', '>=', $from);
        }

        if ($to) {
            $query->where('created_at', '<=', $to);
        }

        $total = $query->count();
        $sent = (clone $query)->sent()->count();
        $failed = (clone $query)->failed()->count();

        return [
            'total' => $total,
            'sent' => $sent,
            'failed' => $failed,
            'success_rate' => $total > 0 ? round(($sent / $total) * 100, 2) : 0,
            'avg_duration_ms' => round((clone $query)->sent()->avg('duration_ms') ?? 0, 2),
            'by_type' => (clone $query)->selectRaw('notification_type, COUNT(*) as count')
                ->groupBy('notification_type')
                ->pluck('count', 'notification_type')
                ->toArray(),
            'by_queue' => (clone $query)->selectRaw('queue, COUNT(*) as count')
                ->groupBy('queue')
                ->pluck('count', 'queue')
                ->toArray(),
            'by_status' => (clone $query)->selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status')
                ->toArray(),
        ];
    }

    /**
     * Get hourly breakdown for today.
     */
    public static function getTodayHourly(): array
    {
        return self::today()
            ->selectRaw('HOUR(created_at) as hour, status, COUNT(*) as count')
            ->groupBy('hour', 'status')
            ->get()
            ->groupBy('hour')
            ->map(fn($items) => $items->pluck('count', 'status')->toArray())
            ->toArray();
    }

    /**
     * Get error summary.
     */
    public static function getErrorSummary(int $hours = 24): array
    {
        return self::failed()
            ->lastHours($hours)
            ->selectRaw('error, COUNT(*) as count')
            ->groupBy('error')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'error')
            ->toArray();
    }

    /**
     * Cleanup old logs.
     */
    public static function cleanup(int $daysToKeep = 30): int
    {
        return self::where('created_at', '<', now()->subDays($daysToKeep))->delete();
    }
}