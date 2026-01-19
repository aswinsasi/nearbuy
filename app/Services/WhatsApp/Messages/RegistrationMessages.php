<?php

namespace App\Services\WhatsApp\Messages;

use App\Enums\ShopCategory;
use App\Enums\NotificationFrequency;

/**
 * Message templates for the registration flow.
 *
 * Contains all user-facing messages, buttons, and list options
 * for the registration process.
 */
class RegistrationMessages
{
    /*
    |--------------------------------------------------------------------------
    | Welcome & Introduction
    |--------------------------------------------------------------------------
    */

    public const WELCOME_NEW_USER = "👋 Welcome to *NearBuy*!\n\nYour local marketplace on WhatsApp.\n\nLet's get you set up in just a few steps.";

    public const WELCOME_BACK_INCOMPLETE = "👋 Welcome back!\n\nLooks like you didn't finish registering. Let's continue where you left off.";

    /*
    |--------------------------------------------------------------------------
    | Step Messages
    |--------------------------------------------------------------------------
    */

    public const ASK_USER_TYPE = "Are you joining as a *customer* or a *shop owner*?\n\n👤 *Customer* - Browse offers, search products, create agreements\n\n🏪 *Shop Owner* - Upload offers, respond to customer requests";

    public const ASK_NAME = "Great choice! 👍\n\nWhat's your name?";

    public const ASK_NAME_SHOP = "Great! Let's set up your shop. 🏪\n\nFirst, what's your name? (Shop owner name)";

    public const ASK_LOCATION = "📍 *Share Your Location*\n\nThis helps us show you offers and shops near you.\n\nTap the button below to share your current location.";

    public const ASK_SHOP_NAME = "What's your *shop name*?\n\nThis is how customers will see your business.";

    public const ASK_SHOP_CATEGORY = "📦 *Shop Category*\n\nSelect the category that best describes your shop:";

    public const ASK_SHOP_LOCATION = "📍 *Shop Location*\n\nShare your shop's location so customers can find you.\n\n(This can be different from your personal location)";

    public const ASK_NOTIFICATION_PREF = "🔔 *Notification Preferences*\n\nHow often would you like to receive product request notifications from nearby customers?";

    /*
    |--------------------------------------------------------------------------
    | Confirmation Messages
    |--------------------------------------------------------------------------
    */

    public const CONFIRM_CUSTOMER = "📋 *Confirm Your Details*\n\n*Name:* {name}\n*Location:* ✅ Saved\n\nIs this correct?";

    public const CONFIRM_SHOP = "📋 *Confirm Your Details*\n\n*Owner:* {name}\n*Shop:* {shop_name}\n*Category:* {category}\n*Location:* ✅ Saved\n*Notifications:* {notification_pref}\n\nIs this correct?";

    /*
    |--------------------------------------------------------------------------
    | Completion Messages
    |--------------------------------------------------------------------------
    */

    public const COMPLETE_CUSTOMER = "🎉 *Registration Complete!*\n\nWelcome to NearBuy, *{name}*!\n\nYou can now:\n• 🛍️ Browse offers from nearby shops\n• 🔍 Search for products locally\n• 📝 Create digital agreements\n\nLet's get started!";

    public const COMPLETE_SHOP = "🎉 *Registration Complete!*\n\nWelcome to NearBuy, *{name}*!\n\nYour shop *{shop_name}* is now live! 🏪\n\nYou can now:\n• 📤 Upload offers for customers\n• 📬 Respond to product requests\n• 📝 Create digital agreements\n\nLet's get your first offer uploaded!";

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    */

    public const ERROR_INVALID_NAME = "⚠️ Please enter a valid name (2-100 characters, letters only).";

    public const ERROR_INVALID_SHOP_NAME = "⚠️ Please enter a valid shop name (2-100 characters).";

    public const ERROR_PHONE_EXISTS = "⚠️ This phone number is already registered.\n\nIf this is your account, you're all set! Type 'menu' to continue.";

    public const ERROR_LOCATION_REQUIRED = "📍 Location is required to continue.\n\nPlease tap the button below to share your location.";

