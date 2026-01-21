<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use Carbon\Carbon;

/**
 * Message templates for Product Search module.
 *
 * Contains all user-facing messages for product search and response flows.
 *
 * ENHANCEMENTS:
 * - Progress indicators for multi-step flows
 * - Better price formatting with comparison hints
 * - Localization support (English + Malayalam)
 * - Clearer shop notification messages
 * - Response sorting and filtering hints
 *
 * @see SRS Section 3.3 - Product Search
 */
class ProductMessages
{
    /*
    |--------------------------------------------------------------------------
    | Customer Search Flow Messages (FR-PRD-01 to FR-PRD-06)
    |--------------------------------------------------------------------------
    */

    public const SEARCH_START = "🔍 *Find Products Nearby*\n\n" .
        "Can't find what you need? Let local shops help!\n\n" .
        "Tell us what you're looking for, and we'll ask nearby shops.";

    // FR-PRD-01: Present category selection via list message
    public const ASK_CATEGORY = "📦 *Step 1 of 3* - Select Category\n\n" .
        "Choose a category to target specific shops:";

    // FR-PRD-02: Collect product description via free-text input
    public const ASK_DESCRIPTION = "📝 *Step 2 of 3* - Describe Product\n\n" .
        "What are you looking for?\n\n" .
        "Be specific for better results:\n" .
        "• Product name/type\n" .
        "• Brand (if any)\n" .
        "• Size/specs\n\n" .
        "_Example: Samsung Galaxy M34, 6GB RAM, any color_";

    public const ASK_IMAGE = "📸 *Add Photo (Optional)*\n\n" .
        "Send a reference image of what you're looking for.\n\n" .
        "Or type *skip* to continue.";

    public const ASK_RADIUS = "📍 *Step 3 of 3* - Search Area\n\n" .
        "How far should we search?";

    // FR-PRD-04: Display confirmation with shop count and Send/Edit/Cancel options
    public const CONFIRM_REQUEST = "📋 *Confirm Request*\n\n" .
        "📦 *Looking for:*\n{description}\n\n" .
        "📁 Category: {category}\n" .
        "📍 Radius: {radius}km\n" .
        "🏪 Shops to notify: {shop_count}\n\n" .
        "Send this request?";

    // FR-PRD-03: Generate unique request number (format: NB-XXXX)
    public const REQUEST_SENT = "✅ *Request Sent!*\n\n" .
        "📋 Request #: *{request_number}*\n" .
        "🏪 Notified: {shop_count} shops\n" .
        "⏰ Expires in: {hours} hours\n\n" .
        "We'll notify you when shops respond. Check back anytime!";

    public const NO_SHOPS_FOUND = "😕 *No Shops Found*\n\n" .
        "No *{category}* shops within {radius}km.\n\n" .
        "Try:\n" .
        "• 'All Categories' option\n" .
        "• Larger search radius";

    public const REQUEST_EXPIRED = "⏰ *Request Expired*\n\n" .
        "This request has expired.\n" .
        "Responses received: {response_count}\n\n" .
        "Create a new request?";

    /*
    |--------------------------------------------------------------------------
    | Customer Response View Messages (FR-PRD-30 to FR-PRD-35)
    |--------------------------------------------------------------------------
    */

    // FR-PRD-31: Sort responses by price (lowest first)
    public const RESPONSES_HEADER = "📬 *Responses - #{request_number}*\n\n" .
        "📦 {description}\n\n" .
        "✅ {response_count} shop(s) have it!\n" .
        "_Sorted by price (lowest first)_";

    public const NO_RESPONSES = "⏳ *No Responses Yet*\n\n" .
        "Request #{request_number}\n\n" .
        "Shops have been notified. Responses usually come within 1-2 hours.";

    // FR-PRD-33: Send product photo and details upon selection
    public const RESPONSE_CARD = "🏪 *{shop_name}*\n" .
        "📍 {distance} away\n\n" .
        "💰 *Price: ₹{price}*\n" .
        "📝 {description}";

