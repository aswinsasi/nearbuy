<?php

declare(strict_types=1);

namespace App\Services\Fish;

use App\Enums\FishSellerType;
use App\Enums\FishSellerVerificationStatus;
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
 * - Fish seller registration (new AND existing users)
 * - Profile management
 * - Verification (PM-003)
 * - Rating updates (PM-004)
 *
 * @srs-ref PM-001 to PM-004 Fish seller requirements
 */
class FishSellerService
{
    /**
     * Register a fish seller.
     *
     * Creates or updates user and creates fish seller profile.
     * Works for both new users and existing registered users.
     *
     * @srs-ref PM-001 Collect: name, phone, seller type, location, harbour/market name
     *
     * @param array $data {
     *     @type string $phone Required
     *     @type string $name Required
     *     @type string $seller_type FishSellerType value (fisherman/fish_shop/vendor)
     *     @type string $location_name Harbour/market/shop name
     *     @type float $latitude
     *     @type float $longitude
     *     @type string|null $verification_photo_url (PM-002)
     * }
     * @return FishSeller
     */
    public function registerFishSeller(array $data): FishSeller
    {
        $this->validateRegistrationData($data);

        $phone = $this->normalizePhone($data['phone']);

        return DB::transaction(function () use ($data, $phone) {
            // Find or create user
            $user = User::where('phone', $phone)->first();

            if (!$user) {
                // Create new user as CUSTOMER type (fish seller is an additional profile)
                // Per SRS Section 6.3: user types are only 'customer' and 'shop'
                $user = User::create([
                    'phone' => $phone,
                    'name' => trim($data['name']),
                    'type' => UserType::CUSTOMER,
                    'latitude' => (float) $data['latitude'],
                    'longitude' => (float) $data['longitude'],
                    'registered_at' => now(),
                ]);

                Log::info('Created new user for fish seller', ['user_id' => $user->id]);
            } else {
                // Update existing user's location
                $user->update([
                    'latitude' => (float) $data['latitude'],
                    'longitude' => (float) $data['longitude'],
                ]);
            }

            // Check if already has fish seller profile
            if ($user->fishSeller) {
                throw new \InvalidArgumentException('Already registered as fish seller');
            }

            // Get seller type
            $sellerType = $data['seller_type'] instanceof FishSellerType
                ? $data['seller_type']
                : FishSellerType::from($data['seller_type']);

            // Create fish seller profile
            $seller = FishSeller::create([
                'user_id' => $user->id,
                'seller_type' => $sellerType,
                'location_name' => trim($data['location_name']),
                'latitude' => (float) $data['latitude'],
                'longitude' => (float) $data['longitude'],
                'verification_status' => FishSellerVerificationStatus::PENDING,
                'verification_photo_url' => $data['verification_photo_url'] ?? null,
                'rating' => 0,
                'rating_count' => 0,
                'total_sales' => 0,
                'total_catches' => 0,
                'is_active' => true,
            ]);

            Log::info('Fish seller registered', [
                'user_id' => $user->id,
                'seller_id' => $seller->id,
                'seller_type' => $sellerType->value,
                'location_name' => $data['location_name'],
            ]);

            return $seller->load('user');
        });
    }

    /**
     * Register existing user as fish seller.
     *
     * For users who are already customers/shop owners.
     *
     * @srs-ref PM-001 Any user can become fish seller
     */
    public function registerExistingUserAsSeller(
        User $user,
        FishSellerType $sellerType,
        string $locationName,
        float $latitude,
        float $longitude,
        ?string $verificationPhotoUrl = null
    ): FishSeller {
        if ($user->fishSeller) {
            throw new \InvalidArgumentException('Already a fish seller');
        }

        return DB::transaction(function () use ($user, $sellerType, $locationName, $latitude, $longitude, $verificationPhotoUrl) {
            // Update user location
            $user->update([
                'latitude' => $latitude,
                'longitude' => $longitude,
            ]);

            // Create fish seller profile (user keeps their type!)
            $seller = FishSeller::create([
                'user_id' => $user->id,
                'seller_type' => $sellerType,
                'location_name' => trim($locationName),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'verification_status' => FishSellerVerificationStatus::PENDING,
                'verification_photo_url' => $verificationPhotoUrl,
                'rating' => 0,
                'rating_count' => 0,
                'total_sales' => 0,
                'total_catches' => 0,
                'is_active' => true,
            ]);

            Log::info('Existing user registered as fish seller', [
                'user_id' => $user->id,
                'seller_id' => $seller->id,
                'original_type' => $user->type->value,
                'seller_type' => $sellerType->value,
            ]);

            return $seller->load('user');
        });
    }

    /**
     * Set verification photo.
     *
     * @srs-ref PM-002 Photo verification
     */
    public function setVerificationPhoto(FishSeller $seller, string $photoUrl): FishSeller
    {
        $seller->update(['verification_photo_url' => $photoUrl]);

        Log::info('Verification photo set', [
            'seller_id' => $seller->id,
            'photo_url' => $photoUrl,
        ]);

        return $seller->fresh();
    }

