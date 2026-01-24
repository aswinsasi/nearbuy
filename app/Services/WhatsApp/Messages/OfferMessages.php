<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use Carbon\Carbon;

/**
 * Message templates for Offers module.
 *
 * Contains all user-facing messages for offer upload and browsing.
 *
 * ENHANCEMENTS:
 * - Localization support (English + Malayalam)
 * - Better formatting with distance/time helpers
 * - Consistent emoji usage
 * - WhatsApp character limit awareness
 *
 * @see SRS Section 3.2 - Offers Management
 */
class OfferMessages
{
    /*
    |--------------------------------------------------------------------------
    | Upload Flow Messages
    |--------------------------------------------------------------------------
    */

    public const UPLOAD_START = "📤 *Upload New Offer*\n\n" .
        "Send an image or PDF of your offer.\n\n" .
        "📸 Supported: JPG, PNG, PDF\n" .
        "📏 Max size: 5MB\n\n" .
        "_Tip: Clear photos get more views!_";

    public const UPLOAD_RECEIVED = "✅ Media received!\n\n" .
        "Now add a caption (optional):\n" .
        "• What's on offer?\n" .
        "• Any special prices?\n\n" .
        "Or type *skip* to continue without caption.";

    public const ASK_CAPTION = "📝 *Add Caption*\n\n" .
        "Describe your offer (max 500 chars):\n\n" .
        "_Example: Fresh vegetables 20% off today! Tomatoes ₹30/kg, Onions ₹25/kg_\n\n" .
        "Or type *skip* to continue.";

    public const ASK_VALIDITY = "⏰ *How Long?*\n\n" .
        "How long should this offer be visible?";

    public const UPLOAD_CONFIRM = "📋 *Review Your Offer*\n\n" .
        "{caption}\n\n" .
        "⏰ Valid: {validity}\n" .
        "👥 Reach: ~{reach} nearby customers\n\n" .
        "Ready to publish?";

    public const UPLOAD_SUCCESS = "🎉 *Offer Published!*\n\n" .
        "Your offer is now visible to customers within {radius}km.\n\n" .
        "📊 Estimated reach: ~{reach} customers\n" .
        "⏰ Expires: {expiry_date}\n\n" .
        "_We'll notify you when customers view it._";

    public const UPLOAD_CANCELLED = "❌ Upload cancelled.\n\n" .
        "You can upload anytime from the main menu.";

    public const MAX_OFFERS_REACHED = "⚠️ *Limit Reached*\n\n" .
        "You have {max} active offers (maximum allowed).\n\n" .
        "Delete an existing offer to upload a new one.";

    public const INVALID_MEDIA = "⚠️ *Invalid File*\n\n" .
        "Please send an image (JPG, PNG) or PDF.\n" .
        "Max size: 5MB";

    public const CAPTION_TOO_LONG = "⚠️ Caption too long!\n\n" .
        "Please keep it under 500 characters.";

    /*
    |--------------------------------------------------------------------------
    | Browse Flow Messages (FR-OFR-10 to FR-OFR-16)
    |--------------------------------------------------------------------------
    */

    public const BROWSE_START = "🛍️ *Browse Offers*\n\n" .
        "Select a category to see offers from nearby shops:";

    public const BROWSE_NO_LOCATION = "📍 *Location Needed*\n\n" .
        "Share your location to see nearby offers.\n\n" .
        "🔒 _Your exact location stays private._";

    public const SELECT_CATEGORY = "📦 *Select Category*\n\n" .
        "What are you looking for?";

    public const SELECT_RADIUS = "📍 *Search Distance*\n\n" .
        "How far would you like to search?";

    // FR-OFR-13: Display shop list with distance and validity
    public const OFFERS_LIST_HEADER = "🛍️ *{category}*\n\n" .
        "Found {count} offer(s) near you:";

    public const NO_OFFERS_IN_CATEGORY = "😕 *No Offers Found*\n\n" .
        "No offers in *{category}* within {radius}km.\n\n" .
        "Try:\n" .
        "• Different category\n" .
        "• Larger search radius";

    public const NO_OFFERS_NEARBY = "😕 *No Nearby Offers*\n\n" .
        "No active offers within {radius}km.\n\n" .
        "Try expanding your search radius.";

    /*
    |--------------------------------------------------------------------------
    | Offer Display Messages (FR-OFR-14)
    |--------------------------------------------------------------------------
    */

    public const OFFER_CARD = "🏪 *{shop_name}*\n" .
        "📍 {distance} away\n" .
        "⏰ Valid till {expiry}\n\n" .
        "{caption}";

