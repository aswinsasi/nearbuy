<?php

namespace App\Enums;

/**
 * Quantity ranges for fish catch postings.
 *
 * @srs-ref Section 2.5.1 Step 4 - Quantity button options
 */
enum FishQuantityRange: string
{
    case KG_5_10 = '5_10';
    case KG_10_25 = '10_25';
    case KG_25_50 = '25_50';
    case KG_50_PLUS = '50_plus';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::KG_5_10 => '5-10 kg',
            self::KG_10_25 => '10-25 kg',
            self::KG_25_50 => '25-50 kg',
            self::KG_50_PLUS => '50+ kg',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::KG_5_10 => '5-10 കിലോ',
            self::KG_10_25 => '10-25 കിലോ',
            self::KG_25_50 => '25-50 കിലോ',
            self::KG_50_PLUS => '50+ കിലോ',
        };
    }

    /**
     * Get emoji for display.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::KG_5_10 => '📦',
            self::KG_10_25 => '📦📦',
            self::KG_25_50 => '📦📦📦',
            self::KG_50_PLUS => '🚛',
        };
    }

    /**
     * Get button title for WhatsApp.
     */
    public function buttonTitle(): string
    {
        return $this->emoji() . ' ' . $this->label();
    }

    /**
     * Get minimum kg value.
     */
    public function minKg(): int
    {
        return match ($this) {
            self::KG_5_10 => 5,
            self::KG_10_25 => 10,
            self::KG_25_50 => 25,
            self::KG_50_PLUS => 50,
        };
    }

    /**
     * Get maximum kg value (null for unlimited).
     */
    public function maxKg(): ?int
    {
        return match ($this) {
            self::KG_5_10 => 10,
            self::KG_10_25 => 25,
            self::KG_25_50 => 50,
            self::KG_50_PLUS => null,
        };
    }

    /**
     * Get approximate display value for alerts.
     */
    public function approximateDisplay(): string
    {
        return match ($this) {
            self::KG_5_10 => '~8 kg',
            self::KG_10_25 => '~15 kg',
            self::KG_25_50 => '~35 kg',
            self::KG_50_PLUS => '50+ kg',
        };
    }

    /**
     * Convert to WhatsApp button array.
     */
    public function toButton(): array
    {
        return [
            'id' => 'qty_' . $this->value,
            'title' => substr($this->buttonTitle(), 0, 20),
        ];
    }

    /**
     * Get all as WhatsApp buttons.
     */
    public static function toButtons(): array
    {
        return array_map(fn(self $range) => $range->toButton(), self::cases());
    }

    /**
     * Get all values as array.
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
}
