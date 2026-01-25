<?php

namespace App\Models;

use App\Enums\FishAlertFrequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fish Subscription Model - Customer alert subscriptions.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $name
 * @property float $latitude
 * @property float $longitude
 * @property string|null $address
 * @property string|null $location_label
 * @property int $radius_km
 * @property array|null $fish_type_ids
 * @property bool $all_fish_types
 * @property array|null $preferred_seller_ids
 * @property array|null $blocked_seller_ids
 * @property FishAlertFrequency $alert_frequency
 * @property string|null $quiet_hours_start
 * @property string|null $quiet_hours_end
 * @property array|null $active_days
 * @property bool $is_active
 * @property bool $is_paused
 * @property \Carbon\Carbon|null $paused_until
 * @property int $alerts_received
 * @property int $alerts_clicked
 * @property \Carbon\Carbon|null $last_alert_at
 *
 * @srs-ref Section 2.3.3 - Customer Subscription
 */
class FishSubscription extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'name',
        'latitude',
        'longitude',
        'address',
        'location_label',
        'radius_km',
        'fish_type_ids',
        'all_fish_types',
        'preferred_seller_ids',
        'blocked_seller_ids',
        'alert_frequency',
        'quiet_hours_start',
        'quiet_hours_end',
        'active_days',
        'is_active',
        'is_paused',
        'paused_until',
        'alerts_received',
        'alerts_clicked',
        'last_alert_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'fish_type_ids' => 'array',
        'all_fish_types' => 'boolean',
        'preferred_seller_ids' => 'array',
        'blocked_seller_ids' => 'array',
        'alert_frequency' => FishAlertFrequency::class,
        'quiet_hours_start' => 'string',
        'quiet_hours_end' => 'string',
        'active_days' => 'array',
        'is_active' => 'boolean',
        'is_paused' => 'boolean',
        'paused_until' => 'datetime',
        'last_alert_at' => 'datetime',
    ];

    /**
     * Default radius in km.
     */
    public const DEFAULT_RADIUS_KM = 5;

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the user who owns this subscription.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get alerts received for this subscription.
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(FishAlert::class);
    }

    /**
     * Get alert batches for this subscription.
     */
    public function batches(): HasMany
    {
        return $this->hasMany(FishAlertBatch::class);
    }

    /**
     * Get preferred fish types.
     */
    public function preferredFishTypes()
    {
        if ($this->all_fish_types || empty($this->fish_type_ids)) {
            return FishType::active()->get();
        }

        return FishType::whereIn('id', $this->fish_type_ids)->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter active subscriptions.
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
     * Scope to filter by alert frequency.
     */
    public function scopeOfFrequency(Builder $query, FishAlertFrequency $frequency): Builder
    {
        return $query->where('alert_frequency', $frequency);
    }

    /**
     * Scope for immediate alerts.
     */
    public function scopeForImmediateAlerts(Builder $query): Builder
    {
        return $query->active()
            ->where('alert_frequency', FishAlertFrequency::IMMEDIATE);
    }

    /**
     * Scope for batched alerts.
     */
    public function scopeForBatchedAlerts(Builder $query): Builder
    {
        return $query->active()
            ->whereIn('alert_frequency', [
                FishAlertFrequency::MORNING_ONLY,
                FishAlertFrequency::TWICE_DAILY,
                FishAlertFrequency::WEEKLY_DIGEST,
            ]);
    }

    /**
     * Scope to find subscriptions that match a catch.
     */
    public function scopeMatchingCatch(Builder $query, FishCatch $catch): Builder
    {
        $catchLat = $catch->catch_latitude;
        $catchLng = $catch->catch_longitude;

        return $query->active()
            // Match by location (catch within subscription radius)
            ->whereRaw(
                "ST_Distance_Sphere(
                    POINT(longitude, latitude),
                    POINT(?, ?)
                ) <= radius_km * 1000",
                [$catchLng, $catchLat]
            )
            // Match by fish type (all types or specific type)
            ->where(function ($q) use ($catch) {
                $q->where('all_fish_types', true)
                    ->orWhereJsonContains('fish_type_ids', $catch->fish_type_id);
            })
            // Exclude blocked sellers
            ->where(function ($q) use ($catch) {
                $q->whereNull('blocked_seller_ids')
                    ->orWhereRaw(
                        'NOT JSON_CONTAINS(blocked_seller_ids, ?)',
                        [json_encode($catch->fish_seller_id)]
                    );
            });
    }

    /**
     * Scope to filter subscriptions currently in quiet hours.
     */
    public function scopeNotInQuietHours(Builder $query): Builder
    {
        $currentTime = now()->format('H:i');

        return $query->where(function ($q) use ($currentTime) {
            $q->whereNull('quiet_hours_start')
                ->orWhereNull('quiet_hours_end')
                ->orWhere(function ($inner) use ($currentTime) {
                    $inner->whereRaw('? NOT BETWEEN quiet_hours_start AND quiet_hours_end', [$currentTime]);
                });
        });
    }

    /**
     * Scope to filter subscriptions active on current day.
     */
    public function scopeActiveToday(Builder $query): Builder
    {
        $todayNum = (int) now()->format('w');

        return $query->where(function ($q) use ($todayNum) {
            $q->whereNull('active_days')
                ->orWhereJsonContains('active_days', $todayNum);
        });
    }

    /**
     * Scope for subscriptions eligible to receive alerts now.
     */
    public function scopeCanReceiveAlerts(Builder $query): Builder
    {
        return $query->active()
            ->notInQuietHours()
            ->activeToday();
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get display name.
     */
    public function getDisplayNameAttribute(): string
    {
        if ($this->name) {
            return $this->name;
        }

        if ($this->location_label) {
            return $this->location_label;
        }

        return 'Fish Alerts';
    }

    /**
     * Get location display.
     */
    public function getLocationDisplayAttribute(): string
    {
        if ($this->location_label) {
            return $this->location_label;
        }

        if ($this->address) {
            return $this->address;
        }

        return 'Custom location';
    }

    /**
     * Get fish types display.
     */
    public function getFishTypesDisplayAttribute(): string
    {
        if ($this->all_fish_types) {
            return 'All fish types';
        }

        if (empty($this->fish_type_ids)) {
            return 'All fish types';
        }

        $count = count($this->fish_type_ids);
        return $count . ' selected fish type' . ($count > 1 ? 's' : '');
    }

    /**
     * Get radius display.
     */
    public function getRadiusDisplayAttribute(): string
    {
        return $this->radius_km . ' km radius';
    }

    /**
     * Get frequency display.
     */
    public function getFrequencyDisplayAttribute(): string
    {
        return $this->alert_frequency->emoji() . ' ' . $this->alert_frequency->label();
    }

    /**
     * Get status display.
     */
    public function getStatusDisplayAttribute(): string
    {
        if (!$this->is_active) {
            return '❌ Inactive';
        }

        if ($this->is_paused) {
            if ($this->paused_until) {
                return '⏸️ Paused until ' . $this->paused_until->format('M j');
            }
            return '⏸️ Paused';
        }

        return '✅ Active';
    }

    /**
     * Check if subscription can receive alerts now.
     */
    public function getCanReceiveAlertsNowAttribute(): bool
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->is_paused && (!$this->paused_until || $this->paused_until > now())) {
            return false;
        }

        // Check quiet hours
        if ($this->quiet_hours_start && $this->quiet_hours_end) {
            $currentTime = now()->format('H:i');
            if ($currentTime >= $this->quiet_hours_start && $currentTime <= $this->quiet_hours_end) {
                return false;
            }
        }

        // Check active days
        if ($this->active_days) {
            $todayNum = (int) now()->format('w');
            if (!in_array($todayNum, $this->active_days)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get click rate.
     */
    public function getClickRateAttribute(): float
    {
        if ($this->alerts_received === 0) {
            return 0;
        }

        return round(($this->alerts_clicked / $this->alerts_received) * 100, 1);
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if subscription matches a catch.
     */
    public function matchesCatch(FishCatch $catch): bool
    {
        // Check fish type
        if (!$this->all_fish_types && $this->fish_type_ids) {
            if (!in_array($catch->fish_type_id, $this->fish_type_ids)) {
                return false;
            }
        }

        // Check blocked sellers
        if ($this->blocked_seller_ids && in_array($catch->fish_seller_id, $this->blocked_seller_ids)) {
            return false;
        }

        // Check distance
        $distance = $this->calculateDistanceTo($catch->catch_latitude, $catch->catch_longitude);
        if ($distance > $this->radius_km) {
            return false;
        }

        return true;
    }

    /**
     * Calculate distance to a point.
     */
    public function calculateDistanceTo(float $lat, float $lng): float
    {
        $earthRadius = 6371; // km

        $latDiff = deg2rad($lat - $this->latitude);
        $lngDiff = deg2rad($lng - $this->longitude);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($this->latitude)) * cos(deg2rad($lat)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Pause subscription.
     */
    public function pause(\Carbon\Carbon $until = null): void
    {
        $this->update([
            'is_paused' => true,
            'paused_until' => $until,
        ]);
    }

    /**
     * Resume subscription.
     */
    public function resume(): void
    {
        $this->update([
            'is_paused' => false,
            'paused_until' => null,
        ]);
    }

    /**
     * Deactivate subscription.
     */
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Activate subscription.
     */
    public function activate(): void
    {
        $this->update([
            'is_active' => true,
            'is_paused' => false,
            'paused_until' => null,
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

    /**
     * Add fish type to subscription.
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
     * Remove fish type from subscription.
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
     * Block a seller.
     */
    public function blockSeller(int $sellerId): void
    {
        $blocked = $this->blocked_seller_ids ?? [];

        if (!in_array($sellerId, $blocked)) {
            $blocked[] = $sellerId;
            $this->update(['blocked_seller_ids' => $blocked]);
        }
    }

    /**
     * Unblock a seller.
     */
    public function unblockSeller(int $sellerId): void
    {
        $blocked = $this->blocked_seller_ids ?? [];
        $blocked = array_values(array_diff($blocked, [$sellerId]));

        $this->update(['blocked_seller_ids' => empty($blocked) ? null : $blocked]);
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        return [
            'id' => 'sub_' . $this->id,
            'title' => substr($this->display_name, 0, 24),
            'description' => substr($this->status_display . ' • ' . $this->radius_display, 0, 72),
        ];
    }
}
