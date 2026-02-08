<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\NotificationFrequency;
use App\Models\PendingShopNotification;
use App\Models\Shop;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send Batched Shop Notifications.
 *
 * Processes pending notifications for shops with non-immediate preferences.
 * Run via scheduler: every 2 hours, or at 9 AM / 5 PM for twice_daily/daily.
 *
 * @srs-ref FR-PRD-12 - Batch for 2hours/twice_daily/daily shops
 *
 * @example Schedule in Console/Kernel.php:
 * $schedule->job(new SendBatchedShopNotifications('2hours'))->everyTwoHours();
 * $schedule->job(new SendBatchedShopNotifications('twice_daily'))->twiceDaily(9, 17);
 * $schedule->job(new SendBatchedShopNotifications('daily'))->dailyAt('09:00');
 */
class SendBatchedShopNotifications implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        public string $frequency = '2hours'
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WhatsAppService $whatsApp): void
    {
        try {
            // Get shops with this frequency
            $shops = Shop::query()
                ->where('is_active', true)
                ->where('notification_frequency', $this->frequency)
                ->get();

            if ($shops->isEmpty()) {
                Log::info('No shops with frequency: ' . $this->frequency);
                return;
            }

            $totalSent = 0;

            foreach ($shops as $shop) {
                $sent = $this->sendBatchToShop($whatsApp, $shop);
                $totalSent += $sent;
            }

            Log::info('Batched notifications sent', [
                'frequency' => $this->frequency,
                'shops_processed' => $shops->count(),
                'notifications_sent' => $totalSent,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send batched notifications', [
                'frequency' => $this->frequency,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Send batched notification to a shop.
     *
     * @srs-ref FR-PRD-12 - Batch requests for shops with delayed preferences
     */
    protected function sendBatchToShop(WhatsAppService $whatsApp, Shop $shop): int
    {
        // Get pending notifications for this shop
        $pending = PendingShopNotification::query()
            ->where('shop_id', $shop->id)
            ->with('request')
            ->orderBy('created_at')
            ->get();

        if ($pending->isEmpty()) {
            return 0;
        }

        // Filter to only active requests
        $active = $pending->filter(fn($p) => $p->request && $p->request->isOpen());

        if ($active->isEmpty()) {
            // Clean up expired
            PendingShopNotification::where('shop_id', $shop->id)->delete();
            return 0;
        }

        $owner = $shop->owner;
        if (!$owner) {
            return 0;
        }

        $count = $active->count();

        if ($count === 1) {
            // Single request - send full notification
            $p = $active->first();
            $this->sendSingleNotification($whatsApp, $owner->phone, $p->request, $p->distance_km);
        } else {
            // Multiple requests - send summary
            $this->sendBatchSummary($whatsApp, $owner->phone, $active);
        }

        // Mark as sent (delete from pending)
        PendingShopNotification::where('shop_id', $shop->id)
            ->whereIn('request_id', $active->pluck('request_id'))
            ->delete();

        return $count;
    }

    /**
     * Send single request notification.
     */
    protected function sendSingleNotification(
        WhatsAppService $whatsApp,
        string $phone,
        $request,
        float $distance
    ): void {
        $message = "🔍 *Product Request!* #{$request->request_number}\n" .
            "'{$this->truncate($request->description, 60)}'\n" .
            $this->formatDistance($distance) . " away";

        if ($request->image_url) {
            $whatsApp->sendImage($phone, $request->image_url, $message);
        } else {
            $whatsApp->sendText($phone, $message);
        }

        $whatsApp->sendButtons(
            $phone,
            "Ee product undoo?",
            [
                ['id' => "yes_{$request->id}", 'title' => '✅ Yes I Have'],
                ['id' => "no_{$request->id}", 'title' => "❌ Don't Have"],
                ['id' => "skip_{$request->id}", 'title' => '⏭️ Skip'],
            ]
        );
    }

    /**
     * Send batch summary notification.
     *
     * Format:
     * "🔍 3 Product Requests!
     *  1. 'Samsung phone' — 1.2km
     *  2. 'Red saree' — 0.8km
     *  3. 'Laptop bag' — 2.1km
     *  [View & Respond]"
     */
    protected function sendBatchSummary(WhatsAppService $whatsApp, string $phone, $pending): void
    {
        $count = $pending->count();
        $lines = ["🔍 *{$count} Product Requests!*\n"];

        $i = 1;
        foreach ($pending->take(5) as $p) {
            $desc = $this->truncate($p->request->description, 30);
            $dist = $this->formatDistance($p->distance_km);
            $lines[] = "{$i}. '{$desc}' — {$dist}";
            $i++;
        }

        if ($count > 5) {
            $lines[] = "...and " . ($count - 5) . " more";
        }

        $whatsApp->sendButtons(
            $phone,
            implode("\n", $lines),
            [
                ['id' => 'view_requests', 'title' => '📬 View & Respond'],
                ['id' => 'menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    protected function formatDistance(float $km): string
    {
        if ($km < 0.1) return 'Very close';
        if ($km < 1) return round($km * 1000) . 'm';
        return round($km, 1) . 'km';
    }

    protected function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;
        return mb_substr($text, 0, $max - 1) . '…';
    }

    public function tags(): array
    {
        return ['notifications', 'batch', 'frequency:' . $this->frequency];
    }
}