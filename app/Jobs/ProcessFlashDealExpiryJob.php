<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\FlashDealStatus;
use App\Models\FlashDeal;
use App\Models\FlashDealClaim;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Job to process flash deal expiry when time runs out.
 *
 * Handles the sad path: target not reached before time expired.
 * Notifies all claimants and sends analytics to shop owner.
 *
 * @srs-ref FD-025 to FD-028 - Expiry Requirements
 * @module Flash Mob Deals
 *
 * @schedule Run every minute: $schedule->job(ProcessFlashDealExpiryJob::class)->everyMinute();
 */
class ProcessFlashDealExpiryJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'process_flash_deal_expiry';
    }

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 60;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        $this->onQueue('flash-deals');
    }

    /**
     * Execute the job.
     */
    public function handle(WhatsAppService $whatsApp): void
    {
        Log::info('Processing flash deal expiry check...');

        // Find all deals that need to be expired
        $expiredDeals = FlashDeal::query()
            ->where('status', FlashDealStatus::LIVE)
            ->where('expires_at', '<=', now())
            ->with(['shop', 'shop.user', 'claims', 'claims.user'])
            ->get();

        if ($expiredDeals->isEmpty()) {
            Log::debug('No flash deals to expire');
            return;
        }

        Log::info("Found {$expiredDeals->count()} deal(s) to expire");

        foreach ($expiredDeals as $deal) {
            try {
                $this->processExpiredDeal($deal, $whatsApp);
            } catch (\Exception $e) {
                Log::error('Failed to process expired deal', [
                    'deal_id' => $deal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process a single expired deal.
     *
     * @srs-ref FD-025 - Time expired without target → EXPIRED
     */
    protected function processExpiredDeal(FlashDeal $deal, WhatsAppService $whatsApp): void
    {
        DB::transaction(function () use ($deal, $whatsApp) {
            // Check if target was reached at the last second
            if ($deal->current_claims >= $deal->target_claims) {
                // Target reached! Activate instead of expire
                $this->activateLastSecond($deal, $whatsApp);
                return;
            }

            // Mark deal as expired
            $deal->update([
                'status' => FlashDealStatus::EXPIRED,
                'expired_at' => now(),
            ]);

            // Notify all claimants
            $this->notifyClaimants($deal, $whatsApp);

            // Send analytics to shop owner
            $this->sendShopAnalytics($deal, $whatsApp);

            Log::info('Flash deal expired', [
                'deal_id' => $deal->id,
                'title' => $deal->title,
                'claims' => $deal->current_claims,
                'target' => $deal->target_claims,
                'shortfall' => $deal->target_claims - $deal->current_claims,
            ]);
        });
    }

    /**
     * Handle last-second activation (target reached at expiry time).
     */
    protected function activateLastSecond(FlashDeal $deal, WhatsAppService $whatsApp): void
    {
        // Dispatch activation job instead
        Log::info('Last-second activation for deal', ['deal_id' => $deal->id]);

        // Update status and generate coupons
        $deal->update([
            'status' => FlashDealStatus::ACTIVATED,
            'activated_at' => now(),
        ]);

        // Generate coupons for all claims
        foreach ($deal->claims as $claim) {
            $claim->generateCouponCode();
            $this->sendActivationMessage($deal, $claim, $whatsApp);
        }

        // Notify shop owner
        $this->sendActivationToShopOwner($deal, $whatsApp);
    }

    /**
     * Send activation message for last-second activations.
     */
    protected function sendActivationMessage(FlashDeal $deal, FlashDealClaim $claim, WhatsAppService $whatsApp): void
    {
        $user = $claim->user;
        if (!$user || !$user->phone) {
            return;
        }

        $shop = $deal->shop;
        $validUntil = $deal->coupon_valid_until
            ? $deal->coupon_valid_until->format('M d, h:i A')
            : 'Today';

        $message = "🎉🎉🎉 *DEAL ACTIVATED!* 🎉🎉🎉\n" .
            "*ഡീൽ ആക്ടിവേറ്റ് ആയി!*\n\n" .
            "⚡ *{$deal->title}*\n" .
            "💰 {$deal->discount_percent}% OFF!\n\n" .
            "🎫 *Your Coupon:*\n" .
            "*{$claim->coupon_code}*\n\n" .
            "🏪 {$shop->shop_name}\n" .
            "⏰ Valid till: {$validUntil}";

        try {
            $whatsApp->sendButtons(
                $user->phone,
                $message,
                [
                    ['id' => 'flash_directions_' . $deal->id, 'title' => '📍 Get Directions'],
                    ['id' => 'flash_share_' . $deal->id, 'title' => '📤 Share Victory!'],
                ],
                '🎉 COUPON UNLOCKED!'
            );
        } catch (\Exception $e) {
            Log::warning('Failed to send last-second activation', [
                'deal_id' => $deal->id,
                'claim_id' => $claim->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send activation notification to shop owner.
     */
    protected function sendActivationToShopOwner(FlashDeal $deal, WhatsAppService $whatsApp): void
    {
        $owner = $deal->shop->user;
        if (!$owner || !$owner->phone) {
            return;
        }

        $message = "🎉 *Flash Deal Activated!*\n" .
            "*ഫ്ലാഷ് ഡീൽ ആക്ടിവേറ്റ് ആയി!*\n\n" .
            "⚡ {$deal->title}\n" .
            "👥 {$deal->current_claims} people claimed!\n\n" .
            "_Get ready for customers!_";

        try {
            $whatsApp->sendButtons(
                $owner->phone,
                $message,
                [
                    ['id' => 'view_claims_' . $deal->id, 'title' => '📋 View Claims'],
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
     * Notify all claimants about expiry.
     *
     * @srs-ref FD-026 - Notify claimants with final count vs target
     * @srs-ref FD-027 - Include "Follow Shop" option
     */
    protected function notifyClaimants(FlashDeal $deal, WhatsAppService $whatsApp): void
    {
        $shop = $deal->shop;
        $shortfall = $deal->target_claims - $deal->current_claims;

        foreach ($deal->claims as $claim) {
            $user = $claim->user;
            if (!$user || !$user->phone) {
                continue;
            }

            $message = "😕 *Flash Deal Expired*\n" .
                "*ഫ്ലാഷ് ഡീൽ കാലഹരണപ്പെട്ടു*\n\n" .
                "━━━━━━━━━━━━━━━━━━\n" .
                "⚡ *{$deal->title}*\n" .
                "🏪 {$shop->shop_name}\n" .
                "━━━━━━━━━━━━━━━━━━\n\n" .
                "📊 *Final Count:*\n" .
                "🎯 {$deal->current_claims}/{$deal->target_claims} claimed\n" .
                "{$deal->progress_bar}\n\n" .
                "❌ *{$shortfall} more were needed*\n" .
                "*{$shortfall} പേർ കൂടി വേണമായിരുന്നു*\n\n" .
                "_Better luck next time!_\n" .
                "_അടുത്ത തവണ ഭാഗ്യം!_\n\n" .
                "🔔 Follow *{$shop->shop_name}* to get notified of future deals!";

            try {
                $whatsApp->sendButtons(
                    $user->phone,
                    $message,
                    [
                        ['id' => 'follow_shop_' . $shop->id, 'title' => "🔔 Follow {$shop->shop_name}"],
                        ['id' => 'browse_flash_deals', 'title' => '⚡ Other Deals'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                    '⏰ Deal Expired'
                );

            } catch (\Exception $e) {
                Log::warning('Failed to notify claimant of expiry', [
                    'deal_id' => $deal->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Send analytics report to shop owner.
     *
     * @srs-ref FD-028 - Send analytics: total claims, peak time, suggestions
     */
    protected function sendShopAnalytics(FlashDeal $deal, WhatsAppService $whatsApp): void
    {
        $owner = $deal->shop->user;
        if (!$owner || !$owner->phone) {
            return;
        }

        // Calculate analytics
        $analytics = $this->calculateAnalytics($deal);
        $suggestions = $this->generateSuggestions($deal, $analytics);

        $message = "📊 *Flash Deal Report*\n" .
            "*ഫ്ലാഷ് ഡീൽ റിപ്പോർട്ട്*\n\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "⚡ *{$deal->title}*\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "📈 *Results:*\n" .
            "• Claims: *{$deal->current_claims}/{$deal->target_claims}* ({$analytics['completion_percent']}%)\n" .
            "• Shortfall: *{$analytics['shortfall']}* people\n" .
            "• Notified: {$deal->notified_customers_count} customers\n" .
            "• Conversion: {$analytics['conversion_rate']}%\n\n" .
            "⏰ *Timing:*\n" .
            "• Peak time: {$analytics['peak_time']}\n" .
            "• Duration: {$deal->time_limit_minutes} mins\n" .
            "• Avg speed: {$analytics['avg_speed']}\n\n" .
            "💡 *Suggestions for next time:*\n" .
            "{$suggestions}\n\n" .
            "🚀 _Don't give up! Adjust and try again!_\n" .
            "_ഹാർ മാനേണ്ട! സെറ്റിംഗ്‌സ് മാറ്റി വീണ്ടും ശ്രമിക്കൂ!_";

        try {
            $whatsApp->sendButtons(
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
            Log::warning('Failed to send analytics to shop owner', [
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Calculate deal analytics.
     */
    protected function calculateAnalytics(FlashDeal $deal): array
    {
        $claims = $deal->claims()->orderBy('claimed_at')->get();

        // Completion percentage
        $completionPercent = $deal->target_claims > 0
            ? round(($deal->current_claims / $deal->target_claims) * 100, 1)
            : 0;

        // Shortfall
        $shortfall = $deal->target_claims - $deal->current_claims;

        // Conversion rate (claims / notifications)
        $conversionRate = $deal->notified_customers_count > 0
            ? round(($deal->current_claims / $deal->notified_customers_count) * 100, 1)
            : 0;

        // Peak time (hour with most claims)
        $peakTime = 'N/A';
        if ($claims->isNotEmpty()) {
            $peakHour = $claims->groupBy(fn($c) => $c->claimed_at->format('H'))
                ->map->count()
                ->sortDesc()
                ->keys()
                ->first();
            if ($peakHour !== null) {
                $peakTime = Carbon::createFromFormat('H', $peakHour)->format('g A');
            }
        }

        // Average claim speed
        $avgSpeed = 'N/A';
        if ($claims->count() > 1) {
            $firstClaim = $claims->first();
            $lastClaim = $claims->last();
            $duration = $firstClaim->claimed_at->diffInMinutes($lastClaim->claimed_at);

            if ($duration > 0) {
                $claimsPerMin = round($claims->count() / $duration, 2);
                $avgSpeed = "{$claimsPerMin}/min";
            } else {
                $avgSpeed = 'Burst! ⚡';
            }
        }

        // Claim timeline (when did claims come in)
        $claimTimeline = [];
        if ($claims->isNotEmpty()) {
            $startTime = $deal->starts_at;
            $quarterDuration = $deal->time_limit_minutes / 4;

            $q1 = $claims->filter(fn($c) => $c->claimed_at->diffInMinutes($startTime) <= $quarterDuration)->count();
            $q2 = $claims->filter(fn($c) =>
                $c->claimed_at->diffInMinutes($startTime) > $quarterDuration &&
                $c->claimed_at->diffInMinutes($startTime) <= ($quarterDuration * 2)
            )->count();
            $q3 = $claims->filter(fn($c) =>
                $c->claimed_at->diffInMinutes($startTime) > ($quarterDuration * 2) &&
                $c->claimed_at->diffInMinutes($startTime) <= ($quarterDuration * 3)
            )->count();
            $q4 = $claims->filter(fn($c) =>
                $c->claimed_at->diffInMinutes($startTime) > ($quarterDuration * 3)
            )->count();

            $claimTimeline = [
                'q1' => $q1,
                'q2' => $q2,
                'q3' => $q3,
                'q4' => $q4,
            ];
        }

        // Referral percentage
        $referralClaims = $claims->whereNotNull('referred_by_user_id')->count();
        $referralPercent = $claims->count() > 0
            ? round(($referralClaims / $claims->count()) * 100, 1)
            : 0;

        return [
            'completion_percent' => $completionPercent,
            'shortfall' => $shortfall,
            'conversion_rate' => $conversionRate,
            'peak_time' => $peakTime,
            'avg_speed' => $avgSpeed,
            'claim_timeline' => $claimTimeline,
            'referral_percent' => $referralPercent,
        ];
    }

    /**
     * Generate improvement suggestions based on analytics.
     */
    protected function generateSuggestions(FlashDeal $deal, array $analytics): string
    {
        $suggestions = [];

        // Target too high?
        if ($analytics['completion_percent'] < 50 && $deal->target_claims > 20) {
            $suggestedTarget = max(10, (int) ($deal->target_claims * 0.6));
            $suggestions[] = "• Try a lower target ({$suggestedTarget} people)";
        }

        // Time too short?
        if ($analytics['completion_percent'] > 70 && $deal->time_limit_minutes <= 30) {
            $suggestions[] = "• Extend time to 1 hour (you were close!)";
        }

        // Low conversion = need better offer?
        if ($analytics['conversion_rate'] < 5 && $deal->discount_percent < 30) {
            $suggestions[] = "• Higher discount (40%+) attracts more";
        }

        // Claims dropped off?
        if (!empty($analytics['claim_timeline'])) {
            $timeline = $analytics['claim_timeline'];
            if (($timeline['q1'] ?? 0) > ($timeline['q2'] ?? 0) * 2) {
                $suggestions[] = "• More sharing reminders mid-deal";
            }
        }

        // Low referrals?
        if ($analytics['referral_percent'] < 20) {
            $suggestions[] = "• Encourage sharing with bonus discounts";
        }

        // Timing might be off
        if ($deal->starts_at->isWeekday() && $deal->starts_at->hour < 17) {
            $suggestions[] = "• Try evening (6-8 PM) or weekend launch";
        }

        // If we have few suggestions, add generic ones
        if (count($suggestions) < 2) {
            $suggestions[] = "• Share deal in local WhatsApp groups";
            $suggestions[] = "• Create urgency with shorter time";
        }

        return implode("\n", array_slice($suggestions, 0, 4));
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Flash deal expiry job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}