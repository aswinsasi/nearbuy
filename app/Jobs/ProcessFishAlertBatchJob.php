<?php

declare(strict_types=1);

namespace App\Jobs\Fish;

use App\Enums\FishAlertFrequency;
use App\Models\FishAlertBatch;
use App\Services\Fish\FishAlertService;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FishMessages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to process batched fish alerts (hourly/daily digests).
 *
 * This job is dispatched by the scheduler to:
 * - Process hourly alert batches
 * - Process daily alert batches
 *
 * @srs-ref Pacha Meen Module - Batch Alert Processing
 */
class ProcessFishAlertBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times to retry the job.
     */
    public int $tries = 3;

    /**
     * Backoff between retries (seconds).
     */
    public array $backoff = [60, 120, 300];

    /**
     * Create a new job instance.
     */
    public function __construct(
        public FishAlertFrequency $frequency
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        WhatsAppService $whatsApp,
        FishAlertService $alertService
    ): void {
        Log::info('Processing fish alert batches', [
            'frequency' => $this->frequency->value,
        ]);

        $processedCount = 0;
        $errorCount = 0;

        // Get ready batches for this frequency
        $batches = $alertService->getReadyBatches();

        foreach ($batches as $batch) {
            // Skip batches for different frequency
            if ($batch->subscription->frequency !== $this->frequency->value) {
                continue;
            }

            try {
                $this->processBatch($batch, $whatsApp, $alertService);
                $processedCount++;
            } catch (\Exception $e) {
                Log::error('Failed to process batch', [
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
                $alertService->markBatchFailed($batch, $e->getMessage());
                $errorCount++;
            }
        }

        Log::info('Batch processing completed', [
            'frequency' => $this->frequency->value,
            'processed' => $processedCount,
            'errors' => $errorCount,
        ]);
    }

    /**
     * Process a single batch.
     */
    protected function processBatch(
        FishAlertBatch $batch,
        WhatsAppService $whatsApp,
        FishAlertService $alertService
    ): void {
        // Skip if already sent
        if ($batch->sent_at) {
            return;
        }

        // Skip if subscription inactive
        if (!$batch->subscription || !$batch->subscription->isActive()) {
            $alertService->markBatchFailed($batch, 'Subscription inactive');
            return;
        }

        // Get alerts in this batch that have available catches
        $alerts = $batch->alerts()
            ->with(['catch.fishType', 'catch.seller'])
            ->whereHas('catch', function ($query) {
                $query->where('status', 'available');
            })
            ->get();

        if ($alerts->isEmpty()) {
            // No available catches, mark as sent (empty digest)
            $alertService->markBatchSent($batch, null);
            return;
        }

        // Get unique catches
        $catches = $alerts->pluck('catch')->unique('id');

        // Build digest message
        $messageData = $alertService->buildBatchMessageData($batch);

        if (!$messageData) {
            $alertService->markBatchFailed($batch, 'Failed to build message');
            return;
        }

        // Send message
        $result = $this->sendDigestMessage($whatsApp, $messageData);

        if ($result && isset($result['messages'][0]['id'])) {
            $messageId = $result['messages'][0]['id'];
            $alertService->markBatchSent($batch, $messageId);

            // Mark individual alerts as sent
            foreach ($alerts as $alert) {
                $alertService->markAlertSent($alert, $messageId);
            }

            Log::info('Batch digest sent', [
                'batch_id' => $batch->id,
                'message_id' => $messageId,
                'catches_count' => $catches->count(),
            ]);
        } else {
            throw new \Exception('No message ID in response');
        }
    }

    /**
     * Send the digest message.
     */
    protected function sendDigestMessage(WhatsAppService $whatsApp, array $data): array
    {
        $phone = $data['phone'];
        $type = $data['type'] ?? 'list';

        return match ($type) {
            'list' => $whatsApp->sendList(
                $phone,
                $data['body'],
                $data['button'] ?? 'View Fish',
                $data['sections'],
                $data['header'] ?? null,
                $data['footer'] ?? null
            ),
            'buttons' => $whatsApp->sendButtons(
                $phone,
                $data['body'],
                $data['buttons'],
                $data['header'] ?? null,
                $data['footer'] ?? null
            ),
            default => $whatsApp->sendText($phone, $data['body']),
        };
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessFishAlertBatchJob failed permanently', [
            'frequency' => $this->frequency->value,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags for the job.
     */
    public function tags(): array
    {
        return [
            'fish-batch',
            'frequency:' . $this->frequency->value,
        ];
    }
}
