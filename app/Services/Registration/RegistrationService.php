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
use Illuminate\Support\Str;

/**
 * Service for handling user and shop registration.
 *
 * VIRAL ADOPTION FEATURES:
 * - Referral tracking for organic growth measurement
 * - Analytics events for funnel optimization
 * - Flexible validation with helpful error messages
 * - Transaction safety for data integrity
 *
 * @see SRS Section 3.1 - User Registration Requirements
 * @see SRS Section 3.1.3 - Shop Registration Additional Requirements
 */
class RegistrationService
{
    /**
     * Analytics event types for registration funnel.
     */
    public const EVENT_REGISTRATION_STARTED = 'registration.started';
    public const EVENT_REGISTRATION_COMPLETED = 'registration.completed';
    public const EVENT_REGISTRATION_ABANDONED = 'registration.abandoned';
    public const EVENT_SHOP_CREATED = 'shop.created';

    /**
     * Create a customer account.
     *
     * @param array $data {
     *     @type string $phone Phone number (required)
     *     @type string $name User name (required)
     *     @type float $latitude User latitude (required)
     *     @type float $longitude User longitude (required)
     *     @type string|null $address Optional address
     *     @type string|null $language Language code (default: 'en')
     *     @type string|null $referrer_phone Phone of referring user
     * }
     * @return User
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createCustomer(array $data): User
    {
        $this->validateCustomerData($data);

        if ($this->phoneExists($data['phone'])) {
            throw new \InvalidArgumentException('Phone number already registered');
        }

        try {
            $user = User::create([
                'phone' => $this->normalizePhone($data['phone']),
                'name' => $this->sanitizeName($data['name']),
                'type' => UserType::CUSTOMER,
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'address' => $data['address'] ?? null,
                'language' => $data['language'] ?? 'en',
                'referred_by' => $this->resolveReferrer($data['referrer_phone'] ?? null),
                'registered_at' => now(),
            ]);

            $this->trackEvent(self::EVENT_REGISTRATION_COMPLETED, [
                'user_id' => $user->id,
                'user_type' => 'customer',
                'has_referrer' => !empty($data['referrer_phone']),
            ]);

            Log::info('Customer registered', [
                'user_id' => $user->id,
                'phone' => $this->maskPhone($data['phone']),
            ]);

            return $user;

        } catch (\Exception $e) {
            Log::error('Failed to create customer', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($data['phone']),
            ]);
            throw $e;
        }
    }

    /**
     * Create a shop owner account with associated shop.
     *
     * Uses database transaction to ensure both user and shop
     * are created atomically (FR-SHOP-05).
     *
     * @param array $data {
     *     @type string $phone Phone number (required)
     *     @type string $name Owner name (required)
     *     @type float $latitude Owner latitude (required)
     *     @type float $longitude Owner longitude (required)
     *     @type string $shop_name Shop name (required)
     *     @type string $shop_category Shop category ID (required)
     *     @type float $shop_latitude Shop latitude (required)
     *     @type float $shop_longitude Shop longitude (required)
     *     @type string $notification_frequency Notification preference (required)
     *     @type string|null $address Optional owner address
     *     @type string|null $shop_address Optional shop address
     *     @type string|null $language Language code (default: 'en')
     *     @type string|null $referrer_phone Phone of referring user
     * }
     * @return User User with shop relationship loaded
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createShopOwner(array $data): User
    {
        $this->validateShopOwnerData($data);

        if ($this->phoneExists($data['phone'])) {
            throw new \InvalidArgumentException('Phone number already registered');
        }

        return DB::transaction(function () use ($data) {
            // Create user record
            $user = User::create([
                'phone' => $this->normalizePhone($data['phone']),
                'name' => $this->sanitizeName($data['name']),
                'type' => UserType::SHOP,
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'address' => $data['address'] ?? null,
                'language' => $data['language'] ?? 'en',
                'referred_by' => $this->resolveReferrer($data['referrer_phone'] ?? null),
                'registered_at' => now(),
            ]);

            // Create shop record (FR-SHOP-05)
            $shop = Shop::create([
                'user_id' => $user->id,
                'shop_name' => $this->sanitizeName($data['shop_name']),
                'category' => $this->parseCategory($data['shop_category']),
                'latitude' => (float) $data['shop_latitude'],
                'longitude' => (float) $data['shop_longitude'],
                'address' => $data['shop_address'] ?? null,
                'notification_frequency' => $this->parseNotificationFrequency(
                    $data['notification_frequency']
                ),
                'verified' => false,
                'is_active' => true,
            ]);

            // Track analytics events
            $this->trackEvent(self::EVENT_REGISTRATION_COMPLETED, [
                'user_id' => $user->id,
                'user_type' => 'shop',
                'has_referrer' => !empty($data['referrer_phone']),
            ]);

            $this->trackEvent(self::EVENT_SHOP_CREATED, [
                'shop_id' => $shop->id,
                'user_id' => $user->id,
                'category' => $data['shop_category'],
            ]);

            Log::info('Shop owner registered', [
                'user_id' => $user->id,
                'shop_id' => $shop->id,
                'phone' => $this->maskPhone($data['phone']),
                'shop_name' => $data['shop_name'],
                'category' => $data['shop_category'],
            ]);

            // Load the shop relationship before returning
            return $user->load('shop');
        });
    }

    /**
     * Update an existing user's profile.
     */
    public function updateUser(User $user, array $data): User
    {
        $updateData = [];

        if (isset($data['name']) && $this->isValidName($data['name'])) {
            $updateData['name'] = $this->sanitizeName($data['name']);
        }

        if (isset($data['latitude'], $data['longitude'])) {
            if ($this->isValidCoordinates($data['latitude'], $data['longitude'])) {
                $updateData['latitude'] = (float) $data['latitude'];
                $updateData['longitude'] = (float) $data['longitude'];
            }
        }

        if (isset($data['address'])) {
            $updateData['address'] = $data['address'];
        }

        if (isset($data['language'])) {
            $updateData['language'] = $data['language'];
        }

        if (!empty($updateData)) {
            $user->update($updateData);
            Log::info('User profile updated', ['user_id' => $user->id]);
        }

        return $user->fresh();
    }

