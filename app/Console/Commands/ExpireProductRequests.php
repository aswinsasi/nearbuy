<?php

namespace App\Console\Commands;

use App\Enums\RequestStatus;
use App\Models\ProductRequest;
use App\Services\Notifications\NotificationService;
use App\Services\Products\ProductSearchService;
use Illuminate\Console\Command;

/**
 * Expire product requests that have passed their expiry time.
 *
 * This command marks requests as expired and notifies customers
 * about responses they received.
 *
 * @example
 * php artisan nearbuy:expire-product-requests
 * php artisan nearbuy:expire-product-requests --notify
 */
class ExpireProductRequests extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'nearbuy:expire-product-requests
                            {--notify : Notify customers about expiration}
                            {--dry-run : Show what would expire without expiring}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Mark expired product requests and notify customers';

    /**
     * Execute the console command.
     */
    public function handle(
        ProductSearchService $searchService,
        NotificationService $notificationService,
    ): int {
        $this->info('Checking for expired product requests...');

        // Find requests to expire
        $requestsToExpire = ProductRequest::query()
            ->whereIn('status', [RequestStatus::OPEN, RequestStatus::COLLECTING])
            ->where('expires_at', '<', now())
            ->with(['user', 'responses'])
            ->get();

        if ($requestsToExpire->isEmpty()) {
            $this->info('No requests to expire.');
            return self::SUCCESS;
        }

        $this->info("Found {$requestsToExpire->count()} request(s) to expire.");

        if ($this->option('dry-run')) {
            return $this->showRequests($requestsToExpire);
        }

        $expired = 0;
        $notified = 0;

        foreach ($requestsToExpire as $request) {
            try {
                // Mark as expired
                $searchService->expireRequest($request);
                $expired++;

                // Notify customer if requested
                if ($this->option('notify')) {
                    $notificationService->notifyRequestExpired($request);
                    $notified++;
                }

            } catch (\Exception $e) {
                $this->error("Failed to expire request {$request->id}: {$e->getMessage()}");
            }
        }

        $this->info("✅ Expired {$expired} request(s).");

        if ($this->option('notify')) {
            $this->info("📨 Notified {$notified} customer(s).");
        }

        return self::SUCCESS;
    }

    /**
     * Show requests that would be expired.
     */
    protected function showRequests($requests): int
    {
        $headers = ['ID', 'Number', 'Description', 'Expired At', 'Responses'];
        $rows = [];

        foreach ($requests as $request) {
            $rows[] = [
                $request->id,
                $request->request_number,
                mb_substr($request->description, 0, 30),
                $request->expires_at->format('Y-m-d H:i'),
                $request->responses->count(),
            ];
        }

        $this->table($headers, $rows);

        return self::SUCCESS;
    }
}