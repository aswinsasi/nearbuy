<?php

namespace App\Enums;

/**
 * Job category identifiers for Njaanum Panikkar.
 *
 * @srs-ref Section 3.1 - Job Categories Master Data
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobCategory: string
{
    // Tier 1 - Zero Skills Required
    case QUEUE_STANDING = 'queue_standing';
    case PARCEL_DELIVERY = 'parcel_delivery';
    case GROCERY_SHOPPING = 'grocery_shopping';
    case BILL_PAYMENT = 'bill_payment';
    case MOVING_HELP = 'moving_help';
    case EVENT_HELPER = 'event_helper';
    case PET_WALKING = 'pet_walking';
    case GARDEN_CLEANING = 'garden_cleaning';
    case HOUSE_CLEANING = 'house_cleaning';
    case ELDERLY_COMPANION = 'elderly_companion';

    // Tier 2 - Basic Skills Required
    case FOOD_DELIVERY = 'food_delivery';
    case DOCUMENT_WORK = 'document_work';
    case COMPUTER_TYPING = 'computer_typing';
    case TRANSLATION = 'translation';
    case PHOTOGRAPHY = 'photography';
    case VEHICLE_PICKUP = 'vehicle_pickup';
    case MEDICINE_PICKUP = 'medicine_pickup';
    case TUTORING = 'tutoring';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::QUEUE_STANDING => 'Queue Standing',
            self::PARCEL_DELIVERY => 'Parcel Pickup/Delivery',
            self::GROCERY_SHOPPING => 'Grocery Shopping',
            self::BILL_PAYMENT => 'Bill Payment',
            self::MOVING_HELP => 'Moving Help',
            self::EVENT_HELPER => 'Event Helper',
            self::PET_WALKING => 'Pet Walking',
            self::GARDEN_CLEANING => 'Garden Cleaning',
            self::HOUSE_CLEANING => 'House Cleaning',
            self::ELDERLY_COMPANION => 'Elderly Companion',
            self::FOOD_DELIVERY => 'Food Delivery',
            self::DOCUMENT_WORK => 'Document Work',
            self::COMPUTER_TYPING => 'Computer Typing',
            self::TRANSLATION => 'Translation Help',
            self::PHOTOGRAPHY => 'Basic Photography',
            self::VEHICLE_PICKUP => 'Vehicle Pickup/Drop',
            self::MEDICINE_PICKUP => 'Medicine Pickup',
            self::TUTORING => 'Tutoring Help',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::QUEUE_STANDING => 'ക്യൂ നിൽക്കൽ',
            self::PARCEL_DELIVERY => 'പാഴ്സൽ എടുക്കൽ',
            self::GROCERY_SHOPPING => 'സാധനം വാങ്ങൽ',
            self::BILL_PAYMENT => 'ബിൽ അടയ്ക്കൽ',
            self::MOVING_HELP => 'സാധനം എടുക്കാൻ',
            self::EVENT_HELPER => 'ചടങ്ങിൽ സഹായം',
            self::PET_WALKING => 'നായയെ നടത്തൽ',
            self::GARDEN_CLEANING => 'തോട്ടം വൃത്തിയാക്കൽ',
            self::HOUSE_CLEANING => 'വീട് വൃത്തിയാക്കൽ',
            self::ELDERLY_COMPANION => 'വയോധികർക്ക് കൂട്ട്',
            self::FOOD_DELIVERY => 'ഭക്ഷണം എത്തിക്കൽ',
            self::DOCUMENT_WORK => 'ഡോക്യുമെന്റ് പണി',
            self::COMPUTER_TYPING => 'ടൈപ്പിംഗ്',
            self::TRANSLATION => 'തർജ്ജമ',
            self::PHOTOGRAPHY => 'ഫോട്ടോ എടുക്കൽ',
            self::VEHICLE_PICKUP => 'വാഹനം കൊണ്ടുവരൽ',
            self::MEDICINE_PICKUP => 'മരുന്ന് വാങ്ങൽ',
            self::TUTORING => 'ട്യൂഷൻ സഹായം',
        };
    }

    /**
     * Get emoji icon.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::QUEUE_STANDING => '🧍',
            self::PARCEL_DELIVERY => '📦',
            self::GROCERY_SHOPPING => '🛒',
            self::BILL_PAYMENT => '💵',
            self::MOVING_HELP => '📦',
            self::EVENT_HELPER => '🎉',
            self::PET_WALKING => '🐕',
            self::GARDEN_CLEANING => '🌳',
            self::HOUSE_CLEANING => '🧹',
            self::ELDERLY_COMPANION => '👴',
            self::FOOD_DELIVERY => '🍔',
            self::DOCUMENT_WORK => '📄',
            self::COMPUTER_TYPING => '⌨️',
            self::TRANSLATION => '🗣️',
            self::PHOTOGRAPHY => '📷',
            self::VEHICLE_PICKUP => '🚗',
            self::MEDICINE_PICKUP => '💊',
            self::TUTORING => '📚',
        };
    }

    /**
     * Get tier (1=zero_skills, 2=basic_skills).
     */
    public function tier(): int
    {
        return match ($this) {
            self::QUEUE_STANDING,
            self::PARCEL_DELIVERY,
            self::GROCERY_SHOPPING,
            self::BILL_PAYMENT,
            self::MOVING_HELP,
            self::EVENT_HELPER,
            self::PET_WALKING,
            self::GARDEN_CLEANING,
            self::HOUSE_CLEANING,
            self::ELDERLY_COMPANION => 1,

            self::FOOD_DELIVERY,
            self::DOCUMENT_WORK,
            self::COMPUTER_TYPING,
            self::TRANSLATION,
            self::PHOTOGRAPHY,
            self::VEHICLE_PICKUP,
            self::MEDICINE_PICKUP,
            self::TUTORING => 2,
        };
    }

    /**
     * Get typical pay range [min, max] in INR.
     */
    public function typicalPayRange(): array
    {
        return match ($this) {
            self::QUEUE_STANDING => [100, 200],
            self::PARCEL_DELIVERY => [50, 150],
            self::GROCERY_SHOPPING => [80, 150],
            self::BILL_PAYMENT => [50, 100],
            self::MOVING_HELP => [200, 500],
            self::EVENT_HELPER => [300, 500],
            self::PET_WALKING => [100, 200],
            self::GARDEN_CLEANING => [200, 400],
            self::HOUSE_CLEANING => [200, 500],
            self::ELDERLY_COMPANION => [150, 300],
            self::FOOD_DELIVERY => [50, 100],
            self::DOCUMENT_WORK => [50, 100],
            self::COMPUTER_TYPING => [100, 300],
            self::TRANSLATION => [200, 500],
            self::PHOTOGRAPHY => [200, 500],
            self::VEHICLE_PICKUP => [150, 400],
            self::MEDICINE_PICKUP => [50, 150],
            self::TUTORING => [200, 500],
        };
    }

    /**
     * Get typical duration in hours.
     */
    public function typicalDuration(): float
    {
        return match ($this) {
            self::QUEUE_STANDING => 2.0,
            self::PARCEL_DELIVERY => 0.75,
            self::GROCERY_SHOPPING => 1.5,
            self::BILL_PAYMENT => 1.0,
            self::MOVING_HELP => 3.0,
            self::EVENT_HELPER => 6.0,
            self::PET_WALKING => 1.0,
            self::GARDEN_CLEANING => 2.5,
            self::HOUSE_CLEANING => 3.0,
            self::ELDERLY_COMPANION => 3.0,
            self::FOOD_DELIVERY => 0.5,
            self::DOCUMENT_WORK => 1.0,
            self::COMPUTER_TYPING => 2.0,
            self::TRANSLATION => 2.0,
            self::PHOTOGRAPHY => 2.0,
            self::VEHICLE_PICKUP => 1.0,
            self::MEDICINE_PICKUP => 1.0,
            self::TUTORING => 2.0,
        };
    }

    /**
     * Get typical duration as display string.
     */
    public function typicalDurationDisplay(): string
    {
        $hours = $this->typicalDuration();
        if ($hours < 1) {
            return (int)($hours * 60) . ' mins';
        }
        return $hours . ' hrs';
    }

    /**
     * Check if job requires vehicle.
     */
    public function requiresVehicle(): bool
    {
        return match ($this) {
            self::FOOD_DELIVERY,
            self::VEHICLE_PICKUP => true,
            default => false,
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
     * Get tier label.
     */
    public function tierLabel(): string
    {
        return $this->tier() === 1 ? 'Zero Skills' : 'Basic Skills';
    }

    /**
     * Get tier label in Malayalam.
     */
    public function tierLabelMl(): string
    {
        return $this->tier() === 1 ? 'കഴിവ് വേണ്ട' : 'അടിസ്ഥാന കഴിവ്';
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        [$min, $max] = $this->typicalPayRange();
        return [
            'id' => 'job_cat_' . $this->value,
            'title' => substr($this->buttonTitle(), 0, 24),
            'description' => "₹{$min}-{$max} • " . $this->typicalDurationDisplay(),
        ];
    }

    /**
     * Get Tier 1 categories (zero skills).
     */
    public static function tier1(): array
    {
        return array_filter(self::cases(), fn(self $cat) => $cat->tier() === 1);
    }

    /**
     * Get Tier 2 categories (basic skills).
     */
    public static function tier2(): array
    {
        return array_filter(self::cases(), fn(self $cat) => $cat->tier() === 2);
    }

    /**
     * Get categories requiring vehicle.
     */
    public static function vehicleRequired(): array
    {
        return array_filter(self::cases(), fn(self $cat) => $cat->requiresVehicle());
    }

    /**
     * Get all as WhatsApp list items grouped by tier.
     */
    public static function toListSections(): array
    {
        return [
            [
                'title' => '🟢 Zero Skills Required',
                'rows' => array_map(fn(self $cat) => $cat->toListItem(), self::tier1()),
            ],
            [
                'title' => '🔵 Basic Skills Required',
                'rows' => array_map(fn(self $cat) => $cat->toListItem(), self::tier2()),
            ],
        ];
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
        $value = str_replace('job_cat_', '', $listId);
        return self::tryFrom($value);
    }
}