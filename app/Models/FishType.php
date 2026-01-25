<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Str;

/**
 * Fish Type Model - Master data for fish varieties.
 *
 * @property int $id
 * @property string $name_en
 * @property string $name_ml
 * @property string $slug
 * @property string $category
 * @property array|null $aliases
 * @property array|null $peak_seasons
 * @property bool $is_seasonal
 * @property float|null $typical_price_min
 * @property float|null $typical_price_max
 * @property string $emoji
 * @property string|null $image_url
 * @property int $sort_order
 * @property bool $is_popular
 * @property bool $is_active
 *
 * @srs-ref Section 2.4 - Fish Types Master Data
 */
class FishType extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'name_en',
        'name_ml',
        'slug',
        'category',
        'aliases',
        'peak_seasons',
        'is_seasonal',
        'typical_price_min',
        'typical_price_max',
        'emoji',
        'image_url',
        'sort_order',
        'is_popular',
        'is_active',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'aliases' => 'array',
        'peak_seasons' => 'array',
        'is_seasonal' => 'boolean',
        'typical_price_min' => 'decimal:2',
        'typical_price_max' => 'decimal:2',
        'is_popular' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Fish categories.
     */
    public const CATEGORY_SEA_FISH = 'sea_fish';
    public const CATEGORY_FRESHWATER = 'freshwater';
    public const CATEGORY_SHELLFISH = 'shellfish';
    public const CATEGORY_CRUSTACEAN = 'crustacean';

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get all catches of this fish type.
     */
    public function catches(): HasMany
    {
        return $this->hasMany(FishCatch::class);
    }

    public function subscriptions(): BelongsToMany
    {
        return $this->belongsToMany(FishSubscription::class, 'fish_subscription_fish_type');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter active fish types.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope to filter popular fish types.
     */
    public function scopePopular(Builder $query): Builder
    {
        return $query->where('is_popular', true);
    }

    /**
     * Scope to filter by category.
     */
    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Scope to filter by current season.
     */
    public function scopeInSeason(Builder $query): Builder
    {
        $currentMonth = (int) now()->format('n');

        return $query->where(function ($q) use ($currentMonth) {
            $q->where('is_seasonal', false)
                ->orWhereJsonContains('peak_seasons', $currentMonth);
        });
    }

    /**
     * Scope for list selection (active, ordered).
     */
    public function scopeForSelection(Builder $query): Builder
    {
        return $query->active()
            ->orderBy('is_popular', 'desc')
            ->orderBy('sort_order')
            ->orderBy('name_en');
    }

    /**
     * Scope to search by name or alias.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = strtolower($term);

        return $query->where(function ($q) use ($term) {
            $q->whereRaw('LOWER(name_en) LIKE ?', ["%{$term}%"])
                ->orWhereRaw('LOWER(name_ml) LIKE ?', ["%{$term}%"])
                ->orWhereRaw('JSON_SEARCH(LOWER(aliases), "one", ?) IS NOT NULL', ["%{$term}%"]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get display name (English with emoji).
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->emoji . ' ' . $this->name_en;
    }

    /**
     * Get display name in Malayalam.
     */
    public function getDisplayNameMlAttribute(): string
    {
        return $this->emoji . ' ' . $this->name_ml;
    }

    /**
     * Get bilingual display name.
     */
    public function getBilingualNameAttribute(): string
    {
        return $this->emoji . ' ' . $this->name_en . ' (' . $this->name_ml . ')';
    }

    /**
     * Get typical price range display.
     */
    public function getPriceRangeAttribute(): ?string
    {
        if (!$this->typical_price_min && !$this->typical_price_max) {
            return null;
        }

        if ($this->typical_price_min && $this->typical_price_max) {
            return '₹' . number_format($this->typical_price_min) .
                ' - ₹' . number_format($this->typical_price_max) . '/kg';
        }

        if ($this->typical_price_min) {
            return 'From ₹' . number_format($this->typical_price_min) . '/kg';
        }

        return 'Up to ₹' . number_format($this->typical_price_max) . '/kg';
    }

    /**
     * Check if fish is currently in season.
     */
    public function getIsInSeasonAttribute(): bool
    {
        if (!$this->is_seasonal) {
            return true;
        }

        $currentMonth = (int) now()->format('n');
        return in_array($currentMonth, $this->peak_seasons ?? []);
    }

    /**
     * Get category display name.
     */
    public function getCategoryDisplayAttribute(): string
    {
        return match ($this->category) {
            self::CATEGORY_SEA_FISH => '🌊 Sea Fish',
            self::CATEGORY_FRESHWATER => '🏞️ Freshwater',
            self::CATEGORY_SHELLFISH => '🐚 Shellfish',
            self::CATEGORY_CRUSTACEAN => '🦐 Crustacean',
            default => '🐟 Fish',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        $description = $this->name_ml;
        if ($this->is_in_season && $this->is_seasonal) {
            $description .= ' • In Season';
        }

        return [
            'id' => 'fish_' . $this->id,
            'title' => substr($this->display_name, 0, 24),
            'description' => substr($description, 0, 72),
        ];
    }

    /**
     * Get popular fish types as list items.
     */
    public static function getPopularListItems(int $limit = 10): array
    {
        return self::active()
            ->popular()
            ->orderBy('sort_order')
            ->limit($limit)
            ->get()
            ->map(fn($fish) => $fish->toListItem())
            ->toArray();
    }

    /**
     * Get all active fish types grouped by category.
     */
    public static function getGroupedByCategory(): array
    {
        $types = self::active()
            ->orderBy('sort_order')
            ->orderBy('name_en')
            ->get();

        return $types->groupBy('category')
            ->map(fn($group) => $group->map(fn($fish) => $fish->toListItem())->toArray())
            ->toArray();
    }

    /**
     * Get list sections for WhatsApp.
     */
    public static function getListSections(): array
    {
        $grouped = self::getGroupedByCategory();
        $sections = [];

        $categoryLabels = [
            self::CATEGORY_SEA_FISH => '🌊 Sea Fish',
            self::CATEGORY_FRESHWATER => '🏞️ Freshwater',
            self::CATEGORY_SHELLFISH => '🐚 Shellfish',
            self::CATEGORY_CRUSTACEAN => '🦐 Crustacean',
        ];

        foreach ($categoryLabels as $category => $label) {
            if (isset($grouped[$category]) && count($grouped[$category]) > 0) {
                $sections[] = [
                    'title' => $label,
                    'rows' => array_slice($grouped[$category], 0, 10),
                ];
            }
        }

        return $sections;
    }

    /**
     * Find by list item ID.
     */
    public static function findByListId(string $listId): ?self
    {
        $id = str_replace('fish_', '', $listId);
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
