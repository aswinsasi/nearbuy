<?php

declare(strict_types=1);

namespace App\Services\FlashDeals;

use App\Enums\FlashDealStatus;
use App\Models\FlashDeal;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for rescuing Flash Deals that are close but running out of time.
 *
 * PSYCHOLOGICAL TRIGGER: Sunk Cost + Urgency
 * When a deal is 80%+ complete with <5 mins left, give shop owner
 * options to save it — extending time or adding bonus discount.
 *
 * @srs-ref Section 4.5.1 - Rescue Mode
 * @srs-ref Section 4.6 - Psychological Triggers (Urgency, Sunk Cost)
 * @module Flash Mob Deals - Advanced Features
 */
class FlashDealRescueService
{
    /**
     * Minimum progress percentage to trigger rescue.
     */
    protected const RESCUE_THRESHOLD_PERCENT = 80;

    /**
     * Maximum time remaining to trigger rescue (in seconds).
     */
    protected const RESCUE_TIME_THRESHOLD_SECONDS = 300; // 5 minutes

    /**
     * Default extension time in minutes.
     */
    protected const DEFAULT_EXTENSION_MINUTES = 10;

    /**
     * Default bonus discount percentage.
     */
    protected const DEFAULT_BONUS_DISCOUNT = 5;

    /**
     * Cache prefix for rescue notifications.
     */
    protected const RESCUE_NOTIFY_CACHE_PREFIX = 'flash_rescue_notified_';

    public function __construct(
        protected WhatsAppService $whatsApp
    ) {}

    /**
     * Check if deal qualifies for rescue mode.
     *
     * @srs-ref Section 4.5.1 - 80%+ with <5 mins remaining
     */
    public function qualifiesForRescue(FlashDeal $deal): bool
    {
        // Must be live
        if ($deal->status !== FlashDealStatus::LIVE) {
            return false;
        }

        // Must be 80%+ complete
        if ($deal->progress_percent < self::RESCUE_THRESHOLD_PERCENT) {
            return false;
        }

        // Must have <5 mins remaining
        if ($deal->time_remaining_seconds > self::RESCUE_TIME_THRESHOLD_SECONDS) {
            return false;
        }

        // Must have time remaining (not already expired)
        if ($deal->time_remaining_seconds <= 0) {
            return false;
        }

        return true;
    }

    /**
     * Check and trigger rescue mode for a deal.
     */
    public function checkAndTriggerRescue(FlashDeal $deal): bool
    {
        if (!$this->qualifiesForRescue($deal)) {
            return false;
        }

        // Check if we already sent rescue notification
        $cacheKey = self::RESCUE_NOTIFY_CACHE_PREFIX . $deal->id;
        if (Cache::has($cacheKey)) {
            return false;
        }

        // Send rescue notification to shop owner
        $this->sendRescueNotification($deal);

        // Mark as notified (cache for 1 hour)
        Cache::put($cacheKey, true, 3600);

        return true;
    }

