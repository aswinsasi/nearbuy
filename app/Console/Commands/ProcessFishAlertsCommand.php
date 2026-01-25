<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FishAlertFrequency;
use App\Jobs\Fish\ProcessFishAlertBatchJob;
use App\Jobs\Fish\SendFishAlertJob;
use App\Services\Fish\FishAlertService;
use Illuminate\Console\Command;

/**
 * Command to process pending fish alerts.
 *
 * Usage:
 * - php artisan fish:process-alerts --immediate
 * - php artisan fish:process-alerts --morning
 * - php artisan fish:process-alerts --twice-daily
 * - php artisan fish:process-alerts --weekly
 * - php artisan fish:process-alerts --all
 *
 * @srs-ref Pacha Meen Module - Alert Processing
 */
class ProcessFishAlertsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'fish:process-alerts 
                            {--immediate : Process immediate alerts}
                            {--morning : Process morning-only batches}
                            {--twice-daily : Process twice-daily batches}
                            {--weekly : Process weekly digest batches}
                            {--all : Process all pending alerts}
                            {--sync : Process synchronously (no queue)}';

    /**
     * The console command description.
     */
    protected $description = 'Process pending fish alert notifications';

    /**
     * Execute the console command.
     */
    public function handle(FishAlertService $alertService): int
    {
        $this->info('Processing fish alerts...');

        $processImmediate = $this->option('immediate') || $this->option('all');
        $processMorning = $this->option('morning') || $this->option('all');
        $processTwiceDaily = $this->option('twice-daily') || $this->option('all');
        $processWeekly = $this->option('weekly') || $this->option('all');
        $sync = $this->option('sync');

        // Default to processing immediate if no options specified
        if (!$processImmediate && !$processMorning && !$processTwiceDaily && !$processWeekly) {
            $processImmediate = true;
        }

        $totalProcessed = 0;

        // Process immediate alerts
        if ($processImmediate) {
            $count = $this->processImmediateAlerts($alertService, $sync);
            $this->info("Dispatched {$count} immediate alerts");
            $totalProcessed += $count;
        }

        // Process morning-only batches
        if ($processMorning) {
            $this->processFrequencyBatch(FishAlertFrequency::MORNING_ONLY, $sync);
            $this->info('Dispatched morning-only batch processing');
        }

        // Process twice-daily batches
        if ($processTwiceDaily) {
            $this->processFrequencyBatch(FishAlertFrequency::TWICE_DAILY, $sync);
            $this->info('Dispatched twice-daily batch processing');
        }

        // Process weekly batches
        if ($processWeekly) {
            $this->processFrequencyBatch(FishAlertFrequency::WEEKLY_DIGEST, $sync);
            $this->info('Dispatched weekly batch processing');
        }

        $this->info("Alert processing completed. Total dispatched: {$totalProcessed}");

        return Command::SUCCESS;
    }

    /**
     * Process immediate (instant) alerts.
     */
    protected function processImmediateAlerts(FishAlertService $alertService, bool $sync): int
    {
        $alerts = $alertService->getReadyAlerts(100);
        $count = 0;

        foreach ($alerts as $alert) {
            // Only process immediate alerts here
            if ($alert->subscription?->frequency !== FishAlertFrequency::IMMEDIATE->value) {
                continue;
            }

            if ($sync) {
                SendFishAlertJob::dispatchSync($alert);
            } else {
                SendFishAlertJob::dispatch($alert);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Process batch alerts for a frequency.
     */
    protected function processFrequencyBatch(FishAlertFrequency $frequency, bool $sync): void
    {
        if ($sync) {
            ProcessFishAlertBatchJob::dispatchSync($frequency);
        } else {
            ProcessFishAlertBatchJob::dispatch($frequency);
        }
    }
}
