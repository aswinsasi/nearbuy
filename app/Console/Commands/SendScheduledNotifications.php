<?php

namespace App\Console\Commands;

use App\Enums\NotificationFrequency;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

/**
 * Send scheduled notifications based on frequency preferences.
 *
 * Handles twice_daily (9 AM and 5 PM) and daily (9 AM) notifications.
 *
 * @example
 * php artisan nearbuy:send-scheduled-notifications
 * php artisan nearbuy:send-scheduled-notifications --frequency=daily
 * php artisan nearbuy:send-scheduled-notifications --frequency=twice_daily
 */
class SendScheduledNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nearbuy:send-scheduled-notifications
                            {--frequency= : Specific frequency to process (daily, twice_daily, every_2_hours)}
                            {--dry-run : Show what would be sent without sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send scheduled notifications (twice daily and daily)';

    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService): int
    {
        $frequency = $this->option('frequency');

        if ($this->option('dry-run')) {
            return $this->dryRun($frequency);
        }

        $totalProcessed = 0;

        if ($frequency) {
            // Process specific frequency
            $totalProcessed = $this->processFrequency($notificationService, $frequency);
        } else {
            // Process all scheduled frequencies
            $totalProcessed = $this->processAllScheduled($notificationService);
        }

        $this->info("✅ Processed {$totalProcessed} batch(es).");

        return self::SUCCESS;
    }

    /**
     * Process specific frequency.
     */
    protected function processFrequency(NotificationService $service, string $frequency): int
    {
        $this->info("Processing {$frequency} notifications...");

        $freq = NotificationFrequency::tryFrom($frequency);

        if (!$freq) {
            $this->error("Invalid frequency: {$frequency}");
            $this->info("Valid options: " . implode(', ', NotificationFrequency::values()));
            return 0;
        }

        return $service->processNotificationsForFrequency($freq);
    }

    /**
     * Process all scheduled notification types.
     */
    protected function processAllScheduled(NotificationService $service): int
    {
        $hour = (int) now()->format('H');
        $processed = 0;

        // 9 AM - process both daily and twice_daily
        if ($hour === 9) {
            $this->info('Processing 9 AM notifications (daily + twice_daily)...');

            $processed += $service->processDailyNotifications();
            $processed += $service->processTwiceDailyNotifications();
        }

        // 5 PM - process twice_daily only
        elseif ($hour === 17) {
            $this->info('Processing 5 PM notifications (twice_daily)...');

            $processed += $service->processTwiceDailyNotifications();
        }

        // Every 2 hours
        elseif ($hour % 2 === 0) {
            $this->info('Processing 2-hourly notifications...');

            $processed += $service->process2HourlyNotifications();
        }

        else {
            $this->info('No scheduled notifications for current hour.');
        }

        return $processed;
    }

    /**
     * Show what would be sent without sending.
     */
    protected function dryRun(?string $frequency): int
    {
        $query = \App\Models\NotificationBatch::query()
            ->where('status', 'pending')
            ->where('scheduled_for', '<=', now())
            ->with('shop');

        if ($frequency) {
            $freq = NotificationFrequency::tryFrom($frequency);
            if ($freq) {
                $query->where('frequency', $freq);
            }
        }

        $batches = $query->get();

        if ($batches->isEmpty()) {
            $this->info('No pending batches to process.');
            return self::SUCCESS;
        }

        $this->info("Found {$batches->count()} pending batch(es):");

        $headers = ['ID', 'Shop', 'Frequency', 'Items', 'Scheduled'];

        $rows = $batches->map(fn($batch) => [
            $batch->id,
            $batch->shop?->name ?? 'Unknown',
            $batch->frequency->value ?? 'N/A',
            count($batch->items ?? []),
            $batch->scheduled_for->format('Y-m-d H:i'),
        ])->toArray();

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}