    /**
     * Send rescue notification to shop owner.
     *
     * @srs-ref Section 4.5.1 - Rescue notification with options
     */
    protected function sendRescueNotification(FlashDeal $deal): void
    {
        $owner = $deal->shop->user;
        if (!$owner || !$owner->phone) {
            return;
        }

        $remaining = $deal->claims_remaining;
        $timeRemaining = $deal->time_remaining_display;
        $progress = $deal->progress_percent;

        $message = "🚨 *RESCUE MODE ACTIVATED!*\n" .
            "*റെസ്ക്യൂ മോഡ്!*\n\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "⚡ *{$deal->title}*\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "📊 *Progress:* {$progress}%!\n" .
            "{$deal->progress_bar}\n" .
            "🎯 {$deal->progress_display} claimed\n\n" .
            "👥 *Just {$remaining} more needed!*\n" .
            "*വെറും {$remaining} പേർ കൂടി!*\n\n" .
            "⏰ *Only {$timeRemaining} left!*\n" .
            "*{$timeRemaining} മാത്രം ബാക്കി!*\n\n" .
            "🔥 _Don't let it expire! You're SO close!_\n" .
            "_എക്സ്പയർ ആകരുത്! വളരെ അടുത്തെത്തി!_\n\n" .
            "*Choose an action:*";

        try {
            // Send with list menu for options
            $this->whatsApp->sendList(
                $owner->phone,
                $message,
                'Rescue Options',
                [
                    [
                        'title' => 'Rescue Actions',
                        'rows' => [
                            [
                                'id' => 'rescue_extend_' . $deal->id,
                                'title' => '🔄 Extend 10 mins',
                                'description' => 'Give more time to reach target',
                            ],
                            [
                                'id' => 'rescue_bonus_' . $deal->id,
                                'title' => '💰 Add 5% bonus',
                                'description' => 'Boost discount to attract more',
                            ],
                            [
                                'id' => 'rescue_both_' . $deal->id,
                                'title' => '🔥 BOTH!',
                                'description' => 'Extend + bonus = maximum rescue!',
                            ],
                            [
                                'id' => 'rescue_expire_' . $deal->id,
                                'title' => '⏰ Let it expire',
                                'description' => 'No action, let deal end naturally',
                            ],
                        ],
                    ],
                ]
            );

            Log::info('Rescue notification sent', [
                'deal_id' => $deal->id,
                'progress' => $progress,
                'time_remaining' => $deal->time_remaining_seconds,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send rescue notification', [
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Process rescue action from shop owner.
     */
    public function processRescueAction(FlashDeal $deal, string $action): array
    {
        return match ($action) {
            'extend' => $this->extendDeal($deal),
            'bonus' => $this->addBonusDiscount($deal),
            'both' => $this->extendAndBonus($deal),
            'expire' => $this->letExpire($deal),
            default => ['success' => false, 'error' => 'Invalid action'],
        };
    }

    /**
     * Extend deal time.
     *
     * @srs-ref Section 4.5.1 - Extend 10 mins option
     */
    public function extendDeal(FlashDeal $deal, int $minutes = self::DEFAULT_EXTENSION_MINUTES): array
    {
        if ($deal->status !== FlashDealStatus::LIVE) {
            return ['success' => false, 'error' => 'Deal is not live'];
        }

        DB::transaction(function () use ($deal, $minutes) {
            // Extend expiry time
            $newExpiry = $deal->expires_at->addMinutes($minutes);
            $deal->update([
                'expires_at' => $newExpiry,
                'rescue_extended' => true,
                'rescue_extended_at' => now(),
                'rescue_extension_minutes' => $minutes,
            ]);

            // Notify all claimants
            $this->notifyClaimantsOfExtension($deal, $minutes);
        });

        Log::info('Deal extended via rescue', [
            'deal_id' => $deal->id,
            'extension_minutes' => $minutes,
            'new_expiry' => $deal->fresh()->expires_at,
        ]);

        return [
            'success' => true,
            'message' => "Extended by {$minutes} minutes!",
            'new_expiry' => $deal->fresh()->expires_at,
        ];
    }

    /**
     * Add bonus discount.
     *
     * @srs-ref Section 4.5.1 - Add 5% bonus discount option
     */
    public function addBonusDiscount(FlashDeal $deal, int $bonusPercent = self::DEFAULT_BONUS_DISCOUNT): array
    {
        if ($deal->status !== FlashDealStatus::LIVE) {
            return ['success' => false, 'error' => 'Deal is not live'];
        }

        DB::transaction(function () use ($deal, $bonusPercent) {
            $originalDiscount = $deal->discount_percent;
            $newDiscount = min(90, $originalDiscount + $bonusPercent);

            $deal->update([
                'discount_percent' => $newDiscount,
                'rescue_bonus_added' => true,
                'rescue_bonus_percent' => $bonusPercent,
                'original_discount_percent' => $originalDiscount,
            ]);

            // Notify all claimants
            $this->notifyClaimantsOfBonus($deal, $bonusPercent, $newDiscount);
        });

        Log::info('Bonus discount added via rescue', [
            'deal_id' => $deal->id,
            'bonus_percent' => $bonusPercent,
            'new_discount' => $deal->fresh()->discount_percent,
        ]);

        return [
            'success' => true,
            'message' => "Added {$bonusPercent}% bonus discount!",
            'new_discount' => $deal->fresh()->discount_percent,
        ];
    }

    /**
     * Extend AND add bonus discount.
     */
    public function extendAndBonus(FlashDeal $deal): array
    {
        $extendResult = $this->extendDeal($deal);
        if (!$extendResult['success']) {
            return $extendResult;
        }

        $bonusResult = $this->addBonusDiscount($deal);
        if (!$bonusResult['success']) {
            return $bonusResult;
        }

        // Send combined notification
        $this->notifyClaimantsOfBothRescue($deal);

        // Notify shop owner of success
        $this->notifyOwnerRescueApplied($deal, 'both');

        return [
            'success' => true,
            'message' => 'Extended 10 mins AND added 5% bonus!',
            'new_expiry' => $extendResult['new_expiry'],
            'new_discount' => $bonusResult['new_discount'],
        ];
    }

    /**
     * Let deal expire naturally.
     */
    public function letExpire(FlashDeal $deal): array
    {
        // Just acknowledge - deal will expire via normal job
        $this->notifyOwnerLetExpire($deal);

        return [
            'success' => true,
            'message' => 'Deal will expire naturally. Good luck!',
        ];
    }

    /**
     * Notify claimants of time extension.
     *
     * @srs-ref Section 4.5.1 - If extended: notify all claimants
     */
    protected function notifyClaimantsOfExtension(FlashDeal $deal, int $minutes): void
    {
        $claims = $deal->claims()->with('user')->get();
        $remaining = $deal->claims_remaining;

        foreach ($claims as $claim) {
            $user = $claim->user;
            if (!$user || !$user->phone) {
                continue;
            }

            $message = "🔥 *EXTENDED!* ⏰\n" .
                "*എക്സ്റ്റെൻഡ് ചെയ്തു!*\n\n" .
                "⚡ *{$deal->title}*\n\n" .
                "🎁 *{$minutes} more minutes added!*\n" .
                "👥 Just *{$remaining} more* needed!\n\n" .
                "📤 *Share NOW to help activate!*\n" .
                "_ആക്ടിവേറ്റ് ചെയ്യാൻ ഇപ്പോൾ ഷെയർ ചെയ്യൂ!_";

            try {
                $this->whatsApp->sendButtons(
                    $user->phone,
                    $message,
                    [
                        ['id' => 'flash_share_' . $deal->id, 'title' => '📤 Share Now!'],
                        ['id' => 'flash_progress_' . $deal->id, 'title' => '📊 Progress'],
                    ],
                    '🔥 EXTENDED!'
                );
            } catch (\Exception $e) {
                Log::warning('Failed to notify claimant of extension', [
                    'deal_id' => $deal->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify claimants of bonus discount.
     */
    protected function notifyClaimantsOfBonus(FlashDeal $deal, int $bonusPercent, int $newDiscount): void
    {
        $claims = $deal->claims()->with('user')->get();
        $remaining = $deal->claims_remaining;

        foreach ($claims as $claim) {
            $user = $claim->user;
            if (!$user || !$user->phone) {
                continue;
            }

            $message = "💰 *BONUS DISCOUNT!* 🎉\n" .
                "*ബോണസ് ഡിസ്കൗണ്ട്!*\n\n" .
                "⚡ *{$deal->title}*\n\n" .
                "🔥 *Extra {$bonusPercent}% OFF added!*\n" .
                "💰 Now *{$newDiscount}% OFF* total!\n\n" .
                "👥 Just *{$remaining} more* needed!\n\n" .
                "📤 *Share the better deal!*\n" .
                "_മികച്ച ഡീൽ ഷെയർ ചെയ്യൂ!_";

            try {
                $this->whatsApp->sendButtons(
                    $user->phone,
                    $message,
                    [
                        ['id' => 'flash_share_' . $deal->id, 'title' => '📤 Share Now!'],
                        ['id' => 'flash_progress_' . $deal->id, 'title' => '📊 Progress'],
                    ],
                    '💰 BONUS!'
                );
            } catch (\Exception $e) {
                Log::warning('Failed to notify claimant of bonus', [
                    'deal_id' => $deal->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify claimants of both rescue actions.
     *
     * @srs-ref Section 4.5.1 - "🔥 EXTENDED! 10 more minutes + extra 5% off!"
     */
    protected function notifyClaimantsOfBothRescue(FlashDeal $deal): void
    {
        $claims = $deal->claims()->with('user')->get();
        $remaining = $deal->claims_remaining;

        foreach ($claims as $claim) {
            $user = $claim->user;
            if (!$user || !$user->phone) {
                continue;
            }

            $message = "🔥🔥🔥 *SUPER RESCUE!* 🔥🔥🔥\n" .
                "*സൂപ്പർ റെസ്ക്യൂ!*\n\n" .
                "⚡ *{$deal->title}*\n\n" .
                "🎁 *10 more minutes* PLUS\n" .
                "💰 *Extra 5% OFF!*\n\n" .
                "💥 Now *{$deal->discount_percent}% OFF* total!\n" .
                "👥 Just *{$remaining} more* needed!\n\n" .
                "🚀 *THIS IS IT! SHARE NOW!*\n" .
                "_ഇതാണ് സമയം! ഇപ്പോൾ ഷെയർ ചെയ്യൂ!_";

            try {
                $this->whatsApp->sendButtons(
                    $user->phone,
                    $message,
                    [
                        ['id' => 'flash_share_urgent_' . $deal->id, 'title' => '🚀 SHARE NOW!'],
                        ['id' => 'flash_progress_' . $deal->id, 'title' => '📊 Progress'],
                    ],
                    '🔥 SUPER RESCUE!'
                );
            } catch (\Exception $e) {
                Log::warning('Failed to notify claimant of super rescue', [
                    'deal_id' => $deal->id,
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify owner that rescue was applied.
     */
    protected function notifyOwnerRescueApplied(FlashDeal $deal, string $type): void
    {
        $owner = $deal->shop->user;
        if (!$owner || !$owner->phone) {
            return;
        }

        $typeMessage = match ($type) {
            'extend' => '⏰ Extended by 10 minutes!',
            'bonus' => '💰 Added 5% bonus discount!',
            'both' => '🔥 Extended + Bonus applied!',
            default => '✅ Action applied!',
        };

        $message = "✅ *Rescue Applied!*\n\n" .
            "⚡ {$deal->title}\n\n" .
            "{$typeMessage}\n\n" .
            "📊 Current: {$deal->progress_display}\n" .
            "⏰ Expires: {$deal->expires_at->format('h:i A')}\n\n" .
            "_All claimants have been notified!_";

        try {
            $this->whatsApp->sendText($owner->phone, $message);
        } catch (\Exception $e) {
            Log::warning('Failed to notify owner of rescue applied', [
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify owner that they chose to let deal expire.
     */
    protected function notifyOwnerLetExpire(FlashDeal $deal): void
    {
        $owner = $deal->shop->user;
        if (!$owner || !$owner->phone) {
            return;
        }

        $message = "👍 *Understood!*\n\n" .
            "⚡ {$deal->title}\n\n" .
            "Deal will expire naturally at {$deal->expires_at->format('h:i A')}.\n\n" .
            "📊 Current progress: {$deal->progress_display}\n\n" .
            "_Good luck! Maybe they'll still make it!_ 🤞";

        try {
            $this->whatsApp->sendText($owner->phone, $message);
        } catch (\Exception $e) {
            Log::warning('Failed to notify owner of let expire', [
                'deal_id' => $deal->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get rescue status for a deal.
     */
    public function getRescueStatus(FlashDeal $deal): array
    {
        return [
            'qualifies' => $this->qualifiesForRescue($deal),
            'progress' => $deal->progress_percent,
            'time_remaining' => $deal->time_remaining_seconds,
            'claims_remaining' => $deal->claims_remaining,
            'was_extended' => $deal->rescue_extended ?? false,
            'bonus_added' => $deal->rescue_bonus_added ?? false,
            'can_rescue' => $this->qualifiesForRescue($deal) && !($deal->rescue_extended && $deal->rescue_bonus_added),
        ];
    }
}