    public const ERROR_SELECT_TYPE = "Please select one of the options above:\n\n👤 *Customer* - To browse and search\n🏪 *Shop Owner* - To sell and offer";

    public const ERROR_SELECT_CATEGORY = "Please select a category from the list above.";

    public const ERROR_SELECT_NOTIFICATION = "Please select a notification preference from the list.";

    /*
    |--------------------------------------------------------------------------
    | Button Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * Get user type selection buttons.
     */
    public static function getUserTypeButtons(): array
    {
        return [
            ['id' => 'customer', 'title' => '👤 Customer'],
            ['id' => 'shop', 'title' => '🏪 Shop Owner'],
        ];
    }

    /**
     * Get confirmation buttons.
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
     * Get post-registration buttons for customers.
     */
    public static function getCustomerNextButtons(): array
    {
        return [
            ['id' => 'browse_offers', 'title' => '🛍️ Browse Offers'],
            ['id' => 'search_product', 'title' => '🔍 Search Product'],
            ['id' => 'menu', 'title' => '📋 Main Menu'],
        ];
    }

    /**
     * Get post-registration buttons for shop owners.
     */
    public static function getShopNextButtons(): array
    {
        return [
            ['id' => 'upload_offer', 'title' => '📤 Upload Offer'],
            ['id' => 'shop_profile', 'title' => '🏪 Shop Profile'],
            ['id' => 'menu', 'title' => '📋 Main Menu'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | List Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * Get shop category list sections.
     * Limited to 10 items total as per WhatsApp requirements.
     */
    public static function getCategorySections(): array
    {
        $categories = [
            ['id' => 'grocery', 'title' => '🛒 Grocery', 'description' => 'Daily essentials & food items'],
            ['id' => 'electronics', 'title' => '📱 Electronics', 'description' => 'Gadgets & electronic items'],
            ['id' => 'clothes', 'title' => '👕 Clothes', 'description' => 'Fashion & apparel'],
            ['id' => 'medical', 'title' => '💊 Medical', 'description' => 'Pharmacy & health products'],
            ['id' => 'restaurant', 'title' => '🍽️ Restaurant', 'description' => 'Food & dining'],
            ['id' => 'furniture', 'title' => '🪑 Furniture', 'description' => 'Home & office furniture'],
            ['id' => 'beauty', 'title' => '💄 Beauty', 'description' => 'Cosmetics & personal care'],
            ['id' => 'hardware', 'title' => '🔧 Hardware', 'description' => 'Tools & building materials'],
            ['id' => 'automotive', 'title' => '🚗 Automotive', 'description' => 'Vehicle parts & services'],
            ['id' => 'other', 'title' => '📦 Other', 'description' => 'Other categories'],
        ];

        return [
            [
                'title' => 'Shop Categories',
                'rows' => $categories,
            ],
        ];
    }

    /**
     * Get notification preference list.
     */
    public static function getNotificationSections(): array
    {
        return [
            [
                'title' => 'Notification Frequency',
                'rows' => [
                    [
                        'id' => 'immediate',
                        'title' => '🔔 Immediately',
                        'description' => 'Get notified for each request',
                    ],
                    [
                        'id' => '2hours',
                        'title' => '⏰ Every 2 Hours',
                        'description' => 'Batched notifications',
                    ],
                    [
                        'id' => 'twice_daily',
                        'title' => '📅 Twice Daily',
                        'description' => 'Morning & evening summary',
                    ],
                    [
                        'id' => 'daily',
                        'title' => '🌅 Once Daily',
                        'description' => 'Daily morning summary',
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
     * Get category label by ID.
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
            'other' => '📦 Other',
        ];

        return $map[$categoryId] ?? ucfirst($categoryId);
    }

    /**
     * Get notification preference label by ID.
     */
    public static function getNotificationLabel(string $prefId): string
    {
        $map = [
            'immediate' => '🔔 Immediately',
            '2hours' => '⏰ Every 2 Hours',
            'twice_daily' => '📅 Twice Daily',
            'daily' => '🌅 Once Daily',
        ];

        return $map[$prefId] ?? ucfirst($prefId);
    }
}