<?php

namespace App\Services\WhatsApp\Messages;

/**
 * Message templates for Notifications module.
 *
 * Contains all notification-related message templates.
 */
class NotificationMessages
{
    /*
    |--------------------------------------------------------------------------
    | Product Request Notifications (to Shops)
    |--------------------------------------------------------------------------
    */

    public const NEW_REQUEST_SINGLE = "🔔 *New Product Request*\n\n📦 *{description}*\n📍 {distance} away\n⏰ Expires in {expires_in}\n\n#{request_number}";

    public const NEW_REQUESTS_BATCH = "🔔 *{count} New Product Requests*\n\n{request_list}\n\nReply with the number to respond, or type \"skip all\" to skip.";

    public const REQUEST_ITEM = "{number}️⃣ {description} - {distance}";

    public const REQUEST_EXPIRING_SOON = "⏰ *Request Expiring Soon*\n\n📦 {description}\n⏳ Expires in {expires_in}\n\nRespond now before it expires!";

    /*
    |--------------------------------------------------------------------------
    | Response Notifications (to Customers)
    |--------------------------------------------------------------------------
    */

    public const NEW_RESPONSE = "✅ *New Response to Your Request*\n\n🏪 *{shop_name}* has responded:\n\n💰 Price: ₹{price}\n📍 {distance} away\n{description}\n\nRequest: {request_description}";

    public const MULTIPLE_RESPONSES = "🎉 *{count} Shops Responded!*\n\nYour request for \"{request_description}\" has {count} response(s).\n\nTap below to view all responses.";

    public const REQUEST_EXPIRED = "⏰ *Request Expired*\n\nYour request for \"{description}\" has expired.\n\n{response_count} shop(s) responded.\n\nWould you like to create a new request?";

    public const NO_RESPONSES = "😔 *No Responses Yet*\n\nYour request for \"{description}\" hasn't received any responses yet.\n\nTry expanding your search radius or modifying your request.";

    /*
    |--------------------------------------------------------------------------
    | Offer Notifications
    |--------------------------------------------------------------------------
    */

    public const OFFER_EXPIRING = "⏰ *Offer Expiring Soon*\n\n📢 Your offer \"{title}\" will expire in {expires_in}.\n\nRenew it to keep attracting customers!";

    public const OFFER_EXPIRED = "❌ *Offer Expired*\n\n📢 Your offer \"{title}\" has expired.\n\nCreate a new offer to continue promoting your shop.";

    public const OFFER_VIEWS_MILESTONE = "🎉 *Milestone Reached!*\n\nYour offer \"{title}\" has reached {views} views!\n\nKeep the momentum going.";

    /*
    |--------------------------------------------------------------------------
    | Agreement Notifications
    |--------------------------------------------------------------------------
    */

    public const AGREEMENT_REMINDER = "🔔 *Agreement Reminder*\n\n{creator_name} is waiting for your confirmation on agreement #{agreement_number}.\n\n💰 Amount: ₹{amount}\n📅 Due: {due_date}\n\nPlease respond to confirm or reject.";

    public const AGREEMENT_DUE_SOON = "⏰ *Agreement Due Soon*\n\nYour agreement with {other_party} for ₹{amount} is due in {days_remaining} days.\n\nAgreement #: {agreement_number}";

    public const AGREEMENT_OVERDUE = "⚠️ *Agreement Overdue*\n\nYour agreement with {other_party} for ₹{amount} was due on {due_date}.\n\nAgreement #: {agreement_number}";

    /*
    |--------------------------------------------------------------------------
    | System Notifications
    |--------------------------------------------------------------------------
    */

    public const DAILY_SUMMARY_SHOP = "📊 *Daily Summary*\n\n🔔 New requests: {new_requests}\n✅ Your responses: {responses_sent}\n👀 Profile views: {profile_views}\n\nKeep up the great work!";

    public const WEEKLY_SUMMARY_SHOP = "📊 *Weekly Summary*\n\n🔔 Total requests: {total_requests}\n✅ Responses sent: {responses_sent}\n⭐ Average rating: {avg_rating}\n👀 Profile views: {profile_views}";

    public const WELCOME_BACK = "👋 *Welcome Back!*\n\nWe missed you! Here's what's new:\n\n🔔 {pending_requests} pending requests in your area\n📢 Check out new features\n\nType \"menu\" to get started.";

    /*
    |--------------------------------------------------------------------------
    | Buttons
    |--------------------------------------------------------------------------
    */

    public static function getRespondButtons(): array
    {
        return [
            ['id' => 'respond', 'title' => '✅ Respond'],
            ['id' => 'skip', 'title' => '⏭️ Skip'],
            ['id' => 'view_all', 'title' => '📋 View All'],
        ];
    }

    public static function getViewResponsesButtons(): array
    {
        return [
            ['id' => 'view_responses', 'title' => '👀 View Responses'],
            ['id' => 'new_request', 'title' => '🔄 New Request'],
            ['id' => 'menu', 'title' => '🏠 Menu'],
        ];
    }

    public static function getExpiredRequestButtons(): array
    {
        return [
            ['id' => 'view_responses', 'title' => '👀 View Responses'],
            ['id' => 'create_new', 'title' => '➕ New Request'],
            ['id' => 'menu', 'title' => '🏠 Menu'],
        ];
    }

    public static function getRenewOfferButtons(): array
    {
        return [
            ['id' => 'renew', 'title' => '🔄 Renew Offer'],
            ['id' => 'edit', 'title' => '✏️ Edit & Renew'],
            ['id' => 'delete', 'title' => '🗑️ Delete'],
        ];
    }

    public static function getAgreementReminderButtons(): array
    {
        return [
            ['id' => 'confirm', 'title' => '✅ Confirm'],
            ['id' => 'reject', 'title' => '❌ Reject'],
            ['id' => 'view', 'title' => '👀 View Details'],
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
     * Build batch request list.
     */
    public static function buildRequestList(array $requests): string
    {
        $lines = [];
        $emojis = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];

        foreach ($requests as $index => $request) {
            $emoji = $emojis[$index] ?? ($index + 1) . '.';
            $description = self::truncate($request['description'] ?? '', 30);
            $distance = self::formatDistance($request['distance_km'] ?? 0);

            $lines[] = "{$emoji} {$description} - {$distance}";
        }

        return implode("\n", $lines);
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
    public static function formatTimeRemaining(\Carbon\Carbon $expiresAt): string
    {
        $now = now();

        if ($expiresAt->isPast()) {
            return 'expired';
        }

        $diff = $now->diff($expiresAt);

        if ($diff->days > 0) {
            return $diff->days . ' day' . ($diff->days > 1 ? 's' : '');
        }

        if ($diff->h > 0) {
            return $diff->h . ' hour' . ($diff->h > 1 ? 's' : '');
        }

        return $diff->i . ' minute' . ($diff->i > 1 ? 's' : '');
    }

    /**
     * Truncate string.
     */
    private static function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1) . '…';
    }
}