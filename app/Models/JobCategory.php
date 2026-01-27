<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Job Category Model - Master data for job types.
 *
 * @property int $id
 * @property string $name_en
 * @property string $name_ml
 * @property string $slug
 * @property int $tier
 * @property string $icon
 * @property float|null $typical_pay_min
 * @property float|null $typical_pay_max
 * @property float|null $typical_duration_hours
 * @property bool $requires_vehicle
 * @property string|null $description
 * @property int $sort_order
 * @property bool $is_popular
 * @property bool $is_active
 *
 * @srs-ref Section 3.1 - Job Categories Master Data
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobCategory extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name_en',
        'name_ml',
        'slug',
        'tier',
        'icon',
        'typical_pay_min',
        'typical_pay_max',
        'typical_duration_hours',
        'requires_vehicle',
        'description',
        'sort_order',
        'is_popular',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'tier' => 'integer',
        'typical_pay_min' => 'decimal:2',
        'typical_pay_max' => 'decimal:2',
        'typical_duration_hours' => 'decimal:1',
        'requires_vehicle' => 'boolean',
        'is_popular' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Tier constants.
     */
    public const TIER_ZERO_SKILLS = 1;
    public const TIER_BASIC_SKILLS = 2;

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get all job posts in this category.
     */
    public function jobPosts(): HasMany
    {
        return $this->hasMany(JobPost::class);
    }

    /**
     * Get active job posts in this category.
     */
    public function activeJobPosts(): HasMany
    {
        return $this->jobPosts()->where('status', 'open');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter active categories.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter popular categories.
     */
    public function scopePopular(Builder $query): Builder
    {
        return $query->where('is_popular', true);
    }

    /**
     * Scope to filter Tier 1 (zero skills) categories.
     */
    public function scopeTier1(Builder $query): Builder
    {
        return $query->where('tier', self::TIER_ZERO_SKILLS);
    }

    /**
     * Scope to filter Tier 2 (basic skills) categories.
     */
    public function scopeTier2(Builder $query): Builder
    {
        return $query->where('tier', self::TIER_BASIC_SKILLS);
    }

    /**
     * Scope to filter categories requiring vehicle.
     */
    public function scopeRequiresVehicle(Builder $query): Builder
    {
        return $query->where('requires_vehicle', true);
    }

    /**
     * Scope to filter categories not requiring vehicle.
     */
    public function scopeNoVehicleRequired(Builder $query): Builder
    {
        return $query->where('requires_vehicle', false);
    }

    /**
     * Scope for list selection (active, ordered).
     */
    public function scopeForSelection(Builder $query): Builder
    {
        return $query->active()
            ->orderBy('tier')
            ->orderBy('is_popular', 'desc')
            ->orderBy('sort_order')
            ->orderBy('name_en');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get display name (English with icon).
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->icon . ' ' . $this->name_en;
    }

    /**
     * Get display name in Malayalam.
     */
    public function getDisplayNameMlAttribute(): string
    {
        return $this->icon . ' ' . $this->name_ml;
    }

    /**
     * Get bilingual display name.
     */
    public function getBilingualNameAttribute(): string
    {
        return $this->icon . ' ' . $this->name_en . ' (' . $this->name_ml . ')';
    }

    /**
     * Get tier label.
     */
    public function getTierLabelAttribute(): string
    {
        return $this->tier === self::TIER_ZERO_SKILLS ? 'Zero Skills' : 'Basic Skills';
    }

    /**
     * Get tier label in Malayalam.
     */
    public function getTierLabelMlAttribute(): string
    {
        return $this->tier === self::TIER_ZERO_SKILLS ? 'കഴിവ് വേണ്ട' : 'അടിസ്ഥാന കഴിവ്';
    }

    /**
     * Get typical pay range display.
     */
    public function getPayRangeAttribute(): ?string
    {
        if (!$this->typical_pay_min && !$this->typical_pay_max) {
            return null;
        }

        if ($this->typical_pay_min && $this->typical_pay_max) {
            return '₹' . number_format($this->typical_pay_min) .
                ' - ₹' . number_format($this->typical_pay_max);
        }

        if ($this->typical_pay_min) {
            return 'From ₹' . number_format($this->typical_pay_min);
        }

        return 'Up to ₹' . number_format($this->typical_pay_max);
    }

    /**
     * Get duration display.
     */
    public function getDurationDisplayAttribute(): ?string
    {
        if (!$this->typical_duration_hours) {
            return null;
        }

        $hours = $this->typical_duration_hours;
        if ($hours < 1) {
            return (int)($hours * 60) . ' mins';
        }

        return $hours . ' hrs';
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get display name based on locale.
     */
    public function getDisplayName(string $locale = 'en'): string
    {
        return $locale === 'ml' ? $this->display_name_ml : $this->display_name;
    }

    /**
     * Get suggested pay range as array.
     */
    public function getSuggestedPayRange(): array
    {
        return [
            'min' => $this->typical_pay_min ?? 0,
            'max' => $this->typical_pay_max ?? 0,
        ];
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        $description = $this->name_ml;
        if ($this->pay_range) {
            $description .= ' • ' . $this->pay_range;
        }

        return [
            'id' => 'job_cat_' . $this->id,
            'title' => substr($this->display_name, 0, 24),
            'description' => substr($description, 0, 72),
        ];
    }

    /**
     * Get list sections for WhatsApp grouped by tier.
     */
    public static function getListSections(): array
    {
        $categories = self::active()
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        $tier1 = $categories->where('tier', self::TIER_ZERO_SKILLS)
            ->map(fn($cat) => $cat->toListItem())->values()->toArray();

        $tier2 = $categories->where('tier', self::TIER_BASIC_SKILLS)
            ->map(fn($cat) => $cat->toListItem())->values()->toArray();

        $sections = [];

        if (count($tier1) > 0) {
            $sections[] = [
                'title' => '🟢 Zero Skills Required',
                'rows' => array_slice($tier1, 0, 10),
            ];
        }

        if (count($tier2) > 0) {
            $sections[] = [
                'title' => '🔵 Basic Skills Required',
                'rows' => array_slice($tier2, 0, 10),
            ];
        }

        return $sections;
    }

    /**
     * Find by list item ID.
     */
    public static function findByListId(string $listId): ?self
    {
        $id = str_replace('job_cat_', '', $listId);
        return self::find($id);
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
            if (empty($model->slug)) {
                $model->slug = Str::slug($model->name_en);
            }
        });
    }
}