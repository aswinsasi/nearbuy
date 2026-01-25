<?php

namespace App\Services\WhatsApp\Messages;

use App\Models\User;
use App\Enums\UserType;
use App\Services\WhatsApp\Messages\MessageTemplates;

/**
 * ENHANCED Template builder for main menu messages.
 *
 * Key improvements:
 * 1. Better organized menu sections
 * 2. Emoji-rich options
 * 3. Contextual descriptions
 * 4. Quick action buttons for common tasks
 * 5. Fish seller menu support
 */
class MainMenuTemplate
{
    /**
     * Customer menu options - organized by frequency of use.
     */
    public const CUSTOMER_MENU = [
        [
            'id' => 'browse_offers',
            'title' => '🛍️ Browse Offers',
            'description' => 'See deals from nearby shops',
        ],
        [
            'id' => 'fish_browse',
            'title' => '🐟 Fresh Fish',
            'description' => 'Browse nearby fresh fish',
        ],
        [
            'id' => 'search_product',
            'title' => '🔍 Search Product',
            'description' => 'Find what you need locally',
        ],
        [
            'id' => 'my_requests',
            'title' => '📬 My Requests',
            'description' => 'Check responses from shops',
        ],
        [
            'id' => 'fish_subscribe',
            'title' => '🔔 Fish Alerts',
            'description' => 'Get notified when fish arrives',
        ],
        [
            'id' => 'create_agreement',
            'title' => '📝 New Agreement',
            'description' => 'Record money transactions',
        ],
        [
            'id' => 'my_agreements',
            'title' => '📋 My Agreements',
            'description' => 'View & manage agreements',
        ],
        [
            'id' => 'pending_agreements',
            'title' => '⏳ Pending Approvals',
            'description' => 'Agreements awaiting confirmation',
        ],
        [
            'id' => 'settings',
            'title' => '⚙️ Settings',
            'description' => 'Update your profile',
        ],
    ];

    /**
     * Shop owner menu options - organized by priority.
     */
    public const SHOP_MENU = [
        [
            'id' => 'upload_offer',
            'title' => '📤 Upload Offer',
            'description' => 'Share a new deal',
        ],
        [
            'id' => 'product_requests',
            'title' => '📬 Customer Requests',
            'description' => 'See what customers need',
        ],
        [
            'id' => 'my_offers',
            'title' => '🏷️ My Offers',
            'description' => 'Manage your active offers',
        ],
        [
            'id' => 'browse_offers',
            'title' => '🛍️ Browse Offers',
            'description' => 'See competitor offers',
        ],
        [
            'id' => 'fish_browse',
            'title' => '🐟 Fresh Fish',
            'description' => 'Browse nearby fresh fish',
        ],
        [
            'id' => 'create_agreement',
            'title' => '📝 New Agreement',
            'description' => 'Record transactions',
        ],
        [
            'id' => 'my_agreements',
            'title' => '📋 My Agreements',
            'description' => 'View agreements',
        ],
        [
            'id' => 'pending_agreements',
            'title' => '⏳ Pending Approvals',
            'description' => 'Confirm agreements',
        ],
        [
            'id' => 'shop_profile',
            'title' => '🏪 Shop Profile',
            'description' => 'Update shop details',
        ],
        [
            'id' => 'settings',
            'title' => '⚙️ Settings',
            'description' => 'Notification preferences',
        ],
    ];

    /**
     * Fish seller menu options.
     */
    public const FISH_SELLER_MENU = [
        [
            'id' => 'fish_post_catch',
            'title' => '🎣 Post Catch',
            'description' => 'Add fresh fish posting',
        ],
        [
            'id' => 'fish_update_stock',
            'title' => '📦 Update Stock',
            'description' => 'Change availability status',
        ],
        [
            'id' => 'fish_my_catches',
            'title' => '📋 My Catches',
            'description' => 'View active fish posts',
        ],
        [
            'id' => 'fish_my_stats',
            'title' => '📊 My Stats',
            'description' => 'View sales & performance',
        ],
        [
            'id' => 'fish_browse',
            'title' => '🐟 Browse Fish',
            'description' => 'See other sellers nearby',
        ],
        [
            'id' => 'create_agreement',
            'title' => '📝 New Agreement',
            'description' => 'Record transactions',
        ],
        [
            'id' => 'my_agreements',
            'title' => '📋 My Agreements',
            'description' => 'View agreements',
        ],
        [
            'id' => 'fish_settings',
            'title' => '⚙️ Seller Settings',
            'description' => 'Update seller profile',
        ],
    ];

