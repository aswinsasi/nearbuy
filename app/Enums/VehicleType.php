<?php

namespace App\Enums;

/**
 * Worker vehicle type options.
 *
 * @srs-ref Section 3.2 - Job Workers
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
            self::NONE => 'No Vehicle',
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
            self::NONE => 'വാഹനം ഇല്ല',
            self::TWO_WHEELER => 'ഇരുചക്രവാഹനം',
            self::FOUR_WHEELER => 'നാലുചക്ര വാഹനം',
        };
    }

    /**
     * Get emoji icon.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::NONE => '🚶',
            self::TWO_WHEELER => '🏍️',
            self::FOUR_WHEELER => '🚗',
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
     * Get button title for WhatsApp.
     */
    public function buttonTitle(): string
    {
        return $this->emoji() . ' ' . $this->label();
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
    public function deliveryRadius(): int
    {
        return match ($this) {
            self::NONE => 2,
            self::TWO_WHEELER => 10,
            self::FOUR_WHEELER => 25,
        };
    }

    /**
     * Get description for registration.
     */
    public function description(): string
    {
        return match ($this) {
            self::NONE => 'I will travel by walk or public transport',
            self::TWO_WHEELER => 'I have a bike/scooter for transportation',
            self::FOUR_WHEELER => 'I have a car/auto for transportation',
        };
    }

    /**
     * Get description in Malayalam.
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
     * Convert to WhatsApp button.
     */
    public function toButton(): array
    {
        return [
            'id' => 'vehicle_' . $this->value,
            'title' => substr($this->buttonTitle(), 0, 20),
        ];
    }

    /**
     * Get all as WhatsApp buttons.
     */
    public static function toButtons(): array
    {
        return array_map(fn(self $type) => $type->toButton(), self::cases());
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
        $value = str_replace('vehicle_', '', $buttonId);
        return self::tryFrom($value);
    }
}