<?php

namespace App\Services\WhatsApp\Messages;

use Carbon\Carbon;

/**
 * Notification message templates for NearBuy.
 *
 * UX Principles:
 * - Every template MAX 4 lines body
 * - Every notification ends with actionable button
 * - Bilingual support (English + Malayalam)
 * - Batch notifications summarize clearly
 * - Time-sensitive alerts are urgent but not scary
 */
class NotificationMessages
{
    /*
    |--------------------------------------------------------------------------
    | Language Constants
    |--------------------------------------------------------------------------
    */

    public const LANG_EN = 'en';
    public const LANG_ML = 'ml';

    /*
    |--------------------------------------------------------------------------
    | Product Request Notifications (to Shops)
    |--------------------------------------------------------------------------
    */

    /**
     * Single new product request (immediate notification).
     */
    public static function newRequest(array $data, string $lang = self::LANG_EN): array
    {
        $description = self::truncate($data['description'] ?? '', 50);
        $distance = self::formatDistance($data['distance_km'] ?? 0);
        $expiresIn = self::formatTimeRemaining($data['expires_at'] ?? null);
        $requestNumber = $data['request_number'] ?? '';

        $message = ($lang === self::LANG_ML)
            ? "🔔 *Product Request!*\n\n📦 {$description}\n📍 {$distance} | ⏰ {$expiresIn}\n#{$requestNumber}"
            : "🔔 *New Request!*\n\n📦 {$description}\n📍 {$distance} away | ⏰ {$expiresIn}\n#{$requestNumber}";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'respond_yes_' . ($data['request_id'] ?? ''), 'title' => '✅ I Have It'],
                ['id' => 'respond_no_' . ($data['request_id'] ?? ''), 'title' => "❌ Don't Have"],
            ],
        ];
    }

    /**
     * Batch product requests summary.
     */
    public static function batchRequests(array $requests, string $lang = self::LANG_EN): array
    {
        $count = count($requests);

        $message = ($lang === self::LANG_ML)
            ? "🔔 *{$count} Product Requests!*\n\n"
            : "🔔 *{$count} New Requests!*\n\n";

        // Show first 3 items
        $shown = array_slice($requests, 0, 3);
        foreach ($shown as $i => $req) {
            $num = $i + 1;
            $desc = self::truncate($req['description'] ?? '', 25);
            $dist = self::formatDistance($req['distance_km'] ?? 0);
            $message .= "{$num}. {$desc} — {$dist}\n";
        }

        if ($count > 3) {
            $more = $count - 3;
            $message .= ($lang === self::LANG_ML)
                ? "\n+{$more} koodi..."
                : "\n+{$more} more...";
        }

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_all_requests', 'title' => "📋 View All ({$count})"],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Request expiring soon alert.
     */
    public static function requestExpiringSoon(array $data, string $lang = self::LANG_EN): array
    {
        $description = self::truncate($data['description'] ?? '', 40);
        $expiresIn = self::formatTimeRemaining($data['expires_at'] ?? null);

        $message = ($lang === self::LANG_ML)
            ? "⏰ *Request Expire Aakum!*\n\n📦 {$description}\n⏳ {$expiresIn} left"
            : "⏰ *Request Expiring!*\n\n📦 {$description}\n⏳ {$expiresIn} left";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'respond_now_' . ($data['request_id'] ?? ''), 'title' => '✅ Respond Now'],
                ['id' => 'skip', 'title' => '⏭️ Skip'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Response Notifications (to Customers)
    |--------------------------------------------------------------------------
    */

    /**
     * New response received.
     */
    public static function newResponse(array $data, string $lang = self::LANG_EN): array
    {
        $shopName = self::truncate($data['shop_name'] ?? '', 25);
        $price = number_format($data['price'] ?? 0);
        $distance = self::formatDistance($data['distance_km'] ?? 0);

        $message = ($lang === self::LANG_ML)
            ? "✅ *Response Vannu!*\n\n🏪 {$shopName}\n💰 ₹{$price} | 📍 {$distance}"
            : "✅ *New Response!*\n\n🏪 {$shopName}\n💰 ₹{$price} | 📍 {$distance}";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_response_' . ($data['response_id'] ?? ''), 'title' => '👀 View Details'],
                ['id' => 'view_all_responses', 'title' => '📋 All Responses'],
            ],
        ];
    }

    /**
     * Multiple responses summary.
     */
    public static function multipleResponses(array $data, string $lang = self::LANG_EN): array
    {
        $count = $data['count'] ?? 0;
        $lowestPrice = number_format($data['lowest_price'] ?? 0);
        $description = self::truncate($data['request_description'] ?? '', 30);

        $message = ($lang === self::LANG_ML)
            ? "🎉 *{$count} Responses!*\n\n📦 {$description}\n💰 ₹{$lowestPrice} muthal"
            : "🎉 *{$count} Shops Responded!*\n\n📦 {$description}\n💰 From ₹{$lowestPrice}";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_responses', 'title' => "👀 View All ({$count})"],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Request expired notification.
     */
    public static function requestExpired(array $data, string $lang = self::LANG_EN): array
    {
        $description = self::truncate($data['description'] ?? '', 35);
        $responseCount = $data['response_count'] ?? 0;

        if ($responseCount > 0) {
            $message = ($lang === self::LANG_ML)
                ? "⏰ *Request Expire Aayi*\n\n📦 {$description}\n✅ {$responseCount} responses vannu!"
                : "⏰ *Request Expired*\n\n📦 {$description}\n✅ {$responseCount} response(s) received!";

            $buttons = [
                ['id' => 'view_responses', 'title' => "👀 View ({$responseCount})"],
                ['id' => 'new_request', 'title' => '➕ New Request'],
            ];
        } else {
            $message = ($lang === self::LANG_ML)
                ? "⏰ *Request Expire Aayi*\n\n📦 {$description}\n😔 Responses vannilla"
                : "⏰ *Request Expired*\n\n📦 {$description}\n😔 No responses received";

            $buttons = [
                ['id' => 'retry_request', 'title' => '🔄 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ];
        }

        return [
            'message' => $message,
            'buttons' => $buttons,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Offer Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Offer expiring soon.
     */
    public static function offerExpiring(array $data, string $lang = self::LANG_EN): array
    {
        $title = self::truncate($data['title'] ?? '', 30);
        $expiresIn = self::formatTimeRemaining($data['expires_at'] ?? null);

        $message = ($lang === self::LANG_ML)
            ? "⏰ *Offer Expire Aakum!*\n\n📢 {$title}\n⏳ {$expiresIn} left"
            : "⏰ *Offer Expiring!*\n\n📢 {$title}\n⏳ {$expiresIn} left";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'renew_offer_' . ($data['offer_id'] ?? ''), 'title' => '🔄 Renew'],
                ['id' => 'let_expire', 'title' => '⏭️ Let Expire'],
            ],
        ];
    }

    /**
     * Offer expired.
     */
    public static function offerExpired(array $data, string $lang = self::LANG_EN): array
    {
        $title = self::truncate($data['title'] ?? '', 30);
        $views = $data['views'] ?? 0;

        $message = ($lang === self::LANG_ML)
            ? "❌ *Offer Expire Aayi*\n\n📢 {$title}\n👀 {$views} views kitty"
            : "❌ *Offer Expired*\n\n📢 {$title}\n👀 Got {$views} views";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'upload_new', 'title' => '📤 New Offer'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Offer views milestone.
     */
    public static function offerMilestone(array $data, string $lang = self::LANG_EN): array
    {
        $title = self::truncate($data['title'] ?? '', 25);
        $views = $data['views'] ?? 0;

        $message = ($lang === self::LANG_ML)
            ? "🎉 *{$views} Views!*\n\n📢 {$title}\n👀 Customers kaaanunnu!"
            : "🎉 *{$views} Views!*\n\n📢 {$title}\n👀 Customers are looking!";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_stats', 'title' => '📊 View Stats'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Agreement Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Agreement confirmation needed (to counterparty).
     */
    public static function agreementPending(array $data, string $lang = self::LANG_EN): array
    {
        $creatorName = self::truncate($data['creator_name'] ?? '', 20);
        $amount = number_format($data['amount'] ?? 0);
        $purpose = $data['purpose'] ?? '';

        $message = ($lang === self::LANG_ML)
            ? "📝 *Agreement Confirm Cheyyuka*\n\n👤 {$creatorName}\n💰 ₹{$amount} ({$purpose})"
            : "📝 *Confirm Agreement*\n\n👤 From: {$creatorName}\n💰 ₹{$amount} ({$purpose})";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'confirm_agreement_' . ($data['agreement_id'] ?? ''), 'title' => '✅ Confirm'],
                ['id' => 'reject_agreement_' . ($data['agreement_id'] ?? ''), 'title' => '❌ Reject'],
                ['id' => 'view_details', 'title' => '👀 Details'],
            ],
        ];
    }

    /**
     * Agreement confirmed.
     */
    public static function agreementConfirmed(array $data, string $lang = self::LANG_EN): array
    {
        $agreementNumber = $data['agreement_number'] ?? '';
        $otherParty = self::truncate($data['other_party'] ?? '', 20);

        $message = ($lang === self::LANG_ML)
            ? "✅ *Agreement Confirmed!*\n\n📋 #{$agreementNumber}\n👤 {$otherParty}\n📄 PDF varunnu..."
            : "✅ *Agreement Confirmed!*\n\n📋 #{$agreementNumber}\n👤 With: {$otherParty}\n📄 PDF coming...";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_agreement_' . ($data['agreement_id'] ?? ''), 'title' => '👀 View'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Agreement reminder (pending confirmation).
     */
    public static function agreementReminder(array $data, string $lang = self::LANG_EN): array
    {
        $creatorName = self::truncate($data['creator_name'] ?? '', 20);
        $amount = number_format($data['amount'] ?? 0);

        $message = ($lang === self::LANG_ML)
            ? "🔔 *Agreement Pending!*\n\n👤 {$creatorName} waiting\n💰 ₹{$amount}"
            : "🔔 *Agreement Pending!*\n\n👤 {$creatorName} is waiting\n💰 ₹{$amount}";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'confirm_agreement_' . ($data['agreement_id'] ?? ''), 'title' => '✅ Confirm'],
                ['id' => 'view_details', 'title' => '👀 Details'],
            ],
        ];
    }

    /**
     * Agreement due soon.
     */
    public static function agreementDueSoon(array $data, string $lang = self::LANG_EN): array
    {
        $otherParty = self::truncate($data['other_party'] ?? '', 20);
        $amount = number_format($data['amount'] ?? 0);
        $daysLeft = $data['days_remaining'] ?? 0;

        $message = ($lang === self::LANG_ML)
            ? "⏰ *Agreement Due Soon!*\n\n👤 {$otherParty} | ₹{$amount}\n📅 {$daysLeft} days left"
            : "⏰ *Agreement Due Soon!*\n\n👤 {$otherParty} | ₹{$amount}\n📅 {$daysLeft} days remaining";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_agreement_' . ($data['agreement_id'] ?? ''), 'title' => '👀 View'],
                ['id' => 'mark_complete', 'title' => '✅ Mark Done'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Pacha Meen (Fish Alert) Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Single fresh fish alert.
     */
    public static function fishAlert(array $data, string $lang = self::LANG_EN): array
    {
        $fishName = $data['fish_name'] ?? '';
        $sellerName = self::truncate($data['seller_name'] ?? '', 20);
        $price = $data['price_per_kg'] ?? 0;
        $distance = self::formatDistance($data['distance_km'] ?? 0);

        $message = ($lang === self::LANG_ML)
            ? "🐟 *Pacha {$fishName}!*\n\n📍 {$sellerName} — {$distance}\n💰 ₹{$price}/kg"
            : "🐟 *Fresh {$fishName}!*\n\n📍 {$sellerName} — {$distance}\n💰 ₹{$price}/kg";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'fish_coming_' . ($data['catch_id'] ?? ''), 'title' => "🔔 I'm Coming"],
                ['id' => 'fish_location_' . ($data['catch_id'] ?? ''), 'title' => '📍 Location'],
            ],
        ];
    }

    /**
     * Batch fish alerts summary.
     */
    public static function batchFishAlerts(array $alerts, string $lang = self::LANG_EN): array
    {
        $count = count($alerts);

        $message = ($lang === self::LANG_ML)
            ? "🐟 *{$count} Fish Alerts!*\n\n"
            : "🐟 *{$count} Fresh Catches!*\n\n";

        // Show first 3
        $shown = array_slice($alerts, 0, 3);
        foreach ($shown as $alert) {
            $fish = $alert['fish_name'] ?? '';
            $price = $alert['price_per_kg'] ?? 0;
            $message .= "• {$fish} — ₹{$price}/kg\n";
        }

        if ($count > 3) {
            $more = $count - 3;
            $message .= "\n+{$more} more...";
        }

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_all_fish', 'title' => "🐟 View All ({$count})"],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Fish sold out notification.
     */
    public static function fishSoldOut(array $data, string $lang = self::LANG_EN): array
    {
        $fishName = $data['fish_name'] ?? '';
        $sellerName = self::truncate($data['seller_name'] ?? '', 20);

        $message = ($lang === self::LANG_ML)
            ? "😔 *{$fishName} Sold Out*\n\n📍 {$sellerName}\nVere sellers nokkaam!"
            : "😔 *{$fishName} Sold Out*\n\n📍 {$sellerName}\nLet's find others!";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'find_alternatives', 'title' => '🔍 Find Others'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Njaanum Panikkar (Jobs) Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * New job available (to worker).
     */
    public static function newJob(array $data, string $lang = self::LANG_EN): array
    {
        $jobType = $data['job_type'] ?? '';
        $location = self::truncate($data['location'] ?? '', 25);
        $pay = number_format($data['pay'] ?? 0);
        $distance = self::formatDistance($data['distance_km'] ?? 0);

        $message = ($lang === self::LANG_ML)
            ? "👷 *Job Available!*\n\n🔧 {$jobType}\n📍 {$location} ({$distance})\n💰 ₹{$pay}"
            : "👷 *Job Available!*\n\n🔧 {$jobType}\n📍 {$location} ({$distance})\n💰 ₹{$pay}";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'apply_job_' . ($data['job_id'] ?? ''), 'title' => '✅ Apply'],
                ['id' => 'skip_job', 'title' => '⏭️ Skip'],
            ],
        ];
    }

    /**
     * Worker selected for job.
     */
    public static function jobSelected(array $data, string $lang = self::LANG_EN): array
    {
        $jobType = $data['job_type'] ?? '';
        $posterName = self::truncate($data['poster_name'] ?? '', 20);
        $location = self::truncate($data['location'] ?? '', 25);

        $message = ($lang === self::LANG_ML)
            ? "🎉 *Job Kitti!*\n\n🔧 {$jobType}\n👤 {$posterName}\n📍 {$location}"
            : "🎉 *You Got the Job!*\n\n🔧 {$jobType}\n👤 {$posterName}\n📍 {$location}";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_job_details', 'title' => '👀 Details'],
                ['id' => 'contact_poster', 'title' => '📞 Contact'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Flash Mob Deals Notifications (PRIORITY — NO BATCHING)
    |--------------------------------------------------------------------------
    */

    /**
     * New flash deal (urgent, time-sensitive).
     */
    public static function flashDealLive(array $data, string $lang = self::LANG_EN): array
    {
        $title = self::truncate($data['title'] ?? '', 30);
        $discount = $data['discount_percent'] ?? 0;
        $shopName = self::truncate($data['shop_name'] ?? '', 20);
        $minutesLeft = $data['minutes_left'] ?? 0;
        $currentClaims = $data['current_claims'] ?? 0;
        $targetClaims = $data['target_claims'] ?? 0;

        $message = ($lang === self::LANG_ML)
            ? "⚡ *FLASH DEAL!*\n\n🔥 {$discount}% OFF — {$title}\n🏪 {$shopName}\n⏰ {$minutesLeft} mins | {$currentClaims}/{$targetClaims}"
            : "⚡ *FLASH DEAL LIVE!*\n\n🔥 {$discount}% OFF — {$title}\n🏪 {$shopName}\n⏰ {$minutesLeft} mins | {$currentClaims}/{$targetClaims}";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'claim_deal_' . ($data['deal_id'] ?? ''), 'title' => "⚡ I'm In!"],
                ['id' => 'share_deal_' . ($data['deal_id'] ?? ''), 'title' => '📤 Share'],
            ],
            'priority' => 'high', // Flag for NotificationService
        ];
    }

    /**
     * Flash deal progress update.
     */
    public static function flashDealProgress(array $data, string $lang = self::LANG_EN): array
    {
        $title = self::truncate($data['title'] ?? '', 25);
        $currentClaims = $data['current_claims'] ?? 0;
        $targetClaims = $data['target_claims'] ?? 0;
        $remaining = $targetClaims - $currentClaims;
        $minutesLeft = $data['minutes_left'] ?? 0;

        $message = ($lang === self::LANG_ML)
            ? "⚡ *{$remaining} koodi venam!*\n\n{$title}\n⏰ {$minutesLeft} mins left\n📤 Share cheythu help cheyyuka!"
            : "⚡ *Just {$remaining} more needed!*\n\n{$title}\n⏰ {$minutesLeft} mins left\n📤 Share to help activate!";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'share_deal_' . ($data['deal_id'] ?? ''), 'title' => '📤 Share Now'],
                ['id' => 'view_deal', 'title' => '👀 View Deal'],
            ],
            'priority' => 'high',
        ];
    }

    /**
     * Flash deal activated!
     */
    public static function flashDealActivated(array $data, string $lang = self::LANG_EN): array
    {
        $title = self::truncate($data['title'] ?? '', 25);
        $couponCode = $data['coupon_code'] ?? '';
        $shopName = self::truncate($data['shop_name'] ?? '', 20);

        $message = ($lang === self::LANG_ML)
            ? "🎉 *DEAL ACTIVATED!*\n\n{$title}\n🎟️ Code: *{$couponCode}*\n🏪 {$shopName}"
            : "🎉 *DEAL ACTIVATED!*\n\n{$title}\n🎟️ Your code: *{$couponCode}*\n🏪 {$shopName}";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'get_directions_' . ($data['shop_id'] ?? ''), 'title' => '📍 Directions'],
                ['id' => 'share_success', 'title' => '📤 Share Win'],
            ],
            'priority' => 'high',
        ];
    }

    /**
     * Flash deal expired (target not met).
     */
    public static function flashDealExpired(array $data, string $lang = self::LANG_EN): array
    {
        $title = self::truncate($data['title'] ?? '', 30);
        $finalClaims = $data['final_claims'] ?? 0;
        $targetClaims = $data['target_claims'] ?? 0;

        $message = ($lang === self::LANG_ML)
            ? "😔 *Deal Expire Aayi*\n\n{$title}\n{$finalClaims}/{$targetClaims} — target ettiyilla"
            : "😔 *Deal Expired*\n\n{$title}\n{$finalClaims}/{$targetClaims} — target not reached";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'follow_shop_' . ($data['shop_id'] ?? ''), 'title' => '🔔 Follow Shop'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Generic Batch Summary
    |--------------------------------------------------------------------------
    */

    /**
     * Generic batch notification summary.
     */
    public static function batchSummary(int $count, string $type, string $lang = self::LANG_EN): array
    {
        $typeDisplay = match ($type) {
            'requests' => ($lang === self::LANG_ML) ? 'Product Requests' : 'Product Requests',
            'responses' => ($lang === self::LANG_ML) ? 'Responses' : 'Responses',
            'fish' => ($lang === self::LANG_ML) ? 'Fish Alerts' : 'Fish Alerts',
            'jobs' => ($lang === self::LANG_ML) ? 'Jobs' : 'Jobs',
            'offers' => ($lang === self::LANG_ML) ? 'Offers' : 'Offers',
            default => ($lang === self::LANG_ML) ? 'Updates' : 'Updates',
        };

        $emoji = match ($type) {
            'requests' => '📦',
            'responses' => '✅',
            'fish' => '🐟',
            'jobs' => '👷',
            'offers' => '🛍️',
            default => '🔔',
        };

        $message = ($lang === self::LANG_ML)
            ? "{$emoji} *{$count} {$typeDisplay}!*\n\nTap to view all."
            : "{$emoji} *{$count} New {$typeDisplay}!*\n\nTap to view all.";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => "view_all_{$type}", 'title' => "👀 View All ({$count})"],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Welcome Back / Re-engagement
    |--------------------------------------------------------------------------
    */

    /**
     * Welcome back after inactivity.
     */
    public static function welcomeBack(array $data, string $lang = self::LANG_EN): array
    {
        $name = self::truncate($data['name'] ?? '', 20);
        $pendingCount = $data['pending_count'] ?? 0;

        $message = ($lang === self::LANG_ML)
            ? "👋 *Welcome Back, {$name}!*\n\n🔔 {$pendingCount} updates waiting"
            : "👋 *Welcome Back, {$name}!*\n\n🔔 {$pendingCount} updates waiting";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'view_updates', 'title' => "🔔 View ({$pendingCount})"],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Format message with placeholders.
     */
    public static function format(string $template, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $template = str_replace("{{$key}}", (string) $value, $template);
        }
        return $template;
    }

    /**
     * Format distance for display.
     */
    public static function formatDistance(float $distanceKm): string
    {
        if ($distanceKm < 1) {
            return round($distanceKm * 1000) . 'm';
        }
        return round($distanceKm, 1) . 'km';
    }

    /**
     * Format time remaining.
     */
    public static function formatTimeRemaining($expiresAt): string
    {
        if (!$expiresAt) {
            return 'soon';
        }

        $expiresAt = $expiresAt instanceof Carbon ? $expiresAt : Carbon::parse($expiresAt);

        if ($expiresAt->isPast()) {
            return 'expired';
        }

        $diff = now()->diff($expiresAt);

        if ($diff->days > 0) {
            return $diff->days . 'd';
        }
        if ($diff->h > 0) {
            return $diff->h . 'h';
        }
        return $diff->i . 'm';
    }

    /**
     * Truncate string with ellipsis.
     */
    public static function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }
        return mb_substr($text, 0, $maxLength - 1) . '…';
    }

    /**
     * Build request list for batch messages.
     */
    public static function buildRequestList(array $requests): string
    {
        $lines = [];
        $emojis = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣'];

        foreach (array_slice($requests, 0, 5) as $index => $request) {
            $emoji = $emojis[$index] ?? ($index + 1) . '.';
            $description = self::truncate($request['description'] ?? '', 25);
            $distance = self::formatDistance($request['distance_km'] ?? 0);
            $lines[] = "{$emoji} {$description} — {$distance}";
        }

        return implode("\n", $lines);
    }
}