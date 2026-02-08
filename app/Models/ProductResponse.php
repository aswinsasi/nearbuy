<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Product Response Model.
 *
 * Shop's response to a customer product request.
 *
 * @property int $id
 * @property int $request_id
 * @property int $shop_id
 * @property bool $is_available
 * @property float|null $price (FR-PRD-21)
 * @property string|null $description (FR-PRD-21: model info)
 * @property string|null $photo_url (FR-PRD-20, FR-PRD-22)
 * @property \Carbon\Carbon $responded_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read ProductRequest $request
 * @property-read Shop $shop
 * @property-read float|null $distance_km (computed)
 *
 * @srs-ref FR-PRD-20 to FR-PRD-23
 */
class ProductResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'shop_id',
        'is_available',
        'price',
        'description',
        'photo_url',
        'responded_at',
    ];

    protected $casts = [
        'is_available' => 'boolean',
        'price' => 'decimal:2',
        'responded_at' => 'datetime',
    ];

    /**
     * Boot: set responded_at, increment request count.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $response) {
            if (empty($response->responded_at)) {
                $response->responded_at = now();
            }
        });

        static::created(function (self $response) {
            // Increment response count on request
            $response->request?->incrementResponses();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the product request.
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class, 'request_id');
    }

    /**
     * Alias for request.
     */
    public function productRequest(): BelongsTo
    {
        return $this->request();
    }

    /**
     * Get the shop.
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
     * Scope: available responses only.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /**
     * Scope: with photo.
     */
    public function scopeWithPhoto(Builder $query): Builder
    {
        return $query->whereNotNull('photo_url');
    }

    /**
     * Scope: with price.
     */
    public function scopeWithPrice(Builder $query): Builder
    {
        return $query->whereNotNull('price');
    }

    /**
     * Scope: sorted by price (lowest first).
     *
     * @srs-ref FR-PRD-31 - Sort responses by price
     */
    public function scopeLowestFirst(Builder $query): Builder
    {
        return $query->orderBy('price', 'asc');
    }

    /**
     * Scope: newest first.
     */
    public function scopeNewest(Builder $query): Builder
    {
        return $query->orderByDesc('responded_at');
    }

    /**
     * Scope: for a specific request.
     */
    public function scopeForRequest(Builder $query, ProductRequest|int $request): Builder
    {
        $id = $request instanceof ProductRequest ? $request->id : $request;
        return $query->where('request_id', $id);
    }

    /**
     * Scope: from a specific shop.
     */
    public function scopeFromShop(Builder $query, Shop|int $shop): Builder
    {
        $id = $shop instanceof Shop ? $shop->id : $shop;
        return $query->where('shop_id', $id);
    }

    /*
    |--------------------------------------------------------------------------
    | Static Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if shop already responded to request.
     *
     * @srs-ref FR-PRD-23 - Prevent duplicate responses
     */
    public static function existsForShop(ProductRequest|int $request, Shop|int $shop): bool
    {
        $requestId = $request instanceof ProductRequest ? $request->id : $request;
        $shopId = $shop instanceof Shop ? $shop->id : $shop;

        return self::query()
            ->where('request_id', $requestId)
            ->where('shop_id', $shopId)
            ->exists();
    }

    /**
     * Create available response.
     *
     * @srs-ref FR-PRD-22 - Store response with photo URL, price, description
     */
    public static function createAvailable(
        ProductRequest $request,
        Shop $shop,
        float $price,
        ?string $description = null,
        ?string $photoUrl = null
    ): self {
        return self::create([
            'request_id' => $request->id,
            'shop_id' => $shop->id,
            'is_available' => true,
            'price' => $price,
            'description' => $description,
            'photo_url' => $photoUrl,
        ]);
    }

    /**
     * Create unavailable response.
     */
    public static function createUnavailable(ProductRequest $request, Shop $shop): self
    {
        return self::create([
            'request_id' => $request->id,
            'shop_id' => $shop->id,
            'is_available' => false,
            'price' => null,
            'description' => null,
            'photo_url' => null,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get formatted price.
     */
    public function getFormattedPriceAttribute(): ?string
    {
        if ($this->price === null) return null;
        return '₹' . number_format((float) $this->price);
    }

    /**
     * Check if has photo.
     */
    public function hasPhoto(): bool
    {
        return !empty($this->photo_url);
    }

    /**
     * Check if has price.
     */
    public function hasPrice(): bool
    {
        return $this->price !== null;
    }

    /**
     * Get distance from customer (if computed).
     */
    public function getDistanceKmAttribute(): ?float
    {
        return $this->attributes['distance_km'] ?? null;
    }
}