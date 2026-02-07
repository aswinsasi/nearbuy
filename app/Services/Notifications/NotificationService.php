<?php

namespace App\Services\Notifications;

use App\Enums\NotificationFrequency;
use App\Jobs\SendBatchNotification;
use App\Jobs\SendWhatsAppMessage;
use App\Models\NotificationBatch;
use App\Models\NotificationQueue;
use App\Models\ProductRequest;
use App\Models\Shop;
use App\Models\User;
use App\Services\Products\ProductSearchService;
use App\Services\WhatsApp\Messages\NotificationMessages;
use App\Services\WhatsApp\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Notification Service for NearBuy.
 *
 * Key Features:
 * - Quiet Hours: 10PM-7AM (notifications queued, delivered at 7AM)
 * - Smart Batching: Combines similar notifications (3 fish alerts → 1 summary)
 * - Priority Override: Flash Deals bypass batching (time-sensitive!)
 * - Frequency Respect: Honors shop notification_frequency preference
 * - Bilingual: Supports EN/ML based on user preference
 *
 * @example
 * $notificationService = app(NotificationService::class);
 * $notificationService->notifyShopsOfRequest($productRequest);
 */
class NotificationService
{
    /*
    |--------------------------------------------------------------------------
    | Configuration Constants
    |--------------------------------------------------------------------------
    */

    /**
     * Quiet hours start (10 PM).
     */
    public const QUIET_HOURS_START = 22;

    /**
     * Quiet hours end (7 AM).
     */
    public const QUIET_HOURS_END = 7;

    /**
     * Minimum items before batching similar notifications.
     */
    public const BATCH_THRESHOLD = 3;

    /**
     * Maximum items in a single batch message.
     */
    public const MAX_BATCH_SIZE = 10;

    /**
     * Priority levels that bypass batching and quiet hours.
     */
    public const PRIORITY_BYPASS = ['high', 'urgent', 'flash_deal'];

