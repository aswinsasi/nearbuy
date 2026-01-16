<?php

namespace App\Enums;

/**
 * User roles in the NearBuy platform.
 */
enum UserRole: string
{
    case CUSTOMER = 'customer';
    case SHOP_OWNER = 'shop_owner';
    case ADMIN = 'admin';

    /**
     * Get the display name for the role.
     */
    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER => 'Customer',
            self::SHOP_OWNER => 'Shop Owner',
            self::ADMIN => 'Administrator',
        };
    }

    /**
     * Get the Malayalam name for the role.
     */
    public function labelMalayalam(): string
    {
        return match ($this) {
            self::CUSTOMER => 'ഉപഭോക്താവ്',
            self::SHOP_OWNER => 'കട ഉടമ',
            self::ADMIN => 'അഡ്മിൻ',
        };
    }

    /**
     * Check if role can create offers.
     */
    public function canCreateOffers(): bool
    {
        return in_array($this, [self::SHOP_OWNER, self::ADMIN]);
    }

    /**
     * Check if role can respond to product requests.
     */
    public function canRespondToRequests(): bool
    {
        return in_array($this, [self::SHOP_OWNER, self::ADMIN]);
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}