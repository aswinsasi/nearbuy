<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fish Catch Response Model - Customer responses to catches.
 *
 * Tracks when customers click "I'm Coming", "Message Seller", etc.
 *
 * @property int $id
 * @property int $fish_catch_id
 * @property int $user_id
 * @property int|null $fish_alert_id
 * @property string $response_type
 * @property int|null $estimated_arrival_mins
 * @property \Carbon\Carbon|null $arrived_at
 * @property bool|null $did_purchase
 * @property string|null $message_content
 * @property \Carbon\Carbon|null $seller_replied_at
 * @property float|null $latitude
 * @property float|null $longitude
 * @property float|null $distance_km
 * @property int|null $rating
 * @property string|null $review
 * @property \Carbon\Carbon|null $rated_at
 * @property string $status
 * @property \Carbon\Carbon|null $cancelled_at
 *
 * @srs-ref Section 2.5.2 - Customer Alert Message Format (action buttons)
 */
class FishCatchResponse extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
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
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'arrived_at' => 'datetime',
        'did_purchase' => 'boolean',
        'seller_replied_at' => 'datetime',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'distance_km' => 'decimal:2',
        'rated_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Response types.
     */
    public const TYPE_COMING = 'coming';
    public const TYPE_MESSAGE = 'message';
    public const TYPE_NOT_TODAY = 'not_today';
    public const TYPE_LOCATION_REQUEST = 'location_request';

    /**
     * Response statuses.
     */
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_COMPLETED = 'completed';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the catch this response is for.
     */
    public function catch(): BelongsTo
    {
        return $this->belongsTo(FishCatch::class, 'fish_catch_id');
    }

    /**
     * Get the user who responded.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the alert that triggered this response.
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(FishAlert::class, 'fish_alert_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter by response type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('response_type', $type);
    }

    /**
     * Scope to filter "I'm Coming" responses.
     */
    public function scopeComing(Builder $query): Builder
    {
        return $query->where('response_type', self::TYPE_COMING);
    }

    /**
     * Scope to filter active responses.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    /**
     * Scope to filter completed responses (purchased).
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope to filter by catch.
     */
    public function scopeForCatch(Builder $query, int $catchId): Builder
    {
        return $query->where('fish_catch_id', $catchId);
    }

    /**
     * Scope to filter by user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to filter rated responses.
     */
    public function scopeRated(Builder $query): Builder
    {
        return $query->whereNotNull('rating');
    }

    /**
     * Scope for responses awaiting arrival.
     */
    public function scopeAwaitingArrival(Builder $query): Builder
    {
        return $query->coming()
            ->active()
            ->whereNull('arrived_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get response type display.
     */
    public function getTypeDisplayAttribute(): string
    {
        return match ($this->response_type) {
            self::TYPE_COMING => '🏃 Coming',
            self::TYPE_MESSAGE => '💬 Message',
            self::TYPE_NOT_TODAY => '❌ Not Today',
            self::TYPE_LOCATION_REQUEST => '📍 Location',
            default => '📢 Response',
        };
    }

    /**
     * Get status display.
     */
    public function getStatusDisplayAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_ACTIVE => '🟢 Active',
            self::STATUS_CANCELLED => '🔴 Cancelled',
            self::STATUS_COMPLETED => '✅ Completed',
            default => '❓ Unknown',
        };
    }

    /**
     * Get estimated arrival display.
     */
    public function getArrivalDisplayAttribute(): ?string
    {
        if ($this->arrived_at) {
            return 'Arrived at ' . $this->arrived_at->format('h:i A');
        }

        if ($this->estimated_arrival_mins) {
            return '~' . $this->estimated_arrival_mins . ' mins';
        }

        return null;
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
            return round($this->distance_km * 1000) . 'm away';
        }

        return round($this->distance_km, 1) . ' km away';
    }

    /**
     * Get rating display with stars.
     */
    public function getRatingDisplayAttribute(): ?string
    {
        if (!$this->rating) {
            return null;
        }

        return str_repeat('⭐', $this->rating);
    }

    /**
     * Check if response is a "coming" type.
     */
    public function getIsComingAttribute(): bool
    {
        return $this->response_type === self::TYPE_COMING;
    }

    /**
     * Check if customer has arrived.
     */
    public function getHasArrivedAttribute(): bool
    {
        return $this->arrived_at !== null;
    }

    /**
     * Check if response can be rated.
     */
    public function getCanBeRatedAttribute(): bool
    {
        return $this->is_coming &&
            $this->status === self::STATUS_COMPLETED &&
            !$this->rating;
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Mark customer as arrived.
     */
    public function markArrived(): void
    {
        $this->update([
            'arrived_at' => now(),
        ]);
    }

    /**
     * Mark as purchased.
     */
    public function markPurchased(): void
    {
        $this->update([
            'did_purchase' => true,
            'status' => self::STATUS_COMPLETED,
        ]);

        $this->catch?->seller?->incrementSales();
    }

    /**
     * Mark as not purchased.
     */
    public function markNotPurchased(): void
    {
        $this->update([
            'did_purchase' => false,
            'status' => self::STATUS_COMPLETED,
        ]);
    }

    /**
     * Cancel response.
     */
    public function cancel(): void
    {
        $this->update([
            'status' => self::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ]);
    }

    /**
     * Add rating.
     */
    public function rate(int $rating, string $review = null): void
    {
        $this->update([
            'rating' => $rating,
            'review' => $review,
            'rated_at' => now(),
        ]);

        // Update seller's average rating
        $this->catch?->seller?->updateRating($rating);
    }

    /**
     * Record seller reply to message.
     */
    public function recordSellerReply(): void
    {
        $this->update([
            'seller_replied_at' => now(),
        ]);
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
        if ($latitude && $longitude) {
            $earthRadius = 6371;
            $latDiff = deg2rad($catch->catch_latitude - $latitude);
            $lngDiff = deg2rad($catch->catch_longitude - $longitude);
            $a = sin($latDiff / 2) * sin($latDiff / 2) +
                cos(deg2rad($latitude)) * cos(deg2rad($catch->catch_latitude)) *
                sin($lngDiff / 2) * sin($lngDiff / 2);
            $distance = $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
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
        ]);

        // Update catch stats
        $catch->incrementComingCount();

        // Record alert click
        $alert?->recordClick(FishAlert::ACTION_COMING);

        return $response;
    }

    /**
     * Create a message response.
     */
    public static function createMessageResponse(
        FishCatch $catch,
        User $user,
        string $message,
        FishAlert $alert = null
    ): self {
        $response = self::create([
            'fish_catch_id' => $catch->id,
            'user_id' => $user->id,
            'fish_alert_id' => $alert?->id,
            'response_type' => self::TYPE_MESSAGE,
            'message_content' => $message,
            'status' => self::STATUS_ACTIVE,
        ]);

        // Update catch stats
        $catch->incrementMessageCount();

        // Record alert click
        $alert?->recordClick(FishAlert::ACTION_MESSAGE);

        return $response;
    }

    /**
     * Check if user has already responded to catch.
     */
    public static function hasUserResponded(int $catchId, int $userId, string $responseType): bool
    {
        return self::where('fish_catch_id', $catchId)
            ->where('user_id', $userId)
            ->where('response_type', $responseType)
            ->exists();
    }
}
