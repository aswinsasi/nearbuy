<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendBatchNotification;
use App\Models\NotificationBatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Send all pending batched notifications.
 *
 * This command processes notification batches that are scheduled
 * to be sent at or before the current time.
 *
 * USAGE:
 * - Run every minute via scheduler
 * - Processes all ready batches
 * - Dispatches jobs to appropriate queues
 *
 * @srs-ref NFR-R-02 - Failed deliveries retried
 * @module Notifications
 *
 * @example
 * php artisan nearbuy:send-batched-notifications
 * php artisan nearbuy:send-batched-notifications --dry-run
 * php artisan nearbuy:send-batched-notifications --limit=50
 */
class SendBatchedNotifications extends Command
{
    /**
     * Command signature.
     */
    protected $signature = 'nearbuy:send-batched-notifications
                            {--dry-run : Show what would be sent without sending}
                            {--limit=100 : Maximum batches to process}
                            {--force : Process even if outside scheduled time}';

    /**
     * Command description.
     */
    protected $description = 'Send all pending batched notifications';

    /**
     * Execute the command.
     */
    public function handle(): int
    {
        $this->info('📬 Processing batched notifications...');

        if ($this->option('dry-run')) {
            return $this->dryRun();
        }

        $limit = (int) $this->option('limit');
        $processed = 0;
        $failed = 0;

        try {
            $batches = NotificationBatch::query()
                ->where('status', 'pending')
                ->where('scheduled_for', '<=', now())
                ->with(['shop.user'])
                ->limit($limit)
                ->get();

            if ($batches->isEmpty()) {
                $this->info('✅ No pending batches to process.');
                return self::SUCCESS;
            }

            $this->info("Found {$batches->count()} batch(es) to process.");

            $progressBar = $this->output->createProgressBar($batches->count());
            $progressBar->start();

            foreach ($batches as $batch) {
                try {
                    $this->processBatch($batch);
                    $processed++;
                } catch (\Exception $e) {
                    $failed++;
                    $this->logError($batch, $e);
                }

                $progressBar->advance();
            }

            $progressBar->finish();
            $this->newLine(2);

            $this->displaySummary($processed, $failed);

            return $failed > 0 ? self::FAILURE : self::SUCCESS;

        } catch (\Exception $e) {
            $this->error("❌ Error: {$e->getMessage()}");
            Log::error('SendBatchedNotifications command failed', [
                'error' => $e->getMessage(),
            ]);
            return self::FAILURE;
        }
    }

    /**
     * Process a single batch.
     */
    protected function processBatch(NotificationBatch $batch): void
    {
        // Validate batch
        if (empty($batch->items)) {
            $batch->markAsSkipped('Empty batch');
            return;
        }

        if (!$batch->shop || !$batch->shop->user) {
            $batch->markAsFailed('Shop or owner not found');
            return;
        }

        // Dispatch job to queue
        SendBatchNotification::dispatch($batch);

        Log::info('Batch notification job dispatched', [
            'batch_id' => $batch->id,
            'shop_id' => $batch->shop_id,
            'items' => count($batch->items),
        ]);
    }

    /**
     * Log processing error.
     */
    protected function logError(NotificationBatch $batch, \Exception $e): void
    {
        Log::error('Failed to process notification batch', [
            'batch_id' => $batch->id,
            'error' => $e->getMessage(),
        ]);

        try {
            $batch->markAsFailed($e->getMessage());
        } catch (\Exception $updateError) {
            // Ignore update errors
        }
    }

    /**
     * Display summary.
     */
    protected function displaySummary(int $processed, int $failed): void
    {
        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        $this->info("📊 Summary:");
        $this->info("   ✅ Dispatched: {$processed}");

        if ($failed > 0) {
            $this->warn("   ❌ Failed: {$failed}");
        }

        $this->info("━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
    }

    /**
     * Show what would be sent (dry run).
     */
    protected function dryRun(): int
    {
        $batches = NotificationBatch::query()
            ->where('status', 'pending')
            ->where('scheduled_for', '<=', now())
            ->with('shop')
            ->get();

        if ($batches->isEmpty()) {
            $this->info('✅ No pending batches to process.');
            return self::SUCCESS;
        }

        $this->info("🔍 DRY RUN - Found {$batches->count()} batch(es):");
        $this->newLine();

        $headers = ['ID', 'Shop', 'Frequency', 'Items', 'Scheduled For', 'Status'];
        $rows = [];

        foreach ($batches as $batch) {
            $rows[] = [
                $batch->id,
                $batch->shop?->shop_name ?? 'Unknown',
                $batch->frequency?->label() ?? 'N/A',
                count($batch->items ?? []),
                $batch->scheduled_for->format('Y-m-d H:i'),
                $this->getBatchStatus($batch),
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Get batch status indicator.
     */
    protected function getBatchStatus(NotificationBatch $batch): string
    {
        if (empty($batch->items)) {
            return '⚠️ Empty';
        }

        if (!$batch->shop || !$batch->shop->user) {
            return '❌ No owner';
        }

        return '✅ Ready';
    }
}