    /**
     * Unregistered user menu (limited options).
     */
    public const UNREGISTERED_MENU = [
        [
            'id' => 'register',
            'title' => '📝 Register',
            'description' => 'Create your free account',
        ],
        [
            'id' => 'browse_offers',
            'title' => '🛍️ Browse Offers',
            'description' => 'See what\'s available nearby',
        ],
        [
            'id' => 'fish_browse',
            'title' => '🐟 Fresh Fish',
            'description' => 'Browse nearby fresh fish',
        ],
        [
            'id' => 'fish_seller_register',
            'title' => '🎣 Become Fish Seller',
            'description' => 'Register to sell fresh fish',
        ],
        [
            'id' => 'about',
            'title' => 'ℹ️ About NearBuy',
            'description' => 'Learn what we offer',
        ],
    ];

    /**
     * Get menu options for a user.
     */
    public static function getMenuForUser(?User $user): array
    {
        if (!$user || !$user->registered_at) {
            return self::UNREGISTERED_MENU;
        }

        if ($user->type === UserType::FISH_SELLER) {
            return self::FISH_SELLER_MENU;
        }

        if ($user->type === UserType::SHOP) {
            return self::SHOP_MENU;
        }

        return self::CUSTOMER_MENU;
    }

    /**
     * Get the menu header.
     */
    public static function getHeader(): string
    {
        return MessageTemplates::MAIN_MENU_HEADER;
    }

    /**
     * Get the menu body text.
     */
    public static function getBody(?User $user): string
    {
        if (!$user || !$user->registered_at) {
            return MessageTemplates::WELCOME;
        }

        $greeting = MessageTemplates::format(
            MessageTemplates::WELCOME_BACK,
            ['name' => $user->name ?? 'there']
        );

        if ($user->type === UserType::FISH_SELLER) {
            $businessName = $user->fishSeller?->business_name ?? 'Fish Seller';
            return $greeting . "\n\n🐟 *{$businessName}*\n\n" . self::getFishSellerMenuText();
        }

        if ($user->type === UserType::SHOP) {
            $shopName = $user->shop?->shop_name ?? 'Your Shop';
            return $greeting . "\n\n🏪 *{$shopName}*\n\n" . MessageTemplates::MAIN_MENU_SHOP;
        }

        return $greeting . "\n\n" . MessageTemplates::MAIN_MENU_CUSTOMER;
    }

    /**
     * Get fish seller menu text.
     */
    public static function getFishSellerMenuText(): string
    {
        return "Post your fresh catch and notify customers instantly!";
    }

    /**
     * Get the menu footer.
     */
    public static function getFooter(): string
    {
        return MessageTemplates::GLOBAL_FOOTER;
    }

    /**
     * Get button text for opening list.
     */
    public static function getButtonText(): string
    {
        return MessageTemplates::MAIN_MENU_BUTTON_TEXT;
    }

