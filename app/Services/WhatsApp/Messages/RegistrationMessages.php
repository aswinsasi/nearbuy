<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

/**
 * Registration Messages - Short, friendly, bilingual.
 *
 * DESIGN PRINCIPLES:
 * - Every message MAX 3-4 lines
 * - Malayalam + English mix (how Keralites actually text)
 * - Feels like chatting with a friend, not filling a form
 * - Clear next action
 *
 * @srs-ref FR-REG-01 to FR-REG-07
 * @srs-ref NFR-U-05 - English and Malayalam support
 */
class RegistrationMessages
{
    /*
    |--------------------------------------------------------------------------
    | Welcome & Name (Step 1)
    |--------------------------------------------------------------------------
    */

    /**
     * Welcome message + ask name.
     * First impression - warm and exciting!
     */
    public static function welcome(): string
    {
        return "Hii! 👋 *NearBuy*-ലേക്ക് welcome!\n\n" .
            "Nearby shops, fresh fish alerts, jobs — ellaam WhatsApp-il! 🛒\n\n" .
            "Ninte peru entha?";
    }

    /**
     * Welcome for users with referral.
     */
    public static function welcomeReferred(string $referrerName): string
    {
        $firstName = self::firstName($referrerName);

        return "Hii! 👋 *{$firstName}* paranjittundallo!\n\n" .
            "NearBuy-ലേക്ക് welcome 🎉\n\n" .
            "Ninte peru entha?";
    }

    /**
     * Invalid name - ask again.
     */
    public static function askNameRetry(): string
    {
        return "🙏 Peru onnu koodi type cheyyamo?\n\n" .
            "_Eg: Rajan, Meera, സുരേഷ്_";
    }

    /**
     * Name too short or invalid.
     */
    public static function invalidName(): string
    {
        return "⚠️ Peru valid alla.\n\n" .
            "2+ letters type cheyyuka.";
    }

    /*
    |--------------------------------------------------------------------------
    | Location (Step 2)
    |--------------------------------------------------------------------------
    */

    /**
     * Acknowledge name + ask location.
     */
    public static function askLocation(string $name): string
    {
        $firstName = self::firstName($name);

        return "Thanks *{$firstName}*! 👍\n\n" .
            "📍 Location share cheyyamo?\n" .
            "_Nearby shops-um offers-um kaanaan_";
    }

    /**
     * Location retry - user sent something else.
     */
    public static function askLocationRetry(): string
    {
        return "📍 Location share cheyyuka.\n\n" .
            "👇 Button click cheythu send cheyyuka.";
    }

    /**
     * How to share location (if user seems confused).
     */
    public static function locationHelp(): string
    {
        return "📍 *Location share cheyyaan:*\n\n" .
            "1. 📎 Attachment button tap cheyyuka\n" .
            "2. 📍 Location select cheyyuka\n" .
            "3. ✅ Send your current location";
    }

    /*
    |--------------------------------------------------------------------------
    | User Type (Step 3)
    |--------------------------------------------------------------------------
    */

    /**
     * Ask user type - after location.
     */
    public static function askType(string $name): string
    {
        $firstName = self::firstName($name);

        return "Perfect, {$firstName}! 📍✅\n\n" .
            "Ningal aara?";
    }

    /**
     * User type buttons.
     */
    public static function typeButtons(): array
    {
        return [
            ['id' => 'customer', 'title' => '🛒 Customer'],
            ['id' => 'shop', 'title' => '🏪 Shop Owner'],
        ];
    }

    /**
     * Type retry - user sent something invalid.
     */
    public static function askTypeRetry(): string
    {
        return "👆 Button tap cheyyuka:\n\n" .
            "🛒 Customer or 🏪 Shop Owner";
    }

    /*
    |--------------------------------------------------------------------------
    | Completion
    |--------------------------------------------------------------------------
    */

    /**
     * Customer registration complete.
     */
    public static function completeCustomer(string $name): string
    {
        $firstName = self::firstName($name);

        return "✅ *Ready, {$firstName}!* 🎉\n\n" .
            "NearBuy-nte ellaa features-um use cheyyaam.\n\n" .
            "Entha cheyyendathu?";
    }

    /**
     * Quick action buttons after customer registration.
     */
    public static function customerMenuButtons(): array
    {
        return [
            ['id' => 'browse_offers', 'title' => '🛍️ Offers kaanuka'],
            ['id' => 'fish_alerts', 'title' => '🐟 Fresh Fish'],
            ['id' => 'main_menu', 'title' => '📋 Full Menu'],
        ];
    }

    /**
     * Shop owner - continue to shop registration.
     */
    public static function shopOwnerContinue(string $name): string
    {
        $firstName = self::firstName($name);

        return "🏪 *Shop owner aano, {$firstName}?* Nice!\n\n" .
            "Shop details koodi tharamo?\n" .
            "_2 minute mathram edukkum_";
    }

