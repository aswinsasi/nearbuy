<?php

declare(strict_types=1);

namespace App\Services\Fish;

use App\Enums\FishSellerType;
use App\Enums\UserType;
use App\Models\ConversationSession;
use App\Models\FishSeller;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing fish sellers.
 *
 * Handles:
 * - Fish seller registration (new users AND existing users)
 * - Profile management
 * - Verification
 * - Statistics
 *
 * IMPORTANT: There are TWO ways to become a fish seller:
 * 1. createFishSeller() - Creates a NEW user with type FISH_SELLER
 * 2. registerExistingUserAsSeller() - Adds fish seller profile to EXISTING user (customer/shop)
 *
 * @srs-ref Pacha Meen Module - Section 2.3.1 Seller/Fisherman Registration
 * @srs-ref Section 2.2: Fish sellers are separate registration from customers/shops
 */
class FishSellerService
{
    /**
     * Analytics event types.
     */
    public const EVENT_SELLER_REGISTERED = 'fish_seller.registered';
    public const EVENT_SELLER_VERIFIED = 'fish_seller.verified';
    public const EVENT_SELLER_DEACTIVATED = 'fish_seller.deactivated';

    /**
     * Create a new fish seller with user account.
     * 
     * Use this for NEW users who are registering as fish sellers.
     * For existing users (customers/shops), use registerExistingUserAsSeller() instead.
     *
     * @param array $data {
     *     @type string $phone Phone number (required)
     *     @type string $name Owner name (required)
     *     @type string $business_name Business/stall name (required)
     *     @type string $seller_type FishSellerType value (required)
     *     @type float $latitude Location latitude (required)
     *     @type float $longitude Location longitude (required)
     *     @type string|null $address Address text
     *     @type string|null $market_name Harbour/market name
     *     @type string|null $landmark Nearby landmark
     *     @type string|null $alternate_phone Secondary phone
     *     @type string|null $upi_id UPI payment ID
     *     @type string|null $language Preferred language
     * }
     * @return User User with fishSeller relationship loaded
     * @throws \InvalidArgumentException
     * @throws \Exception
     */
    public function createFishSeller(array $data): User
    {
        $this->validateSellerData($data);

        if ($this->phoneExists($data['phone'])) {
            throw new \InvalidArgumentException('Phone number already registered');
        }

        return DB::transaction(function () use ($data) {
            // Create user record
            $user = User::create([
                'phone' => $this->normalizePhone($data['phone']),
                'name' => $this->sanitizeName($data['name']),
                'type' => UserType::FISH_SELLER,
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'address' => $data['address'] ?? null,
                'language' => $data['language'] ?? 'en',
                'registered_at' => now(),
            ]);

            // Create fish seller profile
            $sellerType = $data['seller_type'] instanceof FishSellerType
                ? $data['seller_type']
                : FishSellerType::from($data['seller_type']);

            $seller = FishSeller::create([
                'user_id' => $user->id,
                'business_name' => $this->sanitizeName($data['business_name']),
                'seller_type' => $sellerType,
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'address' => $data['address'] ?? null,
                'market_name' => $data['market_name'] ?? null,
                'landmark' => $data['landmark'] ?? null,
                'alternate_phone' => isset($data['alternate_phone'])
                    ? $this->normalizePhone($data['alternate_phone'])
                    : null,
                'upi_id' => $data['upi_id'] ?? null,
                'operating_hours' => $data['operating_hours'] ?? $this->getDefaultOperatingHours(),
                'catch_days' => $data['catch_days'] ?? [1, 2, 3, 4, 5, 6], // Mon-Sat default
                'default_alert_radius_km' => $sellerType->defaultNotificationRadius(),
                'is_active' => true,
                'is_verified' => false,
            ]);

            // Track analytics
            $this->trackEvent(self::EVENT_SELLER_REGISTERED, [
                'user_id' => $user->id,
                'seller_id' => $seller->id,
                'seller_type' => $sellerType->value,
                'registration_type' => 'new_user',
            ]);

            Log::info('Fish seller registered (new user)', [
                'user_id' => $user->id,
                'seller_id' => $seller->id,
                'phone' => $this->maskPhone($data['phone']),
                'seller_type' => $sellerType->value,
            ]);

            return $user->load('fishSeller');
        });
    }

