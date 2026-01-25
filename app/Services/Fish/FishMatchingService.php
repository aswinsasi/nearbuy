<?php

declare(strict_types=1);

namespace App\Services\Fish;

use App\Models\FishCatch;
use App\Models\FishSubscription;
use App\Models\FishSeller;
use App\Models\FishType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for matching fish catches to subscribers.
 *
 * Handles:
 * - Location-based matching (radius calculation)
 * - Fish type preference matching
 * - Seller preference/blocking
 * - Distance calculations
 *
 * @srs-ref Pacha Meen Module - Alert matching logic
 */
class FishMatchingService
{
    /**
     * Earth radius in kilometers.
     */
    protected const EARTH_RADIUS_KM = 6371;

    /**
     * Find all subscriptions that match a given catch.
     *
     * A subscription matches if:
     * 1. It's active and not paused
     * 2. Catch is within subscription's radius
     * 3. Fish type matches (if specific types selected)
     * 4. Seller is not blocked
     * 5. Not in quiet hours
     * 6. Active on current day
     */
    public function findMatchingSubscriptions(FishCatch $catch): Collection
    {
        $catchLat = $catch->catch_latitude;
        $catchLng = $catch->catch_longitude;
        $fishTypeId = $catch->fish_type_id;
        $sellerId = $catch->fish_seller_id;

        // Use database query for efficient matching
        return FishSubscription::query()
            // Active and not paused
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_paused', false)
                    ->orWhere('paused_until', '<', now());
            })
            // Within radius (using Haversine formula in MySQL)
            ->whereRaw(
                "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= radius_km",
                [$catchLat, $catchLng, $catchLat]
            )
            // Fish type matches
            ->where(function ($q) use ($fishTypeId) {
                $q->where('all_fish_types', true)
                    ->orWhereJsonContains('fish_type_ids', $fishTypeId);
            })
            // Seller not blocked
            ->where(function ($q) use ($sellerId) {
                $q->whereNull('blocked_seller_ids')
                    ->orWhereRaw('NOT JSON_CONTAINS(blocked_seller_ids, ?)', [json_encode($sellerId)]);
            })
            // Not in quiet hours
            ->where(function ($q) {
                $currentTime = now()->format('H:i');
                $q->whereNull('quiet_hours_start')
                    ->orWhereNull('quiet_hours_end')
                    ->orWhereRaw('? NOT BETWEEN quiet_hours_start AND quiet_hours_end', [$currentTime]);
            })
            // Active today
            ->where(function ($q) {
                $todayNum = (int) now()->format('w');
                $q->whereNull('active_days')
                    ->orWhereJsonContains('active_days', $todayNum);
            })
            ->get();
    }

    /**
     * Find subscriptions for immediate alerts only.
     */
    public function findImmediateSubscriptions(FishCatch $catch): Collection
    {
        return $this->findMatchingSubscriptions($catch)
            ->where('alert_frequency', \App\Enums\FishAlertFrequency::IMMEDIATE);
    }

    /**
     * Find subscriptions for batched alerts only.
     */
    public function findBatchedSubscriptions(FishCatch $catch): Collection
    {
        return $this->findMatchingSubscriptions($catch)
            ->whereIn('alert_frequency', [
                \App\Enums\FishAlertFrequency::MORNING_ONLY,
                \App\Enums\FishAlertFrequency::TWICE_DAILY,
                \App\Enums\FishAlertFrequency::WEEKLY_DIGEST,
            ]);
    }

    /**
     * Check if a single subscription matches a catch.
     */
    public function subscriptionMatchesCatch(FishSubscription $subscription, FishCatch $catch): bool
    {
        // Check if active
        if (!$subscription->is_active) {
            return false;
        }

        // Check if paused
        if ($subscription->is_paused && (!$subscription->paused_until || $subscription->paused_until > now())) {
            return false;
        }

        // Check fish type
        if (!$subscription->all_fish_types && $subscription->fish_type_ids) {
            if (!in_array($catch->fish_type_id, $subscription->fish_type_ids)) {
                return false;
            }
        }

        // Check blocked sellers
        if ($subscription->blocked_seller_ids && in_array($catch->fish_seller_id, $subscription->blocked_seller_ids)) {
            return false;
        }

        // Check distance
        $distance = $this->calculateDistance(
            $subscription->latitude,
            $subscription->longitude,
            $catch->catch_latitude,
            $catch->catch_longitude
        );

        if ($distance > $subscription->radius_km) {
            return false;
        }

        // Check quiet hours
        if ($subscription->quiet_hours_start && $subscription->quiet_hours_end) {
            $currentTime = now()->format('H:i');
            if ($currentTime >= $subscription->quiet_hours_start && $currentTime <= $subscription->quiet_hours_end) {
                return false;
            }
        }

        // Check active days
        if ($subscription->active_days) {
            $todayNum = (int) now()->format('w');
            if (!in_array($todayNum, $subscription->active_days)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Calculate distance between two points using Haversine formula.
     *
     * @return float Distance in kilometers
     */
    public function calculateDistance(
        float $lat1,
        float $lng1,
        float $lat2,
        float $lng2
    ): float {
        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_KM * $c;
    }

    /**
     * Find catches near a subscription's location.
     */
    public function findCatchesForSubscription(
        FishSubscription $subscription,
        int $limit = 20
    ): Collection {
        $query = FishCatch::active()
            ->with(['seller', 'fishType']);

        // Filter by fish types if not all
        if (!$subscription->all_fish_types && $subscription->fish_type_ids) {
            $query->ofFishTypes($subscription->fish_type_ids);
        }

        // Filter out blocked sellers
        if ($subscription->blocked_seller_ids) {
            $query->whereNotIn('fish_seller_id', $subscription->blocked_seller_ids);
        }

        // Filter by location and distance
        return $query
            ->withDistanceFrom($subscription->latitude, $subscription->longitude)
            ->nearLocation($subscription->latitude, $subscription->longitude, $subscription->radius_km)
            ->nearestFirst()
            ->limit($limit)
            ->get();
    }

    /**
     * Find nearby sellers for a location.
     */
    public function findNearbySellers(
        float $latitude,
        float $longitude,
        float $radiusKm = 5
    ): Collection {
        return FishSeller::active()
            ->withDistanceFrom($latitude, $longitude)
            ->nearLocation($latitude, $longitude, $radiusKm)
            ->orderBy('distance_km')
            ->get()
            ->map(function ($seller) use ($latitude, $longitude) {
                $seller->distance_km = $this->calculateDistance(
                    $latitude,
                    $longitude,
                    $seller->latitude,
                    $seller->longitude
                );
                return $seller;
            });
    }

    /**
     * Find subscribers count for a potential catch location.
     *
     * Useful for sellers to see potential reach.
     */
    public function countPotentialSubscribers(
        float $latitude,
        float $longitude,
        ?int $fishTypeId = null
    ): int {
        $query = FishSubscription::active();

        // Check if location falls within any subscription's radius
        $query->whereRaw(
            "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= radius_km",
            [$latitude, $longitude, $latitude]
        );

        if ($fishTypeId) {
            $query->where(function ($q) use ($fishTypeId) {
                $q->where('all_fish_types', true)
                    ->orWhereJsonContains('fish_type_ids', $fishTypeId);
            });
        }

        return $query->count();
    }

    /**
     * Get recommended fish types based on subscription data near a location.
     *
     * Shows what fish types have the most subscribers.
     */
    public function getPopularFishTypesNearLocation(
        float $latitude,
        float $longitude,
        float $radiusKm = 10,
        int $limit = 10
    ): Collection {
        // Get subscriptions near this location
        $subscriptions = FishSubscription::active()
            ->whereRaw(
                "(6371 * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?",
                [$latitude, $longitude, $latitude, $radiusKm]
            )
            ->get();

        // Count fish type preferences
        $fishTypeCounts = [];

        foreach ($subscriptions as $sub) {
            if ($sub->all_fish_types) {
                // This subscriber wants all types
                continue;
            }

            foreach ($sub->fish_type_ids ?? [] as $fishTypeId) {
                $fishTypeCounts[$fishTypeId] = ($fishTypeCounts[$fishTypeId] ?? 0) + 1;
            }
        }

        // Also add "all types" subscribers count
        $allTypesCount = $subscriptions->where('all_fish_types', true)->count();

        // Get fish types sorted by popularity
        arsort($fishTypeCounts);
        $topIds = array_slice(array_keys($fishTypeCounts), 0, $limit);

        $fishTypes = FishType::whereIn('id', $topIds)
            ->active()
            ->get()
            ->sortBy(function ($type) use ($fishTypeCounts) {
                return -($fishTypeCounts[$type->id] ?? 0);
            });

        // Add subscriber counts
        return $fishTypes->map(function ($type) use ($fishTypeCounts, $allTypesCount) {
            $type->subscriber_count = ($fishTypeCounts[$type->id] ?? 0) + $allTypesCount;
            return $type;
        });
    }

    /**
     * Get heatmap data for subscription density.
     *
     * Returns aggregated location data for visualization.
     */
    public function getSubscriptionHeatmap(
        float $minLat,
        float $maxLat,
        float $minLng,
        float $maxLng,
        float $gridSize = 0.01 // ~1km grid
    ): array {
        $heatmap = [];

        $subscriptions = FishSubscription::active()
            ->whereBetween('latitude', [$minLat, $maxLat])
            ->whereBetween('longitude', [$minLng, $maxLng])
            ->get();

        foreach ($subscriptions as $sub) {
            // Round to grid
            $gridLat = round($sub->latitude / $gridSize) * $gridSize;
            $gridLng = round($sub->longitude / $gridSize) * $gridSize;
            $key = "{$gridLat},{$gridLng}";

            if (!isset($heatmap[$key])) {
                $heatmap[$key] = [
                    'lat' => $gridLat,
                    'lng' => $gridLng,
                    'count' => 0,
                    'avg_radius' => 0,
                ];
            }

            $heatmap[$key]['count']++;
            $heatmap[$key]['avg_radius'] = (
                ($heatmap[$key]['avg_radius'] * ($heatmap[$key]['count'] - 1)) + $sub->radius_km
            ) / $heatmap[$key]['count'];
        }

        return array_values($heatmap);
    }

    /**
     * Suggest optimal location for a seller based on subscriber density.
     */
    public function suggestSellerLocation(
        float $currentLat,
        float $currentLng,
        float $searchRadius = 5
    ): array {
        // Get heatmap around current location
        $heatmap = $this->getSubscriptionHeatmap(
            $currentLat - ($searchRadius / 111), // ~111km per degree latitude
            $currentLat + ($searchRadius / 111),
            $currentLng - ($searchRadius / (111 * cos(deg2rad($currentLat)))),
            $currentLng + ($searchRadius / (111 * cos(deg2rad($currentLat)))),
            0.005 // Finer grid for suggestions
        );

        if (empty($heatmap)) {
            return [
                'suggested_lat' => $currentLat,
                'suggested_lng' => $currentLng,
                'potential_reach' => 0,
                'message' => 'No subscribers found nearby',
            ];
        }

        // Find highest density point
        usort($heatmap, fn($a, $b) => $b['count'] <=> $a['count']);
        $best = $heatmap[0];

        $distance = $this->calculateDistance($currentLat, $currentLng, $best['lat'], $best['lng']);

        return [
            'current_lat' => $currentLat,
            'current_lng' => $currentLng,
            'suggested_lat' => $best['lat'],
            'suggested_lng' => $best['lng'],
            'potential_reach' => $best['count'],
            'distance_km' => round($distance, 2),
            'message' => $distance < 0.5
                ? 'You are already in a good location!'
                : "Moving {$distance} km could increase your reach to {$best['count']} subscribers",
        ];
    }
}
