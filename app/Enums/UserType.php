<?php

namespace App\Enums;

/**
 * User types in the NearBuy platform.
 *
 * A user has one PRIMARY type, but can have additional profiles:
 * - CUSTOMER can also be fish seller (fishSeller relation) or job worker (jobWorker relation)
 * - SHOP owner can also be fish seller and/or job worker
 *
 * @srs-ref Section 2.3 - User Classes (Customer, Shop Owner)
 * @srs-ref Section 2.2 - Pacha Meen (Fish Seller as additional role)
 * @srs-ref Section 3 - Njaanum Panikkar (Job Worker as additional role)
 */
enum UserType: string
{
    case CUSTOMER = 'customer';
    case SHOP = 'shop';
    case FISH_SELLER = 'fish_seller';
    case JOB_WORKER = 'job_worker';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::CUSTOMER => 'Customer',
            self::SHOP => 'Shop Owner',
            self::FISH_SELLER => 'Fish Seller',
            self::JOB_WORKER => 'Job Worker',
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
            self::JOB_WORKER => 'പണിക്കാരൻ',
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
            self::JOB_WORKER => '👷',
        };
    }

    /**
     * Get labeled icon.
     */
    public function labeledIcon(): string
    {
        return $this->icon() . ' ' . $this->label();
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
     * Check if user type can post fish catches.
     * Note: Users with fishSeller profile can also post, regardless of type.
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
        return in_array($this, [self::CUSTOMER, self::SHOP, self::JOB_WORKER]);
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
     * Check if user type can apply for jobs.
     * Note: Users with jobWorker profile can apply, regardless of type.
     *
     * @srs-ref Section 3 - Njaanum Panikkar
     */
    public function canApplyForJobs(): bool
    {
        return $this === self::JOB_WORKER;
    }

    /**
     * Check if user type can post jobs/tasks.
     * Anyone can post tasks - they become "task givers".
     *
     * @srs-ref Section 3.4.2 - Job Posting (Task Giver)
     */
    public function canPostJobs(): bool
    {
        return true; // All user types can post tasks
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
     * Get options for WhatsApp buttons.
     */
    public static function toButtons(): array
    {
        return array_map(fn(self $type) => [
            'id' => $type->value,
            'title' => mb_substr("{$type->icon()} {$type->label()}", 0, 20),
        ], self::registrationOptions());
    }

    /**
     * Get registration options.
     * Users register as CUSTOMER or SHOP initially.
     * FISH_SELLER and JOB_WORKER are additional profiles they can add later.
     */
    public static function registrationOptions(): array
    {
        return [
            self::CUSTOMER,
            self::SHOP,
        ];
    }

    /**
     * Get all options including secondary types.
     */
    public static function allOptions(): array
    {
        return [
            self::CUSTOMER,
            self::SHOP,
            self::FISH_SELLER,
            self::JOB_WORKER,
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
     * Try to create from string.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }
}