<?php

namespace App\Console\Commands;

use App\Models\ConversationSession;
use App\Models\NotificationBatch;
use App\Models\ProductRequest;
use App\Models\WhatsAppMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Clean up old data from the database.
 *
 * Removes old sessions, notification batches, and closed requests.
 *
 * @example
 * php artisan nearbuy:cleanup-old-data
 * php artisan nearbuy:cleanup-old-data --days=30
 */
class CleanupOldData extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nearbuy:cleanup-old-data
                            {--days=30 : Number of days to keep data}
                            {--dry-run : Show what would be deleted without deleting}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up old sessions, batches, and expired data';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $dryRun = $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $this->info("Cleaning up data older than {$days} days (before {$cutoff->format('Y-m-d')})...");

        if ($dryRun) {
            $this->warn('DRY RUN - No data will be deleted.');
        }

        $results = [
            'sessions' => $this->cleanupSessions($cutoff, $dryRun),
            'batches' => $this->cleanupNotificationBatches($cutoff, $dryRun),
            'requests' => $this->cleanupProductRequests($cutoff, $dryRun),
            'messages' => $this->cleanupMessages($cutoff, $dryRun),
        ];

        $this->newLine();
        $this->info('Cleanup Summary:');

        $headers = ['Type', 'Count'];
        $rows = collect($results)->map(fn($count, $type) => [
            ucfirst($type),
            $dryRun ? "{$count} (would delete)" : "{$count} deleted",
        ])->toArray();

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    /**
     * Clean up old conversation sessions.
     */
    protected function cleanupSessions(\Carbon\Carbon $cutoff, bool $dryRun): int
    {
        $query = ConversationSession::query()
            ->where('updated_at', '<', $cutoff);

        $count = $query->count();

        if (!$dryRun && $count > 0) {
            $query->delete();
            $this->info("  ✓ Deleted {$count} old session(s)");
        }

        return $count;
    }

    /**
     * Clean up old notification batches.
     */
    protected function cleanupNotificationBatches(\Carbon\Carbon $cutoff, bool $dryRun): int
    {
        $query = NotificationBatch::query()
            ->whereIn('status', ['sent', 'skipped', 'failed'])
            ->where('updated_at', '<', $cutoff);

        $count = $query->count();

        if (!$dryRun && $count > 0) {
            $query->delete();
            $this->info("  ✓ Deleted {$count} old notification batch(es)");
        }

        return $count;
    }

    /**
     * Clean up old product requests.
     */
    protected function cleanupProductRequests(\Carbon\Carbon $cutoff, bool $dryRun): int
    {
        $query = ProductRequest::query()
            ->whereIn('status', ['closed', 'expired'])
            ->where('updated_at', '<', $cutoff);

        $count = $query->count();

        if (!$dryRun && $count > 0) {
            // Delete related responses first
            $requestIds = $query->pluck('id');
            DB::table('product_responses')
                ->whereIn('request_id', $requestIds)
                ->delete();

            $query->delete();
            $this->info("  ✓ Deleted {$count} old product request(s) and their responses");
        }

        return $count;
    }

    /**
     * Clean up old WhatsApp messages log.
     */
    protected function cleanupMessages(\Carbon\Carbon $cutoff, bool $dryRun): int
    {
        $query = WhatsAppMessage::query()
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if (!$dryRun && $count > 0) {
            $query->delete();
            $this->info("  ✓ Deleted {$count} old message log(s)");
        }

        return $count;
    }
}