<?php

namespace App\Enums;

/**
 * Status of a fish catch posting.
 *
 * @srs-ref Section 2.3.5 - Stock Management
 */
enum FishCatchStatus: string
{
    case AVAILABLE = 'available';
    case LOW_STOCK = 'low_stock';
    case SOLD_OUT = 'sold_out';
    case EXPIRED = 'expired';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Available',
            self::LOW_STOCK => 'Low Stock',
            self::SOLD_OUT => 'Sold Out',
            self::EXPIRED => 'Expired',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::AVAILABLE => 'ലഭ്യമാണ്',
            self::LOW_STOCK => 'കുറവ്',
            self::SOLD_OUT => 'തീർന്നു',
            self::EXPIRED => 'കാലഹരണപ്പെട്ടു',
        };
    }

    /**
     * Get emoji for display.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::AVAILABLE => '✅',
            self::LOW_STOCK => '⚠️',
            self::SOLD_OUT => '❌',
            self::EXPIRED => '⏰',
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
     * Get color for UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::AVAILABLE => 'green',
            self::LOW_STOCK => 'yellow',
            self::SOLD_OUT => 'red',
            self::EXPIRED => 'gray',
        };
    }

    /**
     * Check if catch is still active (can receive responses).
     */
    public function isActive(): bool
    {
        return in_array($this, [self::AVAILABLE, self::LOW_STOCK]);
    }

    /**
     * Check if catch should be hidden from browse.
     */
    public function isHidden(): bool
    {
        return in_array($this, [self::SOLD_OUT, self::EXPIRED]);
    }

    /**
     * Check if status can be changed to target status.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::AVAILABLE => in_array($target, [self::LOW_STOCK, self::SOLD_OUT, self::EXPIRED]),
            self::LOW_STOCK => in_array($target, [self::AVAILABLE, self::SOLD_OUT, self::EXPIRED]),
            self::SOLD_OUT => in_array($target, [self::AVAILABLE, self::EXPIRED]),
            self::EXPIRED => false, // Cannot transition from expired
        };
    }

    /**
     * Get statuses that should send alerts.
     */
    public static function alertableStatuses(): array
    {
        return [self::AVAILABLE, self::LOW_STOCK];
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
