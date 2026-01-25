<?php

declare(strict_types=1);

namespace App\Jobs\Fish;

use App\Models\FishAlert;
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
 * Job to send individual fish alert notifications.
 *
 * This job is dispatched when:
 * - A new catch is posted (immediate alerts)
 * - Stock status changes (low stock alerts)
 *
 * @srs-ref Pacha Meen Module - Alert Delivery
 */
class SendFishAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times to retry the job.
     */
    public int $tries = 3;

    /**
     * Backoff between retries (seconds).
     */
    public array $backoff = [30, 60, 120];

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public FishAlert $alert
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        WhatsAppService $whatsApp,
        FishAlertService $alertService
    ): void {
        // Skip if already sent or failed
        if ($this->alert->sent_at || $this->alert->failed_at) {
            Log::debug('Alert already processed', ['alert_id' => $this->alert->id]);
            return;
        }

        // Skip if catch is no longer available
        if (!$this->alert->catch || !$this->alert->catch->isAvailable()) {
            Log::info('Catch no longer available, skipping alert', [
                'alert_id' => $this->alert->id,
                'catch_id' => $this->alert->fish_catch_id,
            ]);
            $alertService->markAlertFailed($this->alert, 'Catch no longer available');
            return;
        }

        // Skip if subscription is paused or inactive
        if (!$this->alert->subscription || !$this->alert->subscription->isActive()) {
            Log::info('Subscription inactive, skipping alert', [
                'alert_id' => $this->alert->id,
                'subscription_id' => $this->alert->fish_subscription_id,
            ]);
            $alertService->markAlertFailed($this->alert, 'Subscription inactive');
            return;
        }

        try {
            // Build alert message
            $messageData = $alertService->buildAlertMessageData($this->alert);

            // Determine message type and send
            $result = $this->sendMessage($whatsApp, $messageData);

            if ($result && isset($result['messages'][0]['id'])) {
                $messageId = $result['messages'][0]['id'];
                $alertService->markAlertSent($this->alert, $messageId);

                Log::info('Fish alert sent successfully', [
                    'alert_id' => $this->alert->id,
                    'message_id' => $messageId,
                    'phone' => $this->maskPhone($messageData['phone']),
                ]);
            } else {
                throw new \Exception('No message ID in response');
            }

        } catch (\Exception $e) {
            Log::error('Failed to send fish alert', [
                'alert_id' => $this->alert->id,
                'error' => $e->getMessage(),
            ]);

            // Mark as failed on last attempt
            if ($this->attempts() >= $this->tries) {
                $alertService->markAlertFailed($this->alert, $e->getMessage());
            }

            throw $e;
        }
    }

    /**
     * Send the WhatsApp message based on type.
     */
    protected function sendMessage(WhatsAppService $whatsApp, array $data): array
    {
        $phone = $data['phone'];
        $type = $data['type'] ?? 'buttons';

        return match ($type) {
            'buttons' => $whatsApp->sendButtons(
                $phone,
                $data['body'],
                $data['buttons'],
                $data['header'] ?? null,
                $data['footer'] ?? null
            ),
            'text' => $whatsApp->sendText($phone, $data['body']),
            'image' => $whatsApp->sendImage($phone, $data['image_url'], $data['caption'] ?? null),
            default => $whatsApp->sendText($phone, $data['body']),
        };
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendFishAlertJob failed permanently', [
            'alert_id' => $this->alert->id,
            'error' => $exception->getMessage(),
        ]);

        // Mark as failed
        $this->alert->update([
            'failed_at' => now(),
            'failure_reason' => substr($exception->getMessage(), 0, 255),
        ]);
    }

    /**
     * Get the tags for the job.
     */
    public function tags(): array
    {
        return [
            'fish-alert',
            'alert:' . $this->alert->id,
            'catch:' . $this->alert->fish_catch_id,
        ];
    }

    /**
     * Mask phone number for logging.
     */
    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return $phone;
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }
}
