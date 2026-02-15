<?php

declare(strict_types=1);

namespace App\Services\FlashDeals;

use App\Enums\FlashDealStatus;
use App\Models\FlashDeal;
use App\Models\FlashDealClaim;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for Chain Deals — Multi-tier progressive unlock discounts.
 *
 * PSYCHOLOGICAL TRIGGER: Gamification + Social Proof + FOMO
 * As more people claim, the discount INCREASES for everyone!
 * Level 1 (20 people) = 20% → Level 2 (35 people) = 35% → Level 3 (50 people) = 50%
 *
 * @srs-ref Section 4.5.2 - Chain Deals (Progressive Unlocks)
 * @srs-ref Section 4.6 - Psychological Triggers (Social Proof, FOMO)
 * @module Flash Mob Deals - Advanced Features
 */
class FlashDealChainService
{
    /**
     * Cache prefix for level unlock notifications.
     */
    protected const LEVEL_NOTIFY_CACHE_PREFIX = 'chain_level_notified_';

    public function __construct(
        protected WhatsAppService $whatsApp
    ) {}

    /**
     * Default chain deal tiers.
     *
     * @srs-ref Section 4.5.2 - Multi-tier discounts
     */
    public static function getDefaultTiers(): array
    {
        return [
            1 => ['claims' => 20, 'discount' => 20, 'emoji' => '🥉', 'name' => 'Bronze'],
            2 => ['claims' => 35, 'discount' => 35, 'emoji' => '🥈', 'name' => 'Silver'],
            3 => ['claims' => 50, 'discount' => 50, 'emoji' => '🥇', 'name' => 'Gold'],
        ];
    }

    /**
     * Create a chain deal with progressive tiers.
     */
    public function createChainDeal(array $dealData, array $tiers = null): FlashDeal
    {
        $tiers = $tiers ?? self::getDefaultTiers();

        // Use highest tier as the final target
        $maxTier = max(array_keys($tiers));
        $dealData['target_claims'] = $tiers[$maxTier]['claims'];
        $dealData['discount_percent'] = $tiers[1]['discount']; // Start with Level 1 discount
        $dealData['is_chain_deal'] = true;
        $dealData['chain_tiers'] = json_encode($tiers);
        $dealData['current_chain_level'] = 0; // Not yet reached Level 1

        return FlashDeal::create($dealData);
    }

    /**
     * Check and process chain deal level unlocks.
     */
    public function checkAndProcessLevelUnlock(FlashDeal $deal): ?int
    {
        if (!$this->isChainDeal($deal)) {
            return null;
        }

        $tiers = $this->getTiers($deal);
        $currentLevel = $deal->current_chain_level ?? 0;
        $currentClaims = $deal->current_claims;

        // Check each tier
        foreach ($tiers as $level => $tier) {
            // Skip already unlocked levels
            if ($level <= $currentLevel) {
                continue;
            }

            // Check if we've reached this level
            if ($currentClaims >= $tier['claims']) {
                $this->unlockLevel($deal, $level, $tier);
                return $level;
            }
        }

        return null;
    }

    /**
     * Unlock a new level.
     *
     * @srs-ref Section 4.5.2 - When Level hits: notify all claimants
     */
    protected function unlockLevel(FlashDeal $deal, int $level, array $tier): void
    {
        // Check if already notified for this level
        $cacheKey = self::LEVEL_NOTIFY_CACHE_PREFIX . $deal->id . '_' . $level;
        if (Cache::has($cacheKey)) {
            return;
        }

        DB::transaction(function () use ($deal, $level, $tier) {
            // Update deal with new discount level
            $deal->update([
                'discount_percent' => $tier['discount'],
                'current_chain_level' => $level,
            ]);

            // Get next tier info
            $tiers = $this->getTiers($deal);
            $nextTier = $tiers[$level + 1] ?? null;

            // Notify all claimants
            $this->notifyClaimantsOfLevelUnlock($deal, $level, $tier, $nextTier);

            // Notify shop owner
            $this->notifyOwnerOfLevelUnlock($deal, $level, $tier);
        });

        // Mark as notified
        Cache::put($cacheKey, true, 86400); // 24 hours

        Log::info('Chain deal level unlocked', [
            'deal_id' => $deal->id,
            'level' => $level,
            'new_discount' => $tier['discount'],
            'claims' => $deal->current_claims,
        ]);
    }

