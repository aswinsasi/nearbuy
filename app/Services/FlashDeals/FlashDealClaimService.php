<?php

declare(strict_types=1);

namespace App\Services\FlashDeals;

use App\Enums\FlashDealStatus;
use App\Models\FlashDeal;
use App\Models\FlashDealClaim;
use App\Models\User;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Service for handling Flash Deal claims.
 *
 * THIS is where the viral loop happens!
 * - User claims deal
 * - Gets position and share link
 * - Shares to help reach target
 * - Gets progress updates
 * - Deal activates when target reached
 *
 * @srs-ref FD-014 to FD-024 - Claiming & Activation
 * @module Flash Mob Deals
 */
class FlashDealClaimService
{
    public function __construct(
        protected WhatsAppService $whatsApp,
        protected FlashDealNotificationService $notificationService
    ) {}

    /**
     * Process a user claiming a deal.
     *
     * @srs-ref FD-014 - Confirm claim, show position
     * @srs-ref FD-015 - Provide share link/button
     */
    public function claimDeal(FlashDeal $deal, User $user, ?int $referrerId = null): array
    {
        // Validate deal is claimable
        $validation = $this->validateClaim($deal, $user);
        if (!$validation['valid']) {
            return $validation;
        }

        return DB::transaction(function () use ($deal, $user, $referrerId) {
            // Create the claim
            $claim = FlashDealClaim::create([
                'flash_deal_id' => $deal->id,
                'user_id' => $user->id,
                'position' => $deal->current_claims + 1,
                'referred_by_user_id' => $referrerId,
            ]);

            // Increment deal claims and check activation
            $wasActivated = $deal->incrementClaims();

            // Generate coupon code if deal is now activated
            if ($wasActivated || $deal->is_activated) {
                $claim->generateCouponCode();
            }

            // Send claim confirmation to user
            $this->sendClaimConfirmation($deal, $claim, $user);

            // If deal was just activated, trigger activation flow
            if ($wasActivated) {
                $this->notificationService->sendActivationNotifications($deal);
            } else {
                // Check for milestone notifications
                $this->checkAndSendMilestoneNotifications($deal);
            }

            Log::info('Flash deal claimed', [
                'deal_id' => $deal->id,
                'user_id' => $user->id,
                'position' => $claim->position,
                'current_claims' => $deal->current_claims,
                'was_activated' => $wasActivated,
            ]);

            return [
                'valid' => true,
                'claim' => $claim,
                'position' => $claim->position,
                'activated' => $wasActivated,
            ];
        });
    }

    /**
     * Validate if user can claim the deal.
     */
    protected function validateClaim(FlashDeal $deal, User $user): array
    {
        // Check deal is live
        if (!$deal->is_live) {
            if ($deal->status === FlashDealStatus::SCHEDULED) {
                return [
                    'valid' => false,
                    'error' => 'deal_not_started',
                    'message' => "⏰ Deal hasn't started yet!\nഡീൽ ഇതുവരെ തുടങ്ങിയിട്ടില്ല!",
                ];
            }

            if ($deal->is_expired) {
                return [
                    'valid' => false,
                    'error' => 'deal_expired',
                    'message' => "⏰ Sorry, this deal has expired.\nക്ഷമിക്കണം, ഈ ഡീൽ കാലഹരണപ്പെട്ടു.",
                ];
            }

            if ($deal->is_activated) {
                // Deal is activated but user can still claim for coupon
                // This is allowed per FD-024
            } else {
                return [
                    'valid' => false,
                    'error' => 'deal_not_available',
                    'message' => "❌ This deal is no longer available.\nഈ ഡീൽ ഇപ്പോൾ ലഭ്യമല്ല.",
                ];
            }
        }

        // Check user hasn't already claimed
        if ($deal->hasUserClaimed($user->id)) {
            $claim = $deal->getUserClaim($user->id);
            return [
                'valid' => false,
                'error' => 'already_claimed',
                'message' => "✅ You've already claimed this deal!\nനിങ്ങൾ ഇതിനകം ഈ ഡീൽ ക്ലെയിം ചെയ്തു!",
                'claim' => $claim,
                'position' => $claim->position,
            ];
        }

        return ['valid' => true];
    }

