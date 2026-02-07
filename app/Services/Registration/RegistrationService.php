<?php

declare(strict_types=1);

namespace App\Services\Registration;

use App\Enums\NotificationFrequency;
use App\Enums\ShopCategory;
use App\Enums\UserType;
use App\Models\ConversationSession;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Registration Service - User and Shop creation.
 *
 * RESPONSIBILITIES:
 * - Check if user is registered (FR-REG-01)
 * - Validate all registration data
 * - Create user records (FR-REG-06)
 * - Create shop records (FR-SHOP-05)
 * - Handle referral tracking
 *
 * @srs-ref Section 3.1 - User Registration Requirements
 */
class RegistrationService
{
    /*
    |--------------------------------------------------------------------------
    | User Lookup (FR-REG-01)
    |--------------------------------------------------------------------------
    */

    /**
     * Check if phone is already registered.
     *
     * @srs-ref FR-REG-01 - Detect new users by checking phone against database
     */
    public function isRegistered(string $phone): bool
    {
        return User::where('phone', $this->normalizePhone($phone))
            ->whereNotNull('registered_at')
            ->exists();
    }

    /**
     * Find user by phone.
     */
    public function findByPhone(string $phone): ?User
    {
        return User::where('phone', $this->normalizePhone($phone))->first();
    }

