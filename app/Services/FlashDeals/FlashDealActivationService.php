<?php

declare(strict_types=1);

namespace App\Services\FlashDeals;

use App\Enums\FlashDealStatus;
use App\Models\FlashDeal;
use App\Models\FlashDealClaim;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for Flash Deal activation when target is reached.
 *
 * 🎉🎉🎉 THE MOMENT OF VICTORY! 🎉🎉🎉
 * When enough people claim, everyone wins!
 *
 * @srs-ref FD-019 to FD-024 - Activation Requirements
 * @module Flash Mob Deals
 */
class FlashDealActivationService
{
    public function __construct(
        protected WhatsAppService $whatsApp
    ) {}

    /**
     * Check if deal should be activated and activate if so.
     *
     * @srs-ref FD-019 - When target reached → ACTIVATED immediately
     */
    public function checkAndActivate(FlashDeal $deal): bool
    {
        // Already activated or not live
        if ($deal->status !== FlashDealStatus::LIVE) {
            return false;
        }

        // Check if target reached
        if ($deal->current_claims < $deal->target_claims) {
            return false;
        }

        // Activate the deal!
        return $this->activateDeal($deal);
    }

    /**
     * Activate a deal (target has been reached).
     *
     * @srs-ref FD-019 - Immediately mark as ACTIVATED
     * @srs-ref FD-020 - Generate unique coupon for each claimant
     * @srs-ref FD-021 - Send celebration to ALL claimants
     * @srs-ref FD-023 - Notify shop owner
     */
    public function activateDeal(FlashDeal $deal): bool
    {
        return DB::transaction(function () use ($deal) {
            // Update deal status
            $deal->update([
                'status' => FlashDealStatus::ACTIVATED,
                'activated_at' => now(),
            ]);

            // Generate coupons and send celebrations to all claimants
            $claims = $deal->claims()->with('user')->get();

            foreach ($claims as $claim) {
                // Generate unique coupon code
                $claim->generateCouponCode();

                // Send celebration message
                $this->sendCelebrationMessage($deal, $claim);
            }

            // Notify shop owner
            $this->notifyShopOwnerOfActivation($deal, $claims->count());

            Log::info('Flash deal activated', [
                'deal_id' => $deal->id,
                'title' => $deal->title,
                'claims' => $claims->count(),
                'target' => $deal->target_claims,
            ]);

            return true;
        });
    }

