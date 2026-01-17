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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing notifications.
 *
 * Handles notification queuing, batching, and sending based on
 * shop preferences.
 *
 * @example
 * $notificationService = app(NotificationService::class);
 *
 * // Queue notification for a product request
 * $notificationService->notifyShopsOfRequest($productRequest);
 *
 * // Send batched notifications
 * $notificationService->processBatchedNotifications();
 */
class NotificationService
{
    public function __construct(
        protected WhatsAppService $whatsApp,
        protected ProductSearchService $searchService,
    ) {}

    /*
    |--------------------------------------------------------------------------
    | Product Request Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify all eligible shops about a new product request.
     *
     * @param ProductRequest $request
     * @return int Number of shops notified
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
     * @param ProductRequest $request
     * @param Shop $shop
     * @return void
     */
    public function queueProductRequestNotification(ProductRequest $request, Shop $shop): void
    {
        $frequency = $shop->notification_frequency ?? NotificationFrequency::IMMEDIATE;

        if ($frequency === NotificationFrequency::IMMEDIATE) {
            $this->sendImmediateNotification($request, $shop);
            return;
        }

        // Add to batch
        $this->addToBatch($shop, 'product_request', [
            'request_id' => $request->id,
            'request_number' => $request->request_number,
            'description' => $request->description,
            'category' => $request->category,
            'distance_km' => $shop->distance_km ?? 0,
            'expires_at' => $request->expires_at->toIso8601String(),
        ]);
    }

