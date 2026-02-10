<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fish Seller Verification Status.
 *
 * @srs-ref PM-003 Verification status: pending, verified, suspended
 */
enum FishSellerVerificationStatus: string
{
    case PENDING = 'pending';       // Awaiting review
    case VERIFIED = 'verified';     // Approved by team
    case SUSPENDED = 'suspended';   // Temporarily blocked

    /**
     * Display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Verification',
            self::VERIFIED => 'Verified',
            self::SUSPENDED => 'Suspended',
        };
    }

    /**
     * Short label.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::VERIFIED => 'Verified',
            self::SUSPENDED => 'Suspended',
        };
    }

    /**
     * Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::PENDING => 'പരിശോധന കാത്തിരിക്കുന്നു',
            self::VERIFIED => 'സ്ഥിരീകരിച്ചു',
            self::SUSPENDED => 'താൽക്കാലികമായി നിർത്തി',
        };
    }

    /**
     * Icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::PENDING => '⏳',
            self::VERIFIED => '✅',
            self::SUSPENDED => '🚫',
        };
    }

    /**
     * Badge (icon + label).
     */
    public function badge(): string
    {
        return $this->icon() . ' ' . $this->label();
    }

    /**
     * Short badge.
     */
    public function shortBadge(): string
    {
        return $this->icon() . ' ' . $this->shortLabel();
    }

    /**
     * Description for user.
     */
    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Your profile is being reviewed (usually 24 hours)',
            self::VERIFIED => 'Your profile is verified',
            self::SUSPENDED => 'Your account has been temporarily suspended',
        };
    }

    /**
     * Malayalam description.
     */
    public function descriptionMl(): string
    {
        return match ($this) {
            self::PENDING => 'നിങ്ങളുടെ പ്രൊഫൈൽ പരിശോധിക്കുന്നു (സാധാരണ 24 മണിക്കൂർ)',
            self::VERIFIED => 'നിങ്ങളുടെ പ്രൊഫൈൽ സ്ഥിരീകരിച്ചു',
            self::SUSPENDED => 'നിങ്ങളുടെ അക്കൗണ്ട് താൽക്കാലികമായി നിർത്തി',
        };
    }

    /**
     * CSS badge class.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-yellow-100 text-yellow-800',
            self::VERIFIED => 'bg-green-100 text-green-800',
            self::SUSPENDED => 'bg-red-100 text-red-800',
        };
    }

    /**
     * Color name.
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'yellow',
            self::VERIFIED => 'green',
            self::SUSPENDED => 'red',
        };
    }

    /**
     * Can post catches?
     * Allow posting even while pending (build trust, verify later).
     */
    public function canPostCatches(): bool
    {
        return in_array($this, [self::PENDING, self::VERIFIED]);
    }

    /**
     * Can receive alerts/notifications?
     */
    public function canReceiveAlerts(): bool
    {
        return $this === self::VERIFIED;
    }

    /**
     * Is active (not suspended)?
     */
    public function isActive(): bool
    {
        return $this !== self::SUSPENDED;
    }

    /**
     * Needs review?
     */
    public function needsReview(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get statuses that allow posting.
     */
    public static function postableStatuses(): array
    {
        return [self::PENDING, self::VERIFIED];
    }
}