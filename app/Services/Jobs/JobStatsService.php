<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Models\JobPost;
use App\Models\JobWorker;
use App\Models\WorkerBadge;
use App\Models\WorkerEarning;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Job Stats Service - Statistics, leaderboards, and viral mechanics.
 *
 * SRS Section 3.5 - Viral Mechanics:
 * 1. Earnings Showcase: "You earned ₹2,400 this week!" — shareable
 * 2. Worker Referral: Worker invites friend → ₹50 for every 5 jobs friend completes
 * 3. Leaderboard: "Top earners in [City] this month" — public recognition
 * 4. Success Stories: "Student earns ₹15,000/month" — inspirational
 * 5. Badge System: Queue Master, Speed Runner, Reliable, Veteran, Top Earner
 *
 * @srs-ref Section 3.5 - Viral Mechanics
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobStatsService
{
    /*
    |--------------------------------------------------------------------------
    | Weekly Summary (Monday 8AM notification)
    |--------------------------------------------------------------------------
    */

    /**
     * Generate weekly summary message for worker.
     *
     * Format per SRS:
     * "💰 Weekly Summary! 🎉
     * This week: ₹[Amount] from [X] jobs!
     * Total: ₹[Total] earned on NearBuy
     * 🏆 Rank: #[X] in [City]
     * [📊 Full Stats] [📤 Share Earnings]"
     */
    public function generateWeeklySummary(JobWorker $worker): array
    {
        $lastWeekStart = now()->subWeek()->startOfWeek();

        $weeklyAmount = WorkerEarning::weeklyEarnings($worker->id, $lastWeekStart);
        $weeklyJobs = WorkerEarning::weeklyJobsCount($worker->id, $lastWeekStart);
        $totalEarnings = WorkerEarning::totalEarnings($worker->id);

        $rank = WorkerEarning::getWorkerRank(
            $worker->id,
            $lastWeekStart,
            $lastWeekStart->copy()->endOfWeek()
        );

        $city = $worker->city ?? 'Kerala';

        // Build message
        $message = "💰 *Weekly Summary!* 🎉\n\n";
        $message .= "This week: *₹" . number_format($weeklyAmount) . "* from *{$weeklyJobs}* jobs!\n";
        $message .= "Total: *₹" . number_format($totalEarnings) . "* earned on NearBuy\n";

        if ($rank && $rank <= 50) {
            $message .= "🏆 Rank: *#{$rank}* in {$city}\n";
        }

        // Compare with previous week
        $prevWeekAmount = WorkerEarning::weeklyEarnings(
            $worker->id,
            $lastWeekStart->copy()->subWeek()
        );

        if ($prevWeekAmount > 0) {
            $change = $weeklyAmount - $prevWeekAmount;
            $percent = round(($change / $prevWeekAmount) * 100);

            if ($change > 0) {
                $message .= "\n📈 *+{$percent}%* vs last week! Keep it up!";
            } elseif ($change < 0) {
                $message .= "\n💪 Let's bounce back this week!";
            }
        }

        $buttons = [
            ['id' => 'worker_stats', 'title' => '📊 Full Stats'],
            ['id' => 'share_earnings', 'title' => '📤 Share'],
        ];

        return [
            'message' => $message,
            'buttons' => $buttons,
            'data' => [
                'weekly_amount' => $weeklyAmount,
                'weekly_jobs' => $weeklyJobs,
                'total_earnings' => $totalEarnings,
                'rank' => $rank,
                'city' => $city,
            ],
        ];
    }

    /**
     * Generate shareable earnings text.
     */
    public function generateShareableEarnings(JobWorker $worker): string
    {
        $weeklyAmount = WorkerEarning::weeklyEarnings($worker->id);
        $weeklyJobs = WorkerEarning::weeklyJobsCount($worker->id);
        $totalJobs = $worker->jobs_completed ?? 0;

        $text = "🌟 *My NearBuy Earnings!* 🌟\n\n";
        $text .= "This week: ₹" . number_format($weeklyAmount) . " from {$weeklyJobs} jobs!\n";
        $text .= "Total jobs: {$totalJobs} ✅\n";
        $text .= "Rating: ⭐ " . number_format($worker->rating ?? 0, 1) . "\n\n";
        $text .= "Join me on NearBuy! Anyone can earn 💪\n";
        $text .= "#NjaanumPanikkar #NearBuy #Kerala";

        return $text;
    }

    /*
    |--------------------------------------------------------------------------
    | Leaderboard (Monthly)
    |--------------------------------------------------------------------------
    */

    /**
     * Generate leaderboard message.
     *
     * Format per SRS:
     * "🏆 Top Earners in [City] — [Month]:
     * 1. 👑 [Name] — ₹[Amount] — [X] jobs
     * 2. 🥈 [Name] — ₹[Amount] — [X] jobs
     * 3. 🥉 [Name] — ₹[Amount] — [X] jobs
     * ..."
     */
    public function generateLeaderboardMessage(?string $city = null, int $limit = 10): string
    {
        $leaderboard = WorkerEarning::getTopEarners(
            now()->startOfMonth(),
            now()->endOfMonth(),
            $limit,
            $city
        );

        $cityName = $city ?? 'Kerala';
        $month = now()->format('F Y');

        $message = "🏆 *Top Earners in {$cityName}* — {$month}\n\n";

        if (empty($leaderboard)) {
            $message .= "No earnings recorded yet this month.\n";
            $message .= "Be the first! 💪";
            return $message;
        }

        foreach ($leaderboard as $entry) {
            $message .= "{$entry['medal']} *{$entry['name']}*\n";
            $message .= "   {$entry['total_display']} • {$entry['jobs_count']} jobs\n";
        }

        $message .= "\n💪 Work hard, earn more, climb the ranks!";

        return $message;
    }

    /**
     * Get leaderboard data.
     */
    public function getLeaderboard(
        string $period = 'month',
        ?string $city = null,
        int $limit = 10
    ): array {
        $start = match ($period) {
            'week' => now()->startOfWeek(),
            'month' => now()->startOfMonth(),
            'all' => null,
            default => now()->startOfMonth(),
        };

        $end = match ($period) {
            'week' => now()->endOfWeek(),
            'month' => now()->endOfMonth(),
            'all' => now(),
            default => now()->endOfMonth(),
        };

        return WorkerEarning::getTopEarners($start, $end, $limit, $city);
    }

    /*
    |--------------------------------------------------------------------------
    | Badge Eligibility Check
    |--------------------------------------------------------------------------
    */

    /**
     * Check all badge eligibility for a worker.
     * Returns newly earned badges.
     */
    public function checkBadgeEligibility(JobWorker $worker): array
    {
        $newBadges = [];

        foreach (WorkerBadge::BADGES as $type => $info) {
            if (WorkerBadge::hasBadge($worker->id, $type)) {
                continue;
            }

            if ($this->isEligibleForBadge($worker, $type, $info['requirement'])) {
                $badge = WorkerBadge::award($worker->id, $type);
                if ($badge) {
                    $newBadges[] = $badge;
                }
            }
        }

        return $newBadges;
    }

    /**
     * Check if worker is eligible for a specific badge.
     */
    protected function isEligibleForBadge(JobWorker $worker, string $type, array $requirement): bool
    {
        return match ($requirement['type']) {
            'total_jobs' => ($worker->jobs_completed ?? 0) >= $requirement['count'],

            'category_jobs' => $this->getCategoryJobsCount($worker, $requirement['category'])
                >= $requirement['count'],

            'five_star_count' => $this->getFiveStarCount($worker) >= $requirement['count'],

            'weekly_earnings' => WorkerEarning::weeklyEarnings($worker->id)
                >= $requirement['amount'],

            default => false,
        };
    }

    /**
     * Get count of jobs in a specific category.
     */
    protected function getCategoryJobsCount(JobWorker $worker, string $category): int
    {
        return JobPost::where('assigned_worker_id', $worker->id)
            ->where('status', 'completed')
            ->whereHas('category', function ($q) use ($category) {
                $q->where('slug', $category);
            })
            ->count();
    }

    /**
     * Get count of five-star ratings for worker.
     */
    protected function getFiveStarCount(JobWorker $worker): int
    {
        return JobPost::where('assigned_worker_id', $worker->id)
            ->where('status', 'completed')
            ->where('worker_rating', 5)
            ->count();
    }

    /**
     * Generate badge notification message.
     *
     * Format per SRS:
     * "🏆 Badge earned! 'Queue Master' — 10 queue jobs completed! 💪
     * [📤 Share Achievement]"
     */
    public function generateBadgeNotification(WorkerBadge $badge): array
    {
        $message = "🏆 *Badge earned!*\n\n";
        $message .= "{$badge->emoji} *{$badge->label}*\n";
        $message .= "{$badge->description} 💪";

        $buttons = [
            ['id' => 'share_badge_' . $badge->badge_type, 'title' => '📤 Share'],
            ['id' => 'view_badges', 'title' => '🏅 My Badges'],
        ];

        return [
            'message' => $message,
            'buttons' => $buttons,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Referral System (SRS Section 3.5)
    | Note: Requires WorkerReferral model to be created for full functionality
    |--------------------------------------------------------------------------
    */

    /**
     * Generate referral code for worker.
     */
    public function generateReferralCode(JobWorker $worker): string
    {
        return 'NB-' . strtoupper(substr(md5((string) $worker->id), 0, 6));
    }

    /**
     * Generate referral message for sharing.
     */
    public function generateReferralMessage(JobWorker $worker): string
    {
        $code = $this->generateReferralCode($worker);

        return "💰 *Earn money with NearBuy!*\n\n" .
            "Join Njaanum Panikkar - do simple jobs, earn good money!\n" .
            "Queue standing, delivery, shopping - no skills needed!\n\n" .
            "Use my code: *{$code}*\n" .
            "I'll get ₹50 for every 5 jobs you complete! 🎁\n\n" .
            "#NjaanumPanikkar #NearBuy";
    }

    /*
    |--------------------------------------------------------------------------
    | Success Stories (SRS Section 3.5)
    |--------------------------------------------------------------------------
    */

    /**
     * Generate success story for high earners.
     * "Student earns ₹15,000/month doing part-time jobs"
     */
    public function generateSuccessStory(JobWorker $worker): ?array
    {
        $monthlyEarnings = WorkerEarning::monthlyEarnings($worker->id);
        $totalJobs = $worker->jobs_completed ?? 0;

        // Only generate for significant earners
        if ($monthlyEarnings < 5000 || $totalJobs < 10) {
            return null;
        }

        $title = $this->generateStoryTitle($worker, $monthlyEarnings);

        $story = [
            'worker_id' => $worker->id,
            'name' => $worker->name,
            'title' => $title,
            'monthly_earnings' => $monthlyEarnings,
            'total_jobs' => $totalJobs,
            'rating' => $worker->rating ?? 0,
            'member_since' => $worker->created_at?->format('M Y'),
        ];

        $story['message'] = "🌟 *Success Story!*\n\n" .
            "📖 {$title}\n\n" .
            "💰 Earns ₹" . number_format($monthlyEarnings) . "/month\n" .
            "✅ {$totalJobs} jobs completed\n" .
            "⭐ Rating: " . number_format($worker->rating ?? 0, 1) . "\n\n" .
            "\"Anyone can do it! Just start.\" - {$worker->name}\n\n" .
            "#NjaanumPanikkar #SuccessStory";

        return $story;
    }

    /**
     * Generate story title based on worker profile.
     */
    protected function generateStoryTitle(JobWorker $worker, float $earnings): string
    {
        $amount = number_format($earnings);

        // Simple title templates
        $templates = [
            "{$worker->name} earns ₹{$amount}/month with part-time jobs",
            "How {$worker->name} makes ₹{$amount} monthly on NearBuy",
            "₹{$amount}/month: {$worker->name}'s NearBuy journey",
        ];

        return $templates[array_rand($templates)];
    }

    /*
    |--------------------------------------------------------------------------
    | Worker Stats Dashboard
    |--------------------------------------------------------------------------
    */

    /**
     * Get comprehensive stats for worker dashboard.
     */
    public function getWorkerStats(JobWorker $worker): array
    {
        $thisWeekEarnings = WorkerEarning::weeklyEarnings($worker->id);
        $lastWeekEarnings = WorkerEarning::weeklyEarnings($worker->id, now()->subWeek()->startOfWeek());
        $thisMonthEarnings = WorkerEarning::monthlyEarnings($worker->id);
        $totalEarnings = WorkerEarning::totalEarnings($worker->id);

        $weeklyChange = $lastWeekEarnings > 0
            ? round((($thisWeekEarnings - $lastWeekEarnings) / $lastWeekEarnings) * 100)
            : 0;

        $badges = WorkerBadge::byWorker($worker->id)->recent()->get();
        $rank = WorkerEarning::getWorkerRank($worker->id);

        return [
            'earnings' => [
                'this_week' => $thisWeekEarnings,
                'last_week' => $lastWeekEarnings,
                'this_month' => $thisMonthEarnings,
                'total' => $totalEarnings,
                'weekly_change' => $weeklyChange,
            ],
            'jobs' => [
                'total' => $worker->jobs_completed ?? 0,
                'this_week' => WorkerEarning::weeklyJobsCount($worker->id),
                'this_month' => WorkerEarning::monthlyJobsCount($worker->id),
            ],
            'rating' => [
                'average' => $worker->rating ?? 0,
                'count' => $worker->rating_count ?? 0,
            ],
            'badges' => $badges->map(fn($b) => [
                'type' => $b->badge_type,
                'emoji' => $b->emoji,
                'label' => $b->label,
                'earned_at' => $b->earned_at->format('M j'),
            ])->toArray(),
            'rank' => $rank,
            'member_since' => $worker->created_at?->format('M Y'),
        ];
    }

    /**
     * Generate stats dashboard message.
     */
    public function generateStatsDashboard(JobWorker $worker): string
    {
        $stats = $this->getWorkerStats($worker);

        $message = "📊 *{$worker->name}'s Stats*\n\n";

        // Earnings
        $message .= "💰 *Earnings*\n";
        $message .= "This week: ₹" . number_format($stats['earnings']['this_week']) . "\n";
        $message .= "This month: ₹" . number_format($stats['earnings']['this_month']) . "\n";
        $message .= "Total: ₹" . number_format($stats['earnings']['total']) . "\n\n";

        // Jobs
        $message .= "✅ *Jobs*\n";
        $message .= "Total: {$stats['jobs']['total']} completed\n";
        $message .= "This week: {$stats['jobs']['this_week']}\n\n";

        // Rating
        $message .= "⭐ *Rating*: " . number_format($stats['rating']['average'], 1);
        $message .= " ({$stats['rating']['count']} reviews)\n\n";

        // Badges
        if (!empty($stats['badges'])) {
            $badgeEmojis = array_map(fn($b) => $b['emoji'], $stats['badges']);
            $message .= "🏅 *Badges*: " . implode(' ', $badgeEmojis) . "\n\n";
        }

        // Rank
        if ($stats['rank'] && $stats['rank'] <= 50) {
            $message .= "🏆 *Rank*: #{$stats['rank']} this month\n";
        }

        return $message;
    }
}