<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationFrequency;
use App\Models\PendingShopNotification;
use App\Models\ProductRequest;
use App\Models\Shop;
use App\Services\WhatsApp\Messages\ProductMessages;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process Product Request Notifications.
 *
 * Notifies eligible shops about new product requests.
 * Respects notification_frequency preference.
 *
 * @srs-ref FR-PRD-10 - Respect shop notification_frequency preference
 * @srs-ref FR-PRD-11 - Immediate notifications for "immediate" shops
 * @srs-ref FR-PRD-12 - Batch for 2hours/twice_daily/daily shops
 * @srs-ref FR-PRD-13 - Include customer distance in notification
 * @srs-ref FR-PRD-14 - Provide Yes/No/Skip options
 */
class ProcessProductRequestNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function __construct(
        public ProductRequest $request
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WhatsAppService $whatsApp): void
    {
        try {
            // Check if request is still active
            if (!$this->isRequestActive()) {
                Log::info('Skipping notifications - request inactive', [
                    'request_id' => $this->request->id,
                ]);
                return;
            }

            // Find eligible shops
            $shops = $this->request->findEligibleShops();

            if ($shops->isEmpty()) {
                Log::info('No eligible shops for request', [
                    'request_id' => $this->request->id,
                ]);
                return;
            }

            $immediateCount = 0;
            $batchedCount = 0;

            foreach ($shops as $shop) {
                $frequency = $shop->notification_frequency ?? NotificationFrequency::IMMEDIATE;

                // FR-PRD-10: Respect notification_frequency preference
                if ($frequency === NotificationFrequency::IMMEDIATE || $frequency->value === 'immediate') {
                    // FR-PRD-11: Send immediately
                    $this->sendImmediateNotification($whatsApp, $shop);
                    $immediateCount++;
                } else {
                    // FR-PRD-12: Queue for batch
                    $this->queueForBatch($shop);
                    $batchedCount++;
                }
            }

            // Update request with notified count
            $this->request->recordShopsNotified($immediateCount + $batchedCount);

            Log::info('Product request notifications processed', [
                'request_id' => $this->request->id,
                'request_number' => $this->request->request_number,
                'immediate' => $immediateCount,
                'batched' => $batchedCount,
                'total' => $shops->count(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process notifications', [
                'request_id' => $this->request->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Send immediate notification to shop.
     *
     * @srs-ref FR-PRD-11 - Immediate notifications
     * @srs-ref FR-PRD-13 - Include customer distance
     * @srs-ref FR-PRD-14 - Yes/No/Skip buttons
     */
    protected function sendImmediateNotification(WhatsAppService $whatsApp, Shop $shop): void
    {
        $owner = $shop->owner;
        if (!$owner) return;

        $distance = $shop->distance_km ?? 0;
        $category = $this->request->category?->label() ?? 'Product';

        // FR-PRD-13: Include customer distance
        // MAX 3 lines notification
        $message = "🔍 *Product Request!* #{$this->request->request_number}\n" .
            "'{$this->truncate($this->request->description, 60)}'\n" .
            "{$category} • " . ProductMessages::formatDistance($distance) . " away";

        // Send with image if available
        if ($this->request->image_url) {
            $whatsApp->sendImage($owner->phone, $this->request->image_url, $message);
        } else {
            $whatsApp->sendText($owner->phone, $message);
        }

        // FR-PRD-14: Yes I Have / Don't Have / Skip buttons
        $whatsApp->sendButtons(
            $owner->phone,
            "Ee product undoo?",
            [
                ['id' => "yes_{$this->request->id}", 'title' => '✅ Yes I Have'],
                ['id' => "no_{$this->request->id}", 'title' => "❌ Don't Have"],
                ['id' => "skip_{$this->request->id}", 'title' => '⏭️ Skip'],
            ]
        );
    }

    /**
     * Queue notification for batch delivery.
     *
     * @srs-ref FR-PRD-12 - Batch for 2hours/twice_daily/daily shops
     */
    protected function queueForBatch(Shop $shop): void
    {
        // Store in pending notifications table
        // Will be processed by SendBatchedShopNotifications job
        try {
            PendingShopNotification::create([
                'shop_id' => $shop->id,
                'request_id' => $this->request->id,
                'distance_km' => $shop->distance_km ?? 0,
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Table might not exist yet, log and continue
            Log::warning('Could not queue batch notification', [
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Check if request is still active.
     */
    protected function isRequestActive(): bool
    {
        $this->request->refresh();
        return $this->request->isOpen();
    }

    /**
     * Truncate text.
     */
    protected function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;
        return mb_substr($text, 0, $max - 1) . '…';
    }

    /**
     * Handle failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessProductRequestNotifications failed', [
            'request_id' => $this->request->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Job tags.
     */
    public function tags(): array
    {
        return [
            'notifications',
            'product-request',
            'request:' . $this->request->id,
        ];
    }
}