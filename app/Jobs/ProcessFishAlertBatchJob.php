<?php

declare(strict_types=1);

namespace App\Jobs\Fish;

use App\Enums\FishAlertFrequency;
use App\Models\FishAlert;
use App\Services\Fish\FishAlertService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Process Fish Alert Batch Job.
 *
 * Scheduled job to process batched alerts for users with
 * time preferences (PM-020):
 * - Early Morning (5-7 AM)
 * - Morning (7-9 AM)
 * - Twice Daily (6 AM, 4 PM)
 *
 * Run via scheduler at appropriate times.
 *
 * @srs-ref PM-020 Respect alert time preferences
 */
class ProcessFishAlertBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [60, 120, 300];
    public int $timeout = 300; // 5 mins for batch

    public function __construct(
        public ?FishAlertFrequency $frequency = null
    ) {}

    public function handle(FishAlertService $alertService): void
    {
        Log::info('Processing fish alert batch', [
            'frequency' => $this->frequency?->value ?? 'all',
        ]);

        // Get scheduled alerts that are ready
        $query = FishAlert::queued()
            ->readyToSend()
            ->with(['catch', 'subscription', 'user'])
            ->nearestFirst(); // Nearest first

        // Filter by frequency if specified
        if ($this->frequency) {
            $query->whereHas('subscription', function ($q) {
                $q->where('alert_frequency', $this->frequency->value);
            });
        }

        $alerts = $query->limit(500)->get(); // Process up to 500

        if ($alerts->isEmpty()) {
            Log::info('No scheduled alerts to process');
            return;
        }

        $sent = 0;
        $failed = 0;
        $skipped = 0;

        // Process in batches with delay
        $batches = $alerts->chunk(FishAlertService::BATCH_SIZE);

        foreach ($batches as $batch) {
            foreach ($batch as $alert) {
                // Skip if catch no longer active
                if (!$alert->catch?->is_active) {
                    $alert->markFailed('Catch expired');
                    $skipped++;
                    continue;
                }

                // Dispatch individual send job
                SendFishAlertJob::dispatch($alert)
                    ->onQueue('fish-alerts');

                $sent++;
            }

            // Delay between batches
            usleep(FishAlertService::BATCH_DELAY_SECONDS * 1000000);
        }

        Log::info('Batch processing complete', [
            'frequency' => $this->frequency?->value ?? 'all',
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
        ]);
    }

    /**
     * Handle failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessFishAlertBatchJob failed', [
            'frequency' => $this->frequency?->value ?? 'all',
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Job tags.
     */
    public function tags(): array
    {
        return [
            'fish-batch',
            'frequency:' . ($this->frequency?->value ?? 'all'),
        ];
    }
}