    public function __construct(
        protected WhatsAppService $whatsApp,
        protected ProductSearchService $searchService,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Product Request Notifications (FR-PRD-10 to FR-PRD-14)
    |--------------------------------------------------------------------------
    */

    /**
     * Notify all eligible shops about a new product request.
     *
     * Respects shop notification_frequency preference:
     * - immediate: Send now (unless quiet hours)
     * - 2hours: Batch for next 2-hour window
     * - twice_daily: Queue for 9AM or 5PM
     * - daily: Queue for 9AM
     */
    public function notifyShopsOfRequest(ProductRequest $request): int
    {
        $shops = $this->searchService->findEligibleShops($request);

        if ($shops->isEmpty()) {
            Log::info('No eligible shops for product request', [
                'request_id' => $request->id,
            ]);
            return 0;
        }

        $notifiedCount = 0;

        foreach ($shops as $shop) {
            $this->queueProductRequestNotification($request, $shop);
            $notifiedCount++;
        }

        // Update request with notification count
        $this->searchService->updateShopsNotified($request, $notifiedCount);

        Log::info('Product request notifications queued', [
            'request_id' => $request->id,
            'shops_notified' => $notifiedCount,
        ]);

        return $notifiedCount;
    }

    /**
     * Queue a product request notification for a shop.
     *
     * Respects notification frequency preference (FR-PRD-10 to FR-PRD-12).
     */
    public function queueProductRequestNotification(ProductRequest $request, Shop $shop): void
    {
        $frequency = $shop->notification_frequency ?? NotificationFrequency::IMMEDIATE;

        $notificationData = [
            'type' => 'product_request',
            'request_id' => $request->id,
            'request_number' => $request->request_number,
            'description' => $request->description,
            'category' => $request->category,
            'distance_km' => $shop->distance_km ?? 0,
            'expires_at' => $request->expires_at->toIso8601String(),
        ];

        // Immediate frequency — send now (respecting quiet hours)
        if ($frequency === NotificationFrequency::IMMEDIATE) {
            $this->sendOrQueueImmediate($shop, $notificationData);
            return;
        }

        // Batched frequency — add to batch
        $this->addToBatch($shop, 'product_request', $notificationData);
    }

    /**
     * Send immediate notification or queue for quiet hours.
     */
    protected function sendOrQueueImmediate(Shop $shop, array $data, string $priority = 'normal'): void
    {
        $owner = $shop->owner;

        if (!$owner) {
            return;
        }

        // Check if we should bypass quiet hours
        $bypassQuietHours = in_array($priority, self::PRIORITY_BYPASS);

        // If in quiet hours and not high priority, queue for morning
        if (!$bypassQuietHours && $this->isQuietHours()) {
            $this->addToQuietHoursQueue($owner, $data);
            return;
        }

        // Send immediately
        $this->sendImmediateNotification($shop, $data);
    }

    /**
     * Send immediate notification for a product request.
     */
    public function sendImmediateNotification(Shop $shop, array $data): void
    {
        $owner = $shop->owner;

        if (!$owner) {
            return;
        }

        $lang = $owner->preferred_language ?? 'en';

        // Build notification using NotificationMessages
        $notification = NotificationMessages::newRequest($data, $lang);

        // Queue the WhatsApp message
        SendWhatsAppMessage::dispatch(
            $owner->phone,
            $notification['message'],
            'buttons',
            $notification['buttons']
        )->onQueue('notifications');

        Log::debug('Immediate notification sent', [
            'shop_id' => $shop->id,
            'request_id' => $data['request_id'] ?? null,
            'quiet_hours_bypassed' => $this->isQuietHours(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Quiet Hours Management (10PM - 7AM)
    |--------------------------------------------------------------------------
    */

    /**
     * Check if current time is within quiet hours.
     */
    public function isQuietHours(?Carbon $time = null): bool
    {
        $time = $time ?? now();
        $hour = (int) $time->format('H');

        // 22:00 - 23:59 OR 00:00 - 06:59
        return $hour >= self::QUIET_HOURS_START || $hour < self::QUIET_HOURS_END;
    }

    /**
     * Get next delivery time after quiet hours.
     */
    public function getNextDeliveryTime(?Carbon $from = null): Carbon
    {
        $from = $from ?? now();

        if (!$this->isQuietHours($from)) {
            return $from;
        }

        // If before midnight, delivery is 7AM same night's morning
        // If after midnight, delivery is 7AM same day
        $deliveryTime = $from->copy()->setTime(self::QUIET_HOURS_END, 0, 0);

        if ($from->hour >= self::QUIET_HOURS_START) {
            // After 10PM — deliver next morning
            $deliveryTime->addDay();
        }

        return $deliveryTime;
    }

    /**
     * Add notification to quiet hours queue.
     */
    protected function addToQuietHoursQueue(User $user, array $data): void
    {
        $deliveryTime = $this->getNextDeliveryTime();

        // Find or create morning batch for this user
        $batch = NotificationBatch::firstOrCreate(
            [
                'user_id' => $user->id,
                'status' => 'pending',
                'is_quiet_hours_batch' => true,
            ],
            [
                'frequency' => NotificationFrequency::DAILY,
                'items' => [],
                'scheduled_for' => $deliveryTime,
            ]
        );

        $items = $batch->items ?? [];
        $items[] = array_merge($data, [
            'queued_at' => now()->toIso8601String(),
            'reason' => 'quiet_hours',
        ]);

        $batch->update(['items' => $items]);

        Log::debug('Notification queued for quiet hours delivery', [
            'user_id' => $user->id,
            'delivery_time' => $deliveryTime->toIso8601String(),
            'type' => $data['type'] ?? 'unknown',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Batch Management (FR-PRD-11, FR-PRD-12)
    |--------------------------------------------------------------------------
    */

    /**
     * Add notification to batch based on shop's frequency preference.
     */
    public function addToBatch(Shop $shop, string $type, array $data): NotificationBatch
    {
        $frequency = $shop->notification_frequency ?? NotificationFrequency::TWICE_DAILY;

        // Find or create pending batch for this shop
        $batch = NotificationBatch::firstOrCreate(
            [
                'shop_id' => $shop->id,
                'status' => 'pending',
                'is_quiet_hours_batch' => false,
            ],
            [
                'frequency' => $frequency,
                'items' => [],
                'scheduled_for' => $this->calculateNextSendTime($frequency),
            ]
        );

        // Add item to batch
        $items = $batch->items ?? [];
        $items[] = array_merge($data, [
            'type' => $type,
            'added_at' => now()->toIso8601String(),
        ]);

        $batch->update(['items' => $items]);

        return $batch;
    }

    /**
     * Calculate next send time based on frequency.
     */
    protected function calculateNextSendTime(NotificationFrequency $frequency): Carbon
    {
        $now = now();

        $sendTime = match ($frequency) {
            NotificationFrequency::IMMEDIATE => $now,
            NotificationFrequency::EVERY_2_HOURS => $now->copy()->addHours(2)->startOfHour(),
            NotificationFrequency::TWICE_DAILY => $this->getNextTwiceDailyTime($now),
            NotificationFrequency::DAILY => $this->getNextDailyTime($now),
            default => $now->addHours(2),
        };

        // Ensure we don't schedule during quiet hours
        if ($this->isQuietHours($sendTime)) {
            $sendTime = $this->getNextDeliveryTime($sendTime);
        }

        return $sendTime;
    }

    /**
     * Get next twice daily notification time (9 AM or 5 PM).
     */
    protected function getNextTwiceDailyTime(Carbon $now): Carbon
    {
        $morningTime = $now->copy()->setTime(9, 0);
        $eveningTime = $now->copy()->setTime(17, 0);

        if ($now->lt($morningTime)) {
            return $morningTime;
        }

        if ($now->lt($eveningTime)) {
            return $eveningTime;
        }

        return $morningTime->addDay();
    }

    /**
     * Get next daily notification time (9 AM).
     */
    protected function getNextDailyTime(Carbon $now): Carbon
    {
        $morningTime = $now->copy()->setTime(9, 0);

        if ($now->lt($morningTime)) {
            return $morningTime;
        }

        return $morningTime->addDay();
    }

    /*
    |--------------------------------------------------------------------------
    | Smart Batch Processing (Combine Similar Notifications)
    |--------------------------------------------------------------------------
    */

    /**
     * Process all pending batched notifications.
     */
    public function processBatchedNotifications(): int
    {
        $batches = NotificationBatch::query()
            ->where('status', 'pending')
            ->where('scheduled_for', '<=', now())
            ->get();

        $processed = 0;

        foreach ($batches as $batch) {
            try {
                $this->sendBatchedNotifications($batch);
                $processed++;
            } catch (\Exception $e) {
                Log::error('Failed to process notification batch', [
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);

                $batch->update([
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * Send batched notifications with smart grouping.
     *
     * Groups similar notifications (e.g., 5 fish alerts → 1 summary)
     * to reduce notification fatigue.
     */
    public function sendBatchedNotifications(NotificationBatch $batch): void
    {
        $shop = $batch->shop;
        $user = $batch->user ?? $shop?->owner;

        if (!$user) {
            $batch->update(['status' => 'skipped', 'error' => 'No recipient']);
            return;
        }

        $items = $batch->items ?? [];

        if (empty($items)) {
            $batch->update(['status' => 'skipped', 'error' => 'No items']);
            return;
        }

        $lang = $user->preferred_language ?? 'en';

        // Group items by type for smart batching
        $grouped = collect($items)->groupBy('type');

        foreach ($grouped as $type => $typeItems) {
            $this->sendGroupedNotification($user, $type, $typeItems->toArray(), $lang);
        }

        // Update batch status
        $batch->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        Log::info('Batch notification sent', [
            'batch_id' => $batch->id,
            'user_id' => $user->id,
            'item_count' => count($items),
            'groups' => $grouped->keys()->toArray(),
        ]);
    }

    /**
     * Send grouped notification based on type.
     *
     * If count >= BATCH_THRESHOLD, sends summary instead of individual items.
     */
    protected function sendGroupedNotification(User $user, string $type, array $items, string $lang): void
    {
        $count = count($items);

        // If fewer than threshold, could send individually
        // But for batch context, always send as summary
        $notification = match ($type) {
            'product_request' => $this->buildProductRequestBatch($items, $lang),
            'fish_alert' => $this->buildFishAlertBatch($items, $lang),
            'job' => $this->buildJobBatch($items, $lang),
            'response' => $this->buildResponseBatch($items, $lang),
            default => NotificationMessages::batchSummary($count, $type, $lang),
        };

        // Send via WhatsApp
        SendWhatsAppMessage::dispatch(
            $user->phone,
            $notification['message'],
            'buttons',
            $notification['buttons']
        )->onQueue('notifications');
    }

    /**
     * Build product request batch message.
     */
    protected function buildProductRequestBatch(array $items, string $lang): array
    {
        return NotificationMessages::batchRequests($items, $lang);
    }

    /**
     * Build fish alert batch message.
     */
    protected function buildFishAlertBatch(array $items, string $lang): array
    {
        return NotificationMessages::batchFishAlerts($items, $lang);
    }

    /**
     * Build job batch message.
     */
    protected function buildJobBatch(array $items, string $lang): array
    {
        $count = count($items);
        return NotificationMessages::batchSummary($count, 'jobs', $lang);
    }

    /**
     * Build response batch message.
     */
    protected function buildResponseBatch(array $items, string $lang): array
    {
        $count = count($items);

        // Find lowest price for summary
        $lowestPrice = collect($items)->min('price') ?? 0;

        return NotificationMessages::multipleResponses([
            'count' => $count,
            'lowest_price' => $lowestPrice,
            'request_description' => $items[0]['request_description'] ?? 'your product',
        ], $lang);
    }

    /*
    |--------------------------------------------------------------------------
    | Flash Deal Notifications (PRIORITY — No Batching!)
    |--------------------------------------------------------------------------
    */

    /**
     * Send Flash Deal notification immediately.
     *
     * Flash Deals are time-sensitive and BYPASS:
     * - Quiet hours
     * - Batching
     * - Frequency preferences
     *
     * Per SRS: "Creates urgency, FOMO"
     */
    public function sendFlashDealNotification(array $dealData, Collection $recipients): int
    {
        $sentCount = 0;

        foreach ($recipients as $user) {
            try {
                $lang = $user->preferred_language ?? 'en';
                $notification = NotificationMessages::flashDealLive($dealData, $lang);

                // Send IMMEDIATELY — no batching, no quiet hours
                $this->whatsApp->sendButtons(
                    $user->phone,
                    $notification['message'],
                    $notification['buttons']
                );

                $sentCount++;

                // Small delay to avoid rate limiting
                usleep(50000); // 50ms

            } catch (\Exception $e) {
                Log::warning('Failed to send flash deal notification', [
                    'user_id' => $user->id,
                    'deal_id' => $dealData['deal_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Flash deal notifications sent', [
            'deal_id' => $dealData['deal_id'] ?? null,
            'sent_count' => $sentCount,
            'total_recipients' => $recipients->count(),
        ]);

        return $sentCount;
    }

    /**
     * Send Flash Deal progress update.
     */
    public function sendFlashDealProgress(array $dealData, Collection $claimants): int
    {
        $sentCount = 0;

        foreach ($claimants as $user) {
            try {
                $lang = $user->preferred_language ?? 'en';
                $notification = NotificationMessages::flashDealProgress($dealData, $lang);

                $this->whatsApp->sendButtons(
                    $user->phone,
                    $notification['message'],
                    $notification['buttons']
                );

                $sentCount++;

            } catch (\Exception $e) {
                Log::warning('Failed to send flash deal progress', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sentCount;
    }

    /**
     * Send Flash Deal activated celebration.
     */
    public function sendFlashDealActivated(array $dealData, Collection $claimants): int
    {
        $sentCount = 0;

        foreach ($claimants as $user) {
            try {
                $lang = $user->preferred_language ?? 'en';

                // Generate unique coupon code for this user
                $couponData = array_merge($dealData, [
                    'coupon_code' => $dealData['coupon_prefix'] . '-' . strtoupper(substr(md5($user->id . $dealData['deal_id']), 0, 6)),
                ]);

                $notification = NotificationMessages::flashDealActivated($couponData, $lang);

                $this->whatsApp->sendButtons(
                    $user->phone,
                    $notification['message'],
                    $notification['buttons']
                );

                $sentCount++;

            } catch (\Exception $e) {
                Log::warning('Failed to send flash deal activation', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sentCount;
    }

    /*
    |--------------------------------------------------------------------------
    | Fish Alert Notifications (Pacha Meen)
    |--------------------------------------------------------------------------
    */

    /**
     * Send fish alert to subscribed customers.
     */
    public function sendFishAlert(array $catchData, Collection $subscribers): int
    {
        $sentCount = 0;

        // Group by location for batching
        $byUser = $subscribers->groupBy('id');

        foreach ($byUser as $userId => $userGroup) {
            $user = $userGroup->first();
            $lang = $user->preferred_language ?? 'en';

            // Check quiet hours for non-priority
            if ($this->isQuietHours()) {
                $this->addToQuietHoursQueue($user, array_merge($catchData, ['type' => 'fish_alert']));
                continue;
            }

            try {
                $notification = NotificationMessages::fishAlert($catchData, $lang);

                $this->whatsApp->sendButtons(
                    $user->phone,
                    $notification['message'],
                    $notification['buttons']
                );

                $sentCount++;

            } catch (\Exception $e) {
                Log::warning('Failed to send fish alert', [
                    'user_id' => $userId,
                    'catch_id' => $catchData['catch_id'] ?? null,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sentCount;
    }

    /*
    |--------------------------------------------------------------------------
    | Response Notifications (to Customers)
    |--------------------------------------------------------------------------
    */

    /**
     * Notify customer about a new response.
     */
    public function notifyCustomerOfResponse(ProductRequest $request, Shop $shop, array $responseData): void
    {
        $customer = $request->user;

        if (!$customer) {
            return;
        }

        $lang = $customer->preferred_language ?? 'en';

        // Check quiet hours
        if ($this->isQuietHours()) {
            $this->addToQuietHoursQueue($customer, array_merge($responseData, [
                'type' => 'response',
                'shop_name' => $shop->name,
                'request_description' => $request->description,
            ]));
            return;
        }

        $notification = NotificationMessages::newResponse([
            'shop_name' => $shop->name,
            'price' => $responseData['price'] ?? 0,
            'distance_km' => $responseData['distance_km'] ?? 0,
            'response_id' => $responseData['id'] ?? null,
        ], $lang);

        SendWhatsAppMessage::dispatch(
            $customer->phone,
            $notification['message'],
            'buttons',
            $notification['buttons']
        )->onQueue('notifications');
    }

    /**
     * Notify customer that request has expired.
     */
    public function notifyRequestExpired(ProductRequest $request): void
    {
        $customer = $request->user;

        if (!$customer) {
            return;
        }

        $responseCount = $request->responses()->count();
        $lang = $customer->preferred_language ?? 'en';

        $notification = NotificationMessages::requestExpired([
            'description' => $request->description,
            'response_count' => $responseCount,
        ], $lang);

        SendWhatsAppMessage::dispatch(
            $customer->phone,
            $notification['message'],
            'buttons',
            $notification['buttons']
        )->onQueue('notifications');
    }

    /*
    |--------------------------------------------------------------------------
    | Offer Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify shop owner about expiring offer.
     */
    public function notifyOfferExpiring(\App\Models\Offer $offer): void
    {
        $shop = $offer->shop;
        $owner = $shop?->owner;

        if (!$owner) {
            return;
        }

        $lang = $owner->preferred_language ?? 'en';

        $notification = NotificationMessages::offerExpiring([
            'title' => $offer->title,
            'expires_at' => $offer->valid_until,
            'offer_id' => $offer->id,
        ], $lang);

        SendWhatsAppMessage::dispatch(
            $owner->phone,
            $notification['message'],
            'buttons',
            $notification['buttons']
        )->onQueue('notifications');
    }

    /**
     * Notify shop owner that offer has expired.
     */
    public function notifyOfferExpired(\App\Models\Offer $offer): void
    {
        $shop = $offer->shop;
        $owner = $shop?->owner;

        if (!$owner) {
            return;
        }

        $lang = $owner->preferred_language ?? 'en';

        $notification = NotificationMessages::offerExpired([
            'title' => $offer->title,
            'views' => $offer->view_count ?? 0,
        ], $lang);

        SendWhatsAppMessage::dispatch(
            $owner->phone,
            $notification['message'],
            'buttons',
            $notification['buttons']
        )->onQueue('notifications');
    }

    /*
    |--------------------------------------------------------------------------
    | Agreement Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Send agreement confirmation request to counterparty.
     */
    public function sendAgreementPending(\App\Models\Agreement $agreement): void
    {
        $lang = 'en'; // TODO: Get from counterparty if registered

        $notification = NotificationMessages::agreementPending([
            'creator_name' => $agreement->creator->name ?? 'Someone',
            'amount' => $agreement->amount,
            'purpose' => $agreement->purpose_type,
            'agreement_id' => $agreement->id,
        ], $lang);

        SendWhatsAppMessage::dispatch(
            $agreement->to_phone,
            $notification['message'],
            'buttons',
            $notification['buttons']
        )->onQueue('notifications');
    }

    /**
     * Send agreement confirmation reminder.
     */
    public function sendAgreementReminder(\App\Models\Agreement $agreement): void
    {
        $lang = 'en';

        $notification = NotificationMessages::agreementReminder([
            'creator_name' => $agreement->creator->name ?? 'Someone',
            'amount' => $agreement->amount,
            'agreement_id' => $agreement->id,
        ], $lang);

        SendWhatsAppMessage::dispatch(
            $agreement->to_phone,
            $notification['message'],
            'buttons',
            $notification['buttons']
        )->onQueue('notifications');
    }

    /**
     * Send agreement due soon reminder.
     */
    public function sendAgreementDueSoonReminder(\App\Models\Agreement $agreement, User $recipient): void
    {
        $otherParty = $agreement->creator_id === $recipient->id
            ? $agreement->to_name
            : $agreement->creator->name;

        $daysRemaining = now()->diffInDays($agreement->due_date);
        $lang = $recipient->preferred_language ?? 'en';

        $notification = NotificationMessages::agreementDueSoon([
            'other_party' => $otherParty,
            'amount' => $agreement->amount,
            'days_remaining' => $daysRemaining,
            'agreement_id' => $agreement->id,
        ], $lang);

        SendWhatsAppMessage::dispatch(
            $recipient->phone,
            $notification['message'],
            'buttons',
            $notification['buttons']
        )->onQueue('notifications');
    }

    /*
    |--------------------------------------------------------------------------
    | Scheduled Processing by Frequency
    |--------------------------------------------------------------------------
    */

    /**
     * Process notifications for a specific frequency.
     */
    public function processNotificationsForFrequency(NotificationFrequency $frequency): int
    {
        $batches = NotificationBatch::query()
            ->where('status', 'pending')
            ->where('frequency', $frequency)
            ->where('scheduled_for', '<=', now())
            ->get();

        $processed = 0;

        foreach ($batches as $batch) {
            try {
                $this->sendBatchedNotifications($batch);
                $processed++;
            } catch (\Exception $e) {
                Log::error('Failed to process scheduled batch', [
                    'batch_id' => $batch->id,
                    'frequency' => $frequency->value,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $processed;
    }

    /**
     * Process twice daily notifications (9 AM and 5 PM).
     */
    public function processTwiceDailyNotifications(): int
    {
        return $this->processNotificationsForFrequency(NotificationFrequency::TWICE_DAILY);
    }

    /**
     * Process daily notifications (9 AM).
     */
    public function processDailyNotifications(): int
    {
        return $this->processNotificationsForFrequency(NotificationFrequency::DAILY);
    }

    /**
     * Process 2-hour notifications.
     */
    public function process2HourlyNotifications(): int
    {
        return $this->processNotificationsForFrequency(NotificationFrequency::EVERY_2_HOURS);
    }

    /**
     * Process quiet hours queue (called at 7 AM).
     */
    public function processQuietHoursQueue(): int
    {
        $batches = NotificationBatch::query()
            ->where('status', 'pending')
            ->where('is_quiet_hours_batch', true)
            ->where('scheduled_for', '<=', now())
            ->get();

        $processed = 0;

        foreach ($batches as $batch) {
            try {
                $this->sendBatchedNotifications($batch);
                $processed++;
            } catch (\Exception $e) {
                Log::error('Failed to process quiet hours batch', [
                    'batch_id' => $batch->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Processed quiet hours queue', ['count' => $processed]);

        return $processed;
    }

    /*
    |--------------------------------------------------------------------------
    | Cleanup
    |--------------------------------------------------------------------------
    */

    /**
     * Clean up old notification batches.
     */
    public function cleanupOldBatches(int $daysOld = 7): int
    {
        return NotificationBatch::query()
            ->whereIn('status', ['sent', 'skipped', 'failed'])
            ->where('updated_at', '<', now()->subDays($daysOld))
            ->delete();
    }
}