<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FishAlertFrequency;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fish Subscription Model.
 *
 * @property int $id
 * @property int $user_id
 * @property float $latitude
 * @property float $longitude
 * @property string|null $location_label
 * @property int $radius_km - PM-013: 2, 5 (default), 10
 * @property array|null $fish_type_ids - PM-011: specific fish types
 * @property bool $all_fish_types - PM-011: all fish option
 * @property FishAlertFrequency $alert_frequency - PM-014: time preference
 * @property bool $is_active
 * @property bool $is_paused - PM-015: pause alerts
 * @property \Carbon\Carbon|null $paused_until
 * @property int $alerts_received
 * @property int $alerts_clicked
 *
 * @srs-ref PM-011 to PM-015 Customer Subscription
 */
class FishSubscription extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'latitude',
        'longitude',
        'location_label',
        'radius_km',
        'fish_type_ids',
        'all_fish_types',
        'alert_frequency',
        'is_active',
        'is_paused',
        'paused_until',
        'alerts_received',
        'alerts_clicked',
        'last_alert_at',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'radius_km' => 'integer',
        'fish_type_ids' => 'array',
        'all_fish_types' => 'boolean',
        'alert_frequency' => FishAlertFrequency::class,
        'is_active' => 'boolean',
        'is_paused' => 'boolean',
        'paused_until' => 'datetime',
        'last_alert_at' => 'datetime',
    ];

    /**
     * Default radius (PM-013).
     */
    public const DEFAULT_RADIUS_KM = 5;

    /**
     * Available radius options (PM-013).
     */
    public const RADIUS_OPTIONS = [2, 5, 10];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(FishAlert::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Active and not paused.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_paused', false)
                    ->orWhere('paused_until', '<', now());
            });
    }

    /**
     * Match subscriptions to a catch location and fish type.
     */
    public function scopeMatchingCatch(Builder $query, FishCatch $catch): Builder
    {
        return $query->active()
            // Within radius
            ->whereRaw(
                "ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?)) <= radius_km * 1000",
                [$catch->seller?->longitude ?? 0, $catch->seller?->latitude ?? 0]
            )
            // Fish type match
            ->where(function ($q) use ($catch) {
                $q->where('all_fish_types', true)
                    ->orWhereJsonContains('fish_type_ids', $catch->fish_type_id);
            });
    }

    /**
     * Immediate alert subscriptions only.
     */
    public function scopeForImmediateAlerts(Builder $query): Builder
    {
        return $query->active()
            ->where('alert_frequency', FishAlertFrequency::IMMEDIATE);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Check if currently active (not paused).
     */
    public function getIsActiveNowAttribute(): bool
    {
        if (!$this->is_active) return false;
        if (!$this->is_paused) return true;
        if ($this->paused_until && $this->paused_until->isPast()) return true;
        return false;
    }

    /**
     * Get location display.
     */
    public function getLocationDisplayAttribute(): string
    {
        return $this->location_label ?? 'Custom location';
    }

    /**
     * Get fish types display.
     */
    public function getFishTypesDisplayAttribute(): string
    {
        if ($this->all_fish_types || empty($this->fish_type_ids)) {
            return '🐟 All fish';
        }
        $count = count($this->fish_type_ids);
        return "🐟 {$count} types";
    }

    /**
     * Get detailed fish list.
     */
    public function getFishTypesListAttribute(): string
    {
        if ($this->all_fish_types || empty($this->fish_type_ids)) {
            return 'All fish types';
        }
        
        $fishTypes = FishType::whereIn('id', $this->fish_type_ids)->get();
        return $fishTypes->pluck('display_name')->join(', ');
    }

    /**
     * Get radius display.
     */
    public function getRadiusDisplayAttribute(): string
    {
        return "{$this->radius_km} km";
    }

    /**
     * Get frequency display.
     */
    public function getFrequencyDisplayAttribute(): string
    {
        return $this->alert_frequency?->label() ?? 'Immediate';
    }

    /**
     * Get status display.
     */
    public function getStatusDisplayAttribute(): string
    {
        if (!$this->is_active) return '❌ Inactive';
        if ($this->is_paused) {
            if ($this->paused_until) {
                return '⏸️ Paused until ' . $this->paused_until->format('M j');
            }
            return '⏸️ Paused';
        }
        return '🔔 Active';
    }

    /**
     * Get click rate percentage.
     */
    public function getClickRateAttribute(): float
    {
        if ($this->alerts_received === 0) return 0;
        return round(($this->alerts_clicked / $this->alerts_received) * 100, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if matches a catch.
     */
    public function matchesCatch(FishCatch $catch): bool
    {
        // Check fish type
        if (!$this->all_fish_types && $this->fish_type_ids) {
            if (!in_array($catch->fish_type_id, $this->fish_type_ids)) {
                return false;
            }
        }

        // Check distance
        $distance = $this->calculateDistanceTo(
            $catch->seller?->latitude ?? 0,
            $catch->seller?->longitude ?? 0
        );
        
        return $distance <= $this->radius_km;
    }

    /**
     * Calculate distance to a point (km).
     */
    public function calculateDistanceTo(float $lat, float $lng): float
    {
        $earthRadius = 6371;

        $dLat = deg2rad($lat - $this->latitude);
        $dLng = deg2rad($lng - $this->longitude);

        $a = sin($dLat / 2) ** 2 +
            cos(deg2rad($this->latitude)) * cos(deg2rad($lat)) *
            sin($dLng / 2) ** 2;

        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Pause subscription (PM-015).
     */
    public function pause(?\Carbon\Carbon $until = null): void
    {
        $this->update([
            'is_paused' => true,
            'paused_until' => $until,
        ]);
    }

    /**
     * Resume subscription (PM-015).
     */
    public function resume(): void
    {
        $this->update([
            'is_paused' => false,
            'paused_until' => null,
        ]);
    }

    /**
     * Add fish type (PM-015).
     */
    public function addFishType(int $fishTypeId): void
    {
        $ids = $this->fish_type_ids ?? [];
        if (!in_array($fishTypeId, $ids)) {
            $ids[] = $fishTypeId;
            $this->update([
                'fish_type_ids' => $ids,
                'all_fish_types' => false,
            ]);
        }
    }

    /**
     * Remove fish type (PM-015).
     */
    public function removeFishType(int $fishTypeId): void
    {
        $ids = $this->fish_type_ids ?? [];
        $ids = array_values(array_diff($ids, [$fishTypeId]));
        $this->update([
            'fish_type_ids' => empty($ids) ? null : $ids,
            'all_fish_types' => empty($ids),
        ]);
    }

    /**
     * Record alert received.
     */
    public function recordAlertReceived(): void
    {
        $this->increment('alerts_received');
        $this->update(['last_alert_at' => now()]);
    }

    /**
     * Record alert clicked.
     */
    public function recordAlertClicked(): void
    {
        $this->increment('alerts_clicked');
    }
}