    public const RESPONSE_CARD_NO_DESC = "🏪 *{shop_name}*\n" .
        "📍 {distance} away\n\n" .
        "💰 *Price: ₹{price}*";

    public const RESPONSE_NOT_AVAILABLE = "🏪 *{shop_name}*\n" .
        "📍 {distance} away\n\n" .
        "❌ Not available";

    /*
    |--------------------------------------------------------------------------
    | My Requests Messages
    |--------------------------------------------------------------------------
    */

    public const MY_REQUESTS_HEADER = "📋 *My Requests*\n\n" .
        "You have {count} active request(s):";

    public const MY_REQUESTS_EMPTY = "📭 *No Active Requests*\n\n" .
        "You don't have any active product requests.\n\n" .
        "Search for something?";

    public const REQUEST_DETAIL = "📋 *Request #{request_number}*\n\n" .
        "📦 *Looking for:*\n{description}\n\n" .
        "📁 Category: {category}\n" .
        "📊 Status: {status}\n" .
        "📬 Responses: {response_count}\n" .
        "⏰ Expires: {expiry_time}";

    // FR-PRD-35: Allow customer to close request when satisfied
    public const REQUEST_CLOSED = "✅ *Request Closed*\n\n" .
        "Request #{request_number} is now closed.\n\n" .
        "Thank you for using NearBuy!";

    public const CLOSE_REQUEST_CONFIRM = "🔒 *Close Request?*\n\n" .
        "This will:\n" .
        "• Stop accepting new responses\n" .
        "• Keep existing responses visible\n\n" .
        "Continue?";

    /*
    |--------------------------------------------------------------------------
    | Shop Notification Messages (FR-PRD-10 to FR-PRD-14)
    |--------------------------------------------------------------------------
    */

    // FR-PRD-11: Send immediate notifications for shops with immediate preference
    public const NEW_REQUEST_NOTIFICATION = "🔔 *New Product Request*\n\n" .
        "📦 *Looking for:*\n{description}\n\n" .
        "📁 Category: {category}\n" .
        "📍 Customer: {distance} away\n" .
        "⏰ Expires: {time_remaining}\n\n" .
        "📋 #{request_number}";

    // FR-PRD-12: Batch requests for shops with delayed preferences
    public const BATCH_NOTIFICATION = "🔔 *{count} New Request(s)*\n\n" .
        "Customers near you are looking for products.\n\n" .
        "Tap below to view and respond.";

    // FR-PRD-14: Provide Yes I have / Don't have / Skip response options
    public const RESPOND_PROMPT = "Do you have this product?";

    public const RESPOND_NO_THANKS = "👍 Got it! You won't see this request again.";

    public const RESPOND_SKIPPED = "⏭️ Skipped. You can respond later from 'Pending Requests'.";

    /*
    |--------------------------------------------------------------------------
    | Shop Response Flow Messages (FR-PRD-20 to FR-PRD-23)
    |--------------------------------------------------------------------------
    */

    // FR-PRD-20: Prompt for product photo upon positive response
    public const ASK_PHOTO = "📸 *Send Product Photo*\n\n" .
        "Take a photo of the actual product.\n\n" .
        "Or type *skip* to continue without photo.";

    // FR-PRD-21: Collect price and model information via free-text
    public const ASK_PRICE = "💰 *Enter Price*\n\n" .
        "What's your price for this product?\n\n" .
        "_Just type the number, e.g., 15000_";

    public const ASK_PRICE_DETAILS = "💰 *Price & Details*\n\n" .
        "Enter price and any details:\n\n" .
        "_Example: 15000 - Black color, 1 year warranty_";

    public const ASK_DETAILS = "📝 *Add Details (Optional)*\n\n" .
        "Any additional info?\n" .
        "• Condition (new/used)\n" .
        "• Warranty\n" .
        "• Availability\n\n" .
        "Or type *skip*.";

