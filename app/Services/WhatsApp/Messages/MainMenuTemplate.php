<?php

namespace App\Services\WhatsApp\Messages;

use App\Models\User;
use App\Enums\UserType;
use App\Services\WhatsApp\Messages\MessageTemplates;

/**
 * ENHANCED Template builder for main menu messages.
 *
 * CRITICAL: WhatsApp list messages have a hard limit of 10 items total across all sections.
 *
 * IMPORTANT: Fish seller options (Post Catch, Update Stock) should be shown to ANY user
 * who has a fishSeller profile, not just users with type=FISH_SELLER.
 * A SHOP or CUSTOMER user can also have a fishSeller profile.
 *
 * @srs-ref Section 6.2 - Unified Menu Structure
 * @srs-ref Section 2.2 - Any user can become a fish seller
 * @srs-ref PM-015 - Subscription modification
 */
class MainMenuTemplate
{
    /**
     * Customer menu options - 8 items base.
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
        // Fish alerts - DYNAMIC: replaced with subscribe OR manage
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
            'id' => 'settings',
            'title' => '⚙️ Settings',
            'description' => 'Update your profile',
        ],
    ];

    /**
     * Shop owner menu options - 8 items base.
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
            'id' => 'fish_browse',
            'title' => '🐟 Fresh Fish',
            'description' => 'Browse nearby fresh fish',
        ],
        // Fish alerts - DYNAMIC: replaced with subscribe OR manage
        [
            'id' => 'fish_subscribe',
            'title' => '🔔 Fish Alerts',
            'description' => 'Get notified when fish arrives',
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
            'id' => 'shop_profile',
            'title' => '🏪 Shop Profile',
            'description' => 'Update shop details',
        ],
    ];

    /**
     * Fish seller menu options (for users with type=FISH_SELLER) - 8 items.
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
     * Unregistered user menu - 4 items.
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
            'id' => 'about',
            'title' => 'ℹ️ About NearBuy',
            'description' => 'Learn what we offer',
        ],
    ];

    /**
     * Get menu options for a user.
     *
     * CRITICAL LOGIC:
     * 1. If user has type=FISH_SELLER → show FISH_SELLER_MENU
     * 2. If user has fishSeller profile (but type=SHOP or CUSTOMER) → show their menu WITH fish seller options
     * 3. Otherwise → show normal menu based on type
     *
     * @srs-ref Section 2.2: Any user can become a fish seller
     */
    public static function getMenuForUser(?User $user): array
    {
        if (!$user || !$user->registered_at) {
            return self::UNREGISTERED_MENU;
        }

        // Users with PRIMARY type FISH_SELLER get dedicated fish seller menu
        if ($user->type === UserType::FISH_SELLER) {
            return self::FISH_SELLER_MENU;
        }

        // Check if user has fish seller profile (can sell fish)
        $isFishSeller = $user->fishSeller !== null;
        
        // Check subscription status for alerts option
        $hasSubscription = $user->activeFishSubscriptions()->exists();

        // Get base menu based on user type
        $baseMenu = $user->type === UserType::SHOP ? self::SHOP_MENU : self::CUSTOMER_MENU;

        // Build adjusted menu
        $adjustedMenu = [];
        
        // If user is a fish seller, add fish seller options at the top
        if ($isFishSeller) {
            $adjustedMenu[] = [
                'id' => 'fish_post_catch',
                'title' => '🎣 Post Catch',
                'description' => 'Add fresh fish posting',
            ];
            $adjustedMenu[] = [
                'id' => 'fish_update_stock',
                'title' => '📦 Update Stock',
                'description' => 'Change availability',
            ];
            $adjustedMenu[] = [
                'id' => 'fish_my_catches',
                'title' => '📋 My Catches',
                'description' => 'View active fish postings',
            ];
        }

        // Add base menu items
        foreach ($baseMenu as $item) {
            // Skip fish_browse if user is fish seller (they have their own fish options)
            if ($isFishSeller && $item['id'] === 'fish_browse') {
                continue;
            }

            // Handle fish alerts option - show Subscribe or Manage based on status
            if ($item['id'] === 'fish_subscribe') {
                if ($hasSubscription) {
                    $adjustedMenu[] = [
                        'id' => 'fish_manage_alerts',
                        'title' => '⚙️ Manage Alerts',
                        'description' => 'Edit or pause fish alerts',
                    ];
                } else {
                    $adjustedMenu[] = $item;
                }
                continue;
            }

            $adjustedMenu[] = $item;
            
            // Stop if we're at 9 items (leave room for 1 more if needed)
            if (count($adjustedMenu) >= 9) {
                break;
            }
        }

        // Add "Sell Fish" option ONLY if user is NOT already a fish seller and we have room
        if (!$isFishSeller && count($adjustedMenu) < 10) {
            // Insert before last item (settings/shop_profile)
            $lastIndex = count($adjustedMenu) - 1;
            array_splice($adjustedMenu, $lastIndex, 0, [[
                'id' => 'fish_seller_register',
                'title' => '🎣 Sell Fish',
                'description' => 'Register to sell fresh fish',
            ]]);
        }

        // STRICT ENFORCEMENT: Never exceed 10 items
        return array_slice($adjustedMenu, 0, 10);
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

        // Fish seller with type=FISH_SELLER
        if ($user->type === UserType::FISH_SELLER) {
            $businessName = $user->fishSeller?->business_name ?? 'Fish Seller';
            return $greeting . "\n\n🐟 *{$businessName}*\n\n" . self::getFishSellerMenuText();
        }

        // Shop owner (may also be fish seller)
        if ($user->type === UserType::SHOP) {
            $shopName = $user->shop?->shop_name ?? 'Your Shop';
            $extra = '';
            if ($user->fishSeller) {
                $extra = "\n🐟 Also selling as: *{$user->fishSeller->business_name}*";
            }
            return $greeting . "\n\n🏪 *{$shopName}*{$extra}\n\n" . MessageTemplates::MAIN_MENU_SHOP;
        }

        // Customer (may also be fish seller)
        $extra = '';
        if ($user->fishSeller) {
            $extra = "\n\n🐟 Selling as: *{$user->fishSeller->business_name}*";
        }
        return $greeting . $extra . "\n\n" . MessageTemplates::MAIN_MENU_CUSTOMER;
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
     * CRITICAL: Total rows across all sections MUST NOT exceed 10.
     */
    public static function buildListSections(?User $user): array
    {
        $menu = self::getMenuForUser($user);

        // Determine section title
        $sectionTitle = '📋 Menu';
        
        if ($user && $user->registered_at) {
            if ($user->type === UserType::FISH_SELLER) {
                $sectionTitle = '🐟 Fish Seller Menu';
            } elseif ($user->type === UserType::SHOP) {
                $sectionTitle = $user->fishSeller ? '🏪 Shop & Fish Menu' : '🏪 Shop Menu';
            } else {
                $sectionTitle = $user->fishSeller ? '🐟 Menu' : '📋 Menu';
            }
        } else {
            $sectionTitle = '🚀 Get Started';
        }

        return [
            [
                'title' => $sectionTitle,
                'rows' => array_map(fn($item) => [
                    'id' => $item['id'],
                    'title' => self::truncate($item['title'], 24),
                    'description' => self::truncate($item['description'] ?? '', 72),
                ], $menu),
            ],
        ];
    }

    /**
     * Build quick action buttons (for simpler menu).
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

        // Fish seller (by type)
        if ($user->type === UserType::FISH_SELLER) {
            return [
                ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                ['id' => 'fish_update_stock', 'title' => '📦 Update Stock'],
                ['id' => 'more', 'title' => '📋 More Options'],
            ];
        }

        // Shop owner who is also a fish seller
        if ($user->type === UserType::SHOP && $user->fishSeller) {
            return [
                ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                ['id' => 'upload_offer', 'title' => '📤 Upload Offer'],
                ['id' => 'more', 'title' => '📋 More Options'],
            ];
        }

        // Regular shop owner
        if ($user->type === UserType::SHOP) {
            return [
                ['id' => 'upload_offer', 'title' => '📤 Upload Offer'],
                ['id' => 'product_requests', 'title' => '📬 Requests'],
                ['id' => 'more', 'title' => '📋 More Options'],
            ];
        }

        // Customer who is also a fish seller
        if ($user->fishSeller) {
            return [
                ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                ['id' => 'browse_offers', 'title' => '🛍️ Browse'],
                ['id' => 'more', 'title' => '📋 More Options'],
            ];
        }

        // Regular customer
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
            "• Type *help* - Show this message\n\n" .
            "*Quick Commands:*\n" .
            "• Type *browse* - Browse offers\n" .
            "• Type *fish* - Browse fresh fish\n" .
            "• Type *search* - Search for products\n\n" .
            "_Need help? Contact support_";
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