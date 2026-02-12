<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * Worker Badge Model - Gamification for viral mechanics.
 *
 * SRS Section 3.5 Badges:
 * - First Job ✅ (1 job completed)
 * - Queue Master 🏆 (10 queue jobs)
 * - Speed Runner 🏃 (5 deliveries)
 * - Reliable ⭐ (10 five-star ratings)
 * - Veteran 👑 (50 jobs)
 * - Top Earner 💰 (₹10,000+ in one week)
 *
 * @property int $id
 * @property int $worker_id
 * @property string $badge_type
 * @property \Carbon\Carbon $earned_at
 *
 * @srs-ref Section 3.5 - Badge System
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class WorkerBadge extends Model
{
    use HasFactory;

    public $timestamps = false;

    /*
    |--------------------------------------------------------------------------
    | Badge Type Constants (SRS Section 3.5)
    |--------------------------------------------------------------------------
    */

    public const FIRST_JOB = 'first_job';
    public const QUEUE_MASTER = 'queue_master';
    public const SPEED_RUNNER = 'speed_runner';
    public const RELIABLE = 'reliable';
    public const VETERAN = 'veteran';
    public const TOP_EARNER = 'top_earner';

    /**
     * Badge definitions with requirements per SRS.
     */
    public const BADGES = [
        self::FIRST_JOB => [
            'label' => 'First Step',
            'label_ml' => 'ആദ്യ ചുവട്',
            'emoji' => '✅',
            'description' => 'Completed first job',
            'description_ml' => 'ആദ്യ ജോലി പൂർത്തിയാക്കി',
            'requirement' => ['type' => 'total_jobs', 'count' => 1],
        ],
        self::QUEUE_MASTER => [
            'label' => 'Queue Master',
            'label_ml' => 'ക്യൂ മാസ്റ്റർ',
            'emoji' => '🏆',
            'description' => '10 queue standing jobs completed',
            'description_ml' => '10 ക്യൂ നിൽക്കൽ ജോലികൾ',
            'requirement' => ['type' => 'category_jobs', 'category' => 'queue_standing', 'count' => 10],
        ],
        self::SPEED_RUNNER => [
            'label' => 'Speed Runner',
            'label_ml' => 'സ്പീഡ് റണ്ണർ',
            'emoji' => '🏃',
            'description' => '5 delivery jobs completed',
            'description_ml' => '5 ഡെലിവറി ജോലികൾ',
            'requirement' => ['type' => 'category_jobs', 'category' => 'delivery', 'count' => 5],
        ],
        self::RELIABLE => [
            'label' => 'Reliable',
            'label_ml' => 'വിശ്വസ്തൻ',
            'emoji' => '⭐',
            'description' => '10 five-star ratings received',
            'description_ml' => '10 അഞ്ച്-സ്റ്റാർ റേറ്റിംഗ്',
            'requirement' => ['type' => 'five_star_count', 'count' => 10],
        ],
        self::VETERAN => [
            'label' => 'Veteran',
            'label_ml' => 'വെറ്ററൻ',
            'emoji' => '👑',
            'description' => '50 jobs completed',
            'description_ml' => '50 ജോലികൾ പൂർത്തിയാക്കി',
            'requirement' => ['type' => 'total_jobs', 'count' => 50],
        ],
        self::TOP_EARNER => [
            'label' => 'Top Earner',
            'label_ml' => 'ടോപ് ഏർണർ',
            'emoji' => '💰',
            'description' => '₹10,000+ earned in one week',
            'description_ml' => 'ഒരു ആഴ്ച ₹10,000+ സമ്പാദിച്ചു',
            'requirement' => ['type' => 'weekly_earnings', 'amount' => 10000],
        ],
    ];

    protected $fillable = [
        'worker_id',
        'badge_type',
        'earned_at',
    ];

    protected $casts = [
        'earned_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
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

    public function scopeByWorker(Builder $query, int $workerId): Builder
    {
        return $query->where('worker_id', $workerId);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('badge_type', $type);
    }

    public function scopeRecent(Builder $query): Builder
    {
        return $query->orderByDesc('earned_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    public function getLabelAttribute(): string
    {
        return self::BADGES[$this->badge_type]['label'] ?? 'Unknown';
    }

    public function getLabelMlAttribute(): string
    {
        return self::BADGES[$this->badge_type]['label_ml'] ?? 'Unknown';
    }

    public function getEmojiAttribute(): string
    {
        return self::BADGES[$this->badge_type]['emoji'] ?? '🏅';
    }

    public function getDescriptionAttribute(): string
    {
        return self::BADGES[$this->badge_type]['description'] ?? '';
    }

    public function getDescriptionMlAttribute(): string
    {
        return self::BADGES[$this->badge_type]['description_ml'] ?? '';
    }

    public function getDisplayAttribute(): string
    {
        return $this->emoji . ' ' . $this->label;
    }

    public function getRequirementAttribute(): array
    {
        return self::BADGES[$this->badge_type]['requirement'] ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | Static Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Award badge to worker (if not already earned).
     */
    public static function award(int $workerId, string $badgeType): ?self
    {
        if (!isset(self::BADGES[$badgeType])) {
            return null;
        }

        if (self::hasBadge($workerId, $badgeType)) {
            return null;
        }

        return self::create([
            'worker_id' => $workerId,
            'badge_type' => $badgeType,
            'earned_at' => now(),
        ]);
    }

    /**
     * Check if worker has badge.
     */
    public static function hasBadge(int $workerId, string $badgeType): bool
    {
        return self::where('worker_id', $workerId)
            ->where('badge_type', $badgeType)
            ->exists();
    }

    /**
     * Get all badge types.
     */
    public static function allTypes(): array
    {
        return array_keys(self::BADGES);
    }

    /**
     * Get badge info.
     */
    public static function getBadgeInfo(string $type): ?array
    {
        return self::BADGES[$type] ?? null;
    }

    /**
     * Format for notification message.
     */
    public function toNotificationText(): string
    {
        return "🏆 *Badge earned!*\n" .
            "{$this->emoji} *{$this->label}*\n" .
            "{$this->description} 💪";
    }

    /**
     * Format for shareable text.
     */
    public function toShareText(string $workerName): string
    {
        return "🎉 {$workerName} just earned the {$this->display} badge on NearBuy!\n" .
            "{$this->description}\n\n" .
            "#NjaanumPanikkar #NearBuy #Kerala";
    }
}