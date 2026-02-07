<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use App\Enums\NotificationFrequency;
use App\Enums\ShopCategory;

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
 * @srs-ref FR-SHOP-01 to FR-SHOP-05
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
    | Customer Completion
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

    /*
    |--------------------------------------------------------------------------
    | Shop Registration - Continue/Skip Choice
    |--------------------------------------------------------------------------
    */

    /**
     * Shop owner - ask if they want to continue with shop registration.
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

    /**
     * Shop skipped - later message.
     */
    public static function shopSkipped(string $name): string
    {
        $firstName = self::firstName($name);

        return "✅ Ok *{$firstName}*! Pinne shop details add cheyyaam.\n\n" .
            "Ippol entha cheyyendathu?";
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Registration - Step 1: Shop Name (FR-SHOP-01)
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
     * Invalid shop name.
     */
    public static function invalidShopName(): string
    {
        return "⚠️ Shop peru valid alla.\n\n" .
            "2+ letters type cheyyuka.\n" .
            "_Eg: Krishna Stores, Fresh Mart_";
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Registration - Step 2: Category (FR-SHOP-02)
    |--------------------------------------------------------------------------
    */

    /**
     * Acknowledge shop name + ask category.
     */
    public static function askShopCategory(string $shopName): string
    {
        return "*{$shopName}* — nice! 👍\n\n" .
            "Shop category select cheyyuka:";
    }

    /**
     * Category selection retry.
     */
    public static function askCategoryRetry(): string
    {
        return "👆 List-il ninnu category select cheyyuka.";
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Registration - Step 3: Shop Location (FR-SHOP-03)
    |--------------------------------------------------------------------------
    */

    /**
     * Acknowledge category + ask shop location.
     * CRITICAL: Make clear this is SHOP location, not personal.
     */
    public static function askShopLocation(string $categoryLabel): string
    {
        return "{$categoryLabel} ✅\n\n" .
            "📍 *Shop-nte location share cheyyuka*\n\n" .
            "_⚠️ Ith ninte personal location-alla,\nSHOP-nte location aanu_";
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
            ['id' => 'same_location', 'title' => '📍 Same location'],
            ['id' => 'different_location', 'title' => '🗺️ Vere location'],
        ];
    }

    /**
     * Ask for different shop location.
     */
    public static function askShopLocationDifferent(): string
    {
        return "📍 Shop-inte location share cheyyuka.\n\n" .
            "_Customers-nu navigate cheyyaan_";
    }

    /**
     * Shop location retry.
     */
    public static function askShopLocationRetry(): string
    {
        return "📍 Location share cheyyuka please.\n\n" .
            "📎 button → Location → Send";
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Registration - Step 4: Notification Preference (FR-SHOP-04)
    |--------------------------------------------------------------------------
    */

    /**
     * Acknowledge location + ask notification preference.
     */
    public static function askNotificationPref(): string
    {
        return "📍 Location saved! ✅\n\n" .
            "🔔 Product request alerts engane vendathu?";
    }

    /**
     * Notification preference retry.
     */
    public static function askNotificationPrefRetry(): string
    {
        return "👆 List-il ninnu option select cheyyuka.";
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Registration - Complete (FR-SHOP-05)
    |--------------------------------------------------------------------------
    */

    /**
     * Shop registration complete.
     */
    public static function completeShop(string $name, string $shopName): string
    {
        $firstName = self::firstName($name);

        return "🎉 *Congratulations, {$firstName}!*\n\n" .
            "✅ *{$shopName}* registered!\n" .
            "Nearby customers-nu kaanaam 🏪\n\n" .
            "Ippol offers upload cheyyaam 🛍️";
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

    /**
     * Registration failed.
     */
    public static function registrationFailed(): string
    {
        return "❌ Error occurred. Please try again.\n\n" .
            "Type *hi* to restart.";
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
        $category = ShopCategory::tryFrom($id);
        return $category?->displayWithIcon() ?? ucfirst($id);
    }

    /**
     * Get notification label.
     */
    public static function getNotificationLabel(string $id): string
    {
        $freq = NotificationFrequency::tryFrom($id);
        return $freq ? "{$freq->icon()} {$freq->label()}" : ucfirst($id);
    }
}