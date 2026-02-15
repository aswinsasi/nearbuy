<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Flash Deal Claim model - tracks individual customer claims.
 *
 * Each claim gets a unique coupon code upon deal activation.
 *
 * @srs-ref FD-014 (claim + position), FD-020 (unique coupon FLASH-XXXXXX)
 * @module Flash Mob Deals
 *
 * @property int $id
 * @property int $flash_deal_id
 * @property int $user_id
 * @property int $position
 * @property string|null $coupon_code
 * @property bool $coupon_redeemed
 * @property Carbon|null $redeemed_at
 * @property int|null $referred_by_user_id
 * @property array $milestone_notifications_sent
 * @property string|null $claim_source
 * @property Carbon $claimed_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read FlashDeal $deal
 * @property-read User $user
 * @property-read User|null $referrer
 */
class FlashDealClaim extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'flash_deal_id',
        'user_id',
        'position',
        'coupon_code',
        'coupon_redeemed',
        'redeemed_at',
        'referred_by_user_id',
        'milestone_notifications_sent',
        'claim_source',
        'claimed_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'position' => 'integer',
        'coupon_redeemed' => 'boolean',
        'redeemed_at' => 'datetime',
        'referred_by_user_id' => 'integer',
        'milestone_notifications_sent' => 'array',
        'claimed_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'coupon_redeemed' => false,
        'milestone_notifications_sent' => '[]',
    ];

    /**
     * Boot the model.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (FlashDealClaim $claim) {
            if (!$claim->claimed_at) {
                $claim->claimed_at = now();
            }
             if ($claim->milestone_notifications_sent === null) {
                $claim->milestone_notifications_sent = [];
            }
        });
    }

    // =========================================================================
    // RELATIONSHIPS
    // =========================================================================

    /**
     * Get the flash deal this claim belongs to.
     */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(FlashDeal::class, 'flash_deal_id');
    }

    /**
     * Get the user who made this claim.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the user who referred this claimer.
     */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referred_by_user_id');
    }

    // =========================================================================
    // ACCESSORS
    // =========================================================================

    /**
     * Get formatted position display.
     *
     * @srs-ref FD-014 - Show position "You're #13"
     */
    public function getPositionDisplayAttribute(): string
    {
        return "#{$this->position}";
    }

    /**
     * Get ordinal position (1st, 2nd, 3rd, etc.)
     */
    public function getOrdinalPositionAttribute(): string
    {
        $ends = ['th', 'st', 'nd', 'rd', 'th', 'th', 'th', 'th', 'th', 'th'];

        if (($this->position % 100) >= 11 && ($this->position % 100) <= 13) {
            return $this->position . 'th';
        }

        return $this->position . $ends[$this->position % 10];
    }

    /**
     * Check if coupon is valid (deal activated, not used, not expired).
     */
    public function getIsCouponValidAttribute(): bool
    {
        if (!$this->coupon_code) {
            return false;
        }

        if ($this->coupon_redeemed) {
            return false;
        }

        $deal = $this->deal;

        if (!$deal->is_activated) {
            return false;
        }

        if ($deal->coupon_valid_until && $deal->coupon_valid_until->isPast()) {
            return false;
        }

        return true;
    }

    /**
     * Get coupon status display.
     */
    public function getCouponStatusAttribute(): string
    {
        if (!$this->coupon_code) {
            return '⏳ Pending activation';
        }

        if ($this->coupon_redeemed) {
            return '✅ Redeemed';
        }

        if (!$this->deal->is_activated) {
            return '⏳ Waiting for activation';
        }

        if ($this->deal->coupon_valid_until && $this->deal->coupon_valid_until->isPast()) {
            return '⏰ Expired';
        }

        return '🎫 Valid';
    }

    /**
     * Get coupon status in Malayalam.
     */
    public function getCouponStatusMlAttribute(): string
    {
        if (!$this->coupon_code) {
            return 'ആക്ടിവേഷൻ കാത്തിരിക്കുന്നു';
        }

        if ($this->coupon_redeemed) {
            return 'ഉപയോഗിച്ചു';
        }

        if (!$this->deal->is_activated) {
            return 'ആക്ടിവേഷൻ കാത്തിരിക്കുന്നു';
        }

        if ($this->deal->coupon_valid_until && $this->deal->coupon_valid_until->isPast()) {
            return 'കാലഹരണപ്പെട്ടു';
        }

        return 'സാധുവാണ്';
    }

    /**
     * Get time since claim.
     */
    public function getClaimedAgoAttribute(): string
    {
        return $this->claimed_at->diffForHumans();
    }

    // =========================================================================
    // METHODS
    // =========================================================================

    /**
     * Generate unique coupon code for this claim.
     *
     * @srs-ref FD-020 - Generate UNIQUE coupon code (FLASH-XXXXXX)
     */
    public function generateCouponCode(): string
    {
        if ($this->coupon_code) {
            return $this->coupon_code;
        }

        $prefix = $this->deal->coupon_prefix ?? 'FLASH';

        // Generate unique code
        do {
            $code = $prefix . '-' . strtoupper(Str::random(6));
        } while (self::where('coupon_code', $code)->exists());

        $this->coupon_code = $code;
        $this->save();

        return $this->coupon_code;
    }

    /**
     * Mark coupon as redeemed.
     */
    public function markRedeemed(): void
    {
        $this->update([
            'coupon_redeemed' => true,
            'redeemed_at' => now(),
        ]);
    }

    /**
     * Check if a milestone notification was already sent.
     *
     * @srs-ref FD-016 - Progress updates at 25%, 50%, 75%, 90%
     */
    public function wasMilestoneNotificationSent(int $milestone): bool
    {
        $sent = $this->milestone_notifications_sent ?? [];
        return in_array($milestone, $sent);
    }

    /**
     * Mark milestone notification as sent.
     */
    public function markMilestoneNotificationSent(int $milestone): void
    {
        $sent = $this->milestone_notifications_sent ?? [];
        $sent[] = $milestone;

        $this->update([
            'milestone_notifications_sent' => array_unique($sent),
        ]);
    }

    /**
     * Get pending milestone notifications.
     */
    public function getPendingMilestones(): array
    {
        $currentPercent = $this->deal->progress_percent;
        $milestones = [25, 50, 75, 90];
        $pending = [];

        foreach ($milestones as $milestone) {
            if ($currentPercent >= $milestone && !$this->wasMilestoneNotificationSent($milestone)) {
                $pending[] = $milestone;
            }
        }

        return $pending;
    }

    /**
     * Get the coupon display message.
     *
     * @srs-ref FD-022 - Include code, shop address, validity
     */
    public function getCouponDisplayMessage(): string
    {
        $deal = $this->deal;
        $shop = $deal->shop;

        $validUntil = $deal->coupon_valid_until
            ? $deal->coupon_valid_until->format('M d, h:i A')
            : 'Today';

        return "🎫 *Your Coupon Code:*\n" .
            "┏━━━━━━━━━━━━━━━━━┓\n" .
            "┃  *{$this->coupon_code}*  ┃\n" .
            "┗━━━━━━━━━━━━━━━━━┛\n\n" .
            "🏪 *Redeem at:* {$shop->shop_name}\n" .
            "📍 {$shop->address}\n" .
            "⏰ *Valid until:* {$validUntil}";
    }

    // =========================================================================
    // QUERY SCOPES
    // =========================================================================

    /**
     * Scope to unredeemed coupons.
     */
    public function scopeUnredeemed($query)
    {
        return $query->where('coupon_redeemed', false);
    }

    /**
     * Scope to redeemed coupons.
     */
    public function scopeRedeemed($query)
    {
        return $query->where('coupon_redeemed', true);
    }

    /**
     * Scope to claims with generated coupons.
     */
    public function scopeWithCoupon($query)
    {
        return $query->whereNotNull('coupon_code');
    }

    /**
     * Scope to claims without coupons.
     */
    public function scopeWithoutCoupon($query)
    {
        return $query->whereNull('coupon_code');
    }

    /**
     * Scope to claims from referrals.
     */
    public function scopeFromReferral($query)
    {
        return $query->whereNotNull('referred_by_user_id');
    }

    /**
     * Scope to claims by a specific user.
     */
    public function scopeByUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope to claims for a specific deal.
     */
    public function scopeForDeal($query, int $dealId)
    {
        return $query->where('flash_deal_id', $dealId);
    }

    // =========================================================================
    // STATIC METHODS
    // =========================================================================

    /**
     * Get claim by coupon code.
     */
    public static function findByCouponCode(string $code): ?self
    {
        return self::where('coupon_code', $code)->first();
    }

    /**
     * Check if user has claimed a deal.
     */
    public static function hasUserClaimed(int $dealId, int $userId): bool
    {
        return self::where('flash_deal_id', $dealId)
            ->where('user_id', $userId)
            ->exists();
    }

    /**
     * Get user's claim for a deal.
     */
    public static function getUserClaim(int $dealId, int $userId): ?self
    {
        return self::where('flash_deal_id', $dealId)
            ->where('user_id', $userId)
            ->first();
    }


    // Also add accessor to ensure it always returns array
    public function getMilestoneNotificationsSentAttribute($value): array
    {
        if ($value === null) {
            return [];
        }
        
        if (is_string($value)) {
            return json_decode($value, true) ?? [];
        }
        
        return $value;
    }
}