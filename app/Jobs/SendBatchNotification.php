<?php

namespace App\Jobs;

use App\Models\NotificationBatch;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for sending batched notifications.
 *
 * Processes a notification batch and sends combined notification
 * to the shop owner.
 *
 * @example
 * SendBatchNotification::dispatch($batch);
 */
class SendBatchNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public NotificationBatch $batch,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(NotificationService $notificationService): void
    {
        try {
            // Refresh to get latest status
            $this->batch->refresh();

            // Skip if already processed
            if ($this->batch->status !== 'pending') {
                Log::info('Batch already processed, skipping', [
                    'batch_id' => $this->batch->id,
                    'status' => $this->batch->status,
                ]);
                return;
            }

            // Check if batch has items
            $items = $this->batch->items ?? [];
            if (empty($items)) {
                $this->batch->update([
                    'status' => 'skipped',
                    'error' => 'No items in batch',
                ]);
                return;
            }

            // Send the batch
            $notificationService->sendBatchedNotifications($this->batch);

            Log::info('Batch notification sent via job', [
                'batch_id' => $this->batch->id,
                'shop_id' => $this->batch->shop_id,
                'item_count' => count($items),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send batch notification', [
                'batch_id' => $this->batch->id,
                'error' => $e->getMessage(),
            ]);

            $this->batch->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendBatchNotification job failed', [
            'batch_id' => $this->batch->id,
            'error' => $exception->getMessage(),
        ]);

        $this->batch->update([
            'status' => 'failed',
            'error' => 'Job failed: ' . $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags for the job.
     */
    public function tags(): array
    {
        return [
            'notifications',
            'batch',
            'batch:' . $this->batch->id,
            'shop:' . $this->batch->shop_id,
        ];
    }
}