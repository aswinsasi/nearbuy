<?php

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
 * Service for handling user and shop registration.
 *
 * Creates users and shops with proper validation and data integrity.
 *
 * @example
 * $service = app(RegistrationService::class);
 *
 * // Create a customer
 * $user = $service->createCustomer([
 *     'phone' => '919876543210',
 *     'name' => 'John Doe',
 *     'latitude' => 9.5916,
 *     'longitude' => 76.5222,
 * ]);
 *
 * // Create a shop owner with shop
 * $user = $service->createShopOwner([
 *     'phone' => '919876543220',
 *     'name' => 'Jane Doe',
 *     'latitude' => 9.5916,
 *     'longitude' => 76.5222,
 *     'shop_name' => 'Jane\'s Store',
 *     'shop_category' => 'grocery',
 *     'shop_latitude' => 9.5920,
 *     'shop_longitude' => 76.5225,
 *     'notification_frequency' => 'immediate',
 * ]);
 */
class RegistrationService
{
    /**
     * Create a customer account.
     *
     * @param array $data {
     *     @type string $phone Phone number (required)
     *     @type string $name User name (required)
     *     @type float $latitude User latitude (required)
     *     @type float $longitude User longitude (required)
     *     @type string $address Optional address
     *     @type string $language Optional language code (default: 'en')
     * }
     * @return User
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createCustomer(array $data): User
    {
        $this->validateCustomerData($data);

        // Check if phone already exists
        if ($this->phoneExists($data['phone'])) {
            throw new \InvalidArgumentException('Phone number already registered');
        }

        try {
            $user = User::create([
                'phone' => $this->normalizePhone($data['phone']),
                'name' => trim($data['name']),
                'type' => UserType::CUSTOMER,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'address' => $data['address'] ?? null,
                'language' => $data['language'] ?? 'en',
                'registered_at' => now(),
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
     *     @type string $address Optional owner address
     *     @type string $shop_address Optional shop address
     *     @type string $language Optional language code (default: 'en')
     * }
     * @return User
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createShopOwner(array $data): User
    {
        $this->validateShopOwnerData($data);

        // Check if phone already exists
        if ($this->phoneExists($data['phone'])) {
            throw new \InvalidArgumentException('Phone number already registered');
        }

        // Use transaction for data integrity
        return DB::transaction(function () use ($data) {
            // Create user
            $user = User::create([
                'phone' => $this->normalizePhone($data['phone']),
                'name' => trim($data['name']),
                'type' => UserType::SHOP,
                'latitude' => $data['latitude'],
                'longitude' => $data['longitude'],
                'address' => $data['address'] ?? null,
                'language' => $data['language'] ?? 'en',
                'registered_at' => now(),
            ]);

            // Create shop
            $shop = Shop::create([
                'user_id' => $user->id,
                'shop_name' => trim($data['shop_name']),
                'category' => $this->parseCategory($data['shop_category']),
                'latitude' => $data['shop_latitude'],
                'longitude' => $data['shop_longitude'],
                'address' => $data['shop_address'] ?? null,
                'notification_frequency' => $this->parseNotificationFrequency($data['notification_frequency']),
                'verified' => false,
                'is_active' => true,
            ]);

            Log::info('Shop owner registered', [
                'user_id' => $user->id,
                'shop_id' => $shop->id,
                'phone' => $this->maskPhone($data['phone']),
                'shop_name' => $data['shop_name'],
            ]);

            return $user;
        });
    }

    /**
     * Update an existing user's registration.
     */
    public function updateUser(User $user, array $data): User
    {
        $updateData = [];

        if (isset($data['name'])) {
            $updateData['name'] = trim($data['name']);
        }

        if (isset($data['latitude']) && isset($data['longitude'])) {
            $updateData['latitude'] = $data['latitude'];
            $updateData['longitude'] = $data['longitude'];
        }

        if (isset($data['address'])) {
            $updateData['address'] = $data['address'];
        }

        if (isset($data['language'])) {
            $updateData['language'] = $data['language'];
        }

        if (!empty($updateData)) {
            $user->update($updateData);
        }

        return $user->fresh();
    }

