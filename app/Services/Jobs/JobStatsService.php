<?php

declare(strict_types=1);

namespace App\Services\Jobs;

use App\Enums\BadgeType;
use App\Enums\JobPostStatus;
use App\Enums\JobStatus;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Models\WorkerBadge;
use App\Models\WorkerEarning;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for job statistics, leaderboards, and gamification.
 *
 * Handles:
 * - Worker performance statistics
 * - Earnings tracking and analysis
 * - Badge management and eligibility
 * - Leaderboards by various metrics
 * - Shareable stats for viral marketing
 *
 * @srs-ref Section 3.6 - Worker Gamification & Stats
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class JobStatsService
{
    /**
     * Badge criteria constants.
     */
    protected const BADGE_CRITERIA = [
        'FIRST_JOB' => ['jobs' => 1],
        'TEN_JOBS' => ['jobs' => 10],
        'FIFTY_JOBS' => ['jobs' => 50],
        'HUNDRED_JOBS' => ['jobs' => 100],
        'FIVE_STAR' => ['rating' => 5.0, 'min_jobs' => 20],
        'TOP_EARNER' => ['weekly_earnings' => 10000],
        'TRUSTED' => ['verified' => true, 'jobs' => 50, 'rating' => 4.5],
        'RELIABLE' => ['no_cancellations' => true, 'min_jobs' => 30],
        'PUNCTUAL' => ['consecutive_on_time' => 20],
        'EARLY_BIRD' => ['morning_jobs_percent' => 70, 'min_jobs' => 10],
    ];

    /*
    |--------------------------------------------------------------------------
    | Worker Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get comprehensive statistics for a worker.
     *
     * @param JobWorker $worker The worker to get stats for
     * @return array Comprehensive stats array
     */
    public function getWorkerStats(JobWorker $worker): array
    {
        $jobs = $worker->assignedJobs()->with('verification')->get();
        $completedJobs = $jobs->where('status', JobStatus::COMPLETED);
        $badges = $worker->badges()->get();

        // Calculate time-based stats
        $thisWeekEarnings = $this->getWeeklyEarnings($worker);
        $thisMonthEarnings = $this->getMonthlyEarnings($worker);
        $lastWeekEarnings = $this->getWeeklyEarnings($worker, now()->subWeek()->startOfWeek());

        // Calculate rates
        $totalAssigned = $jobs->count();
        $cancellations = $jobs->where('status', JobStatus::CANCELLED)
            ->where('cancelled_by', 'worker')
            ->count();
        $cancellationRate = $totalAssigned > 0 ? ($cancellations / $totalAssigned) * 100 : 0;

        // On-time calculation
        $onTimeCount = $completedJobs->filter(function ($job) {
            return $job->verification?->arrived_on_time ?? false;
        })->count();
        $onTimeRate = $completedJobs->count() > 0
            ? ($onTimeCount / $completedJobs->count()) * 100
            : 0;

        // Calculate 5-star rate
        $fiveStarJobs = $completedJobs->filter(fn($job) => $job->worker_rating === 5)->count();
        $fiveStarRate = $completedJobs->count() > 0
            ? ($fiveStarJobs / $completedJobs->count()) * 100
            : 0;

        // Earnings trend
        $earningsTrend = $lastWeekEarnings > 0
            ? (($thisWeekEarnings - $lastWeekEarnings) / $lastWeekEarnings) * 100
            : 0;

        return [
            // Profile
            'worker_id' => $worker->id,
            'name' => $worker->name,
            'rating' => round($worker->rating ?? 0, 1),
            'rating_count' => $worker->rating_count ?? 0,
            'member_since' => $worker->created_at->format('M Y'),
            'days_active' => $worker->created_at->diffInDays(now()),

            // Jobs
            'total_jobs' => $worker->jobs_completed ?? 0,
            'jobs_this_week' => $completedJobs->filter(fn($j) => $j->completed_at?->isCurrentWeek())->count(),
            'jobs_this_month' => $completedJobs->filter(fn($j) => $j->completed_at?->isCurrentMonth())->count(),
            'pending_jobs' => $jobs->whereIn('status', [JobStatus::ASSIGNED, JobStatus::IN_PROGRESS])->count(),

            // Earnings
            'total_earnings' => $worker->total_earnings ?? 0,
            'this_week_earnings' => $thisWeekEarnings,
            'this_month_earnings' => $thisMonthEarnings,
            'last_week_earnings' => $lastWeekEarnings,
            'earnings_trend' => round($earningsTrend, 1),
            'avg_job_earnings' => $completedJobs->count() > 0
                ? round($completedJobs->avg('agreed_amount') ?? 0)
                : 0,

            // Performance
            'on_time_rate' => round($onTimeRate, 1),
            'five_star_rate' => round($fiveStarRate, 1),
            'cancellation_rate' => round($cancellationRate, 1),
            'response_rate' => $this->calculateResponseRate($worker),

            // Hours
            'total_hours' => $this->calculateTotalHours($worker),
            'avg_hours_per_job' => $this->calculateAvgHoursPerJob($worker),

            // Badges
            'badges_count' => $badges->count(),
            'badges' => $badges->map(fn($b) => [
                'type' => $b->badge_type->value,
                'name' => $b->badge_type->label(),
                'emoji' => $b->badge_type->emoji(),
                'earned_at' => $b->earned_at->format('M j, Y'),
            ])->toArray(),

            // Categories
            'top_categories' => $this->getTopCategories($worker),

            // Rank
            'weekly_rank' => $this->getWorkerRank($worker, 'earnings', 'week'),
            'monthly_rank' => $this->getWorkerRank($worker, 'earnings', 'month'),
        ];
    }

    /**
     * Get worker performance stats summary.
     *
     * @param JobWorker $worker The worker
     * @return array Performance summary
     */
    public function getWorkerPerformanceStats(JobWorker $worker): array
    {
        $jobs = $worker->assignedJobs()
            ->where('status', JobStatus::COMPLETED)
            ->with('verification')
            ->get();

        if ($jobs->isEmpty()) {
            return [
                'on_time_rate' => 0,
                'avg_duration_hours' => 0,
                'five_star_rate' => 0,
                'badges_count' => 0,
                'total_jobs' => 0,
            ];
        }

        $onTimeCount = $jobs->filter(fn($j) => $j->verification?->arrived_on_time)->count();
        $fiveStarCount = $jobs->filter(fn($j) => $j->worker_rating === 5)->count();

        return [
            'on_time_rate' => round(($onTimeCount / $jobs->count()) * 100, 1),
            'avg_duration_hours' => round($jobs->avg('duration_hours') ?? 0, 1),
            'five_star_rate' => round(($fiveStarCount / $jobs->count()) * 100, 1),
            'badges_count' => $worker->badges()->count(),
            'total_jobs' => $jobs->count(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Earnings
    |--------------------------------------------------------------------------
    */

    /**
     * Get weekly earnings for a worker.
     *
     * @param JobWorker $worker The worker
     * @param Carbon|null $weekStart Start of week (defaults to current)
     * @return float Total earnings for the week
     */
    public function getWeeklyEarnings(JobWorker $worker, ?Carbon $weekStart = null): float
    {
        $weekStart = $weekStart ?? now()->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();

        // Try from WorkerEarning first
        $earning = WorkerEarning::byWorker($worker->id)
            ->where('week_start', $weekStart->toDateString())
            ->first();

        if ($earning) {
            return (float) $earning->total_earned;
        }

        // Calculate from jobs
        return (float) $worker->assignedJobs()
            ->where('status', JobStatus::COMPLETED)
            ->whereBetween('completed_at', [$weekStart, $weekEnd])
            ->sum('agreed_amount');
    }

    /**
     * Get monthly earnings for a worker.
     *
     * @param JobWorker $worker The worker
     * @param Carbon|null $monthStart Start of month (defaults to current)
     * @return float Total earnings for the month
     */
    public function getMonthlyEarnings(JobWorker $worker, ?Carbon $monthStart = null): float
    {
        $monthStart = $monthStart ?? now()->startOfMonth();
        $monthEnd = $monthStart->copy()->endOfMonth();

        // Sum from WorkerEarnings
        $fromEarnings = WorkerEarning::byWorker($worker->id)
            ->whereBetween('week_start', [$monthStart, $monthEnd])
            ->sum('total_earned');

        if ($fromEarnings > 0) {
            return (float) $fromEarnings;
        }

        // Calculate from jobs
        return (float) $worker->assignedJobs()
            ->where('status', JobStatus::COMPLETED)
            ->whereBetween('completed_at', [$monthStart, $monthEnd])
            ->sum('agreed_amount');
    }

    /**
     * Get earnings history for a worker.
     *
     * @param JobWorker $worker The worker
     * @param int $weeks Number of weeks to retrieve
     * @return Collection Earnings history
     */
    public function getEarningsHistory(JobWorker $worker, int $weeks = 12): Collection
    {
        return WorkerEarning::byWorker($worker->id)
            ->orderBy('week_start', 'desc')
            ->limit($weeks)
            ->get()
            ->map(fn($e) => [
                'week_start' => $e->week_start->format('M j'),
                'week_end' => $e->week_start->copy()->endOfWeek()->format('M j'),
                'total_earned' => $e->total_earned,
                'total_jobs' => $e->total_jobs,
                'total_hours' => $e->total_hours,
                'average_rating' => $e->average_rating,
            ]);
    }

    /**
     * Get monthly summary for a worker.
     *
     * @param JobWorker $worker The worker
     * @param Carbon|null $month The month to summarize
     * @return array Monthly summary
     */
    public function getMonthlySummary(JobWorker $worker, ?Carbon $month = null): array
    {
        $month = $month ?? now();
        $monthStart = $month->copy()->startOfMonth();
        $monthEnd = $month->copy()->endOfMonth();

        $earnings = WorkerEarning::byWorker($worker->id)
            ->whereBetween('week_start', [$monthStart, $monthEnd])
            ->get();

        $jobs = $worker->assignedJobs()
            ->where('status', JobStatus::COMPLETED)
            ->whereBetween('completed_at', [$monthStart, $monthEnd])
            ->get();

        return [
            'month' => $month->format('F Y'),
            'total_earned' => $earnings->sum('total_earned'),
            'total_jobs' => $earnings->sum('total_jobs'),
            'total_hours' => $earnings->sum('total_hours'),
            'avg_rating' => round($jobs->avg('worker_rating') ?? 0, 1),
            'best_week' => $earnings->max('total_earned'),
            'weeks_active' => $earnings->count(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Badges
    |--------------------------------------------------------------------------
    */

    /**
     * Get all badges for a worker.
     *
     * @param JobWorker $worker The worker
     * @return Collection Worker's badges
     */
    public function getWorkerBadges(JobWorker $worker): Collection
    {
        return $worker->badges()
            ->orderBy('earned_at', 'desc')
            ->get()
            ->map(fn($badge) => [
                'type' => $badge->badge_type->value,
                'name' => $badge->badge_type->label(),
                'name_ml' => $badge->badge_type->labelMl(),
                'emoji' => $badge->badge_type->emoji(),
                'description' => $badge->badge_type->description(),
                'earned_at' => $badge->earned_at->format('M j, Y'),
                'is_new' => $badge->earned_at->isToday(),
            ]);
    }

    /**
     * Check which badges a worker is eligible for but hasn't earned.
     *
     * @param JobWorker $worker The worker
     * @return array List of badges that can be earned with progress
     */
    public function checkBadgeEligibility(JobWorker $worker): array
    {
        $earnedBadges = $worker->badges()->pluck('badge_type')->toArray();
        $eligibleBadges = [];

        foreach (BadgeType::cases() as $badge) {
            // Skip if already earned
            if (in_array($badge, $earnedBadges)) {
                continue;
            }

            $eligibility = $this->checkSingleBadgeEligibility($worker, $badge);

            if ($eligibility['progress'] > 0) {
                $eligibleBadges[] = [
                    'badge' => $badge->value,
                    'name' => $badge->label(),
                    'emoji' => $badge->emoji(),
                    'description' => $badge->description(),
                    'progress' => $eligibility['progress'],
                    'requirement' => $eligibility['requirement'],
                    'current' => $eligibility['current'],
                    'can_earn' => $eligibility['can_earn'],
                ];
            }
        }

        // Sort by progress (closest to completion first)
        usort($eligibleBadges, fn($a, $b) => $b['progress'] <=> $a['progress']);

        return $eligibleBadges;
    }

    /**
     * Check eligibility for a single badge.
     */
    protected function checkSingleBadgeEligibility(JobWorker $worker, BadgeType $badge): array
    {
        $jobsCompleted = $worker->jobs_completed ?? 0;
        $rating = $worker->rating ?? 0;
        $ratingCount = $worker->rating_count ?? 0;

        return match ($badge) {
            BadgeType::FIRST_JOB => [
                'progress' => min(100, $jobsCompleted * 100),
                'requirement' => '1 job',
                'current' => "$jobsCompleted jobs",
                'can_earn' => $jobsCompleted >= 1,
            ],

            BadgeType::TEN_JOBS => [
                'progress' => min(100, ($jobsCompleted / 10) * 100),
                'requirement' => '10 jobs',
                'current' => "$jobsCompleted jobs",
                'can_earn' => $jobsCompleted >= 10,
            ],

            BadgeType::FIFTY_JOBS => [
                'progress' => min(100, ($jobsCompleted / 50) * 100),
                'requirement' => '50 jobs',
                'current' => "$jobsCompleted jobs",
                'can_earn' => $jobsCompleted >= 50,
            ],

            BadgeType::HUNDRED_JOBS => [
                'progress' => min(100, ($jobsCompleted / 100) * 100),
                'requirement' => '100 jobs',
                'current' => "$jobsCompleted jobs",
                'can_earn' => $jobsCompleted >= 100,
            ],

            BadgeType::FIVE_STAR => [
                'progress' => $ratingCount >= 20 && $rating >= 5.0 ? 100 :
                    min(90, ($ratingCount / 20) * 50 + ($rating / 5) * 50),
                'requirement' => '5.0 rating with 20+ reviews',
                'current' => "$rating rating ($ratingCount reviews)",
                'can_earn' => $rating >= 5.0 && $ratingCount >= 20,
            ],

            BadgeType::TOP_EARNER => [
                'progress' => min(100, ($this->getWeeklyEarnings($worker) / 10000) * 100),
                'requirement' => '₹10,000+ in a week',
                'current' => '₹' . number_format($this->getWeeklyEarnings($worker)),
                'can_earn' => $this->getWeeklyEarnings($worker) >= 10000,
            ],

            BadgeType::TRUSTED => [
                'progress' => $this->calculateTrustedProgress($worker),
                'requirement' => 'Verified + 50 jobs + 4.5+ rating',
                'current' => $this->getTrustedCurrentStatus($worker),
                'can_earn' => $this->canEarnTrusted($worker),
            ],

            BadgeType::RELIABLE => [
                'progress' => $this->calculateReliableProgress($worker),
                'requirement' => 'No cancellations in 30+ jobs',
                'current' => $this->getReliableCurrentStatus($worker),
                'can_earn' => $this->canEarnReliable($worker),
            ],

            BadgeType::PUNCTUAL => [
                'progress' => $this->calculatePunctualProgress($worker),
                'requirement' => '20 consecutive on-time arrivals',
                'current' => $this->getPunctualCurrentStatus($worker),
                'can_earn' => $this->canEarnPunctual($worker),
            ],

            default => [
                'progress' => 0,
                'requirement' => 'Unknown',
                'current' => 'N/A',
                'can_earn' => false,
            ],
        };
    }

    /**
     * Award a badge to a worker.
     *
     * @param JobWorker $worker The worker
     * @param BadgeType $badge The badge to award
     * @return WorkerBadge|null The created badge or null if already exists
     */
    public function awardBadge(JobWorker $worker, BadgeType $badge): ?WorkerBadge
    {
        // Check if already has badge
        if ($worker->badges()->where('badge_type', $badge)->exists()) {
            return null;
        }

        $workerBadge = WorkerBadge::create([
            'worker_id' => $worker->id,
            'badge_type' => $badge,
            'earned_at' => now(),
            'metadata' => [
                'jobs_at_award' => $worker->jobs_completed,
                'rating_at_award' => $worker->rating,
            ],
        ]);

        Log::info('Badge awarded', [
            'worker_id' => $worker->id,
            'badge' => $badge->value,
        ]);

        return $workerBadge;
    }

    /**
     * Check and award all eligible badges for a worker.
     *
     * @param JobWorker $worker The worker
     * @return array Newly awarded badges
     */
    public function checkAndAwardAllBadges(JobWorker $worker): array
    {
        $eligibleBadges = $this->checkBadgeEligibility($worker);
        $awarded = [];

        foreach ($eligibleBadges as $badge) {
            if ($badge['can_earn']) {
                $badgeType = BadgeType::from($badge['badge']);
                $workerBadge = $this->awardBadge($worker, $badgeType);

                if ($workerBadge) {
                    $awarded[] = $badgeType;
                }
            }
        }

        return $awarded;
    }

    /*
    |--------------------------------------------------------------------------
    | Leaderboards
    |--------------------------------------------------------------------------
    */

    /**
     * Get leaderboard by type and period.
     *
     * @param string $type Leaderboard type (earnings, jobs, rating)
     * @param string $period Time period (week, month, all)
     * @param int $limit Number of entries
     * @return Collection Leaderboard entries
     */
    public function getLeaderboard(
        string $type = 'earnings',
        string $period = 'week',
        int $limit = 10
    ): Collection {
        return match ($type) {
            'earnings' => $this->getEarningsLeaderboard($period, $limit),
            'jobs' => $this->getJobsLeaderboard($period, $limit),
            'rating' => $this->getRatingLeaderboard($limit),
            default => collect(),
        };
    }

    /**
     * Get earnings leaderboard.
     */
    protected function getEarningsLeaderboard(string $period, int $limit): Collection
    {
        $query = WorkerEarning::query()
            ->select('worker_id', DB::raw('SUM(total_earned) as total_earned'))
            ->groupBy('worker_id');

        if ($period === 'week') {
            $query->where('week_start', now()->startOfWeek()->toDateString());
        } elseif ($period === 'month') {
            $query->whereBetween('week_start', [
                now()->startOfMonth()->toDateString(),
                now()->endOfMonth()->toDateString(),
            ]);
        }

        $results = $query->orderByDesc('total_earned')
            ->limit($limit)
            ->get();

        return $this->enrichLeaderboardResults($results, 'total_earned');
    }

    /**
     * Get jobs completed leaderboard.
     */
    protected function getJobsLeaderboard(string $period, int $limit): Collection
    {
        $query = JobPost::query()
            ->select('assigned_worker_id', DB::raw('COUNT(*) as total_jobs'))
            ->where('status', JobStatus::COMPLETED)
            ->whereNotNull('assigned_worker_id')
            ->groupBy('assigned_worker_id');

        if ($period === 'week') {
            $query->whereBetween('completed_at', [now()->startOfWeek(), now()->endOfWeek()]);
        } elseif ($period === 'month') {
            $query->whereBetween('completed_at', [now()->startOfMonth(), now()->endOfMonth()]);
        }

        $results = $query->orderByDesc('total_jobs')
            ->limit($limit)
            ->get()
            ->map(fn($r) => (object) ['worker_id' => $r->assigned_worker_id, 'total_jobs' => $r->total_jobs]);

        return $this->enrichLeaderboardResults($results, 'total_jobs');
    }

    /**
     * Get rating leaderboard (minimum 10 jobs).
     */
    protected function getRatingLeaderboard(int $limit): Collection
    {
        $workers = JobWorker::active()
            ->verified()
            ->where('rating_count', '>=', 10)
            ->orderByDesc('rating')
            ->orderByDesc('rating_count')
            ->limit($limit)
            ->get();

        return $workers->map(fn($worker, $index) => [
            'rank' => $index + 1,
            'worker_id' => $worker->id,
            'name' => $worker->name,
            'rating' => round($worker->rating, 1),
            'rating_count' => $worker->rating_count,
            'jobs_completed' => $worker->jobs_completed,
            'badges' => $worker->badges()->count(),
        ]);
    }

    /**
     * Enrich leaderboard results with worker details.
     */
    protected function enrichLeaderboardResults(Collection $results, string $valueField): Collection
    {
        $workerIds = $results->pluck('worker_id')->toArray();
        $workers = JobWorker::whereIn('id', $workerIds)->get()->keyBy('id');

        return $results->map(function ($result, $index) use ($workers, $valueField) {
            $worker = $workers->get($result->worker_id);

            return [
                'rank' => $index + 1,
                'worker_id' => $result->worker_id,
                'name' => $worker?->name ?? 'Unknown',
                'rating' => round($worker?->rating ?? 0, 1),
                'value' => $result->$valueField,
                'value_display' => $valueField === 'total_earned'
                    ? '₹' . number_format($result->$valueField)
                    : $result->$valueField,
                'badges' => $worker?->badges()->count() ?? 0,
            ];
        });
    }

    /**
     * Get worker's rank in a leaderboard.
     *
     * @param JobWorker $worker The worker
     * @param string $type Leaderboard type
     * @param string $period Time period
     * @return int|null Rank or null if not ranked
     */
    public function getWorkerRank(JobWorker $worker, string $type, string $period): ?int
    {
        $leaderboard = $this->getLeaderboard($type, $period, 100);

        foreach ($leaderboard as $entry) {
            if ($entry['worker_id'] === $worker->id) {
                return $entry['rank'];
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Shareable Stats
    |--------------------------------------------------------------------------
    */

    /**
     * Generate shareable stats for viral marketing.
     *
     * @param JobWorker $worker The worker
     * @return array Shareable stats with formatted text
     */
    public function generateShareableStats(JobWorker $worker): array
    {
        $stats = $this->getWorkerStats($worker);
        $topBadge = $worker->badges()->orderByDesc('earned_at')->first();

        // Generate shareable text
        $shareText = "🌟 My Njaanum Panikkar Stats! 🌟\n\n" .
            "✅ {$stats['total_jobs']} Jobs Completed\n" .
            "⭐ {$stats['rating']} Rating ({$stats['rating_count']} reviews)\n" .
            "💰 ₹" . number_format($stats['total_earnings']) . " Total Earned\n" .
            "🏅 {$stats['badges_count']} Badges Earned\n";

        if ($topBadge) {
            $shareText .= "\n🏆 Latest Badge: {$topBadge->badge_type->emoji()} {$topBadge->badge_type->label()}\n";
        }

        if ($stats['weekly_rank'] && $stats['weekly_rank'] <= 10) {
            $shareText .= "\n📊 #{$stats['weekly_rank']} in Weekly Earnings!\n";
        }

        $shareText .= "\n#NjaanumPanikkar #LocalJobs #Kerala";

        // Generate WhatsApp share link
        $whatsappShareUrl = 'https://wa.me/?text=' . urlencode($shareText);

        return [
            'text' => $shareText,
            'whatsapp_url' => $whatsappShareUrl,
            'stats' => [
                'jobs' => $stats['total_jobs'],
                'rating' => $stats['rating'],
                'earnings' => $stats['total_earnings'],
                'badges' => $stats['badges_count'],
                'rank' => $stats['weekly_rank'],
            ],
            'card_data' => [
                'name' => $worker->name,
                'avatar_url' => $worker->photo_url,
                'headline' => "{$stats['total_jobs']} Jobs • ⭐ {$stats['rating']}",
                'subheadline' => "₹" . number_format($stats['this_month_earnings']) . " this month",
                'badges' => $stats['badges'],
            ],
        ];
    }

    /**
     * Generate weekly digest for sharing.
     *
     * @param JobWorker $worker The worker
     * @return array Weekly digest data
     */
    public function generateWeeklyDigest(JobWorker $worker): array
    {
        $thisWeek = $this->getWeeklyEarnings($worker);
        $lastWeek = $this->getWeeklyEarnings($worker, now()->subWeek()->startOfWeek());

        $jobs = $worker->assignedJobs()
            ->where('status', JobStatus::COMPLETED)
            ->whereBetween('completed_at', [now()->startOfWeek(), now()->endOfWeek()])
            ->get();

        $change = $lastWeek > 0
            ? round((($thisWeek - $lastWeek) / $lastWeek) * 100, 1)
            : ($thisWeek > 0 ? 100 : 0);

        $emoji = $change >= 0 ? '📈' : '📉';
        $sign = $change >= 0 ? '+' : '';

        return [
            'period' => now()->startOfWeek()->format('M j') . ' - ' . now()->endOfWeek()->format('M j'),
            'earnings' => $thisWeek,
            'earnings_display' => '₹' . number_format($thisWeek),
            'jobs_completed' => $jobs->count(),
            'hours_worked' => $jobs->sum('duration_hours'),
            'avg_rating' => round($jobs->avg('worker_rating') ?? 0, 1),
            'change_percent' => $change,
            'change_display' => "{$emoji} {$sign}{$change}%",
            'top_category' => $this->getTopCategoryForPeriod($worker, now()->startOfWeek(), now()->endOfWeek()),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Calculate total hours worked.
     */
    protected function calculateTotalHours(JobWorker $worker): float
    {
        return (float) $worker->assignedJobs()
            ->where('status', JobStatus::COMPLETED)
            ->sum('duration_hours');
    }

    /**
     * Calculate average hours per job.
     */
    protected function calculateAvgHoursPerJob(JobWorker $worker): float
    {
        $completed = $worker->assignedJobs()->where('status', JobStatus::COMPLETED)->count();

        if ($completed === 0) {
            return 0;
        }

        return round($this->calculateTotalHours($worker) / $completed, 1);
    }

    /**
     * Calculate response rate (accepted / total applications).
     */
    protected function calculateResponseRate(JobWorker $worker): float
    {
        $total = $worker->applications()->count();

        if ($total < 5) {
            return 0;
        }

        $accepted = $worker->applications()->where('status', 'accepted')->count();

        return round(($accepted / $total) * 100, 1);
    }

    /**
     * Get top categories for a worker.
     */
    protected function getTopCategories(JobWorker $worker, int $limit = 3): array
    {
        return $worker->assignedJobs()
            ->where('status', JobStatus::COMPLETED)
            ->select('category_id', DB::raw('COUNT(*) as count'))
            ->groupBy('category_id')
            ->orderByDesc('count')
            ->limit($limit)
            ->with('category')
            ->get()
            ->map(fn($j) => [
                'id' => $j->category_id,
                'name' => $j->category?->name ?? 'Unknown',
                'icon' => $j->category?->icon ?? '🔧',
                'count' => $j->count,
            ])
            ->toArray();
    }

    /**
     * Get top category for a time period.
     */
    protected function getTopCategoryForPeriod(JobWorker $worker, Carbon $start, Carbon $end): ?array
    {
        $result = $worker->assignedJobs()
            ->where('status', JobStatus::COMPLETED)
            ->whereBetween('completed_at', [$start, $end])
            ->select('category_id', DB::raw('COUNT(*) as count'))
            ->groupBy('category_id')
            ->orderByDesc('count')
            ->first();

        if (!$result) {
            return null;
        }

        return [
            'id' => $result->category_id,
            'name' => $result->category?->name ?? 'Unknown',
            'icon' => $result->category?->icon ?? '🔧',
            'count' => $result->count,
        ];
    }

    /**
     * Badge helper: Calculate trusted badge progress.
     */
    protected function calculateTrustedProgress(JobWorker $worker): float
    {
        $verifiedScore = $worker->verification_status?->value === 'verified' ? 33 : 0;
        $jobsScore = min(33, ($worker->jobs_completed / 50) * 33);
        $ratingScore = min(34, (($worker->rating ?? 0) / 4.5) * 34);

        return min(100, $verifiedScore + $jobsScore + $ratingScore);
    }

    protected function getTrustedCurrentStatus(JobWorker $worker): string
    {
        $verified = $worker->verification_status?->value === 'verified' ? '✓' : '✗';
        $jobs = $worker->jobs_completed ?? 0;
        $rating = $worker->rating ?? 0;

        return "Verified: {$verified}, Jobs: {$jobs}/50, Rating: {$rating}/4.5";
    }

    protected function canEarnTrusted(JobWorker $worker): bool
    {
        return $worker->verification_status?->value === 'verified'
            && ($worker->jobs_completed ?? 0) >= 50
            && ($worker->rating ?? 0) >= 4.5;
    }

    protected function calculateReliableProgress(JobWorker $worker): float
    {
        $totalJobs = $worker->assignedJobs()->count();
        $cancellations = $worker->assignedJobs()
            ->where('status', JobStatus::CANCELLED)
            ->where('cancelled_by', 'worker')
            ->count();

        if ($cancellations > 0) {
            return 0;
        }

        return min(100, ($totalJobs / 30) * 100);
    }

    protected function getReliableCurrentStatus(JobWorker $worker): string
    {
        $totalJobs = $worker->assignedJobs()->count();
        $cancellations = $worker->assignedJobs()
            ->where('status', JobStatus::CANCELLED)
            ->where('cancelled_by', 'worker')
            ->count();

        return "Jobs: {$totalJobs}/30, Cancellations: {$cancellations}";
    }

    protected function canEarnReliable(JobWorker $worker): bool
    {
        $totalJobs = $worker->assignedJobs()->count();
        $cancellations = $worker->assignedJobs()
            ->where('status', JobStatus::CANCELLED)
            ->where('cancelled_by', 'worker')
            ->count();

        return $totalJobs >= 30 && $cancellations === 0;
    }

    protected function calculatePunctualProgress(JobWorker $worker): float
    {
        $consecutiveOnTime = $this->getConsecutiveOnTimeCount($worker);

        return min(100, ($consecutiveOnTime / 20) * 100);
    }

    protected function getPunctualCurrentStatus(JobWorker $worker): string
    {
        $consecutiveOnTime = $this->getConsecutiveOnTimeCount($worker);

        return "{$consecutiveOnTime}/20 consecutive on-time";
    }

    protected function canEarnPunctual(JobWorker $worker): bool
    {
        return $this->getConsecutiveOnTimeCount($worker) >= 20;
    }

    protected function getConsecutiveOnTimeCount(JobWorker $worker): int
    {
        $jobs = $worker->assignedJobs()
            ->where('status', JobStatus::COMPLETED)
            ->with('verification')
            ->orderByDesc('completed_at')
            ->get();

        $consecutive = 0;

        foreach ($jobs as $job) {
            if ($job->verification?->arrived_on_time) {
                $consecutive++;
            } else {
                break;
            }
        }

        return $consecutive;
    }
}