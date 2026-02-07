<?php

namespace App\Models;

use App\Enums\NotificationFrequency;
use App\Enums\ShopCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Shop model.
 *
 * @srs-ref Section 6.2.2 - shops Table
 * @srs-ref FR-SHOP-05 - Create linked records in users and shops tables
 *
 * @property int $id
 * @property int $user_id Owner reference (FK → users.id)
 * @property string $shop_name Business name
 * @property ShopCategory $category Shop category (8 options)
 * @property float $latitude Shop location
 * @property float $longitude Shop location
 * @property string|null $address Shop address
 * @property NotificationFrequency $notification_frequency Alert preference (default 2hours)
 * @property bool $verified Verification status (default false)
 * @property bool $is_active Shop active status
 */
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

    /**
     * Default attribute values.
     *
     * @srs-ref Section 6.2.2 - notification_frequency default 2hours, verified default false
     *
     * @var array
     */
    protected $attributes = [
        'notification_frequency' => '2hours',
        'verified' => false,
        'is_active' => true,
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the owner of this shop.
     *
     * @srs-ref Section 6.1 - Users (1) → Shops (1)
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
     *
     * @srs-ref Section 6.1 - Shops (1) → Offers (N)
     */
    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    /**
     * Get active offers from this shop.
     */
    public function activeOffers(): HasMany
    {
        return $this->offers()->where('is_active', true);
    }

    /**
     * Get product responses from this shop.
     *
     * @srs-ref Section 6.1 - Shops respond to Product Requests
     */
    public function productResponses(): HasMany
    {
        return $this->hasMany(ProductResponse::class);
    }

    /**
     * Alias for productResponses.
     */
    public function responses(): HasMany
    {
        return $this->productResponses();
    }

    /**
     * Get notification batches for this shop.
     */
    public function notificationBatches(): HasMany
    {
        return $this->hasMany(NotificationBatch::class);
    }

    // NOTE: flashDeals() relationship will be added when FlashDeal model is created
    // @srs-ref Flash Mob Deals Module (FD-001 to FD-028)

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
     *
     * @srs-ref FR-OFR-10 - Display category list
     */
    public function scopeByCategory(Builder $query, ShopCategory|string $category): Builder
    {
        $categoryValue = $category instanceof ShopCategory ? $category->value : $category;
        return $query->where('category', $categoryValue);
    }

    /**
     * Scope to filter by multiple categories.
     */
    public function scopeByCategories(Builder $query, array $categories): Builder
    {
        $values = array_map(
            fn($cat) => $cat instanceof ShopCategory ? $cat->value : $cat,
            $categories
        );
        return $query->whereIn('category', $values);
    }

    /**
     * Scope to find shops near a location.
     * Uses MySQL ST_Distance_Sphere for accurate distance calculation.
     *
     * @srs-ref FR-OFR-11 - Query offers within configurable radius (default 5km)
     */
    public function scopeNearTo(Builder $query, float $latitude, float $longitude, float $radiusKm = 5): Builder
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
     *
     * @srs-ref FR-OFR-12 - Sort results by distance (nearest first)
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
     *
     * @srs-ref FR-PRD-11 - Send immediate notifications for shops with immediate preference
     */
    public function scopeImmediateNotification(Builder $query): Builder
    {
        return $query->where('notification_frequency', NotificationFrequency::IMMEDIATE);
    }

    /**
     * Scope to get shops needing batched notification.
     *
     * @srs-ref FR-PRD-12 - Batch requests for shops with 2-hour preference
     */
    public function scopeBatchedNotification(Builder $query): Builder
    {
        return $query->where('notification_frequency', '!=', NotificationFrequency::IMMEDIATE);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get active offers count.
     */
    public function getActiveOfferCountAttribute(): int
    {
        return $this->activeOffers()->count();
    }

    /**
     * Alias for activeOfferCount (backward compatibility).
     */
    public function getActiveOffersCountAttribute(): int
    {
        return $this->active_offer_count;
    }

    /**
     * Get the owner's phone number.
     */
    public function getPhoneAttribute(): ?string
    {
        return $this->user?->phone;
    }

    /**
     * Get the owner's name.
     */
    public function getOwnerNameAttribute(): ?string
    {
        return $this->user?->name;
    }

    /**
     * Get category display with icon.
     */
    public function getCategoryDisplayAttribute(): string
    {
        return $this->category?->displayWithIcon() ?? 'Unknown';
    }

    /**
     * Get notification frequency display.
     */
    public function getNotificationDisplayAttribute(): string
    {
        return $this->notification_frequency?->displayWithIcon() ?? 'Unknown';
    }

    /*
    |--------------------------------------------------------------------------
    | Permission & Status Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if shop has reached maximum offers.
     */
    public function hasReachedMaxOffers(): bool
    {
        $max = config('nearbuy.offers.max_active_per_shop', 5);
        return $this->active_offer_count >= $max;
    }

    /**
     * Check if shop can receive immediate notifications.
     */
    public function canReceiveImmediateNotification(): bool
    {
        return $this->notification_frequency === NotificationFrequency::IMMEDIATE;
    }

    /**
     * Check if shop is verified.
     */
    public function isVerified(): bool
    {
        return $this->verified === true;
    }

    /**
     * Check if shop is active.
     */
    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    /*
    |--------------------------------------------------------------------------
    | Distance Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate distance from a given point in km.
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

    /*
    |--------------------------------------------------------------------------
    | Static Query Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Find shops for a product request.
     *
     * @srs-ref FR-PRD-05 - Identify eligible shops by category and proximity
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
            ->nearTo($latitude, $longitude, $radiusKm)
            ->withDistanceFrom($latitude, $longitude)
            ->orderByDistance($latitude, $longitude);

        if ($category) {
            $query->byCategory($category);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Find shops with active offers by category.
     *
     * @srs-ref FR-OFR-10 - Display category list with offer counts
     */
    public static function findWithOffersByCategory(
        float $latitude,
        float $longitude,
        float $radiusKm,
        ShopCategory $category
    ): \Illuminate\Database\Eloquent\Collection {
        return self::query()
            ->active()
            ->byCategory($category)
            ->nearTo($latitude, $longitude, $radiusKm)
            ->whereHas('offers', fn($q) => $q->where('is_active', true))
            ->withDistanceFrom($latitude, $longitude)
            ->orderByDistance($latitude, $longitude)
            ->get();
    }
}