    /**
     * Register an EXISTING user as a fish seller.
     *
     * This allows customers and shop owners to ALSO become fish sellers
     * WITHOUT changing their user type. The fish seller profile is an
     * ADDITION to their existing account.
     *
     * @srs-ref Section 2.2: Fish sellers are separate from main user types
     * @srs-ref PM-001: Three types of fish sellers (shop, fisherman, vendor)
     *
     * @param User $user Existing registered user
     * @param FishSellerType $sellerType Type of fish seller
     * @param string $businessName Business/stall name
     * @param float $latitude Location latitude
     * @param float $longitude Location longitude
     * @param string|null $marketName Harbour/market name
     * @param string|null $address Address text
     * @return FishSeller The created fish seller profile
     * @throws \InvalidArgumentException
     */
    public function registerExistingUserAsSeller(
        User $user,
        FishSellerType $sellerType,
        string $businessName,
        float $latitude,
        float $longitude,
        ?string $marketName = null,
        ?string $address = null
    ): FishSeller {
        // Validate user can register
        if (!$this->canRegisterAsSeller($user)) {
            if (!$user->registered_at) {
                throw new \InvalidArgumentException('User must be registered first');
            }
            if ($user->fishSeller) {
                throw new \InvalidArgumentException('User is already a fish seller');
            }
            throw new \InvalidArgumentException('User cannot register as fish seller');
        }

        // Validate business name
        if (!$this->isValidName($businessName)) {
            throw new \InvalidArgumentException('Invalid business name');
        }

        // Validate coordinates
        if (!$this->isValidCoordinates($latitude, $longitude)) {
            throw new \InvalidArgumentException('Invalid coordinates');
        }

        return DB::transaction(function () use (
            $user,
            $sellerType,
            $businessName,
            $latitude,
            $longitude,
            $marketName,
            $address
        ) {
            // IMPORTANT: Do NOT change user type!
            // User remains CUSTOMER or SHOP, but gets a fish seller profile added

            // Create fish seller profile
            $seller = FishSeller::create([
                'user_id' => $user->id,
                'business_name' => $this->sanitizeName($businessName),
                'seller_type' => $sellerType,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'address' => $address,
                'market_name' => $marketName,
                'operating_hours' => $this->getDefaultOperatingHours(),
                'catch_days' => [1, 2, 3, 4, 5, 6], // Mon-Sat default
                'default_alert_radius_km' => $sellerType->defaultNotificationRadius(),
                'is_active' => true,
                'is_verified' => false,
            ]);

            // Track analytics
            $this->trackEvent(self::EVENT_SELLER_REGISTERED, [
                'user_id' => $user->id,
                'seller_id' => $seller->id,
                'seller_type' => $sellerType->value,
                'original_user_type' => $user->type->value,
                'registration_type' => 'existing_user',
            ]);

            Log::info('Existing user registered as fish seller', [
                'user_id' => $user->id,
                'seller_id' => $seller->id,
                'original_user_type' => $user->type->value, // User keeps this type!
                'seller_type' => $sellerType->value,
                'business_name' => $businessName,
            ]);

            return $seller;
        });
    }

    /**
     * Check if user can register as a fish seller.
     *
     * Any registered user who doesn't already have a fish seller profile can register.
     *
     * @param User $user
     * @return bool
     */
    public function canRegisterAsSeller(User $user): bool
    {
        // Must be registered
        if (!$user->registered_at) {
            return false;
        }

        // Must not already have a fish seller profile
        if ($user->fishSeller) {
            return false;
        }

        return true;
    }

    /**
     * Check if user is a fish seller (has fish_seller profile).
     *
     * This checks for the PROFILE, not user type!
     * A user can be type=CUSTOMER but also have a fish seller profile.
     *
     * @param User $user
     * @return bool
     */
    public function isFishSeller(User $user): bool
    {
        return $user->fishSeller !== null;
    }

