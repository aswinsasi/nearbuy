<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pending Shop Notification.
 *
 * Stores notifications for shops with non-immediate preferences.
 * Processed by SendBatchedShopNotifications job.
 *
 * @property int $id
 * @property int $shop_id
 * @property int $request_id
 * @property float $distance_km
 * @property \Carbon\Carbon $created_at
 *
 * @property-read Shop $shop
 * @property-read ProductRequest $request
 *
 * @srs-ref FR-PRD-12 - Batch for 2hours/twice_daily/daily shops
 */
class PendingShopNotification extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'shop_id',
        'request_id',
        'distance_km',
        'created_at',
    ];

    protected $casts = [
        'distance_km' => 'float',
        'created_at' => 'datetime',
    ];

    /**
     * Boot: set created_at.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $model) {
            if (empty($model->created_at)) {
                $model->created_at = now();
            }
        });
    }

    /**
     * Get the shop.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get the product request.
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class, 'request_id');
    }

    /**
     * Clean up old/expired notifications.
     */
    public static function cleanupExpired(): int
    {
        // Delete notifications older than 24 hours or for expired requests
        return self::query()
            ->where('created_at', '<', now()->subHours(24))
            ->orWhereHas('request', function ($q) {
                $q->where('expires_at', '<', now());
            })
            ->delete();
    }
}