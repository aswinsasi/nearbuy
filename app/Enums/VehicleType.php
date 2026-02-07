<?php

namespace App\Enums;

/**
 * Worker vehicle type options.
 *
 * @srs-ref NP-003 - Vehicle availability: None/Walking, Two Wheeler, Four Wheeler
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum VehicleType: string
{
    case NONE = 'none';
    case TWO_WHEELER = 'two_wheeler';
    case FOUR_WHEELER = 'four_wheeler';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::NONE => 'None / Walking',
            self::TWO_WHEELER => 'Two Wheeler',
            self::FOUR_WHEELER => 'Four Wheeler',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::NONE => 'നടക്കും / ബസ്',
            self::TWO_WHEELER => 'ബൈക്ക് / സ്കൂട്ടർ',
            self::FOUR_WHEELER => 'കാർ / ഓട്ടോ',
        };
    }

    /**
     * Get emoji icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::NONE => '🚶',
            self::TWO_WHEELER => '🏍️',
            self::FOUR_WHEELER => '🚗',
        };
    }

    /**
     * Get display with icon.
     */
    public function displayWithIcon(): string
    {
        return $this->icon() . ' ' . $this->label();
    }

    /**
     * Get button title for WhatsApp (max 20 chars).
     */
    public function buttonTitle(): string
    {
        return mb_substr($this->displayWithIcon(), 0, 20);
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::NONE => 'Walk or public transport',
            self::TWO_WHEELER => 'Bike or scooter available',
            self::FOUR_WHEELER => 'Car or auto available',
        };
    }

    /**
     * Get Malayalam description.
     */
    public function descriptionMl(): string
    {
        return match ($this) {
            self::NONE => 'നടന്നോ ബസിലോ പോകും',
            self::TWO_WHEELER => 'ബൈക്ക്/സ്കൂട്ടർ ഉണ്ട്',
            self::FOUR_WHEELER => 'കാർ/ഓട്ടോ ഉണ്ട്',
        };
    }

    /**
     * Check if has any vehicle.
     */
    public function hasVehicle(): bool
    {
        return $this !== self::NONE;
    }

    /**
     * Check if can do delivery jobs.
     */
    public function canDoDelivery(): bool
    {
        return $this !== self::NONE;
    }

    /**
     * Get typical delivery radius in km.
     */
    public function deliveryRadiusKm(): int
    {
        return match ($this) {
            self::NONE => 2,
            self::TWO_WHEELER => 10,
            self::FOUR_WHEELER => 25,
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
     * Get options for WhatsApp buttons.
     */
    public static function toButtons(): array
    {
        return array_map(fn(self $type) => [
            'id' => 'vehicle_' . $type->value,
            'title' => $type->buttonTitle(),
        ], self::cases());
    }

    /**
     * Create from button ID.
     */
    public static function fromButtonId(string $buttonId): ?self
    {
        $value = str_replace('vehicle_', '', $buttonId);
        return self::tryFrom($value);
    }

    /**
     * Get WhatsApp list sections.
     */
    public static function toListSections(): array
    {
        $rows = array_map(fn(self $type) => [
            'id' => $type->value,
            'title' => $type->buttonTitle(),
            'description' => $type->description(),
        ], self::cases());

        return [
            [
                'title' => 'Vehicle Type',
                'rows' => $rows,
            ],
        ];
    }
}