<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Fish\ExpireStaleCatchesJob;
use App\Services\Fish\FishCatchService;
use Illuminate\Console\Command;

/**
 * Command to expire stale fish catches.
 *
 * Usage:
 * - php artisan fish:expire-catches
 * - php artisan fish:expire-catches --sync
 * - php artisan fish:expire-catches --dry-run
 *
 * @srs-ref Pacha Meen Module - Catch Lifecycle
 */
class ExpireStaleCatchesCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'fish:expire-catches 
                            {--sync : Run synchronously (no queue)}
                            {--dry-run : Show what would be expired without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Expire stale fish catches that have passed their expiry time';

    /**
     * Execute the console command.
     */
    public function handle(FishCatchService $catchService): int
    {
        $this->info('Checking for stale catches...');

        $dryRun = $this->option('dry-run');
        $sync = $this->option('sync');

        if ($dryRun) {
            return $this->handleDryRun($catchService);
        }

        if ($sync) {
            ExpireStaleCatchesJob::dispatchSync();
            $this->info('Stale catches expired (sync)');
        } else {
            ExpireStaleCatchesJob::dispatch();
            $this->info('Expire catches job dispatched');
        }

        return Command::SUCCESS;
    }

    /**
     * Handle dry run - show what would be expired.
     */
    protected function handleDryRun(FishCatchService $catchService): int
    {
        $staleCatches = \App\Models\FishCatch::query()
            ->where('status', 'available')
            ->where('expires_at', '<', now())
            ->with(['fishType', 'seller'])
            ->get();

        if ($staleCatches->isEmpty()) {
            $this->info('No stale catches found.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$staleCatches->count()} stale catches that would be expired:");
        $this->newLine();

        $headers = ['ID', 'Fish Type', 'Seller', 'Posted', 'Expired At'];
        $rows = $staleCatches->map(fn($catch) => [
            $catch->id,
            $catch->fishType->display_name ?? 'Unknown',
            $catch->seller->business_name ?? 'Unknown',
            $catch->created_at->diffForHumans(),
            $catch->expires_at->format('Y-m-d H:i'),
        ])->toArray();

        $this->table($headers, $rows);

        return Command::SUCCESS;
    }
}