    /**
     * Shop continue/skip buttons.
     */
    public static function shopContinueButtons(): array
    {
        return [
            ['id' => 'continue_shop', 'title' => '✅ Continue'],
            ['id' => 'later', 'title' => '⏭️ Pinne'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Already registered.
     */
    public static function alreadyRegistered(string $name = ''): string
    {
        $firstName = $name ? self::firstName($name) : 'Friend';

        return "👋 *{$firstName}*, already registered aanu!\n\n" .
            "Type *menu* to continue.";
    }

    /**
     * Expected location but got something else.
     */
    public static function expectedLocation(): string
    {
        return "📍 Location share cheyyuka please.\n\n" .
            "📎 button → Location → Send";
    }

    /**
     * Expected button tap.
     */
    public static function expectedButton(): string
    {
        return "👆 Button tap cheyyuka please.";
    }

    /**
     * Generic error.
     */
    public static function genericError(): string
    {
        return "🙏 Onnu koodi try cheyyamo?";
    }

    /**
     * Registration cancelled.
     */
    public static function cancelled(): string
    {
        return "❌ Cancelled.\n\n" .
            "Type *hi* to start again.";
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Registration Messages (used by ShopRegistrationFlowHandler)
    |--------------------------------------------------------------------------
    */

    /**
     * Ask shop name.
     */
    public static function askShopName(): string
    {
        return "🏪 Shop-inte peru entha?";
    }

    /**
     * Acknowledge shop name + ask category.
     */
    public static function askShopCategory(string $shopName): string
    {
        return "*{$shopName}* — nice! 👍\n\n" .
            "Category select cheyyuka:";
    }

    /**
     * Shop category list sections.
     */
    public static function categoryList(): array
    {
        return [
            [
                'title' => 'Category',
                'rows' => [
                    ['id' => 'grocery', 'title' => '🛒 Grocery', 'description' => 'Daily needs, vegetables'],
                    ['id' => 'electronics', 'title' => '📱 Electronics', 'description' => 'TV, laptop, gadgets'],
                    ['id' => 'clothes', 'title' => '👕 Clothes', 'description' => 'Fashion, textiles'],
                    ['id' => 'medical', 'title' => '💊 Medical', 'description' => 'Pharmacy, health'],
                    ['id' => 'mobile', 'title' => '📲 Mobile', 'description' => 'Phones, accessories'],
                    ['id' => 'furniture', 'title' => '🪑 Furniture', 'description' => 'Home & office'],
                    ['id' => 'hardware', 'title' => '🔧 Hardware', 'description' => 'Tools, construction'],
                    ['id' => 'restaurant', 'title' => '🍽️ Restaurant', 'description' => 'Food, dining'],
                    ['id' => 'appliances', 'title' => '🔌 Appliances', 'description' => 'AC, fridge, etc.'],
                    ['id' => 'other', 'title' => '📦 Other', 'description' => 'Other categories'],
                ],
            ],
        ];
    }

    /**
     * Ask if shop location is same as personal.
     */
    public static function askShopLocationSame(): string
    {
        return "📍 Shop-um ee location-il aano?";
    }

    /**
     * Shop location same/different buttons.
     */
    public static function shopLocationButtons(): array
    {
        return [
            ['id' => 'same_location', 'title' => '📍 Athe, same'],
            ['id' => 'different', 'title' => '🗺️ Vere location'],
        ];
    }

    /**
     * Ask for different shop location.
     */
    public static function askShopLocation(): string
    {
        return "📍 Shop-inte location share cheyyuka.";
    }

    /**
     * Ask notification preference.
     */
    public static function askNotificationPref(): string
    {
        return "🔔 Customer requests ariyikkanam?";
    }

    /**
     * Notification options list.
     */
    public static function notificationList(): array
    {
        return [
            [
                'title' => 'Notification',
                'rows' => [
                    ['id' => 'immediate', 'title' => '🔔 Udan thanne', 'description' => 'Every request immediately'],
                    ['id' => '2hours', 'title' => '⏰ 2 Hour-il', 'description' => 'Batched (Recommended)'],
                    ['id' => 'twice_daily', 'title' => '📅 Day-il 2 times', 'description' => '9AM & 5PM'],
                    ['id' => 'daily', 'title' => '🌅 Day-il 1 time', 'description' => 'Morning 9AM only'],
                ],
            ],
        ];
    }

    /**
     * Shop registration complete.
     */
    public static function completeShop(string $name, string $shopName): string
    {
        $firstName = self::firstName($name);

        return "🎉 *Congratulations, {$firstName}!*\n\n" .
            "*{$shopName}* is now LIVE! 🏪\n\n" .
            "Nearby customers-nu kaanaam.";
    }

    /**
     * Buttons after shop registration.
     */
    public static function shopMenuButtons(): array
    {
        return [
            ['id' => 'upload_offer', 'title' => '📤 Upload Offer'],
            ['id' => 'view_requests', 'title' => '📬 Requests'],
            ['id' => 'main_menu', 'title' => '📋 Menu'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Extract first name from full name.
     */
    public static function firstName(string $name): string
    {
        $parts = explode(' ', trim($name));
        return $parts[0] ?: 'Friend';
    }

    /**
     * Get category label with emoji.
     */
    public static function getCategoryLabel(string $id): string
    {
        $labels = [
            'grocery' => '🛒 Grocery',
            'electronics' => '📱 Electronics',
            'clothes' => '👕 Clothes',
            'medical' => '💊 Medical',
            'mobile' => '📲 Mobile',
            'furniture' => '🪑 Furniture',
            'hardware' => '🔧 Hardware',
            'restaurant' => '🍽️ Restaurant',
            'appliances' => '🔌 Appliances',
            'other' => '📦 Other',
        ];

        return $labels[$id] ?? ucfirst($id);
    }

    /**
     * Get notification label.
     */
    public static function getNotificationLabel(string $id): string
    {
        $labels = [
            'immediate' => '🔔 Immediately',
            '2hours' => '⏰ Every 2 Hours',
            'twice_daily' => '📅 Twice Daily',
            'daily' => '🌅 Once Daily',
        ];

        return $labels[$id] ?? ucfirst($id);
    }
}