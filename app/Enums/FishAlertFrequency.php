<?php

namespace App\Enums;

/**
 * Alert frequency preferences for fish subscriptions.
 *
 * @srs-ref Section 2.3.4 - Alert Delivery
 */
enum FishAlertFrequency: string
{
    case IMMEDIATE = 'immediate';
    case MORNING_ONLY = 'morning_only';
    case TWICE_DAILY = 'twice_daily';
    case WEEKLY_DIGEST = 'weekly_digest';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'Immediate',
            self::MORNING_ONLY => 'Morning Only (6-8 AM)',
            self::TWICE_DAILY => 'Twice Daily (6 AM & 4 PM)',
            self::WEEKLY_DIGEST => 'Weekly Summary',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'ഉടൻ',
            self::MORNING_ONLY => 'രാവിലെ മാത്രം',
            self::TWICE_DAILY => 'ദിവസം രണ്ട് തവണ',
            self::WEEKLY_DIGEST => 'ആഴ്ചതോറും',
        };
    }

    /**
     * Get emoji for display.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::IMMEDIATE => '🔔',
            self::MORNING_ONLY => '🌅',
            self::TWICE_DAILY => '☀️',
            self::WEEKLY_DIGEST => '📅',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'Get notified instantly when fresh fish arrives',
            self::MORNING_ONLY => 'Get all alerts in the morning (best for early buyers)',
            self::TWICE_DAILY => 'Morning and afternoon digest',
            self::WEEKLY_DIGEST => 'Weekly summary of fish availability',
        };
    }

    /**
     * Get description in Malayalam.
     */
    public function descriptionMl(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'പച്ച മീൻ വരുമ്പോൾ ഉടൻ അറിയിപ്പ്',
            self::MORNING_ONLY => 'രാവിലെ എല്ലാ അറിയിപ്പുകളും',
            self::TWICE_DAILY => 'രാവിലെയും ഉച്ചയ്ക്കും',
            self::WEEKLY_DIGEST => 'ആഴ്ചയിലെ മീൻ ലഭ്യത സംക്ഷിപ്തം',
        };
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        return [
            'id' => 'fish_freq_' . $this->value,
            'title' => $this->emoji() . ' ' . substr($this->label(), 0, 20),
            'description' => substr($this->description(), 0, 72),
        ];
    }

    /**
     * Get schedule times for this frequency.
     * Returns array of hours in 24h format.
     */
    public function scheduleTimes(): array
    {
        return match ($this) {
            self::IMMEDIATE => [], // Send immediately, no schedule
            self::MORNING_ONLY => [6],
            self::TWICE_DAILY => [6, 16],
            self::WEEKLY_DIGEST => [8], // Sunday 8 AM
        };
    }

    /**
     * Check if this frequency should batch alerts.
     */
    public function shouldBatch(): bool
    {
        return $this !== self::IMMEDIATE;
    }

    /**
     * Get batch window in hours.
     */
    public function batchWindowHours(): int
    {
        return match ($this) {
            self::IMMEDIATE => 0,
            self::MORNING_ONLY => 24,
            self::TWICE_DAILY => 12,
            self::WEEKLY_DIGEST => 168, // 7 days
        };
    }

    /**
     * Get all as WhatsApp list items.
     */
    public static function toListItems(): array
    {
        return array_map(fn(self $freq) => $freq->toListItem(), self::cases());
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Create from list item ID.
     */
    public static function fromListId(string $listId): ?self
    {
        $value = str_replace('fish_freq_', '', $listId);
        return self::tryFrom($value);
    }
}