    public const OFFER_CARD_NO_CAPTION = "🏪 *{shop_name}*\n" .
        "📍 {distance} away\n" .
        "⏰ Valid till {expiry}";

    // FR-OFR-16: Send shop location
    public const SHOP_LOCATION_SENT = "📍 *{shop_name}*\n\n" .
        "Tap to open in Maps and get directions.";

    public const SHOP_CONTACT = "📞 *Contact {shop_name}*\n\n" .
        "Phone: {phone}\n\n" .
        "_Tap number to call_";

    /*
    |--------------------------------------------------------------------------
    | Manage Offers Messages
    |--------------------------------------------------------------------------
    */

    public const MY_OFFERS_HEADER = "🏷️ *My Offers*\n\n" .
        "You have {count} active offer(s):";

    public const MY_OFFERS_EMPTY = "📭 *No Active Offers*\n\n" .
        "Upload an offer to attract nearby customers!";

    public const OFFER_STATS = "📊 *Offer Performance*\n\n" .
        "👁️ Views: {views}\n" .
        "📍 Location taps: {location_taps}\n" .
        "⏰ Expires: {expiry}";

    public const DELETE_CONFIRM = "🗑️ *Delete Offer?*\n\n" .
        "This cannot be undone.";

    public const OFFER_DELETED = "✅ Offer deleted.";

    public const OFFER_EXPIRED = "⏰ This offer has expired.";