    /**
     * Build list sections for WhatsApp list message.
     * 
     * ENHANCED: Better organized sections with fish support.
     */
    public static function buildListSections(?User $user): array
    {
        $menu = self::getMenuForUser($user);

        // For unregistered users, single section
        if (!$user || !$user->registered_at) {
            return [
                [
                    'title' => 'Get Started',
                    'rows' => array_map(fn($item) => [
                        'id' => $item['id'],
                        'title' => self::truncate($item['title'], 24),
                        'description' => self::truncate($item['description'] ?? '', 72),
                    ], $menu),
                ],
            ];
        }

        // For fish sellers - organize into sections
        if ($user->type === UserType::FISH_SELLER) {
            return [
                [
                    'title' => '🐟 Fish Sales',
                    'rows' => array_map(fn($item) => [
                        'id' => $item['id'],
                        'title' => self::truncate($item['title'], 24),
                        'description' => self::truncate($item['description'] ?? '', 72),
                    ], array_slice($menu, 0, 5)), // post, update, my catches, stats, browse
                ],
                [
                    'title' => '📋 Agreements',
                    'rows' => array_map(fn($item) => [
                        'id' => $item['id'],
                        'title' => self::truncate($item['title'], 24),
                        'description' => self::truncate($item['description'] ?? '', 72),
                    ], array_slice($menu, 5, 2)), // create, my agreements
                ],
                [
                    'title' => '⚙️ Account',
                    'rows' => array_map(fn($item) => [
                        'id' => $item['id'],
                        'title' => self::truncate($item['title'], 24),
                        'description' => self::truncate($item['description'] ?? '', 72),
                    ], array_slice($menu, 7)), // settings
                ],
            ];
        }

        // For customers - organize into sections
        if ($user->type !== UserType::SHOP) {
            return [
                [
                    'title' => '🛒 Shopping',
                    'rows' => array_map(fn($item) => [
                        'id' => $item['id'],
                        'title' => self::truncate($item['title'], 24),
                        'description' => self::truncate($item['description'] ?? '', 72),
                    ], array_slice($menu, 0, 5)), // browse, fish, search, my requests, fish alerts
                ],
                [
                    'title' => '📋 Agreements',
                    'rows' => array_map(fn($item) => [
                        'id' => $item['id'],
                        'title' => self::truncate($item['title'], 24),
                        'description' => self::truncate($item['description'] ?? '', 72),
                    ], array_slice($menu, 5, 3)), // create, my, pending
                ],
                [
                    'title' => '⚙️ Account',
                    'rows' => array_map(fn($item) => [
                        'id' => $item['id'],
                        'title' => self::truncate($item['title'], 24),
                        'description' => self::truncate($item['description'] ?? '', 72),
                    ], array_slice($menu, 8)), // settings
                ],
            ];
        }

        // For shop owners - organize into sections
        return [
            [
                'title' => '🏪 My Shop',
                'rows' => array_map(fn($item) => [
                    'id' => $item['id'],
                    'title' => self::truncate($item['title'], 24),
                    'description' => self::truncate($item['description'] ?? '', 72),
                ], array_slice($menu, 0, 5)), // upload, requests, my offers, browse, fish
            ],
            [
                'title' => '📋 Agreements',
                'rows' => array_map(fn($item) => [
                    'id' => $item['id'],
                    'title' => self::truncate($item['title'], 24),
                    'description' => self::truncate($item['description'] ?? '', 72),
                ], array_slice($menu, 5, 3)), // create, my, pending
            ],
            [
                'title' => '⚙️ Settings',
                'rows' => array_map(fn($item) => [
                    'id' => $item['id'],
                    'title' => self::truncate($item['title'], 24),
                    'description' => self::truncate($item['description'] ?? '', 72),
                ], array_slice($menu, 8)), // shop profile, settings
            ],
        ];
    }

    /**
     * Build quick action buttons (for simpler menu).
     * 
     * ENHANCED: Context-aware quick actions with fish support.
     */
    public static function buildQuickButtons(?User $user): array
    {
        if (!$user || !$user->registered_at) {
            return [
                ['id' => 'register', 'title' => '📝 Register Free'],
                ['id' => 'browse_offers', 'title' => '🛍️ Browse'],
                ['id' => 'about', 'title' => 'ℹ️ About'],
            ];
        }

        if ($user->type === UserType::FISH_SELLER) {
            return [
                ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                ['id' => 'fish_update_stock', 'title' => '📦 Update Stock'],
                ['id' => 'more', 'title' => '📋 More Options'],
            ];
        }

        if ($user->type === UserType::SHOP) {
            return [
                ['id' => 'upload_offer', 'title' => '📤 Upload Offer'],
                ['id' => 'product_requests', 'title' => '📬 Requests'],
                ['id' => 'more', 'title' => '📋 More Options'],
            ];
        }

        return [
            ['id' => 'browse_offers', 'title' => '🛍️ Browse'],
            ['id' => 'fish_browse', 'title' => '🐟 Fresh Fish'],
            ['id' => 'more', 'title' => '📋 More Options'],
        ];
    }

