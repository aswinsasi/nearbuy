<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FishSellerType;
use App\Enums\FishSellerVerificationStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Fish Seller Model.
 *
 * @property int $id
 * @property int $user_id
 * @property FishSellerType $seller_type
 * @property string $location_name - harbour/market/shop name (PM-001)
 * @property float $latitude
 * @property float $longitude
 * @property FishSellerVerificationStatus $verification_status (PM-003)
 * @property string|null $verification_photo_url (PM-002)
 * @property float $rating - 1-5 stars, default 0 (PM-004)
 * @property int $rating_count
 * @property int $total_sales - default 0 (PM-004)
 * @property int $total_catches
 * @property bool $is_active
 * @property \Carbon\Carbon|null $verified_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @srs-ref Section 5.1.2 fish_sellers table
 * @srs-ref PM-001 to PM-004 Fish seller requirements
 */
class FishSeller extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'seller_type',
        'location_name',
        'latitude',
        'longitude',
        'verification_status',
        'verification_photo_url',
        'rating',
        'rating_count',
        'total_sales',
        'total_catches',
        'is_active',
        'verified_at',
    ];

    protected $casts = [
        'seller_type' => FishSellerType::class,
        'verification_status' => FishSellerVerificationStatus::class,
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'rating' => 'decimal:2',
        'is_active' => 'boolean',
        'verified_at' => 'datetime',
    ];

    protected $attributes = [
        'verification_status' => 'pending',
        'rating' => 0,
        'rating_count' => 0,
        'total_sales' => 0,
        'total_catches' => 0,
        'is_active' => true,
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the user who owns this seller profile.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all catches posted by this seller.
     */
    public function catches(): HasMany
    {
        return $this->hasMany(FishCatch::class, 'seller_id');
    }

    /**
     * Get active catches.
     */
    public function activeCatches(): HasMany
    {
        return $this->catches()
            ->where('status', 'available')
            ->where('expires_at', '>', now());
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope: active sellers.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope: verified sellers.
     */
    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('verification_status', FishSellerVerificationStatus::VERIFIED);
    }

    /**
     * Scope: can post catches (pending or verified).
     */
    public function scopeCanPost(Builder $query): Builder
    {
        return $query->whereIn('verification_status', [
            FishSellerVerificationStatus::PENDING,
            FishSellerVerificationStatus::VERIFIED,
        ]);
    }

    /**
     * Scope: by seller type.
     */
    public function scopeOfType(Builder $query, FishSellerType $type): Builder
    {
        return $query->where('seller_type', $type);
    }

    /**
     * Scope: near location.
     */
    public function scopeNearLocation(Builder $query, float $lat, float $lng, float $radiusKm = 5): Builder
    {
        return $query
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereRaw(
                "ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?)) <= ?",
                [$lng, $lat, $radiusKm * 1000]
            );
    }

    /**
     * Scope: with distance from point.
     */
    public function scopeWithDistanceFrom(Builder $query, float $lat, float $lng): Builder
    {
        return $query->selectRaw(
            "*, ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?)) / 1000 as distance_km",
            [$lng, $lat]
        );
    }

    /**
     * Scope: with active catches.
     */
    public function scopeWithActiveCatches(Builder $query): Builder
    {
        return $query->whereHas('catches', function ($q) {
            $q->where('status', 'available')
                ->where('expires_at', '>', now());
        });
    }

    /**
     * Scope: minimum rating.
     */
    public function scopeMinRating(Builder $query, float $minRating): Builder
    {
        return $query->where('rating', '>=', $minRating);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Display name with icon.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->seller_type->icon() . ' ' . $this->location_name;
    }

    /**
     * Seller type display.
     */
    public function getSellerTypeDisplayAttribute(): string
    {
        return $this->seller_type->display();
    }

    /**
     * Phone from user.
     */
    public function getPhoneAttribute(): ?string
    {
        return $this->user?->phone;
    }

    /**
     * Name from user.
     */
    public function getNameAttribute(): ?string
    {
        return $this->user?->name;
    }

    /**
     * Rating display with stars.
     * @srs-ref PM-004 Rating 1-5 stars
     */
    public function getRatingDisplayAttribute(): string
    {
        if ($this->rating_count === 0) {
            return 'New Seller';
        }

        $stars = str_repeat('⭐', (int) round($this->rating));
        return $stars . ' ' . number_format($this->rating, 1) .
            ' (' . $this->rating_count . ')';
    }

    /**
     * Short rating display.
     */
    public function getShortRatingAttribute(): string
    {
        if ($this->rating_count === 0) {
            return '🆕 New';
        }
        return '⭐ ' . number_format($this->rating, 1) . ' (' . $this->rating_count . ')';
    }

    /**
     * Verification status display.
     */
    public function getVerificationBadgeAttribute(): string
    {
        return $this->verification_status->shortBadge();
    }

    /**
     * Can post catches?
     */
    public function getCanPostAttribute(): bool
    {
        return $this->is_active && $this->verification_status->canPostCatches();
    }

    /**
     * Is verified?
     */
    public function getIsVerifiedAttribute(): bool
    {
        return $this->verification_status === FishSellerVerificationStatus::VERIFIED;
    }

    /**
     * Is pending?
     */
    public function getIsPendingAttribute(): bool
    {
        return $this->verification_status === FishSellerVerificationStatus::PENDING;
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Increment total catches.
     */
    public function incrementCatches(): void
    {
        $this->increment('total_catches');
    }

    /**
     * Increment total sales.
     * @srs-ref PM-004 Track total sales count
     */
    public function incrementSales(): void
    {
        $this->increment('total_sales');
    }

    /**
     * Update rating.
     * @srs-ref PM-004 Track seller rating 1-5 stars
     */
    public function updateRating(int $newRating): void
    {
        // Clamp to 1-5
        $newRating = max(1, min(5, $newRating));

        $totalRating = ($this->rating * $this->rating_count) + $newRating;
        $newCount = $this->rating_count + 1;

        $this->update([
            'rating' => $totalRating / $newCount,
            'rating_count' => $newCount,
        ]);
    }

    /**
     * Mark as verified.
     * @srs-ref PM-003 Verification status
     */
    public function verify(): void
    {
        $this->update([
            'verification_status' => FishSellerVerificationStatus::VERIFIED,
            'verified_at' => now(),
        ]);
    }

    /**
     * Suspend seller.
     * @srs-ref PM-003 Verification status: suspended
     */
    public function suspend(): void
    {
        $this->update([
            'verification_status' => FishSellerVerificationStatus::SUSPENDED,
            'is_active' => false,
        ]);
    }

    /**
     * Reactivate seller (back to pending).
     */
    public function reactivate(): void
    {
        $this->update([
            'verification_status' => FishSellerVerificationStatus::PENDING,
            'is_active' => true,
        ]);
    }

    /**
     * Update location.
     */
    public function updateLocation(float $lat, float $lng, ?string $locationName = null): void
    {
        $data = [
            'latitude' => $lat,
            'longitude' => $lng,
        ];

        if ($locationName) {
            $data['location_name'] = $locationName;
        }

        $this->update($data);

        // Also update user location
        $this->user?->update([
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }

    /**
     * Set verification photo.
     * @srs-ref PM-002 Photo verification
     */
    public function setVerificationPhoto(string $url): void
    {
        $this->update(['verification_photo_url' => $url]);
    }

    /**
     * Get active catch count.
     */
    public function getActiveCatchCount(): int
    {
        return $this->catches()
            ->where('status', 'available')
            ->where('expires_at', '>', now())
            ->count();
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        return [
            'id' => 'seller_' . $this->id,
            'title' => mb_substr($this->display_name, 0, 24),
            'description' => mb_substr($this->short_rating . ' • ' . $this->verification_badge, 0, 72),
        ];
    }

    /**
     * Get stats for menu display.
     */
    public function getStats(): array
    {
        $todayCatches = $this->catches()->whereDate('created_at', today())->count();
        $activeCatches = $this->getActiveCatchCount();

        return [
            'active_catches' => $activeCatches,
            'today_catches' => $todayCatches,
            'total_catches' => $this->total_catches,
            'total_sales' => $this->total_sales,
            'rating' => $this->rating,
            'rating_count' => $this->rating_count,
            'is_verified' => $this->is_verified,
            'verification_status' => $this->verification_status->value,
        ];
    }
}