    /**
     * Update fish seller profile.
     *
     * @param FishSeller $seller
     * @param array $data
     * @return FishSeller
     */
    public function updateSeller(FishSeller $seller, array $data): FishSeller
    {
        $updateData = [];

        if (isset($data['business_name']) && $this->isValidName($data['business_name'])) {
            $updateData['business_name'] = $this->sanitizeName($data['business_name']);
        }

        if (isset($data['seller_type'])) {
            $updateData['seller_type'] = $data['seller_type'] instanceof FishSellerType
                ? $data['seller_type']
                : FishSellerType::from($data['seller_type']);
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

        if (isset($data['market_name'])) {
            $updateData['market_name'] = $data['market_name'];
        }

        if (isset($data['landmark'])) {
            $updateData['landmark'] = $data['landmark'];
        }

        if (isset($data['alternate_phone'])) {
            $updateData['alternate_phone'] = $this->normalizePhone($data['alternate_phone']);
        }

        if (isset($data['upi_id'])) {
            $updateData['upi_id'] = $data['upi_id'];
        }

        if (isset($data['operating_hours'])) {
            $updateData['operating_hours'] = $data['operating_hours'];
        }

        if (isset($data['catch_days'])) {
            $updateData['catch_days'] = $data['catch_days'];
        }

        if (isset($data['default_alert_radius_km'])) {
            $updateData['default_alert_radius_km'] = (int) $data['default_alert_radius_km'];
        }

        if (isset($data['is_accepting_orders'])) {
            $updateData['is_accepting_orders'] = (bool) $data['is_accepting_orders'];
        }

        if (!empty($updateData)) {
            $seller->update($updateData);
            Log::info('Fish seller profile updated', ['seller_id' => $seller->id]);
        }

        return $seller->fresh();
    }

    /**
     * Update seller location.
     *
     * @param FishSeller $seller
     * @param float $latitude
     * @param float $longitude
     * @param string|null $locationName
     * @return FishSeller
     */
    public function updateLocation(
        FishSeller $seller,
        float $latitude,
        float $longitude,
        ?string $locationName = null
    ): FishSeller {
        if (!$this->isValidCoordinates($latitude, $longitude)) {
            throw new \InvalidArgumentException('Invalid coordinates');
        }

        $updateData = [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        if ($locationName) {
            $updateData['market_name'] = $locationName;
        }

        $seller->update($updateData);

        // Also update user location
        $seller->user->update([
            'latitude' => $latitude,
            'longitude' => $longitude,
        ]);

        return $seller->fresh();
    }

    /**
     * Verify a fish seller.
     *
     * @param FishSeller $seller
     * @param string|null $verificationDocUrl
     * @return FishSeller
     */
    public function verifySeller(FishSeller $seller, ?string $verificationDocUrl = null): FishSeller
    {
        $seller->update([
            'is_verified' => true,
            'verified_at' => now(),
            'verification_doc_url' => $verificationDocUrl,
        ]);

        $this->trackEvent(self::EVENT_SELLER_VERIFIED, [
            'seller_id' => $seller->id,
            'user_id' => $seller->user_id,
        ]);

        Log::info('Fish seller verified', ['seller_id' => $seller->id]);

        return $seller->fresh();
    }

    /**
     * Deactivate a fish seller.
     *
     * @param FishSeller $seller
     * @param string|null $reason
     * @return FishSeller
     */
    public function deactivateSeller(FishSeller $seller, ?string $reason = null): FishSeller
    {
        $seller->update(['is_active' => false]);

        $this->trackEvent(self::EVENT_SELLER_DEACTIVATED, [
            'seller_id' => $seller->id,
            'reason' => $reason,
        ]);

        Log::info('Fish seller deactivated', [
            'seller_id' => $seller->id,
            'reason' => $reason,
        ]);

        return $seller->fresh();
    }

    /**
     * Reactivate a fish seller.
     *
     * @param FishSeller $seller
     * @return FishSeller
     */
    public function reactivateSeller(FishSeller $seller): FishSeller
    {
        $seller->update(['is_active' => true]);

        Log::info('Fish seller reactivated', ['seller_id' => $seller->id]);

        return $seller->fresh();
    }

    /**
     * Find fish seller by user ID.
     *
     * @param int $userId
     * @return FishSeller|null
     */
    public function findByUserId(int $userId): ?FishSeller
    {
        return FishSeller::where('user_id', $userId)->first();
    }

    /**
     * Find fish seller by phone number.
     *
     * NOTE: Updated to find ANY user with a fish seller profile,
     * not just users with type=FISH_SELLER.
     *
     * @param string $phone
     * @return FishSeller|null
     */
    public function findByPhone(string $phone): ?FishSeller
    {
        $user = User::where('phone', $this->normalizePhone($phone))
            ->whereHas('fishSeller') // Has fish seller profile (any user type)
            ->first();

        return $user?->fishSeller;
    }

    /**
     * Get seller for session.
     *
     * @param ConversationSession $session
     * @return FishSeller|null
     */
    public function getSellerForSession(ConversationSession $session): ?FishSeller
    {
        if (!$session->user_id) {
            return null;
        }

        return $this->findByUserId($session->user_id);
    }

    /**
     * Find active sellers near a location.
     *
     * @param float $latitude
     * @param float $longitude
     * @param float $radiusKm
     * @param FishSellerType|null $sellerType
     * @return Collection<FishSeller>
     */
    public function findNearby(
        float $latitude,
        float $longitude,
        float $radiusKm = 5,
        ?FishSellerType $sellerType = null
    ): Collection {
        $query = FishSeller::active()
            ->withDistanceFrom($latitude, $longitude)
            ->nearLocation($latitude, $longitude, $radiusKm);

        if ($sellerType) {
            $query->ofType($sellerType);
        }

        return $query->orderBy('distance_km')->get();
    }

    /**
     * Find sellers with active catches near a location.
     *
     * @param float $latitude
     * @param float $longitude
     * @param float $radiusKm
     * @return Collection<FishSeller>
     */
    public function findWithActiveCatches(
        float $latitude,
        float $longitude,
        float $radiusKm = 5
    ): Collection {
        return FishSeller::active()
            ->withActiveCatches()
            ->withDistanceFrom($latitude, $longitude)
            ->nearLocation($latitude, $longitude, $radiusKm)
            ->orderBy('distance_km')
            ->get();
    }

    /**
     * Get seller statistics.
     *
     * @bugfix Updated to include all keys expected by FishSellerMenuHandler
     * Keys added: today_views, today_coming, week_views, week_coming, total_views, avg_rating
     *
     * @param FishSeller $seller
     * @return array
     */
    public function getSellerStats(FishSeller $seller): array
    {
        $activeCatches = $seller->catches()
            ->where('status', 'available')
            ->where('expires_at', '>', now())
            ->count();

        $todayCatches = $seller->catches()
            ->whereDate('created_at', today())
            ->count();

        $weekCatches = $seller->catches()
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->count();

        // Today's stats (ADDED)
        $todayViews = $seller->catches()
            ->whereDate('created_at', today())
            ->sum('view_count');

        $todayComing = $seller->catches()
            ->whereDate('created_at', today())
            ->sum('coming_count');

        // Week's stats (ADDED)
        $weekViews = $seller->catches()
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('view_count');

        $weekComing = $seller->catches()
            ->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->sum('coming_count');

        // All-time stats (ADDED)
        $totalViews = $seller->catches()->sum('view_count');

        $totalCustomers = $seller->catches()
            ->withCount('responses')
            ->get()
            ->sum('responses_count');

        return [
            // Active & counts
            'active_catches' => $activeCatches,
            'today_catches' => $todayCatches,
            'week_catches' => $weekCatches,
            'total_catches' => $seller->total_catches,
            'total_sales' => $seller->total_sales,
            'total_customers' => $totalCustomers,
            
            // Views (ADDED - required by FishSellerMenuHandler)
            'today_views' => $todayViews,
            'week_views' => $weekViews,
            'total_views' => $totalViews,
            
            // Coming responses (ADDED - required by FishSellerMenuHandler)
            'today_coming' => $todayComing,
            'week_coming' => $weekComing,
            
            // Ratings
            'average_rating' => $seller->average_rating,
            'avg_rating' => $seller->average_rating, // Alias for handler compatibility
            'rating_count' => $seller->rating_count,
            'is_verified' => $seller->is_verified,
        ];
    }

    /**
     * Get leaderboard of top sellers.
     *
     * @param int $limit
     * @param string $metric 'sales', 'rating', 'catches'
     * @return Collection<FishSeller>
     */
    public function getLeaderboard(int $limit = 10, string $metric = 'sales'): Collection
    {
        $query = FishSeller::active();

        return match ($metric) {
            'rating' => $query->where('rating_count', '>=', 5)
                ->orderBy('average_rating', 'desc')
                ->limit($limit)
                ->get(),
            'catches' => $query->orderBy('total_catches', 'desc')
                ->limit($limit)
                ->get(),
            default => $query->orderBy('total_sales', 'desc')
                ->limit($limit)
                ->get(),
        };
    }

    /**
     * Check if phone number already exists.
     *
     * @param string $phone
     * @return bool
     */
    public function phoneExists(string $phone): bool
    {
        return User::where('phone', $this->normalizePhone($phone))->exists();
    }

    /**
     * Link session to fish seller user.
     *
     * @param ConversationSession $session
     * @param User $user
     * @return void
     */
    public function linkSessionToUser(ConversationSession $session, User $user): void
    {
        $session->update(['user_id' => $user->id]);

        Log::debug('Session linked to fish seller', [
            'session_id' => $session->id,
            'user_id' => $user->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Validate seller registration data.
     *
     * @param array $data
     * @throws \InvalidArgumentException
     */
    protected function validateSellerData(array $data): void
    {
        $required = ['phone', 'name', 'business_name', 'seller_type', 'latitude', 'longitude'];

        foreach ($required as $field) {
            if (!isset($data[$field]) || $data[$field] === '') {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!$this->isValidPhone($data['phone'])) {
            throw new \InvalidArgumentException('Invalid phone number format');
        }

        if (!$this->isValidName($data['name'])) {
            throw new \InvalidArgumentException('Invalid name format');
        }

        if (!$this->isValidName($data['business_name'])) {
            throw new \InvalidArgumentException('Invalid business name format');
        }

        if (!$this->isValidCoordinates($data['latitude'], $data['longitude'])) {
            throw new \InvalidArgumentException('Invalid coordinates');
        }

        // Validate seller type
        $sellerType = $data['seller_type'];
        if (!$sellerType instanceof FishSellerType && FishSellerType::tryFrom($sellerType) === null) {
            throw new \InvalidArgumentException('Invalid seller type');
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
        $length = mb_strlen($trimmed);
        return $length >= 2 && $length <= 200;
    }

    /**
     * Validate coordinates.
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
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

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
        $name = trim($name);
        $name = preg_replace('/\s+/', ' ', $name);
        return $name;
    }

    /**
     * Get default operating hours.
     */
    protected function getDefaultOperatingHours(): array
    {
        return [
            'mon' => ['open' => '05:00', 'close' => '12:00'],
            'tue' => ['open' => '05:00', 'close' => '12:00'],
            'wed' => ['open' => '05:00', 'close' => '12:00'],
            'thu' => ['open' => '05:00', 'close' => '12:00'],
            'fri' => ['open' => '05:00', 'close' => '12:00'],
            'sat' => ['open' => '05:00', 'close' => '12:00'],
            'sun' => ['open' => '06:00', 'close' => '11:00'],
        ];
    }

    /**
     * Track analytics event.
     */
    protected function trackEvent(string $event, array $data = []): void
    {
        Log::channel('analytics')->info($event, array_merge($data, [
            'timestamp' => now()->toIso8601String(),
        ]));
    }

    /**
     * Mask phone for logging.
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