    /**
     * Get welcome message for first-time users.
     */
    public static function getWelcomeMessage(): string
    {
        return "🙏 *NearBuy-ലേക്ക് സ്വാഗതം!*\n\n" .
            "Your local marketplace on WhatsApp 🛒\n\n" .
            "I can help you:\n" .
            "• 🛍️ Browse offers from nearby shops\n" .
            "• 🐟 Find fresh fish from local sellers\n" .
            "• 🔍 Find products locally\n" .
            "• 📝 Create digital agreements\n\n" .
            "_No app download needed!_\n\n" .
            "Let's get started 👇";
    }

    /**
     * Get about message.
     */
    public static function getAboutMessage(): string
    {
        return "ℹ️ *About NearBuy*\n\n" .
            "NearBuy connects you with local shops and services - all through WhatsApp!\n\n" .
            "✨ *Features:*\n\n" .
            "🛍️ *Browse Offers*\n" .
            "See deals from shops near you\n\n" .
            "🐟 *Fresh Fish (Pacha Meen)*\n" .
            "Get alerts when fresh fish arrives nearby\n\n" .
            "🔍 *Product Search*\n" .
            "Tell us what you need, we'll find it locally\n\n" .
            "📝 *Digital Agreements*\n" .
            "Record loans, advances & deposits securely\n\n" .
            "🏪 *For Shop Owners*\n" .
            "Upload offers and reach nearby customers\n\n" .
            "🎣 *For Fish Sellers*\n" .
            "Post catches and notify subscribers instantly\n\n" .
            "_Free to use • No app download needed_";
    }

    /**
     * Get help message.
     */
    public static function getHelpMessage(): string
    {
        return "ℹ️ *NearBuy Help*\n\n" .
            "*Navigation:*\n" .
            "• Type *menu* - Return to main menu\n" .
            "• Type *cancel* - Cancel current action\n" .
            "• Type *back* - Go to previous step\n" .
            "• Type *help* - Show this message\n\n" .
            "*Quick Commands:*\n" .
            "• Type *browse* - Browse offers\n" .
            "• Type *fish* - Browse fresh fish\n" .
            "• Type *search* - Search for products\n" .
            "• Type *agree* - Create agreement\n\n" .
            "_Need more help?_\n" .
            "Contact: " . config('nearbuy.app.support_phone', '+91 XXXXX XXXXX');
    }

    /**
     * Get statistics for shop dashboard (optional enhancement).
     */
    public static function getShopStats(User $user): ?string
    {
        if ($user->type !== UserType::SHOP || !$user->shop) {
            return null;
        }

        $shop = $user->shop;

        // Get stats (you'd need to implement these counts)
        $activeOffers = $shop->offers()->where('expires_at', '>', now())->count();
        $pendingRequests = 0; // Implement based on your logic
        $totalViews = $shop->offers()->sum('views') ?? 0;

        return "📊 *Shop Stats*\n\n" .
            "🏷️ Active Offers: {$activeOffers}\n" .
            "📬 Pending Requests: {$pendingRequests}\n" .
            "👀 Total Views: {$totalViews}";
    }

    /**
     * Get statistics for fish seller dashboard.
     */
    public static function getFishSellerStats(User $user): ?string
    {
        if ($user->type !== UserType::FISH_SELLER || !$user->fishSeller) {
            return null;
        }

        $seller = $user->fishSeller;

        $activeCatches = $seller->catches()->where('status', 'available')->count();
        $todayViews = $seller->catches()->whereDate('created_at', today())->sum('views') ?? 0;

        return "📊 *Today's Stats*\n\n" .
            "🐟 Active Catches: {$activeCatches}\n" .
            "👀 Views Today: {$todayViews}";
    }

    /**
     * Build contextual greeting based on time of day.
     */
    public static function getTimeBasedGreeting(string $name): string
    {
        $hour = (int) now()->format('H');

        $greeting = match (true) {
            $hour >= 5 && $hour < 12 => '🌅 Good morning',
            $hour >= 12 && $hour < 17 => '☀️ Good afternoon',
            $hour >= 17 && $hour < 21 => '🌆 Good evening',
            default => '🌙 Hello',
        };

        return "{$greeting}, *{$name}*!";
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