<?php

namespace App\Services\WhatsApp\Messages;

/**
 * Message templates for Product Search module.
 *
 * Contains all user-facing messages for product search and response flows.
 */
class ProductMessages
{
    /*
    |--------------------------------------------------------------------------
    | Customer Search Flow Messages
    |--------------------------------------------------------------------------
    */

    public const SEARCH_START = "🔍 *Product Search*\n\nCan't find what you need? Let nearby shops help you!\n\nDescribe what you're looking for, and we'll notify shops in your area.";

    public const ASK_CATEGORY = "📦 *Select Category*\n\nChoose a category to target specific shops:";

    public const ASK_DESCRIPTION = "📝 *Describe Your Need*\n\nWhat product are you looking for?\n\nBe specific for better results:\n• Product name/type\n• Brand preference (if any)\n• Size/specifications\n\nExample: _Samsung Galaxy M34 5G, 6GB RAM, any color_";

    public const ASK_IMAGE = "📸 *Add Reference Image (Optional)*\n\nSend a photo of the product you're looking for, or type 'skip' to continue without an image.";

    public const ASK_RADIUS = "📍 *Search Radius*\n\nHow far should we search for shops?";

    public const CONFIRM_REQUEST = "📋 *Confirm Your Request*\n\n📦 *Looking for:*\n{description}\n\n📁 *Category:* {category}\n📍 *Search radius:* {radius}km\n🏪 *Shops to notify:* {shop_count}\n\nSend this request?";

    public const REQUEST_SENT = "✅ *Request Sent!*\n\n📋 Request #: *{request_number}*\n🏪 Notified: {shop_count} shops\n⏰ Expires: {expiry_time}\n\nWe'll notify you when shops respond. You can check responses anytime from the main menu.";

    public const NO_SHOPS_FOUND = "😕 *No Shops Found*\n\nNo shops in *{category}* category within {radius}km.\n\nTry:\n• Selecting 'All Categories'\n• Expanding your search radius";

    public const REQUEST_EXPIRED = "⏰ *Request Expired*\n\nThis request has expired. You received {response_count} response(s).\n\nWould you like to create a new request?";

    /*
    |--------------------------------------------------------------------------
    | Customer Response View Messages
    |--------------------------------------------------------------------------
    */

    public const RESPONSES_HEADER = "📬 *Responses for Request #{request_number}*\n\n📦 {description}\n\n{response_count} shop(s) responded:";

    public const NO_RESPONSES_YET = "⏳ *No Responses Yet*\n\nRequest #: *{request_number}*\n\nShops have been notified. Responses usually arrive within 1-2 hours.\n\n⏰ Request expires: {expiry_time}";

    // Alias for handlers
    public const NO_RESPONSES = "⏳ *No Responses Yet*\n\nYour request #{request_number} hasn't received any responses yet.\n\nShops have been notified. Please check back later.";

    public const RESPONSE_CARD = "🏪 *{shop_name}*\n📍 {distance} away\n\n💰 *Price:* ₹{price}\n📝 {description}";

    public const RESPONSE_CARD_NO_DESC = "🏪 *{shop_name}*\n📍 {distance} away\n\n💰 *Price:* ₹{price}";

    public const RESPONSE_NOT_AVAILABLE = "🏪 *{shop_name}*\n📍 {distance} away\n\n❌ Product not available";

    /*
    |--------------------------------------------------------------------------
    | My Requests Messages
    |--------------------------------------------------------------------------
    */

    public const MY_REQUESTS_HEADER = "📋 *My Product Requests*\n\nYou have {count} active request(s):";

    public const MY_REQUESTS_EMPTY = "📭 *No Active Requests*\n\nYou don't have any active product requests.\n\nWould you like to search for a product?";

    public const REQUEST_DETAIL = "📋 *Request #{request_number}*\n\n📦 *Looking for:*\n{description}\n\n📁 *Category:* {category}\n📊 *Status:* {status}\n📬 *Responses:* {response_count}\n⏰ *Expires:* {expiry_time}";

    public const REQUEST_CLOSED = "✅ *Request Closed*\n\nRequest #{request_number} has been closed.\n\nThank you for using NearBuy!";

    public const CLOSE_REQUEST_CONFIRM = "🔒 *Close Request?*\n\nClosing this request will:\n• Stop accepting new responses\n• Keep existing responses visible\n\nAre you sure?";

    /*
    |--------------------------------------------------------------------------
    | Shop Notification Messages
    |--------------------------------------------------------------------------
    */

