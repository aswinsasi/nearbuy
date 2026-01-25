<?php

namespace App\Enums;

/**
 * User types in the NearBuy platform.
 */
enum UserType: string
{
    case CUSTOMER = 'customer';
    case SHOP = 'shop';
    case FISH_SELLER = 'fish_seller';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER => 'Customer',
            self::SHOP => 'Shop Owner',
            self::FISH_SELLER => 'Fish Seller',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::CUSTOMER => 'ഉപഭോക്താവ്',
            self::SHOP => 'കട ഉടമ',
            self::FISH_SELLER => 'മീൻ വിൽപ്പനക്കാരൻ',
        };
    }

    /**
     * Get icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::CUSTOMER => '👤',
            self::SHOP => '🏪',
            self::FISH_SELLER => '🐟',
        };
    }

    /**
     * Check if user type can create offers.
     */
    public function canCreateOffers(): bool
    {
        return $this === self::SHOP;
    }

    /**
     * Check if user type can respond to product requests.
     */
    public function canRespondToRequests(): bool
    {
        return $this === self::SHOP;
    }

    /**
     * Check if user type can post fish catches.
     */
    public function canPostCatches(): bool
    {
        return $this === self::FISH_SELLER;
    }

    /**
     * Check if user type can subscribe to fish alerts.
     */
    public function canSubscribeToFishAlerts(): bool
    {
        return in_array($this, [self::CUSTOMER, self::SHOP]);
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get options for WhatsApp buttons.
     */
    public static function toButtons(): array
    {
        return array_map(fn(self $type) => [
            'id' => $type->value,
            'title' => substr("{$type->icon()} {$type->label()}", 0, 20),
        ], self::cases());
    }

    /**
     * Get registration options (excludes admin types).
     */
    public static function registrationOptions(): array
    {
        return [
            self::CUSTOMER,
            self::SHOP,
            self::FISH_SELLER,
        ];
    }
}
