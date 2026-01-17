<?php

namespace App\Console\Commands;

use App\Models\Offer;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Expire offers that have passed their valid_until date.
 *
 * This command deactivates offers and optionally notifies shop owners.
 *
 * @example
 * php artisan nearbuy:expire-offers
 * php artisan nearbuy:expire-offers --notify
 */
class ExpireOffers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nearbuy:expire-offers
                            {--notify : Notify shop owners of expired offers}
                            {--dry-run : Show what would expire without expiring}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Deactivate expired offers';

    /**
     * Execute the console command.
     */
    public function handle(NotificationService $notificationService): int
    {
        $this->info('Checking for expired offers...');

        // Find offers to expire
        $offersToExpire = Offer::query()
            ->where('is_active', true)
            ->where('valid_until', '<', now())
            ->with('shop.owner')
            ->get();

        if ($offersToExpire->isEmpty()) {
            $this->info('No offers to expire.');
            return self::SUCCESS;
        }

        $this->info("Found {$offersToExpire->count()} offer(s) to expire.");

        if ($this->option('dry-run')) {
            return $this->showOffers($offersToExpire);
        }

        $expired = 0;
        $notified = 0;

        foreach ($offersToExpire as $offer) {
            try {
                // Deactivate offer
                $offer->update(['is_active' => false]);
                $expired++;

                // Notify shop owner if requested
                if ($this->option('notify')) {
                    $notificationService->notifyOfferExpired($offer);
                    $notified++;
                }

            } catch (\Exception $e) {
                $this->error("Failed to expire offer {$offer->id}: {$e->getMessage()}");
            }
        }

        $this->info("✅ Expired {$expired} offer(s).");

        if ($this->option('notify')) {
            $this->info("📨 Notified {$notified} shop owner(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Show offers that would be expired.
     */
    protected function showOffers($offers): int
    {
        $headers = ['ID', 'Shop', 'Title', 'Expired At', 'Views'];
        $rows = [];

        foreach ($offers as $offer) {
            $rows[] = [
                $offer->id,
                $offer->shop?->name ?? 'Unknown',
                mb_substr($offer->title, 0, 30),
                $offer->valid_until->format('Y-m-d H:i'),
                $offer->view_count ?? 0,
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}