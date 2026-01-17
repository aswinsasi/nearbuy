<?php

namespace App\Enums;

/**
 * Shop categories available in NearBuy.
 */
enum ShopCategory: string
{
    case GROCERY = 'grocery';
    case ELECTRONICS = 'electronics';
    case CLOTHES = 'clothes';
    case MEDICAL = 'medical';
    case FURNITURE = 'furniture';
    case MOBILE = 'mobile';
    case APPLIANCES = 'appliances';
    case HARDWARE = 'hardware';
    case RESTAURANT = 'restaurant';
    case BAKERY = 'bakery';
    case STATIONERY = 'stationery';
    case BEAUTY = 'beauty';
    case AUTOMOTIVE = 'automotive';
    case JEWELRY = 'jewelry';
    case SPORTS = 'sports';
    case OTHER = 'other';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::GROCERY => 'Grocery Store',
            self::ELECTRONICS => 'Electronics',
            self::CLOTHES => 'Clothing & Fashion',
            self::MEDICAL => 'Medical / Pharmacy',
            self::FURNITURE => 'Furniture',
            self::MOBILE => 'Mobile & Accessories',
            self::APPLIANCES => 'Home Appliances',
            self::HARDWARE => 'Hardware Store',
            self::RESTAURANT => 'Restaurant & Food',
            self::BAKERY => 'Bakery',
            self::STATIONERY => 'Stationery & Books',
            self::BEAUTY => 'Beauty & Salon',
            self::AUTOMOTIVE => 'Automotive',
            self::JEWELRY => 'Jewelry',
            self::SPORTS => 'Sports & Fitness',
            self::OTHER => 'Other',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::GROCERY => 'പലചരക്ക് കട',
            self::ELECTRONICS => 'ഇലക്ട്രോണിക്സ്',
            self::CLOTHES => 'വസ്ത്രങ്ങൾ',
            self::MEDICAL => 'മെഡിക്കൽ ഷോപ്പ്',
            self::FURNITURE => 'ഫർണിച്ചർ',
            self::MOBILE => 'മൊബൈൽ ഷോപ്പ്',
            self::APPLIANCES => 'ഉപകരണങ്ങൾ',
            self::HARDWARE => 'ഹാർഡ്‌വെയർ',
            self::RESTAURANT => 'ഭക്ഷണശാല',
            self::BAKERY => 'ബേക്കറി',
            self::STATIONERY => 'സ്റ്റേഷനറി',
            self::BEAUTY => 'ബ്യൂട്ടി പാർലർ',
            self::AUTOMOTIVE => 'ഓട്ടോമൊബൈൽ',
            self::JEWELRY => 'ജ്വല്ലറി',
            self::SPORTS => 'സ്പോർട്സ്',
            self::OTHER => 'മറ്റുള്ളവ',
        };
    }

    /**
     * Get icon emoji.
     */
    public function icon(): string
    {
        return match ($this) {
            self::GROCERY => '🛒',
            self::ELECTRONICS => '📱',
            self::CLOTHES => '👕',
            self::MEDICAL => '💊',
            self::FURNITURE => '🪑',
            self::MOBILE => '📲',
            self::APPLIANCES => '🔌',
            self::HARDWARE => '🔧',
            self::RESTAURANT => '🍽️',
            self::BAKERY => '🥐',
            self::STATIONERY => '📚',
            self::BEAUTY => '💅',
            self::AUTOMOTIVE => '🚗',
            self::JEWELRY => '💎',
            self::SPORTS => '⚽',
            self::OTHER => '🏪',
        };
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
     * Get options for WhatsApp list (max 10 items per section).
     *
     * @return array<string, array>
     */
    public static function toListSections(): array
    {
        $all = self::cases();

        return [
            [
                'title' => 'Popular Categories',
                'rows' => array_map(fn(self $cat) => [
                    'id' => $cat->value,
                    'title' => substr($cat->displayWithIcon(), 0, 24),
                    'description' => '',
                ], array_slice($all, 0, 10)),
            ],
            [
                'title' => 'More Categories',
                'rows' => array_map(fn(self $cat) => [
                    'id' => $cat->value,
                    'title' => substr($cat->displayWithIcon(), 0, 24),
                    'description' => '',
                ], array_slice($all, 10)),
            ],
        ];
    }
}