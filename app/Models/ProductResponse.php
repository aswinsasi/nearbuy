<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class ProductResponse extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'request_id',
        'shop_id',
        'photo_url',
        'price',
        'description',
        'is_available',
        'responded_at',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'price' => 'decimal:2',
        'is_available' => 'boolean',
        'responded_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($response) {
            if (empty($response->responded_at)) {
                $response->responded_at = now();
            }
        });

        static::created(function ($response) {
            // Increment the response count on the request
            $response->request->incrementResponseCount();
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the product request this response belongs to.
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ProductRequest::class, 'request_id');
    }

    /**
     * Alias for request relationship.
     */
    public function productRequest(): BelongsTo
    {
        return $this->request();
    }

    /**
     * Get the shop that created this response.
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
     * Scope to filter available responses.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('is_available', true);
    }

    /**
     * Scope to filter responses with photos.
     */
    public function scopeWithPhoto(Builder $query): Builder
    {
        return $query->whereNotNull('photo_url');
    }

    /**
     * Scope to filter responses with price.
     */
    public function scopeWithPrice(Builder $query): Builder
    {
        return $query->whereNotNull('price');
    }

    /**
     * Scope to order by price ascending.
     */
    public function scopeLowestPriceFirst(Builder $query): Builder
    {
        return $query->orderBy('price', 'asc');
    }

    /**
     * Scope to order by newest first.
     */
    public function scopeNewest(Builder $query): Builder
    {
        return $query->orderBy('responded_at', 'desc');
    }

    /**
     * Scope to filter by request.
     */
    public function scopeForRequest(Builder $query, ProductRequest|int $request): Builder
    {
        $requestId = $request instanceof ProductRequest ? $request->id : $request;
        return $query->where('request_id', $requestId);
    }

    /**
     * Scope to filter by shop.
     */
    public function scopeFromShop(Builder $query, Shop|int $shop): Builder
    {
        $shopId = $shop instanceof Shop ? $shop->id : $shop;
        return $query->where('shop_id', $shopId);
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
        if ($this->price === null) {
            return null;
        }

        $currency = config('nearbuy.agreements.currency.symbol', '₹');
        return $currency . number_format($this->price, 2);
    }

    /**
     * Check if response has photo.
     */
    public function hasPhoto(): bool
    {
        return !empty($this->photo_url);
    }

    /**
     * Check if response has price.
     */
    public function hasPrice(): bool
    {
        return $this->price !== null;
    }

    /**
     * Get the distance from the customer (if request has location).
     */
    public function getDistanceFromCustomerAttribute(): ?float
    {
        $request = $this->request;
        $shop = $this->shop;

        if (!$request || !$shop) {
            return null;
        }

        return $shop->distanceFrom($request->latitude, $request->longitude);
    }

    /**
     * Get formatted distance from customer.
     */
    public function getFormattedDistanceAttribute(): ?string
    {
        $distance = $this->distance_from_customer;

        if ($distance === null) {
            return null;
        }

        if ($distance < 1) {
            return round($distance * 1000) . ' m';
        }

        return round($distance, 1) . ' km';
    }

    /**
     * Create a response from a shop.
     */
    public static function createFromShop(
        ProductRequest $request,
        Shop $shop,
        bool $isAvailable = true,
        ?float $price = null,
        ?string $description = null,
        ?string $photoUrl = null
    ): self {
        return self::create([
            'request_id' => $request->id,
            'shop_id' => $shop->id,
            'is_available' => $isAvailable,
            'price' => $price,
            'description' => $description,
            'photo_url' => $photoUrl,
        ]);
    }

    /**
     * Get responses for a request, formatted for customer display.
     */
    public static function getForCustomerDisplay(ProductRequest $request, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return self::query()
            ->forRequest($request)
            ->available()
            ->with('shop')
            ->withPrice()
            ->lowestPriceFirst()
            ->limit($limit)
            ->get();
    }
}