    /**
     * Send claim confirmation to user.
     *
     * @srs-ref FD-014 - Confirm claim, show position "You're #13"
     * @srs-ref FD-015 - Provide share link/button
     */
    protected function sendClaimConfirmation(FlashDeal $deal, FlashDealClaim $claim, User $user): void
    {
        $remaining = $deal->claims_remaining;
        $timeRemaining = $deal->time_remaining_display;

        // Build confirmation message
        $message = "✅ *Claimed! You're #{$claim->position}!* ⚡\n" .
            "*ക്ലെയിം ചെയ്തു! നിങ്ങൾ #{$claim->position}!*\n\n" .
            "━━━━━━━━━━━━━━━\n" .
            "🎯 *{$deal->title}*\n" .
            "💰 {$deal->discount_display}\n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "📊 *Progress:* {$deal->progress_display} claimed\n" .
            "{$deal->progress_bar}\n\n";

        if ($deal->is_activated) {
            // Deal already activated
            $message .= "🎉 *DEAL ACTIVATED!*\n" .
                "Your coupon code: *{$claim->coupon_code}*\n\n" .
                "📍 Show this at {$deal->shop->shop_name}\n" .
                "Valid until: {$deal->coupon_valid_until->format('M d, h:i A')}";

            $this->whatsApp->sendButtons(
                $user->phone,
                $message,
                [
                    ['id' => 'flash_directions_' . $deal->id, 'title' => '📍 Get Directions'],
                    ['id' => 'flash_share_' . $deal->id, 'title' => '📤 Share Deal'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '🎉 Deal Activated!'
            );
        } else {
            // Deal still needs more claims
            $message .= "👥 *{$remaining} more needed* to activate!\n" .
                "*{$remaining} പേർ കൂടി വേണം* ആക്ടിവേറ്റ് ചെയ്യാൻ!\n" .
                "⏰ {$timeRemaining} remaining\n\n" .
                "📤 *Share with friends to help activate!*\n" .
                "_ആക്ടിവേറ്റ് ചെയ്യാൻ സുഹൃത്തുക്കളുമായി ഷെയർ ചെയ്യുക!_";

            $this->whatsApp->sendButtons(
                $user->phone,
                $message,
                [
                    ['id' => 'flash_share_' . $deal->id, 'title' => '📤 Share Now!'],
                    ['id' => 'flash_progress_' . $deal->id, 'title' => '📊 Watch Progress'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '✅ Claimed!'
            );
        }
    }

    /**
     * Check and send milestone notifications to all claimants.
     *
     * @srs-ref FD-016 - Progress updates at 25%, 50%, 75%, 90%
     * @srs-ref FD-017 - Urgent notification at 90%+ with <5 mins remaining
     * @srs-ref FD-018 - Emphasize sharing CTA
     */
    protected function checkAndSendMilestoneNotifications(FlashDeal $deal): void
    {
        $milestone = $deal->current_milestone;

        if ($milestone === null) {
            return;
        }

        // Get all claims that haven't received this milestone notification
        $claims = $deal->claims()
            ->whereJsonDoesntContain('milestone_notifications_sent', $milestone)
            ->with('user')
            ->get();

        foreach ($claims as $claim) {
            $this->sendMilestoneNotification($deal, $claim, $milestone);
        }
    }

    /**
     * Send milestone notification to a claimant.
     *
     * @srs-ref FD-016, FD-017, FD-018
     */
    protected function sendMilestoneNotification(FlashDeal $deal, FlashDealClaim $claim, int $milestone): void
    {
        $user = $claim->user;
        if (!$user || !$user->phone) {
            return;
        }

        $remaining = $deal->claims_remaining;
        $timeRemaining = $deal->time_remaining_display;
        $progress = $deal->progress_display;

        // Build milestone-specific message
        $message = match ($milestone) {
            25 => "⚡ *{$deal->title}*\n\n" .
                "📊 *25% reached!* {$progress} claimed\n" .
                "{$deal->progress_bar}\n\n" .
                "👥 {$remaining} more needed!\n" .
                "⏰ {$timeRemaining} left\n\n" .
                "_Keep sharing to help activate!_\n" .
                "_ആക്ടിവേറ്റ് ചെയ്യാൻ ഷെയർ ചെയ്യുക!_",

            50 => "⚡ *HALFWAY THERE!* 🔥\n" .
                "*പകുതി എത്തി!*\n\n" .
                "🎯 *{$deal->title}*\n\n" .
                "📊 *50%!* {$progress} claimed!\n" .
                "{$deal->progress_bar}\n\n" .
                "👥 Just *{$remaining} more* needed!\n" .
                "⏰ {$timeRemaining} left\n\n" .
                "📤 *Share now to cross the finish line!*",

            75 => "⚡ *75% - ALMOST THERE!* 🔥🔥\n" .
                "*75% - ഏതാണ്ട് എത്തി!*\n\n" .
                "🎯 *{$deal->title}*\n\n" .
                "📊 *75%!* {$progress} claimed!\n" .
                "{$deal->progress_bar}\n\n" .
                "👥 Just *{$remaining} more* needed!!\n" .
                "⏰ {$timeRemaining} left\n\n" .
                "📤📤 *SHARE NOW! Almost activated!*",

            90 => $this->buildUrgentMessage($deal, $remaining, $timeRemaining),

            default => null,
        };

        if ($message === null) {
            return;
        }

        try {
            // Determine if this is urgent (90% milestone)
            $isUrgent = $milestone === 90;

            $this->whatsApp->sendButtons(
                $user->phone,
                $message,
                [
                    ['id' => 'flash_share_' . $deal->id, 'title' => $isUrgent ? '📤📤 SHARE NOW!' : '📤 Share'],
                    ['id' => 'flash_progress_' . $deal->id, 'title' => '📊 Progress'],
                ],
                $isUrgent ? '🚨 URGENT!' : "⚡ {$milestone}% Update"
            );

            // Mark milestone as sent
            $claim->markMilestoneNotificationSent($milestone);

        } catch (\Exception $e) {
            Log::warning('Failed to send milestone notification', [
                'deal_id' => $deal->id,
                'claim_id' => $claim->id,
                'milestone' => $milestone,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Build urgent notification message (90%+ with <5 mins).
     *
     * @srs-ref FD-017
     */
    protected function buildUrgentMessage(FlashDeal $deal, int $remaining, string $timeRemaining): string
    {
        return "🚨🚨🚨 *URGENT!* 🚨🚨🚨\n" .
            "*അടിയന്തിരം!*\n\n" .
            "⚡ *{$deal->title}*\n\n" .
            "📊 *90%+ reached!* {$deal->progress_display}\n" .
            "{$deal->progress_bar}\n\n" .
            "🔥 *JUST {$remaining} MORE PEOPLE!*\n" .
            "*വെറും {$remaining} പേർ കൂടി!*\n\n" .
            "⏰ Only *{$timeRemaining}* left!!\n\n" .
            "📤📤📤 *SHARE NOW OR DEAL EXPIRES!*\n" .
            "_ഇപ്പോൾ ഷെയർ ചെയ്യൂ അല്ലെങ്കിൽ ഡീൽ കാലഹരണപ്പെടും!_";
    }

    /**
     * Get claim status for user.
     */
    public function getClaimStatus(FlashDeal $deal, User $user): array
    {
        $claim = $deal->getUserClaim($user->id);

        if (!$claim) {
            return [
                'claimed' => false,
                'can_claim' => $deal->is_live || $deal->is_activated,
            ];
        }

        return [
            'claimed' => true,
            'claim' => $claim,
            'position' => $claim->position,
            'coupon_code' => $claim->coupon_code,
            'coupon_valid' => $claim->is_coupon_valid,
            'deal_activated' => $deal->is_activated,
            'deal_expired' => $deal->is_expired,
            'progress' => $deal->progress_percent,
            'claims_remaining' => $deal->claims_remaining,
            'time_remaining' => $deal->time_remaining_display,
        ];
    }

    /**
     * Send share message to user.
     *
     * @srs-ref FD-015 - Provide share link/button
     */
    public function sendShareMessage(FlashDeal $deal, User $user): void
    {
        $remaining = $deal->claims_remaining;
        $shareText = $deal->getShareText();

        $message = "📤 *Share this deal!*\n" .
            "*ഈ ഡീൽ ഷെയർ ചെയ്യുക!*\n\n" .
            "━━━━━━━━━━━━━━━\n" .
            $shareText . "\n" .
            "━━━━━━━━━━━━━━━\n\n" .
            "👆 _Forward this message to friends!_\n" .
            "_ഈ മെസ്സേജ് സുഹൃത്തുക്കൾക്ക് ഫോർവേഡ് ചെയ്യുക!_\n\n" .
            "👥 *{$remaining} more needed* to activate!\n" .
            "⏰ {$deal->time_remaining_display} remaining";

        $this->whatsApp->sendText($user->phone, $message);
    }

    /**
     * Send current progress to user.
     */
    public function sendProgressUpdate(FlashDeal $deal, User $user): void
    {
        $message = "📊 *Deal Progress*\n" .
            "*ഡീൽ പുരോഗതി*\n\n" .
            "🎯 *{$deal->title}*\n\n" .
            "{$deal->progress_bar}\n" .
            "📊 *{$deal->progress_display}* claimed ({$deal->progress_percent}%)\n\n";

        if ($deal->is_activated) {
            $message .= "🎉 *ACTIVATED!* All coupons are valid!\n" .
                "ആക്ടിവേറ്റ് ആയി! എല്ലാ കൂപ്പണുകളും സാധുവാണ്!";
        } elseif ($deal->is_expired) {
            $message .= "⏰ *Expired* - Target not reached\n" .
                "കാലഹരണപ്പെട്ടു - ടാർഗെറ്റ് എത്തിയില്ല";
        } else {
            $message .= "👥 *{$deal->claims_remaining} more* needed to activate!\n" .
                "⏰ *{$deal->time_remaining_display}* remaining\n\n" .
                "_Keep sharing!_ 📤";
        }

        $this->whatsApp->sendButtons(
            $user->phone,
            $message,
            [
                ['id' => 'flash_share_' . $deal->id, 'title' => '📤 Share'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /**
     * Handle "Not Interested" response.
     */
    public function handleNotInterested(FlashDeal $deal, User $user): void
    {
        $this->whatsApp->sendButtons(
            $user->phone,
            "👍 No problem!\n\n" .
            "_You can still browse other deals._\n" .
            "_മറ്റ് ഡീലുകൾ കാണാം._",
            [
                ['id' => 'browse_flash_deals', 'title' => '⚡ Other Deals'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }
}