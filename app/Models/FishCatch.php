<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FishCatchStatus;
use App\Enums\FishQuantityRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Fish Catch Model.
 *
 * @property int $id
 * @property int $seller_id
 * @property int $fish_type_id
 * @property string $catch_number
 * @property FishQuantityRange $quantity_range
 * @property float $price_per_kg
 * @property string|null $photo_url
 * @property string|null $photo_media_id
 * @property FishCatchStatus $status
 * @property int $customers_coming - PM-019 live claim count
 * @property int $alerts_sent
 * @property int $view_count
 * @property \Carbon\Carbon $arrived_at - PM-010 auto-timestamp
 * @property \Carbon\Carbon $expires_at
 * @property \Carbon\Carbon|null $sold_out_at
 *
 * @srs-ref Section 5.1.3 fish_catches table
 * @srs-ref PM-010 Auto-timestamp, calculate freshness
 */
class FishCatch extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'fish_catches';

    protected $fillable = [
        'seller_id',
        'fish_type_id',
        'catch_number',
        'quantity_range',
        'price_per_kg',
        'photo_url',
        'photo_media_id',
        'status',
        'customers_coming',
        'alerts_sent',
        'view_count',
        'arrived_at',
        'expires_at',
        'sold_out_at',
    ];

    protected $casts = [
        'quantity_range' => FishQuantityRange::class,
        'status' => FishCatchStatus::class,
        'price_per_kg' => 'decimal:2',
        'arrived_at' => 'datetime',
        'expires_at' => 'datetime',
        'sold_out_at' => 'datetime',
    ];

    protected $attributes = [
        'status' => 'available',
        'customers_coming' => 0,
        'alerts_sent' => 0,
        'view_count' => 0,
    ];

    /**
     * Default expiry hours.
     * @srs-ref PM-024 Auto-expire after 6 hours
     */
    public const DEFAULT_EXPIRY_HOURS = 6;

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function seller(): BelongsTo
    {
        return $this->belongsTo(FishSeller::class, 'seller_id');
    }

    public function fishType(): BelongsTo
    {
        return $this->belongsTo(FishType::class);
    }

    public function responses(): HasMany
    {
        return $this->hasMany(FishCatchResponse::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', FishCatchStatus::AVAILABLE);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [
            FishCatchStatus::AVAILABLE,
            FishCatchStatus::LOW_STOCK,
        ])->where('expires_at', '>', now());
    }

    public function scopeOfSeller(Builder $query, int $sellerId): Builder
    {
        return $query->where('seller_id', $sellerId);
    }

    public function scopeOfFishType(Builder $query, int $fishTypeId): Builder
    {
        return $query->where('fish_type_id', $fishTypeId);
    }

    public function scopeNearLocation(Builder $query, float $lat, float $lng, float $radiusKm = 5): Builder
    {
        return $query->join('fish_sellers', 'fish_catches.seller_id', '=', 'fish_sellers.id')
            ->whereRaw(
                "ST_Distance_Sphere(POINT(fish_sellers.longitude, fish_sellers.latitude), POINT(?, ?)) <= ?",
                [$lng, $lat, $radiusKm * 1000]
            )
            ->select('fish_catches.*');
    }

    public function scopeWithDistanceFrom(Builder $query, float $lat, float $lng): Builder
    {
        return $query->join('fish_sellers', 'fish_catches.seller_id', '=', 'fish_sellers.id')
            ->selectRaw(
                "fish_catches.*, ST_Distance_Sphere(POINT(fish_sellers.longitude, fish_sellers.latitude), POINT(?, ?)) / 1000 as distance_km",
                [$lng, $lat]
            );
    }

    public function scopeFreshestFirst(Builder $query): Builder
    {
        return $query->orderBy('arrived_at', 'desc');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors - Freshness (PM-010)
    |--------------------------------------------------------------------------
    */

    /**
     * Get freshness display.
     * @srs-ref PM-010 Calculate freshness
     */
    public function getFreshnessDisplayAttribute(): string
    {
        if (!$this->arrived_at) {
            return 'Just now';
        }

        $mins = $this->arrived_at->diffInMinutes(now());

        if ($mins < 5) return 'Just arrived! 🔥';
        if ($mins < 30) return $mins . ' mins ago';
        if ($mins < 60) return $mins . ' mins';

        $hours = floor($mins / 60);
        if ($hours < 2) return '1 hr ago';
        if ($hours < 6) return $hours . ' hrs ago';

        return $this->arrived_at->format('h:i A');
    }

    /**
     * Get freshness tag.
     */
    public function getFreshnessTagAttribute(): string
    {
        if (!$this->arrived_at) return '🔥 Fresh';

        $mins = $this->arrived_at->diffInMinutes(now());

        if ($mins < 30) return '🔥 Super Fresh';
        if ($mins < 60) return '✨ Fresh';
        if ($mins < 120) return '👍 Good';

        return '⏰ Posted earlier';
    }

    /**
     * Price display.
     */
    public function getPriceDisplayAttribute(): string
    {
        return '₹' . number_format($this->price_per_kg) . '/kg';
    }

    /**
     * Quantity display.
     */
    public function getQuantityDisplayAttribute(): string
    {
        return $this->quantity_range?->label() ?? 'Available';
    }

    /**
     * Status display.
     */
    public function getStatusDisplayAttribute(): string
    {
        return $this->status->display();
    }

    /**
     * Is still active?
     */
    public function getIsActiveAttribute(): bool
    {
        return $this->status->isActive() && $this->expires_at > now();
    }

    /**
     * Time remaining.
     */
    public function getTimeRemainingAttribute(): string
    {
        if ($this->expires_at <= now()) return 'Expired';

        $mins = now()->diffInMinutes($this->expires_at);
        if ($mins < 60) return $mins . ' mins left';

        $hours = floor($mins / 60);
        return $hours . 'h left';
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
        $date = now()->format('md');
        $random = strtoupper(Str::random(4));
        $number = "FC{$date}{$random}";

        while (self::where('catch_number', $number)->exists()) {
            $random = strtoupper(Str::random(4));
            $number = "FC{$date}{$random}";
        }

        return $number;
    }

    /**
     * Mark low stock.
     * @srs-ref PM-022 Update stock status
     */
    public function markLowStock(): void
    {
        $this->update(['status' => FishCatchStatus::LOW_STOCK]);
    }

    /**
     * Mark sold out.
     * @srs-ref PM-022 Update stock status
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
     * Mark expired.
     * @srs-ref PM-024 Auto-expire after 6 hours
     */
    public function markExpired(): void
    {
        $this->update(['status' => FishCatchStatus::EXPIRED]);
    }

    /**
     * Increment customers coming.
     * @srs-ref PM-019 Live claim count
     */
    public function incrementComing(): void
    {
        $this->increment('customers_coming');
    }

    /**
     * Increment alerts sent.
     */
    public function incrementAlertsSent(int $count = 1): void
    {
        $this->increment('alerts_sent', $count);
    }

    /**
     * Increment views.
     */
    public function incrementViews(): void
    {
        $this->increment('view_count');
    }

    /**
     * Convert to alert format.
     * @srs-ref PM-017 Alert message format
     */
    public function toAlertFormat(): array
    {
        return [
            'catch_number' => $this->catch_number,
            'fish_name' => $this->fishType?->name_en,
            'fish_name_ml' => $this->fishType?->name_ml,
            'fish_emoji' => $this->fishType?->emoji ?? '🐟',
            'seller_name' => $this->seller?->location_name,
            'freshness' => $this->freshness_display,
            'freshness_tag' => $this->freshness_tag,
            'quantity' => $this->quantity_display,
            'price' => $this->price_display,
            'price_per_kg' => $this->price_per_kg,
            'seller_rating' => $this->seller?->short_rating,
            'customers_coming' => $this->customers_coming,
            'photo_url' => $this->photo_url,
            'status' => $this->status->value,
        ];
    }

    /**
     * Convert to list item.
     */
    public function toListItem(): array
    {
        $title = ($this->fishType?->emoji ?? '🐟') . ' ' . ($this->fishType?->name_en ?? 'Fish');
        $desc = $this->price_display . ' • ' . $this->freshness_display;

        return [
            'id' => 'catch_' . $this->id,
            'title' => mb_substr($title, 0, 24),
            'description' => mb_substr($desc, 0, 72),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Boot - Auto-timestamp (PM-010)
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
                $model->arrived_at = now(); // PM-010 auto-timestamp
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