<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Fish\FishAlertService;
use Illuminate\Console\Command;

/**
 * Command to clean up old fish alerts and batches.
 *
 * Usage:
 * - php artisan fish:cleanup-alerts
 * - php artisan fish:cleanup-alerts --days=60
 * - php artisan fish:cleanup-alerts --dry-run
 *
 * @srs-ref Pacha Meen Module - Data Maintenance
 */
class CleanupOldAlertsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'fish:cleanup-alerts 
                            {--days=30 : Delete alerts older than this many days}
                            {--dry-run : Show count without deleting}';

    /**
     * The console command description.
     */
    protected $description = 'Clean up old fish alerts and batches from the database';

    /**
     * Execute the console command.
     */
    public function handle(FishAlertService $alertService): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');

        $this->info("Cleaning up alerts older than {$days} days...");

        if ($dryRun) {
            $count = \App\Models\FishAlert::where('created_at', '<', now()->subDays($days))->count();
            $batchCount = \App\Models\FishAlertBatch::where('created_at', '<', now()->subDays($days))->count();

            $this->info("Would delete {$count} alerts and {$batchCount} batches");
            return Command::SUCCESS;
        }

        $deletedCount = $alertService->cleanupOldAlerts($days);

        $this->info("Cleanup completed. Deleted {$deletedCount} old alerts.");

        return Command::SUCCESS;
    }
}
