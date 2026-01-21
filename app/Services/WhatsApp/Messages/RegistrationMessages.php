<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use App\Enums\ShopCategory;
use App\Enums\NotificationFrequency;

/**
 * Message templates for the registration flow.
 *
 * VIRAL ADOPTION OPTIMIZATIONS:
 * - Progress indicators (Step X of Y) reduce abandonment
 * - Friendly, conversational tone builds trust
 * - Minimal friction with smart defaults
 * - Referral hooks for organic growth
 * - Bilingual support (English + Malayalam)
 *
 * @see SRS Section 3.1 - User Registration Requirements
 * @see NFR-U-01 - Registration shall complete within 5 interactions
 */
class RegistrationMessages
{
    /*
    |--------------------------------------------------------------------------
    | Language Configuration
    |--------------------------------------------------------------------------
    */

    private const DEFAULT_LANG = 'en';

    /**
     * Get message in specified language.
     */
    public static function get(string $key, string $lang = 'en'): string
    {
        $messages = self::getMessages($lang);
        return $messages[$key] ?? self::getEnglishMessages()[$key] ?? "Message not found: {$key}";
    }

    /**
     * Get all messages for a language.
     */
    protected static function getMessages(string $lang): array
    {
        return match ($lang) {
            'ml' => self::getMalayalamMessages(),
            default => self::getEnglishMessages(),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | English Messages
    |--------------------------------------------------------------------------
    */

    protected static function getEnglishMessages(): array
    {
        return [
            // Welcome & Introduction
            'welcome_new' => "🙏 *Welcome to NearBuy!*\n\n" .
                "Your neighborhood marketplace on WhatsApp.\n\n" .
                "• 🛍️ Discover local offers\n" .
                "• 🔍 Find products nearby\n" .
                "• 📝 Digital agreements\n\n" .
                "Quick setup - just 3 steps! ⚡",

            'welcome_referred' => "🙏 *Welcome to NearBuy!*\n\n" .
                "You were invited by *{referrer_name}* 🎉\n\n" .
                "Join {user_count}+ neighbors already using NearBuy.\n\n" .
                "Quick setup - just 3 steps! ⚡",

            'welcome_back_incomplete' => "👋 *Welcome back!*\n\n" .
                "Let's finish your registration.\n" .
                "You were at: *{last_step}*\n\n" .
                "Continue or start fresh?",

            // Step 1: User Type (Customer Flow: 1 of 3)
            'ask_type' => "*Step 1 of 3* 📝\n\n" .
                "How will you use NearBuy?",

            'ask_type_shop' => "*Step 1 of 5* 📝\n\n" .
                "How will you use NearBuy?",

            // Step 2: Name
            'ask_name_customer' => "*Step 2 of 3* 📝\n\n" .
                "What should we call you?\n\n" .
                "_Type your name below_",

            'ask_name_shop' => "*Step 2 of 5* 📝\n\n" .
                "First, your name as the shop owner.\n\n" .
                "_Type your name below_",

            'name_acknowledged' => "Nice to meet you, *{name}*! 👋",

            // Step 3: Location (Final for Customer)
            'ask_location_customer' => "*Step 3 of 3* 📍\n\n" .
                "Share your location to see nearby offers and shops.\n\n" .
                "🔒 Your exact location stays private - we only use it to find shops near you.",

            'ask_location_shop_owner' => "*Step 3 of 5* 📍\n\n" .
                "Share your personal location.\n\n" .
                "_This is for delivery coordination, not shown publicly._",

            // Shop-specific steps
            'ask_shop_name' => "*Step 4 of 5* 🏪\n\n" .
                "What's your shop/business name?\n\n" .
                "_This is how customers will find you_",

            'ask_shop_category' => "*Step 4 of 5* 📦\n\n" .
                "Select your shop category:",

            'ask_shop_location' => "*Step 5 of 5* 📍\n\n" .
                "Now share your *shop's location*.\n\n" .
                "This helps customers find and navigate to your store.",

            'ask_shop_location_same' => "*Step 5 of 5* 📍\n\n" .
                "Is your shop at the same location you just shared?\n\n" .
                "Or share a different location for your shop.",

            'ask_notification_pref' => "🔔 *One last thing!*\n\n" .
                "How often should we notify you about customer requests?\n\n" .
                "_You can change this anytime_",

            // Confirmation
            'confirm_customer' => "✅ *Almost done!*\n\n" .
                "*Name:* {name}\n" .
                "*Location:* 📍 Saved\n\n" .
                "Everything correct?",

            'confirm_shop' => "✅ *Almost done!*\n\n" .
                "*Owner:* {name}\n" .
                "*Shop:* {shop_name}\n" .
                "*Category:* {category}\n" .
                "*Location:* 📍 Saved\n" .
                "*Alerts:* {notification_pref}\n\n" .
                "Everything correct?",

            // Completion - CRITICAL FOR VIRAL ADOPTION
            'complete_customer' => "🎉 *You're all set, {name}!*\n\n" .
                "Welcome to your local marketplace.\n\n" .
                "What would you like to do first?",

            'complete_shop' => "🎉 *Congratulations, {name}!*\n\n" .
                "*{shop_name}* is now live! 🏪\n\n" .
                "You'll start receiving customer requests from your area.\n\n" .
                "What's next?",

            // Referral prompt (shown after completion)
            'referral_prompt' => "📢 *Spread the word!*\n\n" .
                "Share NearBuy with fellow shop owners:\n\n" .
                "👉 wa.me/{bot_number}?text=Hi%20NearBuy\n\n" .
                "_The more shops join, the better for everyone!_",

            // Error Messages
            'error_invalid_name' => "⚠️ Please enter a valid name (2-100 characters).\n\n" .
                "_Just type your name below_",

            'error_invalid_shop_name' => "⚠️ Please enter a valid shop name.\n\n" .
                "_Example: Krishna Stores, Fresh Mart_",

            'error_phone_exists' => "👋 You're already registered!\n\n" .
                "Type *menu* to see your options.",

            'error_location_required' => "📍 We need your location to continue.\n\n" .
                "Tap the button below to share.",

            'error_select_type' => "Please tap one of the buttons above ☝️",

            'error_select_category' => "Please select a category from the list.",

            'error_select_notification' => "Please select a notification option.",

            'error_generic' => "Something went wrong. Let's try again.",

            // Cancel/Restart
            'restart_message' => "🔄 Starting fresh...\n\nYour previous answers have been cleared.",

            'cancel_message' => "❌ Registration cancelled.\n\n" .
                "No worries! Type *register* whenever you're ready.",
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Malayalam Messages (Localization Support - NFR-U-05)
    |--------------------------------------------------------------------------
    */

    protected static function getMalayalamMessages(): array
    {
        return [
            'welcome_new' => "🙏 *NearBuy-ലേക്ക് സ്വാഗതം!*\n\n" .
                "WhatsApp-ൽ നിങ്ങളുടെ നാട്ടിലെ മാർക്കറ്റ്പ്ലേസ്.\n\n" .
                "• 🛍️ ലോക്കൽ ഓഫറുകൾ കാണുക\n" .
                "• 🔍 സമീപത്തുള്ള ഉൽപ്പന്നങ്ങൾ കണ്ടെത്തുക\n" .
                "• 📝 ഡിജിറ്റൽ എഗ്രിമെന്റുകൾ\n\n" .
                "3 സ്റ്റെപ്പുകളിൽ സെറ്റപ്പ് ചെയ്യാം! ⚡",

            'ask_type' => "*സ്റ്റെപ്പ് 1/3* 📝\n\n" .
                "നിങ്ങൾ NearBuy എങ്ങനെ ഉപയോഗിക്കും?",

            'ask_name_customer' => "*സ്റ്റെപ്പ് 2/3* 📝\n\n" .
                "നിങ്ങളുടെ പേര് എന്താണ്?\n\n" .
                "_താഴെ ടൈപ്പ് ചെയ്യുക_",

            'ask_location_customer' => "*സ്റ്റെപ്പ് 3/3* 📍\n\n" .
                "നിങ്ങളുടെ ലൊക്കേഷൻ ഷെയർ ചെയ്യുക.\n\n" .
                "🔒 നിങ്ങളുടെ കൃത്യമായ ലൊക്കേഷൻ സ്വകാര്യമാണ്.",

            'complete_customer' => "🎉 *{name}, നിങ്ങൾ റെഡി!*\n\n" .
                "നിങ്ങളുടെ ലോക്കൽ മാർക്കറ്റ്പ്ലേസിലേക്ക് സ്വാഗതം.\n\n" .
                "ആദ്യം എന്ത് ചെയ്യണം?",

            'ask_name_shop' => "*സ്റ്റെപ്പ് 2/5* 📝\n\n" .
                "ആദ്യം, ഷോപ്പ് ഉടമയുടെ പേര്.\n\n" .
                "_താഴെ ടൈപ്പ് ചെയ്യുക_",

            'ask_shop_name' => "*സ്റ്റെപ്പ് 4/5* 🏪\n\n" .
                "നിങ്ങളുടെ ഷോപ്പിന്റെ പേര് എന്താണ്?",

            'complete_shop' => "🎉 *അഭിനന്ദനങ്ങൾ, {name}!*\n\n" .
                "*{shop_name}* ഇപ്പോൾ ലൈവാണ്! 🏪",

            'error_invalid_name' => "⚠️ ദയവായി ശരിയായ പേര് നൽകുക (2-100 അക്ഷരങ്ങൾ).",

            'error_location_required' => "📍 തുടരാൻ ലൊക്കേഷൻ ആവശ്യമാണ്.",
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Button Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * User type selection buttons.
     * Limited to 3 buttons per WhatsApp API constraint.
     */
    public static function getUserTypeButtons(): array
    {
        return [
            ['id' => 'customer', 'title' => '🛒 Customer'],
            ['id' => 'shop', 'title' => '🏪 Shop Owner'],
        ];
    }

    /**
     * Continue/restart buttons for incomplete registrations.
     */
    public static function getContinueButtons(): array
    {
        return [
            ['id' => 'continue', 'title' => '▶️ Continue'],
            ['id' => 'restart', 'title' => '🔄 Start Fresh'],
        ];
    }

    /**
     * Same location option for shop - reduces friction.
     */
    public static function getShopLocationButtons(): array
    {
        return [
            ['id' => 'same_location', 'title' => '📍 Same Location'],
            ['id' => 'different', 'title' => '🗺️ Different Place'],
        ];
    }

    /**
     * Confirmation buttons.
     */
    public static function getConfirmButtons(): array
    {
        return [
            ['id' => 'confirm', 'title' => '✅ Confirm'],
            ['id' => 'edit', 'title' => '✏️ Edit'],
            ['id' => 'cancel', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * Post-registration buttons for customers.
     * Designed to drive immediate engagement.
     */
    public static function getCustomerNextButtons(): array
    {
        return [
            ['id' => 'browse_offers', 'title' => '🛍️ See Offers'],
            ['id' => 'search_product', 'title' => '🔍 Find Product'],
            ['id' => 'menu', 'title' => '📋 Main Menu'],
        ];
    }

    /**
     * Post-registration buttons for shop owners.
     * "Upload Offer" first to drive immediate value creation.
     */
    public static function getShopNextButtons(): array
    {
        return [
            ['id' => 'upload_offer', 'title' => '📤 Upload Offer'],
            ['id' => 'view_requests', 'title' => '📬 View Requests'],
            ['id' => 'menu', 'title' => '📋 Main Menu'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | List Configurations (Max 10 items per WhatsApp API)
    |--------------------------------------------------------------------------
    */

    /**
     * Shop category list.
     * Top 10 categories from SRS Appendix 8.1.
     */
    public static function getCategorySections(): array
    {
        return [
            [
                'title' => 'Select Category',
                'rows' => [
                    ['id' => 'grocery', 'title' => '🛒 Grocery', 'description' => 'Vegetables, fruits, daily needs'],
                    ['id' => 'electronics', 'title' => '📱 Electronics', 'description' => 'TV, laptop, gadgets'],
                    ['id' => 'clothes', 'title' => '👕 Clothes', 'description' => 'Fashion & textiles'],
                    ['id' => 'medical', 'title' => '💊 Medical', 'description' => 'Pharmacy & health'],
                    ['id' => 'furniture', 'title' => '🪑 Furniture', 'description' => 'Home & office'],
                    ['id' => 'mobile', 'title' => '📲 Mobile', 'description' => 'Phones & accessories'],
                    ['id' => 'appliances', 'title' => '🔌 Appliances', 'description' => 'AC, fridge, washing machine'],
                    ['id' => 'hardware', 'title' => '🔧 Hardware', 'description' => 'Tools & construction'],
                    ['id' => 'restaurant', 'title' => '🍽️ Restaurant', 'description' => 'Food & dining'],
                    ['id' => 'other', 'title' => '📦 Other', 'description' => 'Other categories'],
                ],
            ],
        ];
    }

    /**
     * Notification frequency list.
     * From SRS Appendix 8.3.
     */
    public static function getNotificationSections(): array
    {
        return [
            [
                'title' => 'Alert Frequency',
                'rows' => [
                    [
                        'id' => 'immediate',
                        'title' => '🔔 Immediately',
                        'description' => 'Each request as it arrives',
                    ],
                    [
                        'id' => '2hours',
                        'title' => '⏰ Every 2 Hours',
                        'description' => 'Batched (Recommended)',
                    ],
                    [
                        'id' => 'twice_daily',
                        'title' => '📅 Twice Daily',
                        'description' => 'Morning 9AM & Evening 5PM',
                    ],
                    [
                        'id' => 'daily',
                        'title' => '🌅 Once Daily',
                        'description' => 'Morning 9AM only',
                    ],
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Format a message template with placeholders.
     *
     * @param string $template Message with {placeholder} syntax
     * @param array $data Key-value pairs for replacement
     * @return string
     */
    public static function format(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace("{{$key}}", (string) $value, $template);
        }

        return $template;
    }

    /**
     * Get localized message with formatting.
     */
    public static function getFormatted(string $key, array $data = [], string $lang = 'en'): string
    {
        $template = self::get($key, $lang);
        return self::format($template, $data);
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
            'other' => '📦 Other',
        ];

        return $labels[$categoryId] ?? ucfirst($categoryId);
    }

    /**
     * Get human-readable notification label.
     */
    public static function getNotificationLabel(string $prefId): string
    {
        $labels = [
            'immediate' => '🔔 Immediately',
            '2hours' => '⏰ Every 2 Hours',
            'twice_daily' => '📅 Twice Daily',
            'daily' => '🌅 Once Daily',
        ];

        return $labels[$prefId] ?? ucfirst($prefId);
    }

    /**
     * Get step description for welcome back message.
     */
    public static function getStepDescription(string $step): string
    {
        $steps = [
            'ask_type' => 'Selecting account type',
            'ask_name' => 'Entering your name',
            'ask_location' => 'Sharing location',
            'ask_shop_name' => 'Entering shop name',
            'ask_shop_category' => 'Selecting category',
            'ask_shop_location' => 'Shop location',
            'ask_notification_pref' => 'Notification settings',
            'confirm' => 'Confirmation',
        ];

        return $steps[$step] ?? 'Registration';
    }

    /*
    |--------------------------------------------------------------------------
    | Legacy Constants (Backward Compatibility)
    |--------------------------------------------------------------------------
    */

    public const WELCOME_NEW_USER = "🙏 *Welcome to NearBuy!*\n\nYour neighborhood marketplace on WhatsApp.\n\nQuick setup - just 3 steps! ⚡";
    public const WELCOME_BACK_INCOMPLETE = "👋 *Welcome back!*\n\nLet's finish your registration.";
    public const ASK_USER_TYPE = "*Step 1 of 3* 📝\n\nHow will you use NearBuy?";
    public const ASK_NAME = "*Step 2 of 3* 📝\n\nWhat should we call you?\n\n_Type your name below_";
    public const ASK_NAME_SHOP = "*Step 2 of 5* 📝\n\nFirst, your name as the shop owner.\n\n_Type your name below_";
    public const ASK_LOCATION = "*Step 3 of 3* 📍\n\nShare your location to see nearby offers and shops.";
    public const ASK_SHOP_NAME = "*Step 4 of 5* 🏪\n\nWhat's your shop/business name?";
    public const ASK_SHOP_CATEGORY = "*Step 4 of 5* 📦\n\nSelect your shop category:";
    public const ASK_SHOP_LOCATION = "*Step 5 of 5* 📍\n\nNow share your *shop's location*.";
    public const ASK_NOTIFICATION_PREF = "🔔 *One last thing!*\n\nHow often should we notify you about customer requests?";
    public const CONFIRM_CUSTOMER = "✅ *Almost done!*\n\n*Name:* {name}\n*Location:* 📍 Saved\n\nEverything correct?";
    public const CONFIRM_SHOP = "✅ *Almost done!*\n\n*Owner:* {name}\n*Shop:* {shop_name}\n*Category:* {category}\n*Location:* 📍 Saved\n*Alerts:* {notification_pref}\n\nEverything correct?";
    public const COMPLETE_CUSTOMER = "🎉 *You're all set, {name}!*\n\nWelcome to your local marketplace.\n\nWhat would you like to do first?";
    public const COMPLETE_SHOP = "🎉 *Congratulations, {name}!*\n\n*{shop_name}* is now live! 🏪\n\nWhat's next?";
    public const ERROR_INVALID_NAME = "⚠️ Please enter a valid name (2-100 characters).";
    public const ERROR_INVALID_SHOP_NAME = "⚠️ Please enter a valid shop name.";
    public const ERROR_PHONE_EXISTS = "👋 You're already registered!\n\nType *menu* to see your options.";
    public const ERROR_LOCATION_REQUIRED = "📍 We need your location to continue.";
    public const ERROR_SELECT_TYPE = "Please tap one of the buttons above ☝️";
    public const ERROR_SELECT_CATEGORY = "Please select a category from the list.";
    public const ERROR_SELECT_NOTIFICATION = "Please select a notification option.";
}