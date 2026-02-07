<?php

namespace App\Models;

use App\Enums\UserType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * User model.
 *
 * @srs-ref Section 6.2.1 - users Table
 *
 * IMPORTANT: A user's "type" is CUSTOMER or SHOP only (per SRS Section 6.3).
 * Fish sellers and job workers are ADDITIONAL PROFILES stored in separate tables.
 * Any user (customer or shop) can also be a fish seller AND/OR job worker.
 *
 * @property int $id
 * @property string $phone WhatsApp phone number (unique)
 * @property string|null $name User display name
 * @property UserType $type customer or shop
 * @property float|null $latitude User location
 * @property float|null $longitude User location
 * @property string|null $address Location description
 * @property string $language Preferred language (en/ml)
 * @property \Carbon\Carbon|null $registered_at Registration timestamp
 */
class User extends Authenticatable
{
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'phone',
        'name',
        'type',
        'latitude',
        'longitude',
        'address',
        'language',
        'registered_at',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'type' => UserType::class,
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
        'registered_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     *
     * @var array
     */
    protected $attributes = [
        'type' => 'customer',
        'language' => 'en',
    ];

    /*
    |--------------------------------------------------------------------------
    | Core Relationships (SRS Section 6.1)
    |--------------------------------------------------------------------------
    */

    /**
     * Get the shop owned by this user (1:1).
     *
     * @srs-ref Section 6.1 - Users (1) → Shops (1)
     */
    public function shop(): HasOne
    {
        return $this->hasOne(Shop::class);
    }

    /**
     * Get product requests created by this user (1:N).
     *
     * @srs-ref Section 6.1 - Users (1) → Product Requests (N)
     */
    public function productRequests(): HasMany
    {
        return $this->hasMany(ProductRequest::class);
    }

    /**
     * Get agreements where user is the creator (from party).
     *
     * @srs-ref Section 6.1 - Users (1) → Agreements (N)
     */
    public function createdAgreements(): HasMany
    {
        return $this->hasMany(Agreement::class, 'from_user_id');
    }

    /**
     * Get agreements where user is the recipient (to party).
     */
    public function receivedAgreements(): HasMany
    {
        return $this->hasMany(Agreement::class, 'to_user_id');
    }

    /**
     * Get conversation session for this user.
     */
    public function conversationSession(): HasOne
    {
        return $this->hasOne(ConversationSession::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Add-On Module Relationships (Profiles)
    |--------------------------------------------------------------------------
    */

    /**
     * Get fish seller profile (nullable).
     *
     * Any user can ALSO be a fish seller by having this profile.
     *
     * @srs-ref Pacha Meen Module (PM-001 to PM-004)
     */
    public function fishSeller(): HasOne
    {
        return $this->hasOne(FishSeller::class);
    }

    /**
     * Get fish subscriptions for this user.
     *
     * @srs-ref PM-011 to PM-015 - Customer Subscriptions
     */
    public function fishSubscriptions(): HasMany
    {
        return $this->hasMany(FishSubscription::class);
    }

    /**
     * Get active fish subscriptions.
     */
    public function activeFishSubscriptions(): HasMany
    {
        return $this->fishSubscriptions()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('is_paused', false)
                    ->orWhere('paused_until', '<', now());
            });
    }

    /**
     * Get job worker profile (nullable).
     *
     * Any user can ALSO be a job worker by having this profile.
     *
     * @srs-ref Njaanum Panikkar Module (NP-001 to NP-005)
     */
    public function jobWorker(): HasOne
    {
        return $this->hasOne(JobWorker::class);
    }

    /**
     * Get job posts created by this user (as task giver).
     *
     * @srs-ref NP-006 to NP-014 - Job Posting
     */
    public function jobPosts(): HasMany
    {
        return $this->hasMany(JobPost::class, 'poster_user_id');
    }

    /**
     * Get active job posts by this user.
     */
    public function activeJobPosts(): HasMany
    {
        return $this->jobPosts()->whereIn('status', ['open', 'assigned', 'in_progress']);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter by user type.
     */
    public function scopeOfType(Builder $query, UserType $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to filter customers only.
     */
    public function scopeCustomers(Builder $query): Builder
    {
        return $query->where('type', UserType::CUSTOMER);
    }

    /**
     * Scope to filter shop owners only.
     */
    public function scopeShopOwners(Builder $query): Builder
    {
        return $query->where('type', UserType::SHOP);
    }

    /**
     * Scope to filter registered users.
     */
    public function scopeRegistered(Builder $query): Builder
    {
        return $query->whereNotNull('registered_at');
    }

    /**
     * Scope to filter by phone number.
     */
    public function scopeByPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone);
    }

