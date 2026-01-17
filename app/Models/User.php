<?php

namespace App\Models;

use App\Enums\UserType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Database\Eloquent\Builder;

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
        $earthRadiusKm = 6371;

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