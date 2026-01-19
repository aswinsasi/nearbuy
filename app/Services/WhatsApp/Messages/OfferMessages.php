<?php

namespace App\Services\WhatsApp\Messages;

use App\Models\Offer;
use App\Models\Shop;

/**
 * Message templates for Offers module.
 *
 * Contains all user-facing messages for offer upload and browsing.
 */
class OfferMessages
{
    /*
    |--------------------------------------------------------------------------
    | Upload Flow Messages
    |--------------------------------------------------------------------------
    */

    public const UPLOAD_START = "📤 *Upload New Offer*\n\nSend an image or PDF of your offer.\n\n📸 Supported formats: JPG, PNG, PDF\n📏 Max size: 5MB";

    public const UPLOAD_RECEIVED = "✅ Media received!\n\nNow add a caption for your offer (optional).\n\nType your caption or send 'skip' to continue without one.";

    public const ASK_CAPTION = "📝 Add a caption for your offer:\n\n• Describe what's on offer\n• Include prices if applicable\n• Max 500 characters\n\nOr type 'skip' to continue without a caption.";

    public const ASK_VALIDITY = "⏰ *Offer Validity*\n\nHow long should this offer be valid?";

    public const UPLOAD_CONFIRM = "📋 *Review Your Offer*\n\n{caption}\n\n⏰ *Valid:* {validity}\n👥 *Estimated reach:* ~{reach} customers\n\nReady to publish?";

    public const UPLOAD_SUCCESS = "🎉 *Offer Published!*\n\nYour offer is now live and visible to customers within {radius}km.\n\n📊 *Estimated reach:* ~{reach} customers\n⏰ *Expires:* {expiry_date}\n\nYou'll receive notifications when customers view your offer.";

    public const UPLOAD_CANCELLED = "❌ Offer upload cancelled.\n\nYou can upload a new offer anytime from the main menu.";

    public const MAX_OFFERS_REACHED = "⚠️ *Limit Reached*\n\nYou've reached the maximum of {max} active offers.\n\nPlease delete an existing offer to upload a new one.";

    public const INVALID_MEDIA = "⚠️ Invalid file type.\n\nPlease send an *image* (JPG, PNG) or *PDF* file.\n\nMax size: 5MB";

    public const CAPTION_TOO_LONG = "⚠️ Caption is too long (max 500 characters).\n\nPlease shorten your caption and try again.";

    /*
    |--------------------------------------------------------------------------
    | Browse Flow Messages
    |--------------------------------------------------------------------------
    */

    public const BROWSE_START = "🛍️ *Browse Offers*\n\nSelect a category to see offers from nearby shops:";

    public const BROWSE_NO_LOCATION = "📍 *Location Required*\n\nTo see nearby offers, please share your location first.";

    public const SELECT_CATEGORY = "📦 *Select Category*\n\nChoose a category to browse offers:";

    public const NO_OFFERS_IN_CATEGORY = "😕 *No Offers Found*\n\nNo offers in *{category}* within {radius}km.\n\nTry a different category or expand your search.";

    public const OFFERS_LIST_HEADER = "🛍️ *{category} Offers*\n\nFound {count} offer(s) near you:";

    public const SELECT_RADIUS = "📍 *Search Radius*\n\nHow far would you like to search?";

    public const NO_OFFERS_NEARBY = "😕 *No Offers Nearby*\n\nNo active offers found within {radius}km.\n\nTry expanding your search radius.";

    /*
    |--------------------------------------------------------------------------
    | Offer Display Messages
    |--------------------------------------------------------------------------
    */

    public const OFFER_CARD = "🏪 *{shop_name}*\n📍 {distance} away\n⏰ Valid till {expiry}\n\n{caption}";

    public const OFFER_CARD_NO_CAPTION = "🏪 *{shop_name}*\n📍 {distance} away\n⏰ Valid till {expiry}";

    public const OFFER_VIEWED = "👁️ Offer from *{shop_name}* viewed";

    public const SHOP_LOCATION_SENT = "📍 *{shop_name}*\n\nHere's the shop location. Tap to open in maps.";

    public const SHOP_CONTACT = "📞 *Contact {shop_name}*\n\nPhone: {phone}\n\nTap the number to call or save to contacts.";

    /*
    |--------------------------------------------------------------------------
    | Manage Offers Messages
    |--------------------------------------------------------------------------
    */

