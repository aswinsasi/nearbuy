<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\JobWorker;
use App\Models\WorkerBadge;
use App\Services\Jobs\JobStatsService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily Badge Eligibility Check Command.
 *
 * Scans all active workers and awards earned badges.
 * Sends notification for each new badge.
 *
 * SRS Section 3.5 - Badge System:
 * - First Job ✅ (1 job completed)
 * - Queue Master 🏆 (10 queue jobs)
 * - Speed Runner 🏃 (5 deliveries)
 * - Reliable ⭐ (10 five-star ratings)
 * - Veteran 👑 (50 jobs)
 * - Top Earner 💰 (₹10,000+ in one week)
 *
 * Notification format:
 * "🏆 Badge earned! 'Queue Master' — 10 queue jobs completed! 💪
 * [📤 Share Achievement]"
 *
 * Schedule: Daily at 9 AM
 *
 * @example
 * php artisan jobs:check-badges
 * php artisan jobs:check-badges --worker=123
 * php artisan jobs:check-badges --notify
 * php artisan jobs:check-badges --dry-run
 *
 * @srs-ref Section 3.5 - Badge System
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
class CheckBadgeEligibilityCommand extends Command
{
    protected $signature = 'jobs:check-badges
                            {--worker= : Check specific worker ID only}
                            {--notify : Send WhatsApp notification for new badges}
                            {--dry-run : Show eligible badges without awarding}';

    protected $description = 'Check and award badges to eligible workers (daily at 9 AM)';

    public function __construct(
        protected JobStatsService $statsService,
        protected WhatsAppService $whatsApp
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->info('🏅 Checking badge eligibility...');

        $query = JobWorker::query()->where('is_available', true);

        if ($workerId = $this->option('worker')) {
            $query->where('id', $workerId);
        }

        $workers = $query->with('user')->get();

        if ($workers->isEmpty()) {
            $this->info('No active workers found.');
            return self::SUCCESS;
        }

        $this->info("Scanning {$workers->count()} worker(s)...");

        $totalAwarded = 0;
        $workersAwarded = 0;

        /** @var JobWorker $worker */
        foreach ($workers as $worker) {
            $newBadges = $this->checkWorkerBadges($worker);

            if (empty($newBadges)) {
                continue;
            }

            if ($this->option('dry-run')) {
                $this->showEligibleBadges($worker, $newBadges);
                continue;
            }

            $totalAwarded += count($newBadges);
            $workersAwarded++;

            // Send notifications
            if ($this->option('notify')) {
                $this->sendBadgeNotifications($worker, $newBadges);
            }

            // Log awards
            foreach ($newBadges as $badge) {
                Log::info('Badge awarded', [
                    'worker_id' => $worker->id,
                    'worker_name' => $worker->name,
                    'badge' => $badge->badge_type,
                ]);
            }
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('Dry run complete — no badges awarded.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->info("✅ Awarded {$totalAwarded} badge(s) to {$workersAwarded} worker(s).");

        return self::SUCCESS;
    }

    /**
     * Check all badges for a worker.
     */
    protected function checkWorkerBadges(JobWorker $worker): array
    {
        return $this->statsService->checkBadgeEligibility($worker);
    }

    /**
     * Show eligible badges (dry run).
     */
    protected function showEligibleBadges(JobWorker $worker, array $badges): void
    {
        $badgeNames = array_map(
            fn($b) => $b->emoji . ' ' . $b->label,
            $badges
        );

        $this->line("👷 {$worker->name} (#{$worker->id}): " . implode(', ', $badgeNames));
    }

    /**
     * Send badge notifications to worker.
     */
    protected function sendBadgeNotifications(JobWorker $worker, array $badges): void
    {
        if (!$worker->user?->phone) {
            return;
        }

        foreach ($badges as $badge) {
            try {
                $notification = $this->statsService->generateBadgeNotification($badge);

                $this->whatsApp->sendButtons(
                    $worker->user->phone,
                    $notification['message'],
                    $notification['buttons'],
                    '🏆 Badge Earned!'
                );

                $this->line("📨 Notified {$worker->name}: {$badge->emoji} {$badge->label}");

            } catch (\Exception $e) {
                Log::error('Failed to send badge notification', [
                    'worker_id' => $worker->id,
                    'badge' => $badge->badge_type,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }
}