<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use App\Enums\OfferValidity;
use Carbon\Carbon;

/**
 * Offer Messages - Short, bilingual, Kerala-friendly.
 *
 * DESIGN PRINCIPLES:
 * - Every message MAX 2-3 lines
 * - Malayalam + English mix (how Keralites actually text)
 * - Clear next action
 * - Minimal friction
 *
 * @srs-ref FR-OFR-01 to FR-OFR-06
 */
class OfferMessages
{
    /*
    |--------------------------------------------------------------------------
    | Upload Flow Messages (Simplified)
    |--------------------------------------------------------------------------
    */

    /**
     * Step 1: Ask for image/PDF.
     * FR-OFR-01: Accept image (JPEG, PNG) AND PDF uploads
     */
    public const ASK_IMAGE = "🛍️ Offer upload cheyyaam!\n\n" .
        "📸 Photo or PDF ayakkuka";

    /**
     * Invalid media type error.
     */
    public const INVALID_MEDIA = "⚠️ JPEG, PNG, or PDF files mathram upload cheyyaan pattuu.\n\n" .
        "📸 Photo or PDF ayakkuka";

    /**
     * Media processing in progress.
     */
    public const PROCESSING = "⏳ Processing...";

    /**
     * Step 2: Ask validity.
     * FR-OFR-04: Prompt validity period (Today / 3 Days / This Week)
     */
    public const ASK_VALIDITY = "✅ Photo saved!\n\n" .
        "⏰ Evide vare valid?";

    /**
     * Invalid validity selection.
     */
    public const INVALID_VALIDITY = "👆 Button tap cheyyuka please";

    /**
     * Step 3: Success message.
     * FR-OFR-05: Confirm publication, show estimated customer reach
     * FR-OFR-06: Track offer view counts and location tap metrics
     */
    public const SUCCESS = "✅ *Offer live aayi!* 🎉\n\n" .
        "📊 ~{reach} customers nearby kaanum\n" .
        "⏰ Valid till: {expiry}\n\n" .
        "👀 Views: 0 | 📍 Taps: 0\n" .
        "_Stats update cheythukond irikum_";

    /**
     * Upload failed error.
     */
    public const UPLOAD_FAILED = "❌ Upload failed. Try again?\n\n" .
        "📸 Photo or PDF ayakkuka";

    /**
     * Max offers reached.
     */
    public const MAX_OFFERS = "⚠️ Maximum {max} offers already active!\n\n" .
        "Delete one to upload new.";

    /**
     * Shop required error.
     */
    public const SHOP_REQUIRED = "⚠️ Shop owners mathram offers upload cheyyaan pattuu.\n\n" .
        "Shop register cheyyuka first.";

    /*
    |--------------------------------------------------------------------------
    | Browse Flow Messages
    |--------------------------------------------------------------------------
    */

    public const BROWSE_START = "🛍️ *Nearby Offers*\n\n" .
        "Category select cheyyuka:";

    public const NO_OFFERS = "😕 Offers illa {radius}km-il.\n\n" .
        "Try another category?";

    public const NO_LOCATION = "📍 Location share cheyyuka offers kaanaan.";

    public const OFFERS_FOUND = "🛍️ *{category}*\n\n" .
        "{count} offer(s) found:";

    /**
     * Offer card display.
     * FR-OFR-14: Send offer image with caption containing shop details
     */
    public const OFFER_CARD = "🏪 *{shop_name}*\n" .
        "📍 {distance} away\n" .
        "⏰ Valid till {expiry}";

    /**
     * Shop location sent.
     * FR-OFR-16: Send shop location as WhatsApp location message
     */
    public const LOCATION_SENT = "📍 *{shop_name}*\n\n" .
        "Maps-il open cheyyuka ➡️";

    /*
    |--------------------------------------------------------------------------
    | Manage Flow Messages
    |--------------------------------------------------------------------------
    */

    public const MY_OFFERS_HEADER = "🏷️ *Ninte Offers*\n\n" .
        "{count} active offer(s):";

    public const MY_OFFERS_EMPTY = "📭 Active offers illa.\n\n" .
        "Upload cheyyuka!";

    public const OFFER_STATS = "📊 *Stats*\n\n" .
        "👀 Views: {views}\n" .
        "📍 Location taps: {taps}\n" .
        "⏰ Expires: {expiry}";

    public const DELETE_CONFIRM = "🗑️ Offer delete cheyyano?";

    public const DELETED = "✅ Offer deleted.";

    /*
    |--------------------------------------------------------------------------
    | Button Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * Validity buttons.
     * FR-OFR-04: Today / 3 Days / This Week
     */
    public static function validityButtons(): array
    {
        return OfferValidity::toButtons();
    }

    /**
     * Post-upload action buttons.
     */
    public static function successButtons(): array
    {
        return [
            ['id' => 'upload_another', 'title' => '📸 Upload Another'],
            ['id' => 'main_menu', 'title' => '🏠 Menu'],
        ];
    }

    /**
     * Offer action buttons.
     * FR-OFR-15: Get Location and Call Shop action buttons
     */
    public static function offerActionButtons(): array
    {
        return [
            ['id' => 'get_location', 'title' => '📍 Get Location'],
            ['id' => 'call_shop', 'title' => '📞 Call Shop'],
            ['id' => 'back', 'title' => '⬅️ Back'],
        ];
    }

    /**
     * Manage offer buttons.
     */
    public static function manageButtons(): array
    {
        return [
            ['id' => 'view_stats', 'title' => '📊 Stats'],
            ['id' => 'delete', 'title' => '🗑️ Delete'],
            ['id' => 'back', 'title' => '⬅️ Back'],
        ];
    }

