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
use Illuminate\Support\Str;

/**
 * Service for Surprise Drop deals — Mystery deals with hidden discounts.
 *
 * PSYCHOLOGICAL TRIGGERS: Curiosity + Scarcity + FOMO
 * The discount and product are HIDDEN until the user claims!
 * "First X people discover the offer!"
 *
 * @srs-ref Section 4.5.3 - Surprise Drop
 * @srs-ref Section 4.6 - Psychological Triggers (FOMO, Curiosity)
 * @module Flash Mob Deals - Advanced Features
 */
class FlashDealSurpriseService
{
    /**
     * Mystery emoji for hidden content.
     */
    protected const MYSTERY_EMOJI = '🎁';

    /**
     * Hidden display text.
     */
    protected const HIDDEN_TEXT = '???';

    public function __construct(
        protected WhatsAppService $whatsApp
    ) {}

    /**
     * Create a surprise/mystery deal.
     */
    public function createSurpriseDeal(array $dealData): FlashDeal
    {
        // Mark as surprise deal
        $dealData['is_surprise_deal'] = true;
        $dealData['reveal_on_claim'] = true;

        // Store the actual values but mark as hidden
        $dealData['hidden_title'] = $dealData['title'] ?? 'Mystery Deal';
        $dealData['hidden_discount'] = $dealData['discount_percent'];
        $dealData['hidden_product'] = $dealData['product_description'] ?? null;

        // Public facing shows mystery
        $dealData['title'] = $this->generateMysteryTitle($dealData);

        return FlashDeal::create($dealData);
    }

    /**
     * Generate mystery title for a surprise deal.
     */
    protected function generateMysteryTitle(array $dealData): string
    {
        $shop = isset($dealData['shop_id'])
            ? \App\Models\Shop::find($dealData['shop_id'])
            : null;

        $shopName = $shop ? $shop->shop_name : 'a Shop';
        $category = $dealData['category'] ?? null;

        if ($category) {
            return "Mystery {$category} Deal from {$shopName}";
        }

        return "Mystery Deal from {$shopName}";
    }

    /**
     * Get surprise deal alert message (hidden content).
     *
     * @srs-ref Section 4.5.3 - Mystery alert with hidden discount
     */
    public function getSurpriseAlertMessage(FlashDeal $deal, User $customer): string
    {
        $shop = $deal->shop;
        $distance = $customer->latitude && $customer->longitude
            ? $deal->formattedDistanceFrom($customer->latitude, $customer->longitude)
            : 'nearby';

        $spotsLeft = $deal->target_claims - $deal->current_claims;

        return "🎁✨ *MYSTERY DEAL!* ✨🎁\n" .
            "*മിസ്റ്ററി ഡീൽ!*\n\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "🏪 *{$shop->shop_name}*\n" .
            "📍 {$distance} away\n" .
            "━━━━━━━━━━━━━━━━━━\n\n" .
            "🎁 *" . self::HIDDEN_TEXT . "% OFF* on *" . self::HIDDEN_TEXT . "*\n\n" .
            "❓ _What's the deal? Only one way to find out!_\n" .
            "❓ _എന്താണ് ഡീൽ? കണ്ടെത്താൻ ഒരേയൊരു വഴി!_\n\n" .
            "👥 *First {$deal->target_claims} people* discover the offer!\n" .
            "🔥 *Only {$spotsLeft} spots left!*\n" .
            "⏰ {$deal->time_remaining_display} remaining\n\n" .
            "🎲 _Are you feeling lucky?_\n" .
            "_ഭാഗ്യം പരീക്ഷിക്കാൻ തയ്യാറാണോ?_";
    }

    /**
     * Send surprise deal alert to customer.
     *
     * @srs-ref Section 4.5.3 - Alert with [🎁 Reveal & Claim!] [⏭️ Skip]
     */
    public function sendSurpriseAlert(FlashDeal $deal, User $customer): void
    {
        $message = $this->getSurpriseAlertMessage($deal, $customer);

        // Send mystery image (silhouette or question mark image)
        if ($deal->mystery_image_url) {
            $this->whatsApp->sendImage(
                $customer->phone,
                $deal->mystery_image_url,
                "🎁 Mystery Deal from {$deal->shop->shop_name}!"
            );
        }

        $this->whatsApp->sendButtons(
            $customer->phone,
            $message,
            [
                ['id' => 'surprise_reveal_' . $deal->id, 'title' => '🎁 Reveal & Claim!'],
                ['id' => 'surprise_skip_' . $deal->id, 'title' => '⏭️ Skip'],
            ],
            '🎁 MYSTERY DEAL!'
        );
    }

