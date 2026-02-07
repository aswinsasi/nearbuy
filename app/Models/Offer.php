<?php

namespace App\Models;

use App\Enums\OfferValidity;
use App\Enums\ShopCategory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Offer Model.
 *
 * Represents a shop's promotional offer with image/PDF.
 *
 * @property int $id
 * @property int $shop_id
 * @property string $media_url
 * @property string $media_type (image|pdf)
 * @property string|null $caption
 * @property OfferValidity $validity_type
 * @property Carbon $expires_at
 * @property int $view_count (FR-OFR-06)
 * @property int $location_tap_count (FR-OFR-06)
 * @property bool $is_active
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read Shop $shop
 * @property-read float|null $distance_km (when using withDistance scope)
 *
 * @srs-ref FR-OFR-01 to FR-OFR-16
 */
class Offer extends Model
{
    use HasFactory;

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

    protected $casts = [
        'validity_type' => OfferValidity::class,
        'expires_at' => 'datetime',
        'view_count' => 'integer',
        'location_tap_count' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'view_count' => 0,
        'location_tap_count' => 0,
        'is_active' => true,
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
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope: active and not expired offers.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('expires_at', '>', now());
    }

    /**
     * Scope: expired offers.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope: not expired (regardless of is_active).
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope: from active shops only.
     */
    public function scopeFromActiveShops(Builder $query): Builder
    {
        return $query->whereHas('shop', fn(Builder $q) => $q->where('is_active', true));
    }

    /**
     * Scope: filter by shop category.
     *
     * @srs-ref FR-OFR-10 - Display category list with offer counts
     */
    public function scopeByCategory(Builder $query, ShopCategory|string $category): Builder
    {
        $value = $category instanceof ShopCategory ? $category->value : strtoupper($category);

        return $query->whereHas('shop', fn(Builder $q) => $q->where('category', $value));
    }

    /**
     * Scope: offers near a location within radius.
     *
     * Uses MySQL ST_Distance_Sphere for accurate distance calculation.
     *
     * @srs-ref FR-OFR-11 - Query within configurable radius using spatial queries
     */
    public function scopeNearTo(Builder $query, float $lat, float $lng, float $radiusKm = 5): Builder
    {
        return $query
            ->join('shops', 'offers.shop_id', '=', 'shops.id')
            ->whereRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) <= ?
            ', [$lng, $lat, $radiusKm])
            ->select('offers.*');
    }

    /**
     * Scope: add distance calculation to query.
     *
     * @srs-ref FR-OFR-12 - Sort by distance (nearest first)
     */
    public function scopeWithDistance(Builder $query, float $lat, float $lng): Builder
    {
        return $query
            ->join('shops', 'offers.shop_id', '=', 'shops.id')
            ->selectRaw('
                offers.*,
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$lng, $lat]);
    }

    /**
     * Scope: order by distance (nearest first).
     *
     * @srs-ref FR-OFR-12 - Sort by distance (nearest first)
     */
    public function scopeOrderByDistance(Builder $query, float $lat, float $lng): Builder
    {
        return $query->orderByRaw('
            ST_Distance_Sphere(
                POINT(shops.longitude, shops.latitude),
                POINT(?, ?)
            ) ASC
        ', [$lng, $lat]);
    }

    /**
     * Scope: filter by media type.
     */
    public function scopeOfType(Builder $query, string $mediaType): Builder
    {
        return $query->where('media_type', $mediaType);
    }

    /**
     * Scope: created today.
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', Carbon::today());
    }

    /*
    |--------------------------------------------------------------------------
    | Static Query Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Browse offers for customer - main query method.
     *
     * Combines: active, from active shops, near location, sorted by distance.
     *
     * @srs-ref FR-OFR-11, FR-OFR-12
     */
    public static function browse(
        float $lat,
        float $lng,
        float $radiusKm = 5,
        ?string $category = null,
        int $limit = 10
    ): \Illuminate\Database\Eloquent\Collection {
        $query = static::query()
            ->select('offers.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$lng, $lat])
            ->join('shops', 'offers.shop_id', '=', 'shops.id')
            ->where('offers.is_active', true)
            ->where('offers.expires_at', '>', now())
            ->where('shops.is_active', true)
            ->havingRaw('distance_km <= ?', [$radiusKm])
            ->orderBy('distance_km')
            ->limit($limit)
            ->with('shop');

        if ($category && $category !== 'all') {
            $query->where('shops.category', strtoupper($category));
        }

        return $query->get();
    }

    /**
     * Get offer counts by category near location.
     *
     * @srs-ref FR-OFR-10 - Display category list with offer counts per category
     */
    public static function countsByCategory(float $lat, float $lng, float $radiusKm = 5): array
    {
        $results = \Illuminate\Support\Facades\DB::table('offers')
            ->selectRaw('shops.category, COUNT(offers.id) as count')
            ->join('shops', 'offers.shop_id', '=', 'shops.id')
            ->where('offers.is_active', true)
            ->where('offers.expires_at', '>', now())
            ->where('shops.is_active', true)
            ->whereRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) <= ?
            ', [$lng, $lat, $radiusKm])
            ->groupBy('shops.category')
            ->get();

        $counts = [];
        $total = 0;

        foreach ($results as $row) {
            $key = strtolower($row->category);
            $counts[$key] = (int) $row->count;
            $total += $row->count;
        }

        $counts['all'] = $total;

        return $counts;
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check if offer is active and not expired.
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
     * Check if media is image.
     */
    public function isImage(): bool
    {
        return $this->media_type === 'image';
    }

    /**
     * Check if media is PDF.
     */
    public function isPdf(): bool
    {
        return $this->media_type === 'pdf';
    }

    /**
     * Get shop's category.
     */
    public function getCategory(): ?ShopCategory
    {
        return $this->shop?->category;
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

    /*
    |--------------------------------------------------------------------------
    | Metrics (FR-OFR-06)
    |--------------------------------------------------------------------------
    */

    /**
     * Increment view count.
     *
     * @srs-ref FR-OFR-06 - Track offer view counts
     */
    public function recordView(): void
    {
        $this->increment('view_count');
    }

    /**
     * Increment location tap count.
     *
     * @srs-ref FR-OFR-06 - Track location tap metrics
     */
    public function recordLocationTap(): void
    {
        $this->increment('location_tap_count');
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    /**
     * Deactivate the offer.
     */
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    /**
     * Extend validity.
     */
    public function extendValidity(OfferValidity $newValidity): void
    {
        $this->update([
            'validity_type' => $newValidity,
            'expires_at' => $newValidity->expiresAt(),
        ]);
    }

    /**
     * Create offer for shop (factory method).
     */
    public static function createForShop(
        Shop $shop,
        string $mediaUrl,
        string $mediaType,
        OfferValidity $validity,
        ?string $caption = null
    ): self {
        return static::create([
            'shop_id' => $shop->id,
            'media_url' => $mediaUrl,
            'media_type' => $mediaType,
            'caption' => $caption,
            'validity_type' => $validity,
            'expires_at' => $validity->expiresAt(),
        ]);
    }
}