<?php

declare(strict_types=1);

namespace App\Services\FlashDeals;

use App\Enums\FlashDealStatus;
use App\Jobs\SendFlashDealAlertJob;
use App\Models\FlashDeal;
use App\Models\FlashDealClaim;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Service for sending Flash Deal notifications.
 *
 * THIS is where the viral loop starts!
 * - Notifies all customers when deal goes live
 * - Sends progress updates
 * - Celebrates activation
 * - Notifies on expiry
 *
 * @srs-ref FD-009 to FD-013 - Notification & Display
 * @srs-ref FD-019 to FD-028 - Activation & Expiry
 * @module Flash Mob Deals
 */
class FlashDealNotificationService
{
    /**
     * Default notification radius in km.
     */
    protected const DEFAULT_RADIUS_KM = 3;

    /**
     * Maximum notifications per batch.
     */
    protected const BATCH_SIZE = 50;

    public function __construct(
        protected WhatsAppService $whatsApp
    ) {}

    /**
     * Send deal live notifications to all nearby customers.
     *
     * @srs-ref FD-009 - Notify ALL customers within radius when deal goes live
     */
    public function sendDealLiveNotifications(FlashDeal $deal, float $radiusKm = self::DEFAULT_RADIUS_KM): int
    {
        $shop = $deal->shop;

        // Find all customers within radius
        $customers = $this->findNearbyCustomers(
            $shop->latitude,
            $shop->longitude,
            $radiusKm
        );

        $notified = 0;

        // Queue notifications in batches
        foreach ($customers->chunk(self::BATCH_SIZE) as $batch) {
            foreach ($batch as $customer) {
                // Dispatch job for each customer
                SendFlashDealAlertJob::dispatch($deal, $customer);
                $notified++;
            }
        }

        // Update deal with notification count
        $deal->update(['notified_customers_count' => $notified]);

        Log::info('Flash deal notifications queued', [
            'deal_id' => $deal->id,
            'customers_notified' => $notified,
            'radius_km' => $radiusKm,
        ]);

        return $notified;
    }

