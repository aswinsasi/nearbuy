<?php

declare(strict_types=1);

namespace App\Services\Fish;

use App\Enums\FishAlertFrequency;
use App\Models\FishSubscription;
use App\Models\FishType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing fish alert subscriptions.
 *
 * Handles:
 * - Creating/updating subscriptions
 * - Managing fish type preferences
 * - Pause/resume subscriptions
 * - Finding matching subscriptions for catches
 *
 * @srs-ref Pacha Meen Module - Section 2.3.3 Customer Subscription
 */
class FishSubscriptionService
{
    /**
     * Maximum subscriptions per user.
     */
    public const MAX_SUBSCRIPTIONS_PER_USER = 5;

    /**
     * Default radius in km.
     */
    public const DEFAULT_RADIUS_KM = 5;

    /**
     * Create a new fish subscription.
     *
     * @param User $user
     * @param array $data {
     *     @type float $latitude Location latitude (required)
     *     @type float $longitude Location longitude (required)
     *     @type string|null $name Subscription name (e.g., "Home", "Office")
     *     @type string|null $address Address text
     *     @type string|null $location_label User-friendly location name
     *     @type int $radius_km Alert radius in km (default: 5)
     *     @type array|null $fish_type_ids Array of fish type IDs to subscribe to
     *     @type bool $all_fish_types Subscribe to all fish types (default: true)
     *     @type string $alert_frequency FishAlertFrequency value (default: immediate)
     *     @type string|null $quiet_hours_start Don't send alerts after this time (HH:mm)
     *     @type string|null $quiet_hours_end Resume alerts after this time (HH:mm)
     *     @type array|null $active_days Days to receive alerts [0-6] (0=Sunday)
     * }
     * @return FishSubscription
     * @throws \InvalidArgumentException
     */
    public function createSubscription(User $user, array $data): FishSubscription
    {
        $this->validateSubscriptionData($data);

        // Check subscription limit
        if ($this->hasReachedSubscriptionLimit($user)) {
            throw new \InvalidArgumentException(
                'Maximum subscription limit reached (' . self::MAX_SUBSCRIPTIONS_PER_USER . ')'
            );
        }

        // Parse alert frequency
        $alertFrequency = isset($data['alert_frequency'])
            ? ($data['alert_frequency'] instanceof FishAlertFrequency
                ? $data['alert_frequency']
                : FishAlertFrequency::from($data['alert_frequency']))
            : FishAlertFrequency::IMMEDIATE;

        // Validate and process fish type IDs
        $fishTypeIds = null;
        $allFishTypes = $data['all_fish_types'] ?? true;

        if (!$allFishTypes && !empty($data['fish_type_ids'])) {
            $fishTypeIds = $this->validateFishTypeIds($data['fish_type_ids']);
            if (empty($fishTypeIds)) {
                $allFishTypes = true;
            }
        }

        $subscription = FishSubscription::create([
            'user_id' => $user->id,
            'name' => $data['name'] ?? null,
            'latitude' => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
            'address' => $data['address'] ?? null,
            'location_label' => $data['location_label'] ?? null,
            'radius_km' => $data['radius_km'] ?? self::DEFAULT_RADIUS_KM,
            'fish_type_ids' => $fishTypeIds,
            'all_fish_types' => $allFishTypes,
            'preferred_seller_ids' => $data['preferred_seller_ids'] ?? null,
            'blocked_seller_ids' => $data['blocked_seller_ids'] ?? null,
            'alert_frequency' => $alertFrequency,
            'quiet_hours_start' => $data['quiet_hours_start'] ?? null,
            'quiet_hours_end' => $data['quiet_hours_end'] ?? null,
            'active_days' => $data['active_days'] ?? null,
            'is_active' => true,
            'is_paused' => false,
            'alerts_received' => 0,
            'alerts_clicked' => 0,
        ]);

        Log::info('Fish subscription created', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'radius_km' => $subscription->radius_km,
            'frequency' => $alertFrequency->value,
        ]);

