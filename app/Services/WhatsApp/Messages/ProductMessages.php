<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use Carbon\Carbon;

/**
 * Product Search Message Templates.
 *
 * Friendly, conversational tone - like getting recommendations from a helpful friend.
 *
 * @srs-ref FR-PRD-01 to FR-PRD-35
 */
class ProductMessages
{
    /*
    |--------------------------------------------------------------------------
    | Customer Search Flow (FR-PRD-01 to FR-PRD-06)
    |--------------------------------------------------------------------------
    */

    /** FR-PRD-01: Category selection */
    public const ASK_CATEGORY = "🔍 *Entha nokkunnath?*\n\n" .
        "Category select cheyyuka - sheriyaaya shops-ne notify cheyyaam:";

    /** FR-PRD-02: Product description */
    public const ASK_DESCRIPTION = "📝 *Entha product?*\n\n" .
        "Specific aayitt parayuka:\n" .
        "_Eg: Samsung M34, 6GB, black color_";

    public const ASK_IMAGE = "📸 *Photo undo?* (optional)\n\n" .
        "Reference image ayakkuka, or skip cheyyuka.";

    public const ASK_RADIUS = "📍 *Evide vare search cheyyanam?*";

    /** FR-PRD-04: Confirm with shop count */
    public const CONFIRM_REQUEST = "📋 *Request Summary*\n\n" .
        "🔍 *{description}*\n" .
        "📁 {category}\n" .
        "📍 {radius}km radius\n\n" .
        "🏪 *{shop_count} shops* nearby can help!\n\n" .
        "Send cheyyatte?";

    /** FR-PRD-03: Request number generated */
    public const REQUEST_SENT = "✅ *Sent to {shop_count} shops!*\n\n" .
        "Request #{request_number}\n" .
        "⏰ Valid for {hours}hrs\n\n" .
        "Responses varumbol notify cheyyaam 👍";

    public const NO_SHOPS_FOUND = "😕 *Shops illa nearby*\n\n" .
        "'{category}' shops {radius}km-ൽ illa.\n\n" .
        "Try: 'All Categories' or larger radius.";

    /*
    |--------------------------------------------------------------------------
    | Real-Time Response Notification (NEW!)
    |--------------------------------------------------------------------------
    */

    /**
     * Sent immediately when a shop responds.
     * Feels like a friend found something for you!
     */
    public const NEW_RESPONSE_ALERT = "💬 *Found one!* #{request_number}\n\n" .
        "🏪 *{shop_name}* has it!\n" .
        "📍 {distance} away\n" .
        "💰 *₹{price}*";

    /** With description */
    public const NEW_RESPONSE_ALERT_WITH_DESC = "💬 *Found one!* #{request_number}\n\n" .
        "🏪 *{shop_name}* has it!\n" .
        "📍 {distance} away\n" .
        "💰 *₹{price}*\n" .
        "📝 {description}";

    /** Multiple responses summary */
    public const RESPONSES_SUMMARY = "🎉 *{count} shops replied!* #{request_number}\n\n" .
        "'{description}'\n\n" .
        "Best price: *₹{best_price}* ({best_shop})\n\n" .
        "Compare all responses?";

    /*
    |--------------------------------------------------------------------------
    | Response List View (FR-PRD-31, FR-PRD-32)
    |--------------------------------------------------------------------------
    */

    /** FR-PRD-31: Sorted by price header */
    public const RESPONSES_LIST_HEADER = "📬 *{count} Responses* — #{request_number}\n\n" .
        "'{description}'\n\n" .
        "_Sorted: lowest price first_ ⬇️";

    public const NO_RESPONSES_YET = "⏳ *Waiting for shops...*\n\n" .
        "#{request_number}\n" .
        "'{description}'\n\n" .
        "Usually replies come in 1-2 hours.\n" .
        "Notify cheyyaam response varumbol!";

    public const REQUEST_EXPIRED = "⏰ *Request expired*\n\n" .
        "#{request_number}\n" .
        "{response_count} responses kittiyirunnu.\n\n" .
        "New search thudangatte?";

