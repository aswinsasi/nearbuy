<?php

namespace App\Models;

use App\Enums\BadgeType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Worker Badge Model - Gamification badges for workers.
 *
 * @property int $id
 * @property int $worker_id
 * @property BadgeType $badge_type
 * @property string|null $badge_name
 * @property string $badge_icon
 * @property array|null $achievement_data
 * @property \Carbon\Carbon $earned_at
 *
 * @srs-ref Section 3.6 - Worker Gamification
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class WorkerBadge extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'worker_id',
        'badge_type',
        'badge_name',
        'badge_icon',
        'achievement_data',
        'earned_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'badge_type' => BadgeType::class,
        'achievement_data' => 'array',
        'earned_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the worker who earned this badge.
     */
    public function worker(): BelongsTo
    {
        return $this->belongsTo(JobWorker::class, 'worker_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter by badge type.
     */
    public function scopeOfType(Builder $query, BadgeType $type): Builder
    {
        return $query->where('badge_type', $type);
    }

    /**
     * Scope to filter by worker.
     */
    public function scopeByWorker(Builder $query, int $workerId): Builder
    {
        return $query->where('worker_id', $workerId);
    }

    /**
     * Scope to order by most recent.
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('earned_at', 'desc');
    }

    /**
     * Scope to filter by category.
     */
    public function scopeOfCategory(Builder $query, string $category): Builder
    {
        $badgeTypes = BadgeType::byCategory($category);
        return $query->whereIn('badge_type', $badgeTypes);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get badge display with icon.
     */
    public function getDisplayAttribute(): string
    {
        return $this->badge_type->display();
    }

    /**
     * Get badge label.
     */
    public function getLabelAttribute(): string
    {
        return $this->badge_name ?? $this->badge_type->label();
    }

    /**
     * Get badge icon.
     */
    public function getIconAttribute(): string
    {
        return $this->badge_icon ?? $this->badge_type->emoji();
    }

    /**
     * Get badge description.
     */
    public function getDescriptionAttribute(): string
    {
        return $this->badge_type->description();
    }

    /**
     * Get badge tier.
     */
    public function getTierAttribute(): int
    {
        return $this->badge_type->tier();
    }

    /**
     * Get tier label.
     */
    public function getTierLabelAttribute(): string
    {
        return $this->badge_type->tierLabel();
    }

    /**
     * Get time since earned.
     */
    public function getEarnedAgoAttribute(): string
    {
        return $this->earned_at->diffForHumans();
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Award badge to a worker.
     */
    public static function awardBadge(JobWorker $worker, BadgeType $badgeType, ?array $achievementData = null): ?self
    {
        // Check if worker already has this badge
        if (self::where('worker_id', $worker->id)->where('badge_type', $badgeType)->exists()) {
            return null;
        }

        return self::create([
            'worker_id' => $worker->id,
            'badge_type' => $badgeType,
            'badge_name' => $badgeType->label(),
            'badge_icon' => $badgeType->emoji(),
            'achievement_data' => $achievementData,
            'earned_at' => now(),
        ]);
    }

    /**
     * Check if a worker qualifies for a badge.
     */
    public static function checkAndAwardBadge(JobWorker $worker, BadgeType $badgeType): ?self
    {
        // Already has badge
        if (self::where('worker_id', $worker->id)->where('badge_type', $badgeType)->exists()) {
            return null;
        }

        $requirement = $badgeType->requirement();
        $qualified = false;
        $achievementData = [];

        switch ($requirement['type'] ?? '') {
            case 'total_jobs':
                $qualified = $worker->jobs_completed >= $requirement['count'];
                $achievementData = ['jobs_completed' => $worker->jobs_completed];
                break;

            case 'weekly_earnings':
                // Check current week earnings
                $weekEarnings = $worker->earnings()
                    ->where('week_start', now()->startOfWeek()->toDateString())
                    ->value('total_earnings') ?? 0;
                $qualified = $weekEarnings >= $requirement['amount'];
                $achievementData = ['weekly_earnings' => $weekEarnings];
                break;

            case 'rating_streak':
                $qualified = $worker->rating >= $requirement['rating'] 
                    && $worker->rating_count >= $requirement['count'];
                $achievementData = [
                    'rating' => $worker->rating,
                    'rating_count' => $worker->rating_count,
                ];
                break;

            // Add more badge type checks as needed
        }

        if ($qualified) {
            return self::awardBadge($worker, $badgeType, $achievementData);
        }

        return null;
    }

    /**
     * Check all milestone badges for a worker.
     */
    public static function checkMilestoneBadges(JobWorker $worker): array
    {
        $awarded = [];

        foreach (BadgeType::milestones() as $badgeType) {
            $badge = self::checkAndAwardBadge($worker, $badgeType);
            if ($badge) {
                $awarded[] = $badge;
            }
        }

        return $awarded;
    }

    /**
     * Convert to display format.
     */
    public function toDisplayFormat(): array
    {
        return [
            'type' => $this->badge_type->value,
            'name' => $this->label,
            'icon' => $this->icon,
            'description' => $this->description,
            'tier' => $this->tier,
            'tier_label' => $this->tier_label,
            'earned_at' => $this->earned_at->format('M j, Y'),
            'earned_ago' => $this->earned_ago,
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
            if (empty($model->earned_at)) {
                $model->earned_at = now();
            }

            if (empty($model->badge_name)) {
                $model->badge_name = $model->badge_type->label();
            }

            if (empty($model->badge_icon)) {
                $model->badge_icon = $model->badge_type->emoji();
            }
        });
    }
}