    /**
     * Check if phone exists (registered or not).
     */
    public function phoneExists(string $phone): bool
    {
        return User::where('phone', $this->normalizePhone($phone))->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Customer Creation (FR-REG-06)
    |--------------------------------------------------------------------------
    */

    /**
     * Create a customer account.
     *
     * @param array $data {
     *     phone: string,
     *     name: string,
     *     latitude: float,
     *     longitude: float,
     *     address?: string,
     *     referrer_phone?: string
     * }
     *
     * @srs-ref FR-REG-06 - Store registration data with timestamp
     */
    public function createCustomer(array $data): User
    {
        $this->validateCustomerData($data);

        $phone = $this->normalizePhone($data['phone']);

        if ($this->phoneExists($phone)) {
            throw new \InvalidArgumentException('Phone already registered');
        }

        $user = User::create([
            'phone' => $phone,
            'name' => $this->sanitizeName($data['name']),
            'type' => UserType::CUSTOMER,
            'latitude' => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
            'address' => $data['address'] ?? null,
            'referred_by' => $this->resolveReferrer($data['referrer_phone'] ?? null),
            'registered_at' => now(),
        ]);

        Log::info('Customer registered', [
            'user_id' => $user->id,
            'phone' => $this->maskPhone($phone),
        ]);

        return $user;
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Owner Creation (FR-SHOP-05)
    |--------------------------------------------------------------------------
    */

    /**
     * Create shop owner with shop in transaction.
     *
     * @param array $data {
     *     phone: string,
     *     name: string,
     *     latitude: float,
     *     longitude: float,
     *     shop_name: string,
     *     shop_category: string,
     *     shop_latitude: float,
     *     shop_longitude: float,
     *     notification_frequency: string,
     *     address?: string,
     *     shop_address?: string,
     *     referrer_phone?: string
     * }
     *
     * @srs-ref FR-SHOP-05 - Create linked records in users and shops tables
     */
    public function createShopOwner(array $data): User
    {
        $this->validateShopOwnerData($data);

        $phone = $this->normalizePhone($data['phone']);

        if ($this->phoneExists($phone)) {
            throw new \InvalidArgumentException('Phone already registered');
        }

        return DB::transaction(function () use ($data, $phone) {
            // Create user
            $user = User::create([
                'phone' => $phone,
                'name' => $this->sanitizeName($data['name']),
                'type' => UserType::SHOP,
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'address' => $data['address'] ?? null,
                'referred_by' => $this->resolveReferrer($data['referrer_phone'] ?? null),
                'registered_at' => now(),
            ]);

            // Create shop
            Shop::create([
                'user_id' => $user->id,
                'shop_name' => $this->sanitizeName($data['shop_name']),
                'category' => ShopCategory::from($data['shop_category']),
                'latitude' => (float) $data['shop_latitude'],
                'longitude' => (float) $data['shop_longitude'],
                'address' => $data['shop_address'] ?? null,
                'notification_frequency' => $this->parseNotificationFrequency($data['notification_frequency']),
                'verified' => false,
                'is_active' => true,
            ]);

            Log::info('Shop owner registered', [
                'user_id' => $user->id,
                'phone' => $this->maskPhone($phone),
                'shop_name' => $data['shop_name'],
            ]);

            return $user->load('shop');
        });
    }

    /**
     * Upgrade existing customer to shop owner.
     */
    public function upgradeToShopOwner(User $user, array $shopData): User
    {
        $this->validateShopData($shopData);

        return DB::transaction(function () use ($user, $shopData) {
            $user->update(['type' => UserType::SHOP]);

            Shop::create([
                'user_id' => $user->id,
                'shop_name' => $this->sanitizeName($shopData['shop_name']),
                'category' => ShopCategory::from($shopData['shop_category']),
                'latitude' => (float) $shopData['shop_latitude'],
                'longitude' => (float) $shopData['shop_longitude'],
                'address' => $shopData['shop_address'] ?? null,
                'notification_frequency' => $this->parseNotificationFrequency($shopData['notification_frequency']),
                'verified' => false,
                'is_active' => true,
            ]);

            Log::info('Customer upgraded to shop owner', ['user_id' => $user->id]);

            return $user->fresh()->load('shop');
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Session Management
    |--------------------------------------------------------------------------
    */

    /**
     * Link session to user after registration.
     */
    public function linkSessionToUser(ConversationSession $session, User $user): void
    {
        $session->update(['user_id' => $user->id]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate customer registration data.
     */
    protected function validateCustomerData(array $data): void
    {
        $required = ['phone', 'name', 'latitude', 'longitude'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing: {$field}");
            }
        }

        if (!$this->isValidPhone($data['phone'])) {
            throw new \InvalidArgumentException('Invalid phone');
        }

        if (!$this->isValidName($data['name'])) {
            throw new \InvalidArgumentException('Invalid name');
        }

        if (!$this->isValidCoordinates($data['latitude'], $data['longitude'])) {
            throw new \InvalidArgumentException('Invalid coordinates');
        }
    }

    /**
     * Validate shop owner data (customer + shop).
     */
    protected function validateShopOwnerData(array $data): void
    {
        $this->validateCustomerData($data);
        $this->validateShopData($data);
    }

    /**
     * Validate shop-specific data.
     */
    protected function validateShopData(array $data): void
    {
        $required = ['shop_name', 'shop_category', 'shop_latitude', 'shop_longitude', 'notification_frequency'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing: {$field}");
            }
        }

        if (!$this->isValidName($data['shop_name'])) {
            throw new \InvalidArgumentException('Invalid shop name');
        }

        if (!$this->isValidCategory($data['shop_category'])) {
            throw new \InvalidArgumentException('Invalid category');
        }

        if (!$this->isValidCoordinates($data['shop_latitude'], $data['shop_longitude'])) {
            throw new \InvalidArgumentException('Invalid shop coordinates');
        }

        if (!$this->isValidNotificationFrequency($data['notification_frequency'])) {
            throw new \InvalidArgumentException('Invalid notification frequency');
        }
    }

    /**
     * Validate phone format.
     */
    public function isValidPhone(string $phone): bool
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        return strlen($cleaned) >= 10 && strlen($cleaned) <= 15;
    }

    /**
     * Validate name (2-100 chars, at least one letter).
     */
    public function isValidName(string $name): bool
    {
        $trimmed = trim($name);
        $length = mb_strlen($trimmed);

        if ($length < 2 || $length > 100) {
            return false;
        }

        // Must have at least one letter (supports Malayalam/other scripts)
        return (bool) preg_match('/\p{L}/u', $trimmed);
    }

    /**
     * Validate coordinates.
     */
    public function isValidCoordinates($latitude, $longitude): bool
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    /**
     * Validate shop category.
     */
    public function isValidCategory(string $category): bool
    {
        return ShopCategory::tryFrom($category) !== null;
    }

    /**
     * Validate notification frequency.
     */
    public function isValidNotificationFrequency(string $frequency): bool
    {
        return in_array($frequency, ['immediate', '2hours', 'twice_daily', 'daily']);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize phone (add 91 if 10 digits).
     */
    protected function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        if (strlen($cleaned) === 10) {
            $cleaned = '91' . $cleaned;
        }

        return $cleaned;
    }

    /**
     * Sanitize name (trim, normalize spaces, title case).
     */
    protected function sanitizeName(string $name): string
    {
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name);
        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Parse notification frequency to enum.
     */
    protected function parseNotificationFrequency(string $frequency): NotificationFrequency
    {
        return match ($frequency) {
            'immediate' => NotificationFrequency::IMMEDIATE,
            '2hours' => NotificationFrequency::EVERY_2_HOURS,
            'twice_daily' => NotificationFrequency::TWICE_DAILY,
            'daily' => NotificationFrequency::DAILY,
            default => NotificationFrequency::EVERY_2_HOURS,
        };
    }

    /**
     * Resolve referrer user ID.
     */
    protected function resolveReferrer(?string $phone): ?int
    {
        if (!$phone) {
            return null;
        }

        $referrer = $this->findByPhone($phone);
        return $referrer?->id;
    }

    /**
     * Mask phone for logging.
     */
    protected function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 6) {
            return str_repeat('*', $len);
        }
        return substr($phone, 0, 3) . str_repeat('*', $len - 6) . substr($phone, -3);
    }

    /**
     * Get total user count (for social proof).
     */
    public function getTotalUserCount(): int
    {
        return User::whereNotNull('registered_at')->count();
    }
}