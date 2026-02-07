<?php

namespace App\Enums;

/**
 * Shop categories - EXACTLY 8 from SRS Appendix 8.1.
 *
 * 🛒 Grocery — Vegetables, fruits, daily needs
 * 📱 Electronics — TV, laptop, gadgets
 * 👕 Clothes — Fashion, textiles
 * 💊 Medical — Pharmacy, health products
 * 🪑 Furniture — Home & office furniture
 * 📲 Mobile — Phones & accessories
 * 🔌 Appliances — AC, fridge, washing machine
 * 🔧 Hardware — Tools, construction materials
 *
 * @srs-ref SRS Appendix 8.1 - Shop Categories
 * @srs-ref FR-SHOP-02 - Present shop category via list message (8 categories)
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

    /**
     * Get English label.
     */
    public function label(): string
    {
        return match ($this) {
            self::GROCERY => 'Grocery',
            self::ELECTRONICS => 'Electronics',
            self::CLOTHES => 'Clothes',
            self::MEDICAL => 'Medical',
            self::FURNITURE => 'Furniture',
            self::MOBILE => 'Mobile',
            self::APPLIANCES => 'Appliances',
            self::HARDWARE => 'Hardware',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::GROCERY => 'പലചരക്ക്',
            self::ELECTRONICS => 'ഇലക്ട്രോണിക്സ്',
            self::CLOTHES => 'വസ്ത്രങ്ങൾ',
            self::MEDICAL => 'മെഡിക്കൽ',
            self::FURNITURE => 'ഫർണിച്ചർ',
            self::MOBILE => 'മൊബൈൽ',
            self::APPLIANCES => 'ഉപകരണങ്ങൾ',
            self::HARDWARE => 'ഹാർഡ്‌വെയർ',
        };
    }

    /**
     * Get emoji icon (from SRS Appendix 8.1).
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
        };
    }

    /**
     * Get description (from SRS Appendix 8.1).
     */
    public function description(): string
    {
        return match ($this) {
            self::GROCERY => 'Vegetables, fruits, daily needs',
            self::ELECTRONICS => 'TV, laptop, gadgets',
            self::CLOTHES => 'Fashion, textiles',
            self::MEDICAL => 'Pharmacy, health products',
            self::FURNITURE => 'Home & office furniture',
            self::MOBILE => 'Phones & accessories',
            self::APPLIANCES => 'AC, fridge, washing machine',
            self::HARDWARE => 'Tools, construction materials',
        };
    }

    /**
     * Get Malayalam description.
     */
    public function descriptionMl(): string
    {
        return match ($this) {
            self::GROCERY => 'പച്ചക്കറി, പഴം, ദിനവൃത്തി',
            self::ELECTRONICS => 'ടിവി, ലാപ്ടോപ്പ്, ഗാഡ്ജറ്റ്',
            self::CLOTHES => 'ഫാഷൻ, തുണിത്തരം',
            self::MEDICAL => 'ഫാർമസി, ആരോഗ്യം',
            self::FURNITURE => 'വീട്, ഓഫീസ് ഫർണിച്ചർ',
            self::MOBILE => 'ഫോൺ, ആക്സസറീസ്',
            self::APPLIANCES => 'AC, ഫ്രിഡ്ജ്, വാഷിംഗ് മെഷീൻ',
            self::HARDWARE => 'ടൂൾസ്, കെട്ടിട സാമഗ്രി',
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
     * Check if value is valid.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values());
    }

    /**
     * Get WhatsApp list sections - all 8 categories fit in one list.
     *
     * @srs-ref FR-SHOP-02 - Present shop category via list message (8 categories)
     */
    public static function toListSections(): array
    {
        $rows = array_map(fn(self $cat) => [
            'id' => $cat->value,
            'title' => mb_substr($cat->displayWithIcon(), 0, 24),
            'description' => $cat->description(),
        ], self::cases());

        return [
            [
                'title' => 'Shop Category',
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
        ])->toArray();
    }
}