    public const RESPONSE_CONFIRM = "📋 *Confirm Response*\n\n" .
        "📦 Request: {request_description}\n\n" .
        "✅ Available: {available}\n" .
        "💰 Price: ₹{price}\n" .
        "📝 Details: {description}\n" .
        "📷 Photo: {has_photo}\n\n" .
        "Send this response?";

    // FR-PRD-22: Store response with photo URL, price, and description
    public const RESPONSE_SENT = "✅ *Response Sent!*\n\n" .
        "Your response is on its way to the customer.\n\n" .
        "💰 Your price: ₹{price}\n" .
        "📋 Request #{request_number}\n\n" .
        "_If interested, they'll contact you directly._";

    // FR-PRD-23: Prevent duplicate responses from same shop
    public const ALREADY_RESPONDED = "⚠️ *Already Responded*\n\n" .
        "You've already responded to this request.\n\n" .
        "Your response: ₹{price}";

    public const REQUEST_NO_LONGER_ACTIVE = "⚠️ *Request Closed*\n\n" .
        "This request is no longer accepting responses.\n\n" .
        "It may have expired or been closed by the customer.";

    /*
    |--------------------------------------------------------------------------
    | Shop Pending Requests Messages
    |--------------------------------------------------------------------------
    */

    public const PENDING_REQUESTS_HEADER = "📬 *Product Requests*\n\n" .
        "You have {count} pending request(s) nearby:";

    public const PENDING_REQUESTS_EMPTY = "📭 *No Pending Requests*\n\n" .
        "No product requests from customers in your area right now.\n\n" .
        "We'll notify you when someone needs something!";

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    */

    public const ERROR_INVALID_DESCRIPTION = "⚠️ Please provide more details (at least 10 characters).\n\n" .
        "_Be specific: product name, brand, size, etc._";

    public const ERROR_INVALID_PRICE = "⚠️ Invalid price.\n\n" .
        "Please enter a number.\n" .
        "_Example: 15000_";

    public const ERROR_REQUEST_NOT_FOUND = "❌ Request not found or has expired.";

    public const ERROR_NO_LOCATION = "📍 *Location Required*\n\n" .
        "Share your location to search nearby shops.";

    /*
    |--------------------------------------------------------------------------
    | Button Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * Search radius buttons.
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
     * Request confirmation buttons.
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
     * Alias for getConfirmButtons.
     */
    public static function getConfirmRequestButtons(): array
    {
        return self::getConfirmButtons();
    }

    /**
     * Shop response choice buttons (FR-PRD-14).
     */
    public static function getResponseChoiceButtons(): array
    {
        return [
            ['id' => 'available', 'title' => '✅ Yes, I have it'],
            ['id' => 'unavailable', 'title' => "❌ Don't have"],
            ['id' => 'skip', 'title' => '⏭️ Skip'],
        ];
    }

    /**
     * Alias for shop response buttons.
     */
    public static function getRespondChoiceButtons(): array
    {
        return [
            ['id' => 'yes', 'title' => '✅ Yes, I have it'],
            ['id' => 'no', 'title' => "❌ Don't have"],
            ['id' => 'skip', 'title' => '⏭️ Skip'],
        ];
    }

