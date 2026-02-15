<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a Flash Deal throughout its lifecycle.
 *
 * Lifecycle: SCHEDULED → LIVE → ACTIVATED/EXPIRED
 *            Any state → CANCELLED (by shop owner)
 *
 * @srs-ref FD-019 (ACTIVATED), FD-025 (EXPIRED)
 * @module Flash Mob Deals
 */
enum FlashDealStatus: string
{
    case SCHEDULED = 'scheduled';
    case LIVE = 'live';
    case ACTIVATED = 'activated';
    case EXPIRED = 'expired';
    case CANCELLED = 'cancelled';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Scheduled',
            self::LIVE => 'Live',
            self::ACTIVATED => 'Activated',
            self::EXPIRED => 'Expired',
            self::CANCELLED => 'Cancelled',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::SCHEDULED => 'ഷെഡ്യൂൾ ചെയ്തു',
            self::LIVE => 'ലൈവ്',
            self::ACTIVATED => 'ആക്ടിവേറ്റ് ആയി',
            self::EXPIRED => 'കാലഹരണപ്പെട്ടു',
            self::CANCELLED => 'റദ്ദാക്കി',
        };
    }

    /**
     * Get emoji for display.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::SCHEDULED => '🕐',
            self::LIVE => '🔴',
            self::ACTIVATED => '🎉',
            self::EXPIRED => '⏰',
            self::CANCELLED => '❌',
        };
    }

    /**
     * Get display with emoji.
     */
    public function display(): string
    {
        return $this->emoji() . ' ' . $this->label();
    }

    /**
     * Get bilingual display.
     */
    public function displayBilingual(): string
    {
        return $this->emoji() . ' ' . $this->label() . ' / ' . $this->labelMl();
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::SCHEDULED => 'blue',
            self::LIVE => 'red',
            self::ACTIVATED => 'green',
            self::EXPIRED => 'gray',
            self::CANCELLED => 'gray',
        };
    }

    /**
     * Get Tailwind color class.
     */
    public function tailwindColor(): string
    {
        return match ($this) {
            self::SCHEDULED => 'bg-blue-100 text-blue-800',
            self::LIVE => 'bg-red-100 text-red-800 animate-pulse',
            self::ACTIVATED => 'bg-green-100 text-green-800',
            self::EXPIRED => 'bg-gray-100 text-gray-500',
            self::CANCELLED => 'bg-gray-100 text-gray-400',
        };
    }

    /**
     * Check if deal is currently accepting claims.
     *
     * @srs-ref FD-024 - Continue accepting claims after activation
     */
    public function acceptsClaims(): bool
    {
        return in_array($this, [self::LIVE, self::ACTIVATED]);
    }

    /**
     * Check if deal is active (not in terminal state).
     */
    public function isActive(): bool
    {
        return in_array($this, [self::SCHEDULED, self::LIVE]);
    }

    /**
     * Check if deal was successful (target reached).
     *
     * @srs-ref FD-019
     */
    public function isSuccessful(): bool
    {
        return $this === self::ACTIVATED;
    }

    /**
     * Check if deal failed (target not reached).
     *
     * @srs-ref FD-025
     */
    public function isFailed(): bool
    {
        return $this === self::EXPIRED;
    }

    /**
     * Check if deal is in terminal state (no more changes).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::ACTIVATED, self::EXPIRED, self::CANCELLED]);
    }

    /**
     * Check if deal can be cancelled.
     */
    public function canCancel(): bool
    {
        return in_array($this, [self::SCHEDULED, self::LIVE]);
    }

    /**
     * Check if coupons are valid for this status.
     */
    public function couponsValid(): bool
    {
        return $this === self::ACTIVATED;
    }

    /**
     * Check if deal should show countdown.
     */
    public function showsCountdown(): bool
    {
        return $this === self::LIVE;
    }

    /**
     * Can transition to target status.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::SCHEDULED => in_array($target, [self::LIVE, self::CANCELLED]),
            self::LIVE => in_array($target, [self::ACTIVATED, self::EXPIRED, self::CANCELLED]),
            self::ACTIVATED => false, // Terminal
            self::EXPIRED => false, // Terminal
            self::CANCELLED => false, // Terminal
        };
    }

    /**
     * Get next valid statuses.
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::SCHEDULED => [self::LIVE, self::CANCELLED],
            self::LIVE => [self::ACTIVATED, self::EXPIRED, self::CANCELLED],
            self::ACTIVATED => [],
            self::EXPIRED => [],
            self::CANCELLED => [],
        };
    }

    /**
     * Get customer-facing status message.
     */
    public function customerMessage(): string
    {
        return match ($this) {
            self::SCHEDULED => 'Deal starts soon! Stay tuned.',
            self::LIVE => 'Deal is LIVE! Claim now before time runs out!',
            self::ACTIVATED => 'Deal activated! Show your coupon at the shop.',
            self::EXPIRED => 'This deal has expired.',
            self::CANCELLED => 'This deal was cancelled.',
        };
    }

    /**
     * Get customer-facing status message in Malayalam.
     */
    public function customerMessageMl(): string
    {
        return match ($this) {
            self::SCHEDULED => 'ഡീൽ ഉടൻ തുടങ്ങും! കാത്തിരിക്കൂ.',
            self::LIVE => 'ഡീൽ ലൈവ്! സമയം തീരും മുമ്പ് ക്ലെയിം ചെയ്യൂ!',
            self::ACTIVATED => 'ഡീൽ ആക്ടിവേറ്റ് ആയി! ഷോപ്പിൽ കൂപ്പൺ കാണിക്കുക.',
            self::EXPIRED => 'ഈ ഡീൽ കാലഹരണപ്പെട്ടു.',
            self::CANCELLED => 'ഈ ഡീൽ റദ്ദാക്കി.',
        };
    }

    /**
     * Get shop owner notification message.
     */
    public function shopOwnerMessage(string $dealTitle, int $claims = 0, int $target = 0): string
    {
        return match ($this) {
            self::SCHEDULED => "🕐 *Deal Scheduled*\n\n{$dealTitle}\n\nYour deal is scheduled and will go live soon!",
            self::LIVE => "🔴 *DEAL IS LIVE!*\n\n{$dealTitle}\n\n⏰ Clock is ticking! Watch the claims roll in!",
            self::ACTIVATED => "🎉🎉🎉 *DEAL ACTIVATED!* 🎉🎉🎉\n\n{$dealTitle}\n\n✅ Target reached: {$claims}/{$target} claims!\nGet ready for customers!",
            self::EXPIRED => "⏰ *Deal Expired*\n\n{$dealTitle}\n\n❌ Target not reached: {$claims}/{$target}\nDon't worry, try again with adjusted settings!",
            self::CANCELLED => "❌ *Deal Cancelled*\n\n{$dealTitle}",
        };
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get statuses where claims can be made.
     */
    public static function claimableStatuses(): array
    {
        return [self::LIVE, self::ACTIVATED];
    }

    /**
     * Get active (non-terminal) statuses.
     */
    public static function activeStatuses(): array
    {
        return [self::SCHEDULED, self::LIVE];
    }

    /**
     * Get terminal statuses.
     */
    public static function terminalStatuses(): array
    {
        return [self::ACTIVATED, self::EXPIRED, self::CANCELLED];
    }

    /**
     * Get successful statuses.
     */
    public static function successfulStatuses(): array
    {
        return [self::ACTIVATED];
    }
}