    /**
     * Delete confirmation buttons.
     */
    public static function deleteConfirmButtons(): array
    {
        return [
            ['id' => 'confirm_delete', 'title' => '🗑️ Yes, Delete'],
            ['id' => 'cancel', 'title' => '❌ No'],
        ];
    }

    /**
     * Radius selection buttons.
     */
    public static function radiusButtons(): array
    {
        return [
            ['id' => 'radius_2', 'title' => '📍 2 km'],
            ['id' => 'radius_5', 'title' => '📍 5 km'],
            ['id' => 'radius_10', 'title' => '📍 10 km'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Formatting Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Format message with placeholders.
     */
    public static function format(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace("{{$key}}", (string) $value, $template);
        }
        return $template;
    }

    /**
     * Format distance for display.
     * FR-OFR-12: Sort by distance (nearest first)
     */
    public static function formatDistance(float $km): string
    {
        if ($km < 0.1) {
            return 'Very close';
        }
        if ($km < 1) {
            return round($km * 1000) . 'm';
        }
        return round($km, 1) . 'km';
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
     * Truncate text for WhatsApp limits.
     */
    public static function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) {
            return $text;
        }
        return mb_substr($text, 0, $max - 1) . '…';
    }

    /**
     * Build offer card message.
     * FR-OFR-14: Send offer image with caption containing shop details
     */
    public static function buildOfferCard(array $offer, float $distanceKm): string
    {
        return self::format(self::OFFER_CARD, [
            'shop_name' => $offer['shop']['shop_name'] ?? 'Shop',
            'distance' => self::formatDistance($distanceKm),
            'expiry' => self::formatExpiry($offer['expires_at'] ?? null),
        ]);
    }

    /**
     * Build success message with reach estimate.
     * FR-OFR-05: Confirm publication, show estimated customer reach
     */
    public static function buildSuccessMessage(int $reach, Carbon $expiry): string
    {
        return self::format(self::SUCCESS, [
            'reach' => $reach,
            'expiry' => self::formatExpiry($expiry),
        ]);
    }

    /**
     * Build stats message.
     * FR-OFR-06: Track offer view counts and location tap metrics
     */
    public static function buildStatsMessage(int $views, int $taps, Carbon $expiry): string
    {
        return self::format(self::OFFER_STATS, [
            'views' => $views,
            'taps' => $taps,
            'expiry' => self::formatExpiry($expiry),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Category List (for browse)
    |--------------------------------------------------------------------------
    */

    /**
     * Get category sections with offer counts.
     * FR-OFR-10: Display category list with offer counts per category
     */
    public static function categorySections(array $counts = []): array
    {
        $categories = [
            ['id' => 'all', 'icon' => '🔍', 'name' => 'All'],
            ['id' => 'grocery', 'icon' => '🛒', 'name' => 'Grocery'],
            ['id' => 'electronics', 'icon' => '📱', 'name' => 'Electronics'],
            ['id' => 'clothes', 'icon' => '👕', 'name' => 'Clothes'],
            ['id' => 'medical', 'icon' => '💊', 'name' => 'Medical'],
            ['id' => 'furniture', 'icon' => '🪑', 'name' => 'Furniture'],
            ['id' => 'mobile', 'icon' => '📲', 'name' => 'Mobile'],
            ['id' => 'appliances', 'icon' => '🔌', 'name' => 'Appliances'],
            ['id' => 'hardware', 'icon' => '🔧', 'name' => 'Hardware'],
        ];

        $rows = array_map(function ($cat) use ($counts) {
            $count = $counts[$cat['id']] ?? 0;
            $desc = $count > 0 ? "{$count} offer(s)" : 'No offers';

            return [
                'id' => $cat['id'],
                'title' => self::truncate("{$cat['icon']} {$cat['name']}", 24),
                'description' => $desc,
            ];
        }, $categories);

        return [['title' => 'Categories', 'rows' => $rows]];
    }

    /**
     * Build offers list for WhatsApp.
     * FR-OFR-13: Display shop list with distance and validity
     */
    public static function offersList(array $offers): array
    {
        $rows = [];

        foreach (array_slice($offers, 0, 10) as $offer) {
            $shop = $offer['shop'] ?? [];
            $shopName = $shop['shop_name'] ?? 'Shop';
            $distance = isset($offer['distance_km'])
                ? self::formatDistance($offer['distance_km'])
                : '';
            $expiry = self::formatExpiry($offer['expires_at'] ?? null);

            $rows[] = [
                'id' => 'offer_' . $offer['id'],
                'title' => self::truncate($shopName, 24),
                'description' => self::truncate("{$distance} • {$expiry}", 72),
            ];
        }

        return [['title' => 'Offers', 'rows' => $rows]];
    }

    /**
     * Build my offers list for shop owner.
     */
    public static function myOffersList(array $offers): array
    {
        $rows = [];

        foreach (array_slice($offers, 0, 10) as $i => $offer) {
            $views = $offer['view_count'] ?? 0;
            $expiry = self::formatExpiry($offer['expires_at'] ?? null);

            $rows[] = [
                'id' => 'manage_' . $offer['id'],
                'title' => self::truncate("Offer #" . ($i + 1), 24),
                'description' => self::truncate("👀 {$views} views • {$expiry}", 72),
            ];
        }

        return [['title' => 'Your Offers', 'rows' => $rows]];
    }
}