    /*
    |--------------------------------------------------------------------------
    | Response Detail View (FR-PRD-33, FR-PRD-34)
    |--------------------------------------------------------------------------
    */

    /** FR-PRD-33: Full response with photo */
    public const RESPONSE_DETAIL = "🏪 *{shop_name}*\n" .
        "📍 {distance} • ⭐ {rating}\n\n" .
        "💰 *₹{price}*\n" .
        "{description_block}" .
        "\n_Tap below to visit or call!_";

    public const RESPONSE_DETAIL_NO_DESC = "🏪 *{shop_name}*\n" .
        "📍 {distance} • ⭐ {rating}\n\n" .
        "💰 *₹{price}*\n\n" .
        "_Tap below to visit or call!_";

    /** Shop not available response */
    public const RESPONSE_NOT_AVAILABLE = "🏪 *{shop_name}*\n" .
        "📍 {distance}\n\n" .
        "❌ _Not available right now_";

    /*
    |--------------------------------------------------------------------------
    | Close Request (FR-PRD-35)
    |--------------------------------------------------------------------------
    */

    /** FR-PRD-35: Close confirmation */
    public const CLOSE_CONFIRM = "🔒 *Close this search?*\n\n" .
        "#{request_number}\n\n" .
        "• No more responses accepted\n" .
        "• Existing responses still visible\n\n" .
        "Close cheyyatte?";

    public const REQUEST_CLOSED = "✅ *Search closed!*\n\n" .
        "Thanks for using NearBuy 🛒\n\n" .
        "Found what you needed? Happy to help again!";

    /*
    |--------------------------------------------------------------------------
    | My Requests
    |--------------------------------------------------------------------------
    */

    public const MY_REQUESTS_HEADER = "📋 *Ninte Requests*\n\n" .
        "{count} active request(s):";

    public const MY_REQUESTS_EMPTY = "📭 *No active requests*\n\n" .
        "Search for something?";

    public const REQUEST_DETAIL_VIEW = "📋 *#{request_number}*\n\n" .
        "🔍 '{description}'\n" .
        "📁 {category}\n" .
        "{status_icon} {status}\n" .
        "📬 {response_count} response(s)\n" .
        "⏰ {expiry}";

    /*
    |--------------------------------------------------------------------------
    | Shop Notification (FR-PRD-10 to FR-PRD-14)
    |--------------------------------------------------------------------------
    */

    /** FR-PRD-13: Include customer distance */
    public const SHOP_REQUEST_ALERT = "🔍 *Product Request!* #{request_number}\n" .
        "'{description}'\n" .
        "{category} • {distance} away";

    /** FR-PRD-12: Batched notification */
    public const SHOP_BATCH_ALERT = "🔍 *{count} Product Requests!*\n\n" .
        "{request_list}\n\n" .
        "View & respond?";

    /*
    |--------------------------------------------------------------------------
    | Shop Response Flow (FR-PRD-20 to FR-PRD-23)
    |--------------------------------------------------------------------------
    */

    public const SHOP_ASK_PRICE = "💰 *Price/model entha?*\n\n" .
        "_Eg: 1500 or 1500, Samsung model_";

    public const SHOP_ASK_PHOTO = "📸 *Photo undo?* (optional)\n\n" .
        "Product photo send cheyyuka, or skip.";

    public const SHOP_RESPONSE_SENT = "✅ *Response ayachittund!*\n\n" .
        "💰 ₹{price}\n" .
        "Customer-nu notify cheythittund 👍";

    /** FR-PRD-23: Duplicate prevention */
    public const SHOP_ALREADY_RESPONDED = "⚠️ *Already responded*\n\n" .
        "Your price: ₹{price}";

    public const SHOP_REQUEST_CLOSED = "⚠️ *Request closed aayi*\n\n" .
        "Customer may have found what they needed.";

    public const SHOP_PENDING_EMPTY = "📭 *No requests now*\n\n" .
        "Notify cheyyaam requests varumbol!";

    public const SHOP_PENDING_HEADER = "📬 *{count} Requests nearby*\n\n" .
        "Respond cheyyaan select cheyyuka:";

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    */

