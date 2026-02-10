<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FishAlertFrequency;
use App\Jobs\Fish\ProcessFishAlertBatchJob;
use App\Jobs\Fish\SendFishAlertJob;
use App\Models\FishAlert;
use Illuminate\Console\Command;

/**
 * Process pending fish alerts.
 *
 * Fish alerts are TIME-SENSITIVE - must deliver within 2 minutes.
 *
 * Usage:
 *   php artisan fish:process-alerts                      # Process all ready
 *   php artisan fish:process-alerts --immediate          # ANYTIME subscribers only
 *   php artisan fish:process-alerts --frequency=morning  # Specific frequency
 *   php artisan fish:process-alerts --sync               # Run synchronously
 *
 * @srs-ref PM-016 to PM-020: Alert Delivery
 * @srs-ref PM-014: Alert time preferences (Early Morning 5-7, Morning 7-9, Anytime)
 */
class ProcessFishAlertsCommand extends Command
{
    protected $signature = 'fish:process-alerts 
                            {--immediate : Only ANYTIME (instant) alerts}
                            {--early-morning : Process early morning batch (5-7 AM)}
                            {--morning : Process morning batch (7-9 AM)}
                            {--twice-daily : Process twice daily batch (6 AM & 4 PM)}
                            {--frequency= : Specific frequency (anytime, early_morning, morning, twice_daily)}
                            {--all : Process all frequencies}
                            {--limit=100 : Max alerts per run}
                            {--sync : Process synchronously}';

    protected $description = 'Process pending fish alert notifications (time-sensitive - 2min target)';

    public function handle(): int
    {
        $sync = $this->option('sync');
        $limit = (int) $this->option('limit');
        $all = $this->option('all');

        $this->info('🐟 Processing fish alerts...');

        $total = 0;

        // Specific frequency option
        if ($freq = $this->option('frequency')) {
            $frequency = FishAlertFrequency::tryFrom($freq);
            if (!$frequency) {
                $this->error("Invalid frequency: {$freq}");
                $this->line('Valid: anytime, early_morning, morning, twice_daily');
                return Command::FAILURE;
            }
            $total = $this->processFrequency($frequency, $limit, $sync);
        }
        // Process all
        elseif ($all) {
            $total += $this->processImmediate($limit, $sync);
            $total += $this->dispatchBatchJobs($sync);
        }
        // Individual frequency flags
        elseif ($this->option('immediate')) {
            $total = $this->processImmediate($limit, $sync);
        }
        elseif ($this->option('early-morning')) {
            $total = $this->processFrequency(FishAlertFrequency::EARLY_MORNING, $limit, $sync);
        }
        elseif ($this->option('morning')) {
            $total = $this->processFrequency(FishAlertFrequency::MORNING, $limit, $sync);
        }
        elseif ($this->option('twice-daily')) {
            $total = $this->processFrequency(FishAlertFrequency::TWICE_DAILY, $limit, $sync);
        }
        // Default: process immediate alerts (most common case)
        else {
            $total = $this->processImmediate($limit, $sync);
        }

        $this->info("✅ Processed/dispatched {$total} alerts");

        return Command::SUCCESS;
    }

    /**
     * Process immediate (ANYTIME) alerts - highest priority.
     * These go out instantly when fish arrives.
     */
    protected function processImmediate(int $limit, bool $sync): int
    {
        $alerts = FishAlert::query()
            ->where('status', FishAlert::STATUS_PENDING)
            ->where(function ($q) {
                $q->whereNull('scheduled_for')
                    ->orWhere('scheduled_for', '<=', now());
            })
            ->whereHas('subscription', fn($q) => 
                $q->where('alert_frequency', FishAlertFrequency::ANYTIME)
                    ->where('is_active', true)
                    ->where('is_paused', false)
            )
            ->orderBy('distance_km') // Nearest first for viral effect
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        $this->line("Found {$alerts->count()} immediate alerts");

        return $this->dispatchAlerts($alerts, $sync);
    }

    /**
     * Process alerts for a specific frequency.
     */
    protected function processFrequency(FishAlertFrequency $frequency, int $limit, bool $sync): int
    {
        // ANYTIME = immediate processing
        if ($frequency === FishAlertFrequency::ANYTIME) {
            return $this->processImmediate($limit, $sync);
        }

        // Check time window for scheduled frequencies
        if (!$frequency->isWithinWindow()) {
            $this->warn("⏰ Not within {$frequency->label()} window. Use --sync to force.");
            if (!$sync) {
                return 0;
            }
        }

        $this->line("Processing {$frequency->label()} batch...");

        if ($sync) {
            ProcessFishAlertBatchJob::dispatchSync($frequency);
        } else {
            ProcessFishAlertBatchJob::dispatch($frequency)->onQueue('fish-alerts');
        }

        return 1; // Job dispatched
    }

    /**
     * Dispatch batch jobs for all scheduled frequencies.
     */
    protected function dispatchBatchJobs(bool $sync): int
    {
        $count = 0;

        foreach ([FishAlertFrequency::EARLY_MORNING, FishAlertFrequency::MORNING, FishAlertFrequency::TWICE_DAILY] as $freq) {
            if ($freq->isWithinWindow()) {
                $this->line("Dispatching {$freq->value} batch...");
                
                if ($sync) {
                    ProcessFishAlertBatchJob::dispatchSync($freq);
                } else {
                    ProcessFishAlertBatchJob::dispatch($freq)->onQueue('fish-alerts');
                }
                $count++;
            }
        }

        return $count;
    }

    /**
     * Dispatch individual alert jobs.
     */
    protected function dispatchAlerts($alerts, bool $sync): int
    {
        $count = 0;

        foreach ($alerts as $alert) {
            if ($sync) {
                SendFishAlertJob::dispatchSync($alert);
            } else {
                SendFishAlertJob::dispatch($alert)->onQueue('fish-alerts');
            }
            $count++;
        }

        return $count;
    }
}