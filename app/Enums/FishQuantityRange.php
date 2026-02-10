<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Quantity ranges for fish catch postings.
 *
 * @srs-ref PM-006 Quantity ranges: 5-10kg, 10-25kg, 25-50kg, 50+kg
 */
enum FishQuantityRange: string
{
    case RANGE_5_10 = '5_10';
    case RANGE_10_25 = '10_25';
    case RANGE_25_50 = '25_50';
    case RANGE_50_PLUS = '50_plus';

    /**
     * Short label for buttons (max 20 chars).
     */
    public function label(): string
    {
        return match ($this) {
            self::RANGE_5_10 => '5-10 kg',
            self::RANGE_10_25 => '10-25 kg',
            self::RANGE_25_50 => '25-50 kg',
            self::RANGE_50_PLUS => '50+ kg',
        };
    }

    /**
     * Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::RANGE_5_10 => '5-10 കിലോ',
            self::RANGE_10_25 => '10-25 കിലോ',
            self::RANGE_25_50 => '25-50 കിലോ',
            self::RANGE_50_PLUS => '50+ കിലോ',
        };
    }

    /**
     * Button title for WhatsApp (SHORT!).
     */
    public function buttonTitle(): string
    {
        return $this->label(); // Keep it short!
    }

    /**
     * Minimum kg.
     */
    public function minKg(): int
    {
        return match ($this) {
            self::RANGE_5_10 => 5,
            self::RANGE_10_25 => 10,
            self::RANGE_25_50 => 25,
            self::RANGE_50_PLUS => 50,
        };
    }

    /**
     * Maximum kg (null for unlimited).
     */
    public function maxKg(): ?int
    {
        return match ($this) {
            self::RANGE_5_10 => 10,
            self::RANGE_10_25 => 25,
            self::RANGE_25_50 => 50,
            self::RANGE_50_PLUS => null,
        };
    }

    /**
     * Approximate display for alerts.
     */
    public function approximateDisplay(): string
    {
        return match ($this) {
            self::RANGE_5_10 => '~8 kg',
            self::RANGE_10_25 => '~15 kg',
            self::RANGE_25_50 => '~35 kg',
            self::RANGE_50_PLUS => '50+ kg',
        };
    }

    /**
     * Convert to WhatsApp button.
     */
    public function toButton(): array
    {
        return [
            'id' => 'qty_' . $this->value,
            'title' => $this->buttonTitle(),
        ];
    }

    /**
     * Get first 3 as buttons (WhatsApp limit).
     * Returns most common ranges.
     */
    public static function toButtons(): array
    {
        // Only 3 buttons allowed - show most common
        return [
            self::RANGE_5_10->toButton(),
            self::RANGE_10_25->toButton(),
            self::RANGE_25_50->toButton(),
        ];
    }

    /**
     * Get all as list items (for list message).
     */
    public static function toListItems(): array
    {
        return array_map(fn(self $range) => [
            'id' => 'qty_' . $range->value,
            'title' => $range->label(),
            'description' => $range->approximateDisplay(),
        ], self::cases());
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Create from button ID.
     */
    public static function fromButtonId(string $buttonId): ?self
    {
        $value = str_replace('qty_', '', $buttonId);
        return self::tryFrom($value);
    }

    /**
     * Guess range from kg input.
     */
    public static function fromKg(int $kg): self
    {
        if ($kg <= 10) return self::RANGE_5_10;
        if ($kg <= 25) return self::RANGE_10_25;
        if ($kg <= 50) return self::RANGE_25_50;
        return self::RANGE_50_PLUS;
    }
}