    /**
     * Send immediate notification for a product request.
     *
     * @param ProductRequest $request
     * @param Shop $shop
     * @return void
     */
    public function sendImmediateNotification(ProductRequest $request, Shop $shop): void
    {
        $owner = $shop->owner;

        if (!$owner) {
            return;
        }

        $message = NotificationMessages::format(NotificationMessages::NEW_REQUEST_SINGLE, [
            'description' => $request->description,
            'distance' => NotificationMessages::formatDistance($shop->distance_km ?? 0),
            'expires_in' => NotificationMessages::formatTimeRemaining($request->expires_at),
            'request_number' => $request->request_number,
        ]);

        // Queue the WhatsApp message
        SendWhatsAppMessage::dispatch(
            $owner->phone,
            $message,
            'buttons',
            NotificationMessages::getRespondButtons()
        )->onQueue('notifications');

        Log::debug('Immediate notification sent', [
            'shop_id' => $shop->id,
            'request_id' => $request->id,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Batch Management
    |--------------------------------------------------------------------------
    */

    /**
     * Add notification to batch.
     *
     * @param Shop $shop
     * @param string $type
     * @param array $data
     * @return NotificationBatch
     */
    public function addToBatch(Shop $shop, string $type, array $data): NotificationBatch
    {
        $frequency = $shop->notification_frequency ?? NotificationFrequency::TWICE_DAILY;

        // Find or create pending batch for this shop
        $batch = NotificationBatch::firstOrCreate(
            [
                'shop_id' => $shop->id,
                'status' => 'pending',
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
     *
     * @param NotificationFrequency $frequency
     * @return \Carbon\Carbon
     */
    protected function calculateNextSendTime(NotificationFrequency $frequency): \Carbon\Carbon
    {
        $now = now();

        return match ($frequency) {
            NotificationFrequency::IMMEDIATE => $now,

            NotificationFrequency::EVERY_2_HOURS => $now->copy()
                ->addHours(2)
                ->startOfHour(),

            NotificationFrequency::TWICE_DAILY => $this->getNextTwiceDailyTime($now),

            NotificationFrequency::DAILY => $this->getNextDailyTime($now),

            default => $now->addHours(2),
        };
    }

    /**
     * Get next twice daily notification time (9 AM or 5 PM).
     *
     * @param \Carbon\Carbon $now
     * @return \Carbon\Carbon
     */
    protected function getNextTwiceDailyTime(\Carbon\Carbon $now): \Carbon\Carbon
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
     *
     * @param \Carbon\Carbon $now
     * @return \Carbon\Carbon
     */
    protected function getNextDailyTime(\Carbon\Carbon $now): \Carbon\Carbon
    {
        $morningTime = $now->copy()->setTime(9, 0);

        if ($now->lt($morningTime)) {
            return $morningTime;
        }

        return $morningTime->addDay();
    }

    /**
     * Process all pending batched notifications.
     *
     * @return int Number of batches processed
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
     * Send batched notifications.
     *
     * @param NotificationBatch $batch
     * @return void
     */
    public function sendBatchedNotifications(NotificationBatch $batch): void
    {
        $shop = $batch->shop;
        $owner = $shop?->owner;

        if (!$owner) {
            $batch->update(['status' => 'skipped', 'error' => 'No shop owner']);
            return;
        }

        $items = $batch->items ?? [];

        if (empty($items)) {
            $batch->update(['status' => 'skipped', 'error' => 'No items']);
            return;
        }

        // Filter to only valid product requests
        $productRequests = collect($items)
            ->filter(fn($item) => ($item['type'] ?? '') === 'product_request')
            ->values()
            ->toArray();

        if (empty($productRequests)) {
            $batch->update(['status' => 'skipped', 'error' => 'No product requests']);
            return;
        }

        // Build the batch message
        $message = $this->buildBatchMessage($productRequests);

        // Send via WhatsApp
        $this->whatsApp->sendText($owner->phone, $message);

        // Update batch status
        $batch->update([
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        Log::info('Batch notification sent', [
            'batch_id' => $batch->id,
            'shop_id' => $shop->id,
            'item_count' => count($productRequests),
        ]);
    }

    /**
     * Build batch notification message.
     *
     * @param array $requests
     * @return string
     */
    protected function buildBatchMessage(array $requests): string
    {
        $requestList = NotificationMessages::buildRequestList($requests);

        return NotificationMessages::format(NotificationMessages::NEW_REQUESTS_BATCH, [
            'count' => count($requests),
            'request_list' => $requestList,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Response Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify customer about a new response.
     *
     * @param ProductRequest $request
     * @param Shop $shop
     * @param array $responseData
     * @return void
     */
    public function notifyCustomerOfResponse(ProductRequest $request, Shop $shop, array $responseData): void
    {
        $customer = $request->user;

        if (!$customer) {
            return;
        }

        $message = NotificationMessages::format(NotificationMessages::NEW_RESPONSE, [
            'shop_name' => $shop->name,
            'price' => number_format($responseData['price'] ?? 0),
            'distance' => NotificationMessages::formatDistance($responseData['distance_km'] ?? 0),
            'description' => $responseData['description'] ?? '',
            'request_description' => $request->description,
        ]);

        SendWhatsAppMessage::dispatch(
            $customer->phone,
            $message,
            'buttons',
            NotificationMessages::getViewResponsesButtons()
        )->onQueue('notifications');
    }

    /**
     * Notify customer about multiple responses.
     *
     * @param ProductRequest $request
     * @param int $responseCount
     * @return void
     */
    public function notifyCustomerOfMultipleResponses(ProductRequest $request, int $responseCount): void
    {
        $customer = $request->user;

        if (!$customer) {
            return;
        }

        $message = NotificationMessages::format(NotificationMessages::MULTIPLE_RESPONSES, [
            'count' => $responseCount,
            'request_description' => $request->description,
        ]);

        SendWhatsAppMessage::dispatch(
            $customer->phone,
            $message,
            'buttons',
            NotificationMessages::getViewResponsesButtons()
        )->onQueue('notifications');
    }

    /**
     * Notify customer that request has expired.
     *
     * @param ProductRequest $request
     * @return void
     */
    public function notifyRequestExpired(ProductRequest $request): void
    {
        $customer = $request->user;

        if (!$customer) {
            return;
        }

        $responseCount = $request->responses()->count();

        $message = NotificationMessages::format(NotificationMessages::REQUEST_EXPIRED, [
            'description' => $request->description,
            'response_count' => $responseCount,
        ]);

        SendWhatsAppMessage::dispatch(
            $customer->phone,
            $message,
            'buttons',
            NotificationMessages::getExpiredRequestButtons()
        )->onQueue('notifications');
    }

    /*
    |--------------------------------------------------------------------------
    | Offer Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify shop owner about expiring offer.
     *
     * @param \App\Models\Offer $offer
     * @return void
     */
    public function notifyOfferExpiring(\App\Models\Offer $offer): void
    {
        $shop = $offer->shop;
        $owner = $shop?->owner;

        if (!$owner) {
            return;
        }

        $message = NotificationMessages::format(NotificationMessages::OFFER_EXPIRING, [
            'title' => $offer->title,
            'expires_in' => NotificationMessages::formatTimeRemaining($offer->valid_until),
        ]);

        SendWhatsAppMessage::dispatch(
            $owner->phone,
            $message,
            'buttons',
            NotificationMessages::getRenewOfferButtons()
        )->onQueue('notifications');
    }

    /**
     * Notify shop owner that offer has expired.
     *
     * @param \App\Models\Offer $offer
     * @return void
     */
    public function notifyOfferExpired(\App\Models\Offer $offer): void
    {
        $shop = $offer->shop;
        $owner = $shop?->owner;

        if (!$owner) {
            return;
        }

        $message = NotificationMessages::format(NotificationMessages::OFFER_EXPIRED, [
            'title' => $offer->title,
        ]);

        SendWhatsAppMessage::dispatch(
            $owner->phone,
            $message,
            'buttons',
            NotificationMessages::getRenewOfferButtons()
        )->onQueue('notifications');
    }

    /*
    |--------------------------------------------------------------------------
    | Agreement Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Send agreement confirmation reminder.
     *
     * @param \App\Models\Agreement $agreement
     * @return void
     */
    public function sendAgreementReminder(\App\Models\Agreement $agreement): void
    {
        $message = NotificationMessages::format(NotificationMessages::AGREEMENT_REMINDER, [
            'creator_name' => $agreement->creator->name ?? 'Someone',
            'agreement_number' => $agreement->agreement_number,
            'amount' => number_format($agreement->amount),
            'due_date' => $agreement->due_date?->format('M j, Y') ?? 'No fixed date',
        ]);

        SendWhatsAppMessage::dispatch(
            $agreement->counterparty_phone,
            $message,
            'buttons',
            NotificationMessages::getAgreementReminderButtons()
        )->onQueue('notifications');
    }

    /**
     * Send agreement due soon reminder.
     *
     * @param \App\Models\Agreement $agreement
     * @param User $recipient
     * @return void
     */
    public function sendAgreementDueSoonReminder(\App\Models\Agreement $agreement, User $recipient): void
    {
        $otherParty = $agreement->creator_id === $recipient->id
            ? $agreement->counterparty_name
            : $agreement->creator->name;

        $daysRemaining = now()->diffInDays($agreement->due_date);

        $message = NotificationMessages::format(NotificationMessages::AGREEMENT_DUE_SOON, [
            'other_party' => $otherParty,
            'amount' => number_format($agreement->amount),
            'days_remaining' => $daysRemaining,
            'agreement_number' => $agreement->agreement_number,
        ]);

        SendWhatsAppMessage::dispatch(
            $recipient->phone,
            $message,
            'text'
        )->onQueue('notifications');
    }

    /*
    |--------------------------------------------------------------------------
    | Scheduled Notifications by Frequency
    |--------------------------------------------------------------------------
    */

    /**
     * Process notifications for a specific frequency.
     *
     * @param NotificationFrequency $frequency
     * @return int
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
     *
     * @return int
     */
    public function processTwiceDailyNotifications(): int
    {
        return $this->processNotificationsForFrequency(NotificationFrequency::TWICE_DAILY);
    }

    /**
     * Process daily notifications (9 AM).
     *
     * @return int
     */
    public function processDailyNotifications(): int
    {
        return $this->processNotificationsForFrequency(NotificationFrequency::DAILY);
    }

    /**
     * Process 2-hour notifications.
     *
     * @return int
     */
    public function process2HourlyNotifications(): int
    {
        return $this->processNotificationsForFrequency(NotificationFrequency::EVERY_2_HOURS);
    }

    /*
    |--------------------------------------------------------------------------
    | Cleanup
    |--------------------------------------------------------------------------
    */

    /**
     * Clean up old notification batches.
     *
     * @param int $daysOld
     * @return int
     */
    public function cleanupOldBatches(int $daysOld = 7): int
    {
        return NotificationBatch::query()
            ->whereIn('status', ['sent', 'skipped', 'failed'])
            ->where('updated_at', '<', now()->subDays($daysOld))
            ->delete();
    }
}