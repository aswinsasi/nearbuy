<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Notification frequency options.
 *
 * For SHOP OWNERS - 4 options (SRS Appendix 8.3):
 * 🔔 Immediately — Send each request as it arrives
 * ⏰ Every 2 Hours — Batch requests (Recommended)
 * 📅 Twice Daily — Morning 9AM & Evening 5PM
 * 🌅 Once Daily — Morning 9AM only
 *
 * For CUSTOMERS - Simple ON/OFF toggle
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
    case OFF = 'off';  // For customers who want to disable notifications

    /**
     * Get English label.
     */
    public function label(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'Immediately',
            self::EVERY_2_HOURS => 'Every 2 Hours',
            self::TWICE_DAILY => 'Twice Daily',
            self::DAILY => 'Once Daily',
            self::OFF => 'Off',
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
            self::OFF => 'ഓഫ്',
        };
    }

    /**
     * Get bilingual label.
     */
    public function labelBilingual(): string
    {
        return "{$this->label()} / {$this->labelMl()}";
    }

    /**
     * Get emoji icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::IMMEDIATE => '🔔',
            self::EVERY_2_HOURS => '⏰',
            self::TWICE_DAILY => '📅',
            self::DAILY => '🌅',
            self::OFF => '🔕',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::IMMEDIATE => 'Send each request as it arrives',
            self::EVERY_2_HOURS => 'Batch requests (Recommended)',
            self::TWICE_DAILY => 'Morning 9AM & Evening 5PM',
            self::DAILY => 'Morning 9AM only',
            self::OFF => 'No notifications',
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
            self::OFF => 'നോട്ടിഫിക്കേഷൻ ഇല്ല',
        };
    }

    /**
     * Get display with icon.
     */
    public function display(): string
    {
        return "{$this->icon()} {$this->label()}";
    }

    /**
     * Check if this is the recommended option.
     */
    public function isRecommended(): bool
    {
        return $this === self::EVERY_2_HOURS;
    }

    /**
     * Check if notifications are enabled.
     */
    public function isEnabled(): bool
    {
        return $this !== self::OFF;
    }

    /**
     * Get list title (max 24 chars for WhatsApp).
     */
    public function listTitle(): string
    {
        $suffix = $this->isRecommended() ? ' ✓' : '';
        return mb_substr("{$this->icon()} {$this->label()}{$suffix}", 0, 24);
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get shop owner frequencies (excludes OFF).
     */
    public static function shopFrequencies(): array
    {
        return [
            self::IMMEDIATE,
            self::EVERY_2_HOURS,
            self::TWICE_DAILY,
            self::DAILY,
        ];
    }

    /**
     * Get customer toggle options.
     */
    public static function customerOptions(): array
    {
        return [
            self::IMMEDIATE,
            self::OFF,
        ];
    }

    /**
     * Check if value is valid.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values());
    }

    /**
     * Get default for shop owners.
     */
    public static function defaultForShop(): self
    {
        return self::EVERY_2_HOURS;
    }

    /**
     * Get default for customers.
     */
    public static function defaultForCustomer(): self
    {
        return self::IMMEDIATE;
    }

    /**
     * Get WhatsApp list sections for SHOP OWNERS (4 frequencies).
     *
     * @srs-ref FR-SHOP-04
     */
    public static function toShopListSections(): array
    {
        $rows = [];

        foreach (self::shopFrequencies() as $freq) {
            $rows[] = [
                'id' => 'notif_' . $freq->value,
                'title' => $freq->listTitle(),
                'description' => $freq->description(),
            ];
        }

        return [
            [
                'title' => 'Alert Frequency',
                'rows' => $rows,
            ],
        ];
    }

    /**
     * Get WhatsApp buttons for CUSTOMER toggle (ON/OFF).
     */
    public static function toCustomerButtons(): array
    {
        return [
            ['id' => 'notif_on', 'title' => '🔔 ON'],
            ['id' => 'notif_off', 'title' => '🔕 OFF'],
        ];
    }

    /**
     * Parse from button/list selection ID.
     */
    public static function fromSelectionId(string $id): ?self
    {
        // Handle customer toggle
        if ($id === 'notif_on') {
            return self::IMMEDIATE;
        }
        if ($id === 'notif_off') {
            return self::OFF;
        }

        // Handle shop frequencies
        $value = str_replace('notif_', '', $id);
        return self::tryFrom($value);
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