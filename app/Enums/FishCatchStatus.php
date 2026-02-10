<?php

namespace App\Enums;

/**
 * Status of a fish catch posting.
 *
 * @srs-ref PM-022 - Seller updates status: Available, Low Stock, Sold Out
 * @srs-ref PM-024 - Auto-expire catches after 6 hours
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
            self::AVAILABLE => 'ലഭ്യം',
            self::LOW_STOCK => 'കുറവ്',
            self::SOLD_OUT => 'തീർന്നു',
            self::EXPIRED => 'കാലഹരണം',
        };
    }

    /**
     * Get short bilingual label for messages.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::AVAILABLE => 'Available/ലഭ്യം',
            self::LOW_STOCK => 'Low/കുറവ്',
            self::SOLD_OUT => 'Sold Out/തീർന്നു',
            self::EXPIRED => 'Expired',
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
     * Get display with emoji (short format for lists).
     */
    public function display(): string
    {
        return $this->emoji() . ' ' . $this->label();
    }

    /**
     * Get display with emoji and Malayalam.
     */
    public function displayBilingual(): string
    {
        return $this->emoji() . ' ' . $this->shortLabel();
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
            self::EXPIRED => false,
        };
    }

    /**
     * Check if this status should trigger alternative suggestions.
     * @srs-ref PM-023
     */
    public function shouldSuggestAlternatives(): bool
    {
        return $this === self::SOLD_OUT;
    }

    /**
     * Get statuses that should send alerts.
     */
    public static function alertableStatuses(): array
    {
        return [self::AVAILABLE, self::LOW_STOCK];
    }

    /**
     * Get button options for status update (WhatsApp button format).
     */
    public static function getUpdateButtons(): array
    {
        return [
            ['id' => 'status_available', 'title' => '✅ Available/ലഭ്യം'],
            ['id' => 'status_low_stock', 'title' => '⚠️ Low Stock/കുറവ്'],
            ['id' => 'status_sold_out', 'title' => '❌ Sold Out/തീർന്നു'],
        ];
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}