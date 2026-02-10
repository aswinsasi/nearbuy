<?php

declare(strict_types=1);

namespace App\Services\Fish;

use App\Enums\FishAlertFrequency;
use App\Jobs\Fish\SendFishAlertJob;
use App\Models\FishAlert;
use App\Models\FishCatch;
use App\Models\FishSubscription;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FishMessages;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Fish Alert Service - THE viral moment!
 *
 * When fresh fish arrives, this service:
 * 1. Finds ALL matching subscribers within radius (PM-016)
 * 2. Orders by distance (nearest first - most likely to come)
 * 3. Respects time preferences (PM-020)
 * 4. Dispatches alerts in batches of 50 (WhatsApp rate limits)
 * 5. Tracks social proof (PM-019)
 *
 * Target: Alerts sent within 2 minutes of posting!
 *
 * @srs-ref PM-016 to PM-020 Alert requirements
 */
class FishAlertService
{
    /**
     * Batch size for WhatsApp rate limits.
     */
    public const BATCH_SIZE = 50;

    /**
     * Delay between batches (seconds).
     */
    public const BATCH_DELAY_SECONDS = 1;

    public function __construct(
        protected FishSubscriptionService $subscriptionService,
        protected WhatsAppService $whatsApp,
    ) {}

    /**
     * Process new catch - find subscribers and dispatch alerts.
     *
     * @srs-ref PM-016 Alert ALL subscribed customers within radius
     *
     * @return array{total_subscribers: int, immediate: int, scheduled: int}
     */
    public function processNewCatch(FishCatch $catch): array
    {
        if (!$catch->is_active) {
            Log::warning('Catch not active, skipping alerts', ['catch_id' => $catch->id]);
            return ['total_subscribers' => 0, 'immediate' => 0, 'scheduled' => 0];
        }

        // Find ALL matching subscriptions (PM-016)
        $subscriptions = $this->subscriptionService->findMatchingSubscriptions($catch);

        if ($subscriptions->isEmpty()) {
            Log::info('No matching subscribers', ['catch_id' => $catch->id]);
            return ['total_subscribers' => 0, 'immediate' => 0, 'scheduled' => 0];
        }

        $immediate = 0;
        $scheduled = 0;

        // Create alerts ordered by distance (nearest first)
        $alerts = collect();

        foreach ($subscriptions as $subscription) {
            // Skip if already alerted
            if (FishAlert::existsForCatchAndUser($catch->id, $subscription->user_id)) {
                continue;
            }

            // Determine scheduled time based on preference (PM-020)
            $scheduledFor = $this->calculateScheduledTime($subscription);

            $alert = FishAlert::createForCatch(
                $catch,
                $subscription,
                FishAlert::TYPE_NEW_CATCH,
                $scheduledFor
            );

            $alerts->push($alert);

            if ($scheduledFor) {
                $scheduled++;
            } else {
                $immediate++;
            }
        }

        // Dispatch alerts - NEAREST FIRST, in batches
        $this->dispatchAlerts($alerts->sortBy('distance_km'));

        Log::info('Alerts created for catch', [
            'catch_id' => $catch->id,
            'total' => $alerts->count(),
            'immediate' => $immediate,
            'scheduled' => $scheduled,
        ]);

        return [
            'total_subscribers' => $alerts->count(),
            'immediate' => $immediate,
            'scheduled' => $scheduled,
        ];
    }

    /**
     * Dispatch alerts in batches with delays.
     */
    protected function dispatchAlerts(Collection $alerts): void
    {
        $batches = $alerts->chunk(self::BATCH_SIZE);
        $delay = 0;

        foreach ($batches as $batch) {
            foreach ($batch as $alert) {
                // Priority queue for fast delivery
                SendFishAlertJob::dispatch($alert)
                    ->onQueue('fish-alerts')
                    ->delay(now()->addSeconds($delay));
            }

            // Add delay between batches
            $delay += self::BATCH_DELAY_SECONDS;
        }
    }

