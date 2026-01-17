<?php

namespace App\Models;

use App\Enums\OfferValidity;
use App\Enums\ShopCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class Offer extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'shop_id',
        'media_url',
        'media_type',
        'caption',
        'validity_type',
        'expires_at',
        'view_count',
        'location_tap_count',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'validity_type' => OfferValidity::class,
        'expires_at' => 'datetime',
        'view_count' => 'integer',
        'location_tap_count' => 'integer',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the shop that owns this offer.
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter active offers.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('expires_at', '>', now());
    }

    /**
     * Scope to filter expired offers.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope to filter by shop category.
     */
    public function scopeByCategory(Builder $query, ShopCategory|string $category): Builder
    {
        $categoryValue = $category instanceof ShopCategory ? $category->value : $category;

        return $query->whereHas('shop', function (Builder $q) use ($categoryValue) {
            $q->where('category', $categoryValue);
        });
    }

    /**
     * Scope to filter by multiple categories.
     */
    public function scopeByCategories(Builder $query, array $categories): Builder
    {
        $values = array_map(fn($cat) => $cat instanceof ShopCategory ? $cat->value : $cat, $categories);

        return $query->whereHas('shop', function (Builder $q) use ($values) {
            $q->whereIn('category', $values);
        });
    }

    /**
     * Scope to find offers near a location.
     * Joins with shops table to filter by location.
     */
    public function scopeNearLocation(Builder $query, float $latitude, float $longitude, float $radiusKm = 5): Builder
    {
        return $query->whereHas('shop', function (Builder $q) use ($latitude, $longitude, $radiusKm) {
            $q->nearLocation($latitude, $longitude, $radiusKm);
        });
    }

    /**
     * Scope to select with distance from a point.
     */
    public function scopeWithDistanceFrom(Builder $query, float $latitude, float $longitude): Builder
    {
        return $query
            ->join('shops', 'offers.shop_id', '=', 'shops.id')
            ->selectRaw(
                "offers.*, ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
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
        return $query
            ->join('shops', 'offers.shop_id', '=', 'shops.id')
            ->orderByRaw(
                "ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) {$direction}",
                [$longitude, $latitude]
            )
            ->select('offers.*');
    }

    /**
     * Scope to filter today's offers.
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', Carbon::today());
    }

    /**
     * Scope to filter by media type.
     */
    public function scopeOfMediaType(Builder $query, string $mediaType): Builder
    {
        return $query->where('media_type', $mediaType);
    }

    /**
     * Scope to only include offers from active shops.
     */
    public function scopeFromActiveShops(Builder $query): Builder
    {
        return $query->whereHas('shop', function (Builder $q) {
            $q->active();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check if offer is active.
     */
    public function isActive(): bool
    {
        return $this->is_active && $this->expires_at->isFuture();
    }

    /**
     * Check if offer is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if offer is an image.
     */
    public function isImage(): bool
    {
        return $this->media_type === 'image';
    }

    /**
     * Check if offer is a PDF.
     */
    public function isPdf(): bool
    {
        return $this->media_type === 'pdf';
    }

    /**
     * Get time remaining until expiry.
     */
    public function getTimeRemainingAttribute(): string
    {
        if ($this->isExpired()) {
            return 'Expired';
        }

        return $this->expires_at->diffForHumans(['parts' => 2]);
    }

    /**
     * Get the shop's category.
     */
    public function getCategoryAttribute(): ?ShopCategory
    {
        return $this->shop?->category;
    }

    /**
     * Increment view count.
     */
    public function incrementViews(): void
    {
        $this->increment('view_count');
    }

    /**
     * Increment location tap count.
     */
    public function incrementLocationTaps(): void
    {
        $this->increment('location_tap_count');
    }

    /**
     * Deactivate the offer.
     */
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Create a new offer for a shop.
     */
    public static function createForShop(
        Shop $shop,
        string $mediaUrl,
        string $mediaType,
        OfferValidity $validity,
        ?string $caption = null
    ): self {
        return self::create([
            'shop_id' => $shop->id,
            'media_url' => $mediaUrl,
            'media_type' => $mediaType,
            'caption' => $caption,
            'validity_type' => $validity,
            'expires_at' => $validity->expiresAt(),
            'is_active' => true,
        ]);
    }

    /**
     * Find offers for browsing by a customer.
     */
    public static function browseForCustomer(
        float $latitude,
        float $longitude,
        float $radiusKm = 5,
        ?ShopCategory $category = null,
        int $limit = 10
    ): \Illuminate\Database\Eloquent\Collection {
        $query = self::query()
            ->active()
            ->fromActiveShops()
            ->nearLocation($latitude, $longitude, $radiusKm)
            ->with('shop')
            ->latest();

        if ($category) {
            $query->byCategory($category);
        }

        return $query->limit($limit)->get();
    }
}