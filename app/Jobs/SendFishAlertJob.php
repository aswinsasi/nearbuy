<?php

declare(strict_types=1);

namespace App\Jobs\Fish;

use App\Models\FishAlert;
use App\Services\Fish\FishAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send Fish Alert Job - Individual alert delivery.
 *
 * Priority queue for fast delivery - target <2 mins from posting.
 *
 * @srs-ref PM-016 Alert delivery
 * @srs-ref PM-017 Include all info
 * @srs-ref PM-018 Include buttons
 * @srs-ref PM-019 Social proof
 */
class SendFishAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Retry attempts.
     */
    public int $tries = 3;

    /**
     * Backoff between retries (seconds).
     */
    public array $backoff = [10, 30, 60];

    /**
     * Timeout (seconds).
     */
    public int $timeout = 30;

    /**
     * Delete if model missing.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public FishAlert $alert
    ) {
        // Use priority queue
        $this->onQueue('fish-alerts');
    }

    public function handle(FishAlertService $alertService): void
    {
        // Skip if already processed
        if ($this->alert->sent_at || $this->alert->failed_at) {
            Log::debug('Alert already processed', ['alert_id' => $this->alert->id]);
            return;
        }

        // Check if scheduled for later (PM-020)
        if ($this->alert->scheduled_for && $this->alert->scheduled_for->isFuture()) {
            // Re-dispatch for scheduled time
            self::dispatch($this->alert)
                ->delay($this->alert->scheduled_for);
            return;
        }

        // Check if catch still active
        $catch = $this->alert->catch;
        if (!$catch || !$catch->is_active) {
            $this->alert->markFailed('Catch no longer active');
            Log::info('Alert skipped - catch inactive', [
                'alert_id' => $this->alert->id,
                'catch_id' => $this->alert->fish_catch_id,
            ]);
            return;
        }

        // Check if subscription active
        $subscription = $this->alert->subscription;
        if (!$subscription || !$subscription->is_active) {
            $this->alert->markFailed('Subscription inactive');
            return;
        }

        // Send the alert
        $success = $alertService->sendAlert($this->alert);

        if (!$success && $this->attempts() >= $this->tries) {
            Log::warning('Alert failed after all retries', [
                'alert_id' => $this->alert->id,
                'attempts' => $this->attempts(),
            ]);
        }
    }

    /**
     * Handle failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendFishAlertJob failed permanently', [
            'alert_id' => $this->alert->id,
            'error' => $exception->getMessage(),
        ]);

        $this->alert->markFailed($exception->getMessage());
    }

    /**
     * Job tags for monitoring.
     */
    public function tags(): array
    {
        return [
            'fish-alert',
            'alert:' . $this->alert->id,
            'catch:' . $this->alert->fish_catch_id,
        ];
    }
}