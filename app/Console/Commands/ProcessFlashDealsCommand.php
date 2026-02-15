<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\FlashDealStatus;
use App\Models\FlashDeal;
use App\Services\FlashDeals\FlashDealNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Process flash deals lifecycle - launch scheduled deals and expire timed-out ones.
 *
 * Schedule: Run every minute
 *
 * @example
 * php artisan flash-deals:process
 * php artisan flash-deals:process --dry-run
 *
 * @srs-ref Flash Mob Deals Module - Deal Lifecycle
 * @schedule Run every minute: $schedule->command('flash-deals:process')->everyMinute();
 */
class ProcessFlashDealsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'flash-deals:process
                            {--dry-run : Show what would happen without making changes}';

    /**
     * The console command description.
     */
    protected $description = 'Process flash deals: launch scheduled deals and expire timed-out ones';

    /**
     * Execute the console command.
     */
    public function handle(FlashDealNotificationService $notificationService): int
    {
        $this->info('⚡ Processing flash deals...');

        $launched = $this->launchScheduledDeals($notificationService);
        $expired = $this->expireTimedOutDeals($notificationService);

        $this->newLine();
        $this->info("✅ Complete: {$launched} launched, {$expired} expired.");

        return self::SUCCESS;
    }

    /**
     * Launch scheduled deals that are ready to go live.
     */
    protected function launchScheduledDeals(FlashDealNotificationService $notificationService): int
    {
        $deals = FlashDeal::readyToLaunch()->with('shop')->get();

        if ($deals->isEmpty()) {
            $this->info('  No scheduled deals ready to launch.');
            return 0;
        }

        $this->info("  Found {$deals->count()} deal(s) ready to launch.");

        if ($this->option('dry-run')) {
            foreach ($deals as $deal) {
                $this->line("    - [{$deal->id}] {$deal->title} @ {$deal->shop->shop_name}");
            }
            return 0;
        }

        $launched = 0;

        foreach ($deals as $deal) {
            try {
                // Update status to LIVE
                $deal->goLive();

                // Send notifications to nearby customers
                $notified = $notificationService->sendDealLiveNotifications($deal);

                $this->info("    ✓ Launched [{$deal->id}] {$deal->title} - {$notified} customers notified");
                $launched++;

                Log::info('Flash deal launched by scheduler', [
                    'deal_id' => $deal->id,
                    'title' => $deal->title,
                    'customers_notified' => $notified,
                ]);

            } catch (\Exception $e) {
                $this->error("    ✗ Failed to launch [{$deal->id}]: {$e->getMessage()}");
                Log::error('Failed to launch flash deal', [
                    'deal_id' => $deal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $launched;
    }

    /**
     * Expire deals that have passed their expiration time.
     */
    protected function expireTimedOutDeals(FlashDealNotificationService $notificationService): int
    {
        $deals = FlashDeal::needsExpiration()->with(['shop', 'claims'])->get();

        if ($deals->isEmpty()) {
            $this->info('  No deals to expire.');
            return 0;
        }

        $this->info("  Found {$deals->count()} deal(s) to expire.");

        if ($this->option('dry-run')) {
            foreach ($deals as $deal) {
                $this->line("    - [{$deal->id}] {$deal->title} ({$deal->progress_display})");
            }
            return 0;
        }

        $expired = 0;

        foreach ($deals as $deal) {
            try {
                // Check if target was reached (last-second activation)
                if ($deal->current_claims >= $deal->target_claims) {
                    $deal->activate();
                    $notificationService->sendActivationNotifications($deal);
                    $this->info("    ✓ Activated [{$deal->id}] {$deal->title} (target reached at expiry)");
                } else {
                    // Expire the deal
                    $deal->expire();
                    $notificationService->sendExpiryNotifications($deal);
                    $this->info("    ✗ Expired [{$deal->id}] {$deal->title} ({$deal->progress_display})");
                }

                $expired++;

                Log::info('Flash deal expired by scheduler', [
                    'deal_id' => $deal->id,
                    'title' => $deal->title,
                    'final_claims' => $deal->current_claims,
                    'target' => $deal->target_claims,
                    'was_activated' => $deal->status === FlashDealStatus::ACTIVATED,
                ]);

            } catch (\Exception $e) {
                $this->error("    ✗ Failed to process [{$deal->id}]: {$e->getMessage()}");
                Log::error('Failed to expire flash deal', [
                    'deal_id' => $deal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $expired;
    }
}