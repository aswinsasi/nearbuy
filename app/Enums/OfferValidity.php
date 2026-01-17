<?php

namespace App\Enums;

use Carbon\Carbon;

/**
 * Offer validity period options.
 */
enum OfferValidity: string
{
    case TODAY = 'today';
    case THREE_DAYS = '3days';
    case WEEK = 'week';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::TODAY => 'Today Only',
            self::THREE_DAYS => '3 Days',
            self::WEEK => '1 Week',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::TODAY => 'ഇന്ന് മാത്രം',
            self::THREE_DAYS => '3 ദിവസം',
            self::WEEK => '1 ആഴ്ച',
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
            self::WEEK => '🗓️',
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
            self::WEEK => 7,
        };
    }

    /**
     * Calculate expiration date from now.
     */
    public function expiresAt(): Carbon
    {
        return match ($this) {
            self::TODAY => Carbon::today()->endOfDay(),
            self::THREE_DAYS => Carbon::now()->addDays(3)->endOfDay(),
            self::WEEK => Carbon::now()->addWeek()->endOfDay(),
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
     * Get options for WhatsApp buttons.
     */
    public static function toButtons(): array
    {
        return array_map(fn(self $validity) => [
            'id' => $validity->value,
            'title' => substr("{$validity->icon()} {$validity->label()}", 0, 20),
        ], self::cases());
    }
}