    /**
     * Send individual deal alert to customer.
     *
     * @srs-ref FD-010 - Include: title, discount, shop, distance, time, claims, rating
     * @srs-ref FD-011 - Buttons: "I'm In!", "Share with Friends", "Not Interested"
     * @srs-ref FD-012 - LIVE indicator + countdown timer
     * @srs-ref FD-013 - Claim progress: "X/Y claimed"
     */
    public function sendDealAlert(FlashDeal $deal, User $customer): void
    {
        $shop = $deal->shop;

        // Calculate distance
        $distance = $customer->latitude && $customer->longitude
            ? $deal->formattedDistanceFrom($customer->latitude, $customer->longitude)
            : 'nearby';

        // Build alert message per SRS FD-010 to FD-013
        $message = $this->buildAlertMessage($deal, $shop, $distance);

        try {
            // Send image first
            $this->whatsApp->sendImage(
                $customer->phone,
                $deal->image_url,
                "⚡ FLASH DEAL from {$shop->shop_name}!"
            );

            // Send interactive message with buttons
            $this->whatsApp->sendButtons(
                $customer->phone,
                $message,
                [
                    ['id' => 'flash_claim_' . $deal->id, 'title' => "⚡ I'm In!"],
                    ['id' => 'flash_share_' . $deal->id, 'title' => '📤 Share'],
                    ['id' => 'flash_skip_' . $deal->id, 'title' => '❌ No Thanks'],
                ],
                '🔴 LIVE NOW!'
            );

            Log::debug('Flash deal alert sent', [
                'deal_id' => $deal->id,
                'customer_id' => $customer->id,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send flash deal alert', [
                'deal_id' => $deal->id,
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Build alert message per SRS requirements.
     *
     * @srs-ref FD-010, FD-012, FD-013
     */
    protected function buildAlertMessage(FlashDeal $deal, $shop, string $distance): string
    {
        $capDisplay = $deal->max_discount_value
            ? " (max ₹{$deal->max_discount_value})"
            : '';

        $ratingDisplay = $shop->rating
            ? "⭐ {$shop->rating}"
            : '';

        return "⚡ *FLASH DEAL!* 🔴 LIVE\n" .
            "*ഫ്ലാഷ് ഡീൽ!*\n\n" .
            "━━━━━━━━━━━━━━━\n" .
            "🎯 *{$deal->title}*\n\n" .
            "🏪 {$shop->shop_name} • {$distance} away\n" .
            ($ratingDisplay ? "{$ratingDisplay}\n" : '') .
            "\n" .
            "💰 *{$deal->discount_percent}% OFF*{$capDisplay}\n\n" .
            "👥 {$deal->progress_display} claimed\n" .
            "{$deal->progress_bar}\n\n" .
            "⏰ *{$deal->time_remaining_display}* remaining\n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "⚠️ *Deal activates ONLY if {$deal->target_claims} people claim!*\n" .
            "_ഡീൽ ആക്ടിവേറ്റ് ആകണമെങ്കിൽ {$deal->target_claims} പേർ ക്ലെയിം ചെയ്യണം!_";
    }

    /**
     * Send activation celebration to all claimants.
     *
     * @srs-ref FD-019 - Mark deal as ACTIVATED
     * @srs-ref FD-020 - Generate unique coupon codes
     * @srs-ref FD-021 - Send activation celebration with coupon
     * @srs-ref FD-022 - Include code, address, validity, directions
     */
    public function sendActivationNotifications(FlashDeal $deal): void
    {
        $shop = $deal->shop;

        // Get all claims
        $claims = $deal->claims()->with('user')->get();

        foreach ($claims as $claim) {
            // Generate coupon if not already generated
            if (!$claim->coupon_code) {
                $claim->generateCouponCode();
            }

            $this->sendActivationMessage($deal, $claim, $shop);
        }

        // Notify shop owner
        $this->notifyShopOwnerOfActivation($deal, $claims->count());

        Log::info('Flash deal activation notifications sent', [
            'deal_id' => $deal->id,
            'claims_notified' => $claims->count(),
        ]);
    }

    /**
     * Send activation message to individual claimant.
     *
     * @srs-ref FD-021, FD-022
     */
    protected function sendActivationMessage(FlashDeal $deal, FlashDealClaim $claim, $shop): void
    {
        $user = $claim->user;
        if (!$user || !$user->phone) {
            return;
        }

        $validUntil = $deal->coupon_valid_until
            ? $deal->coupon_valid_until->format('M d, h:i A')
            : 'Today';

        $message = "🎉🎉🎉 *DEAL ACTIVATED!* 🎉🎉🎉\n" .
            "*ഡീൽ ആക്ടിവേറ്റ് ആയി!*\n\n" .
            "━━━━━━━━━━━━━━━\n" .
            "🎯 *{$deal->title}*\n" .
            "💰 {$deal->discount_display}\n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "🎫 *Your Coupon Code:*\n" .
            "┏━━━━━━━━━━━━━━━┓\n" .
            "┃  *{$claim->coupon_code}*  ┃\n" .
            "┗━━━━━━━━━━━━━━━┛\n\n" .
            "🏪 *Redeem at:* {$shop->shop_name}\n" .
            "📍 {$shop->address}\n" .
            "⏰ *Valid until:* {$validUntil}\n\n" .
            "📱 _Show this code at the shop to get your discount!_\n" .
            "_ഡിസ്കൗണ്ട് ലഭിക്കാൻ ഷോപ്പിൽ ഈ കോഡ് കാണിക്കുക!_";

        try {
            $this->whatsApp->sendButtons(
                $user->phone,
                $message,
                [
                    ['id' => 'flash_directions_' . $deal->id, 'title' => '📍 Get Directions'],
                    ['id' => 'flash_share_' . $deal->id, 'title' => '📤 Share Victory!'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '🎉 COUPON UNLOCKED!'
            );

        } catch (\Exception $e) {
            Log::warning('Failed to send activation message', [
                'deal_id' => $deal->id,
                'claim_id' => $claim->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify shop owner of deal activation.
     *
     * @srs-ref FD-023 - Notify shop owner with total claims and expected footfall
     */
    protected function notifyShopOwnerOfActivation(FlashDeal $deal, int $totalClaims): void
    {
        $shop = $deal->shop;
        $owner = $shop->user;

        if (!$owner || !$owner->phone) {
            return;
        }

        $message = "🎉 *FLASH DEAL ACTIVATED!*\n" .
            "*ഫ്ലാഷ് ഡീൽ ആക്ടിവേറ്റ് ആയി!*\n\n" .
            "━━━━━━━━━━━━━━━\n" .
            "🎯 *{$deal->title}*\n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "✅ *Target reached!*\n" .
            "📊 Total claims: *{$totalClaims}*\n" .
            "👥 Expected footfall: *{$totalClaims} customers*\n\n" .
            "📝 _Customers will show coupon codes starting with:_\n" .
            "*{$deal->coupon_prefix}-XXXXXX*\n\n" .
            "⏰ Coupons valid until: {$deal->coupon_valid_until->format('M d, h:i A')}\n\n" .
            "_Get ready for the rush!_ 🏃";

        try {
            $this->whatsApp->sendButtons(
                $owner->phone,
                $message,
                [
                    ['id' => 'view_deal_claims_' . $deal->id, 'title' => '📋 View Claims'],
                    ['id' => 'flash_create', 'title' => '⚡ Create Another'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '🎉 Deal Activated!'
            );

        } catch (\Exception $e) {
            Log::warning('Failed to notify shop owner of activation', [
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send expiry notifications to all claimants.
     *
     * @srs-ref FD-025 - Mark deal as EXPIRED
     * @srs-ref FD-026 - Notify claimants with final count
     * @srs-ref FD-027 - Include "Follow Shop" option
     */
    public function sendExpiryNotifications(FlashDeal $deal): void
    {
        // Notify all claimants
        $claims = $deal->claims()->with('user')->get();

        foreach ($claims as $claim) {
            $this->sendExpiryMessage($deal, $claim);
        }

        // Send analytics to shop owner
        $this->sendExpiryAnalytics($deal);

        Log::info('Flash deal expiry notifications sent', [
            'deal_id' => $deal->id,
            'claims_notified' => $claims->count(),
        ]);
    }

    /**
     * Send expiry message to individual claimant.
     *
     * @srs-ref FD-026, FD-027
     */
    protected function sendExpiryMessage(FlashDeal $deal, FlashDealClaim $claim): void
    {
        $user = $claim->user;
        if (!$user || !$user->phone) {
            return;
        }

        $message = "⏰ *Deal Expired*\n" .
            "*ഡീൽ കാലഹരണപ്പെട്ടു*\n\n" .
            "━━━━━━━━━━━━━━━\n" .
            "🎯 *{$deal->title}*\n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "📊 Final count: {$deal->progress_display}\n" .
            "{$deal->progress_bar}\n\n" .
            "❌ Target of {$deal->target_claims} not reached.\n" .
            "ടാർഗെറ്റ് എത്തിയില്ല.\n\n" .
            "_Don't worry! Follow the shop for future deals._\n" .
            "_വിഷമിക്കേണ്ട! ഭാവിയിലെ ഡീലുകൾക്കായി ഷോപ്പ് ഫോളോ ചെയ്യുക._";

        try {
            $this->whatsApp->sendButtons(
                $user->phone,
                $message,
                [
                    ['id' => 'follow_shop_' . $deal->shop_id, 'title' => '🔔 Follow Shop'],
                    ['id' => 'browse_flash_deals', 'title' => '⚡ Other Deals'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '⏰ Deal Expired'
            );

        } catch (\Exception $e) {
            Log::warning('Failed to send expiry message', [
                'deal_id' => $deal->id,
                'claim_id' => $claim->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send expiry analytics to shop owner.
     *
     * @srs-ref FD-028 - Send analytics with suggestions
     */
    protected function sendExpiryAnalytics(FlashDeal $deal): void
    {
        $shop = $deal->shop;
        $owner = $shop->user;

        if (!$owner || !$owner->phone) {
            return;
        }

        // Calculate analytics
        $claimRate = $deal->notified_customers_count > 0
            ? round(($deal->current_claims / $deal->notified_customers_count) * 100, 1)
            : 0;

        $shortfall = $deal->claims_remaining;

        // Determine suggestions
        $suggestions = [];
        if ($deal->target_claims > 30) {
            $suggestions[] = "• Try a lower target (20-30)";
        }
        if ($deal->time_limit_minutes < 30) {
            $suggestions[] = "• Try a longer time window";
        }
        if ($deal->discount_percent < 30) {
            $suggestions[] = "• Higher discount may attract more";
        }

        $message = "📊 *Flash Deal Analytics*\n" .
            "*ഫ്ലാഷ് ഡീൽ അനലിറ്റിക്സ്*\n\n" .
            "━━━━━━━━━━━━━━━\n" .
            "🎯 *{$deal->title}*\n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "📈 *Results:*\n" .
            "• Customers notified: {$deal->notified_customers_count}\n" .
            "• Total claims: {$deal->current_claims}\n" .
            "• Claim rate: {$claimRate}%\n" .
            "• Target shortfall: {$shortfall}\n\n";

        if (!empty($suggestions)) {
            $message .= "💡 *Suggestions for next time:*\n" .
                implode("\n", $suggestions) . "\n\n";
        }

        $message .= "_Don't give up! Try again with adjusted settings._\n" .
            "_ഹാർ മാനല്ലേ! സെറ്റിംഗ്‌സ് മാറ്റി വീണ്ടും ശ്രമിക്കുക._";

        try {
            $this->whatsApp->sendButtons(
                $owner->phone,
                $message,
                [
                    ['id' => 'flash_create', 'title' => '⚡ Try Again'],
                    ['id' => 'my_flash_deals', 'title' => '📋 My Deals'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '📊 Deal Analytics'
            );

        } catch (\Exception $e) {
            Log::warning('Failed to send expiry analytics', [
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Find customers within radius.
     */
    protected function findNearbyCustomers(float $lat, float $lng, float $radiusKm): Collection
    {
        return User::query()
            ->where('type', 'customer')
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->whereNotNull('phone')
            ->whereRaw(
                "ST_Distance_Sphere(POINT(longitude, latitude), POINT(?, ?)) <= ?",
                [$lng, $lat, $radiusKm * 1000]
            )
            ->get();
    }

    /**
     * Send location/directions to customer.
     */
    public function sendDirections(FlashDeal $deal, User $user): void
    {
        $shop = $deal->shop;

        // Send location message
        $this->whatsApp->sendLocation(
            $user->phone,
            $shop->latitude,
            $shop->longitude,
            $shop->shop_name,
            $shop->address
        );

        // Send follow-up text
        $this->whatsApp->sendText(
            $user->phone,
            "📍 *{$shop->shop_name}*\n{$shop->address}\n\n" .
            "_Show your coupon code when you arrive!_\n" .
            "_എത്തുമ്പോൾ കൂപ്പൺ കോഡ് കാണിക്കുക!_"
        );
    }
}