    /**
     * Reveal and claim a surprise deal.
     *
     * @srs-ref Section 4.5.3 - Upon claim: "🎁 REVEALED! [X]% off [Product]!"
     */
    public function revealAndClaim(FlashDeal $deal, User $user): array
    {
        // Check if already claimed
        if ($deal->hasUserClaimed($user->id)) {
            $claim = $deal->getUserClaim($user->id);
            return [
                'success' => false,
                'error' => 'already_claimed',
                'claim' => $claim,
                'revealed' => $this->getRevealedContent($deal),
            ];
        }

        // Check if deal is still available
        if (!$deal->is_live && !$deal->is_activated) {
            return [
                'success' => false,
                'error' => 'deal_not_available',
            ];
        }

        // Check if spots are available
        if ($deal->current_claims >= $deal->target_claims && !$deal->is_activated) {
            return [
                'success' => false,
                'error' => 'no_spots_left',
            ];
        }

        return DB::transaction(function () use ($deal, $user) {
            // Create the claim
            $claim = FlashDealClaim::create([
                'flash_deal_id' => $deal->id,
                'user_id' => $user->id,
                'position' => $deal->current_claims + 1,
                'claim_source' => 'surprise_reveal',
            ]);

            // Increment deal claims
            $wasActivated = $deal->incrementClaims();

            // Generate coupon if activated
            if ($wasActivated || $deal->is_activated) {
                $claim->generateCouponCode();
            }

            // Send reveal message
            $this->sendRevealMessage($deal, $claim, $user, $wasActivated);

            Log::info('Surprise deal revealed and claimed', [
                'deal_id' => $deal->id,
                'user_id' => $user->id,
                'position' => $claim->position,
                'revealed_discount' => $deal->hidden_discount ?? $deal->discount_percent,
            ]);

            return [
                'success' => true,
                'claim' => $claim,
                'position' => $claim->position,
                'activated' => $wasActivated,
                'revealed' => $this->getRevealedContent($deal),
            ];
        });
    }

