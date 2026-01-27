<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * Worker Earning Model - Weekly earnings tracking.
 *
 * @property int $id
 * @property int $worker_id
 * @property \Carbon\Carbon $week_start
 * @property \Carbon\Carbon $week_end
 * @property int $total_jobs
 * @property int $total_applications
 * @property int $accepted_applications
 * @property float $total_earnings
 * @property float $average_per_job
 * @property float $total_hours_worked
 * @property array|null $earnings_by_category
 * @property float|null $average_rating
 * @property int $on_time_count
 * @property int $late_count
 *
 * @srs-ref Section 3.7 - Worker Earnings Analytics
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class WorkerEarning extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'worker_id',
        'week_start',
        'week_end',
        'total_jobs',
        'total_applications',
        'accepted_applications',
        'total_earnings',
        'average_per_job',
        'total_hours_worked',
        'earnings_by_category',
        'average_rating',
        'on_time_count',
        'late_count',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'week_start' => 'date',
        'week_end' => 'date',
        'total_earnings' => 'decimal:2',
        'average_per_job' => 'decimal:2',
        'total_hours_worked' => 'decimal:2',
        'earnings_by_category' => 'array',
        'average_rating' => 'decimal:1',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'total_jobs' => 0,
        'total_applications' => 0,
        'accepted_applications' => 0,
        'total_earnings' => 0.00,
        'average_per_job' => 0.00,
        'total_hours_worked' => 0.00,
        'on_time_count' => 0,
        'late_count' => 0,
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the worker.
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
     * Scope to filter by worker.
     */
    public function scopeByWorker(Builder $query, int $workerId): Builder
    {
        return $query->where('worker_id', $workerId);
    }

    /**
     * Scope to get earnings for a specific week.
     */
    public function scopeForWeek(Builder $query, $date): Builder
    {
        $weekStart = Carbon::parse($date)->startOfWeek()->toDateString();
        return $query->where('week_start', $weekStart);
    }

    /**
     * Scope to get this week's earnings.
     */
    public function scopeThisWeek(Builder $query): Builder
    {
        return $query->forWeek(now());
    }

    /**
     * Scope to get this month's earnings.
     */
    public function scopeThisMonth(Builder $query): Builder
    {
        return $query->whereBetween('week_start', [
            now()->startOfMonth()->toDateString(),
            now()->endOfMonth()->toDateString(),
        ]);
    }

    /**
     * Scope to get last month's earnings.
     */
    public function scopeLastMonth(Builder $query): Builder
    {
        return $query->whereBetween('week_start', [
            now()->subMonth()->startOfMonth()->toDateString(),
            now()->subMonth()->endOfMonth()->toDateString(),
        ]);
    }

    /**
     * Scope to order by most recent.
     */
    public function scopeLatest(Builder $query): Builder
    {
        return $query->orderBy('week_start', 'desc');
    }

    /**
     * Scope to get high earning weeks.
     */
    public function scopeHighEarning(Builder $query, float $minAmount = 5000): Builder
    {
        return $query->where('total_earnings', '>=', $minAmount);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get week range display.
     */
    public function getWeekRangeAttribute(): string
    {
        return $this->week_start->format('M j') . ' - ' . $this->week_end->format('M j, Y');
    }

    /**
     * Get total earnings display.
     */
    public function getEarningsDisplayAttribute(): string
    {
        return '₹' . number_format($this->total_earnings);
    }

    /**
     * Get average per job display.
     */
    public function getAveragePerJobDisplayAttribute(): string
    {
        return '₹' . number_format($this->average_per_job);
    }

    /**
     * Get acceptance rate.
     */
    public function getAcceptanceRateAttribute(): float
    {
        if ($this->total_applications === 0) {
            return 0;
        }

        return round(($this->accepted_applications / $this->total_applications) * 100, 1);
    }

    /**
     * Get on-time rate.
     */
    public function getOnTimeRateAttribute(): float
    {
        $total = $this->on_time_count + $this->late_count;
        if ($total === 0) {
            return 100;
        }

        return round(($this->on_time_count / $total) * 100, 1);
    }

    /**
     * Get rating display.
     */
    public function getRatingDisplayAttribute(): ?string
    {
        if (!$this->average_rating) {
            return null;
        }

        return '⭐ ' . number_format($this->average_rating, 1);
    }

    /**
     * Check if this is current week.
     */
    public function getIsCurrentWeekAttribute(): bool
    {
        return $this->week_start->eq(now()->startOfWeek());
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get or create earnings record for a week.
     */
    public static function getOrCreateForWeek(JobWorker $worker, $date = null): self
    {
        $date = $date ? Carbon::parse($date) : now();
        $weekStart = $date->startOfWeek()->toDateString();
        $weekEnd = $date->endOfWeek()->toDateString();

        return self::firstOrCreate(
            [
                'worker_id' => $worker->id,
                'week_start' => $weekStart,
            ],
            [
                'week_end' => $weekEnd,
            ]
        );
    }

    /**
     * Record a completed job.
     */
    public function recordCompletedJob(float $amount, float $hours, int $categoryId, bool $onTime = true): void
    {
        $this->total_jobs++;
        $this->total_earnings += $amount;
        $this->total_hours_worked += $hours;

        if ($this->total_jobs > 0) {
            $this->average_per_job = $this->total_earnings / $this->total_jobs;
        }

        if ($onTime) {
            $this->on_time_count++;
        } else {
            $this->late_count++;
        }

        // Update category breakdown
        $byCategory = $this->earnings_by_category ?? [];
        if (!isset($byCategory[$categoryId])) {
            $byCategory[$categoryId] = ['jobs' => 0, 'earnings' => 0];
        }
        $byCategory[$categoryId]['jobs']++;
        $byCategory[$categoryId]['earnings'] += $amount;
        $this->earnings_by_category = $byCategory;

        $this->save();
    }

    /**
     * Record an application.
     */
    public function recordApplication(bool $accepted = false): void
    {
        $this->total_applications++;
        if ($accepted) {
            $this->accepted_applications++;
        }
        $this->save();
    }

    /**
     * Update average rating for the week.
     */
    public function updateAverageRating(float $newRating): void
    {
        $currentTotal = ($this->average_rating ?? 0) * $this->total_jobs;
        $newAverage = ($currentTotal + $newRating) / max(1, $this->total_jobs);
        
        $this->update(['average_rating' => round($newAverage, 1)]);
    }

    /**
     * Get monthly summary for a worker.
     */
    public static function getMonthlySummary(JobWorker $worker, $month = null): array
    {
        $month = $month ? Carbon::parse($month) : now();

        $earnings = self::byWorker($worker->id)
            ->whereBetween('week_start', [
                $month->startOfMonth()->toDateString(),
                $month->endOfMonth()->toDateString(),
            ])
            ->get();

        return [
            'month' => $month->format('F Y'),
            'total_earnings' => $earnings->sum('total_earnings'),
            'total_jobs' => $earnings->sum('total_jobs'),
            'total_hours' => $earnings->sum('total_hours_worked'),
            'average_rating' => $earnings->avg('average_rating'),
            'weeks' => $earnings->count(),
        ];
    }

    /**
     * Convert to summary format.
     */
    public function toSummary(): array
    {
        return [
            'week_range' => $this->week_range,
            'is_current_week' => $this->is_current_week,
            'total_jobs' => $this->total_jobs,
            'total_earnings' => $this->earnings_display,
            'average_per_job' => $this->average_per_job_display,
            'total_hours' => $this->total_hours_worked,
            'acceptance_rate' => $this->acceptance_rate . '%',
            'on_time_rate' => $this->on_time_rate . '%',
            'average_rating' => $this->rating_display,
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
            if (empty($model->week_end) && $model->week_start) {
                $model->week_end = Carbon::parse($model->week_start)->endOfWeek()->toDateString();
            }
        });
    }
}