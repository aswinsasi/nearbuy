<?php

namespace App\Console\Commands;

use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;

/**
 * Send all pending batched notifications.
 *
 * This command processes notification batches that are scheduled
 * to be sent at or before the current time.
 *
 * @example
 * php artisan nearbuy:send-batched-notifications
 */
class SendBatchedNotifications extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nearbuy:send-batched-notifications
                            {--dry-run : Show what would be sent without sending}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send all pending batched notifications';

    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService): int
    {
        $this->info('Processing batched notifications...');

        if ($this->option('dry-run')) {
            return $this->dryRun();
        }

        try {
            $processed = $notificationService->processBatchedNotifications();

            $this->info("✅ Processed {$processed} notification batch(es).");

            return self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            return self::FAILURE;
        }
    }

    /**
     * Show what would be sent without sending.
     */
    protected function dryRun(): int
    {
        $batches = \App\Models\NotificationBatch::query()
            ->where('status', 'pending')
            ->where('scheduled_for', '<=', now())
            ->with('shop')
            ->get();

        if ($batches->isEmpty()) {
            $this->info('No pending batches to process.');
            return self::SUCCESS;
        }

        $this->info("Found {$batches->count()} batch(es) to process:");

        $headers = ['ID', 'Shop', 'Items', 'Frequency', 'Scheduled For'];
        $rows = [];

        foreach ($batches as $batch) {
            $rows[] = [
                $batch->id,
                $batch->shop?->name ?? 'Unknown',
                count($batch->items ?? []),
                $batch->frequency->value ?? 'N/A',
                $batch->scheduled_for->format('Y-m-d H:i'),
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}