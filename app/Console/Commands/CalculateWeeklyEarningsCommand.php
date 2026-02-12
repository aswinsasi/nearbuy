<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\JobWorker;
use App\Models\WorkerEarning;
use App\Services\Jobs\JobStatsService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Weekly Earnings Summary Command.
 *
 * Sends Monday 8 AM weekly summary to all workers.
 *
 * SRS Section 3.5 - Earnings Showcase:
 * "💰 Weekly Summary! 🎉
 * This week: ₹[Amount] from [X] jobs!
 * Total: ₹[Total] earned on NearBuy
 * 🏆 Rank: #[X] in [City]
 * [📊 Full Stats] [📤 Share Earnings]"
 *
 * Schedule: Monday 8:00 AM
 *
 * @example
 * php artisan jobs:weekly-summary
 * php artisan jobs:weekly-summary --worker=123
 * php artisan jobs:weekly-summary --dry-run
 * php artisan jobs:weekly-summary --week=2026-05
 *
 * @srs-ref Section 3.5 - Earnings Showcase
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class CalculateWeeklyEarningsCommand extends Command
{
    protected $signature = 'jobs:weekly-summary
                            {--worker= : Send to specific worker ID only}
                            {--week= : Specific week (YYYY-WW format, e.g., 2026-05)}
                            {--dry-run : Show summaries without sending}
                            {--leaderboard : Also send monthly leaderboard}';

    protected $description = 'Send weekly earnings summary to workers (Monday 8 AM)';

    public function __construct(
        protected JobStatsService $statsService,
        protected WhatsAppService $whatsApp
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('💰 Generating weekly summaries...');

        // Determine week to summarize (default: last week)
        $weekStart = $this->getWeekStart();
        $weekEnd = $weekStart->copy()->endOfWeek();

        $this->info("Week: {$weekStart->format('M j')} - {$weekEnd->format('M j, Y')}");

        // Get workers with earnings last week
        $workers = $this->getWorkersWithEarnings($weekStart, $weekEnd);

        if ($workers->isEmpty()) {
            $this->info('No workers with earnings this week.');
            return self::SUCCESS;
        }

        $this->info("Processing {$workers->count()} worker(s)...");

        $sent = 0;
        $skipped = 0;

        /** @var JobWorker $worker */
        foreach ($workers as $worker) {
            $summary = $this->statsService->generateWeeklySummary($worker);

            if ($this->option('dry-run')) {
                $this->showSummary($worker, $summary);
                continue;
            }

            if ($this->sendSummary($worker, $summary)) {
                $sent++;
                $this->line("📨 Sent to {$worker->name}");
            } else {
                $skipped++;
            }
        }

        // Send leaderboard if requested
        if ($this->option('leaderboard') && !$this->option('dry-run')) {
            $this->sendLeaderboard();
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run complete — no messages sent.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("✅ Sent {$sent} weekly summaries. Skipped {$skipped}.");

        return self::SUCCESS;
    }

    /**
     * Get week start date.
     */
    protected function getWeekStart(): Carbon
    {
        if ($week = $this->option('week')) {
            [$year, $weekNum] = explode('-', $week);
            return Carbon::now()
                ->setISODate((int) $year, (int) $weekNum)
                ->startOfWeek();
        }

        // Default: last week
        return now()->subWeek()->startOfWeek();
    }

    /**
     * Get workers with earnings in the period.
     */
    protected function getWorkersWithEarnings(Carbon $start, Carbon $end)
    {
        $query = JobWorker::query()
            ->whereHas('earnings', function ($q) use ($start, $end) {
                $q->whereBetween('earned_at', [$start, $end]);
            })
            ->with('user');

        if ($workerId = $this->option('worker')) {
            $query->where('id', $workerId);
        }

        return $query->get();
    }

    /**
     * Show summary (dry run).
     */
    protected function showSummary(JobWorker $worker, array $summary): void
    {
        $data = $summary['data'];

        $this->newLine();
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("👷 {$worker->name} (#{$worker->id})");
        $this->line("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->line("💰 Weekly: ₹" . number_format($data['weekly_amount']));
        $this->line("📋 Jobs: {$data['weekly_jobs']}");
        $this->line("📊 Total: ₹" . number_format($data['total_earnings']));

        if ($data['rank']) {
            $this->line("🏆 Rank: #{$data['rank']} in {$data['city']}");
        }
    }

    /**
     * Send summary to worker.
     */
    protected function sendSummary(JobWorker $worker, array $summary): bool
    {
        if (!$worker->user?->phone) {
            return false;
        }

        try {
            $this->whatsApp->sendButtons(
                $worker->user->phone,
                $summary['message'],
                $summary['buttons'],
                '💰 Weekly Summary'
            );

            Log::info('Weekly summary sent', [
                'worker_id' => $worker->id,
                'amount' => $summary['data']['weekly_amount'],
                'jobs' => $summary['data']['weekly_jobs'],
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Failed to send weekly summary', [
                'worker_id' => $worker->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send monthly leaderboard to all workers.
     */
    protected function sendLeaderboard(): void
    {
        $this->info('📊 Sending monthly leaderboard...');

        $leaderboard = $this->statsService->generateLeaderboardMessage();

        // Get all active workers
        $workers = JobWorker::where('is_available', true)
            ->whereHas('user')
            ->with('user')
            ->get();

        $sent = 0;

        /** @var JobWorker $worker */
        foreach ($workers as $worker) {
            if (!$worker->user?->phone) {
                continue;
            }

            try {
                $this->whatsApp->sendText(
                    $worker->user->phone,
                    $leaderboard
                );
                $sent++;
            } catch (\Exception $e) {
                Log::error('Failed to send leaderboard', [
                    'worker_id' => $worker->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("📊 Sent leaderboard to {$sent} workers.");
    }
}