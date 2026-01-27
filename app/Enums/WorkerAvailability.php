<?php

namespace App\Enums;

/**
 * Worker availability time slots.
 *
 * @srs-ref Section 3.2 - Job Workers
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum WorkerAvailability: string
{
    case MORNING = 'morning';
    case AFTERNOON = 'afternoon';
    case EVENING = 'evening';
    case FLEXIBLE = 'flexible';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::MORNING => 'Morning',
            self::AFTERNOON => 'Afternoon',
            self::EVENING => 'Evening',
            self::FLEXIBLE => 'Flexible',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::MORNING => 'രാവിലെ',
            self::AFTERNOON => 'ഉച്ചയ്ക്ക്',
            self::EVENING => 'വൈകുന്നേരം',
            self::FLEXIBLE => 'എപ്പോഴും',
        };
    }

    /**
     * Get emoji icon.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::MORNING => '🌅',
            self::AFTERNOON => '☀️',
            self::EVENING => '🌆',
            self::FLEXIBLE => '🔄',
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
     * Get time range as string.
     */
    public function timeRange(): string
    {
        return match ($this) {
            self::MORNING => '6:00 AM - 12:00 PM',
            self::AFTERNOON => '12:00 PM - 6:00 PM',
            self::EVENING => '6:00 PM - 10:00 PM',
            self::FLEXIBLE => 'Any time',
        };
    }

    /**
     * Get time range in Malayalam.
     */
    public function timeRangeMl(): string
    {
        return match ($this) {
            self::MORNING => '6:00 - 12:00',
            self::AFTERNOON => '12:00 - 6:00',
            self::EVENING => '6:00 - 10:00',
            self::FLEXIBLE => 'എപ്പോൾ വേണമെങ്കിലും',
        };
    }

    /**
     * Get start hour (24-hour format).
     */
    public function startHour(): int
    {
        return match ($this) {
            self::MORNING => 6,
            self::AFTERNOON => 12,
            self::EVENING => 18,
            self::FLEXIBLE => 6,
        };
    }

    /**
     * Get end hour (24-hour format).
     */
    public function endHour(): int
    {
        return match ($this) {
            self::MORNING => 12,
            self::AFTERNOON => 18,
            self::EVENING => 22,
            self::FLEXIBLE => 22,
        };
    }

    /**
     * Check if given hour falls within this availability.
     */
    public function includesHour(int $hour): bool
    {
        if ($this === self::FLEXIBLE) {
            return $hour >= 6 && $hour <= 22;
        }
        return $hour >= $this->startHour() && $hour < $this->endHour();
    }

    /**
     * Get button title for WhatsApp.
     */
    public function buttonTitle(): string
    {
        return $this->emoji() . ' ' . $this->label() . ' (' . $this->timeRange() . ')';
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        return [
            'id' => 'avail_' . $this->value,
            'title' => substr($this->display(), 0, 24),
            'description' => $this->timeRange(),
        ];
    }

    /**
     * Get all as WhatsApp list items.
     */
    public static function toListItems(): array
    {
        return array_map(fn(self $avail) => $avail->toListItem(), self::cases());
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
        $value = str_replace('avail_', '', $listId);
        return self::tryFrom($value);
    }

    /**
     * Get availabilities that cover a specific hour.
     */
    public static function forHour(int $hour): array
    {
        return array_filter(self::cases(), fn(self $avail) => $avail->includesHour($hour));
    }
}