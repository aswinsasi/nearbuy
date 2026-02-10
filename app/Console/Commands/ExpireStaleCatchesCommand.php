<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Fish\ExpireStaleCatchesJob;
use App\Models\FishCatch;
use Illuminate\Console\Command;

/**
 * Expire stale fish catches.
 *
 * @srs-ref PM-024: Auto-expire catch postings after 6 hours if not manually updated
 * @srs-ref PM-010: Auto-timestamp, calculate freshness
 *
 * Usage:
 *   php artisan fish:expire-catches             # Dispatch job (default)
 *   php artisan fish:expire-catches --sync      # Run synchronously
 *   php artisan fish:expire-catches --dry-run   # Preview only
 *   php artisan fish:expire-catches --hours=6   # Custom expiry (default: 6)
 *   php artisan fish:expire-catches --notify    # Notify sellers
 */
class ExpireStaleCatchesCommand extends Command
{
    protected $signature = 'fish:expire-catches 
                            {--sync : Run synchronously instead of queueing}
                            {--dry-run : Show what would be expired without changes}
                            {--hours=6 : Hours before auto-expire (PM-024: 6 hours)}
                            {--notify : Send WhatsApp notification to sellers}';

    protected $description = 'Expire stale fish catches (PM-024: auto-expire after 6 hours)';

    /**
     * SRS PM-024: 6 hours default.
     */
    public const DEFAULT_EXPIRY_HOURS = 6;

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');
        $sync = $this->option('sync');
        $hours = (int) $this->option('hours') ?: self::DEFAULT_EXPIRY_HOURS;
        $notify = $this->option('notify');

        $this->info("🐟 Checking catches older than {$hours} hours (PM-024)...");

        if ($dryRun) {
            return $this->dryRun($hours);
        }

        if ($sync) {
            ExpireStaleCatchesJob::dispatchSync($hours, $notify);
            $this->info('✅ Stale catches expired (sync)');
        } else {
            ExpireStaleCatchesJob::dispatch($hours, $notify);
            $this->info('✅ Expire job dispatched to queue');
        }

        return Command::SUCCESS;
    }

    /**
     * Preview what would be expired.
     */
    protected function dryRun(int $hours): int
    {
        $cutoff = now()->subHours($hours);

        $stale = FishCatch::query()
            ->whereIn('status', ['available', 'low_stock'])
            ->where('updated_at', '<', $cutoff)
            ->with(['fishType', 'seller'])
            ->get();

        if ($stale->isEmpty()) {
            $this->info('✅ No stale catches found.');
            return Command::SUCCESS;
        }

        $this->warn("Found {$stale->count()} stale catches (would be expired):");
        $this->newLine();

        $this->table(
            ['ID', 'Fish', 'Seller', 'Status', 'Posted', 'Last Update', 'Age'],
            $stale->map(fn($c) => [
                $c->id,
                $c->fishType?->display_name ?? '🐟',
                substr($c->seller?->business_name ?? '-', 0, 18),
                $c->status,
                $c->created_at->format('H:i'),
                $c->updated_at->format('H:i'),
                $c->updated_at->diffForHumans(['short' => true]),
            ])->toArray()
        );

        $this->newLine();
        $this->comment("Run without --dry-run to expire these catches.");

        return Command::SUCCESS;
    }
}