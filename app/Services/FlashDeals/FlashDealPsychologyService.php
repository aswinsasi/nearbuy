<?php

declare(strict_types=1);

namespace App\Services\FlashDeals;

use App\Models\FlashDeal;
use App\Models\FlashDealClaim;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Service implementing psychological triggers for maximum virality.
 *
 * PSYCHOLOGICAL TRIGGERS (Section 4.6):
 * - Urgency: countdown in every message
 * - FOMO: "Deal expires if X more don't join"
 * - Social Proof: "23 people already claimed"
 * - Sunk Cost: "You've claimed! Help activate!"
 * - Reciprocity: track who invited whom
 * - Victory: celebration message on activation
 *
 * @srs-ref Section 4.6 - Psychological Triggers
 * @module Flash Mob Deals - Advanced Features
 */
class FlashDealPsychologyService
{
    /**
     * Urgency thresholds in seconds.
     */
    protected const URGENCY_CRITICAL = 300;  // 5 minutes
    protected const URGENCY_HIGH = 900;      // 15 minutes
    protected const URGENCY_MEDIUM = 1800;   // 30 minutes

    /**
     * Social proof thresholds.
     */
    protected const SOCIAL_PROOF_LOW = 5;
    protected const SOCIAL_PROOF_MEDIUM = 15;
    protected const SOCIAL_PROOF_HIGH = 30;

    public function __construct(
        protected WhatsAppService $whatsApp
    ) {}

    // =========================================================================
    // URGENCY TRIGGERS
    // =========================================================================

