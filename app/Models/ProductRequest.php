<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Enums\ShopCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class ProductRequest extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'request_number',
        'category',
        'description',
        'image_url',
        'latitude',
        'longitude',
        'radius_km',
        'status',
        'expires_at',
        'shops_notified',
        'response_count',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'category' => ShopCategory::class,
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'radius_km' => 'integer',
        'status' => RequestStatus::class,
        'expires_at' => 'datetime',
        'shops_notified' => 'integer',
        'response_count' => 'integer',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($request) {
            if (empty($request->request_number)) {
                $request->request_number = self::generateRequestNumber();
            }

            if (empty($request->expires_at)) {
                $request->expires_at = now()->addHours(
                    config('nearbuy.product_search.request_expiry_hours', 48)
                );
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the user who created this request.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Alias for user relationship.
     */
    public function customer(): BelongsTo
    {
        return $this->user();
    }

    /**
     * Get responses to this request.
     */
    public function responses(): HasMany
    {
        return $this->hasMany(ProductResponse::class, 'request_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter open requests.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::OPEN);
    }

    /**
     * Scope to filter active requests (open or collecting).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [RequestStatus::OPEN, RequestStatus::COLLECTING]);
    }

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus(Builder $query, RequestStatus $status): Builder
    {
        return $query->where('status', $status);
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
     * Scope to filter requests not yet expired.
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope to filter expired requests.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope to find requests near a shop's location.
     * This is used to find requests a shop might be able to respond to.
     */
    public function scopeNearShop(Builder $query, Shop $shop): Builder
    {
        // Find requests where the shop falls within the request's radius
        return $query->whereRaw(
            "ST_Distance_Sphere(
                POINT(longitude, latitude),
                POINT(?, ?)
            ) <= radius_km * 1000",
            [$shop->longitude, $shop->latitude]
        );
    }

    /**
     * Scope to find requests matching a shop's category.
     */
    public function scopeMatchingShop(Builder $query, Shop $shop): Builder
    {
        return $query
            ->nearShop($shop)
            ->where(function (Builder $q) use ($shop) {
                $q->whereNull('category')
                    ->orWhere('category', $shop->category);
            });
    }

    /**
     * Scope to filter by user.
     */
    public function scopeByUser(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;
        return $query->where('user_id', $userId);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check if request is open for responses.
     */
    public function isOpen(): bool
    {
        return $this->status->acceptsResponses() && $this->expires_at->isFuture();
    }

    /**
     * Check if request is expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if a shop has already responded.
     */
    public function hasResponseFrom(Shop $shop): bool
    {
        return $this->responses()->where('shop_id', $shop->id)->exists();
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
     * Mark request as collecting responses.
     */
    public function markAsCollecting(): void
    {
        if ($this->status === RequestStatus::OPEN) {
            $this->update(['status' => RequestStatus::COLLECTING]);
        }
    }

    /**
     * Close the request.
     */
    public function close(): void
    {
        if ($this->status->acceptsResponses()) {
            $this->update(['status' => RequestStatus::CLOSED]);
        }
    }

    /**
     * Mark as expired.
     */
    public function markAsExpired(): void
    {
        $this->update(['status' => RequestStatus::EXPIRED]);
    }

    /**
     * Increment response count.
     */
    public function incrementResponseCount(): void
    {
        $this->increment('response_count');
    }

    /**
     * Update shops notified count.
     */
    public function updateShopsNotified(int $count): void
    {
        $this->update(['shops_notified' => $count]);
    }

    /**
     * Generate a unique request number.
     */
    public static function generateRequestNumber(): string
    {
        do {
            $number = 'NB-' . strtoupper(Str::random(4));
        } while (self::where('request_number', $number)->exists());

        return $number;
    }

    /**
     * Find shops that can respond to this request.
     */
    public function findEligibleShops(int $limit = 20): \Illuminate\Database\Eloquent\Collection
    {
        $query = Shop::query()
            ->active()
            ->nearLocation($this->latitude, $this->longitude, $this->radius_km)
            ->withDistanceFrom($this->latitude, $this->longitude)
            ->orderByDistance($this->latitude, $this->longitude);

        if ($this->category) {
            $query->ofCategory($this->category);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Create a new product request.
     */
    public static function createForUser(
        User $user,
        string $description,
        float $latitude,
        float $longitude,
        int $radiusKm = 5,
        ?ShopCategory $category = null,
        ?string $imageUrl = null
    ): self {
        return self::create([
            'user_id' => $user->id,
            'description' => $description,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'radius_km' => $radiusKm,
            'category' => $category,
            'image_url' => $imageUrl,
            'status' => RequestStatus::OPEN,
        ]);
    }
}