<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Worker Availability Time Slots.
 *
 * @srs-ref NP-004: Morning (6-12), Afternoon (12-6), Evening (6-10), Flexible
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum WorkerAvailability: string
{
    case MORNING = 'morning';       // 6 AM - 12 PM
    case AFTERNOON = 'afternoon';   // 12 PM - 6 PM
    case EVENING = 'evening';       // 6 PM - 10 PM
    case FLEXIBLE = 'flexible';     // Anytime

    /**
     * Display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::MORNING => 'Morning (6-12)',
            self::AFTERNOON => 'Afternoon (12-6)',
            self::EVENING => 'Evening (6-10)',
            self::FLEXIBLE => 'Flexible',
        };
    }

    /**
     * Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::MORNING => 'രാവിലെ (6-12)',
            self::AFTERNOON => 'ഉച്ചയ്ക്ക് (12-6)',
            self::EVENING => 'വൈകിട്ട് (6-10)',
            self::FLEXIBLE => 'എപ്പോഴും ഫ്രീ',
        };
    }

    /**
     * Emoji icon.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::MORNING => '🌅',
            self::AFTERNOON => '☀️',
            self::EVENING => '🌙',
            self::FLEXIBLE => '🔄',
        };
    }

    /**
     * Button title (short, for WhatsApp).
     */
    public function buttonTitle(): string
    {
        return match ($this) {
            self::MORNING => '🌅 Morning 6-12',
            self::AFTERNOON => '☀️ Afternoon 12-6',
            self::EVENING => '🌙 Evening 6-10',
            self::FLEXIBLE => '🔄 Flexible',
        };
    }

    /**
     * Start hour (24h).
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
     * End hour (24h).
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
     * Check if hour is within this slot.
     */
    public function includesHour(int $hour): bool
    {
        if ($this === self::FLEXIBLE) {
            return $hour >= 6 && $hour <= 22;
        }
        return $hour >= $this->startHour() && $hour < $this->endHour();
    }

    /**
     * Time range display.
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
     * To list item for WhatsApp.
     */
    public function toListItem(): array
    {
        return [
            'id' => 'avail_' . $this->value,
            'title' => $this->emoji() . ' ' . $this->label(),
            'description' => $this->labelMl(),
        ];
    }

    /**
     * Get all as list items.
     */
    public static function toListItems(): array
    {
        return array_map(fn(self $a) => $a->toListItem(), self::cases());
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Create from button/list ID.
     */
    public static function fromId(string $id): ?self
    {
        $value = str_replace('avail_', '', $id);
        return self::tryFrom($value);
    }

    /**
     * Get availabilities covering a specific hour.
     */
    public static function forHour(int $hour): array
    {
        return array_filter(self::cases(), fn(self $a) => $a->includesHour($hour));
    }
}