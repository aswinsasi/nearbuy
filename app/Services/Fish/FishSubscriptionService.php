<?php

declare(strict_types=1);

namespace App\Services\Fish;

use App\Enums\FishAlertFrequency;
use App\Models\FishCatch;
use App\Models\FishSubscription;
use App\Models\FishType;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Fish Subscription Service.
 *
 * @srs-ref PM-011 to PM-015 Customer Subscription
 */
class FishSubscriptionService
{
    /**
     * Max subscriptions per user.
     */
    public const MAX_SUBSCRIPTIONS = 3;

    /**
     * Create subscription.
     *
     * @param User $user
     * @param array $data {
     *   latitude: float,
     *   longitude: float,
     *   location_label?: string,
     *   radius_km?: int (default 5),
     *   fish_type_ids?: array,
     *   all_fish_types?: bool,
     *   alert_frequency?: string|FishAlertFrequency
     * }
     */
    public function createSubscription(User $user, array $data): FishSubscription
    {
        // Validate required fields
        if (!isset($data['latitude'], $data['longitude'])) {
            throw new \InvalidArgumentException('Location required');
        }

        // Check limit
        if ($user->fishSubscriptions()->count() >= self::MAX_SUBSCRIPTIONS) {
            throw new \InvalidArgumentException('Max subscriptions reached');
        }

        // Parse frequency
        $frequency = FishAlertFrequency::IMMEDIATE;
        if (isset($data['alert_frequency'])) {
            $frequency = $data['alert_frequency'] instanceof FishAlertFrequency
                ? $data['alert_frequency']
                : FishAlertFrequency::tryFrom($data['alert_frequency']) ?? FishAlertFrequency::IMMEDIATE;
        }

        // Validate fish type IDs
        $fishTypeIds = null;
        $allFish = $data['all_fish_types'] ?? true;
        
        if (!$allFish && !empty($data['fish_type_ids'])) {
            $fishTypeIds = FishType::whereIn('id', $data['fish_type_ids'])
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
            
            if (empty($fishTypeIds)) {
                $allFish = true;
            }
        }

        $subscription = FishSubscription::create([
            'user_id' => $user->id,
            'latitude' => (float) $data['latitude'],
            'longitude' => (float) $data['longitude'],
            'location_label' => $data['location_label'] ?? null,
            'radius_km' => $data['radius_km'] ?? FishSubscription::DEFAULT_RADIUS_KM,
            'fish_type_ids' => $fishTypeIds,
            'all_fish_types' => $allFish,
            'alert_frequency' => $frequency,
            'is_active' => true,
            'is_paused' => false,
            'alerts_received' => 0,
            'alerts_clicked' => 0,
        ]);

        Log::info('Subscription created', [
            'id' => $subscription->id,
            'user_id' => $user->id,
            'radius' => $subscription->radius_km,
        ]);

        return $subscription;
    }

    /**
     * Update subscription.
     */
    public function updateSubscription(FishSubscription $subscription, array $data): FishSubscription
    {
        $update = [];

        // Location
        if (isset($data['latitude'], $data['longitude'])) {
            $update['latitude'] = (float) $data['latitude'];
            $update['longitude'] = (float) $data['longitude'];
        }

        if (isset($data['location_label'])) {
            $update['location_label'] = $data['location_label'];
        }

        // Radius (PM-013)
        if (isset($data['radius_km'])) {
            $radius = (int) $data['radius_km'];
            if (in_array($radius, FishSubscription::RADIUS_OPTIONS) || ($radius >= 1 && $radius <= 50)) {
                $update['radius_km'] = $radius;
            }
        }

        // Fish types (PM-011)
        if (isset($data['all_fish_types'])) {
            $update['all_fish_types'] = (bool) $data['all_fish_types'];
            if ($update['all_fish_types']) {
                $update['fish_type_ids'] = null;
            }
        }

        if (isset($data['fish_type_ids'])) {
            $fishTypeIds = FishType::whereIn('id', $data['fish_type_ids'])
                ->where('is_active', true)
                ->pluck('id')
                ->toArray();
            
            $update['fish_type_ids'] = empty($fishTypeIds) ? null : $fishTypeIds;
            if (!empty($fishTypeIds)) {
                $update['all_fish_types'] = false;
            }
        }

        // Frequency (PM-014)
        if (isset($data['alert_frequency'])) {
            $update['alert_frequency'] = $data['alert_frequency'] instanceof FishAlertFrequency
                ? $data['alert_frequency']
                : FishAlertFrequency::tryFrom($data['alert_frequency']);
        }

        if (!empty($update)) {
            $subscription->update($update);
        }

        return $subscription->fresh();
    }

