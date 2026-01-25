<?php

namespace App\Models;

use App\Enums\FishSellerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fish Seller Model - Fish seller profiles.
 *
 * @property int $id
 * @property int $user_id
 * @property string $business_name
 * @property FishSellerType $seller_type
 * @property float $latitude
 * @property float $longitude
 * @property string|null $address
 * @property string|null $market_name
 * @property string|null $landmark
 * @property string|null $alternate_phone
 * @property string|null $upi_id
 * @property array|null $operating_hours
 * @property array|null $catch_days
 * @property int $total_catches
 * @property int $total_sales
 * @property float $average_rating
 * @property int $rating_count
 * @property bool $is_verified
 * @property \Carbon\Carbon|null $verified_at
 * @property string|null $verification_doc_url
 * @property bool $is_active
 * @property bool $is_accepting_orders
 * @property int $default_alert_radius_km
 *
 * @srs-ref Section 5.1.2 - fish_sellers table
 */
class FishSeller extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'user_id',
        'business_name',
        'seller_type',
        'latitude',
        'longitude',
        'address',
        'market_name',
        'landmark',
        'alternate_phone',
        'upi_id',
        'operating_hours',
        'catch_days',
        'total_catches',
        'total_sales',
        'average_rating',
        'rating_count',
        'is_verified',
        'verified_at',
        'verification_doc_url',
        'is_active',
        'is_accepting_orders',
        'default_alert_radius_km',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'seller_type' => FishSellerType::class,
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'operating_hours' => 'array',
        'catch_days' => 'array',
        'average_rating' => 'decimal:2',
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
        'is_active' => 'boolean',
        'is_accepting_orders' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the user who owns this seller profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alias for user relationship (owner).
     */
    public function owner(): BelongsTo
    {
        return $this->user();
    }

    /**
     * Get all catches posted by this seller.
     */
    public function catches(): HasMany
    {
        return $this->hasMany(FishCatch::class);
    }

    /**
     * Get active catches.
     */
    public function activeCatches(): HasMany
    {
        return $this->catches()
            ->where('status', 'available')
            ->where('expires_at', '>', now());
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter active sellers.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter verified sellers.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('is_verified', true);
    }

    /**
     * Scope to filter by seller type.
     */
    public function scopeOfType(Builder $query, FishSellerType $type): Builder
    {
        return $query->where('seller_type', $type);
    }

    /**
     * Scope to find sellers near a location.
     */
    public function scopeNearLocation(Builder $query, float $latitude, float $longitude, float $radiusKm = 5): Builder
    {
        return $query
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereRaw(
                "ST_Distance_Sphere(
                    POINT(longitude, latitude),
                    POINT(?, ?)
                ) <= ?",
                [$longitude, $latitude, $radiusKm * 1000]
            );
    }

    /**
     * Scope to select with distance from a point.
     */
    public function scopeWithDistanceFrom(Builder $query, float $latitude, float $longitude): Builder
    {
        return $query->selectRaw(
            "*, ST_Distance_Sphere(
                POINT(longitude, latitude),
                POINT(?, ?)
            ) / 1000 as distance_km",
            [$longitude, $latitude]
        );
    }

    /**
     * Scope to filter sellers with active catches.
     */
    public function scopeWithActiveCatches(Builder $query): Builder
    {
        return $query->whereHas('catches', function ($q) {
            $q->where('status', 'available')
                ->where('expires_at', '>', now());
        });
    }

    /**
     * Scope by rating.
     */
    public function scopeMinRating(Builder $query, float $minRating): Builder
    {
        return $query->where('average_rating', '>=', $minRating);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get display name with type.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->seller_type->emoji() . ' ' . $this->business_name;
    }

    /**
     * Get seller type display.
     */
    public function getSellerTypeDisplayAttribute(): string
    {
        return $this->seller_type->label();
    }

    /**
     * Get formatted phone.
     */
    public function getFormattedPhoneAttribute(): string
    {
        return $this->user?->formatted_phone ?? '';
    }

    /**
     * Get rating display with stars.
     */
    public function getRatingDisplayAttribute(): string
    {
        if ($this->rating_count === 0) {
            return 'New Seller';
        }

        $stars = str_repeat('⭐', (int) round($this->average_rating));
        return $stars . ' ' . number_format($this->average_rating, 1) .
            ' (' . $this->rating_count . ' ratings)';
    }

    /**
     * Get short rating display.
     */
    public function getShortRatingAttribute(): string
    {
        if ($this->rating_count === 0) {
            return 'New';
        }

        return '⭐ ' . number_format($this->average_rating, 1) .
            ' (' . $this->rating_count . ')';
    }

    /**
     * Get location display.
     */
    public function getLocationDisplayAttribute(): string
    {
        $parts = [];

        if ($this->market_name) {
            $parts[] = $this->market_name;
        }

        if ($this->landmark) {
            $parts[] = $this->landmark;
        }

        if ($this->address && count($parts) === 0) {
            $parts[] = $this->address;
        }

        return implode(', ', $parts) ?: 'Location available';
    }

    /**
     * Check if seller is currently open.
     */
    public function getIsOpenNowAttribute(): bool
    {
        if (!$this->operating_hours) {
            return true; // Assume always open if no hours set
        }

        $dayKey = strtolower(now()->format('D')); // mon, tue, etc.
        $hours = $this->operating_hours[$dayKey] ?? null;

        if (!$hours) {
            return false;
        }

        $now = now()->format('H:i');
        return $now >= ($hours['open'] ?? '00:00') &&
            $now <= ($hours['close'] ?? '23:59');
    }

    /**
     * Check if today is a catch day.
     */
    public function getIsCatchDayAttribute(): bool
    {
        if (!$this->catch_days) {
            return true; // Assume every day if not set
        }

        $todayNum = (int) now()->format('w'); // 0 = Sunday
        return in_array($todayNum, $this->catch_days);
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Increment total catches count.
     */
    public function incrementCatches(): void
    {
        $this->increment('total_catches');
    }

    /**
     * Increment total sales count.
     */
    public function incrementSales(): void
    {
        $this->increment('total_sales');
    }

    /**
     * Update average rating.
     */
    public function updateRating(int $newRating): void
    {
        $totalRating = ($this->average_rating * $this->rating_count) + $newRating;
        $newCount = $this->rating_count + 1;

        $this->update([
            'average_rating' => $totalRating / $newCount,
            'rating_count' => $newCount,
        ]);
    }

    /**
     * Mark as verified.
     */
    public function verify(): void
    {
        $this->update([
            'is_verified' => true,
            'verified_at' => now(),
        ]);
    }

    /**
     * Get active catch count.
     */
    public function getActiveCatchCount(): int
    {
        return $this->catches()
            ->where('status', 'available')
            ->where('expires_at', '>', now())
            ->count();
    }

    /**
     * Convert to alert message format.
     */
    public function toAlertFormat(): array
    {
        return [
            'name' => $this->business_name,
            'type' => $this->seller_type->label(),
            'location' => $this->location_display,
            'rating' => $this->short_rating,
            'phone' => $this->user?->phone,
        ];
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        return [
            'id' => 'seller_' . $this->id,
            'title' => substr($this->display_name, 0, 24),
            'description' => substr($this->location_display . ' • ' . $this->short_rating, 0, 72),
        ];
    }
}
