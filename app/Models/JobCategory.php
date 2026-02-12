<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * Job Category Model - Master data for job types.
 *
 * Tier 1 (Zero Skills): Queue Standing, Parcel Delivery, Grocery Shopping,
 *   Bill Payment, Moving Help, Event Helper, Pet Walking, Garden Cleaning
 *
 * Tier 2 (Basic Skills): Food Delivery, Document Work, Typing, Translation, Photography
 *
 * @property int $id
 * @property string $name_en
 * @property string $name_ml
 * @property string $slug
 * @property int $tier (1 = Zero Skills, 2 = Basic Skills)
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
 * @srs-ref Section 3.3.1 - Job Categories (Tier 1 + Tier 2)
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobCategory extends Model
{
    use HasFactory;

    protected $table = 'job_categories';

    /**
     * Tier constants.
     */
    public const TIER_ZERO_SKILLS = 1;
    public const TIER_BASIC_SKILLS = 2;

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
        'sort_order' => 'integer',
    ];

    /**
     * Default values.
     */
    protected $attributes = [
        'tier' => 1,
        'is_active' => true,
        'is_popular' => false,
        'requires_vehicle' => false,
        'sort_order' => 0,
    ];

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
     * Get active job posts.
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
     * Get name (alias for name_en).
     */
    public function getNameAttribute(): string
    {
        return $this->name_en ?? '';
    }

    /**
     * Get display name with icon.
     */
    public function getDisplayNameAttribute(): string
    {
        return ($this->icon ?? '📋') . ' ' . ($this->name_en ?? '');
    }

    /**
     * Get display name in Malayalam.
     */
    public function getDisplayNameMlAttribute(): string
    {
        return ($this->icon ?? '📋') . ' ' . ($this->name_ml ?? '');
    }

    /**
     * Get bilingual display name.
     */
    public function getBilingualNameAttribute(): string
    {
        $icon = $this->icon ?? '📋';
        $en = $this->name_en ?? '';
        $ml = $this->name_ml ?? '';
        
        return $ml ? "{$icon} {$en} ({$ml})" : "{$icon} {$en}";
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
            return (int) ($hours * 60) . ' mins';
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
            'min' => (float) ($this->typical_pay_min ?? 100),
            'max' => (float) ($this->typical_pay_max ?? 500),
        ];
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        $description = $this->name_ml ?? '';
        if ($this->pay_range) {
            $description .= ($description ? ' • ' : '') . $this->pay_range;
        }

        return [
            'id' => 'cat_' . $this->id,
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
            ->map(fn($cat) => $cat->toListItem())
            ->values()
            ->toArray();

        $tier2 = $categories->where('tier', self::TIER_BASIC_SKILLS)
            ->map(fn($cat) => $cat->toListItem())
            ->values()
            ->toArray();

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
        $id = str_replace('cat_', '', $listId);
        return is_numeric($id) ? self::find($id) : null;
    }

    /**
     * Get all as simple array for WhatsApp list.
     */
    public static function getForWhatsAppList(int $limit = 9): array
    {
        return self::active()
            ->orderBy('tier')
            ->orderBy('sort_order')
            ->limit($limit)
            ->get()
            ->map(fn($cat) => $cat->toListItem())
            ->toArray();
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
            if (empty($model->slug) && $model->name_en) {
                $model->slug = Str::slug($model->name_en);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Seeder Data - SRS Categories
    |--------------------------------------------------------------------------
    */

    /**
     * Get SRS-defined categories for seeding.
     *
     * @srs-ref Section 3.3.1 - Job Categories
     */
    public static function getSrsCategories(): array
    {
        return [
            // Tier 1: Zero Skills Required
            [
                'name_en' => 'Queue Standing',
                'name_ml' => 'ക്യൂ നിൽക്കൽ',
                'slug' => 'queue',
                'icon' => '⏱️',
                'tier' => self::TIER_ZERO_SKILLS,
                'typical_pay_min' => 100,
                'typical_pay_max' => 200,
                'typical_duration_hours' => 2,
                'requires_vehicle' => false,
                'is_popular' => true,
                'sort_order' => 1,
            ],
            [
                'name_en' => 'Parcel Delivery',
                'name_ml' => 'പാഴ്സൽ എടുക്കൽ',
                'slug' => 'delivery',
                'icon' => '📦',
                'tier' => self::TIER_ZERO_SKILLS,
                'typical_pay_min' => 50,
                'typical_pay_max' => 150,
                'typical_duration_hours' => 1,
                'requires_vehicle' => true,
                'is_popular' => true,
                'sort_order' => 2,
            ],
            [
                'name_en' => 'Grocery Shopping',
                'name_ml' => 'സാധനം വാങ്ങൽ',
                'slug' => 'shopping',
                'icon' => '🛒',
                'tier' => self::TIER_ZERO_SKILLS,
                'typical_pay_min' => 80,
                'typical_pay_max' => 150,
                'typical_duration_hours' => 1.5,
                'requires_vehicle' => false,
                'is_popular' => true,
                'sort_order' => 3,
            ],
            [
                'name_en' => 'Bill Payment',
                'name_ml' => 'ബിൽ അടയ്ക്കൽ',
                'slug' => 'bill',
                'icon' => '💳',
                'tier' => self::TIER_ZERO_SKILLS,
                'typical_pay_min' => 50,
                'typical_pay_max' => 100,
                'typical_duration_hours' => 1,
                'requires_vehicle' => false,
                'sort_order' => 4,
            ],
            [
                'name_en' => 'Moving Help',
                'name_ml' => 'സാധനം എടുക്കാൻ',
                'slug' => 'moving',
                'icon' => '🏋️',
                'tier' => self::TIER_ZERO_SKILLS,
                'typical_pay_min' => 200,
                'typical_pay_max' => 500,
                'typical_duration_hours' => 3,
                'requires_vehicle' => false,
                'sort_order' => 5,
            ],
            [
                'name_en' => 'Event Helper',
                'name_ml' => 'ചടങ്ങിൽ സഹായം',
                'slug' => 'event',
                'icon' => '🎉',
                'tier' => self::TIER_ZERO_SKILLS,
                'typical_pay_min' => 300,
                'typical_pay_max' => 500,
                'typical_duration_hours' => 5,
                'requires_vehicle' => false,
                'sort_order' => 6,
            ],
            [
                'name_en' => 'Pet Walking',
                'name_ml' => 'നായയെ നടത്തൽ',
                'slug' => 'pet',
                'icon' => '🐕',
                'tier' => self::TIER_ZERO_SKILLS,
                'typical_pay_min' => 100,
                'typical_pay_max' => 200,
                'typical_duration_hours' => 1,
                'requires_vehicle' => false,
                'sort_order' => 7,
            ],
            [
                'name_en' => 'Garden Cleaning',
                'name_ml' => 'തോട്ടം വൃത്തിയാക്കൽ',
                'slug' => 'garden',
                'icon' => '🌿',
                'tier' => self::TIER_ZERO_SKILLS,
                'typical_pay_min' => 200,
                'typical_pay_max' => 400,
                'typical_duration_hours' => 2.5,
                'requires_vehicle' => false,
                'sort_order' => 8,
            ],
            // Tier 2: Basic Skills Required
            [
                'name_en' => 'Food Delivery',
                'name_ml' => 'ഭക്ഷണം എത്തിക്കൽ',
                'slug' => 'food',
                'icon' => '🍕',
                'tier' => self::TIER_BASIC_SKILLS,
                'typical_pay_min' => 50,
                'typical_pay_max' => 100,
                'typical_duration_hours' => 0.5,
                'requires_vehicle' => true,
                'sort_order' => 10,
            ],
            [
                'name_en' => 'Document Work',
                'name_ml' => 'ഡോക്യുമെന്റ് പണി',
                'slug' => 'document',
                'icon' => '📄',
                'tier' => self::TIER_BASIC_SKILLS,
                'typical_pay_min' => 50,
                'typical_pay_max' => 100,
                'typical_duration_hours' => 1,
                'requires_vehicle' => false,
                'sort_order' => 11,
            ],
            [
                'name_en' => 'Computer Typing',
                'name_ml' => 'ടൈപ്പിംഗ്',
                'slug' => 'typing',
                'icon' => '⌨️',
                'tier' => self::TIER_BASIC_SKILLS,
                'typical_pay_min' => 100,
                'typical_pay_max' => 300,
                'typical_duration_hours' => 2,
                'requires_vehicle' => false,
                'sort_order' => 12,
            ],
            [
                'name_en' => 'Translation Help',
                'name_ml' => 'തർജ്ജമ',
                'slug' => 'translation',
                'icon' => '🗣️',
                'tier' => self::TIER_BASIC_SKILLS,
                'typical_pay_min' => 200,
                'typical_pay_max' => 500,
                'typical_duration_hours' => 2,
                'requires_vehicle' => false,
                'sort_order' => 13,
            ],
            [
                'name_en' => 'Basic Photography',
                'name_ml' => 'ഫോട്ടോ എടുക്കൽ',
                'slug' => 'photo',
                'icon' => '📸',
                'tier' => self::TIER_BASIC_SKILLS,
                'typical_pay_min' => 200,
                'typical_pay_max' => 500,
                'typical_duration_hours' => 2,
                'requires_vehicle' => false,
                'sort_order' => 14,
            ],
        ];
    }
}