    /**
     * Update a shop's details.
     */
    public function updateShop(Shop $shop, array $data): Shop
    {
        $updateData = [];

        if (isset($data['shop_name']) && $this->isValidName($data['shop_name'])) {
            $updateData['shop_name'] = $this->sanitizeName($data['shop_name']);
        }

        if (isset($data['shop_category']) && $this->isValidCategory($data['shop_category'])) {
            $updateData['category'] = $this->parseCategory($data['shop_category']);
        }

        if (isset($data['shop_latitude'], $data['shop_longitude'])) {
            if ($this->isValidCoordinates($data['shop_latitude'], $data['shop_longitude'])) {
                $updateData['latitude'] = (float) $data['shop_latitude'];
                $updateData['longitude'] = (float) $data['shop_longitude'];
            }
        }

        if (isset($data['shop_address'])) {
            $updateData['address'] = $data['shop_address'];
        }

        if (isset($data['notification_frequency'])) {
            if ($this->isValidNotificationFrequency($data['notification_frequency'])) {
                $updateData['notification_frequency'] = $this->parseNotificationFrequency(
                    $data['notification_frequency']
                );
            }
        }

        if (!empty($updateData)) {
            $shop->update($updateData);
            Log::info('Shop profile updated', ['shop_id' => $shop->id]);
        }

        return $shop->fresh();
    }

    /**
     * Link a conversation session to a newly created user.
     */
    public function linkSessionToUser(ConversationSession $session, User $user): void
    {
        $session->update(['user_id' => $user->id]);

        Log::debug('Session linked to user', [
            'session_id' => $session->id,
            'user_id' => $user->id,
        ]);
    }

    /**
     * Check if a phone number is already registered.
     */
    public function phoneExists(string $phone): bool
    {
        return User::where('phone', $this->normalizePhone($phone))->exists();
    }

    /**
     * Find user by phone number.
     */
    public function findByPhone(string $phone): ?User
    {
        return User::where('phone', $this->normalizePhone($phone))->first();
    }

    /**
     * Check if user has completed registration.
     */
    public function isRegistered(string $phone): bool
    {
        $user = $this->findByPhone($phone);
        return $user !== null && $user->registered_at !== null;
    }

    /**
     * Get total registered user count (for social proof).
     */
    public function getTotalUserCount(): int
    {
        return User::whereNotNull('registered_at')->count();
    }

    /**
     * Get referral stats for a user.
     */
    public function getReferralStats(User $user): array
    {
        $referredCount = User::where('referred_by', $user->id)->count();

        return [
            'total_referred' => $referredCount,
            'referred_shops' => User::where('referred_by', $user->id)
                ->where('type', UserType::SHOP)
                ->count(),
            'referred_customers' => User::where('referred_by', $user->id)
                ->where('type', UserType::CUSTOMER)
                ->count(),
        ];
    }