    /**
     * Send reveal message after claiming.
     *
     * @srs-ref Section 4.5.3 - "🎁 REVEALED! [X]% off [Product]! You got it!"
     */
    protected function sendRevealMessage(
        FlashDeal $deal,
        FlashDealClaim $claim,
        User $user,
        bool $wasActivated
    ): void {
        $revealed = $this->getRevealedContent($deal);
        $shop = $deal->shop;

        $message = "🎁✨ *REVEALED!* ✨🎁\n" .
            "*വെളിപ്പെട്ടു!*\n\n" .
            "━━━━━━━━━━━━━━━━━━\n" .
            "🎯 *{$revealed['title']}*\n" .
            "💰 *{$revealed['discount']}% OFF!*" .
            ($revealed['max_discount'] ? " (max ₹{$revealed['max_discount']})" : '') . "\n";

        if ($revealed['product']) {
            $message .= "🛍️ *On:* {$revealed['product']}\n";
        }

        $message .= "━━━━━━━━━━━━━━━━━━\n\n" .
            "✅ *You got it! Position {$claim->position_display}*\n" .
            "*നിങ്ങൾക്ക് കിട്ടി!*\n\n";

        if ($wasActivated || $deal->is_activated) {
            $message .= "🎉 *DEAL ACTIVATED!*\n" .
                "🎫 Your coupon: *{$claim->coupon_code}*\n\n" .
                "🏪 {$shop->shop_name}\n" .
                "📍 {$shop->address}";

            $this->whatsApp->sendButtons(
                $user->phone,
                $message,
                [
                    ['id' => 'flash_directions_' . $deal->id, 'title' => '📍 Get Directions'],
                    ['id' => 'surprise_share_' . $deal->id, 'title' => '📤 Share the Find!'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '🎁 REVEALED!'
            );
        } else {
            $remaining = $deal->claims_remaining;
            $message .= "📊 {$deal->progress_display} discovered so far\n" .
                "{$deal->progress_bar}\n\n" .
                "👥 *{$remaining} more* needed to activate!\n" .
                "⏰ {$deal->time_remaining_display} remaining\n\n" .
                "📤 *Share the secret!*\n" .
                "_രഹസ്യം ഷെയർ ചെയ്യൂ!_";

            $this->whatsApp->sendButtons(
                $user->phone,
                $message,
                [
                    ['id' => 'surprise_share_' . $deal->id, 'title' => '📤 Share the Secret!'],
                    ['id' => 'flash_progress_' . $deal->id, 'title' => '📊 Watch Progress'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '🎁 REVEALED!'
            );
        }

        // Send the actual deal image now that it's revealed
        if ($deal->image_url) {
            $this->whatsApp->sendImage(
                $user->phone,
                $deal->image_url,
                "🎁 Here's what you discovered!"
            );
        }
    }

    /**
     * Get revealed content for a surprise deal.
     */
    public function getRevealedContent(FlashDeal $deal): array
    {
        return [
            'title' => $deal->hidden_title ?? $deal->title,
            'discount' => $deal->hidden_discount ?? $deal->discount_percent,
            'product' => $deal->hidden_product ?? null,
            'max_discount' => $deal->max_discount_value,
            'image_url' => $deal->image_url,
        ];
    }

    /**
     * Generate share message for revealed surprise deal.
     */
    public function getShareMessage(FlashDeal $deal, FlashDealClaim $claim): string
    {
        $revealed = $this->getRevealedContent($deal);
        $shop = $deal->shop;
        $remaining = $deal->claims_remaining;

        return "🎁 *I just discovered a SECRET DEAL!*\n\n" .
            "⚡ *{$revealed['title']}*\n" .
            "💰 *{$revealed['discount']}% OFF* at {$shop->shop_name}!\n\n" .
            ($remaining > 0
                ? "👥 Only *{$remaining} spots* left!\n⏰ {$deal->time_remaining_display} remaining!\n\n"
                : "🎉 Deal activated! But you can still claim!\n\n") .
            "🎲 _Want to find out what's hidden?_\n" .
            "Claim now! 🔥";
    }

    /**
     * Handle skip action.
     */
    public function handleSkip(FlashDeal $deal, User $user): void
    {
        $this->whatsApp->sendButtons(
            $user->phone,
            "👍 *No worries!*\n\n" .
            "_The mystery remains..._\n" .
            "_Maybe next time!_",
            [
                ['id' => 'browse_flash_deals', 'title' => '⚡ Other Deals'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            '🎁 Mystery Skipped'
        );
    }

    /**
     * Check if deal is a surprise deal.
     */
    public function isSurpriseDeal(FlashDeal $deal): bool
    {
        return $deal->is_surprise_deal ?? false;
    }

    /**
     * Get teaser hint for surprise deal (optional partial reveal).
     */
    public function getTeaserHint(FlashDeal $deal): ?string
    {
        if (!$this->isSurpriseDeal($deal)) {
            return null;
        }

        // Optionally provide hints based on deal attributes
        $hints = [];

        // Hint about discount range
        $discount = $deal->hidden_discount ?? $deal->discount_percent;
        if ($discount >= 50) {
            $hints[] = "💎 _Hint: It's a BIG one!_";
        } elseif ($discount >= 30) {
            $hints[] = "✨ _Hint: Pretty good deal!_";
        }

        // Hint about category
        if ($deal->category) {
            $hints[] = "🏷️ _Category: {$deal->category}_";
        }

        // Hint about spots
        $spotsLeft = $deal->target_claims - $deal->current_claims;
        if ($spotsLeft <= 10) {
            $hints[] = "🔥 _Almost gone!_";
        }

        return !empty($hints) ? implode("\n", $hints) : null;
    }

    /**
     * Create dramatic reveal animation messages.
     */
    public function sendDramaticReveal(FlashDeal $deal, User $user): void
    {
        $revealed = $this->getRevealedContent($deal);

        // Message 1: Building suspense
        $this->whatsApp->sendText(
            $user->phone,
            "🎁 *Opening the mystery box...*\n" .
            "🎁 *മിസ്റ്ററി ബോക്സ് തുറക്കുന്നു...*"
        );

        // Small delay could be added via queue
        sleep(1);

        // Message 2: Drum roll
        $this->whatsApp->sendText(
            $user->phone,
            "🥁🥁🥁\n\n*3... 2... 1...*"
        );

        sleep(1);

        // Message 3: The reveal!
        $this->whatsApp->sendText(
            $user->phone,
            "🎉🎉🎉\n\n" .
            "*IT'S {$revealed['discount']}% OFF!*\n" .
            "*{$revealed['discount']}% ഓഫ്!*\n\n" .
            "🎯 *{$revealed['title']}*"
        );
    }

    /**
     * Get surprise deal statistics.
     */
    public function getSurpriseStats(FlashDeal $deal): array
    {
        if (!$this->isSurpriseDeal($deal)) {
            return [];
        }

        $claims = $deal->claims()->get();
        $revealRate = $deal->notified_customers_count > 0
            ? round(($claims->count() / $deal->notified_customers_count) * 100, 1)
            : 0;

        return [
            'is_surprise_deal' => true,
            'reveals' => $claims->count(),
            'reveal_rate' => $revealRate,
            'skips_estimated' => $deal->notified_customers_count - $claims->count(),
            'hidden_discount' => $deal->hidden_discount ?? $deal->discount_percent,
            'curiosity_score' => $this->calculateCuriosityScore($deal),
        ];
    }

    /**
     * Calculate curiosity score (how compelling was the mystery).
     */
    protected function calculateCuriosityScore(FlashDeal $deal): string
    {
        $revealRate = $deal->notified_customers_count > 0
            ? ($deal->current_claims / $deal->notified_customers_count) * 100
            : 0;

        if ($revealRate >= 20) {
            return '🌟🌟🌟 Very Curious!';
        } elseif ($revealRate >= 10) {
            return '🌟🌟 Moderately Curious';
        } elseif ($revealRate >= 5) {
            return '🌟 Somewhat Curious';
        }

        return '😴 Low Curiosity';
    }

    /**
     * Generate mystery image URL (placeholder or silhouette).
     */
    public function generateMysteryImageUrl(FlashDeal $deal): string
    {
        // This could generate or return a mystery placeholder image
        // For now, return a default mystery image URL
        return config('app.url') . '/images/mystery-deal.png';
    }
}