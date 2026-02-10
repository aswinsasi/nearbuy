<?php

declare(strict_types=1);

namespace App\Jobs\Fish;

use App\Models\FishCatch;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Expire stale fish catches.
 *
 * @srs-ref PM-024: Auto-expire catch postings after 6 hours if not manually updated
 *
 * Notification to seller:
 * "⏰ [Fish] auto-expired (6hrs). Update or post fresh! 🐟"
 */
class ExpireStaleCatchesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(
        public int $expiryHours = 6,
        public bool $notifySellers = true
    ) {}

    public function handle(WhatsAppService $whatsApp): void
    {
        Log::info('ExpireStaleCatchesJob starting', ['hours' => $this->expiryHours]);

        $cutoff = now()->subHours($this->expiryHours);

        // Find stale catches (PM-024: not manually updated)
        $staleCatches = FishCatch::query()
            ->whereIn('status', ['available', 'low_stock'])
            ->where('updated_at', '<', $cutoff)
            ->with(['fishType', 'seller.user'])
            ->get();

        if ($staleCatches->isEmpty()) {
            Log::info('No stale catches to expire');
            return;
        }

        Log::info('Expiring stale catches', ['count' => $staleCatches->count()]);

        $expiredCount = 0;
        $sellerNotifications = [];

        foreach ($staleCatches as $catch) {
            // Expire the catch
            $catch->update([
                'status' => 'expired',
                'expired_at' => now(),
                'expiry_reason' => 'auto_6hr', // PM-024 reference
            ]);
            $expiredCount++;

            // Group by seller for consolidated notification
            if ($this->notifySellers && $catch->seller?->user) {
                $sellerId = $catch->seller->id;
                
                if (!isset($sellerNotifications[$sellerId])) {
                    $sellerNotifications[$sellerId] = [
                        'phone' => $catch->seller->user->phone,
                        'name' => $catch->seller->business_name,
                        'catches' => [],
                    ];
                }
                
                $sellerNotifications[$sellerId]['catches'][] = [
                    'fish' => $catch->fishType?->display_name ?? '🐟 Fish',
                    'fish_ml' => $catch->fishType?->name_ml ?? 'Meen',
                ];
            }
        }

        // Send consolidated notifications
        foreach ($sellerNotifications as $data) {
            $this->notifySeller($whatsApp, $data);
        }

        Log::info('ExpireStaleCatchesJob completed', [
            'expired' => $expiredCount,
            'sellers_notified' => count($sellerNotifications),
        ]);
    }

    /**
     * Notify seller about expired catches.
     *
     * Message format: "⏰ [Fish] auto-expired (6hrs). Update or post fresh! 🐟"
     */
    protected function notifySeller(WhatsAppService $whatsApp, array $data): void
    {
        try {
            $count = count($data['catches']);
            
            if ($count === 1) {
                // Single catch expired
                $fish = $data['catches'][0]['fish'];
                $message = "⏰ *{$fish}* auto-expired ({$this->expiryHours}hrs).\n\n" .
                           "Update status or post fresh catch! 🐟";
            } else {
                // Multiple catches expired
                $fishList = collect($data['catches'])
                    ->take(3)
                    ->pluck('fish_ml')
                    ->join(', ');
                
                $extra = $count > 3 ? " +" . ($count - 3) . " more" : '';
                
                $message = "⏰ *{$count} catches* auto-expired:\n" .
                           "{$fishList}{$extra}\n\n" .
                           "Post fresh catches! 🐟";
            }

            $whatsApp->sendButtons(
                $data['phone'],
                $message,
                [
                    ['id' => 'fish_post_catch', 'title' => '🐟 Post Fresh'],
                    ['id' => 'fish_my_catches', 'title' => '📋 My Catches'],
                ]
            );

            Log::info('Sent expiry notification', ['phone' => substr($data['phone'], -4)]);

        } catch (\Exception $e) {
            Log::warning('Failed to send expiry notification', [
                'seller' => $data['name'],
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function tags(): array
    {
        return ['fish', 'expire-catches', 'pm-024'];
    }
}