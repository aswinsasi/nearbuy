<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Fish Type Model - Master data for fish varieties.
 *
 * SRS Section 2.4 - Fish Types Master Data:
 * - Sardine/Mathi, Mackerel/Ayala, Pearl Spot/Karimeen, Tuna/Choora
 * - Red Snapper/Nenmeen, Prawns/Konju, Crab/Njandu, Sole Fish/Manthal
 * - Seer Fish/Neymeen, Pomfret/Avoli
 *
 * Categories: sea_fish, fresh_water, shellfish
 *
 * @property int $id
 * @property string $name_en - English name (name_english in SRS)
 * @property string $name_ml - Malayalam name (name_malayalam in SRS)
 * @property string $slug
 * @property string $category - sea_fish, fresh_water, shellfish
 * @property string|null $season_peak - Peak availability months
 * @property string $emoji - Visual icon
 * @property bool $is_popular - Show first in lists
 * @property bool $is_active
 * @property int $sort_order
 * @property float|null $typical_price_min
 * @property float|null $typical_price_max
 *
 * @srs-ref Section 2.4 Fish Types Master Data
 * @srs-ref Section 5.1.1 fish_types table
 */
class FishType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name_en',
        'name_ml',
        'slug',
        'category',
        'season_peak',
        'emoji',
        'is_popular',
        'is_active',
        'sort_order',
        'typical_price_min',
        'typical_price_max',
    ];

    protected $casts = [
        'is_popular' => 'boolean',
        'is_active' => 'boolean',
        'typical_price_min' => 'decimal:2',
        'typical_price_max' => 'decimal:2',
    ];

    /**
     * Categories (SRS Section 2.4).
     */
    public const CATEGORY_SEA_FISH = 'sea_fish';
    public const CATEGORY_FRESH_WATER = 'fresh_water';
    public const CATEGORY_SHELLFISH = 'shellfish';

    /**
     * SRS Fish Types (Section 2.4).
     */
    public const SRS_FISH_TYPES = [
        // Sea Fish
        ['name_en' => 'Sardine', 'name_ml' => 'Mathi', 'emoji' => '🐟', 'category' => 'sea_fish', 'season_peak' => 'Jun-Sep', 'is_popular' => true],
        ['name_en' => 'Mackerel', 'name_ml' => 'Ayala', 'emoji' => '🐟', 'category' => 'sea_fish', 'season_peak' => 'Year-round', 'is_popular' => true],
        ['name_en' => 'Tuna', 'name_ml' => 'Choora', 'emoji' => '🐟', 'category' => 'sea_fish', 'season_peak' => 'Nov-Apr', 'is_popular' => true],
        ['name_en' => 'Red Snapper', 'name_ml' => 'Nenmeen', 'emoji' => '🐠', 'category' => 'sea_fish', 'season_peak' => 'Year-round', 'is_popular' => false],
        ['name_en' => 'Sole Fish', 'name_ml' => 'Manthal', 'emoji' => '🐟', 'category' => 'sea_fish', 'season_peak' => 'Year-round', 'is_popular' => false],
        ['name_en' => 'Seer Fish', 'name_ml' => 'Neymeen', 'emoji' => '🐟', 'category' => 'sea_fish', 'season_peak' => 'Oct-Mar', 'is_popular' => true],
        ['name_en' => 'Pomfret', 'name_ml' => 'Avoli', 'emoji' => '🐠', 'category' => 'sea_fish', 'season_peak' => 'Sep-Feb', 'is_popular' => true],
        
        // Fresh Water
        ['name_en' => 'Pearl Spot', 'name_ml' => 'Karimeen', 'emoji' => '🐠', 'category' => 'fresh_water', 'season_peak' => 'Oct-Mar', 'is_popular' => true],
        
        // Shellfish (includes crustaceans)
        ['name_en' => 'Prawns', 'name_ml' => 'Konju', 'emoji' => '🦐', 'category' => 'shellfish', 'season_peak' => 'Aug-Dec', 'is_popular' => true],
        ['name_en' => 'Crab', 'name_ml' => 'Njandu', 'emoji' => '🦀', 'category' => 'shellfish', 'season_peak' => 'Year-round', 'is_popular' => true],
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    public function catches(): HasMany
    {
        return $this->hasMany(FishCatch::class);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Active fish types only.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Popular fish types (PM-011: show first in lists).
     */
    public function scopePopular(Builder $query): Builder
    {
        return $query->where('is_popular', true);
    }

    /**
     * Filter by category.
     */
    public function scopeByCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Alias for byCategory.
     */
    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        return $this->scopeByCategory($query, $category);
    }

    /**
     * Order by popularity then sort_order.
     */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_popular')
            ->orderBy('sort_order')
            ->orderBy('name_en');
    }

    /**
     * For selection lists (active + ordered).
     */
    public function scopeForSelection(Builder $query): Builder
    {
        return $query->active()->ordered();
    }

    /**
     * Search by name.
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = strtolower($term);
        return $query->where(function ($q) use ($term) {
            $q->whereRaw('LOWER(name_en) LIKE ?', ["%{$term}%"])
                ->orWhereRaw('LOWER(name_ml) LIKE ?', ["%{$term}%"]);
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Display name with emoji.
     */
    public function getDisplayNameAttribute(): string
    {
        return $this->emoji . ' ' . $this->name_en;
    }

    /**
     * Display name in Malayalam.
     */
    public function getDisplayNameMlAttribute(): string
    {
        return $this->emoji . ' ' . $this->name_ml;
    }

    /**
     * Bilingual name: "🐟 Sardine (Mathi)".
     */
    public function getBilingualNameAttribute(): string
    {
        return $this->emoji . ' ' . $this->name_en . ' (' . $this->name_ml . ')';
    }

    /**
     * Category display.
     */
    public function getCategoryDisplayAttribute(): string
    {
        return match ($this->category) {
            self::CATEGORY_SEA_FISH => '🌊 Sea Fish',
            self::CATEGORY_FRESH_WATER => '🏞️ Fresh Water',
            self::CATEGORY_SHELLFISH => '🦐 Shellfish',
            default => '🐟 Fish',
        };
    }

    /**
     * Price range display.
     */
    public function getPriceRangeAttribute(): ?string
    {
        if (!$this->typical_price_min && !$this->typical_price_max) {
            return null;
        }
        if ($this->typical_price_min && $this->typical_price_max) {
            return '₹' . (int) $this->typical_price_min . '-' . (int) $this->typical_price_max . '/kg';
        }
        return $this->typical_price_min 
            ? '₹' . (int) $this->typical_price_min . '+/kg'
            : '~₹' . (int) $this->typical_price_max . '/kg';
    }

    /**
     * List item ID.
     */
    public function getListIdAttribute(): string
    {
        return 'fish_' . $this->id;
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
        return [
            'id' => $this->list_id,
            'title' => substr($this->display_name, 0, 24),
            'description' => substr($this->name_ml . ($this->season_peak ? ' • ' . $this->season_peak : ''), 0, 72),
        ];
    }

    /**
     * Find by list ID.
     */
    public static function findByListId(string $listId): ?self
    {
        $id = (int) str_replace('fish_', '', $listId);
        return $id > 0 ? self::find($id) : null;
    }

    /**
     * Get popular fish as list items.
     */
    public static function getPopularListItems(int $limit = 8): array
    {
        return self::active()
            ->popular()
            ->ordered()
            ->limit($limit)
            ->get()
            ->map(fn($f) => $f->toListItem())
            ->toArray();
    }

    /**
     * Get fish grouped by category.
     */
    public static function getGroupedByCategory(): array
    {
        return self::active()
            ->ordered()
            ->get()
            ->groupBy('category')
            ->map(fn($group) => $group->map(fn($f) => $f->toListItem())->toArray())
            ->toArray();
    }

    /**
     * Get WhatsApp list sections by category.
     */
    public static function getListSections(): array
    {
        $grouped = self::getGroupedByCategory();
        $sections = [];

        $labels = [
            self::CATEGORY_SEA_FISH => '🌊 Sea Fish',
            self::CATEGORY_FRESH_WATER => '🏞️ Fresh Water',
            self::CATEGORY_SHELLFISH => '🦐 Shellfish',
        ];

        foreach ($labels as $cat => $label) {
            if (!empty($grouped[$cat])) {
                $sections[] = [
                    'title' => $label,
                    'rows' => array_slice($grouped[$cat], 0, 10),
                ];
            }
        }

        return $sections;
    }

    /**
     * Seed SRS fish types.
     */
    public static function seedSrsTypes(): void
    {
        foreach (self::SRS_FISH_TYPES as $i => $data) {
            self::updateOrCreate(
                ['name_en' => $data['name_en']],
                array_merge($data, [
                    'slug' => Str::slug($data['name_en']),
                    'sort_order' => $i + 1,
                    'is_active' => true,
                ])
            );
        }
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
            if (empty($model->emoji)) {
                $model->emoji = '🐟';
            }
        });
    }
}