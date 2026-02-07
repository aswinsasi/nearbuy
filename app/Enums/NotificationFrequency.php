<?php

namespace App\Enums;

/**
 * Notification frequency - EXACTLY 4 options from SRS Appendix 8.3.
 *
 * 🔔 Immediately — Send each request as it arrives
 * ⏰ Every 2 Hours — Batch requests (Recommended)
 * 📅 Twice Daily — Morning 9AM & Evening 5PM
 * 🌅 Once Daily — Morning 9AM only
 *
 * @srs-ref SRS Appendix 8.3 - Notification Frequency Options
 * @srs-ref FR-SHOP-04 - Collect notification frequency preference via list
 */
enum NotificationFrequency: string
{
    case IMMEDIATE = 'immediate';
    case EVERY_2_HOURS = '2hours';
    case TWICE_DAILY = 'twice_daily';
    case DAILY = 'daily';

    /**
     * Get English label (from SRS Appendix 8.3).
     */
    public function label(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'Immediately',
            self::EVERY_2_HOURS => 'Every 2 Hours',
            self::TWICE_DAILY => 'Twice Daily',
            self::DAILY => 'Once Daily',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'ഉടൻ തന്നെ',
            self::EVERY_2_HOURS => '2 മണിക്കൂർ കൂടുമ്പോൾ',
            self::TWICE_DAILY => 'ദിവസം 2 തവണ',
            self::DAILY => 'ദിവസം 1 തവണ',
        };
    }

    /**
     * Get emoji icon (from SRS Appendix 8.3).
     */
    public function icon(): string
    {
        return match ($this) {
            self::IMMEDIATE => '🔔',
            self::EVERY_2_HOURS => '⏰',
            self::TWICE_DAILY => '📅',
            self::DAILY => '🌅',
        };
    }

    /**
     * Get description (from SRS Appendix 8.3).
     */
    public function description(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'Send each request as it arrives',
            self::EVERY_2_HOURS => 'Batch requests (Recommended)',
            self::TWICE_DAILY => 'Morning 9AM & Evening 5PM',
            self::DAILY => 'Morning 9AM only',
        };
    }

    /**
     * Get Malayalam description.
     */
    public function descriptionMl(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'ഓരോ request-ഉം ഉടൻ',
            self::EVERY_2_HOURS => 'Batch ആയി (Recommended)',
            self::TWICE_DAILY => 'രാവിലെ 9, വൈകുന്നേരം 5',
            self::DAILY => 'രാവിലെ 9 മണിക്ക് മാത്രം',
        };
    }

    /**
     * Check if this is the recommended option.
     */
    public function isRecommended(): bool
    {
        return $this === self::EVERY_2_HOURS;
    }

    /**
     * Get display for WhatsApp list title (max 24 chars).
     * Marks recommended option with ✓.
     */
    public function listTitle(): string
    {
        $suffix = $this->isRecommended() ? ' ✓' : '';
        return mb_substr("{$this->icon()} {$this->label()}{$suffix}", 0, 24);
    }

    /**
     * Get formatted display with icon.
     */
    public function displayWithIcon(): string
    {
        return "{$this->icon()} {$this->label()}";
    }

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
     * Get default value (recommended).
     */
    public static function default(): self
    {
        return self::EVERY_2_HOURS;
    }

    /**
     * Get WhatsApp list sections - all 4 frequencies.
     *
     * @srs-ref FR-SHOP-04 - Collect notification frequency preference via list
     */
    public static function toListSections(): array
    {
        $rows = array_map(fn(self $freq) => [
            'id' => $freq->value,
            'title' => $freq->listTitle(),
            'description' => $freq->description(),
        ], self::cases());

        return [
            [
                'title' => 'Alert Frequency',
                'rows' => $rows,
            ],
        ];
    }

    /**
     * Get options for forms/select.
     */
    public static function options(): array
    {
        return collect(self::cases())->map(fn($case) => [
            'value' => $case->value,
            'label' => $case->label(),
            'label_ml' => $case->labelMl(),
            'icon' => $case->icon(),
            'description' => $case->description(),
            'recommended' => $case->isRecommended(),
        ])->toArray();
    }
}