    /**
     * Update location (PM-012).
     */
    public function updateLocation(
        FishSubscription $subscription,
        float $latitude,
        float $longitude,
        ?string $label = null
    ): FishSubscription {
        $data = [
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];
        
        if ($label) {
            $data['location_label'] = $label;
        }

        $subscription->update($data);
        return $subscription->fresh();
    }

    /**
     * Set radius (PM-013).
     */
    public function setRadius(FishSubscription $subscription, int $radiusKm): FishSubscription
    {
        if ($radiusKm < 1 || $radiusKm > 50) {
            throw new \InvalidArgumentException('Radius must be 1-50 km');
        }

        $subscription->update(['radius_km' => $radiusKm]);
        return $subscription->fresh();
    }

    /**
     * Set fish types (PM-011).
     */
    public function setFishTypes(FishSubscription $subscription, array $fishTypeIds): FishSubscription
    {
        $validIds = FishType::whereIn('id', $fishTypeIds)
            ->where('is_active', true)
            ->pluck('id')
            ->toArray();

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
     * Set frequency (PM-014).
     */
    public function setFrequency(
        FishSubscription $subscription,
        FishAlertFrequency $frequency
    ): FishSubscription {
        $subscription->update(['alert_frequency' => $frequency]);
        return $subscription->fresh();
    }

    /**
     * Pause subscription (PM-015).
     */
    public function pause(FishSubscription $subscription, ?\Carbon\Carbon $until = null): FishSubscription
    {
        $subscription->pause($until);
        Log::info('Subscription paused', ['id' => $subscription->id]);
        return $subscription->fresh();
    }

    /**
     * Resume subscription (PM-015).
     */
    public function resume(FishSubscription $subscription): FishSubscription
    {
        $subscription->resume();
        Log::info('Subscription resumed', ['id' => $subscription->id]);
        return $subscription->fresh();
    }

    /**
     * Delete subscription.
     */
    public function delete(FishSubscription $subscription): bool
    {
        Log::info('Subscription deleted', ['id' => $subscription->id]);
        return $subscription->delete();
    }

    /**
     * Find subscriptions matching a catch.
     */
    public function findMatchingSubscriptions(FishCatch $catch): Collection
    {
        return FishSubscription::matchingCatch($catch)->get();
    }

    /**
     * Get user's primary subscription.
     */
    public function getUserSubscription(User $user): ?FishSubscription
    {
        return $user->fishSubscriptions()->active()->first();
    }

    /**
     * Get all user subscriptions.
     */
    public function getUserSubscriptions(User $user): Collection
    {
        return $user->fishSubscriptions()->orderByDesc('is_active')->get();
    }

    /**
     * Check if user has active subscription.
     */
    public function hasActiveSubscription(User $user): bool
    {
        return $user->fishSubscriptions()->active()->exists();
    }

    /**
     * Get subscription stats.
     */
    public function getStats(FishSubscription $subscription): array
    {
        return [
            'alerts_received' => $subscription->alerts_received,
            'alerts_clicked' => $subscription->alerts_clicked,
            'click_rate' => $subscription->click_rate,
            'last_alert' => $subscription->last_alert_at?->diffForHumans(),
            'is_active' => $subscription->is_active_now,
        ];
    }

    /**
     * Get popular fish types for selection.
     */
    public function getPopularFishTypes(int $limit = 8): Collection
    {
        return FishType::active()
            ->where('is_popular', true)
            ->orderBy('sort_order')
            ->limit($limit)
            ->get();
    }

    /**
     * Get fish types by category.
     */
    public function getFishTypesByCategory(): array
    {
        return FishType::active()
            ->orderBy('category')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('category')
            ->toArray();
    }
}