    /**
     * Get urgency level for a deal.
     *
     * @srs-ref Section 4.6 - Urgency: countdown in every message
     */
    public function getUrgencyLevel(FlashDeal $deal): string
    {
        $seconds = $deal->time_remaining_seconds;

        if ($seconds <= 0) {
            return 'expired';
        }
        if ($seconds <= self::URGENCY_CRITICAL) {
            return 'critical';
        }
        if ($seconds <= self::URGENCY_HIGH) {
            return 'high';
        }
        if ($seconds <= self::URGENCY_MEDIUM) {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Get urgency message component.
     */
    public function getUrgencyMessage(FlashDeal $deal): string
    {
        $level = $this->getUrgencyLevel($deal);
        $time = $deal->time_remaining_display;

        return match ($level) {
            'critical' => "🚨🚨🚨 *ONLY {$time} LEFT!* 🚨🚨🚨\n_ഇനി {$time} മാത്രം!_",
            'high' => "⏰⏰ *{$time} remaining!* ⏰⏰\n_സമയം തീരുന്നു!_",
            'medium' => "⏰ *{$time} left*\n_സമയം ശ്രദ്ധിക്കുക_",
            'low' => "⏰ {$time} remaining",
            'expired' => "⏰ *Time's up!*",
        };
    }

    /**
     * Get urgency emoji based on time remaining.
     */
    public function getUrgencyEmoji(FlashDeal $deal): string
    {
        return match ($this->getUrgencyLevel($deal)) {
            'critical' => '🚨',
            'high' => '⏰',
            'medium' => '🕐',
            'low' => '⌛',
            'expired' => '💀',
        };
    }

    // =========================================================================
    // FOMO (FEAR OF MISSING OUT) TRIGGERS
    // =========================================================================

    /**
     * Get FOMO message component.
     *
     * @srs-ref Section 4.6 - FOMO: "Deal expires if X more don't join"
     */
    public function getFomoMessage(FlashDeal $deal): string
    {
        $remaining = $deal->claims_remaining;
        $progress = $deal->progress_percent;

        if ($progress >= 90) {
            return "🔥 *SO CLOSE!* Just *{$remaining}* more or deal expires!\n" .
                "_വെറും {$remaining} പേർ കൂടി ഇല്ലെങ്കിൽ ഡീൽ എക്സ്പയർ ആകും!_";
        }

        if ($progress >= 75) {
            return "⚠️ *Almost there!* {$remaining} more people needed!\n" .
                "_ഇനി {$remaining} പേർ മാത്രം!_";
        }

        if ($progress >= 50) {
            return "📢 *Halfway!* Need {$remaining} more to activate.\n" .
                "_ആക്ടിവേറ്റ് ചെയ്യാൻ {$remaining} പേർ കൂടി വേണം._";
        }

        return "👥 *{$remaining} more* people needed to unlock this deal.\n" .
            "_ഡീൽ അൺലോക്ക് ചെയ്യാൻ {$remaining} പേർ വേണം._";
    }

    /**
     * Get scarcity message (limited spots).
     */
    public function getScarcityMessage(FlashDeal $deal): string
    {
        $claimed = $deal->current_claims;
        $target = $deal->target_claims;
        $spotsLeft = max(0, $target - $claimed);

        if ($spotsLeft <= 5 && $spotsLeft > 0) {
            return "🔥 *Only {$spotsLeft} spots left!*";
        }

        if ($spotsLeft <= 10) {
            return "⚡ *{$spotsLeft} spots remaining!*";
        }

        return "👥 {$spotsLeft} spots available";
    }

    // =========================================================================
    // SOCIAL PROOF TRIGGERS
    // =========================================================================

    /**
     * Get social proof message.
     *
     * @srs-ref Section 4.6 - Social Proof: "23 people already claimed"
     */
    public function getSocialProofMessage(FlashDeal $deal): string
    {
        $claimed = $deal->current_claims;

        if ($claimed >= self::SOCIAL_PROOF_HIGH) {
            return "🎉 *{$claimed} people already claimed!*\n_Join the crowd!_";
        }

        if ($claimed >= self::SOCIAL_PROOF_MEDIUM) {
            return "👥 *{$claimed} people* have already joined!\n_{$claimed} പേർ ഇതിനകം ജോയിൻ ചെയ്തു!_";
        }

        if ($claimed >= self::SOCIAL_PROOF_LOW) {
            return "👥 {$claimed} people have claimed this deal";
        }

        if ($claimed > 0) {
            return "👥 First {$claimed} people already in!";
        }

        return "🚀 Be the first to claim!";
    }

    /**
     * Get trending indicator.
     */
    public function getTrendingIndicator(FlashDeal $deal): ?string
    {
        // Calculate claim velocity (claims per minute)
        $claims = $deal->claims()->orderBy('claimed_at', 'desc')->take(10)->get();

        if ($claims->count() < 3) {
            return null;
        }

        $firstClaim = $claims->last();
        $lastClaim = $claims->first();
        $duration = $firstClaim->claimed_at->diffInMinutes($lastClaim->claimed_at);

        if ($duration <= 0) {
            return "🔥 *TRENDING NOW!* Claims flooding in!";
        }

        $velocity = $claims->count() / $duration;

        if ($velocity >= 2) {
            return "🚀 *VIRAL!* {$claims->count()} claims in {$duration} min!";
        }

        if ($velocity >= 1) {
            return "🔥 *TRENDING!* Claims coming fast!";
        }

        if ($velocity >= 0.5) {
            return "📈 *Popular!* Steady claims";
        }

        return null;
    }

    /**
     * Get recent claimers message (anonymized).
     */
    public function getRecentClaimersMessage(FlashDeal $deal, int $limit = 3): string
    {
        $claims = $deal->claims()
            ->with('user')
            ->orderBy('claimed_at', 'desc')
            ->take($limit)
            ->get();

        if ($claims->isEmpty()) {
            return '';
        }

        $names = $claims->map(function ($claim) {
            $name = $claim->user->name ?? 'Someone';
            // Anonymize: "John D." or "S***a"
            $parts = explode(' ', $name);
            if (count($parts) > 1) {
                return $parts[0] . ' ' . substr($parts[1], 0, 1) . '.';
            }
            return substr($name, 0, 1) . '***' . substr($name, -1);
        });

        $namesStr = $names->implode(', ');

        return "🙋 *Recent:* {$namesStr} just claimed!";
    }

    // =========================================================================
    // SUNK COST TRIGGERS
    // =========================================================================

    /**
     * Get sunk cost message for existing claimants.
     *
     * @srs-ref Section 4.6 - Sunk Cost: "You've claimed! Help activate!"
     */
    public function getSunkCostMessage(FlashDeal $deal, FlashDealClaim $claim): string
    {
        $position = $claim->position;
        $remaining = $deal->claims_remaining;
        $progress = $deal->progress_percent;

        if ($progress >= 90) {
            return "🎯 You're #{$position}! Just {$remaining} more!\n" .
                "*Your effort is almost paying off!*\n" .
                "_നിങ്ങളുടെ ശ്രമം ഫലിക്കാറായി!_";
        }

        if ($progress >= 50) {
            return "✅ You claimed at #{$position}!\n" .
                "📊 Halfway there — *don't let your claim go to waste!*\n" .
                "_നിങ്ങളുടെ ക്ലെയിം വെറുതെ പോകരുത്!_";
        }

        return "✅ You're in at #{$position}!\n" .
            "📤 *Share to help activate your deal!*\n" .
            "_നിങ്ങളുടെ ഡീൽ ആക്ടിവേറ്റ് ചെയ്യാൻ ഷെയർ ചെയ്യൂ!_";
    }

    /**
     * Get "don't waste your claim" reminder.
     */
    public function getClaimWasteWarning(FlashDeal $deal): string
    {
        $remaining = $deal->claims_remaining;
        $time = $deal->time_remaining_display;

        return "⚠️ *Your claim needs backup!*\n\n" .
            "You've already claimed — but the deal only activates if we reach the target.\n\n" .
            "👥 *{$remaining} more* people needed\n" .
            "⏰ *{$time}* remaining\n\n" .
            "📤 *Share NOW or your claim expires worthless!*\n" .
            "_ഇപ്പോൾ ഷെയർ ചെയ്തില്ലെങ്കിൽ നിങ്ങളുടെ ക്ലെയിം വെറുതെ ആകും!_";
    }

    // =========================================================================
    // RECIPROCITY TRIGGERS
    // =========================================================================

    /**
     * Track referral relationship.
     *
     * @srs-ref Section 4.6 - Reciprocity: track who invited whom
     */
    public function trackReferral(FlashDealClaim $claim, int $referrerId): void
    {
        $claim->update([
            'referred_by_user_id' => $referrerId,
        ]);

        // Notify referrer
        $this->notifyReferrer($claim);

        Log::info('Referral tracked', [
            'claim_id' => $claim->id,
            'referrer_id' => $referrerId,
            'deal_id' => $claim->flash_deal_id,
        ]);
    }

    /**
     * Notify referrer that someone used their link.
     */
    protected function notifyReferrer(FlashDealClaim $claim): void
    {
        $referrer = User::find($claim->referred_by_user_id);
        if (!$referrer || !$referrer->phone) {
            return;
        }

        $deal = $claim->deal;
        $newUser = $claim->user;
        $newUserName = $newUser->name ?? 'Someone';

        // Count total referrals by this user
        $totalReferrals = FlashDealClaim::where('referred_by_user_id', $referrer->id)
            ->where('flash_deal_id', $deal->id)
            ->count();

        $message = "🎉 *{$newUserName} joined through your share!*\n\n" .
            "⚡ {$deal->title}\n" .
            "👥 You've brought in *{$totalReferrals} people*!\n\n" .
            "_Keep sharing to help activate!_ 📤";

        try {
            $this->whatsApp->sendText($referrer->phone, $message);
        } catch (\Exception $e) {
            Log::warning('Failed to notify referrer', [
                'referrer_id' => $referrer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get referral stats for a user on a deal.
     */
    public function getReferralStats(FlashDeal $deal, User $user): array
    {
        $referrals = FlashDealClaim::where('flash_deal_id', $deal->id)
            ->where('referred_by_user_id', $user->id)
            ->count();

        return [
            'total_referrals' => $referrals,
            'contribution_percent' => $deal->current_claims > 0
                ? round(($referrals / $deal->current_claims) * 100, 1)
                : 0,
            'is_top_referrer' => $this->isTopReferrer($deal, $user),
        ];
    }

    /**
     * Check if user is top referrer for a deal.
     */
    protected function isTopReferrer(FlashDeal $deal, User $user): bool
    {
        $topReferrer = FlashDealClaim::where('flash_deal_id', $deal->id)
            ->whereNotNull('referred_by_user_id')
            ->selectRaw('referred_by_user_id, COUNT(*) as count')
            ->groupBy('referred_by_user_id')
            ->orderByDesc('count')
            ->first();

        return $topReferrer && $topReferrer->referred_by_user_id === $user->id;
    }

    /**
     * Generate unique referral link for sharing.
     */
    public function generateReferralLink(FlashDeal $deal, User $user): string
    {
        $code = base64_encode("{$deal->id}:{$user->id}");
        return config('app.url') . "/flash/{$deal->id}?ref={$code}";
    }

    /**
     * Parse referral code from link.
     */
    public function parseReferralCode(string $code): ?array
    {
        try {
            $decoded = base64_decode($code);
            [$dealId, $userId] = explode(':', $decoded);
            return [
                'deal_id' => (int) $dealId,
                'user_id' => (int) $userId,
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    // =========================================================================
    // VICTORY CELEBRATION TRIGGERS
    // =========================================================================

    /**
     * Generate victory celebration message.
     *
     * @srs-ref Section 4.6 - Victory: celebration message on activation
     */
    public function getVictoryCelebration(FlashDeal $deal, FlashDealClaim $claim): string
    {
        $position = $claim->position;
        $total = $deal->current_claims;

        // Special positions get special messages
        if ($position === 1) {
            $specialMsg = "🥇 *FIRST TO CLAIM!* Pioneer bonus respect!";
        } elseif ($position <= 3) {
            $specialMsg = "🏆 *Early bird #{$position}!* You helped start this!";
        } elseif ($position === $total) {
            $specialMsg = "🎯 *YOU COMPLETED IT!* The final piece!";
        } else {
            $specialMsg = "✨ *Claimer #{$position}!* Part of the winning team!";
        }

        return "🎉🎉🎉 *WE DID IT!* 🎉🎉🎉\n" .
            "*നമ്മൾ ചെയ്തു!*\n\n" .
            "{$specialMsg}\n\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "⚡ *{$deal->title}*\n" .
            "💰 *{$deal->discount_percent}% OFF* — ACTIVATED!\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "👥 *{$total} people* made this happen!\n" .
            "{$total} പേർ ഒരുമിച്ച് ഇത് സാധ്യമാക്കി!\n\n" .
            "🎫 *Your coupon:* {$claim->coupon_code}";
    }

    /**
     * Get leaderboard for top referrers.
     */
    public function getReferralLeaderboard(FlashDeal $deal, int $limit = 5): array
    {
        return FlashDealClaim::where('flash_deal_id', $deal->id)
            ->whereNotNull('referred_by_user_id')
            ->selectRaw('referred_by_user_id, COUNT(*) as referral_count')
            ->groupBy('referred_by_user_id')
            ->orderByDesc('referral_count')
            ->take($limit)
            ->with('referrer:id,name')
            ->get()
            ->map(function ($item, $index) {
                return [
                    'rank' => $index + 1,
                    'user_id' => $item->referred_by_user_id,
                    'name' => $item->referrer->name ?? 'Unknown',
                    'referrals' => $item->referral_count,
                    'emoji' => match ($index) {
                        0 => '🥇',
                        1 => '🥈',
                        2 => '🥉',
                        default => '🏅',
                    },
                ];
            })
            ->toArray();
    }

    // =========================================================================
    // COMBINED MESSAGE BUILDER
    // =========================================================================

    /**
     * Build a psychologically optimized message for a deal.
     */
    public function buildOptimizedMessage(FlashDeal $deal, ?FlashDealClaim $claim = null): string
    {
        $components = [];

        // Add urgency if time is running out
        if ($deal->time_remaining_seconds <= self::URGENCY_HIGH) {
            $components[] = $this->getUrgencyMessage($deal);
        }

        // Add social proof
        if ($deal->current_claims >= self::SOCIAL_PROOF_LOW) {
            $components[] = $this->getSocialProofMessage($deal);
        }

        // Add FOMO
        $components[] = $this->getFomoMessage($deal);

        // Add sunk cost if user has claimed
        if ($claim) {
            $components[] = $this->getSunkCostMessage($deal, $claim);
        }

        // Add trending indicator if applicable
        $trending = $this->getTrendingIndicator($deal);
        if ($trending) {
            $components[] = $trending;
        }

        return implode("\n\n", $components);
    }

    /**
     * Get all psychological metrics for a deal.
     */
    public function getMetrics(FlashDeal $deal): array
    {
        return [
            'urgency_level' => $this->getUrgencyLevel($deal),
            'social_proof_level' => $this->getSocialProofLevel($deal),
            'fomo_intensity' => $this->getFomoIntensity($deal),
            'trending' => $this->getTrendingIndicator($deal) !== null,
            'virality_score' => $this->calculateViralityScore($deal),
        ];
    }

    /**
     * Get social proof level.
     */
    protected function getSocialProofLevel(FlashDeal $deal): string
    {
        $claims = $deal->current_claims;

        if ($claims >= self::SOCIAL_PROOF_HIGH) {
            return 'high';
        }
        if ($claims >= self::SOCIAL_PROOF_MEDIUM) {
            return 'medium';
        }
        if ($claims >= self::SOCIAL_PROOF_LOW) {
            return 'low';
        }

        return 'none';
    }

    /**
     * Get FOMO intensity level.
     */
    protected function getFomoIntensity(FlashDeal $deal): string
    {
        $progress = $deal->progress_percent;
        $timeLevel = $this->getUrgencyLevel($deal);

        if ($progress >= 90 || $timeLevel === 'critical') {
            return 'extreme';
        }
        if ($progress >= 75 || $timeLevel === 'high') {
            return 'high';
        }
        if ($progress >= 50 || $timeLevel === 'medium') {
            return 'medium';
        }

        return 'low';
    }

    /**
     * Calculate overall virality score.
     */
    protected function calculateViralityScore(FlashDeal $deal): int
    {
        $score = 0;

        // Urgency contributes
        $score += match ($this->getUrgencyLevel($deal)) {
            'critical' => 30,
            'high' => 20,
            'medium' => 10,
            default => 0,
        };

        // Social proof contributes
        $score += match ($this->getSocialProofLevel($deal)) {
            'high' => 25,
            'medium' => 15,
            'low' => 5,
            default => 0,
        };

        // Progress contributes
        $score += (int) ($deal->progress_percent * 0.3);

        // Trending bonus
        if ($this->getTrendingIndicator($deal)) {
            $score += 15;
        }

        return min(100, $score);
    }
}