    /**
     * Verify a seller.
     *
     * @srs-ref PM-003 Verification status: verified
     */
    public function verifySeller(FishSeller $seller): FishSeller
    {
        $seller->verify();

        Log::info('Fish seller verified', ['seller_id' => $seller->id]);

        return $seller->fresh();
    }

    /**
     * Suspend a seller.
     *
     * @srs-ref PM-003 Verification status: suspended
     */
    public function suspendSeller(FishSeller $seller, ?string $reason = null): FishSeller
    {
        $seller->suspend();

        Log::info('Fish seller suspended', [
            'seller_id' => $seller->id,
            'reason' => $reason,
        ]);

        return $seller->fresh();
    }

    /**
     * Add rating to seller.
     *
     * @srs-ref PM-004 Track seller rating 1-5 stars
     */
    public function addRating(FishSeller $seller, int $rating): FishSeller
    {
        $seller->updateRating($rating);

        Log::info('Rating added', [
            'seller_id' => $seller->id,
            'new_rating' => $rating,
            'avg_rating' => $seller->fresh()->rating,
        ]);

        return $seller->fresh();
    }

    /**
     * Increment sales count.
     *
     * @srs-ref PM-004 Track total sales count
     */
    public function incrementSales(FishSeller $seller): void
    {
        $seller->incrementSales();
    }

    /**
     * Update seller location.
     */
    public function updateLocation(FishSeller $seller, float $lat, float $lng, ?string $locationName = null): FishSeller
    {
        $seller->updateLocation($lat, $lng, $locationName);

        return $seller->fresh();
    }

    /**
     * Find seller by user ID.
     */
    public function findByUserId(int $userId): ?FishSeller
    {
        return FishSeller::where('user_id', $userId)->first();
    }

    /**
     * Find seller by phone.
     */
    public function findByPhone(string $phone): ?FishSeller
    {
        $phone = $this->normalizePhone($phone);

        return FishSeller::whereHas('user', function ($q) use ($phone) {
            $q->where('phone', $phone);
        })->first();
    }

    /**
     * Get seller for session.
     */
    public function getSellerForSession(ConversationSession $session): ?FishSeller
    {
        // Try by user_id first
        if ($session->user_id) {
            $seller = $this->findByUserId($session->user_id);
            if ($seller) {
                return $seller;
            }
        }

        // Try by phone
        return $this->findByPhone($session->phone);
    }

    /**
     * Check if user is a fish seller.
     */
    public function isFishSeller(User $user): bool
    {
        return $user->fishSeller !== null;
    }

    /**
     * Check if user can register as seller.
     */
    public function canRegisterAsSeller(User $user): bool
    {
        return $user->fishSeller === null;
    }

    /**
     * Find sellers near location.
     */
    public function findNearby(float $lat, float $lng, float $radiusKm = 5, ?FishSellerType $type = null): Collection
    {
        $query = FishSeller::active()
            ->canPost()
            ->withDistanceFrom($lat, $lng)
            ->nearLocation($lat, $lng, $radiusKm);

        if ($type) {
            $query->ofType($type);
        }

        return $query->orderBy('distance_km')->get();
    }

    /**
     * Find sellers with active catches.
     */
    public function findWithActiveCatches(float $lat, float $lng, float $radiusKm = 5): Collection
    {
        return FishSeller::active()
            ->canPost()
            ->withActiveCatches()
            ->withDistanceFrom($lat, $lng)
            ->nearLocation($lat, $lng, $radiusKm)
            ->orderBy('distance_km')
            ->get();
    }

    /**
     * Get seller stats.
     */
    public function getSellerStats(FishSeller $seller): array
    {
        return $seller->getStats();
    }

    /**
     * Link session to user.
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

    protected function validateRegistrationData(array $data): void
    {
        $required = ['phone', 'name', 'seller_type', 'location_name', 'latitude', 'longitude'];

        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!$this->isValidPhone($data['phone'])) {
            throw new \InvalidArgumentException('Invalid phone number');
        }

        // Validate seller type
        $sellerType = $data['seller_type'];
        if (!$sellerType instanceof FishSellerType && FishSellerType::tryFrom($sellerType) === null) {
            throw new \InvalidArgumentException('Invalid seller type');
        }

        if (!$this->isValidCoordinates($data['latitude'], $data['longitude'])) {
            throw new \InvalidArgumentException('Invalid coordinates');
        }
    }

    protected function isValidPhone(string $phone): bool
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        return strlen($cleaned) >= 10 && strlen($cleaned) <= 15;
    }

    protected function isValidCoordinates(mixed $lat, mixed $lng): bool
    {
        if (!is_numeric($lat) || !is_numeric($lng)) {
            return false;
        }
        $lat = (float) $lat;
        $lng = (float) $lng;
        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    protected function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($cleaned) === 10) {
            $cleaned = '91' . $cleaned;
        }
        return $cleaned;
    }
}