        return $subscription;
    }

    /**
     * Update an existing subscription.
     */
    public function updateSubscription(FishSubscription $subscription, array $data): FishSubscription
    {
        $updateData = [];

        // Update location
        if (isset($data['latitude'], $data['longitude'])) {
            if ($this->isValidCoordinates($data['latitude'], $data['longitude'])) {
                $updateData['latitude'] = (float) $data['latitude'];
                $updateData['longitude'] = (float) $data['longitude'];
            }
        }

        // Update basic fields
        $simpleFields = ['name', 'address', 'location_label', 'quiet_hours_start', 'quiet_hours_end'];
        foreach ($simpleFields as $field) {
            if (isset($data[$field])) {
                $updateData[$field] = $data[$field];
            }
        }

        // Update radius
        if (isset($data['radius_km'])) {
            $radius = (int) $data['radius_km'];
            if ($radius >= 1 && $radius <= 50) {
                $updateData['radius_km'] = $radius;
            }
        }

        // Update fish types
        if (isset($data['all_fish_types'])) {
            $updateData['all_fish_types'] = (bool) $data['all_fish_types'];
            if ($updateData['all_fish_types']) {
                $updateData['fish_type_ids'] = null;
            }
        }

        if (isset($data['fish_type_ids'])) {
            $fishTypeIds = $this->validateFishTypeIds($data['fish_type_ids']);
            $updateData['fish_type_ids'] = empty($fishTypeIds) ? null : $fishTypeIds;
            if (!empty($fishTypeIds)) {
                $updateData['all_fish_types'] = false;
            }
        }

        // Update alert frequency
        if (isset($data['alert_frequency'])) {
            $updateData['alert_frequency'] = $data['alert_frequency'] instanceof FishAlertFrequency
                ? $data['alert_frequency']
                : FishAlertFrequency::from($data['alert_frequency']);
        }

        // Update active days
        if (isset($data['active_days'])) {
            $updateData['active_days'] = $data['active_days'];
        }

        if (!empty($updateData)) {
            $subscription->update($updateData);
            Log::info('Fish subscription updated', ['subscription_id' => $subscription->id]);
        }

        return $subscription->fresh();
    }

    /**
     * Update subscription location.
     */
    public function updateLocation(
        FishSubscription $subscription,
        float $latitude,
        float $longitude,
        ?string $locationLabel = null
    ): FishSubscription {
        if (!$this->isValidCoordinates($latitude, $longitude)) {
            throw new \InvalidArgumentException('Invalid coordinates');
        }

        $updateData = [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        if ($locationLabel) {
            $updateData['location_label'] = $locationLabel;
        }

        $subscription->update($updateData);

        return $subscription->fresh();
    }

    /**
     * Set fish type preferences.
     */
    public function setFishTypes(FishSubscription $subscription, array $fishTypeIds): FishSubscription
    {
        $validIds = $this->validateFishTypeIds($fishTypeIds);

        if (empty($validIds)) {
            $subscription->update([
                'fish_type_ids' => null,
                'all_fish_types' => true,
            ]);
        } else {
            $subscription->update([
                'fish_type_ids' => $validIds,
                'all_fish_types' => false,
            ]);
        }

        return $subscription->fresh();
    }

    /**
     * Add a fish type to subscription.
     */
    public function addFishType(FishSubscription $subscription, int $fishTypeId): FishSubscription
    {
        if (!FishType::where('id', $fishTypeId)->where('is_active', true)->exists()) {
            throw new \InvalidArgumentException('Invalid fish type');
        }

        $subscription->addFishType($fishTypeId);

        return $subscription->fresh();
    }

    /**
     * Remove a fish type from subscription.
     */
    public function removeFishType(FishSubscription $subscription, int $fishTypeId): FishSubscription
    {
        $subscription->removeFishType($fishTypeId);

        return $subscription->fresh();
    }

    /**
     * Set alert frequency.
     */
    public function setAlertFrequency(
        FishSubscription $subscription,
        FishAlertFrequency $frequency
    ): FishSubscription {
        $subscription->update(['alert_frequency' => $frequency]);

        return $subscription->fresh();
    }

    /**
     * Set alert radius.
     */
    public function setRadius(FishSubscription $subscription, int $radiusKm): FishSubscription
    {
        if ($radiusKm < 1 || $radiusKm > 50) {
            throw new \InvalidArgumentException('Radius must be between 1 and 50 km');
        }

        $subscription->update(['radius_km' => $radiusKm]);

        return $subscription->fresh();
    }

    /**
     * Set quiet hours.
     */
    public function setQuietHours(
        FishSubscription $subscription,
        ?string $startTime,
        ?string $endTime
    ): FishSubscription {
        $subscription->update([
            'quiet_hours_start' => $startTime,
            'quiet_hours_end' => $endTime,
        ]);

        return $subscription->fresh();
    }

    /**
     * Pause subscription.
     */
    public function pauseSubscription(
        FishSubscription $subscription,
        ?\Carbon\Carbon $until = null
    ): FishSubscription {
        $subscription->pause($until);

        Log::info('Fish subscription paused', [
            'subscription_id' => $subscription->id,
            'until' => $until?->toIso8601String(),
        ]);

        return $subscription->fresh();
    }

    /**
     * Resume subscription.
     */
    public function resumeSubscription(FishSubscription $subscription): FishSubscription
    {
        $subscription->resume();

        Log::info('Fish subscription resumed', ['subscription_id' => $subscription->id]);

        return $subscription->fresh();
    }

    /**
     * Deactivate subscription.
     */
    public function deactivateSubscription(FishSubscription $subscription): FishSubscription
    {
        $subscription->deactivate();

        Log::info('Fish subscription deactivated', ['subscription_id' => $subscription->id]);

        return $subscription->fresh();
    }

    /**
     * Activate subscription.
     */
    public function activateSubscription(FishSubscription $subscription): FishSubscription
    {
        $subscription->activate();

        return $subscription->fresh();
    }

    /**
     * Delete subscription (soft delete).
     */
    public function deleteSubscription(FishSubscription $subscription): bool
    {
        Log::info('Fish subscription deleted', ['subscription_id' => $subscription->id]);

        return $subscription->delete();
    }

    /**
     * Block a seller for this subscription.
     */
    public function blockSeller(FishSubscription $subscription, int $sellerId): FishSubscription
    {
        $subscription->blockSeller($sellerId);

        return $subscription->fresh();
    }

    /**
     * Unblock a seller for this subscription.
     */
    public function unblockSeller(FishSubscription $subscription, int $sellerId): FishSubscription
    {
        $subscription->unblockSeller($sellerId);

        return $subscription->fresh();
    }

    /**
     * Find subscription by ID.
     */
    public function findById(int $subscriptionId): ?FishSubscription
    {
        return FishSubscription::find($subscriptionId);
    }

    /**
     * Get all subscriptions for a user.
     */
    public function getUserSubscriptions(User $user): Collection
    {
        return $user->fishSubscriptions()
            ->orderBy('is_active', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get active subscriptions for a user.
     */
    public function getUserActiveSubscriptions(User $user): Collection
    {
        return $user->activeFishSubscriptions()->get();
    }

    /**
     * Get user's primary (first active) subscription.
     */
    public function getUserPrimarySubscription(User $user): ?FishSubscription
    {
        return $user->activeFishSubscriptions()->first();
    }

    /**
     * Find subscriptions that match a fish catch.
     *
     * Used by alert system to find who should receive notifications.
     */
    public function findMatchingSubscriptions(
        \App\Models\FishCatch $catch,
        bool $immediateOnly = false
    ): Collection {
        $query = FishSubscription::matchingCatch($catch)
            ->canReceiveAlerts();

        if ($immediateOnly) {
            $query->forImmediateAlerts();
        }

        return $query->get();
    }

    /**
     * Find subscriptions for batched alerts by frequency.
     */
    public function getSubscriptionsForBatchedAlerts(FishAlertFrequency $frequency): Collection
    {
        return FishSubscription::active()
            ->ofFrequency($frequency)
            ->get();
    }

    /**
     * Get subscription statistics.
     */
    public function getSubscriptionStats(FishSubscription $subscription): array
    {
        return [
            'alerts_received' => $subscription->alerts_received,
            'alerts_clicked' => $subscription->alerts_clicked,
            'click_rate' => $subscription->click_rate,
            'last_alert_at' => $subscription->last_alert_at?->diffForHumans(),
            'is_active' => $subscription->is_active,
            'is_paused' => $subscription->is_paused,
            'can_receive_now' => $subscription->can_receive_alerts_now,
        ];
    }

    /**
     * Get popular fish types for subscription suggestions.
     */
    public function getPopularFishTypes(int $limit = 10): Collection
    {
        return FishType::active()
            ->popular()
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();
    }

    /**
     * Get all available fish types grouped by category.
     */
    public function getFishTypesByCategory(): array
    {
        return FishType::getGroupedByCategory();
    }

    /**
     * Check if user has any active subscriptions.
     */
    public function hasActiveSubscription(User $user): bool
    {
        return $user->activeFishSubscriptions()->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Validation & Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Validate subscription data.
     */
    protected function validateSubscriptionData(array $data): void
    {
        $required = ['latitude', 'longitude'];

        foreach ($required as $field) {
            if (!isset($data[$field])) {
                throw new \InvalidArgumentException("Missing required field: {$field}");
            }
        }

        if (!$this->isValidCoordinates($data['latitude'], $data['longitude'])) {
            throw new \InvalidArgumentException('Invalid coordinates');
        }

        // Validate radius if provided
        if (isset($data['radius_km'])) {
            $radius = (int) $data['radius_km'];
            if ($radius < 1 || $radius > 50) {
                throw new \InvalidArgumentException('Radius must be between 1 and 50 km');
            }
        }

        // Validate alert frequency if provided
        if (isset($data['alert_frequency'])) {
            $freq = $data['alert_frequency'];
            if (!$freq instanceof FishAlertFrequency && FishAlertFrequency::tryFrom($freq) === null) {
                throw new \InvalidArgumentException('Invalid alert frequency');
            }
        }
    }

    /**
     * Validate coordinates.
     */
    protected function isValidCoordinates(mixed $latitude, mixed $longitude): bool
    {
        if (!is_numeric($latitude) || !is_numeric($longitude)) {
            return false;
        }

        $lat = (float) $latitude;
        $lng = (float) $longitude;

        return $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
    }

    /**
     * Validate fish type IDs.
     *
     * @return array Valid fish type IDs
     */
    protected function validateFishTypeIds(array $ids): array
    {
        $ids = array_filter(array_map('intval', $ids));

        if (empty($ids)) {
            return [];
        }

        // Get only valid active fish type IDs
        return FishType::whereIn('id', $ids)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();
    }

    /**
     * Check if user has reached subscription limit.
     */
    protected function hasReachedSubscriptionLimit(User $user): bool
    {
        return $user->fishSubscriptions()->count() >= self::MAX_SUBSCRIPTIONS_PER_USER;
    }
}
