<?php

declare(strict_types=1);

namespace App\Jobs\Fish;

use App\Services\Fish\FishCatchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to expire stale fish catches.
 *
 * Runs periodically to:
 * - Mark old catches as expired
 * - Clean up abandoned postings
 * - Update stock status based on time
 *
 * @srs-ref Pacha Meen Module - Catch Lifecycle Management
 */
class ExpireStaleCatchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Number of times to retry the job.
     */
    public int $tries = 1;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(FishCatchService $catchService): void
    {
        Log::info('Starting stale catch expiration');

        try {
            $expiredCount = $catchService->expireStale();

            Log::info('Stale catch expiration completed', [
                'expired_count' => $expiredCount,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to expire stale catches', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the tags for the job.
     */
    public function tags(): array
    {
        return ['fish-maintenance', 'expire-catches'];
    }
}
