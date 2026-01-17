<?php

namespace App\Enums;

/**
 * Notification frequency options for shops.
 *
 * Determines how often shops receive batched notifications
 * about new product requests in their area.
 */
enum NotificationFrequency: string
{
    case IMMEDIATE = 'immediate';
    case EVERY_2_HOURS = '2hours';
    case TWICE_DAILY = 'twice_daily';
    case DAILY = 'daily';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'Immediate',
            self::EVERY_2_HOURS => 'Every 2 Hours',
            self::TWICE_DAILY => 'Twice Daily (9 AM & 5 PM)',
            self::DAILY => 'Daily (9 AM)',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'ഉടൻ',
            self::EVERY_2_HOURS => 'ഓരോ 2 മണിക്കൂറിലും',
            self::TWICE_DAILY => 'ദിവസം രണ്ടു തവണ',
            self::DAILY => 'ദിവസവും (രാവിലെ 9)',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'Get notified instantly when new requests arrive',
            self::EVERY_2_HOURS => 'Receive batched notifications every 2 hours',
            self::TWICE_DAILY => 'Receive notifications at 9 AM and 5 PM',
            self::DAILY => 'Receive all notifications once a day at 9 AM',
        };
    }

    /**
     * Get icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::IMMEDIATE => '⚡',
            self::EVERY_2_HOURS => '🕐',
            self::TWICE_DAILY => '🌅',
            self::DAILY => '📅',
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
     * Get options for forms/lists.
     */
    public static function options(): array
    {
        return collect(self::cases())->map(fn($case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'icon' => $case->icon(),
            'description' => $case->description(),
        ])->toArray();
    }
}