    public const MY_OFFERS_HEADER = "🏷️ *My Offers*\n\nYou have {count} active offer(s):";

    public const MY_OFFERS_EMPTY = "📭 *No Active Offers*\n\nYou don't have any active offers.\n\nUpload a new offer to attract customers!";

    public const OFFER_STATS = "📊 *Offer Stats*\n\n👁️ Views: {views}\n📍 Location taps: {location_taps}\n⏰ Expires: {expiry}";

    public const DELETE_CONFIRM = "🗑️ *Delete Offer?*\n\nAre you sure you want to delete this offer?\n\nThis action cannot be undone.";

    public const OFFER_DELETED = "✅ Offer deleted successfully.";

    public const OFFER_EXPIRED = "⏰ This offer has expired and is no longer visible to customers.";

    /*
    |--------------------------------------------------------------------------
    | Button Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * Get validity selection buttons.
     */
    public static function getValidityButtons(): array
    {
        return [
            ['id' => 'today', 'title' => '📅 Today Only'],
            ['id' => '3days', 'title' => '📆 3 Days'],
            ['id' => 'week', 'title' => '🗓️ This Week'],
        ];
    }

    /**
     * Get upload confirmation buttons.
     */
    public static function getConfirmButtons(): array
    {
        return [
            ['id' => 'publish', 'title' => '✅ Publish'],
            ['id' => 'edit', 'title' => '✏️ Edit'],
            ['id' => 'cancel', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * Get offer action buttons.
     */
    public static function getOfferActionButtons(): array
    {
        return [
            ['id' => 'location', 'title' => '📍 Get Location'],
            ['id' => 'contact', 'title' => '📞 Contact Shop'],
            ['id' => 'back', 'title' => '⬅️ More Offers'],
        ];
    }

    /**
     * Get offer management buttons.
     */
    public static function getManageButtons(): array
    {
        return [
            ['id' => 'stats', 'title' => '📊 View Stats'],
            ['id' => 'delete', 'title' => '🗑️ Delete'],
            ['id' => 'back', 'title' => '⬅️ Back'],
        ];
    }

    /**
     * Get radius selection buttons.
     */
    public static function getRadiusButtons(): array
    {
        return [
            ['id' => '2', 'title' => '📍 2 km'],
            ['id' => '5', 'title' => '📍 5 km'],
            ['id' => '10', 'title' => '📍 10 km'],
        ];
    }

    /**
     * Get delete confirmation buttons.
     */
    public static function getDeleteConfirmButtons(): array
    {
        return [
            ['id' => 'confirm_delete', 'title' => '🗑️ Yes, Delete'],
            ['id' => 'cancel_delete', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * Get next action buttons after upload.
     */
    public static function getPostUploadButtons(): array
    {
        return [
            ['id' => 'upload_another', 'title' => '📤 Upload Another'],
            ['id' => 'my_offers', 'title' => '🏷️ My Offers'],
            ['id' => 'menu', 'title' => '🏠 Main Menu'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | List Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * Get category list sections with offer counts.
     */
    public static function getCategorySections(array $categoryCounts = []): array
    {
        $categories = [
            ['id' => 'all', 'icon' => '🔍', 'name' => 'All Categories'],
            ['id' => 'grocery', 'icon' => '🛒', 'name' => 'Grocery'],
            ['id' => 'electronics', 'icon' => '📱', 'name' => 'Electronics'],
            ['id' => 'clothes', 'icon' => '👕', 'name' => 'Clothes'],
            ['id' => 'medical', 'icon' => '💊', 'name' => 'Medical'],
            ['id' => 'restaurant', 'icon' => '🍽️', 'name' => 'Restaurant'],
            ['id' => 'furniture', 'icon' => '🪑', 'name' => 'Furniture'],
            ['id' => 'beauty', 'icon' => '💄', 'name' => 'Beauty'],
            ['id' => 'hardware', 'icon' => '🔧', 'name' => 'Hardware'],
            ['id' => 'automotive', 'icon' => '🚗', 'name' => 'Automotive'],
        ];

        $formatRow = function ($cat) use ($categoryCounts) {
            $count = $categoryCounts[$cat['id']] ?? 0;
            $countText = $count > 0 ? "{$count} offers" : 'No offers';
            return [
                'id' => $cat['id'],
                'title' => "{$cat['icon']} {$cat['name']}",
                'description' => $countText,
            ];
        };

        return [
            [
                'title' => 'Shop Categories',
                'rows' => array_map($formatRow, $categories),
            ],
        ];
    }

    /**
     * Build offers list for a category.
     */
    public static function buildOffersList(array $offers): array
    {
        $rows = [];

        foreach ($offers as $index => $offer) {
            $shop = $offer['shop'] ?? null;
            $distance = isset($offer['distance']) ? self::formatDistance($offer['distance']) : '';

            $rows[] = [
                'id' => 'offer_' . ($offer['id'] ?? $index),
                'title' => self::truncate($shop['shop_name'] ?? 'Shop', 24),
                'description' => self::truncate("{$distance} • Valid till " . ($offer['expiry'] ?? 'N/A'), 72),
            ];
        }

        return [
            [
                'title' => 'Nearby Offers',
                'rows' => array_slice($rows, 0, 10), // WhatsApp limit
            ],
        ];
    }

    /**
     * Build my offers list for shop owner.
     */
    public static function buildMyOffersList(array $offers): array
    {
        $rows = [];

        foreach ($offers as $index => $offer) {
            $views = $offer['view_count'] ?? 0;
            $expiry = $offer['expires_at'] ?? 'N/A';

            $rows[] = [
                'id' => 'manage_' . ($offer['id'] ?? $index),
                'title' => self::truncate($offer['caption'] ?? 'Offer #' . ($index + 1), 24),
                'description' => self::truncate("👁️ {$views} views • Expires: {$expiry}", 72),
            ];
        }

        return [
            [
                'title' => 'Your Active Offers',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Format a message with placeholders.
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
            $meters = round($distanceKm * 1000);
            return "{$meters}m";
        }

        return round($distanceKm, 1) . "km";
    }

    /**
     * Format validity for display.
     */
    public static function formatValidity(string $validityId): string
    {
        return match ($validityId) {
            'today' => 'Today only',
            '3days' => '3 days',
            'week' => 'This week',
            default => $validityId,
        };
    }

    /**
     * Format expiry date for display.
     */
    public static function formatExpiry(\Carbon\Carbon|string $expiresAt): string
    {
        if (is_string($expiresAt)) {
            $expiresAt = \Carbon\Carbon::parse($expiresAt);
        }

        if ($expiresAt->isToday()) {
            return 'Today ' . $expiresAt->format('h:i A');
        }

        if ($expiresAt->isTomorrow()) {
            return 'Tomorrow';
        }

        return $expiresAt->format('M j');
    }

    /**
     * Get category label.
     */
    public static function getCategoryLabel(string $categoryId): string
    {
        $map = [
            'grocery' => '🛒 Grocery',
            'electronics' => '📱 Electronics',
            'clothes' => '👕 Clothes',
            'medical' => '💊 Medical',
            'furniture' => '🪑 Furniture',
            'mobile' => '📲 Mobile',
            'appliances' => '🔌 Appliances',
            'hardware' => '🔧 Hardware',
            'restaurant' => '🍽️ Restaurant',
            'bakery' => '🍞 Bakery',
            'stationery' => '📚 Stationery',
            'beauty' => '💄 Beauty',
            'automotive' => '🚗 Automotive',
            'jewelry' => '💍 Jewelry',
            'sports' => '⚽ Sports',
            'all' => '🔍 All',
            'other' => '📦 Other',
        ];

        return $map[$categoryId] ?? ucfirst($categoryId);
    }

    /**
     * Build offer card message.
     */
    public static function buildOfferCard(array $offer, float $distanceKm): string
    {
        $shopName = $offer['shop']['shop_name'] ?? 'Shop';
        $distance = self::formatDistance($distanceKm);
        $expiry = self::formatExpiry($offer['expires_at']);
        $caption = $offer['caption'] ?? '';

        if (empty($caption)) {
            return self::format(self::OFFER_CARD_NO_CAPTION, [
                'shop_name' => $shopName,
                'distance' => $distance,
                'expiry' => $expiry,
            ]);
        }

        return self::format(self::OFFER_CARD, [
            'shop_name' => $shopName,
            'distance' => $distance,
            'expiry' => $expiry,
            'caption' => $caption,
        ]);
    }

    /**
     * Truncate string to fit WhatsApp limits.
     */
    private static function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1) . '…';
    }
}