    /**
     * Calculate scheduled time based on preference.
     *
     * @srs-ref PM-020 Respect alert time preferences
     */
    protected function calculateScheduledTime(FishSubscription $subscription): ?\Carbon\Carbon
    {
        $frequency = $subscription->alert_frequency;

        // Immediate - no scheduling
        if ($frequency === FishAlertFrequency::IMMEDIATE || $frequency === null) {
            return null;
        }

        $now = now();
        $hour = $now->hour;

        // Morning only preference (6-8 AM)
        if ($frequency === FishAlertFrequency::MORNING_ONLY) {
            if ($hour >= 6 && $hour < 8) {
                return null; // Send now - within window
            }
            // Schedule for next 6 AM
            if ($hour >= 8) {
                return $now->copy()->addDay()->setTime(6, 0);
            }
            return $now->copy()->setTime(6, 0);
        }

        // Twice daily (6 AM and 4 PM)
        if ($frequency === FishAlertFrequency::TWICE_DAILY) {
            if (($hour >= 6 && $hour < 7) || ($hour >= 16 && $hour < 17)) {
                return null; // Send now - within window
            }
            // Schedule for next window
            if ($hour < 6) {
                return $now->copy()->setTime(6, 0);
            }
            if ($hour < 16) {
                return $now->copy()->setTime(16, 0);
            }
            return $now->copy()->addDay()->setTime(6, 0);
        }

        // Weekly digest - schedule for Sunday 8 AM
        if ($frequency === FishAlertFrequency::WEEKLY_DIGEST) {
            $nextSunday = $now->copy()->next('Sunday')->setTime(8, 0);
            return $nextSunday;
        }

        return null; // Default: send immediately
    }

