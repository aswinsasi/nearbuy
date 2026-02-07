<?php

namespace App\Services\WhatsApp\Messages;

/**
 * Centralized message templates for NearBuy.
 *
 * UX Principles:
 * - Bilingual: English and Malayalam (NFR-U-05)
 * - Consistent navigation (NFR-U-04)
 * - Short, scannable messages
 * - Emojis for visual hierarchy
 * - Kerala-friendly tone
 */
class MessageTemplates
{
    /*
    |--------------------------------------------------------------------------
    | Language Constants
    |--------------------------------------------------------------------------
    */

    public const LANG_EN = 'en';
    public const LANG_ML = 'ml';

    /*
    |--------------------------------------------------------------------------
    | Global Navigation (NFR-U-04)
    |--------------------------------------------------------------------------
    */

    public const MENU_HINT_EN = "💡 Type *menu* anytime for Main Menu";
    public const MENU_HINT_ML = "💡 *menu* type cheythal Main Menu";

    /**
     * Global footer for all messages.
     */
    public const GLOBAL_FOOTER = "NearBuy • Type 'menu' for options";

    /**
     * Generic error message.
     */
    public const ERROR_GENERIC = "❌ Something went wrong. Please try again or type *menu* to start over.";

    public const MENU_BUTTON = ['id' => 'main_menu', 'title' => '🏠 Menu'];
    public const CANCEL_BUTTON = ['id' => 'cancel', 'title' => '❌ Cancel'];
    public const BACK_BUTTON = ['id' => 'back', 'title' => '⬅️ Back'];
    public const SKIP_BUTTON = ['id' => 'skip', 'title' => '⏭️ Skip'];
    public const RETRY_BUTTON = ['id' => 'retry', 'title' => '🔄 Try Again'];
    public const DONE_BUTTON = ['id' => 'done', 'title' => '✅ Done'];

    /*
    |--------------------------------------------------------------------------
    | Welcome & Registration
    |--------------------------------------------------------------------------
    */

    public static function welcome(string $lang = self::LANG_EN): string
    {
        return ($lang === self::LANG_ML)
            ? "🙏 *NearBuy-ലേക്ക് സ്വാഗതം!*\n\n" .
              "WhatsApp-il local shopping 🛒\n\n" .
              "• 🛍️ Nearby offers kaanuka\n" .
              "• 🔍 Products search cheyyuka\n" .
              "• 📝 Agreements create cheyyuka"
            : "🙏 *Welcome to NearBuy!*\n\n" .
              "Your local marketplace on WhatsApp 🛒\n\n" .
              "• 🛍️ Browse nearby offers\n" .
              "• 🔍 Search for products\n" .
              "• 📝 Create digital agreements";
    }

    public static function welcomeBack(string $name, string $lang = self::LANG_EN): string
    {
        return ($lang === self::LANG_ML)
            ? "🙏 *Welcome back, {$name}!*\n\nEnthokke cheyyaam?"
            : "🙏 *Welcome back, {$name}!*\n\nHow can I help?";
    }

