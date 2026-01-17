<?php

namespace App\Jobs;

use App\Models\ProductRequest;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for processing product request notifications.
 *
 * Notifies all eligible shops about a new product request
 * based on their notification preferences.
 *
 * @example
 * ProcessProductRequestNotifications::dispatch($productRequest);
 */
class ProcessProductRequestNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 30;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public ProductRequest $request,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(NotificationService $notificationService): void
    {
        try {
            // Ensure request is still active
            if (!$this->isRequestActive()) {
                Log::info('Skipping notifications for inactive request', [
                    'request_id' => $this->request->id,
                    'status' => $this->request->status,
                ]);
                return;
            }

            $notifiedCount = $notificationService->notifyShopsOfRequest($this->request);

            Log::info('Product request notifications processed', [
                'request_id' => $this->request->id,
                'request_number' => $this->request->request_number,
                'shops_notified' => $notifiedCount,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to process product request notifications', [
                'request_id' => $this->request->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if the request is still active.
     */
    protected function isRequestActive(): bool
    {
        $this->request->refresh();

        return in_array($this->request->status->value ?? $this->request->status, ['open', 'collecting'])
            && $this->request->expires_at > now();
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessProductRequestNotifications job failed', [
            'request_id' => $this->request->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags for the job.
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