    /**
     * Scope to find users near a location.
     * Uses MySQL ST_Distance_Sphere for accurate distance calculation.
     *
     * @srs-ref FR-OFR-11 - Configurable radius queries
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
     * Scope to filter users who have a fish seller profile.
     */
    public function scopeWithFishSellerProfile(Builder $query): Builder
    {
        return $query->whereHas('fishSeller');
    }

    /**
     * Scope to filter users who have a job worker profile.
     */
    public function scopeWithJobWorkerProfile(Builder $query): Builder
    {
        return $query->whereHas('jobWorker');
    }

    /*
    |--------------------------------------------------------------------------
    | Type Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if user is a customer.
     */
    public function isCustomer(): bool
    {
        return $this->type === UserType::CUSTOMER;
    }

    /**
     * Check if user is a shop owner.
     */
    public function isShopOwner(): bool
    {
        return $this->type === UserType::SHOP;
    }

    /**
     * Check if user has a shop record.
     */
    public function hasShop(): bool
    {
        return $this->shop !== null;
    }

    /**
     * Check if user is a fish seller (has fish seller profile).
     *
     * NOTE: This checks for profile, not user type!
     * Any user (customer or shop) can be a fish seller.
     */
    public function isFishSeller(): bool
    {
        return $this->fishSeller !== null;
    }

    /**
     * Check if user is a job worker (has job worker profile).
     *
     * NOTE: This checks for profile, not user type!
     * Any user (customer or shop) can be a job worker.
     */
    public function isJobWorker(): bool
    {
        return $this->jobWorker !== null;
    }

    /**
     * Check if user is registered.
     */
    public function isRegistered(): bool
    {
        return $this->registered_at !== null;
    }

    /**
     * Check if user has location set.
     */
    public function hasLocation(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Permission Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if user can create offers.
     */
    public function canCreateOffers(): bool
    {
        return $this->isShopOwner() && $this->hasShop();
    }

    /**
     * Check if user can respond to product requests.
     */
    public function canRespondToRequests(): bool
    {
        return $this->isShopOwner() && $this->hasShop();
    }

    /**
     * Check if user can post fish catches.
     */
    public function canPostFishCatches(): bool
    {
        return $this->isFishSeller();
    }

    /**
     * Check if user can register as fish seller.
     */
    public function canRegisterAsFishSeller(): bool
    {
        return $this->isRegistered() && !$this->isFishSeller();
    }

    /**
     * Check if user can subscribe to fish alerts.
     */
    public function canSubscribeToFishAlerts(): bool
    {
        return $this->isRegistered();
    }

    /**
     * Check if user has active fish subscriptions.
     */
    public function hasFishSubscription(): bool
    {
        return $this->activeFishSubscriptions()->exists();
    }

    /**
     * Check if user can apply for jobs.
     */
    public function canApplyForJobs(): bool
    {
        return $this->isJobWorker();
    }

    /**
     * Check if user can register as job worker.
     */
    public function canRegisterAsJobWorker(): bool
    {
        return $this->isRegistered() && !$this->isJobWorker();
    }

    /**
     * Check if user can post jobs (as task giver).
     * Any registered user can post jobs.
     */
    public function canPostJobs(): bool
    {
        return $this->isRegistered();
    }

    /**
     * Check if user has active job posts.
     */
    public function hasActiveJobPosts(): bool
    {
        return $this->activeJobPosts()->exists();
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get formatted phone number for display.
     */
    public function getFormattedPhoneAttribute(): string
    {
        $phone = $this->phone;

        if (str_starts_with($phone, '91') && strlen($phone) === 12) {
            return '+91 ' . substr($phone, 2, 5) . ' ' . substr($phone, 7);
        }

        return '+' . $phone;
    }

    /**
     * Get display name (name or phone).
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->name ?? $this->formatted_phone;
    }

    /**
     * Get first name.
     */
    public function getFirstNameAttribute(): string
    {
        if (!$this->name) {
            return 'Friend';
        }

        $parts = explode(' ', trim($this->name));
        return $parts[0];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate distance from a given point in km.
     */
    public function distanceFrom(float $latitude, float $longitude): float
    {
        if (!$this->hasLocation()) {
            return 0;
        }

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

    /**
     * Get all agreements (created + received).
     */
    public function getAllAgreements()
    {
        return Agreement::where('from_user_id', $this->id)
            ->orWhere('to_user_id', $this->id)
            ->latest()
            ->get();
    }

    /**
     * Find user by phone number.
     */
    public static function findByPhone(string $phone): ?self
    {
        return self::where('phone', $phone)->first();
    }

    /**
     * Find or create user by phone number.
     */
    public static function findOrCreateByPhone(string $phone): self
    {
        return self::firstOrCreate(
            ['phone' => $phone],
            ['type' => UserType::CUSTOMER]
        );
    }
}