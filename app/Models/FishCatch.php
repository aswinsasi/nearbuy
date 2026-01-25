<?php

namespace App\Models;

use App\Enums\FishCatchStatus;
use App\Enums\FishQuantityRange;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Fish Catch Model - Individual catch postings.
 *
 * @property int $id
 * @property int $fish_seller_id
 * @property int $fish_type_id
 * @property string $catch_number
 * @property FishQuantityRange $quantity_range
 * @property float|null $quantity_kg
 * @property float $price_per_kg
 * @property string|null $photo_url
 * @property string|null $photo_media_id
 * @property float|null $latitude
 * @property float|null $longitude
 * @property string|null $location_name
 * @property FishCatchStatus $status
 * @property \Carbon\Carbon|null $sold_out_at
 * @property \Carbon\Carbon|null $arrived_at
 * @property \Carbon\Carbon $expires_at
 * @property int $view_count
 * @property int $alerts_sent
 * @property int $coming_count
 * @property int $message_count
 * @property string|null $freshness_tag
 * @property string|null $notes
 *
 * @srs-ref Section 5.1.3 - fish_catches table
 * @srs-ref Section 2.3.2 - Catch Posting
 */
class FishCatch extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     */
    protected $table = 'fish_catches';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'fish_seller_id',
        'fish_type_id',
        'catch_number',
        'quantity_range',
        'quantity_kg',
        'price_per_kg',
        'photo_url',
        'photo_media_id',
        'latitude',
        'longitude',
        'location_name',
        'status',
        'sold_out_at',
        'arrived_at',
        'expires_at',
        'view_count',
        'alerts_sent',
        'coming_count',
        'message_count',
        'freshness_tag',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'quantity_range' => FishQuantityRange::class,
        'quantity_kg' => 'decimal:2',
        'price_per_kg' => 'decimal:2',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'status' => FishCatchStatus::class,
        'sold_out_at' => 'datetime',
        'arrived_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /**
     * Default expiry hours.
     */
    public const DEFAULT_EXPIRY_HOURS = 6;

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the seller who posted this catch.
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(FishSeller::class, 'fish_seller_id');
    }

    /**
     * Get the fish type.
     */
    public function fishType(): BelongsTo
    {
        return $this->belongsTo(FishType::class);
    }

    /**
     * Get alerts sent for this catch.
     */
    public function alerts(): HasMany
    {
        return $this->hasMany(FishAlert::class);
    }

    /**
     * Get customer responses to this catch.
     */
    public function responses(): HasMany
    {
        return $this->hasMany(FishCatchResponse::class);
    }

    /**
     * Get "I'm Coming" responses.
     */
    public function comingResponses(): HasMany
    {
        return $this->responses()->where('response_type', 'coming');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter available catches.
     */
    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', FishCatchStatus::AVAILABLE);
    }

    /**
     * Scope to filter active catches (not expired).
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            FishCatchStatus::AVAILABLE,
            FishCatchStatus::LOW_STOCK,
        ])->where('expires_at', '>', now());
    }

    /**
     * Scope to filter expired catches.
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('status', FishCatchStatus::EXPIRED)
                ->orWhere('expires_at', '<=', now());
        });
    }

    /**
     * Scope to filter by fish type.
     */
    public function scopeOfFishType(Builder $query, int $fishTypeId): Builder
    {
        return $query->where('fish_type_id', $fishTypeId);
    }

    /**
     * Scope to filter by multiple fish types.
     */
    public function scopeOfFishTypes(Builder $query, array $fishTypeIds): Builder
    {
        return $query->whereIn('fish_type_id', $fishTypeIds);
    }

    /**
     * Scope to filter by seller.
     */
    public function scopeOfSeller(Builder $query, int $sellerId): Builder
    {
        return $query->where('fish_seller_id', $sellerId);
    }

    /**
     * Scope to find catches near a location.
     */
    public function scopeNearLocation(Builder $query, float $latitude, float $longitude, float $radiusKm = 5): Builder
    {
        return $query
            ->join('fish_sellers', 'fish_catches.fish_seller_id', '=', 'fish_sellers.id')
            ->whereRaw(
                "ST_Distance_Sphere(
                    POINT(COALESCE(fish_catches.longitude, fish_sellers.longitude), 
                          COALESCE(fish_catches.latitude, fish_sellers.latitude)),
                    POINT(?, ?)
                ) <= ?",
                [$longitude, $latitude, $radiusKm * 1000]
            )
            ->select('fish_catches.*');
    }

    /**
     * Scope to select with distance from a point.
     */
    public function scopeWithDistanceFrom(Builder $query, float $latitude, float $longitude): Builder
    {
        return $query
            ->join('fish_sellers', 'fish_catches.fish_seller_id', '=', 'fish_sellers.id')
            ->selectRaw(
                "fish_catches.*, ST_Distance_Sphere(
                    POINT(COALESCE(fish_catches.longitude, fish_sellers.longitude), 
                          COALESCE(fish_catches.latitude, fish_sellers.latitude)),
                    POINT(?, ?)
                ) / 1000 as distance_km",
                [$longitude, $latitude]
            );
    }

    /**
     * Scope to order by freshest first.
     */
    public function scopeFreshestFirst(Builder $query): Builder
    {
        return $query->orderBy('arrived_at', 'desc');
    }

    /**
     * Scope to order by nearest first.
     */
    public function scopeNearestFirst(Builder $query): Builder
    {
        return $query->orderBy('distance_km', 'asc');
    }

    /**
     * Scope for browse view.
     */
    public function scopeForBrowse(Builder $query): Builder
    {
        return $query->with(['seller', 'fishType'])
            ->active()
            ->freshestFirst();
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get display title.
     */
    public function getDisplayTitleAttribute(): string
    {
        return $this->fishType?->display_name ?? '🐟 Fish';
    }

    /**
     * Get price display.
     */
    public function getPriceDisplayAttribute(): string
    {
        return '₹' . number_format($this->price_per_kg) . '/kg';
    }

    /**
     * Get quantity display.
     */
    public function getQuantityDisplayAttribute(): string
    {
        if ($this->quantity_kg) {
            return $this->quantity_kg . ' kg';
        }

        return $this->quantity_range?->label() ?? 'Available';
    }

    /**
     * Get freshness display (time since arrival).
     */
    public function getFreshnessDisplayAttribute(): string
    {
        if (!$this->arrived_at) {
            return 'Just posted';
        }

        $minutes = $this->arrived_at->diffInMinutes(now());

        if ($minutes < 60) {
            return $minutes . ' mins ago';
        }

        $hours = floor($minutes / 60);
        return $hours . ' hr' . ($hours > 1 ? 's' : '') . ' ago';
    }

    /**
     * Get status display.
     */
    public function getStatusDisplayAttribute(): string
    {
        return $this->status->display();
    }

    /**
     * Get location for this catch.
     */
    public function getCatchLatitudeAttribute(): float
    {
        return $this->latitude ?? $this->seller?->latitude ?? 0;
    }

    public function getCatchLongitudeAttribute(): float
    {
        return $this->longitude ?? $this->seller?->longitude ?? 0;
    }

    /**
     * Get location display.
     */
    public function getLocationDisplayAttribute(): string
    {
        if ($this->location_name) {
            return $this->location_name;
        }

        return $this->seller?->location_display ?? 'Location available';
    }

    /**
     * Check if catch is still active.
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->status->isActive() && $this->expires_at > now();
    }

    /**
     * Get time remaining until expiry.
     */
    public function getTimeRemainingAttribute(): string
    {
        if ($this->expires_at <= now()) {
            return 'Expired';
        }

        $minutes = now()->diffInMinutes($this->expires_at);

        if ($minutes < 60) {
            return $minutes . ' mins left';
        }

        $hours = floor($minutes / 60);
        $mins = $minutes % 60;

        if ($hours < 24) {
            return $hours . 'h ' . $mins . 'm left';
        }

        return $this->expires_at->diffForHumans();
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Generate unique catch number.
     */
    public static function generateCatchNumber(): string
    {
        $date = now()->format('Ymd');
        $random = strtoupper(Str::random(4));
        $number = "FC-{$date}-{$random}";

        // Ensure uniqueness
        while (self::where('catch_number', $number)->exists()) {
            $random = strtoupper(Str::random(4));
            $number = "FC-{$date}-{$random}";
        }

        return $number;
    }

    /**
     * Mark as low stock.
     */
    public function markLowStock(): void
    {
        $this->update(['status' => FishCatchStatus::LOW_STOCK]);
    }

    /**
     * Mark as sold out.
     */
    public function markSoldOut(): void
    {
        $this->update([
            'status' => FishCatchStatus::SOLD_OUT,
            'sold_out_at' => now(),
        ]);

        $this->seller?->incrementSales();
    }

    /**
     * Mark as expired.
     */
    public function markExpired(): void
    {
        $this->update(['status' => FishCatchStatus::EXPIRED]);
    }

    /**
     * Restore stock (mark available again).
     */
    public function restoreStock(): void
    {
        if ($this->status->canTransitionTo(FishCatchStatus::AVAILABLE)) {
            $this->update([
                'status' => FishCatchStatus::AVAILABLE,
                'sold_out_at' => null,
            ]);
        }
    }

    /**
     * Increment view count.
     */
    public function incrementViews(): void
    {
        $this->increment('view_count');
    }

    /**
     * Increment alerts sent count.
     */
    public function incrementAlertsSent(int $count = 1): void
    {
        $this->increment('alerts_sent', $count);
    }

    /**
     * Increment coming count.
     */
    public function incrementComingCount(): void
    {
        $this->increment('coming_count');
    }

    /**
     * Increment message count.
     */
    public function incrementMessageCount(): void
    {
        $this->increment('message_count');
    }

    /**
     * Convert to alert message format.
     *
     * @srs-ref Section 2.5.2 - Customer Alert Message Format
     */
    public function toAlertFormat(): array
    {
        return [
            'catch_number' => $this->catch_number,
            'fish_name' => $this->fishType?->name_en,
            'fish_name_ml' => $this->fishType?->name_ml,
            'emoji' => $this->fishType?->emoji ?? '🐟',
            'seller_name' => $this->seller?->business_name,
            'location' => $this->location_display,
            'arrived_at' => $this->arrived_at?->format('h:i A'),
            'freshness' => $this->freshness_display,
            'quantity' => $this->quantity_display,
            'price' => $this->price_display,
            'price_per_kg' => $this->price_per_kg,
            'seller_rating' => $this->seller?->short_rating,
            'photo_url' => $this->photo_url,
            'status' => $this->status->value,
        ];
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        $title = $this->fishType?->emoji . ' ' . $this->fishType?->name_en;
        $description = $this->price_display . ' • ' . $this->freshness_display;

        if ($this->status === FishCatchStatus::LOW_STOCK) {
            $description .= ' • ⚠️ Low stock';
        }

        return [
            'id' => 'catch_' . $this->id,
            'title' => substr($title, 0, 24),
            'description' => substr($description, 0, 72),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Boot
    |--------------------------------------------------------------------------
    */

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->catch_number)) {
                $model->catch_number = self::generateCatchNumber();
            }

            if (empty($model->arrived_at)) {
                $model->arrived_at = now();
            }

            if (empty($model->expires_at)) {
                $model->expires_at = now()->addHours(self::DEFAULT_EXPIRY_HOURS);
            }

            if (empty($model->status)) {
                $model->status = FishCatchStatus::AVAILABLE;
            }
        });

        static::created(function ($model) {
            $model->seller?->incrementCatches();
        });
    }
}
