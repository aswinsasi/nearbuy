<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Fish Catch Response Model - Customer responses to catches.
 *
 * @srs-ref PM-019 - Display live claim count as social proof
 * @srs-ref PM-023 - Notify claimed customers when sold out
 */
class FishCatchResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'fish_catch_id',
        'user_id',
        'fish_alert_id',
        'response_type',
        'estimated_arrival_mins',
        'arrived_at',
        'did_purchase',
        'message_content',
        'seller_replied_at',
        'latitude',
        'longitude',
        'distance_km',
        'rating',
        'review',
        'rated_at',
        'status',
        'cancelled_at',
        'notified_sold_out', // NEW: Track if user was notified of sold out
    ];

    protected $casts = [
        'arrived_at' => 'datetime',
        'did_purchase' => 'boolean',
        'seller_replied_at' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'distance_km' => 'decimal:2',
        'rated_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'notified_sold_out' => 'boolean',
    ];

    // Response types
    public const TYPE_COMING = 'coming';
    public const TYPE_MESSAGE = 'message';
    public const TYPE_NOT_TODAY = 'not_today';
    public const TYPE_LOCATION_REQUEST = 'location_request';

    // Response statuses
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function catch(): BelongsTo
    {
        return $this->belongsTo(FishCatch::class, 'fish_catch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alert(): BelongsTo
    {
        return $this->belongsTo(FishAlert::class, 'fish_alert_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('response_type', $type);
    }

    public function scopeComing(Builder $query): Builder
    {
        return $query->where('response_type', self::TYPE_COMING);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeForCatch(Builder $query, int $catchId): Builder
    {
        return $query->where('fish_catch_id', $catchId);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope for responses that need sold-out notification.
     * @srs-ref PM-023
     */
    public function scopeNeedsSoldOutNotification(Builder $query): Builder
    {
        return $query->coming()
            ->active()
            ->where(function ($q) {
                $q->whereNull('notified_sold_out')
                  ->orWhere('notified_sold_out', false);
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getTypeDisplayAttribute(): string
    {
        return match ($this->response_type) {
            self::TYPE_COMING => '🏃 Coming',
            self::TYPE_MESSAGE => '💬 Message',
            self::TYPE_NOT_TODAY => '❌ Not Today',
            default => '📢 Response',
        };
    }

    public function getIsComingAttribute(): bool
    {
        return $this->response_type === self::TYPE_COMING;
    }

    public function getDistanceDisplayAttribute(): ?string
    {
        if (!$this->distance_km) {
            return null;
        }
        return $this->distance_km < 1
            ? round($this->distance_km * 1000) . 'm'
            : round($this->distance_km, 1) . 'km';
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    public function markArrived(): void
    {
        $this->update(['arrived_at' => now()]);
    }

    public function markPurchased(): void
    {
        $this->update([
            'did_purchase' => true,
            'status' => self::STATUS_COMPLETED,
        ]);
        $this->catch?->seller?->incrementSales();
    }

    public function cancel(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Mark that user was notified about sold out status.
     * @srs-ref PM-023
     */
    public function markSoldOutNotified(): void
    {
        $this->update(['notified_sold_out' => true]);
    }

    public function rate(int $rating, string $review = null): void
    {
        $this->update([
            'rating' => $rating,
            'review' => $review,
            'rated_at' => now(),
        ]);
        $this->catch?->seller?->updateRating($rating);
    }

    /**
     * Create a "coming" response.
     */
    public static function createComingResponse(
        FishCatch $catch,
        User $user,
        FishAlert $alert = null,
        int $estimatedMins = null,
        float $latitude = null,
        float $longitude = null
    ): self {
        $distance = null;
        if ($latitude && $longitude && $catch->latitude && $catch->longitude) {
            $distance = self::calculateDistance(
                $latitude, $longitude,
                (float) $catch->latitude, (float) $catch->longitude
            );
        }

        $response = self::create([
            'fish_catch_id' => $catch->id,
            'user_id' => $user->id,
            'fish_alert_id' => $alert?->id,
            'response_type' => self::TYPE_COMING,
            'estimated_arrival_mins' => $estimatedMins,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'distance_km' => $distance,
            'status' => self::STATUS_ACTIVE,
            'notified_sold_out' => false,
        ]);

        $catch->incrementComingCount();
        $alert?->recordClick(FishAlert::ACTION_COMING);

        return $response;
    }

    /**
     * Get users who need sold-out notification for a catch.
     * @srs-ref PM-023
     */
    public static function getUsersToNotifyForSoldOut(int $catchId): Collection
    {
        return self::forCatch($catchId)
            ->needsSoldOutNotification()
            ->with('user')
            ->get();
    }

    public static function hasUserResponded(int $catchId, int $userId, string $responseType): bool
    {
        return self::where('fish_catch_id', $catchId)
            ->where('user_id', $userId)
            ->where('response_type', $responseType)
            ->exists();
    }

    /**
     * Calculate distance between two points.
     */
    protected static function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);
        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}