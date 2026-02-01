<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\JobStatus;
use App\Models\JobPost;
use App\Models\JobWorker;
use App\Models\WorkerEarning;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Calculate weekly earnings for all workers.
 *
 * This command aggregates completed job earnings per worker per week
 * and optionally sends summary notifications.
 *
 * @example
 * php artisan jobs:calculate-earnings
 * php artisan jobs:calculate-earnings --week=2025-01
 * php artisan jobs:calculate-earnings --notify
 * php artisan jobs:calculate-earnings --dry-run
 *
 * @srs-ref Njaanum Panikkar Module - Worker Earnings
 */
class CalculateWeeklyEarningsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'jobs:calculate-earnings
                            {--week= : Specific week to calculate (YYYY-WW format, e.g., 2025-01)}
                            {--notify : Send weekly summary to workers}
                            {--dry-run : Show calculations without saving}';

    /**
     * The console command description.
     */
    protected $description = 'Calculate weekly earnings for all workers';

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppService $whatsApp): int
    {
        // Determine which week to calculate
        $weekOption = $this->option('week');
        
        if ($weekOption) {
            [$year, $week] = explode('-', $weekOption);
            $weekStart = Carbon::now()->setISODate((int) $year, (int) $week)->startOfWeek();
        } else {
            // Default to last week (for Sunday midnight run)
            $weekStart = Carbon::now()->subWeek()->startOfWeek();
        }

        $weekEnd = $weekStart->copy()->endOfWeek();
        $weekNumber = $weekStart->weekOfYear;
        $year = $weekStart->year;

        $this->info("Calculating earnings for Week {$weekNumber}, {$year}");
        $this->info("Period: {$weekStart->format('Y-m-d')} to {$weekEnd->format('Y-m-d')}");

        // Get all completed jobs for the week grouped by worker
        $earningsByWorker = JobPost::query()
            ->where('status', JobStatus::COMPLETED)
            ->whereBetween('completed_at', [$weekStart, $weekEnd])
            ->whereNotNull('assigned_worker_id')
            ->select([
                'assigned_worker_id',
                DB::raw('COUNT(*) as jobs_count'),
                DB::raw('SUM(final_amount) as total_earnings'),
                DB::raw('AVG(final_amount) as avg_per_job'),
                DB::raw('MIN(final_amount) as min_earning'),
                DB::raw('MAX(final_amount) as max_earning'),
            ])
            ->groupBy('assigned_worker_id')
            ->with(['assignedWorker.user'])
            ->get();

        if ($earningsByWorker->isEmpty()) {
            $this->info('No completed jobs found for this week.');
            return self::SUCCESS;
        }

        $this->info("Found earnings for {$earningsByWorker->count()} worker(s).");

        if ($this->option('dry-run')) {
            return $this->showEarnings($earningsByWorker, $weekStart, $weekEnd);
        }

        $saved = 0;
        $notified = 0;

        foreach ($earningsByWorker as $earning) {
            try {
                $worker = JobWorker::find($earning->assigned_worker_id);

                if (!$worker) {
                    continue;
                }

                // Create or update earnings record
                $workerEarning = WorkerEarning::updateOrCreate(
                    [
                        'worker_id' => $worker->id,
                        'year' => $year,
                        'week_number' => $weekNumber,
                    ],
                    [
                        'week_start' => $weekStart,
                        'week_end' => $weekEnd,
                        'jobs_completed' => $earning->jobs_count,
                        'total_earnings' => $earning->total_earnings ?? 0,
                        'average_per_job' => $earning->avg_per_job ?? 0,
                        'min_earning' => $earning->min_earning ?? 0,
                        'max_earning' => $earning->max_earning ?? 0,
                        'calculated_at' => now(),
                    ]
                );

                $saved++;

                // Send notification if requested
                if ($this->option('notify')) {
                    $notified += $this->sendWeeklySummary($whatsApp, $worker, $workerEarning);
                }

                Log::info('Weekly earnings calculated', [
                    'worker_id' => $worker->id,
                    'week' => "{$year}-{$weekNumber}",
                    'jobs' => $earning->jobs_count,
                    'total' => $earning->total_earnings,
                ]);

            } catch (\Exception $e) {
                $this->error("Failed to process worker {$earning->assigned_worker_id}: {$e->getMessage()}");
                Log::error('Failed to calculate worker earnings', [
                    'worker_id' => $earning->assigned_worker_id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("✅ Saved earnings for {$saved} worker(s).");

        if ($this->option('notify')) {
            $this->info("📨 Sent {$notified} weekly summary(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Send weekly summary to worker.
     */
    protected function sendWeeklySummary(WhatsAppService $whatsApp, JobWorker $worker, WorkerEarning $earning): int
    {
        if (!$worker->user || !$worker->user->phone) {
            return 0;
        }

        try {
            $totalFormatted = '₹' . number_format($earning->total_earnings);
            $avgFormatted = '₹' . number_format($earning->average_per_job);

            // Get comparison with previous week
            $prevWeek = WorkerEarning::where('worker_id', $worker->id)
                ->where('year', $earning->year)
                ->where('week_number', $earning->week_number - 1)
                ->first();

            $comparisonText = '';
            if ($prevWeek) {
                $diff = $earning->total_earnings - $prevWeek->total_earnings;
                $percentChange = $prevWeek->total_earnings > 0
                    ? round(($diff / $prevWeek->total_earnings) * 100)
                    : 0;

                if ($diff > 0) {
                    $comparisonText = "\n📈 *{$percentChange}% more* than last week!";
                } elseif ($diff < 0) {
                    $comparisonText = "\n📉 {$percentChange}% less than last week";
                } else {
                    $comparisonText = "\n➡️ Same as last week";
                }
            }

            $message = "📊 *Weekly Earnings Summary*\n" .
                "*ആഴ്ചയിലെ വരുമാന റിപ്പോർട്ട്*\n\n" .
                "👤 *{$worker->name}*\n" .
                "📅 Week {$earning->week_number}, {$earning->year}\n\n" .
                "💰 *Total Earnings:* {$totalFormatted}\n" .
                "✅ *Jobs Completed:* {$earning->jobs_completed}\n" .
                "📊 *Avg per Job:* {$avgFormatted}" .
                $comparisonText . "\n\n" .
                "Keep up the great work! 💪\n" .
                "നല്ല പ്രവർത്തനം തുടരുക!";

            $whatsApp->sendButtons(
                $worker->user->phone,
                $message,
                [
                    ['id' => 'job_browse', 'title' => '🔍 Find More Jobs'],
                    ['id' => 'job_worker_menu', 'title' => '👷 My Dashboard'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '📊 Weekly Summary'
            );

            return 1;

        } catch (\Exception $e) {
            Log::error('Failed to send weekly summary', [
                'worker_id' => $worker->id,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    /**
     * Show earnings that would be saved (dry run).
     */
    protected function showEarnings($earnings, Carbon $weekStart, Carbon $weekEnd): int
    {
        $this->newLine();
        $this->info("Week: {$weekStart->format('M d')} - {$weekEnd->format('M d, Y')}");
        $this->newLine();

        $headers = ['Worker ID', 'Name', 'Jobs', 'Total', 'Avg/Job', 'Min', 'Max'];
        $rows = [];
        $grandTotal = 0;
        $totalJobs = 0;

        foreach ($earnings as $earning) {
            $worker = JobWorker::find($earning->assigned_worker_id);
            $name = $worker?->name ?? 'Unknown';

            $rows[] = [
                $earning->assigned_worker_id,
                mb_substr($name, 0, 15),
                $earning->jobs_count,
                '₹' . number_format($earning->total_earnings ?? 0),
                '₹' . number_format($earning->avg_per_job ?? 0),
                '₹' . number_format($earning->min_earning ?? 0),
                '₹' . number_format($earning->max_earning ?? 0),
            ];

            $grandTotal += $earning->total_earnings ?? 0;
            $totalJobs += $earning->jobs_count;
        }

        $this->table($headers, $rows);

        $this->newLine();
        $this->info("📊 Summary:");
        $this->info("   Workers: {$earnings->count()}");
        $this->info("   Total Jobs: {$totalJobs}");
        $this->info("   Grand Total: ₹" . number_format($grandTotal));

        return self::SUCCESS;
    }
}