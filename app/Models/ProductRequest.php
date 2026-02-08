<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Enums\ShopCategory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Product Request Model.
 *
 * Represents a customer's request for a product broadcast to nearby shops.
 *
 * @property int $id
 * @property int $user_id
 * @property string $request_number (FR-PRD-03: format NB-XXXX)
 * @property ShopCategory|null $category
 * @property string $description (FR-PRD-02)
 * @property string|null $image_url
 * @property float $latitude
 * @property float $longitude
 * @property int $radius_km (FR-PRD-05: proximity)
 * @property RequestStatus $status
 * @property Carbon $expires_at (FR-PRD-06: default 2 hours)
 * @property int $shops_notified (FR-PRD-05)
 * @property int $response_count
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read User $user
 * @property-read Collection<ProductResponse> $responses
 *
 * @srs-ref FR-PRD-01 to FR-PRD-06
 */
class ProductRequest extends Model
{
    use HasFactory;

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

    protected $attributes = [
        'radius_km' => 5,
        'shops_notified' => 0,
        'response_count' => 0,
    ];

    /**
     * Boot: auto-generate request_number and expires_at.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $request) {
            // FR-PRD-03: Generate unique request number (NB-XXXX)
            if (empty($request->request_number)) {
                $request->request_number = self::generateRequestNumber();
            }

            // FR-PRD-06: Set expiration (default 2 hours)
            if (empty($request->expires_at)) {
                $hours = config('nearbuy.products.request_expiry_hours', 2);
                $request->expires_at = now()->addHours($hours);
            }

            // Default status
            if (empty($request->status)) {
                $request->status = RequestStatus::OPEN;
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
     * Alias for user.
     */
    public function customer(): BelongsTo
    {
        return $this->user();
    }

    /**
     * Get responses from shops.
     */
    public function responses(): HasMany
    {
        return $this->hasMany(ProductResponse::class, 'request_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Query Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope: open requests.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', RequestStatus::OPEN);
    }

    /**
     * Scope: active (open or collecting, not expired).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query
            ->whereIn('status', [RequestStatus::OPEN, RequestStatus::COLLECTING])
            ->where('expires_at', '>', now());
    }

    /**
     * Scope: not expired.
     */
    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '>', now());
    }

    /**
     * Scope: expired.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where('expires_at', '<=', now());
    }

    /**
     * Scope: by user.
     */
    public function scopeByUser(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: by category.
     */
    public function scopeOfCategory(Builder $query, ShopCategory|string $category): Builder
    {
        $value = $category instanceof ShopCategory ? $category->value : strtoupper($category);
        return $query->where('category', $value);
    }

    /**
     * Scope: requests a shop can respond to (within radius + matching category).
     *
     * @srs-ref FR-PRD-05 - Identify eligible shops by category AND proximity
     */
    public function scopeForShop(Builder $query, Shop $shop): Builder
    {
        return $query
            ->active()
            ->where(function (Builder $q) use ($shop) {
                $q->whereNull('category')
                    ->orWhere('category', $shop->category);
            })
            ->whereRaw('
                (ST_Distance_Sphere(
                    POINT(longitude, latitude),
                    POINT(?, ?)
                ) / 1000) <= radius_km
            ', [$shop->longitude, $shop->latitude])
            ->whereNotExists(function ($sub) use ($shop) {
                $sub->selectRaw('1')
                    ->from('product_responses')
                    ->whereColumn('product_responses.request_id', 'product_requests.id')
                    ->where('product_responses.shop_id', $shop->id);
            });
    }

    /*
    |--------------------------------------------------------------------------
    | Static Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Generate unique request number (NB-XXXX).
     *
     * @srs-ref FR-PRD-03
     */
    public static function generateRequestNumber(): string
    {
        do {
            $number = 'NB-' . strtoupper(Str::random(4));
        } while (self::where('request_number', $number)->exists());

        return $number;
    }

    /**
     * Create request for user.
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
        ]);
    }

    /**
     * Find eligible shops for this request.
     *
     * @srs-ref FR-PRD-05 - Identify eligible shops by category AND proximity
     */
    public function findEligibleShops(int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        $query = Shop::query()
            ->select('shops.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$this->longitude, $this->latitude])
            ->where('shops.is_active', true)
            ->havingRaw('distance_km <= ?', [$this->radius_km])
            ->orderBy('distance_km')
            ->limit($limit);

        // FR-PRD-05: Filter by category if specified
        if ($this->category) {
            $query->where('shops.category', $this->category);
        }

        return $query->get();
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check if request accepts responses.
     */
    public function isOpen(): bool
    {
        return in_array($this->status, [RequestStatus::OPEN, RequestStatus::COLLECTING])
            && $this->expires_at->isFuture();
    }

    /**
     * Check if expired.
     */
    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Check if shop has responded.
     */
    public function hasResponseFrom(Shop $shop): bool
    {
        return $this->responses()->where('shop_id', $shop->id)->exists();
    }

    /**
     * Get time remaining.
     */
    public function getTimeRemainingAttribute(): string
    {
        if ($this->isExpired()) {
            return 'Expired';
        }

        $diff = now()->diff($this->expires_at);

        if ($diff->d > 0) {
            return "{$diff->d}d {$diff->h}h";
        }
        if ($diff->h > 0) {
            return "{$diff->h}h {$diff->i}m";
        }
        return "{$diff->i} min";
    }

    /*
    |--------------------------------------------------------------------------
    | Status Actions
    |--------------------------------------------------------------------------
    */

    /**
     * Mark as collecting (first response received).
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
        $this->update(['status' => RequestStatus::CLOSED]);
    }

    /**
     * Mark as expired.
     */
    public function markAsExpired(): void
    {
        $this->update(['status' => RequestStatus::EXPIRED]);
    }

    /**
     * Record shops notified count.
     */
    public function recordShopsNotified(int $count): void
    {
        $this->update(['shops_notified' => $count]);
    }

    /**
     * Increment response count.
     */
    public function incrementResponses(): void
    {
        $this->increment('response_count');

        // Transition to collecting on first response
        if ($this->status === RequestStatus::OPEN) {
            $this->update(['status' => RequestStatus::COLLECTING]);
        }
    }
}