<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FlashDealStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Flash Deal model for time-limited collective offers.
 *
 * "50% off — BUT only if 30 people claim in 30 minutes!"
 *
 * @srs-ref Section 5.3.1 - flash_deals table
 * @module Flash Mob Deals
 *
 * @property int $id
 * @property int $shop_id
 * @property string $title
 * @property string|null $description
 * @property string $image_url
 * @property int $discount_percent
 * @property int|null $max_discount_value
 * @property int $target_claims
 * @property int $time_limit_minutes
 * @property Carbon $starts_at
 * @property Carbon $expires_at
 * @property Carbon|null $coupon_valid_until
 * @property FlashDealStatus $status
 * @property int $current_claims
 * @property string $coupon_prefix
 * @property int $notified_customers_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Shop $shop
 * @property-read \Illuminate\Database\Eloquent\Collection|FlashDealClaim[] $claims
 */
class FlashDeal extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'shop_id',
        'title',
        'description',
        'image_url',
        'discount_percent',
        'max_discount_value',
        'target_claims',
        'time_limit_minutes',
        'starts_at',
        'expires_at',
        'coupon_valid_until',
        'status',
        'current_claims',
        'coupon_prefix',
        'notified_customers_count',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'discount_percent' => 'integer',
        'max_discount_value' => 'integer',
        'target_claims' => 'integer',
        'time_limit_minutes' => 'integer',
        'starts_at' => 'datetime',
        'expires_at' => 'datetime',
        'coupon_valid_until' => 'datetime',
        'status' => FlashDealStatus::class,
        'current_claims' => 'integer',
        'notified_customers_count' => 'integer',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'current_claims' => 0,
        'notified_customers_count' => 0,
        'coupon_prefix' => 'FLASH',
    ];

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the shop that owns this deal.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Get all claims for this deal.
     */
    public function claims(): HasMany
    {
        return $this->hasMany(FlashDealClaim::class);
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Get discount display string.
     */
    public function getDiscountDisplayAttribute(): string
    {
        $display = "{$this->discount_percent}% OFF";

        if ($this->max_discount_value) {
            $display .= " (max ₹{$this->max_discount_value})";
        }

        return $display;
    }

    /**
     * Get time remaining in seconds.
     */
    public function getTimeRemainingSecondsAttribute(): int
    {
        if ($this->expires_at->isPast()) {
            return 0;
        }

        return (int) now()->diffInSeconds($this->expires_at);
    }

    /**
     * Get time remaining as formatted string (MM:SS or HH:MM:SS).
     */
    public function getTimeRemainingDisplayAttribute(): string
    {
        $seconds = $this->time_remaining_seconds;

        if ($seconds <= 0) {
            return '00:00';
        }

        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%02d:%02d', $minutes, $secs);
    }

    /**
     * Get claims remaining to activate.
     */
    public function getClaimsRemainingAttribute(): int
    {
        return max(0, $this->target_claims - $this->current_claims);
    }

    /**
     * Get progress percentage.
     */
    public function getProgressPercentAttribute(): int
    {
        if ($this->target_claims === 0) {
            return 0;
        }

        return (int) min(100, round(($this->current_claims / $this->target_claims) * 100));
    }

    /**
     * Get progress display string.
     */
    public function getProgressDisplayAttribute(): string
    {
        return "{$this->current_claims}/{$this->target_claims}";
    }

    /**
     * Get progress bar (emoji-based).
     */
    public function getProgressBarAttribute(): string
    {
        $percent = $this->progress_percent;
        $filled = (int) round($percent / 10);
        $empty = 10 - $filled;

        return str_repeat('🟢', $filled) . str_repeat('⚪', $empty);
    }

    /**
     * Get status emoji.
     */
    public function getStatusEmojiAttribute(): string
    {
        return $this->status->emoji();
    }

    /**
     * Check if deal is currently live.
     */
    public function getIsLiveAttribute(): bool
    {
        return $this->status === FlashDealStatus::LIVE &&
            $this->starts_at->isPast() &&
            $this->expires_at->isFuture();
    }

    /**
     * Check if deal is activated (target reached).
     */
    public function getIsActivatedAttribute(): bool
    {
        return $this->status === FlashDealStatus::ACTIVATED;
    }

    /**
     * Check if deal has expired.
     */
    public function getIsExpiredAttribute(): bool
    {
        return $this->status === FlashDealStatus::EXPIRED ||
            $this->expires_at->isPast();
    }

    /**
     * Check if in urgent phase (90%+ and <5 mins remaining).
     *
     * @srs-ref FD-017
     */
    public function getIsUrgentAttribute(): bool
    {
        return $this->progress_percent >= 90 &&
            $this->time_remaining_seconds > 0 &&
            $this->time_remaining_seconds <= 300; // 5 minutes
    }

    /**
     * Get the current progress milestone (25, 50, 75, 90, 100 or null).
     */
    public function getCurrentMilestoneAttribute(): ?int
    {
        $percent = $this->progress_percent;

        if ($percent >= 100) {
            return 100;
        }
        if ($percent >= 90) {
            return 90;
        }
        if ($percent >= 75) {
            return 75;
        }
        if ($percent >= 50) {
            return 50;
        }
        if ($percent >= 25) {
            return 25;
        }

        return null;
    }

    // =========================================================================
    // QUERY SCOPES
    // =========================================================================

    /**
     * Scope to live deals.
     */
    public function scopeLive($query)
    {
        return $query->where('status', FlashDealStatus::LIVE)
            ->where('starts_at', '<=', now())
            ->where('expires_at', '>', now());
    }

    /**
     * Scope to scheduled deals ready to launch.
     */
    public function scopeReadyToLaunch($query)
    {
        return $query->where('status', FlashDealStatus::SCHEDULED)
            ->where('starts_at', '<=', now());
    }

    /**
     * Scope to expired deals needing status update.
     */
    public function scopeNeedsExpiration($query)
    {
        return $query->where('status', FlashDealStatus::LIVE)
            ->where('expires_at', '<=', now());
    }

    /**
     * Scope to deals by shop.
     */
    public function scopeForShop($query, int $shopId)
    {
        return $query->where('shop_id', $shopId);
    }

    /**
     * Scope to deals within radius of coordinates.
     */
    public function scopeWithinRadius($query, float $lat, float $lng, float $radiusKm)
    {
        return $query->whereHas('shop', function ($q) use ($lat, $lng, $radiusKm) {
            $q->whereRaw(
                "ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?)) <= ?",
                [$lng, $lat, $radiusKm * 1000]
            );
        });
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    /**
     * Increment claim count and check for activation.
     */
    public function incrementClaims(): bool
    {
        $this->increment('current_claims');
        $this->refresh();

        // Check if target reached
        if ($this->current_claims >= $this->target_claims && $this->status === FlashDealStatus::LIVE) {
            $this->activate();
            return true; // Indicates deal was just activated
        }

        return false;
    }

    /**
     * Activate the deal (target reached).
     *
     * @srs-ref FD-019
     */
    public function activate(): void
    {
        $this->update(['status' => FlashDealStatus::ACTIVATED]);
    }

    /**
     * Expire the deal (time ran out).
     *
     * @srs-ref FD-025
     */
    public function expire(): void
    {
        $this->update(['status' => FlashDealStatus::EXPIRED]);
    }

    /**
     * Cancel the deal.
     */
    public function cancel(): void
    {
        $this->update(['status' => FlashDealStatus::CANCELLED]);
    }

    /**
     * Go live (for scheduled deals).
     */
    public function goLive(): void
    {
        $this->update(['status' => FlashDealStatus::LIVE]);
    }

    /**
     * Check if user has already claimed this deal.
     */
    public function hasUserClaimed(int $userId): bool
    {
        return $this->claims()->where('user_id', $userId)->exists();
    }

    /**
     * Get user's claim for this deal.
     */
    public function getUserClaim(int $userId): ?FlashDealClaim
    {
        return $this->claims()->where('user_id', $userId)->first();
    }

    /**
     * Get position for a specific claim.
     */
    public function getClaimPosition(FlashDealClaim $claim): int
    {
        return $this->claims()
            ->where('created_at', '<=', $claim->created_at)
            ->count();
    }

    /**
     * Calculate distance from coordinates.
     */
    public function distanceFrom(float $lat, float $lng): float
    {
        $shopLat = $this->shop->latitude;
        $shopLng = $this->shop->longitude;

        $earthRadius = 6371; // km

        $latDiff = deg2rad($shopLat - $lat);
        $lngDiff = deg2rad($shopLng - $lng);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($lat)) * cos(deg2rad($shopLat)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Get formatted distance from coordinates.
     */
    public function formattedDistanceFrom(float $lat, float $lng): string
    {
        $distance = $this->distanceFrom($lat, $lng);

        if ($distance < 1) {
            return round($distance * 1000) . 'm';
        }

        return round($distance, 1) . 'km';
    }

    /**
     * Generate share text for the deal.
     */
    public function getShareText(): string
    {
        $remaining = $this->claims_remaining;

        return "⚡ FLASH DEAL at {$this->shop->shop_name}!\n" .
            "🎯 {$this->title}\n" .
            "💰 {$this->discount_display}\n" .
            "👥 Just {$remaining} more people needed!\n" .
            "⏰ {$this->time_remaining_display} left!\n\n" .
            "Claim now before it expires! 🔥";
    }

    /**
     * Generate WhatsApp share link.
     */
    public function getWhatsAppShareLink(): string
    {
        $text = urlencode($this->getShareText());
        return "https://wa.me/?text={$text}";
    }
}