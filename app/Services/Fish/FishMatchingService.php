<?php

declare(strict_types=1);

namespace App\Services\Fish;

use App\Enums\FishAlertFrequency;
use App\Models\FishCatch;
use App\Models\FishSubscription;
use App\Models\FishType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Fish Matching Service.
 *
 * Matches catches to subscribers based on:
 * - Fish type (specific types or "All Fish")
 * - Distance within subscriber's radius
 * - Alert time preferences (PM-014, PM-020)
 * - Prioritizes nearest subscribers first
 *
 * @srs-ref PM-011: Subscribe to specific fish types
 * @srs-ref PM-013: Configurable alert radius
 * @srs-ref PM-014: Alert time preferences
 * @srs-ref PM-019: Social proof (customers_coming count)
 * @srs-ref PM-020: Respect alert time preferences
 */
class FishMatchingService
{
    /**
     * Earth radius in km for Haversine formula.
     */
    protected const EARTH_RADIUS_KM = 6371;

    /**
     * Find all matching subscriptions for a catch.
     *
     * @param FishCatch $catch
     * @param bool $respectTimePrefs - If true, only returns subscriptions currently in their alert window
     * @return Collection Sorted by distance (nearest first)
     */
    public function findMatchingSubscriptions(FishCatch $catch, bool $respectTimePrefs = false): Collection
    {
        $catchLat = $catch->latitude ?? $catch->seller?->latitude;
        $catchLng = $catch->longitude ?? $catch->seller?->longitude;

        if (!$catchLat || !$catchLng) {
            Log::warning('Catch has no location', ['catch_id' => $catch->id]);
            return collect();
        }

        $query = FishSubscription::query()
            // Active and not paused
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_paused', false)
                    ->orWhere('paused_until', '<', now());
            })
            // Within radius (Haversine in MySQL)
            ->whereRaw(
                "(? * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= radius_km",
                [self::EARTH_RADIUS_KM, $catchLat, $catchLng, $catchLat]
            )
            // Fish type match: all_fish_types OR specific type in array
            ->where(function ($q) use ($catch) {
                $q->where('all_fish_types', true)
                    ->orWhereJsonContains('fish_type_ids', $catch->fish_type_id);
            })
            // Add calculated distance for sorting
            ->selectRaw('*, (? * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) as distance_km', 
                [self::EARTH_RADIUS_KM, $catchLat, $catchLng, $catchLat]
            )
            // Nearest first (for viral effect - closest customers get alerts first)
            ->orderBy('distance_km');

        $subscriptions = $query->get();

        // Filter by time preferences if requested (PM-014, PM-020)
        if ($respectTimePrefs) {
            $subscriptions = $subscriptions->filter(function ($sub) {
                return $sub->alert_frequency->isWithinWindow();
            });
        }

        Log::info('Matched subscriptions for catch', [
            'catch_id' => $catch->id,
            'fish_type' => $catch->fish_type_id,
            'total_matches' => $subscriptions->count(),
        ]);

        return $subscriptions;
    }

    /**
     * Find subscriptions for IMMEDIATE alerts only.
     * These get real-time notifications (PM-014: "Anytime").
     */
    public function findImmediateSubscriptions(FishCatch $catch): Collection
    {
        return $this->findMatchingSubscriptions($catch)
            ->filter(fn($sub) => $sub->alert_frequency === FishAlertFrequency::ANYTIME);
    }

    /**
     * Find subscriptions that need BATCHED alerts.
     * These get scheduled notifications (PM-014: Early Morning, Morning).
     */
    public function findBatchedSubscriptions(FishCatch $catch): Collection
    {
        return $this->findMatchingSubscriptions($catch)
            ->filter(fn($sub) => $sub->alert_frequency->shouldBatch());
    }

    /**
     * Find subscriptions by specific frequency.
     */
    public function findByFrequency(FishCatch $catch, FishAlertFrequency $frequency): Collection
    {
        return $this->findMatchingSubscriptions($catch)
            ->filter(fn($sub) => $sub->alert_frequency === $frequency);
    }

    /**
     * Check if a subscription matches a catch.
     */
    public function matches(FishSubscription $subscription, FishCatch $catch): bool
    {
        // Must be active
        if (!$subscription->is_active) {
            return false;
        }

        // Must not be paused
        if ($subscription->is_paused && (!$subscription->paused_until || $subscription->paused_until->isFuture())) {
            return false;
        }

        // Fish type must match
        if (!$subscription->all_fish_types && $subscription->fish_type_ids) {
            if (!in_array($catch->fish_type_id, $subscription->fish_type_ids)) {
                return false;
            }
        }

        // Must be within radius
        $distance = $this->calculateDistance(
            $subscription->latitude,
            $subscription->longitude,
            $catch->latitude ?? $catch->seller?->latitude ?? 0,
            $catch->longitude ?? $catch->seller?->longitude ?? 0
        );

        return $distance <= $subscription->radius_km;
    }

    /**
     * Calculate distance between two points (Haversine).
     *
     * @return float Distance in kilometers
     */
    public function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);

        $a = sin($dLat / 2) ** 2 +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($dLng / 2) ** 2;

        return self::EARTH_RADIUS_KM * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Get subscriber count for a catch location.
     * Used for social proof display (PM-019).
     */
    public function countSubscribers(float $lat, float $lng, ?int $fishTypeId = null): int
    {
        $query = FishSubscription::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_paused', false)
                    ->orWhere('paused_until', '<', now());
            })
            ->whereRaw(
                "(? * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= radius_km",
                [self::EARTH_RADIUS_KM, $lat, $lng, $lat]
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
     * Get social proof text for display (PM-019).
     */
    public function getSocialProofText(int $comingCount): string
    {
        if ($comingCount === 0) {
            return '';
        }
        if ($comingCount < 5) {
            return "👥 {$comingCount} people coming";
        }
        return "👥 *{$comingCount} people already coming!*";
    }

    /**
     * Find catches matching a subscription.
     */
    public function findCatchesForSubscription(FishSubscription $subscription, int $limit = 10): Collection
    {
        $query = FishCatch::query()
            ->where('status', 'available')
            ->with(['seller', 'fishType']);

        // Filter by fish types
        if (!$subscription->all_fish_types && $subscription->fish_type_ids) {
            $query->whereIn('fish_type_id', $subscription->fish_type_ids);
        }

        // Filter by distance
        $lat = $subscription->latitude;
        $lng = $subscription->longitude;
        $radius = $subscription->radius_km;

        return $query
            ->selectRaw('*, (? * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) as distance_km',
                [self::EARTH_RADIUS_KM, $lat, $lng, $lat]
            )
            ->havingRaw('distance_km <= ?', [$radius])
            ->orderBy('distance_km')
            ->limit($limit)
            ->get();
    }

    /**
     * Get popular fish types near a location.
     */
    public function getPopularFishNearby(float $lat, float $lng, float $radiusKm = 10): Collection
    {
        // Get recent catches in area
        $catches = FishCatch::query()
            ->where('status', 'available')
            ->where('created_at', '>=', now()->subDays(7))
            ->selectRaw('fish_type_id, COUNT(*) as catch_count')
            ->whereRaw(
                "(? * acos(cos(radians(?)) * cos(radians(latitude)) * cos(radians(longitude) - radians(?)) + sin(radians(?)) * sin(radians(latitude)))) <= ?",
                [self::EARTH_RADIUS_KM, $lat, $lng, $lat, $radiusKm]
            )
            ->groupBy('fish_type_id')
            ->orderByDesc('catch_count')
            ->limit(10)
            ->pluck('catch_count', 'fish_type_id');

        return FishType::whereIn('id', $catches->keys())
            ->get()
            ->map(function ($fish) use ($catches) {
                $fish->recent_catches = $catches[$fish->id] ?? 0;
                return $fish;
            })
            ->sortByDesc('recent_catches');
    }

    /**
     * Group subscriptions by alert frequency for batch processing.
     */
    public function groupByFrequency(Collection $subscriptions): array
    {
        return [
            'immediate' => $subscriptions->filter(fn($s) => $s->alert_frequency === FishAlertFrequency::ANYTIME),
            'early_morning' => $subscriptions->filter(fn($s) => $s->alert_frequency === FishAlertFrequency::EARLY_MORNING),
            'morning' => $subscriptions->filter(fn($s) => $s->alert_frequency === FishAlertFrequency::MORNING),
            'twice_daily' => $subscriptions->filter(fn($s) => $s->alert_frequency === FishAlertFrequency::TWICE_DAILY),
        ];
    }
}