    /**
     * Send celebration message to claimant with coupon.
     *
     * @srs-ref FD-021 - Celebration message to ALL claimants
     * @srs-ref FD-022 - Include code, shop address, validity, Get Directions
     */
    protected function sendCelebrationMessage(FlashDeal $deal, FlashDealClaim $claim): void
    {
        $user = $claim->user;
        if (!$user || !$user->phone) {
            return;
        }

        $shop = $deal->shop;
        $validUntil = $deal->coupon_valid_until
            ? $deal->coupon_valid_until->format('M d, h:i A')
            : 'Store closing today';

        // Build celebration message
        $message = "🎉🎉🎉 *DEAL ACTIVATED!* 🎉🎉🎉\n" .
            "*ഡീൽ ആക്ടിവേറ്റ് ആയി!*\n\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "⚡ *{$deal->title}*\n" .
            "💰 *{$deal->discount_percent}% OFF*" .
            ($deal->max_discount_value ? " (max ₹{$deal->max_discount_value})" : '') . "\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "🎫 *Your Coupon Code:*\n" .
            "┏━━━━━━━━━━━━━━━━━┓\n" .
            "┃   *{$claim->coupon_code}*   ┃\n" .
            "┗━━━━━━━━━━━━━━━━━┛\n\n" .
            "🏪 *Redeem at:*\n" .
            "*{$shop->shop_name}*\n" .
            "📍 {$shop->address}\n\n" .
            "⏰ *Valid until:* {$validUntil}\n\n" .
            "📱 _Show this code at the shop!_\n" .
            "_ഷോപ്പിൽ ഈ കോഡ് കാണിക്കുക!_";

        try {
            // Send the celebration message with buttons
            $this->whatsApp->sendButtons(
                $user->phone,
                $message,
                [
                    ['id' => 'flash_directions_' . $deal->id, 'title' => '📍 Get Directions'],
                    ['id' => 'flash_share_victory_' . $deal->id, 'title' => '📤 Share Victory!'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '🎉 COUPON UNLOCKED!'
            );

        } catch (\Exception $e) {
            Log::warning('Failed to send activation celebration', [
                'deal_id' => $deal->id,
                'claim_id' => $claim->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify shop owner of successful activation.
     *
     * @srs-ref FD-023 - Notify shop owner with total claims + expected footfall
     */
    protected function notifyShopOwnerOfActivation(FlashDeal $deal, int $totalClaims): void
    {
        $shop = $deal->shop;
        $owner = $shop->user;

        if (!$owner || !$owner->phone) {
            return;
        }

        // Calculate analytics
        $analytics = $this->calculateActivationAnalytics($deal);

        $message = "🎉🎉🎉 *FLASH DEAL ACTIVATED!* 🎉🎉🎉\n" .
            "*ഫ്ലാഷ് ഡീൽ ആക്ടിവേറ്റ് ആയി!*\n\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "⚡ *{$deal->title}*\n" .
            "💰 {$deal->discount_percent}% OFF\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "✅ *Target reached!*\n" .
            "👥 Total claims: *{$totalClaims} people*\n" .
            "📊 Expected footfall: *{$totalClaims} customers*\n\n" .
            "📈 *Stats:*\n" .
            "• Peak time: {$analytics['peak_time']}\n" .
            "• Avg claim speed: {$analytics['avg_speed']}\n" .
            "• Notified: {$deal->notified_customers_count} customers\n" .
            "• Conversion: {$analytics['conversion_rate']}%\n\n" .
            "📝 *Coupon codes start with:*\n" .
            "*{$deal->coupon_prefix}-XXXXXX*\n\n" .
            "⏰ Valid until: {$deal->coupon_valid_until->format('M d, h:i A')}\n\n" .
            "🏃 _Get ready for the rush!_\n" .
            "_കസ്റ്റമേഴ്സിനെ സ്വാഗതം ചെയ്യാൻ തയ്യാറാകൂ!_";

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
                'shop_id' => $shop->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculate activation analytics.
     */
    protected function calculateActivationAnalytics(FlashDeal $deal): array
    {
        $claims = $deal->claims()->orderBy('claimed_at')->get();

        // Peak time (hour with most claims)
        $peakHour = $claims->groupBy(fn($c) => $c->claimed_at->format('H'))
            ->map->count()
            ->sortDesc()
            ->keys()
            ->first();
        $peakTime = $peakHour ? Carbon::createFromFormat('H', $peakHour)->format('g A') : 'N/A';

        // Average speed (claims per minute)
        if ($claims->count() > 1) {
            $duration = $claims->first()->claimed_at->diffInMinutes($claims->last()->claimed_at);
            $avgSpeed = $duration > 0
                ? round($claims->count() / $duration, 1) . '/min'
                : 'Instant! ⚡';
        } else {
            $avgSpeed = 'N/A';
        }

        // Conversion rate
        $conversionRate = $deal->notified_customers_count > 0
            ? round(($deal->current_claims / $deal->notified_customers_count) * 100, 1)
            : 0;

        return [
            'peak_time' => $peakTime,
            'avg_speed' => $avgSpeed,
            'conversion_rate' => $conversionRate,
        ];
    }

    /**
     * Generate coupons for claims after activation.
     *
     * @srs-ref FD-024 - Continue accepting claims after activation
     */
    public function generateCouponForNewClaim(FlashDealClaim $claim): void
    {
        $deal = $claim->deal;

        // Only generate if deal is activated
        if ($deal->status !== FlashDealStatus::ACTIVATED) {
            return;
        }

        // Generate coupon
        $claim->generateCouponCode();

        // Send coupon to user
        $this->sendCouponToNewClaimer($deal, $claim);
    }

    /**
     * Send coupon to a new claimer (after activation).
     */
    protected function sendCouponToNewClaimer(FlashDeal $deal, FlashDealClaim $claim): void
    {
        $user = $claim->user;
        if (!$user || !$user->phone) {
            return;
        }

        $shop = $deal->shop;
        $validUntil = $deal->coupon_valid_until
            ? $deal->coupon_valid_until->format('M d, h:i A')
            : 'Store closing today';

        $message = "✅ *Claimed!* You're {$claim->position_display}! 🎉\n" .
            "*ക്ലെയിം ചെയ്തു!*\n\n" .
            "This deal is already *ACTIVATED*!\n" .
            "Here's your coupon:\n\n" .
            "🎫 *{$claim->coupon_code}*\n\n" .
            "🏪 {$shop->shop_name}\n" .
            "⏰ Valid until: {$validUntil}";

        try {
            $this->whatsApp->sendButtons(
                $user->phone,
                $message,
                [
                    ['id' => 'flash_directions_' . $deal->id, 'title' => '📍 Get Directions'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '🎫 Coupon Ready!'
            );

        } catch (\Exception $e) {
            Log::warning('Failed to send coupon to new claimer', [
                'deal_id' => $deal->id,
                'claim_id' => $claim->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send directions to shop.
     */
    public function sendDirections(FlashDeal $deal, User $user): void
    {
        $shop = $deal->shop;

        // Send location
        $this->whatsApp->sendLocation(
            $user->phone,
            $shop->latitude,
            $shop->longitude,
            $shop->shop_name,
            $shop->address
        );

        // Follow up with reminder
        $claim = $deal->getUserClaim($user->id);
        if ($claim && $claim->coupon_code) {
            $this->whatsApp->sendText(
                $user->phone,
                "📍 *{$shop->shop_name}*\n\n" .
                "Don't forget your coupon:\n" .
                "🎫 *{$claim->coupon_code}*\n\n" .
                "_Show this code to get {$deal->discount_percent}% off!_"
            );
        }
    }

    /**
     * Verify and redeem a coupon code.
     */
    public function redeemCoupon(string $couponCode, int $shopId): array
    {
        $claim = FlashDealClaim::findByCouponCode($couponCode);

        if (!$claim) {
            return [
                'valid' => false,
                'error' => 'invalid_code',
                'message' => 'Invalid coupon code.',
            ];
        }

        $deal = $claim->deal;

        // Verify shop
        if ($deal->shop_id !== $shopId) {
            return [
                'valid' => false,
                'error' => 'wrong_shop',
                'message' => 'This coupon is for a different shop.',
            ];
        }

        // Check if already redeemed
        if ($claim->coupon_redeemed) {
            return [
                'valid' => false,
                'error' => 'already_redeemed',
                'message' => 'This coupon has already been used.',
                'redeemed_at' => $claim->redeemed_at->format('M d, h:i A'),
            ];
        }

        // Check validity period
        if ($deal->coupon_valid_until && $deal->coupon_valid_until->isPast()) {
            return [
                'valid' => false,
                'error' => 'expired',
                'message' => 'This coupon has expired.',
            ];
        }

        // Mark as redeemed
        $claim->markRedeemed();

        return [
            'valid' => true,
            'deal' => $deal,
            'claim' => $claim,
            'discount_percent' => $deal->discount_percent,
            'max_discount' => $deal->max_discount_value,
            'customer_name' => $claim->user->name,
        ];
    }

    /**
     * Get activation stats for a deal.
     */
    public function getActivationStats(FlashDeal $deal): array
    {
        $claims = $deal->claims()->with('user')->get();
        $redeemed = $claims->where('coupon_redeemed', true)->count();

        return [
            'total_claims' => $claims->count(),
            'coupons_generated' => $claims->whereNotNull('coupon_code')->count(),
            'coupons_redeemed' => $redeemed,
            'redemption_rate' => $claims->count() > 0
                ? round(($redeemed / $claims->count()) * 100, 1)
                : 0,
            'is_activated' => $deal->status === FlashDealStatus::ACTIVATED,
            'activated_at' => $deal->activated_at,
        ];
    }
}