<?php

namespace App\Enums;

/**
 * User roles for permission levels.
 *
 * NOTE: This is SEPARATE from UserType.
 * - UserType: What the user IS (customer or shop owner)
 * - UserRole: Permission level for admin features
 *
 * Most users have MEMBER role. ADMIN role is for platform administrators.
 */
enum UserRole: string
{
    case MEMBER = 'member';
    case MODERATOR = 'moderator';
    case ADMIN = 'admin';

    /**
     * Get the display name.
     */
    public function label(): string
    {
        return match ($this) {
            self::MEMBER => 'Member',
            self::MODERATOR => 'Moderator',
            self::ADMIN => 'Administrator',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::MEMBER => 'അംഗം',
            self::MODERATOR => 'മോഡറേറ്റർ',
            self::ADMIN => 'അഡ്മിൻ',
        };
    }

    /**
     * Get icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::MEMBER => '👤',
            self::MODERATOR => '🛡️',
            self::ADMIN => '👑',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Permission Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if role can verify shops.
     */
    public function canVerifyShops(): bool
    {
        return in_array($this, [self::MODERATOR, self::ADMIN]);
    }

    /**
     * Check if role can verify fish sellers.
     */
    public function canVerifyFishSellers(): bool
    {
        return in_array($this, [self::MODERATOR, self::ADMIN]);
    }

    /**
     * Check if role can moderate content.
     */
    public function canModerateContent(): bool
    {
        return in_array($this, [self::MODERATOR, self::ADMIN]);
    }

    /**
     * Check if role can access admin panel.
     */
    public function canAccessAdminPanel(): bool
    {
        return $this === self::ADMIN;
    }

    /**
     * Check if role can manage users.
     */
    public function canManageUsers(): bool
    {
        return $this === self::ADMIN;
    }

    /**
     * Check if role can view analytics.
     */
    public function canViewAnalytics(): bool
    {
        return in_array($this, [self::MODERATOR, self::ADMIN]);
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
     * Get default role for new users.
     */
    public static function default(): self
    {
        return self::MEMBER;
    }
}