<?php

namespace App\Models;

use App\Enums\ShopCategory;
use App\Enums\NotificationFrequency;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;

class Shop extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'shop_name',
        'category',
        'latitude',
        'longitude',
        'address',
        'notification_frequency',
        'verified',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'category' => ShopCategory::class,
        'notification_frequency' => NotificationFrequency::class,
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'verified' => 'boolean',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the owner of this shop.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alias for user relationship.
     */
    public function owner(): BelongsTo
    {
        return $this->user();
    }

    /**
     * Get offers from this shop.
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * Get product responses from this shop.
     */
    public function productResponses(): HasMany
    {
        return $this->hasMany(ProductResponse::class);
    }

    /**
     * Get notification batches for this shop.
     */
    public function notificationBatches(): HasMany
    {
        return $this->hasMany(NotificationBatch::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter active shops.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter verified shops.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verified', true);
    }

    /**
     * Scope to filter by category.
     */
    public function scopeOfCategory(Builder $query, ShopCategory|string $category): Builder
    {
        $categoryValue = $category instanceof ShopCategory ? $category->value : $category;
        return $query->where('category', $categoryValue);
    }

    /**
     * Scope to filter by multiple categories.
     */
    public function scopeOfCategories(Builder $query, array $categories): Builder
    {
        $values = array_map(fn($cat) => $cat instanceof ShopCategory ? $cat->value : $cat, $categories);
        return $query->whereIn('category', $values);
    }

    /**
     * Scope to find shops within a radius (km) of a location.
     * Uses MySQL ST_Distance_Sphere for accurate distance calculation.
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
     * Scope to select distance from a point.
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
     * Scope to order by distance from a point.
     */
    public function scopeOrderByDistance(Builder $query, float $latitude, float $longitude, string $direction = 'asc'): Builder
    {
        return $query->orderByRaw(
            "ST_Distance_Sphere(
                POINT(longitude, latitude),
                POINT(?, ?)
            ) {$direction}",
            [$longitude, $latitude]
        );
    }

    /**
     * Scope to filter by notification frequency.
     */
    public function scopeWithNotificationFrequency(Builder $query, NotificationFrequency $frequency): Builder
    {
        return $query->where('notification_frequency', $frequency);
    }

    /**
     * Scope to get shops that need immediate notification.
     */
    public function scopeImmediateNotification(Builder $query): Builder
    {
        return $query->where('notification_frequency', NotificationFrequency::IMMEDIATE);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get active offers count.
     */
    public function getActiveOffersCountAttribute(): int
    {
        return $this->offers()->active()->count();
    }

    /**
     * Check if shop has reached maximum offers.
     */
    public function hasReachedMaxOffers(): bool
    {
        $max = config('nearbuy.offers.max_active_per_shop', 5);
        return $this->active_offers_count >= $max;
    }

    /**
     * Check if shop can receive notifications now.
     */
    public function canReceiveImmediateNotification(): bool
    {
        return $this->notification_frequency === NotificationFrequency::IMMEDIATE;
    }

    /**
     * Get the owner's phone number.
     */
    public function getPhoneAttribute(): ?string
    {
        return $this->user?->phone;
    }

    /**
     * Calculate distance from a given point.
     */
    public function distanceFrom(float $latitude, float $longitude): float
    {
        $earthRadiusKm = 6371;

        $latDiff = deg2rad($latitude - $this->latitude);
        $lonDiff = deg2rad($longitude - $this->longitude);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($this->latitude)) * cos(deg2rad($latitude)) *
            sin($lonDiff / 2) * sin($lonDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Get formatted distance string.
     */
    public function getFormattedDistanceFrom(float $latitude, float $longitude): string
    {
        $distance = $this->distanceFrom($latitude, $longitude);

        if ($distance < 1) {
            return round($distance * 1000) . ' m';
        }

        return round($distance, 1) . ' km';
    }

    /**
     * Find nearby shops for a product request.
     */
    public static function findForProductRequest(
        float $latitude,
        float $longitude,
        float $radiusKm,
        ?ShopCategory $category = null,
        int $limit = 20
    ): \Illuminate\Database\Eloquent\Collection {
        $query = self::query()
            ->active()
            ->nearLocation($latitude, $longitude, $radiusKm)
            ->withDistanceFrom($latitude, $longitude)
            ->orderByDistance($latitude, $longitude);

        if ($category) {
            $query->ofCategory($category);
        }

        return $query->limit($limit)->get();
    }
}