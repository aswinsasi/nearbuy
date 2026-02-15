<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\FlashDeal;
use App\Models\User;
use App\Services\FlashDeals\FlashDealNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to send flash deal alert to a single customer.
 *
 * This job is queued for each customer when a deal goes live,
 * allowing for efficient parallel processing of notifications.
 *
 * @srs-ref FD-009 - Notify ALL customers within radius when deal goes live
 * @module Flash Mob Deals
 */
class SendFlashDealAlertJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     */
    public int $backoff = 30;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public FlashDeal $deal,
        public User $customer
    ) {
        $this->onQueue('flash-deals');
    }

    /**
     * Execute the job.
     */
    public function handle(FlashDealNotificationService $notificationService): void
    {
        // Verify deal is still live before sending
        $this->deal->refresh();

        if (!$this->deal->is_live) {
            Log::debug('Flash deal no longer live, skipping notification', [
                'deal_id' => $this->deal->id,
                'customer_id' => $this->customer->id,
                'status' => $this->deal->status->value,
            ]);
            return;
        }

        // Verify customer hasn't already claimed
        if ($this->deal->hasUserClaimed($this->customer->id)) {
            Log::debug('Customer already claimed, skipping notification', [
                'deal_id' => $this->deal->id,
                'customer_id' => $this->customer->id,
            ]);
            return;
        }

        try {
            $notificationService->sendDealAlert($this->deal, $this->customer);

            Log::debug('Flash deal alert sent successfully', [
                'deal_id' => $this->deal->id,
                'customer_id' => $this->customer->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send flash deal alert', [
                'deal_id' => $this->deal->id,
                'customer_id' => $this->customer->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Re-throw to trigger retry
            throw $e;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Flash deal alert job failed permanently', [
            'deal_id' => $this->deal->id,
            'customer_id' => $this->customer->id,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): \DateTime
    {
        // Don't retry after deal expires
        return $this->deal->expires_at->toDateTime();
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'flash-deal',
            'flash-deal:' . $this->deal->id,
            'customer:' . $this->customer->id,
        ];
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return "flash_alert_{$this->deal->id}_{$this->customer->id}";
    }

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public function uniqueFor(): int
    {
        // Lock for the duration of the deal
        return max(60, $this->deal->time_remaining_seconds);
    }
}