    /**
     * Notify all claimants of level unlock.
     *
     * @srs-ref Section 4.5.2 - "🎉 Level 1 UNLOCKED! 20% off!
     *                          But keep sharing — 35% at 35 people! 🔥"
     */
    protected function notifyClaimantsOfLevelUnlock(
        FlashDeal $deal,
        int $level,
        array $tier,
        ?array $nextTier
    ): void {
        $claims = $deal->claims()->with('user')->get();

        foreach ($claims as $claim) {
            $user = $claim->user;
            if (!$user || !$user->phone) {
                continue;
            }

            $message = $this->buildLevelUnlockMessage($deal, $level, $tier, $nextTier);

            try {
                $buttons = [
                    ['id' => 'flash_share_' . $deal->id, 'title' => '📤 Share for More!'],
                    ['id' => 'flash_progress_' . $deal->id, 'title' => '📊 Progress'],
                ];

                $this->whatsApp->sendButtons(
                    $user->phone,
                    $message,
                    $buttons,
                    "{$tier['emoji']} Level {$level} Unlocked!"
                );

            } catch (\Exception $e) {
                Log::warning('Failed to notify claimant of level unlock', [
                    'deal_id' => $deal->id,
                    'user_id' => $user->id,
                    'level' => $level,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Build level unlock message.
     */
    protected function buildLevelUnlockMessage(
        FlashDeal $deal,
        int $level,
        array $tier,
        ?array $nextTier
    ): string {
        $message = "{$tier['emoji']}{$tier['emoji']}{$tier['emoji']} *LEVEL {$level} UNLOCKED!* {$tier['emoji']}{$tier['emoji']}{$tier['emoji']}\n" .
            "*ലെവൽ {$level} അൺലോക്ക് ആയി!*\n\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "⚡ *{$deal->title}*\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "🎉 *{$tier['name']} Level reached!*\n" .
            "💰 Discount now: *{$tier['discount']}% OFF!*\n\n" .
            "👥 {$deal->progress_display} claimed\n" .
            "{$this->buildChainProgressBar($deal)}\n\n";

        if ($nextTier) {
            $needed = $nextTier['claims'] - $deal->current_claims;
            $message .= "🔥 *But wait — there's more!*\n" .
                "*Keep sharing for bigger savings:*\n\n" .
                "{$nextTier['emoji']} Level " . ($level + 1) . ": *{$nextTier['discount']}% OFF*\n" .
                "👥 Just *{$needed} more* people needed!\n\n" .
                "📤 *Share to unlock the next level!*\n" .
                "_അടുത്ത ലെവൽ അൺലോക്ക് ചെയ്യാൻ ഷെയർ ചെയ്യൂ!_";
        } else {
            // This is the final level!
            $message .= "🏆 *MAXIMUM LEVEL REACHED!*\n" .
                "*പരമാവധി ലെവൽ എത്തി!*\n\n" .
                "🎉 You've unlocked the best discount!\n" .
                "📤 Keep sharing so friends can join too!";
        }

        return $message;
    }

    /**
     * Build chain progress bar showing levels.
     */
    protected function buildChainProgressBar(FlashDeal $deal): string
    {
        $tiers = $this->getTiers($deal);
        $claims = $deal->current_claims;
        $bar = '';

        foreach ($tiers as $level => $tier) {
            if ($claims >= $tier['claims']) {
                $bar .= $tier['emoji'] . ' ';
            } else {
                $bar .= '⬜ ';
            }
        }

        return trim($bar);
    }

    /**
     * Notify shop owner of level unlock.
     */
    protected function notifyOwnerOfLevelUnlock(FlashDeal $deal, int $level, array $tier): void
    {
        $owner = $deal->shop->user;
        if (!$owner || !$owner->phone) {
            return;
        }

        $message = "{$tier['emoji']} *Chain Deal Level Up!*\n\n" .
            "⚡ {$deal->title}\n\n" .
            "🎉 *Level {$level} ({$tier['name']}) Unlocked!*\n" .
            "💰 Discount now: {$tier['discount']}%\n" .
            "👥 Claims: {$deal->current_claims}\n\n" .
            "_All claimants have been notified!_";

        try {
            $this->whatsApp->sendText($owner->phone, $message);
        } catch (\Exception $e) {
            Log::warning('Failed to notify owner of level unlock', [
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get the chain deal alert message for customers.
     */
    public function getChainDealAlertMessage(FlashDeal $deal, float $distance): string
    {
        $tiers = $this->getTiers($deal);
        $currentLevel = $deal->current_chain_level ?? 0;
        $currentDiscount = $deal->discount_percent;

        $tiersDisplay = $this->buildTiersPreview($tiers, $currentLevel);

        return "⚡🔗 *CHAIN DEAL!* 🔗⚡\n" .
            "*ചെയിൻ ഡീൽ!*\n\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "🎯 *{$deal->title}*\n" .
            "🏪 {$deal->shop->shop_name} • {$distance} away\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "💰 *Starts at {$currentDiscount}% OFF*\n" .
            "📈 *Discount GROWS as more people join!*\n\n" .
            "{$tiersDisplay}\n\n" .
            "👥 Current: {$deal->progress_display} claimed\n" .
            "⏰ {$deal->time_remaining_display} remaining\n\n" .
            "🔥 _Join now and help unlock bigger discounts!_\n" .
            "_ഇപ്പോൾ ജോയിൻ ചെയ്യൂ, കൂടുതൽ ഡിസ്കൗണ്ട് അൺലോക്ക് ചെയ്യൂ!_";
    }

    /**
     * Build tiers preview display.
     */
    protected function buildTiersPreview(array $tiers, int $currentLevel): string
    {
        $display = "*📊 Unlock Levels:*\n";

        foreach ($tiers as $level => $tier) {
            $status = $level <= $currentLevel ? '✅' : '🔒';
            $display .= "{$status} {$tier['emoji']} Level {$level}: *{$tier['discount']}% OFF* ({$tier['claims']} people)\n";
        }

        return $display;
    }

    /**
     * Get claim confirmation for chain deal.
     */
    public function getChainClaimConfirmation(FlashDeal $deal, FlashDealClaim $claim): string
    {
        $tiers = $this->getTiers($deal);
        $currentLevel = $deal->current_chain_level ?? 0;
        $currentDiscount = $deal->discount_percent;

        // Find next tier
        $nextTier = null;
        foreach ($tiers as $level => $tier) {
            if ($level > $currentLevel) {
                $nextTier = $tier;
                $nextLevel = $level;
                break;
            }
        }

        $message = "✅ *Claimed! You're {$claim->position_display}!* 🔗\n" .
            "*ക്ലെയിം ചെയ്തു!*\n\n" .
            "⚡ *{$deal->title}*\n\n" .
            "💰 *Current discount: {$currentDiscount}% OFF*\n\n" .
            "👥 {$deal->progress_display} claimed\n" .
            "{$this->buildChainProgressBar($deal)}\n\n";

        if ($nextTier) {
            $needed = $nextTier['claims'] - $deal->current_claims;
            $message .= "🔓 *Next unlock:* {$nextTier['emoji']} {$nextTier['discount']}% OFF\n" .
                "👥 Need *{$needed} more* people!\n\n" .
                "📤 *Share to unlock bigger discounts!*\n" .
                "_കൂടുതൽ ഡിസ്കൗണ്ട് അൺലോക്ക് ചെയ്യാൻ ഷെയർ ചെയ്യൂ!_";
        } else {
            $message .= "🏆 *Maximum discount already unlocked!*\n" .
                "📤 Share so friends can join too!";
        }

        return $message;
    }

    /**
     * Check if deal is a chain deal.
     */
    public function isChainDeal(FlashDeal $deal): bool
    {
        return $deal->is_chain_deal ?? false;
    }

    /**
     * Get tiers for a chain deal.
     */
    public function getTiers(FlashDeal $deal): array
    {
        if (!$this->isChainDeal($deal)) {
            return [];
        }

        $tiers = $deal->chain_tiers;

        if (is_string($tiers)) {
            $tiers = json_decode($tiers, true);
        }

        return $tiers ?? self::getDefaultTiers();
    }

    /**
     * Get current level info for a chain deal.
     */
    public function getCurrentLevelInfo(FlashDeal $deal): array
    {
        if (!$this->isChainDeal($deal)) {
            return [];
        }

        $tiers = $this->getTiers($deal);
        $currentLevel = $deal->current_chain_level ?? 0;

        $currentTier = $tiers[$currentLevel] ?? null;
        $nextTier = $tiers[$currentLevel + 1] ?? null;

        return [
            'current_level' => $currentLevel,
            'current_tier' => $currentTier,
            'next_tier' => $nextTier,
            'current_discount' => $deal->discount_percent,
            'max_level' => max(array_keys($tiers)),
            'max_discount' => $tiers[max(array_keys($tiers))]['discount'],
            'claims_to_next' => $nextTier ? max(0, $nextTier['claims'] - $deal->current_claims) : 0,
        ];
    }

    /**
     * Get chain deal statistics.
     */
    public function getChainDealStats(FlashDeal $deal): array
    {
        if (!$this->isChainDeal($deal)) {
            return [];
        }

        $tiers = $this->getTiers($deal);
        $levelInfo = $this->getCurrentLevelInfo($deal);

        $levelsUnlocked = $deal->current_chain_level ?? 0;
        $totalLevels = count($tiers);

        return [
            'is_chain_deal' => true,
            'levels_unlocked' => $levelsUnlocked,
            'total_levels' => $totalLevels,
            'completion_percent' => round(($levelsUnlocked / $totalLevels) * 100),
            'current_discount' => $deal->discount_percent,
            'max_discount' => $levelInfo['max_discount'],
            'discount_increase' => $deal->discount_percent - ($tiers[1]['discount'] ?? 0),
            'claims_to_next_level' => $levelInfo['claims_to_next'],
            'tiers' => $tiers,
        ];
    }
}