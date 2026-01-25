<?php

declare(strict_types=1);

namespace App\Services\Fish;

use App\Enums\FishAlertFrequency;
use App\Enums\FishCatchStatus;
use App\Models\FishAlert;
use App\Models\FishAlertBatch;
use App\Models\FishCatch;
use App\Models\FishSubscription;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for managing fish catch alerts.
 *
 * Handles:
 * - Finding matching subscribers for a catch
 * - Creating and queuing alerts
 * - Sending immediate alerts
 * - Managing batched alerts
 * - Alert tracking and analytics
 *
 * @srs-ref Pacha Meen Module - Section 2.3.4 Alert Delivery
 * @srs-ref Pacha Meen Module - Section 2.5.2 Customer Alert Message Format
 */
class FishAlertService
{
    /**
     * Maximum alerts to send per batch.
     */
    public const BATCH_SIZE = 50;

    /**
     * Retry delay in minutes for failed alerts.
     */
    public const RETRY_DELAY_MINUTES = 5;

    /**
     * Maximum retry attempts.
     */
    public const MAX_RETRY_ATTEMPTS = 3;

    public function __construct(
        protected FishSubscriptionService $subscriptionService
    ) {}

    /**
     * Process a new catch and create alerts for matching subscribers.
     *
     * @param FishCatch $catch
     * @return array {
     *     @type int $immediate_count Number of immediate alerts queued
     *     @type int $batched_count Number of alerts added to batches
     *     @type int $total_subscribers Total matching subscribers
     * }
     */
    public function processNewCatch(FishCatch $catch): array
    {
        if (!$catch->is_active) {
            Log::warning('Attempted to process inactive catch', ['catch_id' => $catch->id]);
            return ['immediate_count' => 0, 'batched_count' => 0, 'total_subscribers' => 0];
        }

        // Find all matching subscriptions
        $subscriptions = $this->subscriptionService->findMatchingSubscriptions($catch);

        $immediateCount = 0;
        $batchedCount = 0;

        foreach ($subscriptions as $subscription) {
            // Skip if alert already exists for this catch/subscription
            if (FishAlert::existsForCatchAndSubscription($catch->id, $subscription->id)) {
                continue;
            }

            if ($subscription->alert_frequency === FishAlertFrequency::IMMEDIATE) {
                // Create immediate alert
                $this->createImmediateAlert($catch, $subscription);
                $immediateCount++;
            } else {
                // Add to batch
                $this->addToBatch($catch, $subscription);
                $batchedCount++;
            }
        }

        Log::info('Processed new catch for alerts', [
            'catch_id' => $catch->id,
            'immediate_count' => $immediateCount,
            'batched_count' => $batchedCount,
            'total_subscribers' => $subscriptions->count(),
        ]);

        return [
            'immediate_count' => $immediateCount,
            'batched_count' => $batchedCount,
            'total_subscribers' => $subscriptions->count(),
        ];
    }

    /**
     * Create an immediate alert for a catch and subscription.
     */
    public function createImmediateAlert(FishCatch $catch, FishSubscription $subscription): FishAlert
    {
        $alert = FishAlert::createForCatch(
            $catch,
            $subscription,
            FishAlert::TYPE_NEW_CATCH,
            false // not batched
        );

        Log::debug('Immediate alert created', [
            'alert_id' => $alert->id,
            'catch_id' => $catch->id,
            'user_id' => $subscription->user_id,
        ]);

        return $alert;
    }

    /**
     * Add catch to a batched alert.
     */
    public function addToBatch(FishCatch $catch, FishSubscription $subscription): FishAlert
    {
        // Get or create pending batch for this subscription
        $batch = FishAlertBatch::getOrCreatePending($subscription);

        // Add catch to batch
        $batch->addCatch($catch->id);

        // Create alert record linked to batch
        $alert = FishAlert::createForCatch(
            $catch,
            $subscription,
            FishAlert::TYPE_NEW_CATCH,
            true, // batched
            $batch->scheduled_for
        );

        $alert->update(['batch_id' => $batch->id]);

        return $alert;
    }

