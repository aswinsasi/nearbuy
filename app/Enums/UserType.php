<?php

namespace App\Enums;

/**
 * User types in the NearBuy platform.
 *
 * IMPORTANT: Only CUSTOMER and SHOP are PRIMARY user types per SRS Section 6.3.
 * Fish sellers and job workers are ADDITIONAL PROFILES (separate tables),
 * not user types. Any user can add these profiles later.
 *
 * @srs-ref Section 6.3 - users.type: customer, shop
 * @srs-ref Section 2.3 - User Classes (Customer, Shop Owner)
 */
enum UserType: string
{
    case CUSTOMER = 'customer';
    case SHOP = 'shop';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER => 'Customer',
            self::SHOP => 'Shop Owner',
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
        };
    }

    /**
     * Get icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::CUSTOMER => '🛒',
            self::SHOP => '🏪',
        };
    }

    /**
     * Get labeled icon.
     */
    public function labeledIcon(): string
    {
        return $this->icon() . ' ' . $this->label();
    }

    /**
     * Get button title (max 20 chars for WhatsApp).
     */
    public function buttonTitle(): string
    {
        return mb_substr($this->labeledIcon(), 0, 20);
    }

    /*
    |--------------------------------------------------------------------------
    | Permission Checks
    |--------------------------------------------------------------------------
    */

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
     * Check if user type can create flash deals.
     *
     * @srs-ref Section 4 - Flash Mob Deals (Shop owners only)
     */
    public function canCreateFlashDeals(): bool
    {
        return $this === self::SHOP;
    }

    /**
     * Check if user type can subscribe to fish alerts.
     * All users can subscribe to fish alerts.
     */
    public function canSubscribeToFishAlerts(): bool
    {
        return true;
    }

    /**
     * Check if user type can post jobs/tasks.
     * Anyone can post tasks - they become "task givers".
     *
     * @srs-ref Section 3.4.2 - Job Posting (Task Giver)
     */
    public function canPostJobs(): bool
    {
        return true;
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
            'id' => $type->value,
            'title' => $type->buttonTitle(),
        ], self::cases());
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
        ])->toArray();
    }
}