    /**
     * Response confirmation buttons.
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
     * Alias for response confirm buttons.
     */
    public static function getConfirmResponseButtons(): array
    {
        return [
            ['id' => 'send', 'title' => '✅ Send'],
            ['id' => 'edit', 'title' => '✏️ Edit Price'],
            ['id' => 'cancel', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * Response action buttons (FR-PRD-34).
     */
    public static function getResponseActionButtons(): array
    {
        return [
            ['id' => 'location', 'title' => '📍 Get Location'],
            ['id' => 'contact', 'title' => '📞 Call Shop'],
            ['id' => 'back', 'title' => '⬅️ More Responses'],
        ];
    }

    /**
     * Request management buttons.
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
     * Post-request buttons.
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
     * Empty requests buttons.
     */
    public static function getEmptyRequestsButtons(): array
    {
        return [
            ['id' => 'new_search', 'title' => '🔍 Search Product'],
            ['id' => 'menu', 'title' => '🏠 Main Menu'],
        ];
    }

    /**
     * Post-response buttons for shop.
     */
    public static function getPostResponseButtons(): array
    {
        return [
            ['id' => 'more_requests', 'title' => '📬 More Requests'],
            ['id' => 'menu', 'title' => '🏠 Main Menu'],
        ];
    }

    /**
     * Close request confirmation buttons.
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
    | List Configurations (Max 10 items per WhatsApp API)
    |--------------------------------------------------------------------------
    */

    /**
     * Get category sections for product search (FR-PRD-01).
     */
    public static function getCategorySections(): array
    {
        return [
            [
                'title' => 'Popular',
                'rows' => [
                    ['id' => 'electronics', 'title' => '📱 Electronics', 'description' => 'Gadgets & devices'],
                    ['id' => 'mobile', 'title' => '📲 Mobile', 'description' => 'Phones & accessories'],
                    ['id' => 'clothes', 'title' => '👕 Clothes', 'description' => 'Fashion & apparel'],
                    ['id' => 'grocery', 'title' => '🛒 Grocery', 'description' => 'Daily essentials'],
                    ['id' => 'medical', 'title' => '💊 Medical', 'description' => 'Pharmacy & health'],
                ],
            ],
            [
                'title' => 'More Categories',
                'rows' => [
                    ['id' => 'appliances', 'title' => '🔌 Appliances', 'description' => 'Home appliances'],
                    ['id' => 'furniture', 'title' => '🪑 Furniture', 'description' => 'Home & office'],
                    ['id' => 'hardware', 'title' => '🔧 Hardware', 'description' => 'Tools & materials'],
                    ['id' => 'all', 'title' => '🔍 All Categories', 'description' => 'Search all shops'],
                    ['id' => 'other', 'title' => '📦 Other', 'description' => 'Other categories'],
                ],
            ],
        ];
    }

    /**
     * Build responses list for customer view (FR-PRD-32).
     */
    public static function buildResponsesList(array $responses): array
    {
        $rows = [];
        $lowestPrice = null;

        // Find lowest price for comparison
        foreach ($responses as $response) {
            if (($response['is_available'] ?? true) && isset($response['price'])) {
                if ($lowestPrice === null || $response['price'] < $lowestPrice) {
                    $lowestPrice = $response['price'];
                }
            }
        }

        foreach ($responses as $index => $response) {
            $shop = $response['shop'] ?? [];
            $shopName = $shop['shop_name'] ?? 'Shop';
            $price = $response['price'] ?? 0;
            $distance = isset($response['distance_km']) ? self::formatDistance($response['distance_km']) : '';
            $available = $response['is_available'] ?? true;

            if ($available) {
                $priceStr = '₹' . number_format($price);
                // Add "Best" tag if lowest price
                if ($price === $lowestPrice && count($responses) > 1) {
                    $priceStr .= ' ⭐';
                }
                $title = self::truncate("{$priceStr} - {$shopName}", 24);
                $desc = "{$distance} away";
            } else {
                $title = self::truncate("❌ {$shopName}", 24);
                $desc = 'Not available';
            }

            $rows[] = [
                'id' => 'response_' . ($response['id'] ?? $index),
                'title' => $title,
                'description' => self::truncate($desc, 72),
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
                'description' => self::truncate(
                    "{$statusEmoji} {$responseCount} response" . ($responseCount !== 1 ? 's' : '') .
                    " • #{$request['request_number']}",
                    72
                ),
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
            $distance = isset($request['distance_km']) ? self::formatDistance($request['distance_km']) : '';
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
        if ($distanceKm < 0.1) {
            return 'Very close';
        }

        if ($distanceKm < 1) {
            $meters = round($distanceKm * 1000, -1);
            return "{$meters}m";
        }

        return round($distanceKm, 1) . 'km';
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
     * Format time remaining until expiry.
     */
    public static function formatTimeRemaining(Carbon|string|null $expiresAt): string
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

        $diff = now()->diff($expiresAt);

        if ($diff->d > 0) {
            return $diff->d . 'd ' . $diff->h . 'h';
        }

        if ($diff->h > 0) {
            return $diff->h . 'h ' . $diff->i . 'm';
        }

        return $diff->i . ' min';
    }

    /**
     * Format expiry time.
     */
    public static function formatExpiry(Carbon|string|null $expiresAt): string
    {
        if (!$expiresAt) {
            return 'Unknown';
        }

        if (is_string($expiresAt)) {
            $expiresAt = Carbon::parse($expiresAt);
        }

        if ($expiresAt->isToday()) {
            return 'Today ' . $expiresAt->format('g:i A');
        }

        if ($expiresAt->isTomorrow()) {
            return 'Tomorrow ' . $expiresAt->format('g:i A');
        }

        return $expiresAt->format('M j, g:i A');
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
            'mobile' => '📲 Mobile',
            'appliances' => '🔌 Appliances',
            'hardware' => '🔧 Hardware',
            'furniture' => '🪑 Furniture',
            'all' => '🔍 All Categories',
            'other' => '📦 Other',
        ];

        return $labels[strtolower($categoryId)] ?? ucfirst($categoryId);
    }

    /**
     * Get human-readable status label.
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'open' => '🟢 Open',
            'collecting' => '🟡 Collecting',
            'closed' => '✅ Closed',
            'expired' => '⏰ Expired',
            default => ucfirst($status),
        };
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
            'search_start' => self::SEARCH_START,
            'ask_category' => self::ASK_CATEGORY,
            'ask_description' => self::ASK_DESCRIPTION,
            'no_responses' => self::NO_RESPONSES,
            'request_sent' => self::REQUEST_SENT,
        ];
    }

    /**
     * Malayalam messages.
     */
    protected static function getMalayalamMessages(): array
    {
        return [
            'search_start' => "🔍 *സമീപത്ത് ഉൽപ്പന്നങ്ങൾ കണ്ടെത്തുക*\n\n" .
                "നിങ്ങൾക്ക് ആവശ്യമുള്ളത് കണ്ടെത്താൻ കഴിയുന്നില്ലേ? പ്രാദേശിക ഷോപ്പുകളെ സഹായിക്കാൻ അനുവദിക്കൂ!",
            'ask_category' => "📦 *ഘട്ടം 1/3* - വിഭാഗം തിരഞ്ഞെടുക്കുക\n\n" .
                "നിർദ്ദിഷ്ട ഷോപ്പുകളെ ലക്ഷ്യമിടാൻ ഒരു വിഭാഗം തിരഞ്ഞെടുക്കുക:",
            'ask_description' => "📝 *ഘട്ടം 2/3* - ഉൽപ്പന്നം വിവരിക്കുക\n\n" .
                "നിങ്ങൾ എന്താണ് തിരയുന്നത്?",
            'no_responses' => "⏳ *ഇതുവരെ പ്രതികരണങ്ങളില്ല*\n\n" .
                "അഭ്യർത്ഥന #{request_number}\n\n" .
                "ഷോപ്പുകളെ അറിയിച്ചു. പ്രതികരണങ്ങൾ സാധാരണയായി 1-2 മണിക്കൂറിനുള്ളിൽ വരും.",
            'request_sent' => "✅ *അഭ്യർത്ഥന അയച്ചു!*\n\n" .
                "📋 അഭ്യർത്ഥന #: *{request_number}*\n" .
                "🏪 അറിയിച്ചത്: {shop_count} ഷോപ്പുകൾ",
        ];
    }
}