    /**
     * Get queued alerts ready to send.
     */
    public function getReadyAlerts(int $limit = null): Collection
    {
        $query = FishAlert::readyToSend()
            ->immediate()
            ->with(['catch.seller', 'catch.fishType', 'subscription', 'user']);

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Get pending batches ready to send.
     */
    public function getReadyBatches(int $limit = null): Collection
    {
        $query = FishAlertBatch::readyToSend()
            ->with(['subscription', 'user']);

        if ($limit) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * Mark alert as sent.
     */
    public function markAlertSent(FishAlert $alert, ?string $whatsappMessageId = null): FishAlert
    {
        $alert->markSent($whatsappMessageId);

        return $alert->fresh();
    }

    /**
     * Mark alert as delivered.
     */
    public function markAlertDelivered(FishAlert $alert): FishAlert
    {
        $alert->markDelivered();

        return $alert->fresh();
    }

    /**
     * Mark alert as failed.
     */
    public function markAlertFailed(FishAlert $alert, string $reason): FishAlert
    {
        $alert->markFailed($reason);

        Log::warning('Alert delivery failed', [
            'alert_id' => $alert->id,
            'reason' => $reason,
        ]);

        return $alert->fresh();
    }

    /**
     * Record alert click action.
     */
    public function recordAlertClick(FishAlert $alert, string $action): FishAlert
    {
        $alert->recordClick($action);

        Log::info('Alert click recorded', [
            'alert_id' => $alert->id,
            'action' => $action,
        ]);

        return $alert->fresh();
    }

    /**
     * Mark batch as sent.
     */
    public function markBatchSent(FishAlertBatch $batch, ?string $whatsappMessageId = null): FishAlertBatch
    {
        $batch->markSent($whatsappMessageId);

        return $batch->fresh();
    }

    /**
     * Mark batch as failed.
     */
    public function markBatchFailed(FishAlertBatch $batch, string $reason): FishAlertBatch
    {
        $batch->markFailed($reason);

        Log::warning('Batch delivery failed', [
            'batch_id' => $batch->id,
            'reason' => $reason,
        ]);

        return $batch->fresh();
    }

    /**
     * Find alert by ID.
     */
    public function findById(int $alertId): ?FishAlert
    {
        return FishAlert::with(['catch.seller', 'catch.fishType', 'subscription', 'user'])
            ->find($alertId);
    }

    /**
     * Find alert by WhatsApp message ID.
     */
    public function findByWhatsAppMessageId(string $messageId): ?FishAlert
    {
        return FishAlert::where('whatsapp_message_id', $messageId)->first();
    }

    /**
     * Get alerts for a user.
     */
    public function getUserAlerts(User $user, int $limit = 20): Collection
    {
        return FishAlert::forUser($user->id)
            ->with(['catch.seller', 'catch.fishType'])
            ->sent()
            ->orderBy('sent_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get alerts for a specific catch.
     */
    public function getCatchAlerts(FishCatch $catch): Collection
    {
        return FishAlert::forCatch($catch->id)
            ->with(['subscription', 'user'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get alert statistics for a catch.
     */
    public function getCatchAlertStats(FishCatch $catch): array
    {
        $alerts = $catch->alerts;

        return [
            'total_alerts' => $alerts->count(),
            'sent' => $alerts->where('status', FishAlert::STATUS_SENT)->count()
                + $alerts->where('status', FishAlert::STATUS_DELIVERED)->count(),
            'delivered' => $alerts->where('status', FishAlert::STATUS_DELIVERED)->count(),
            'failed' => $alerts->where('status', FishAlert::STATUS_FAILED)->count(),
            'clicked' => $alerts->where('was_clicked', true)->count(),
            'click_rate' => $alerts->count() > 0
                ? round($alerts->where('was_clicked', true)->count() / $alerts->count() * 100, 1)
                : 0,
            'actions' => [
                'coming' => $alerts->where('click_action', FishAlert::ACTION_COMING)->count(),
                'message' => $alerts->where('click_action', FishAlert::ACTION_MESSAGE)->count(),
                'location' => $alerts->where('click_action', FishAlert::ACTION_LOCATION)->count(),
            ],
        ];
    }

    /**
     * Create a low stock alert for existing subscribers.
     */
    public function sendLowStockAlerts(FishCatch $catch): int
    {
        if ($catch->status !== FishCatchStatus::LOW_STOCK) {
            return 0;
        }

        // Find users who already received alert and clicked "coming"
        $alertedUsers = FishAlert::forCatch($catch->id)
            ->sent()
            ->where('click_action', FishAlert::ACTION_COMING)
            ->pluck('user_id')
            ->toArray();

        if (empty($alertedUsers)) {
            return 0;
        }

        $count = 0;

        foreach ($alertedUsers as $userId) {
            // Create low stock alert
            $subscription = FishSubscription::where('user_id', $userId)
                ->active()
                ->first();

            if ($subscription && !FishAlert::where('fish_catch_id', $catch->id)
                ->where('user_id', $userId)
                ->where('alert_type', FishAlert::TYPE_LOW_STOCK)
                ->exists()
            ) {
                FishAlert::create([
                    'fish_catch_id' => $catch->id,
                    'fish_subscription_id' => $subscription->id,
                    'user_id' => $userId,
                    'alert_type' => FishAlert::TYPE_LOW_STOCK,
                    'status' => FishAlert::STATUS_QUEUED,
                    'queued_at' => now(),
                    'is_batched' => false,
                ]);
                $count++;
            }
        }

        if ($count > 0) {
            Log::info('Low stock alerts created', [
                'catch_id' => $catch->id,
                'count' => $count,
            ]);
        }

        return $count;
    }

    /**
     * Build alert message data for WhatsApp.
     *
     * @srs-ref Section 2.5.2 - Customer Alert Message Format
     */
    public function buildAlertMessageData(FishAlert $alert): array
    {
        $catch = $alert->catch;
        $seller = $catch->seller;
        $fishType = $catch->fishType;

        $header = $alert->alert_type === FishAlert::TYPE_LOW_STOCK
            ? "⚠️ Low Stock Alert!"
            : "🐟 Fresh {$fishType->name_en} Available!";

        $body = $this->formatAlertBody($catch, $seller, $fishType, $alert);

        $buttons = $this->buildAlertButtons($catch, $alert);

        return [
            'header' => $header,
            'body' => $body,
            'buttons' => $buttons,
            'image_url' => $catch->photo_url,
            'catch_data' => $catch->toAlertFormat(),
            'seller_data' => $seller->toAlertFormat(),
        ];
    }

    /**
     * Build batch digest message data.
     */
    public function buildBatchMessageData(FishAlertBatch $batch): array
    {
        $catches = $batch->catches();
        $subscription = $batch->subscription;

        $header = "🐟 Fish Alert Digest";

        $bodyLines = [
            "Fresh catches near {$subscription->location_display}:",
            "",
        ];

        foreach ($catches as $catch) {
            $fishName = $catch->fishType?->display_name ?? '🐟 Fish';
            $price = $catch->price_display;
            $seller = $catch->seller?->business_name ?? 'Seller';
            $freshness = $catch->freshness_display;

            $bodyLines[] = "• {$fishName} @ {$price}";
            $bodyLines[] = "  📍 {$seller} • {$freshness}";
            $bodyLines[] = "";
        }

        $body = implode("\n", $bodyLines);

        return [
            'header' => $header,
            'body' => $body,
            'catch_count' => $batch->catch_count,
            'catches' => $catches->map(fn($c) => $c->toAlertFormat())->toArray(),
        ];
    }

    /**
     * Process scheduled batches by frequency.
     */
    public function processScheduledBatches(FishAlertFrequency $frequency): int
    {
        $batches = FishAlertBatch::pending()
            ->ofFrequency($frequency)
            ->where('scheduled_for', '<=', now())
            ->where('catch_count', '>', 0)
            ->get();

        $processed = 0;

        foreach ($batches as $batch) {
            // Filter out expired catches
            $activeCatchIds = FishCatch::whereIn('id', $batch->catch_ids ?? [])
                ->active()
                ->pluck('id')
                ->toArray();

            if (empty($activeCatchIds)) {
                // No active catches, mark batch as sent (nothing to send)
                $batch->update([
                    'status' => FishAlertBatch::STATUS_SENT,
                    'sent_at' => now(),
                    'catch_ids' => [],
                    'catch_count' => 0,
                ]);
                continue;
            }

            // Update batch with only active catches
            $batch->update([
                'catch_ids' => $activeCatchIds,
                'catch_count' => count($activeCatchIds),
            ]);

            // Mark as ready for sending (will be picked up by job)
            $processed++;
        }

        return $processed;
    }

    /**
     * Clean up old alerts.
     */
    public function cleanupOldAlerts(int $daysOld = 30): int
    {
        $cutoff = now()->subDays($daysOld);

        $count = FishAlert::where('created_at', '<', $cutoff)->delete();

        if ($count > 0) {
            Log::info('Cleaned up old alerts', ['count' => $count, 'days_old' => $daysOld]);
        }

        return $count;
    }

    /**
     * Get alert analytics for a time period.
     */
    public function getAnalytics(\Carbon\Carbon $from, \Carbon\Carbon $to): array
    {
        $alerts = FishAlert::whereBetween('created_at', [$from, $to])->get();

        return [
            'total_alerts' => $alerts->count(),
            'sent' => $alerts->whereIn('status', [FishAlert::STATUS_SENT, FishAlert::STATUS_DELIVERED])->count(),
            'delivered' => $alerts->where('status', FishAlert::STATUS_DELIVERED)->count(),
            'failed' => $alerts->where('status', FishAlert::STATUS_FAILED)->count(),
            'clicked' => $alerts->where('was_clicked', true)->count(),
            'click_rate' => $alerts->count() > 0
                ? round($alerts->where('was_clicked', true)->count() / $alerts->count() * 100, 1)
                : 0,
            'by_type' => [
                'new_catch' => $alerts->where('alert_type', FishAlert::TYPE_NEW_CATCH)->count(),
                'low_stock' => $alerts->where('alert_type', FishAlert::TYPE_LOW_STOCK)->count(),
            ],
            'by_action' => [
                'coming' => $alerts->where('click_action', FishAlert::ACTION_COMING)->count(),
                'message' => $alerts->where('click_action', FishAlert::ACTION_MESSAGE)->count(),
                'location' => $alerts->where('click_action', FishAlert::ACTION_LOCATION)->count(),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Format alert body text.
     */
    protected function formatAlertBody(
        FishCatch $catch,
        $seller,
        $fishType,
        FishAlert $alert
    ): string {
        $distance = $alert->distance_km
            ? ($alert->distance_km < 1
                ? round($alert->distance_km * 1000) . 'm'
                : round($alert->distance_km, 1) . ' km')
            : '';

        $lines = [
            "{$fishType->emoji} *{$fishType->name_en}* ({$fishType->name_ml})",
            "",
            "💰 *{$catch->price_display}*",
            "📦 Quantity: {$catch->quantity_display}",
            "⏰ Arrived: {$catch->freshness_display}",
            "",
            "📍 *{$seller->business_name}*",
            "{$catch->location_display}",
        ];

        if ($distance) {
            $lines[] = "🚗 {$distance} away";
        }

        if ($seller->rating_count > 0) {
            $lines[] = "";
            $lines[] = "{$seller->short_rating}";
        }

        return implode("\n", $lines);
    }

    /**
     * Build alert action buttons.
     */
    protected function buildAlertButtons(FishCatch $catch, FishAlert $alert): array
    {
        return [
            [
                'id' => "fish_coming_{$catch->id}_{$alert->id}",
                'title' => "🏃 I'm Coming!",
            ],
            [
                'id' => "fish_message_{$catch->id}_{$alert->id}",
                'title' => "💬 Message Seller",
            ],
            [
                'id' => "fish_location_{$catch->id}_{$alert->id}",
                'title' => "📍 Get Location",
            ],
        ];
    }
}
