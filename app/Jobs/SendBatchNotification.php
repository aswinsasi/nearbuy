<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationBatch;
use App\Services\Notifications\NotificationService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job for sending batched notifications to shop owners.
 *
 * Processes a notification batch and sends a combined notification
 * summarizing all pending items (product requests, offers, etc.)
 *
 * FEATURES:
 * - Retry 3x with exponential backoff
 * - Unique job per batch (prevents duplicates)
 * - Tracks sent/failed counts
 * - Respects quiet hours via SendWhatsAppMessage
 *
 * @srs-ref NFR-R-02 - Failed deliveries retried with exponential backoff
 * @module Notifications
 *
 * @example
 * SendBatchNotification::dispatch($batch);
 */
class SendBatchNotification implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // =========================================================================
    // CONFIGURATION
    // =========================================================================

    /**
     * Retry 3 times.
     */
    public int $tries = 3;

    /**
     * Job timeout.
     */
    public int $timeout = 60;

    /**
     * Unique lock duration (5 minutes).
     */
    public int $uniqueFor = 300;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public NotificationBatch $batch,
    ) {
        $this->onQueue('notifications');
    }

    // =========================================================================
    // MIDDLEWARE & BACKOFF
    // =========================================================================

    /**
     * Middleware for rate limiting.
     */
    public function middleware(): array
    {
        return [
            new RateLimited('whatsapp-api'),
        ];
    }

    /**
     * Exponential backoff.
     */
    public function backoff(): array
    {
        return [60, 120, 240];
    }

    /**
     * Retry until 2 hours.
     */
    public function retryUntil(): Carbon
    {
        return now()->addHours(2);
    }

    /**
     * Unique ID based on batch.
     */
    public function uniqueId(): string
    {
        return 'batch_' . $this->batch->id;
    }

    // =========================================================================
    // EXECUTION
    // =========================================================================

    /**
     * Execute the job.
     */
    public function handle(WhatsAppService $whatsApp): void
    {
        $startTime = microtime(true);

        try {
            // Refresh batch status
            $this->batch->refresh();

            // Skip if already processed
            if ($this->batch->status !== 'pending') {
                Log::info('Batch already processed, skipping', [
                    'batch_id' => $this->batch->id,
                    'status' => $this->batch->status,
                ]);
                return;
            }

            // Check for items
            $items = $this->batch->items ?? [];
            if (empty($items)) {
                $this->batch->markAsSkipped('No items in batch');
                return;
            }

            // Get shop and phone
            $shop = $this->batch->shop;
            if (!$shop || !$shop->user || !$shop->user->phone) {
                $this->batch->markAsFailed('Shop owner phone not found');
                return;
            }

            $phone = $shop->user->phone;

            // Build and send the batch message
            $message = $this->buildBatchMessage($items, $shop);
            $response = $whatsApp->sendText($phone, $message);
            $messageId = is_array($response) ? ($response['id'] ?? null) : $response;

            // Update batch stats
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->markBatchSent($items, $messageId, $duration);

            Log::info('Batch notification sent', [
                'batch_id' => $this->batch->id,
                'shop_id' => $this->batch->shop_id,
                'items_count' => count($items),
                'duration_ms' => $duration,
            ]);

        } catch (\Exception $e) {
            $this->handleFailure($e);
            throw $e;
        }
    }

    /**
     * Build the batch message.
     */
    protected function buildBatchMessage(array $items, $shop): string
    {
        $count = count($items);
        $frequency = $this->batch->frequency?->label() ?? 'Batched';

        $message = "📬 *{$frequency} Summary*\n" .
            "*{$frequency} സമ്മറി*\n\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "🏪 *{$shop->shop_name}*\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "📊 *{$count} new notification" . ($count > 1 ? 's' : '') . "*\n\n";

        // Group items by type
        $grouped = collect($items)->groupBy('type');

        foreach ($grouped as $type => $typeItems) {
            $emoji = $this->getTypeEmoji($type);
            $label = $this->getTypeLabel($type);
            $typeCount = $typeItems->count();

            $message .= "{$emoji} *{$label}:* {$typeCount}\n";

            // Show first 3 items as preview
            foreach ($typeItems->take(3) as $item) {
                $preview = $this->getItemPreview($item);
                $message .= "   • {$preview}\n";
            }

            if ($typeCount > 3) {
                $more = $typeCount - 3;
                $message .= "   _+ {$more} more..._\n";
            }

            $message .= "\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━\n" .
            "Reply with item number to respond,\n" .
            "or type *menu* for options.";

        return $message;
    }

    /**
     * Get emoji for notification type.
     */
    protected function getTypeEmoji(string $type): string
    {
        return match ($type) {
            'product_request' => '📦',
            'offer_response' => '💰',
            'flash_deal' => '⚡',
            'job_request' => '👷',
            'agreement' => '📋',
            default => '📌',
        };
    }

    /**
     * Get label for notification type.
     */
    protected function getTypeLabel(string $type): string
    {
        return match ($type) {
            'product_request' => 'Product Requests',
            'offer_response' => 'Offer Responses',
            'flash_deal' => 'Flash Deals',
            'job_request' => 'Job Requests',
            'agreement' => 'Agreements',
            default => 'Notifications',
        };
    }

    /**
     * Get preview text for an item.
     */
    protected function getItemPreview(array $item): string
    {
        $description = $item['description'] ?? $item['title'] ?? 'Item';
        return mb_strlen($description) > 40
            ? mb_substr($description, 0, 37) . '...'
            : $description;
    }

    /**
     * Mark batch as sent and update stats.
     */
    protected function markBatchSent(array $items, ?string $messageId, float $duration): void
    {
        $this->batch->update([
            'status' => 'sent',
            'sent_at' => now(),
            'message_id' => $messageId,
            'total_items' => count($items),
            'sent_count' => count($items),
            'failed_count' => 0,
            'duration_ms' => $duration,
        ]);
    }

    /**
     * Handle job failure.
     */
    protected function handleFailure(\Exception $e): void
    {
        $this->batch->update([
            'status' => 'failed',
            'error' => $e->getMessage(),
            'failed_count' => ($this->batch->failed_count ?? 0) + 1,
        ]);

        Log::error('Failed to send batch notification', [
            'batch_id' => $this->batch->id,
            'attempt' => $this->attempts(),
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Handle permanent failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendBatchNotification job failed permanently', [
            'batch_id' => $this->batch->id,
            'error' => $exception->getMessage(),
        ]);

        $this->batch->update([
            'status' => 'failed',
            'error' => 'Job failed permanently: ' . $exception->getMessage(),
        ]);
    }

    /**
     * Get job tags.
     */
    public function tags(): array
    {
        return [
            'notifications',
            'batch',
            'batch:' . $this->batch->id,
            'shop:' . $this->batch->shop_id,
            'frequency:' . ($this->batch->frequency?->value ?? 'unknown'),
        ];
    }
}