    public static function askUserType(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "👤 *Ningal aaraan?*"
            : "👤 *Are you a customer or shop owner?*";

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'customer', 'title' => '🛒 Customer'],
                ['id' => 'shop', 'title' => '🏪 Shop Owner'],
            ],
        ];
    }

    public static function askName(string $lang = self::LANG_EN): string
    {
        return ($lang === self::LANG_ML)
            ? "👤 *Ningalude peru?*\n\nType cheyyuka:"
            : "👤 *What's your name?*\n\nPlease type:";
    }

    public static function askLocation(string $lang = self::LANG_EN): string
    {
        return ($lang === self::LANG_ML)
            ? "📍 *Location share cheyyuka*\n\nSameepathe shops kaanikkan."
            : "📍 *Share your location*\n\nTo show nearby shops and offers.";
    }

    public static function locationSaved(string $lang = self::LANG_EN): string
    {
        return ($lang === self::LANG_ML)
            ? "✅ *Location saved!*"
            : "✅ *Location saved!*";
    }

    public static function askShopName(string $lang = self::LANG_EN): string
    {
        return ($lang === self::LANG_ML)
            ? "🏪 *Shop-inte peru?*\n\nType cheyyuka:"
            : "🏪 *What's your shop name?*\n\nPlease type:";
    }

    public static function registrationComplete(string $name, bool $isShop, ?string $shopName = null, string $lang = self::LANG_EN): string
    {
        if ($isShop) {
            return ($lang === self::LANG_ML)
                ? "🎉 *Registration Complete!*\n\n" .
                  "Welcome, *{$name}*!\n" .
                  "🏪 *{$shopName}* live aayi!\n\n" .
                  "📤 First offer upload cheyyuka!"
                : "🎉 *Registration Complete!*\n\n" .
                  "Welcome, *{$name}*!\n" .
                  "🏪 *{$shopName}* is now live!\n\n" .
                  "📤 Upload your first offer!";
        }

        return ($lang === self::LANG_ML)
            ? "🎉 *Registration Complete!*\n\n" .
              "Welcome, *{$name}*!\n\n" .
              "🛍️ Nearby offers explore cheyyaam!"
            : "🎉 *Registration Complete!*\n\n" .
              "Welcome, *{$name}*!\n\n" .
              "🛍️ Let's explore nearby offers!";
    }

    /*
    |--------------------------------------------------------------------------
    | Main Menu
    |--------------------------------------------------------------------------
    */

    public static function mainMenuCustomer(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "🛒 *NearBuy Menu*\n\nEnthokke cheyyaam?"
            : "🛒 *NearBuy Menu*\n\nWhat would you like to do?";

        return [
            'message' => $message,
            'buttonText' => ($lang === self::LANG_ML) ? '📋 Options' : '📋 View Options',
            'sections' => [
                [
                    'title' => ($lang === self::LANG_ML) ? 'Main' : 'Main Features',
                    'rows' => [
                        ['id' => 'browse_offers', 'title' => '🛍️ Browse Offers', 'description' => ($lang === self::LANG_ML) ? 'Nearby shops kaanuka' : 'See nearby shop offers'],
                        ['id' => 'search_product', 'title' => '🔍 Search Product', 'description' => ($lang === self::LANG_ML) ? 'Product find cheyyuka' : 'Find products locally'],
                        ['id' => 'my_requests', 'title' => '📋 My Requests', 'description' => ($lang === self::LANG_ML) ? 'Requests & responses' : 'View your requests'],
                    ],
                ],
                [
                    'title' => ($lang === self::LANG_ML) ? 'More' : 'More Features',
                    'rows' => [
                        ['id' => 'agreements', 'title' => '📝 Agreements', 'description' => ($lang === self::LANG_ML) ? 'Digital agreements' : 'Create/view agreements'],
                        ['id' => 'fish_alerts', 'title' => '🐟 Fresh Fish', 'description' => ($lang === self::LANG_ML) ? 'Pacha meen alerts' : 'Fresh catch alerts'],
                        ['id' => 'settings', 'title' => '⚙️ Settings', 'description' => ($lang === self::LANG_ML) ? 'Location, language' : 'Update preferences'],
                    ],
                ],
            ],
        ];
    }

    public static function mainMenuShop(string $lang = self::LANG_EN): array
    {
        $message = ($lang === self::LANG_ML)
            ? "🏪 *Shop Menu*\n\nEnthokke cheyyaam?"
            : "🏪 *Shop Menu*\n\nWhat would you like to do?";

        return [
            'message' => $message,
            'buttonText' => ($lang === self::LANG_ML) ? '📋 Options' : '📋 View Options',
            'sections' => [
                [
                    'title' => ($lang === self::LANG_ML) ? 'Shop' : 'Shop Features',
                    'rows' => [
                        ['id' => 'upload_offer', 'title' => '📤 Upload Offer', 'description' => ($lang === self::LANG_ML) ? 'Putha offer publish' : 'Publish new offer'],
                        ['id' => 'my_offers', 'title' => '📢 My Offers', 'description' => ($lang === self::LANG_ML) ? 'Active offers kaanuka' : 'View active offers'],
                        ['id' => 'product_requests', 'title' => '📦 Requests', 'description' => ($lang === self::LANG_ML) ? 'Customer requests' : 'Customer product requests'],
                    ],
                ],
                [
                    'title' => ($lang === self::LANG_ML) ? 'More' : 'More Features',
                    'rows' => [
                        ['id' => 'agreements', 'title' => '📝 Agreements', 'description' => ($lang === self::LANG_ML) ? 'Digital agreements' : 'Create/view agreements'],
                        ['id' => 'shop_stats', 'title' => '📊 Statistics', 'description' => ($lang === self::LANG_ML) ? 'Views, responses' : 'Views & performance'],
                        ['id' => 'settings', 'title' => '⚙️ Settings', 'description' => ($lang === self::LANG_ML) ? 'Notifications, profile' : 'Update preferences'],
                    ],
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Categories
    |--------------------------------------------------------------------------
    */

    public static function getShopCategories(string $lang = self::LANG_EN): array
    {
        return [
            ['id' => 'grocery', 'title' => '🛒 Grocery', 'description' => ($lang === self::LANG_ML) ? 'Pazhangal, pachakkari' : 'Vegetables, fruits, daily needs'],
            ['id' => 'electronics', 'title' => '📱 Electronics', 'description' => ($lang === self::LANG_ML) ? 'TV, laptop, gadgets' : 'TV, laptop, gadgets'],
            ['id' => 'clothes', 'title' => '👕 Clothes', 'description' => ($lang === self::LANG_ML) ? 'Fashion, textiles' : 'Fashion, textiles'],
            ['id' => 'medical', 'title' => '💊 Medical', 'description' => ($lang === self::LANG_ML) ? 'Pharmacy, medicines' : 'Pharmacy, health products'],
            ['id' => 'furniture', 'title' => '🪑 Furniture', 'description' => ($lang === self::LANG_ML) ? 'Home & office' : 'Home & office furniture'],
            ['id' => 'mobile', 'title' => '📲 Mobile', 'description' => ($lang === self::LANG_ML) ? 'Phones, accessories' : 'Phones & accessories'],
            ['id' => 'appliances', 'title' => '🔌 Appliances', 'description' => ($lang === self::LANG_ML) ? 'AC, fridge, washing' : 'AC, fridge, washing machine'],
            ['id' => 'hardware', 'title' => '🔧 Hardware', 'description' => ($lang === self::LANG_ML) ? 'Tools, materials' : 'Tools, construction materials'],
        ];
    }

    public static function getCategoryEmoji(string $categoryId): string
    {
        return match ($categoryId) {
            'grocery' => '🛒',
            'electronics' => '📱',
            'clothes' => '👕',
            'medical' => '💊',
            'furniture' => '🪑',
            'mobile' => '📲',
            'appliances' => '🔌',
            'hardware' => '🔧',
            default => '🏪',
        };
    }

    public static function getCategoryName(string $categoryId, string $lang = self::LANG_EN): string
    {
        $names = [
            'grocery' => ['en' => 'Grocery', 'ml' => 'Grocery'],
            'electronics' => ['en' => 'Electronics', 'ml' => 'Electronics'],
            'clothes' => ['en' => 'Clothes', 'ml' => 'Clothes'],
            'medical' => ['en' => 'Medical', 'ml' => 'Medical'],
            'furniture' => ['en' => 'Furniture', 'ml' => 'Furniture'],
            'mobile' => ['en' => 'Mobile', 'ml' => 'Mobile'],
            'appliances' => ['en' => 'Appliances', 'ml' => 'Appliances'],
            'hardware' => ['en' => 'Hardware', 'ml' => 'Hardware'],
        ];

        return $names[$categoryId][$lang] ?? ucfirst($categoryId);
    }

    /*
    |--------------------------------------------------------------------------
    | Notification Frequencies
    |--------------------------------------------------------------------------
    */

    public static function getNotificationFrequencies(string $lang = self::LANG_EN): array
    {
        return [
            ['id' => 'immediate', 'title' => '🔔 Immediately', 'description' => ($lang === self::LANG_ML) ? 'Udane ariyikkuka' : 'Get notified instantly'],
            ['id' => '2hours', 'title' => '⏰ Every 2 Hours', 'description' => ($lang === self::LANG_ML) ? 'Recommended' : 'Batched (Recommended)'],
            ['id' => 'twice_daily', 'title' => '📅 Twice Daily', 'description' => ($lang === self::LANG_ML) ? '9 AM & 5 PM' : '9 AM & 5 PM'],
            ['id' => 'daily', 'title' => '🌅 Once Daily', 'description' => ($lang === self::LANG_ML) ? 'Raavile 9 AM' : 'Morning 9 AM only'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Offer Validity Options
    |--------------------------------------------------------------------------
    */

    public static function getValidityOptions(string $lang = self::LANG_EN): array
    {
        return [
            ['id' => 'today', 'title' => '📅 Today Only', 'description' => ($lang === self::LANG_ML) ? 'Innu mathram' : 'Expires tonight'],
            ['id' => '3days', 'title' => '📅 3 Days', 'description' => ($lang === self::LANG_ML) ? '3 divasam' : 'Short promotion'],
            ['id' => 'week', 'title' => '📅 This Week', 'description' => ($lang === self::LANG_ML) ? 'Ee aazhcha' : 'Week-long offer'],
            ['id' => 'month', 'title' => '📅 This Month', 'description' => ($lang === self::LANG_ML) ? 'Ee maasam' : 'Monthly deal'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Agreement Purpose Options
    |--------------------------------------------------------------------------
    */

    public static function getAgreementPurposes(string $lang = self::LANG_EN): array
    {
        return [
            ['id' => 'loan', 'title' => '🤝 Loan', 'description' => ($lang === self::LANG_ML) ? 'Kadham' : 'Lending to friend/family'],
            ['id' => 'advance', 'title' => '🔧 Work Advance', 'description' => ($lang === self::LANG_ML) ? 'Pani advance' : 'Advance for work/service'],
            ['id' => 'deposit', 'title' => '🏠 Deposit', 'description' => ($lang === self::LANG_ML) ? 'Deposit/booking' : 'Rent, booking, purchase'],
            ['id' => 'business', 'title' => '💼 Business', 'description' => ($lang === self::LANG_ML) ? 'Business payment' : 'Vendor/supplier payment'],
            ['id' => 'other', 'title' => '📝 Other', 'description' => ($lang === self::LANG_ML) ? 'Mattu' : 'Other purpose'],
        ];
    }

    public static function getAgreementDueDates(string $lang = self::LANG_EN): array
    {
        return [
            ['id' => 'due_1week', 'title' => '📅 1 Week', 'description' => date('d M Y', strtotime('+1 week'))],
            ['id' => 'due_2weeks', 'title' => '📅 2 Weeks', 'description' => date('d M Y', strtotime('+2 weeks'))],
            ['id' => 'due_1month', 'title' => '📅 1 Month', 'description' => date('d M Y', strtotime('+1 month'))],
            ['id' => 'due_3months', 'title' => '📅 3 Months', 'description' => date('d M Y', strtotime('+3 months'))],
            ['id' => 'due_none', 'title' => '⏳ No Fixed Date', 'description' => ($lang === self::LANG_ML) ? 'Open-ended' : 'Open-ended'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Radius Options
    |--------------------------------------------------------------------------
    */

    public static function getRadiusOptions(string $lang = self::LANG_EN): array
    {
        return [
            ['id' => 'radius_2', 'title' => '📍 2 km', 'description' => ($lang === self::LANG_ML) ? 'Nadannu pokaavunna dooram' : 'Walking distance'],
            ['id' => 'radius_5', 'title' => '📍 5 km', 'description' => ($lang === self::LANG_ML) ? 'Recommended' : 'Nearby area (Recommended)'],
            ['id' => 'radius_10', 'title' => '📍 10 km', 'description' => ($lang === self::LANG_ML) ? 'Koodi dooram' : 'Extended area'],
            ['id' => 'radius_20', 'title' => '📍 20 km', 'description' => ($lang === self::LANG_ML) ? 'Valiya area' : 'Wide search'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Formatting Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Replace placeholders in template.
     */
    public static function format(string $template, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $template = str_replace("{{$key}}", (string) $value, $template);
        }
        return $template;
    }

    /**
     * Format amount in Indian format with rupee symbol.
     */
    public static function formatAmount(float $amount): string
    {
        return '₹' . number_format($amount, 0, '.', ',');
    }

    /**
     * Format distance.
     */
    public static function formatDistance(float $km): string
    {
        if ($km < 1) {
            return round($km * 1000) . 'm';
        }
        return round($km, 1) . 'km';
    }

    /**
     * Get purpose display with emoji.
     */
    public static function getPurposeDisplay(string $purposeId, string $lang = self::LANG_EN): string
    {
        return match ($purposeId) {
            'loan' => '🤝 Loan',
            'advance' => '🔧 Work Advance',
            'deposit' => '🏠 Deposit',
            'business' => '💼 Business',
            'other' => '📝 Other',
            default => '📋 ' . ucfirst($purposeId),
        };
    }

    /**
     * Convert amount to words (Indian format).
     */
    public static function amountToWords(float $amount): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $amount = (int) $amount;

        if ($amount == 0) {
            return 'Zero Rupees Only';
        }

        $words = '';

        // Crores
        if ($amount >= 10000000) {
            $crores = (int) ($amount / 10000000);
            $words .= self::convertBelowHundred($crores, $ones, $tens) . ' Crore ';
            $amount %= 10000000;
        }

        // Lakhs
        if ($amount >= 100000) {
            $lakhs = (int) ($amount / 100000);
            $words .= self::convertBelowHundred($lakhs, $ones, $tens) . ' Lakh ';
            $amount %= 100000;
        }

        // Thousands
        if ($amount >= 1000) {
            $thousands = (int) ($amount / 1000);
            $words .= self::convertBelowHundred($thousands, $ones, $tens) . ' Thousand ';
            $amount %= 1000;
        }

        // Hundreds
        if ($amount >= 100) {
            $hundreds = (int) ($amount / 100);
            $words .= $ones[$hundreds] . ' Hundred ';
            $amount %= 100;
        }

        // Remaining
        if ($amount > 0) {
            $words .= self::convertBelowHundred($amount, $ones, $tens);
        }

        return 'Rupees ' . trim($words) . ' Only';
    }

    private static function convertBelowHundred(int $num, array $ones, array $tens): string
    {
        if ($num < 20) {
            return $ones[$num];
        }
        return $tens[(int) ($num / 10)] . ($num % 10 ? ' ' . $ones[$num % 10] : '');
    }

    /**
     * Get expected input help text.
     */
    public static function getExpectedInputHelp(string $expectedType, string $lang = self::LANG_EN): string
    {
        $helps = [
            'text' => ['en' => 'Please type your response.', 'ml' => 'Type cheyyuka.'],
            'button' => ['en' => 'Please tap a button above ☝️', 'ml' => 'Mele button tap cheyyuka ☝️'],
            'list' => ['en' => 'Please select from the list.', 'ml' => 'List-il ninnu select cheyyuka.'],
            'location' => ['en' => 'Please share your location 📍', 'ml' => 'Location share cheyyuka 📍'],
            'image' => ['en' => 'Please send an image 📷', 'ml' => 'Photo ayakkuka 📷'],
            'phone' => ['en' => 'Enter 10 digit number\n_Eg: 9876543210_', 'ml' => '10 digit number\n_Eg: 9876543210_'],
            'amount' => ['en' => 'Enter numbers only\n_Eg: 5000_', 'ml' => 'Numbers mathram\n_Eg: 5000_'],
        ];

        return $helps[$expectedType][$lang] ?? $helps[$expectedType]['en'] ?? 'Please provide a valid response.';
    }
}