    public const SHOP_NEW_REQUEST = "🔔 *New Product Request*\n\n📦 *Looking for:*\n{description}\n\n📁 *Category:* {category}\n📍 *Customer is {distance} away*\n⏰ *Expires:* {expiry_time}\n\n📋 Request #: {request_number}";

    // Alias for handlers
    public const NEW_REQUEST_NOTIFICATION = "🔔 *New Product Request*\n\n📦 *Looking for:*\n{description}\n\n📁 Category: {category}\n📍 Customer is {distance} away\n⏰ Expires: {time_remaining}\n\n📋 Request #: {request_number}";

    public const SHOP_BATCH_NOTIFICATION = "🔔 *{count} New Product Request(s)*\n\nCustomers near you are looking for products.\n\nTap below to view and respond.";

    /*
    |--------------------------------------------------------------------------
    | Shop Response Flow Messages
    |--------------------------------------------------------------------------
    */

    public const RESPOND_PROMPT = "Do you have this product available?";

    public const RESPOND_NO_THANKS = "👍 No problem! You won't be asked about this request again.";

    public const RESPOND_SKIPPED = "⏭️ *Request Skipped*\n\nYou can respond to other requests from the main menu.";

    public const ASK_PHOTO = "📸 *Send Product Photo*\n\nTake a photo of the actual product to show the customer.\n\nOr type 'skip' to continue without a photo.";

    public const ASK_PRICE = "💰 *Enter Price*\n\nEnter your price for this product.\n\nYou can also add details:\nExample: _15000 - Black color, warranty included_";

    public const ASK_PRICE_DETAILS = "💰 *Enter Price & Details*\n\nEnter your price and any additional details.\n\nFormat: Price - Details\nExample: _15000 - Samsung M34, 6GB, Black color, 1 year warranty_";

    public const ASK_DETAILS = "📝 *Add Details* (Optional)\n\nAdd any details about the product:\n• Condition (new/used)\n• Warranty info\n• Availability\n\nOr type 'skip' to continue.";

    public const CONFIRM_RESPONSE = "📋 *Confirm Your Response*\n\n💰 *Price:* ₹{price}\n📝 *Details:* {description}\n📷 *Photo:* {has_photo}\n\nSend this response to the customer?";

    public const RESPONSE_CONFIRM = "📋 *Confirm Your Response*\n\n📦 *Request:* {request_description}\n\n✅ *Available:* {available}\n💰 *Price:* ₹{price}\n📝 *Details:* {description}\n\nSend this response?";

    public const RESPONSE_SENT = "✅ *Response Sent!*\n\nYour response has been sent to the customer.\n\nIf they're interested, they'll contact you directly.\n\n💰 Your price: ₹{price}\n📋 Request #: {request_number}";

    public const ALREADY_RESPONDED = "⚠️ *Already Responded*\n\nYou have already responded to this request.\n\nYour response: ₹{price}";

    public const REQUEST_NO_LONGER_ACTIVE = "⚠️ This request is no longer accepting responses.\n\nIt may have expired or been closed by the customer.";

    public const REQUEST_CLOSED_SHOP = "⚠️ *Request Closed*\n\nThis request has been closed by the customer.";

    /*
    |--------------------------------------------------------------------------
    | Shop Pending Requests Messages
    |--------------------------------------------------------------------------
    */

    public const PENDING_REQUESTS_HEADER = "📬 *Product Requests*\n\nYou have {count} pending request(s) from nearby customers:";

    public const PENDING_REQUESTS_EMPTY = "📭 *No Pending Requests*\n\nNo product requests from customers in your area right now.\n\nWe'll notify you when customers are looking for products!";

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    */

    public const ERROR_INVALID_DESCRIPTION = "⚠️ Please provide a more detailed description (at least 10 characters).";

    public const ERROR_INVALID_PRICE = "⚠️ Invalid price format.\n\nPlease enter a number, optionally followed by details.\n\nExample: _15000 - Black color, warranty included_";

    public const ERROR_REQUEST_NOT_FOUND = "❌ Request not found or has expired.";

    public const ERROR_NO_LOCATION = "📍 *Location Required*\n\nPlease share your location to search for nearby shops.";

