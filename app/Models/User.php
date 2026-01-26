<?php

namespace App\Models;

use App\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;

/**
 * User model.
 *
 * IMPORTANT: A user's "type" (CUSTOMER, SHOP, FISH_SELLER) is their PRIMARY role.
 * However, any user can ALSO be a fish seller by having a fish_seller profile.
 * 
 * Example: A SHOP user can also sell fish by registering as a fish seller.
 * They remain type=SHOP but also have a fishSeller relationship.
 *
 * @srs-ref Section 2.2: Fish sellers are separate registration from customers/shops
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

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the shop owned by this user.
     */
    public function shop(): HasOne
    {
        return $this->hasOne(Shop::class);
    }

    /**
     * Get the fish seller profile for this user.
     *
     * NOTE: Any user type can have a fish seller profile!
     * A CUSTOMER or SHOP user can also be a fish seller.
     *
     * @srs-ref Pacha Meen Module
     * @srs-ref Section 2.2: Fish sellers are separate from main user types
     */
    public function fishSeller(): HasOne
    {
        return $this->hasOne(FishSeller::class);
    }

    /**
     * Get fish subscriptions for this user.
     *
     * @srs-ref Pacha Meen Module - Customer Subscriptions
     */
    public function fishSubscriptions(): HasMany
    {
        return $this->hasMany(FishSubscription::class);
    }

    /**
     * Get active fish subscriptions.
     *
     * @srs-ref PM-015: Used for manage alerts feature
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
     * Get fish alerts received by this user.
     */
    public function fishAlerts(): HasMany
    {
        return $this->hasMany(FishAlert::class);
    }

    /**
     * Get fish catch responses by this user.
     */
    public function fishCatchResponses(): HasMany
    {
        return $this->hasMany(FishCatchResponse::class);
    }

    /**
     * Get product requests created by this user.
     */
    public function productRequests(): HasMany
    {
        return $this->hasMany(ProductRequest::class);
    }

    /**
     * Get agreements where user is the creator (from party).
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
     * Get the conversation session for this user.
     */
    public function conversationSession(): HasOne
    {
        return $this->hasOne(ConversationSession::class);
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
     * Scope to filter users with type FISH_SELLER.
     *
     * NOTE: This only finds users whose PRIMARY type is FISH_SELLER.
     * To find ALL users who can sell fish, use scopeWithFishSellerProfile().
     */
    public function scopeFishSellers(Builder $query): Builder
    {
        return $query->where('type', UserType::FISH_SELLER);
    }

    /**
     * Scope to filter users who have a fish seller profile.
     *
     * This finds ANY user who can sell fish, regardless of their primary type.
     * Use this instead of scopeFishSellers() when you need all fish sellers.
     *
     * @srs-ref Section 2.2: Any user can be a fish seller
     */
    public function scopeWithFishSellerProfile(Builder $query): Builder
    {
        return $query->whereHas('fishSeller');
    }

    /**
     * Scope to filter users with active fish subscriptions.
     *
     * @srs-ref PM-015: For subscription management
     */
    public function scopeWithActiveFishSubscription(Builder $query): Builder
    {
        return $query->whereHas('fishSubscriptions', function ($q) {
            $q->where('is_active', true)
                ->where(function ($q2) {
                    $q2->where('is_paused', false)
                        ->orWhere('paused_until', '<', now());
                });
        });
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
     * Scope to find users within a radius (km) of a location.
     * Uses MySQL ST_Distance_Sphere for accurate distance calculation.
     */
    public function scopeNearLocation(Builder $query, float $latitude, float $longitude, float $radiusKm = 5): Builder
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

    /*
    |--------------------------------------------------------------------------
    | Accessors & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check if user is a shop owner.
     */
    public function isShopOwner(): bool
    {
        return $this->type === UserType::SHOP;
    }

    /**
     * Check if user is a customer.
     */
    public function isCustomer(): bool
    {
        return $this->type === UserType::CUSTOMER;
    }

    /**
     * Check if user is a fish seller (has fish seller PROFILE).
     *
     * FIXED: This now checks for fish_seller PROFILE, not user type!
     * Any user (CUSTOMER, SHOP) can also be a fish seller.
     *
     * @srs-ref Section 2.2: Fish sellers are separate from main user types
     */
    public function isFishSeller(): bool
    {
        // FIXED: Check for fish seller PROFILE, not user type
        // OLD (WRONG): return $this->type === UserType::FISH_SELLER;
        // NEW: Check if user has a fish seller profile
        return $this->fishSeller !== null;
    }

    /**
     * Check if user's PRIMARY type is FISH_SELLER.
     *
     * Use this only when you specifically need to check the user type,
     * not whether they can sell fish. For most cases, use isFishSeller().
     */
    public function hasTypeFishSeller(): bool
    {
        return $this->type === UserType::FISH_SELLER;
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

    /**
     * Check if user can post fish catches.
     *
     * FIXED: Now uses the corrected isFishSeller() check.
     */
    public function canPostFishCatches(): bool
    {
        // User must have a fish seller profile to post catches
        return $this->fishSeller !== null;
    }

    /**
     * Check if user can register as a fish seller.
     *
     * Any registered user who doesn't already have a fish seller profile can register.
     *
     * @srs-ref Section 2.2: Any user can become a fish seller
     */
    public function canRegisterAsFishSeller(): bool
    {
        return $this->isRegistered() && $this->fishSeller === null;
    }

    /**
     * Check if user can subscribe to fish alerts.
     */
    public function canSubscribeToFishAlerts(): bool
    {
        return $this->type->canSubscribeToFishAlerts();
    }

    /**
     * Check if user has an active fish subscription.
     *
     * Used to determine whether to show "Subscribe" or "Manage Alerts" option.
     *
     * @srs-ref PM-015: Subscription modification
     */
    public function hasFishSubscription(): bool
    {
        return $this->activeFishSubscriptions()->exists();
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