    /**
     * Track incomplete registration for follow-up.
     */
    public function trackIncompleteRegistration(
        string $phone,
        string $lastStep,
        array $tempData
    ): void {
        $this->trackEvent(self::EVENT_REGISTRATION_ABANDONED, [
            'phone' => $this->maskPhone($phone),
            'last_step' => $lastStep,
            'user_type' => $tempData['user_type'] ?? 'unknown',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Validate customer registration data.
     *
     * @throws \InvalidArgumentException
     */
    protected function validateCustomerData(array $data): void
    {
        $required = ['phone', 'name', 'latitude', 'longitude'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!$this->isValidPhone($data['phone'])) {
            throw new \InvalidArgumentException('Invalid phone number format');
        }

        if (!$this->isValidName($data['name'])) {
            throw new \InvalidArgumentException('Invalid name format (2-100 characters required)');
        }

        if (!$this->isValidCoordinates($data['latitude'], $data['longitude'])) {
            throw new \InvalidArgumentException('Invalid coordinates');
        }
    }

    /**
     * Validate shop owner registration data.
     *
     * @throws \InvalidArgumentException
     */
    protected function validateShopOwnerData(array $data): void
    {
        // Validate basic user data first
        $this->validateCustomerData($data);

        // Validate shop-specific fields
        $shopRequired = [
            'shop_name',
            'shop_category',
            'shop_latitude',
            'shop_longitude',
            'notification_frequency',
        ];

        foreach ($shopRequired as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!$this->isValidName($data['shop_name'])) {
            throw new \InvalidArgumentException('Invalid shop name format');
        }

        if (!$this->isValidCategory($data['shop_category'])) {
            throw new \InvalidArgumentException('Invalid shop category');
        }

        if (!$this->isValidCoordinates($data['shop_latitude'], $data['shop_longitude'])) {
            throw new \InvalidArgumentException('Invalid shop coordinates');
        }

        if (!$this->isValidNotificationFrequency($data['notification_frequency'])) {
            throw new \InvalidArgumentException('Invalid notification frequency');
        }
    }

    /**
     * Validate phone number format.
     */
    public function isValidPhone(string $phone): bool
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        return strlen($cleaned) >= 10 && strlen($cleaned) <= 15;
    }

    /**
     * Validate name format.
     * Allows letters, spaces, and common punctuation.
     */
    public function isValidName(string $name): bool
    {
        $trimmed = trim($name);
        $length = mb_strlen($trimmed);

        // Must be 2-100 characters
        if ($length < 2 || $length > 100) {
            return false;
        }

        // Must contain at least one letter
        if (!preg_match('/\p{L}/u', $trimmed)) {
            return false;
        }

        return true;
    }

    /**
     * Validate geographic coordinates.
     */
    public function isValidCoordinates(mixed $latitude, mixed $longitude): bool
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
        $validCategories = [
            'grocery',
            'electronics',
            'clothes',
            'medical',
            'furniture',
            'mobile',
            'appliances',
            'hardware',
            'restaurant',
            'bakery',
            'stationery',
            'beauty',
            'automotive',
            'jewelry',
            'sports',
            'other',
        ];

        return in_array(strtolower($category), $validCategories, true);
    }

    /**
     * Validate notification frequency.
     */
    public function isValidNotificationFrequency(string $frequency): bool
    {
        $valid = ['immediate', '2hours', 'twice_daily', 'daily'];
        return in_array(strtolower($frequency), $valid, true);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize phone number to standard format.
     * Assumes Indian numbers if 10 digits.
     */
    protected function normalizePhone(string $phone): string
    {
        // Remove all non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        // Add India country code if 10 digits
        if (strlen($cleaned) === 10) {
            $cleaned = '91' . $cleaned;
        }

        return $cleaned;
    }

    /**
     * Sanitize name input.
     */
    protected function sanitizeName(string $name): string
    {
        // Trim whitespace and normalize multiple spaces
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name);

        // Title case for names
        return mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Parse category string to enum.
     */
    protected function parseCategory(string $category): ShopCategory
    {
        return ShopCategory::from(strtolower($category));
    }

    /**
     * Parse notification frequency string to enum.
     */
    protected function parseNotificationFrequency(string $frequency): NotificationFrequency
    {
        $map = [
            'immediate' => NotificationFrequency::IMMEDIATE,
            '2hours' => NotificationFrequency::EVERY_2_HOURS,
            'twice_daily' => NotificationFrequency::TWICE_DAILY,
            'daily' => NotificationFrequency::DAILY,
        ];

        return $map[strtolower($frequency)] ?? NotificationFrequency::EVERY_2_HOURS;
    }

    /**
     * Resolve referrer user ID from phone number.
     */
    protected function resolveReferrer(?string $referrerPhone): ?int
    {
        if (empty($referrerPhone)) {
            return null;
        }

        $referrer = $this->findByPhone($referrerPhone);
        return $referrer?->id;
    }

    /**
     * Track analytics event.
     * Can be extended to send to analytics service.
     */
    protected function trackEvent(string $event, array $data = []): void
    {
        Log::channel('analytics')->info($event, array_merge($data, [
            'timestamp' => now()->toIso8601String(),
        ]));

        // TODO: Integrate with analytics service (Mixpanel, Amplitude, etc.)
        // Analytics::track($event, $data);
    }

    /**
     * Mask phone number for logging (privacy).
     */
    protected function maskPhone(string $phone): string
    {
        $length = strlen($phone);

        if ($length < 6) {
            return str_repeat('*', $length);
        }

        return substr($phone, 0, 3) . str_repeat('*', $length - 6) . substr($phone, -3);
    }
}