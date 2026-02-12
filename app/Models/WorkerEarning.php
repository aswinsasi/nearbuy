<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * Worker Earning Model - Individual job earnings tracking.
 *
 * Simple structure: worker_id, job_post_id, amount, earned_at
 * Helpers: totalEarnings(), weeklyEarnings(), monthlyEarnings()
 *
 * @property int $id
 * @property int $worker_id
 * @property int $job_post_id
 * @property float $amount
 * @property \Carbon\Carbon $earned_at
 *
 * @srs-ref Section 3.5 - Earnings Showcase
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class WorkerEarning extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'worker_id',
        'job_post_id',
        'amount',
        'earned_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
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

    public function jobPost(): BelongsTo
    {
        return $this->belongsTo(JobPost::class, 'job_post_id');
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

    public function scopeThisWeek(Builder $query): Builder
    {
        return $query->whereBetween('earned_at', [
            now()->startOfWeek(),
            now()->endOfWeek(),
        ]);
    }

    public function scopeLastWeek(Builder $query): Builder
    {
        return $query->whereBetween('earned_at', [
            now()->subWeek()->startOfWeek(),
            now()->subWeek()->endOfWeek(),
        ]);
    }

    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->whereBetween('earned_at', [
            now()->startOfMonth(),
            now()->endOfMonth(),
        ]);
    }

    public function scopeForWeek(Builder $query, Carbon $weekStart): Builder
    {
        return $query->whereBetween('earned_at', [
            $weekStart->copy()->startOfWeek(),
            $weekStart->copy()->endOfWeek(),
        ]);
    }

    public function scopeForMonth(Builder $query, Carbon $monthStart): Builder
    {
        return $query->whereBetween('earned_at', [
            $monthStart->copy()->startOfMonth(),
            $monthStart->copy()->endOfMonth(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Static Helpers - Earnings Calculations
    |--------------------------------------------------------------------------
    */

    /**
     * Total earnings for a worker (all time).
     */
    public static function totalEarnings(int $workerId): float
    {
        return (float) self::byWorker($workerId)->sum('amount');
    }

    /**
     * Weekly earnings for a worker.
     */
    public static function weeklyEarnings(int $workerId, ?Carbon $weekStart = null): float
    {
        $query = self::byWorker($workerId);

        if ($weekStart) {
            $query->forWeek($weekStart);
        } else {
            $query->thisWeek();
        }

        return (float) $query->sum('amount');
    }

    /**
     * Monthly earnings for a worker.
     */
    public static function monthlyEarnings(int $workerId, ?Carbon $monthStart = null): float
    {
        $query = self::byWorker($workerId);

        if ($monthStart) {
            $query->forMonth($monthStart);
        } else {
            $query->thisMonth();
        }

        return (float) $query->sum('amount');
    }

    /**
     * Jobs count for a worker this week.
     */
    public static function weeklyJobsCount(int $workerId, ?Carbon $weekStart = null): int
    {
        $query = self::byWorker($workerId);

        if ($weekStart) {
            $query->forWeek($weekStart);
        } else {
            $query->thisWeek();
        }

        return $query->count();
    }

    /**
     * Jobs count for a worker this month.
     */
    public static function monthlyJobsCount(int $workerId, ?Carbon $monthStart = null): int
    {
        $query = self::byWorker($workerId);

        if ($monthStart) {
            $query->forMonth($monthStart);
        } else {
            $query->thisMonth();
        }

        return $query->count();
    }

    /**
     * Record earning for a completed job.
     */
    public static function recordEarning(int $workerId, int $jobPostId, float $amount): self
    {
        return self::create([
            'worker_id' => $workerId,
            'job_post_id' => $jobPostId,
            'amount' => $amount,
            'earned_at' => now(),
        ]);
    }

    /**
     * Get weekly summary for a worker.
     */
    public static function getWeeklySummary(int $workerId, ?Carbon $weekStart = null): array
    {
        $weekStart = $weekStart ?? now()->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();

        $earnings = self::byWorker($workerId)->forWeek($weekStart)->get();

        return [
            'week_start' => $weekStart->format('M j'),
            'week_end' => $weekEnd->format('M j, Y'),
            'total_amount' => $earnings->sum('amount'),
            'jobs_count' => $earnings->count(),
            'amount_display' => '₹' . number_format($earnings->sum('amount')),
        ];
    }

    /**
     * Get monthly summary for a worker.
     */
    public static function getMonthlySummary(int $workerId, ?Carbon $monthStart = null): array
    {
        $monthStart = $monthStart ?? now()->startOfMonth();

        $earnings = self::byWorker($workerId)->forMonth($monthStart)->get();

        return [
            'month' => $monthStart->format('F Y'),
            'total_amount' => $earnings->sum('amount'),
            'jobs_count' => $earnings->count(),
            'amount_display' => '₹' . number_format($earnings->sum('amount')),
            'avg_per_job' => $earnings->count() > 0
                ? round($earnings->sum('amount') / $earnings->count())
                : 0,
        ];
    }

    /**
     * Get earnings history (last N weeks).
     */
    public static function getEarningsHistory(int $workerId, int $weeks = 8): array
    {
        $history = [];

        for ($i = 0; $i < $weeks; $i++) {
            $weekStart = now()->subWeeks($i)->startOfWeek();
            $history[] = self::getWeeklySummary($workerId, $weekStart);
        }

        return $history;
    }

    /**
     * Get top earners for a period (for leaderboard).
     */
    public static function getTopEarners(
        ?Carbon $start = null,
        ?Carbon $end = null,
        int $limit = 10,
        ?string $city = null
    ): array {
        $start = $start ?? now()->startOfMonth();
        $end = $end ?? now()->endOfMonth();

        $query = self::query()
            ->selectRaw('worker_id, SUM(amount) as total_earned, COUNT(*) as jobs_count')
            ->whereBetween('earned_at', [$start, $end])
            ->groupBy('worker_id')
            ->orderByDesc('total_earned')
            ->limit($limit);

        $results = $query->get();

        $leaderboard = [];
        $rank = 1;
        $medals = ['👑', '🥈', '🥉'];

        foreach ($results as $row) {
            $worker = JobWorker::with('user')->find($row->worker_id);

            if (!$worker) {
                continue;
            }

            // Filter by city if specified
            if ($city && $worker->city !== $city) {
                continue;
            }

            $medal = $medals[$rank - 1] ?? ($rank . '.');

            $leaderboard[] = [
                'rank' => $rank,
                'medal' => $medal,
                'worker_id' => $row->worker_id,
                'name' => $worker->name,
                'total_earned' => (float) $row->total_earned,
                'total_display' => '₹' . number_format($row->total_earned),
                'jobs_count' => $row->jobs_count,
                'rating' => $worker->rating ?? 0,
            ];

            $rank++;
        }

        return $leaderboard;
    }

    /**
     * Get worker's rank in leaderboard.
     */
    public static function getWorkerRank(
        int $workerId,
        ?Carbon $start = null,
        ?Carbon $end = null
    ): ?int {
        $start = $start ?? now()->startOfMonth();
        $end = $end ?? now()->endOfMonth();

        $workerEarnings = self::byWorker($workerId)
            ->whereBetween('earned_at', [$start, $end])
            ->sum('amount');

        if ($workerEarnings <= 0) {
            return null;
        }

        $rank = self::query()
            ->selectRaw('worker_id, SUM(amount) as total')
            ->whereBetween('earned_at', [$start, $end])
            ->groupBy('worker_id')
            ->havingRaw('SUM(amount) > ?', [$workerEarnings])
            ->count();

        return $rank + 1;
    }
}