    /**
     * Update a shop's details.
     */
    public function updateShop(Shop $shop, array $data): Shop
    {
        $updateData = [];

        if (isset($data['shop_name'])) {
            $updateData['shop_name'] = trim($data['shop_name']);
        }

        if (isset($data['shop_category'])) {
            $updateData['category'] = $this->parseCategory($data['shop_category']);
        }

        if (isset($data['shop_latitude']) && isset($data['shop_longitude'])) {
            $updateData['latitude'] = $data['shop_latitude'];
            $updateData['longitude'] = $data['shop_longitude'];
        }

        if (isset($data['shop_address'])) {
            $updateData['address'] = $data['shop_address'];
        }

        if (isset($data['notification_frequency'])) {
            $updateData['notification_frequency'] = $this->parseNotificationFrequency($data['notification_frequency']);
        }

        if (!empty($updateData)) {
            $shop->update($updateData);
        }

        return $shop->fresh();
    }

    /**
     * Link a session to a newly created user.
     */
    public function linkSessionToUser(ConversationSession $session, User $user): void
    {
        $session->update(['user_id' => $user->id]);
    }

    /**
     * Check if a user exists by phone.
     */
    public function phoneExists(string $phone): bool
    {
        return User::where('phone', $this->normalizePhone($phone))->exists();
    }

    /**
     * Find user by phone.
     */
    public function findByPhone(string $phone): ?User
    {
        return User::where('phone', $this->normalizePhone($phone))->first();
    }

    /**
     * Check if user is fully registered.
     */
    public function isRegistered(string $phone): bool
    {
        $user = $this->findByPhone($phone);
        return $user && $user->registered_at !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Methods
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
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!$this->isValidPhone($data['phone'])) {
            throw new \InvalidArgumentException('Invalid phone number format');
        }

        if (!$this->isValidName($data['name'])) {
            throw new \InvalidArgumentException('Invalid name format');
        }

        if (!$this->isValidCoordinates($data['latitude'], $data['longitude'])) {
            throw new \InvalidArgumentException('Invalid coordinates');
        }
    }

    /**
     * Validate shop owner registration data.
     */
    protected function validateShopOwnerData(array $data): void
    {
        // Validate basic user data
        $this->validateCustomerData($data);

        // Validate shop-specific fields
        $shopRequired = ['shop_name', 'shop_category', 'shop_latitude', 'shop_longitude', 'notification_frequency'];

        foreach ($shopRequired as $field) {
            if (empty($data[$field])) {
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
     */
    public function isValidName(string $name): bool
    {
        $trimmed = trim($name);
        return mb_strlen($trimmed) >= 2 && mb_strlen($trimmed) <= 100;
    }

    /**
     * Validate coordinates.
     */
    public function isValidCoordinates(float $latitude, float $longitude): bool
    {
        return $latitude >= -90 && $latitude <= 90
            && $longitude >= -180 && $longitude <= 180;
    }

    /**
     * Validate category.
     */
    public function isValidCategory(string $category): bool
    {
        $validCategories = [
            'grocery', 'electronics', 'clothes', 'medical',
            'furniture', 'mobile', 'appliances', 'hardware',
            'restaurant', 'bakery', 'stationery', 'beauty',
            'automotive', 'jewelry', 'sports', 'other',
        ];

        return in_array(strtolower($category), $validCategories);
    }

    /**
     * Validate notification frequency.
     */
    public function isValidNotificationFrequency(string $frequency): bool
    {
        $valid = ['immediate', '2hours', 'twice_daily', 'daily'];
        return in_array(strtolower($frequency), $valid);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize phone number.
     */
    protected function normalizePhone(string $phone): string
    {
        // Remove all non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        // Ensure it starts with country code (assume India if 10 digits)
        if (strlen($cleaned) === 10) {
            $cleaned = '91' . $cleaned;
        }

        return $cleaned;
    }

    /**
     * Parse category string to enum.
     */
    protected function parseCategory(string $category): ShopCategory
    {
        return ShopCategory::from(strtoupper($category));
    }

    /**
     * Parse notification frequency string to enum.
     */
    protected function parseNotificationFrequency(string $frequency): NotificationFrequency
    {
        $map = [
            'immediate' => NotificationFrequency::IMMEDIATE,
            '2hours' => NotificationFrequency::TWO_HOURS,
            'twice_daily' => NotificationFrequency::TWICE_DAILY,
            'daily' => NotificationFrequency::DAILY,
        ];

        return $map[strtolower($frequency)] ?? NotificationFrequency::TWICE_DAILY;
    }

    /**
     * Mask phone number for logging.
     */
    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return $phone;
        }

        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }
}