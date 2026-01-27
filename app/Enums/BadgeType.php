<?php

namespace App\Enums;

/**
 * Worker badge types for gamification.
 *
 * @srs-ref Section 3.6 - Worker Gamification
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum BadgeType: string
{
    // Performance badges
    case QUEUE_MASTER = 'queue_master';
    case SPEED_RUNNER = 'speed_runner';
    case HELPFUL_HAND = 'helpful_hand';
    case EARLY_BIRD = 'early_bird';
    case FIVE_STAR = 'five_star';
    case TOP_EARNER = 'top_earner';

    // Milestone badges
    case FIRST_JOB = 'first_job';
    case TEN_JOBS = 'ten_jobs';
    case FIFTY_JOBS = 'fifty_jobs';
    case HUNDRED_JOBS = 'hundred_jobs';

    // Reliability badges
    case PUNCTUAL = 'punctual';
    case RELIABLE = 'reliable';
    case TRUSTED = 'trusted';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::QUEUE_MASTER => 'Queue Master',
            self::SPEED_RUNNER => 'Speed Runner',
            self::HELPFUL_HAND => 'Helpful Hand',
            self::EARLY_BIRD => 'Early Bird',
            self::FIVE_STAR => 'Five Star Worker',
            self::TOP_EARNER => 'Top Earner',
            self::FIRST_JOB => 'First Job',
            self::TEN_JOBS => '10 Jobs Completed',
            self::FIFTY_JOBS => '50 Jobs Completed',
            self::HUNDRED_JOBS => '100 Jobs Completed',
            self::PUNCTUAL => 'Always On Time',
            self::RELIABLE => 'Super Reliable',
            self::TRUSTED => 'Trusted Worker',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::QUEUE_MASTER => 'ക്യൂ മാസ്റ്റർ',
            self::SPEED_RUNNER => 'സ്പീഡ് റണ്ണർ',
            self::HELPFUL_HAND => 'സഹായകരം',
            self::EARLY_BIRD => 'നേരത്തെ എത്തുന്നയാൾ',
            self::FIVE_STAR => 'അഞ്ച് നക്ഷത്ര പണിക്കാരൻ',
            self::TOP_EARNER => 'ടോപ്പ് എർണർ',
            self::FIRST_JOB => 'ആദ്യ പണി',
            self::TEN_JOBS => '10 പണി പൂർത്തിയായി',
            self::FIFTY_JOBS => '50 പണി പൂർത്തിയായി',
            self::HUNDRED_JOBS => '100 പണി പൂർത്തിയായി',
            self::PUNCTUAL => 'സമയനിഷ്ഠ',
            self::RELIABLE => 'വിശ്വസനീയൻ',
            self::TRUSTED => 'വിശ്വസ്ത പണിക്കാരൻ',
        };
    }

    /**
     * Get emoji icon.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::QUEUE_MASTER => '🧍‍♂️',
            self::SPEED_RUNNER => '⚡',
            self::HELPFUL_HAND => '🤝',
            self::EARLY_BIRD => '🐦',
            self::FIVE_STAR => '⭐',
            self::TOP_EARNER => '💰',
            self::FIRST_JOB => '🎉',
            self::TEN_JOBS => '🔟',
            self::FIFTY_JOBS => '🏅',
            self::HUNDRED_JOBS => '💯',
            self::PUNCTUAL => '⏰',
            self::RELIABLE => '💪',
            self::TRUSTED => '🛡️',
        };
    }

    /**
     * Get display with emoji.
     */
    public function display(): string
    {
        return $this->emoji() . ' ' . $this->label();
    }

    /**
     * Get description of how to earn the badge.
     */
    public function description(): string
    {
        return match ($this) {
            self::QUEUE_MASTER => 'Complete 10+ queue standing jobs',
            self::SPEED_RUNNER => 'Complete 5 jobs faster than estimated time',
            self::HELPFUL_HAND => 'Complete 20 jobs with positive reviews',
            self::EARLY_BIRD => 'Complete 5 jobs starting before 8 AM',
            self::FIVE_STAR => 'Maintain 5-star rating for 20+ jobs',
            self::TOP_EARNER => 'Earn ₹10,000+ in a single week',
            self::FIRST_JOB => 'Complete your first job',
            self::TEN_JOBS => 'Complete 10 jobs successfully',
            self::FIFTY_JOBS => 'Complete 50 jobs successfully',
            self::HUNDRED_JOBS => 'Complete 100 jobs successfully',
            self::PUNCTUAL => 'Arrive on time for 20 consecutive jobs',
            self::RELIABLE => 'Never cancel an accepted job for 30+ jobs',
            self::TRUSTED => 'Be verified and complete 50+ jobs with 4.5+ rating',
        };
    }

    /**
     * Get description in Malayalam.
     */
    public function descriptionMl(): string
    {
        return match ($this) {
            self::QUEUE_MASTER => '10+ ക്യൂ നിൽക്കൽ പണികൾ പൂർത്തിയാക്കുക',
            self::SPEED_RUNNER => '5 പണികൾ സമയത്തിനു മുമ്പ് തീർക്കുക',
            self::HELPFUL_HAND => 'നല്ല റിവ്യൂകളോടെ 20 പണികൾ പൂർത്തിയാക്കുക',
            self::EARLY_BIRD => '8 AM-ന് മുമ്പ് 5 പണികൾ തുടങ്ങുക',
            self::FIVE_STAR => '20+ പണികളിൽ 5-സ്റ്റാർ റേറ്റിംഗ് നിലനിർത്തുക',
            self::TOP_EARNER => 'ഒരു ആഴ്ചയിൽ ₹10,000+ സമ്പാദിക്കുക',
            self::FIRST_JOB => 'ആദ്യത്തെ പണി പൂർത്തിയാക്കുക',
            self::TEN_JOBS => '10 പണികൾ വിജയകരമായി പൂർത്തിയാക്കുക',
            self::FIFTY_JOBS => '50 പണികൾ വിജയകരമായി പൂർത്തിയാക്കുക',
            self::HUNDRED_JOBS => '100 പണികൾ വിജയകരമായി പൂർത്തിയാക്കുക',
            self::PUNCTUAL => 'തുടർച്ചയായി 20 പണികളിൽ സമയത്തിന് എത്തുക',
            self::RELIABLE => '30+ പണികളിൽ ഒരിക്കലും റദ്ദാക്കാതിരിക്കുക',
            self::TRUSTED => 'വെരിഫൈഡ് ആയി 50+ പണികൾ 4.5+ റേറ്റിംഗിൽ പൂർത്തിയാക്കുക',
        };
    }

    /**
     * Get requirement threshold.
     */
    public function requirement(): array
    {
        return match ($this) {
            self::QUEUE_MASTER => ['type' => 'category_jobs', 'category' => 'queue_standing', 'count' => 10],
            self::SPEED_RUNNER => ['type' => 'fast_completions', 'count' => 5],
            self::HELPFUL_HAND => ['type' => 'positive_reviews', 'count' => 20],
            self::EARLY_BIRD => ['type' => 'early_jobs', 'before_hour' => 8, 'count' => 5],
            self::FIVE_STAR => ['type' => 'rating_streak', 'rating' => 5.0, 'count' => 20],
            self::TOP_EARNER => ['type' => 'weekly_earnings', 'amount' => 10000],
            self::FIRST_JOB => ['type' => 'total_jobs', 'count' => 1],
            self::TEN_JOBS => ['type' => 'total_jobs', 'count' => 10],
            self::FIFTY_JOBS => ['type' => 'total_jobs', 'count' => 50],
            self::HUNDRED_JOBS => ['type' => 'total_jobs', 'count' => 100],
            self::PUNCTUAL => ['type' => 'on_time_streak', 'count' => 20],
            self::RELIABLE => ['type' => 'no_cancel_streak', 'count' => 30],
            self::TRUSTED => ['type' => 'verified_with_jobs', 'count' => 50, 'min_rating' => 4.5],
        };
    }

    /**
     * Get badge tier (1=bronze, 2=silver, 3=gold).
     */
    public function tier(): int
    {
        return match ($this) {
            self::FIRST_JOB => 1,
            self::TEN_JOBS,
            self::EARLY_BIRD,
            self::PUNCTUAL => 2,
            self::QUEUE_MASTER,
            self::SPEED_RUNNER,
            self::HELPFUL_HAND,
            self::FIFTY_JOBS,
            self::RELIABLE => 3,
            self::FIVE_STAR,
            self::TOP_EARNER,
            self::HUNDRED_JOBS,
            self::TRUSTED => 4,
        };
    }

    /**
     * Get tier label.
     */
    public function tierLabel(): string
    {
        return match ($this->tier()) {
            1 => 'Bronze',
            2 => 'Silver',
            3 => 'Gold',
            4 => 'Platinum',
            default => 'Unknown',
        };
    }

    /**
     * Get category of badge.
     */
    public function category(): string
    {
        return match ($this) {
            self::QUEUE_MASTER,
            self::SPEED_RUNNER,
            self::HELPFUL_HAND,
            self::EARLY_BIRD,
            self::FIVE_STAR,
            self::TOP_EARNER => 'performance',
            self::FIRST_JOB,
            self::TEN_JOBS,
            self::FIFTY_JOBS,
            self::HUNDRED_JOBS => 'milestone',
            self::PUNCTUAL,
            self::RELIABLE,
            self::TRUSTED => 'reliability',
        };
    }

    /**
     * Get badges by category.
     */
    public static function byCategory(string $category): array
    {
        return array_filter(self::cases(), fn(self $badge) => $badge->category() === $category);
    }

    /**
     * Get all milestone badges in order.
     */
    public static function milestones(): array
    {
        return [self::FIRST_JOB, self::TEN_JOBS, self::FIFTY_JOBS, self::HUNDRED_JOBS];
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}