    /*
    |--------------------------------------------------------------------------
    | Button Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * Validity selection buttons.
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
     * Upload confirmation buttons.
     */
    public static function getConfirmButtons(): array
    {
        return [
            ['id' => 'publish', 'title' => '✅ Publish'],
            ['id' => 'edit', 'title' => '✏️ Edit Caption'],
            ['id' => 'cancel', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * Offer action buttons (FR-OFR-15).
     */
    public static function getOfferActionButtons(): array
    {
        return [
            ['id' => 'location', 'title' => '📍 Get Location'],
            ['id' => 'contact', 'title' => '📞 Call Shop'],
            ['id' => 'back', 'title' => '⬅️ More Offers'],
        ];
    }

    /**
     * Offer management buttons.
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
     * Radius selection buttons.
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
     * Delete confirmation buttons.
     */
    public static function getDeleteConfirmButtons(): array
    {
        return [
            ['id' => 'confirm_delete', 'title' => '🗑️ Yes, Delete'],
            ['id' => 'cancel_delete', 'title' => '❌ Keep It'],
        ];
    }

    /**
     * Post-upload action buttons.
     */
    public static function getPostUploadButtons(): array
    {
        return [
            ['id' => 'upload_another', 'title' => '📤 Upload Another'],
            ['id' => 'my_offers', 'title' => '🏷️ My Offers'],
            ['id' => 'menu', 'title' => '🏠 Main Menu'],
        ];
    }

    /**
     * No offers found buttons.
     */
    public static function getNoOffersButtons(): array
    {
        return [
            ['id' => 'change_radius', 'title' => '📍 Change Radius'],
            ['id' => 'change_category', 'title' => '📦 Other Category'],
            ['id' => 'menu', 'title' => '🏠 Main Menu'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | List Configurations (Max 10 items per WhatsApp API)
    |--------------------------------------------------------------------------
    */

    /**
     * Get category list sections with offer counts.
     * FR-OFR-10: Display category list with offer counts per category.
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

        $rows = array_map(function ($cat) use ($categoryCounts) {
            // Each category shows its own count (0 if no offers in that category)
            $count = $categoryCounts[$cat['id']] ?? 0;
            $countText = $count > 0 ? "{$count} offer" . ($count > 1 ? 's' : '') : 'No offers';

            return [
                'id' => $cat['id'],
                'title' => "{$cat['icon']} {$cat['name']}",
                'description' => $countText,
            ];
        }, $categories);

        return [
            [
                'title' => 'Shop Categories',
                'rows' => $rows,
            ],
        ];
    }

    /**
     * Build offers list for display.
     * FR-OFR-13: Display shop list with distance and validity information.
     */
    public static function buildOffersList(array $offers): array
    {
        $rows = [];

        foreach ($offers as $index => $offer) {
            $shop = $offer['shop'] ?? null;
            $shopName = $shop['shop_name'] ?? 'Shop';
            $distance = isset($offer['distance_km']) ? self::formatDistance($offer['distance_km']) : '';
            $expiry = isset($offer['expires_at']) ? self::formatExpiry($offer['expires_at']) : '';

            $rows[] = [
                'id' => 'offer_' . ($offer['id'] ?? $index),
                'title' => self::truncate($shopName, 24),
                'description' => self::truncate("{$distance} • {$expiry}", 72),
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
            $caption = $offer['caption'] ?? 'Offer #' . ($index + 1);
            $expiry = isset($offer['expires_at']) ? self::formatExpiry($offer['expires_at']) : 'N/A';

            $rows[] = [
                'id' => 'manage_' . ($offer['id'] ?? $index),
                'title' => self::truncate($caption, 24),
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
     * FR-OFR-12: Sort results by distance (nearest first).
     */
    public static function formatDistance(float $distanceKm): string
    {
        if ($distanceKm < 0.1) {
            return 'Very close';
        }

        if ($distanceKm < 1) {
            $meters = round($distanceKm * 1000, -1); // Round to nearest 10m
            return "{$meters}m";
        }

        return round($distanceKm, 1) . 'km';
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
    public static function formatExpiry(Carbon|string|null $expiresAt): string
    {
        if (!$expiresAt) {
            return 'Unknown';
        }

        if (is_string($expiresAt)) {
            $expiresAt = Carbon::parse($expiresAt);
        }

        if ($expiresAt->isPast()) {
            return 'Expired';
        }

        if ($expiresAt->isToday()) {
            return 'Today ' . $expiresAt->format('g:i A');
        }

        if ($expiresAt->isTomorrow()) {
            return 'Tomorrow';
        }

        if ($expiresAt->diffInDays(now()) < 7) {
            return $expiresAt->format('l'); // Day name
        }

        return $expiresAt->format('M j');
    }

    /**
     * Get human-readable category label.
     */
    public static function getCategoryLabel(string $categoryId): string
    {
        $labels = [
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

        return $labels[strtolower($categoryId)] ?? ucfirst($categoryId);
    }

    /**
     * Build offer card message for display.
     * FR-OFR-14: Send offer image with caption containing shop details.
     */
    public static function buildOfferCard(array $offer, float $distanceKm): string
    {
        $shopName = $offer['shop']['shop_name'] ?? 'Shop';
        $distance = self::formatDistance($distanceKm);
        $expiry = self::formatExpiry($offer['expires_at'] ?? null);
        $caption = $offer['caption'] ?? '';

        $template = empty($caption) ? self::OFFER_CARD_NO_CAPTION : self::OFFER_CARD;

        return self::format($template, [
            'shop_name' => $shopName,
            'distance' => $distance,
            'expiry' => $expiry,
            'caption' => $caption,
        ]);
    }

    /**
     * Truncate string to fit WhatsApp limits.
     */
    public static function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1) . '…';
    }

    /*
    |--------------------------------------------------------------------------
    | Localization Support
    |--------------------------------------------------------------------------
    */

    /**
     * Get message in specified language.
     */
    public static function get(string $key, string $lang = 'en'): string
    {
        $messages = match ($lang) {
            'ml' => self::getMalayalamMessages(),
            default => self::getEnglishMessages(),
        };

        return $messages[$key] ?? self::getEnglishMessages()[$key] ?? "Message not found: {$key}";
    }

    /**
     * English messages.
     */
    protected static function getEnglishMessages(): array
    {
        return [
            'browse_start' => self::BROWSE_START,
            'no_location' => self::BROWSE_NO_LOCATION,
            'no_offers' => self::NO_OFFERS_IN_CATEGORY,
            'shop_location' => self::SHOP_LOCATION_SENT,
        ];
    }

    /**
     * Malayalam messages.
     */
    protected static function getMalayalamMessages(): array
    {
        return [
            'browse_start' => "🛍️ *ഓഫറുകൾ കാണുക*\n\n" .
                "സമീപത്തുള്ള ഷോപ്പുകളിൽ നിന്നുള്ള ഓഫറുകൾ കാണാൻ ഒരു വിഭാഗം തിരഞ്ഞെടുക്കുക:",
            'no_location' => "📍 *ലൊക്കേഷൻ ആവശ്യമാണ്*\n\n" .
                "സമീപത്തുള്ള ഓഫറുകൾ കാണാൻ നിങ്ങളുടെ ലൊക്കേഷൻ പങ്കിടുക.",
            'no_offers' => "😕 *ഓഫറുകൾ കണ്ടെത്തിയില്ല*\n\n" .
                "{radius}km ഉള്ളിൽ *{category}* വിഭാഗത്തിൽ ഓഫറുകളില്ല.",
            'shop_location' => "📍 *{shop_name}*\n\n" .
                "മാപ്പിൽ തുറക്കാൻ ടാപ്പ് ചെയ്യുക.",
        ];
    }
}