    /*
    |--------------------------------------------------------------------------
    | Button Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * Get search radius buttons.
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
     * Get request confirmation buttons.
     */
    public static function getConfirmButtons(): array
    {
        return [
            ['id' => 'send', 'title' => '✅ Send Request'],
            ['id' => 'edit', 'title' => '✏️ Edit'],
            ['id' => 'cancel', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * Get shop response choice buttons.
     */
    public static function getResponseChoiceButtons(): array
    {
        return [
            ['id' => 'available', 'title' => '✅ Yes, I have it'],
            ['id' => 'unavailable', 'title' => '❌ Don\'t have'],
            ['id' => 'skip', 'title' => '⏭️ Skip'],
        ];
    }

    /**
     * Get response confirmation buttons.
     */
    public static function getResponseConfirmButtons(): array
    {
        return [
            ['id' => 'confirm', 'title' => '✅ Send'],
            ['id' => 'edit', 'title' => '✏️ Edit'],
            ['id' => 'cancel', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * Get response action buttons.
     */
    public static function getResponseActionButtons(): array
    {
        return [
            ['id' => 'location', 'title' => '📍 Get Location'],
            ['id' => 'contact', 'title' => '📞 Contact Shop'],
            ['id' => 'back', 'title' => '⬅️ Back'],
        ];
    }

    /**
     * Get request management buttons.
     */
    public static function getRequestManageButtons(): array
    {
        return [
            ['id' => 'view_responses', 'title' => '📬 View Responses'],
            ['id' => 'close', 'title' => '✅ Close Request'],
            ['id' => 'back', 'title' => '⬅️ Back'],
        ];
    }

    /**
     * Get post-request buttons.
     */
    public static function getPostRequestButtons(): array
    {
        return [
            ['id' => 'view_responses', 'title' => '📬 Check Responses'],
            ['id' => 'new_search', 'title' => '🔍 New Search'],
            ['id' => 'menu', 'title' => '🏠 Main Menu'],
        ];
    }

    /**
     * Get empty requests buttons.
     */
    public static function getEmptyRequestsButtons(): array
    {
        return [
            ['id' => 'new_search', 'title' => '🔍 Search Product'],
            ['id' => 'menu', 'title' => '🏠 Main Menu'],
        ];
    }

    /**
     * Get confirm request buttons (alias).
     */
    public static function getConfirmRequestButtons(): array
    {
        return [
            ['id' => 'send', 'title' => '✅ Send Request'],
            ['id' => 'edit', 'title' => '✏️ Edit'],
            ['id' => 'cancel', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * Get respond choice buttons (alias).
     */
    public static function getRespondChoiceButtons(): array
    {
        return [
            ['id' => 'yes', 'title' => '✅ Yes, I have it'],
            ['id' => 'no', 'title' => '❌ Don\'t have'],
            ['id' => 'skip', 'title' => '⏭️ Skip for now'],
        ];
    }

    /**
     * Get confirm response buttons (alias).
     */
    public static function getConfirmResponseButtons(): array
    {
        return [
            ['id' => 'send', 'title' => '✅ Send Response'],
            ['id' => 'edit', 'title' => '✏️ Edit'],
            ['id' => 'cancel', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * Get post-response buttons for shop.
     */
    public static function getPostResponseButtons(): array
    {
        return [
            ['id' => 'more_requests', 'title' => '📬 More Requests'],
            ['id' => 'menu', 'title' => '🏠 Main Menu'],
        ];
    }

    /**
     * Get close request confirmation buttons.
     */
    public static function getCloseRequestButtons(): array
    {
        return [
            ['id' => 'confirm_close', 'title' => '🔒 Yes, Close'],
            ['id' => 'cancel_close', 'title' => '❌ Keep Open'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | List Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * Get category sections for product search.
     */
    public static function getCategorySections(): array
    {
        return [
            [
                'title' => 'Popular Categories',
                'rows' => [
                    ['id' => 'grocery', 'title' => '🛒 Grocery', 'description' => 'Daily essentials'],
                    ['id' => 'electronics', 'title' => '📱 Electronics', 'description' => 'Gadgets & devices'],
                    ['id' => 'clothes', 'title' => '👕 Clothes', 'description' => 'Fashion & apparel'],
                    ['id' => 'medical', 'title' => '💊 Medical', 'description' => 'Pharmacy & health'],
                    ['id' => 'mobile', 'title' => '📲 Mobile', 'description' => 'Phones & accessories'],
                    ['id' => 'appliances', 'title' => '🔌 Appliances', 'description' => 'Home appliances'],
                    ['id' => 'hardware', 'title' => '🔧 Hardware', 'description' => 'Tools & materials'],
                    ['id' => 'furniture', 'title' => '🪑 Furniture', 'description' => 'Home & office'],
                ],
            ],
            [
                'title' => 'More Options',
                'rows' => [
                    ['id' => 'all', 'title' => '🔍 All Categories', 'description' => 'Search all shops'],
                    ['id' => 'other', 'title' => '📦 Other', 'description' => 'Other categories'],
                ],
            ],
        ];
    }

    /**
     * Build responses list for customer view.
     */
    public static function buildResponsesList(array $responses): array
    {
        $rows = [];

        foreach ($responses as $index => $response) {
            $shop = $response['shop'] ?? [];
            $price = $response['price'] ?? 0;
            $distance = isset($response['distance']) ? self::formatDistance($response['distance']) : '';
            $available = $response['is_available'] ?? true;

            $title = $available
                ? '₹' . number_format($price) . ' - ' . ($shop['shop_name'] ?? 'Shop')
                : '❌ ' . ($shop['shop_name'] ?? 'Shop');

            $rows[] = [
                'id' => 'response_' . ($response['id'] ?? $index),
                'title' => self::truncate($title, 24),
                'description' => self::truncate($distance . ($available ? '' : ' - Not available'), 72),
            ];
        }

        return [
            [
                'title' => 'Shop Responses',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];
    }

    /**
     * Build my requests list.
     */
    public static function buildMyRequestsList(array $requests): array
    {
        $rows = [];

        foreach ($requests as $request) {
            $responseCount = $request['response_count'] ?? 0;
            $status = $request['status'] ?? 'open';

            $statusEmoji = match ($status) {
                'open' => '🟢',
                'collecting' => '🟡',
                'closed' => '✅',
                'expired' => '⏰',
                default => '📋',
            };

            $rows[] = [
                'id' => 'request_' . $request['id'],
                'title' => self::truncate($request['description'] ?? 'Request', 24),
                'description' => self::truncate("{$statusEmoji} {$responseCount} responses • #{$request['request_number']}", 72),
            ];
        }

        return [
            [
                'title' => 'Your Requests',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];
    }

    /**
     * Build pending requests list for shop.
     */
    public static function buildPendingRequestsList(array $requests): array
    {
        $rows = [];

        foreach ($requests as $request) {
            $distance = isset($request['distance']) ? self::formatDistance($request['distance']) : '';
            $expiry = self::formatTimeRemaining($request['expires_at'] ?? null);

            $rows[] = [
                'id' => 'respond_' . $request['id'],
                'title' => self::truncate($request['description'] ?? 'Request', 24),
                'description' => self::truncate("📍 {$distance} • ⏰ {$expiry}", 72),
            ];
        }

        return [
            [
                'title' => 'Pending Requests',
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
     * Format price for display.
     */
    public static function formatPrice(?float $price): string
    {
        if ($price === null) {
            return 'Price on request';
        }

        return '₹' . number_format($price, 0);
    }

    /**
     * Format time remaining.
     */
    public static function formatTimeRemaining($expiresAt): string
    {
        if (!$expiresAt) {
            return 'Unknown';
        }

        if (is_string($expiresAt)) {
            $expiresAt = \Carbon\Carbon::parse($expiresAt);
        }

        if ($expiresAt->isPast()) {
            return 'Expired';
        }

        $diff = now()->diff($expiresAt);

        if ($diff->h > 0) {
            return $diff->h . 'h ' . $diff->i . 'm';
        }

        return $diff->i . ' min';
    }

    /**
     * Format expiry time.
     */
    public static function formatExpiry($expiresAt): string
    {
        if (!$expiresAt) {
            return 'Unknown';
        }

        if (is_string($expiresAt)) {
            $expiresAt = \Carbon\Carbon::parse($expiresAt);
        }

        if ($expiresAt->isToday()) {
            return 'Today ' . $expiresAt->format('h:i A');
        }

        return $expiresAt->format('M j, h:i A');
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
            'mobile' => '📲 Mobile',
            'appliances' => '🔌 Appliances',
            'hardware' => '🔧 Hardware',
            'furniture' => '🪑 Furniture',
            'all' => '🔍 All Categories',
            'other' => '📦 Other',
        ];

        return $map[strtolower($categoryId)] ?? ucfirst($categoryId);
    }

    /**
     * Get status label.
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'open' => '🟢 Open',
            'collecting' => '🟡 Collecting Responses',
            'closed' => '✅ Closed',
            'expired' => '⏰ Expired',
            default => ucfirst($status),
        };
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