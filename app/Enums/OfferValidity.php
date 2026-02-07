<?php

namespace App\Enums;

use Carbon\Carbon;

/**
 * Offer validity period options.
 *
 * EXACTLY 3 options per SRS FR-OFR-04:
 * - Today
 * - 3 Days
 * - This Week
 *
 * @srs-ref FR-OFR-04 - Prompt for offer validity period
 */
enum OfferValidity: string
{
    case TODAY = 'today';
    case THREE_DAYS = '3days';
    case THIS_WEEK = 'week';

    /**
     * Get English label.
     */
    public function label(): string
    {
        return match ($this) {
            self::TODAY => 'Today',
            self::THREE_DAYS => '3 Days',
            self::THIS_WEEK => 'This Week',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::TODAY => 'ഇന്ന്',
            self::THREE_DAYS => '3 ദിവസം',
            self::THIS_WEEK => 'ഈ ആഴ്ച',
        };
    }

    /**
     * Get icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::TODAY => '⏰',
            self::THREE_DAYS => '📅',
            self::THIS_WEEK => '🗓️',
        };
    }

    /**
     * Get number of days.
     */
    public function days(): int
    {
        return match ($this) {
            self::TODAY => 1,
            self::THREE_DAYS => 3,
            self::THIS_WEEK => 7,
        };
    }

    /**
     * Get button title (with icon, max 20 chars).
     */
    public function buttonTitle(): string
    {
        return mb_substr("{$this->icon()} {$this->label()}", 0, 20);
    }

    /**
     * Calculate expiration date from now.
     */
    public function expiresAt(): Carbon
    {
        return match ($this) {
            self::TODAY => Carbon::today()->endOfDay(),
            self::THREE_DAYS => Carbon::now()->addDays(3)->endOfDay(),
            self::THIS_WEEK => Carbon::now()->addWeek()->endOfDay(),
        };
    }

    /**
     * Get human-readable expiry description.
     */
    public function expiryDescription(): string
    {
        $expiry = $this->expiresAt();

        return match ($this) {
            self::TODAY => "Today until {$expiry->format('g:i A')}",
            self::THREE_DAYS => $expiry->format('l, M j'),
            self::THIS_WEEK => $expiry->format('l, M j'),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Static Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if value is valid.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values());
    }

    /**
     * Get options for WhatsApp buttons (max 3).
     *
     * @srs-ref FR-OFR-04 - Today / 3 Days / This Week
     */
    public static function toButtons(): array
    {
        return array_map(fn(self $v) => [
            'id' => $v->value,
            'title' => $v->buttonTitle(),
        ], self::cases());
    }

    /**
     * Try to match from text input.
     */
    public static function fromText(string $text): ?self
    {
        $text = mb_strtolower(trim($text));

        // Today
        if (in_array($text, ['today', '1', 'innu', 'ഇന്ന്'])) {
            return self::TODAY;
        }

        // 3 Days
        if (preg_match('/^(3|three|3days|3 day)/', $text)) {
            return self::THREE_DAYS;
        }

        // This Week
        if (in_array($text, ['week', 'this week', '7', 'aazcha', 'ആഴ്ച'])) {
            return self::THIS_WEEK;
        }

        return null;
    }
}