    /**
     * Send single alert immediately.
     *
     * @srs-ref PM-017 Include all required info
     * @srs-ref PM-018 Include action buttons
     * @srs-ref PM-019 Include social proof
     */
    public function sendAlert(FishAlert $alert): bool
    {
        $catch = $alert->catch;
        $subscription = $alert->subscription;

        if (!$catch || !$subscription) {
            $alert->markFailed('Missing catch or subscription');
            return false;
        }

        // Check if catch still active
        if (!$catch->is_active) {
            $alert->markFailed('Catch no longer active');
            return false;
        }

        // Check if subscription still active
        if (!$subscription->is_active) {
            $alert->markFailed('Subscription inactive');
            return false;
        }

        try {
            // Get alert message with photo as image + caption
            $phone = $subscription->user?->phone;
            if (!$phone) {
                $alert->markFailed('No phone number');
                return false;
            }

            // Send alert - photo first if available
            if ($catch->photo_url) {
                // PM-017: Photo with caption containing all info
                $caption = FishMessages::buildAlertCaption($catch, $alert);
                $result = $this->whatsApp->sendImage($phone, $catch->photo_url, $caption);
            } else {
                // No photo - send buttons message
                $message = FishMessages::newCatchAlert($catch, $alert);
                $result = $this->sendMessage($phone, $message);
            }

            // Send buttons separately if image was sent
            if ($catch->photo_url) {
                $buttons = FishMessages::alertButtons($catch, $alert);
                $this->sendMessage($phone, $buttons);
            }

            $messageId = $result['messages'][0]['id'] ?? null;
            $alert->markSent($messageId);

            Log::info('Alert sent', [
                'alert_id' => $alert->id,
                'catch_id' => $catch->id,
                'distance' => $alert->distance_display,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('Alert send failed', [
                'alert_id' => $alert->id,
                'error' => $e->getMessage(),
            ]);
            $alert->markFailed($e->getMessage());
            return false;
        }
    }

    /**
     * Send WhatsApp message based on type.
     */
    protected function sendMessage(string $phone, array $message): array
    {
        $type = $message['type'] ?? 'text';

        return match ($type) {
            'buttons' => $this->whatsApp->sendButtons(
                $phone,
                $message['body'] ?? '',
                $message['buttons'] ?? [],
                $message['header'] ?? null,
                $message['footer'] ?? null
            ),
            'image' => $this->whatsApp->sendImage(
                $phone,
                $message['image'] ?? '',
                $message['caption'] ?? null
            ),
            default => $this->whatsApp->sendText($phone, $message['body'] ?? ''),
        };
    }

    /**
     * Handle "I'm Coming" click.
     *
     * @srs-ref PM-019 Increment coming count for social proof
     */
    public function handleComingClick(FishAlert $alert): void
    {
        $alert->recordClick(FishAlert::ACTION_COMING);

        $catch = $alert->catch;
        $user = $alert->user;

        if (!$catch || !$user) return;

        // Send confirmation to customer
        $confirmation = FishMessages::comingConfirmation($catch);
        $this->sendMessage($user->phone, $confirmation);

        // Notify seller
        $this->notifySellerCustomerComing($catch, $user, $alert->distance_km);

        Log::info('Customer coming', [
            'catch_id' => $catch->id,
            'user_id' => $user->id,
            'total_coming' => $catch->customers_coming,
        ]);
    }

    /**
     * Notify seller when customer is coming.
     */
    protected function notifySellerCustomerComing(
        FishCatch $catch,
        $customer,
        ?float $distance
    ): void {
        $seller = $catch->seller;
        if (!$seller?->user?->phone) return;

        $message = FishMessages::sellerComingNotification(
            $catch,
            $customer,
            $catch->customers_coming,
            $distance
        );

        $this->sendMessage($seller->user->phone, $message);
    }

    /**
     * Send low stock alerts to customers who said "I'm Coming".
     */
    public function sendLowStockAlerts(FishCatch $catch): int
    {
        // Find alerts where customer clicked "coming"
        $comingAlerts = FishAlert::forCatch($catch->id)
            ->where('click_action', FishAlert::ACTION_COMING)
            ->with('user')
            ->get();

        $sent = 0;

        foreach ($comingAlerts as $alert) {
            if (!$alert->user?->phone) continue;

            // Don't send if already sent low stock alert
            if (FishAlert::where('fish_catch_id', $catch->id)
                ->where('user_id', $alert->user_id)
                ->where('alert_type', FishAlert::TYPE_LOW_STOCK)
                ->exists()) {
                continue;
            }

            $message = FishMessages::lowStockAlert($catch, $alert);
            $this->sendMessage($alert->user->phone, $message);
            $sent++;
        }

        Log::info('Low stock alerts sent', [
            'catch_id' => $catch->id,
            'count' => $sent,
        ]);

        return $sent;
    }

    /**
     * Build message data for alert (used by job).
     */
    public function buildAlertMessageData(FishAlert $alert): array
    {
        $catch = $alert->catch;

        return [
            'phone' => $alert->subscription?->user?->phone,
            'type' => $catch->photo_url ? 'image_with_buttons' : 'buttons',
            'image_url' => $catch->photo_url,
            'caption' => $catch->photo_url ? FishMessages::buildAlertCaption($catch, $alert) : null,
            'message' => FishMessages::newCatchAlert($catch, $alert),
            'buttons' => FishMessages::alertButtons($catch, $alert),
        ];
    }

    /**
     * Get alert stats for a catch.
     */
    public function getCatchAlertStats(FishCatch $catch): array
    {
        $alerts = FishAlert::forCatch($catch->id)->get();

        return [
            'total' => $alerts->count(),
            'sent' => $alerts->where('status', FishAlert::STATUS_SENT)->count(),
            'failed' => $alerts->where('status', FishAlert::STATUS_FAILED)->count(),
            'coming' => $alerts->where('click_action', FishAlert::ACTION_COMING)->count(),
            'click_rate' => $alerts->count() > 0
                ? round($alerts->whereNotNull('click_action')->count() / $alerts->count() * 100, 1)
                : 0,
        ];
    }

    /**
     * Find alert by ID.
     */
    public function findById(int $id): ?FishAlert
    {
        return FishAlert::with(['catch.seller', 'catch.fishType', 'subscription', 'user'])
            ->find($id);
    }

    /**
     * Parse alert action from button ID.
     * Format: fish_action_catchId_alertId
     */
    public function parseAlertAction(string $buttonId): ?array
    {
        if (!str_starts_with($buttonId, 'fish_')) {
            return null;
        }

        $parts = explode('_', $buttonId);
        if (count($parts) < 4) {
            return null;
        }

        return [
            'action' => $parts[1], // coming, message, location
            'catch_id' => (int) $parts[2],
            'alert_id' => (int) $parts[3],
        ];
    }
}