    public const ERROR_INVALID_DESCRIPTION = "⚠️ Koodi details parayuka (min 10 chars).\n\n" .
        "_Product name, brand, size etc._";

    public const ERROR_INVALID_PRICE = "⚠️ Valid price enter cheyyuka.\n\n" .
        "_Eg: 15000_";

    public const ERROR_REQUEST_NOT_FOUND = "❌ Request kandilla or expired.";

    public const ERROR_NO_LOCATION = "📍 Location share cheyyuka nearby shops kandupidikkan.";

    /*
    |--------------------------------------------------------------------------
    | Button Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * Radius selection buttons.
     */
    public static function getRadiusButtons(): array
    {
        return [
            ['id' => 'radius_2', 'title' => '📍 2 km'],
            ['id' => 'radius_5', 'title' => '📍 5 km'],
            ['id' => 'radius_10', 'title' => '📍 10 km'],
        ];
    }

    /**
     * Request confirmation buttons (FR-PRD-04).
     */
    public static function getConfirmButtons(): array
    {
        return [
            ['id' => 'send', 'title' => '✅ Send'],
            ['id' => 'edit', 'title' => '✏️ Edit'],
            ['id' => 'cancel', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * Real-time response alert buttons.
     */
    public static function getResponseAlertButtons(int $responseId): array
    {
        return [
            ['id' => "photo_{$responseId}", 'title' => '📸 See Photo'],
            ['id' => "location_{$responseId}", 'title' => '📍 Location'],
            ['id' => "call_{$responseId}", 'title' => '📞 Call'],
        ];
    }

    /**
     * Response summary buttons.
     */
    public static function getResponseSummaryButtons(): array
    {
        return [
            ['id' => 'view_all', 'title' => '📋 View All'],
            ['id' => 'close_request', 'title' => '✅ Close Search'],
        ];
    }

    /**
     * FR-PRD-34: Response detail action buttons.
     */
    public static function getResponseDetailButtons(int $responseId): array
    {
        return [
            ['id' => "location_{$responseId}", 'title' => '📍 Get Location'],
            ['id' => "call_{$responseId}", 'title' => '📞 Call Shop'],
            ['id' => 'more_responses', 'title' => '⬅️ More'],
        ];
    }

    /**
     * Response detail with close option.
     */
    public static function getResponseDetailWithCloseButtons(int $responseId): array
    {
        return [
            ['id' => "location_{$responseId}", 'title' => '📍 Get Location'],
            ['id' => "call_{$responseId}", 'title' => '📞 Call Shop'],
            ['id' => 'close_request', 'title' => '✅ Done'],
        ];
    }

    /**
     * FR-PRD-35: Close request confirmation buttons.
     */
    public static function getCloseConfirmButtons(): array
    {
        return [
            ['id' => 'confirm_close', 'title' => '🔒 Yes, Close'],
            ['id' => 'keep_open', 'title' => '⬅️ Keep Open'],
        ];
    }

    /**
     * Post-close buttons.
     */
    public static function getPostCloseButtons(): array
    {
        return [
            ['id' => 'new_search', 'title' => '🔍 New Search'],
            ['id' => 'menu', 'title' => '🏠 Menu'],
        ];
    }

    /**
     * Waiting for responses buttons.
     */
    public static function getWaitingButtons(): array
    {
        return [
            ['id' => 'check_responses', 'title' => '📬 Check Now'],
            ['id' => 'menu', 'title' => '🏠 Menu'],
        ];
    }

    /**
     * My requests management buttons.
     */
    public static function getRequestManageButtons(): array
    {
        return [
            ['id' => 'view_responses', 'title' => '📬 Responses'],
            ['id' => 'close_request', 'title' => '✅ Close'],
            ['id' => 'back', 'title' => '⬅️ Back'],
        ];
    }

    /**
     * FR-PRD-14: Shop response choice buttons.
     */
    public static function getShopResponseButtons(int $requestId): array
    {
        return [
            ['id' => "yes_{$requestId}", 'title' => '✅ Yes I Have'],
            ['id' => "no_{$requestId}", 'title' => "❌ Don't Have"],
            ['id' => "skip_{$requestId}", 'title' => '⏭️ Skip'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | List Builders (FR-PRD-32)
    |--------------------------------------------------------------------------
    */

    /**
     * FR-PRD-01: Category selection list.
     */
    public static function getCategorySections(): array
    {
        return [
            [
                'title' => 'Popular',
                'rows' => [
                    ['id' => 'mobile', 'title' => '📲 Mobile', 'description' => 'Phones & accessories'],
                    ['id' => 'electronics', 'title' => '📱 Electronics', 'description' => 'Gadgets & devices'],
                    ['id' => 'clothes', 'title' => '👕 Clothes', 'description' => 'Fashion & apparel'],
                    ['id' => 'grocery', 'title' => '🛒 Grocery', 'description' => 'Daily essentials'],
                ],
            ],
            [
                'title' => 'More',
                'rows' => [
                    ['id' => 'medical', 'title' => '💊 Medical', 'description' => 'Pharmacy & health'],
                    ['id' => 'appliances', 'title' => '🔌 Appliances', 'description' => 'Home appliances'],
                    ['id' => 'furniture', 'title' => '🪑 Furniture', 'description' => 'Home & office'],
                    ['id' => 'hardware', 'title' => '🔧 Hardware', 'description' => 'Tools & materials'],
                    ['id' => 'all', 'title' => '🔍 All', 'description' => 'Search all shops'],
                ],
            ],
        ];
    }

    /**
     * FR-PRD-32: Build responses list sorted by price.
     *
     * Format: "₹1500 — Shop A (1.2km)"
     */
    public static function buildResponsesList(array $responses): array
    {
        $rows = [];
        $bestPrice = null;

        // Find best price
        foreach ($responses as $r) {
            if (($r['is_available'] ?? true) && isset($r['price'])) {
                if ($bestPrice === null || $r['price'] < $bestPrice) {
                    $bestPrice = $r['price'];
                }
            }
        }

        foreach ($responses as $r) {
            $shop = $r['shop'] ?? [];
            $shopName = $shop['shop_name'] ?? 'Shop';
            $price = $r['price'] ?? 0;
            $distance = isset($r['distance_km']) ? self::formatDistance($r['distance_km']) : '';
            $available = $r['is_available'] ?? true;

            if ($available && $price > 0) {
                $priceStr = '₹' . number_format($price);
                // Mark best price
                $tag = ($price === $bestPrice && count($responses) > 1) ? ' ⭐' : '';
                $title = self::truncate("{$priceStr}{$tag} — {$shopName}", 24);
                $desc = $distance;
            } else {
                $title = self::truncate("❌ {$shopName}", 24);
                $desc = 'Not available';
            }

            $rows[] = [
                'id' => 'resp_' . ($r['id'] ?? count($rows)),
                'title' => $title,
                'description' => self::truncate($desc, 72),
            ];
        }

        return [['title' => 'Responses', 'rows' => array_slice($rows, 0, 10)]];
    }

    /**
     * Build my requests list.
     */
    public static function buildMyRequestsList(array $requests): array
    {
        $rows = [];

        foreach ($requests as $req) {
            $count = $req['response_count'] ?? 0;
            $status = $req['status'] ?? 'open';

            $icon = match ($status) {
                'open' => '🟢',
                'collecting' => '🟡',
                'closed' => '✅',
                'expired' => '⏰',
                default => '📋',
            };

            $rows[] = [
                'id' => 'req_' . $req['id'],
                'title' => self::truncate($req['description'] ?? 'Request', 24),
                'description' => "{$icon} {$count} responses • #{$req['request_number']}",
            ];
        }

        return [['title' => 'Your Requests', 'rows' => array_slice($rows, 0, 10)]];
    }

    /**
     * Build pending requests list for shop.
     */
    public static function buildPendingRequestsList(array $requests): array
    {
        $rows = [];

        foreach ($requests as $req) {
            $distance = isset($req['distance_km']) ? self::formatDistance($req['distance_km']) : '';
            $expiry = self::formatTimeRemaining($req['expires_at'] ?? null);

            $rows[] = [
                'id' => 'req_' . $req['id'],
                'title' => self::truncate($req['description'] ?? 'Request', 24),
                'description' => "📍 {$distance} • ⏰ {$expiry}",
            ];
        }

        return [['title' => 'Requests', 'rows' => array_slice($rows, 0, 10)]];
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
     * Format distance.
     */
    public static function formatDistance(float $km): string
    {
        if ($km < 0.1) return 'Very close';
        if ($km < 1) return round($km * 1000) . 'm';
        return round($km, 1) . 'km';
    }

    /**
     * Format price.
     */
    public static function formatPrice(?float $price): string
    {
        if ($price === null) return 'Price on request';
        return '₹' . number_format($price);
    }

    /**
     * Format time remaining.
     */
    public static function formatTimeRemaining(Carbon|string|null $expiresAt): string
    {
        if (!$expiresAt) return 'Unknown';

        if (is_string($expiresAt)) {
            $expiresAt = Carbon::parse($expiresAt);
        }

        if ($expiresAt->isPast()) return 'Expired';

        $diff = now()->diff($expiresAt);

        if ($diff->d > 0) return "{$diff->d}d {$diff->h}h";
        if ($diff->h > 0) return "{$diff->h}h {$diff->i}m";
        return "{$diff->i}m";
    }

    /**
     * Format expiry time.
     */
    public static function formatExpiry(Carbon|string|null $expiresAt): string
    {
        if (!$expiresAt) return 'Unknown';

        if (is_string($expiresAt)) {
            $expiresAt = Carbon::parse($expiresAt);
        }

        if ($expiresAt->isPast()) return 'Expired';
        if ($expiresAt->isToday()) return 'Today ' . $expiresAt->format('g:i A');
        if ($expiresAt->isTomorrow()) return 'Tomorrow ' . $expiresAt->format('g:i A');
        return $expiresAt->format('M j, g:i A');
    }

    /**
     * Get category label.
     */
    public static function getCategoryLabel(string $id): string
    {
        return match (strtolower($id)) {
            'grocery' => '🛒 Grocery',
            'electronics' => '📱 Electronics',
            'clothes' => '👕 Clothes',
            'medical' => '💊 Medical',
            'mobile' => '📲 Mobile',
            'appliances' => '🔌 Appliances',
            'hardware' => '🔧 Hardware',
            'furniture' => '🪑 Furniture',
            'all' => '🔍 All Categories',
            default => ucfirst($id),
        };
    }

    /**
     * Get status icon.
     */
    public static function getStatusIcon(string $status): string
    {
        return match ($status) {
            'open' => '🟢',
            'collecting' => '🟡',
            'closed' => '✅',
            'expired' => '⏰',
            default => '📋',
        };
    }

    /**
     * Truncate text.
     */
    public static function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;
        return mb_substr($text, 0, $max - 1) . '…';
    }

    /*
    |--------------------------------------------------------------------------
    | Localization
    |--------------------------------------------------------------------------
    */

    /**
     * Get message in language.
     */
    public static function get(string $key, string $lang = 'en'): string
    {
        $messages = match ($lang) {
            'ml' => self::getMalayalamMessages(),
            default => [],
        };

        return $messages[$key] ?? constant("self::" . strtoupper($key)) ?? "Message: {$key}";
    }

    /**
     * Malayalam messages.
     */
    protected static function getMalayalamMessages(): array
    {
        return [
            'ask_category' => "🔍 *എന്താ നോക്കുന്നത്?*\n\nCategory select ചെയ്യുക:",
            'ask_description' => "📝 *എന്ത് product?*\n\nSpecific ആയി പറയുക:",
            'request_sent' => "✅ *{shop_count} shops-ന് അയച്ചു!*\n\nRequest #{request_number}",
            'no_responses_yet' => "⏳ *Responses ഇതുവരെ ഇല്ല*\n\nShops-നെ notify ചെയ്തു.",
            'request_closed' => "✅ *Search close ആയി!*\n\nNearBuy use ചെയ്തതിന് നന്ദി 🛒",
        ];
    }
}