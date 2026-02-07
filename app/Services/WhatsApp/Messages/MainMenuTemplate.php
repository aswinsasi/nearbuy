<?php

namespace App\Services\WhatsApp\Messages;

use App\Models\User;
use App\Enums\UserType;

/**
 * Main Menu Template for NearBuy.
 *
 * FIRST THING USERS SEE - Must be clean, inviting, and VIRAL-worthy for Kerala.
 *
 * WhatsApp List Constraints:
 * - Max 10 items TOTAL across all sections
 * - Title: max 24 characters
 * - Description: max 72 characters
 * - Body: max 1024 characters (keep it SHORT - 2 lines ideal)
 *
 * Menu Structure:
 * - Section 1: Quick Actions (role-based, 1-2 items)
 * - Section 2: All Services (6 core modules)
 * - Section 3: More (settings)
 *
 * @srs-ref Section 6.2 - Unified Menu Structure
 */
class MainMenuTemplate
{
    /*
    |--------------------------------------------------------------------------
    | Core Menu Items (SRS Section 6.2)
    |--------------------------------------------------------------------------
    */

    /**
     * All 6 core service modules - EXACTLY as specified in SRS.
     */
    public const CORE_SERVICES = [
        [
            'id' => 'browse_offers',
            'title' => '🛍️ Shop Offers',
            'description' => 'Nearby deals & offers',
        ],
        [
            'id' => 'search_product',
            'title' => '🔍 Find Product',
            'description' => 'Search anything nearby',
        ],
        [
            'id' => 'fish_browse',
            'title' => '🐟 Pacha Meen',
            'description' => 'Fresh fish alerts',
        ],
        [
            'id' => 'job_browse',
            'title' => '👷 Njaanum Panikkar',
            'description' => 'Post or find work',
        ],
        [
            'id' => 'flash_deals',
            'title' => '⚡ Flash Deals',
            'description' => 'Group deals with timer',
        ],
        [
            'id' => 'create_agreement',
            'title' => '📝 Agreements',
            'description' => 'Digital karar',
        ],
    ];

    /**
     * Settings/More section.
     */
    public const MORE_OPTIONS = [
        [
            'id' => 'settings',
            'title' => '⚙️ Settings',
            'description' => 'Location, notifications',
        ],
    ];

    /*
    |--------------------------------------------------------------------------
    | Role-Based Quick Actions
    |--------------------------------------------------------------------------
    */

    /**
     * Quick actions for shop owners.
     */
    public const SHOP_QUICK_ACTIONS = [
        [
            'id' => 'upload_offer',
            'title' => '📤 Upload Offer',
            'description' => 'Post new deal now',
        ],
        [
            'id' => 'product_requests',
            'title' => '📬 Customer Requests',
            'description' => 'See what people need',
        ],
    ];

    /**
     * Quick actions for fish sellers.
     */
    public const FISH_SELLER_QUICK_ACTIONS = [
        [
            'id' => 'fish_post_catch',
            'title' => '🎣 Post Catch',
            'description' => 'Share fresh arrival',
        ],
        [
            'id' => 'fish_update_stock',
            'title' => '📦 Update Stock',
            'description' => 'Change availability',
        ],
    ];

    /**
     * Quick actions for job workers.
     */
    public const WORKER_QUICK_ACTIONS = [
        [
            'id' => 'job_browse',
            'title' => '🔍 Available Jobs',
            'description' => 'Find work near you',
        ],
        [
            'id' => 'job_worker_menu',
            'title' => '👷 My Jobs',
            'description' => 'Track your tasks',
        ],
    ];

    /**
     * Quick actions for new/unregistered users.
     */
    public const NEW_USER_QUICK_ACTIONS = [
        [
            'id' => 'register',
            'title' => '📝 Register Free',
            'description' => 'Join in 30 seconds',
        ],
    ];

    /*
    |--------------------------------------------------------------------------
    | Header, Body, Button Text
    |--------------------------------------------------------------------------
    */

    /**
     * Get menu header.
     */
    public static function getHeader(): string
    {
        return 'NearBuy 🛒';
    }

    /**
     * Get menu body - personalized greeting.
     * KEEP IT SHORT: Max 2 lines for clean look.
     */
    public static function getBody(?User $user): string
    {
        if (!$user || !$user->registered_at) {
            return "🙏 Swaagatham!\nEntha vendathu?";
        }

        $name = $user->name ?? 'Friend';
        $firstName = explode(' ', trim($name))[0];

        return "Hii {$firstName}! 👋\nEntha vendathu?";
    }

    /**
     * Get button text for opening the list.
     */
    public static function getButtonText(): string
    {
        return 'Menu kaanuka 📋';
    }

    /**
     * Get footer text.
     */
    public static function getFooter(): string
    {
        return 'NearBuy • Kerala\'s Local Market';
    }

    /*
    |--------------------------------------------------------------------------
    | Build List Sections
    |--------------------------------------------------------------------------
    */

    /**
     * Build complete list sections for user.
     *
     * Structure:
     * - Section 1: Quick Actions (role-based, 1-2 items)
     * - Section 2: All Services (6 items)
     * - Section 3: More (1 item)
     *
     * TOTAL: Max 9 items (well under 10 limit)
     *
     * @return array WhatsApp list sections
     */
    public static function buildListSections(?User $user): array
    {
        $sections = [];

        // Section 1: Quick Actions (role-based)
        $quickActions = self::getQuickActionsForUser($user);
        if (!empty($quickActions)) {
            $sections[] = [
                'title' => self::getQuickActionsSectionTitle($user),
                'rows' => self::formatRows($quickActions),
            ];
        }

        // Section 2: All Services (6 core modules)
        $sections[] = [
            'title' => 'All Services',
            'rows' => self::formatRows(self::CORE_SERVICES),
        ];

        // Section 3: More
        $sections[] = [
            'title' => 'More',
            'rows' => self::formatRows(self::MORE_OPTIONS),
        ];

        return $sections;
    }

    /**
     * Get quick actions based on user role.
     */
    public static function getQuickActionsForUser(?User $user): array
    {
        // Unregistered user
        if (!$user || !$user->registered_at) {
            return self::NEW_USER_QUICK_ACTIONS;
        }

        // Priority 1: Fish seller (by type OR profile)
        if ($user->type === UserType::FISH_SELLER || $user->fishSeller !== null) {
            return self::FISH_SELLER_QUICK_ACTIONS;
        }

        // Priority 2: Job worker (has worker profile)
        if ($user->jobWorker !== null) {
            return self::WORKER_QUICK_ACTIONS;
        }

        // Priority 3: Shop owner
        if ($user->type === UserType::SHOP) {
            return self::SHOP_QUICK_ACTIONS;
        }

        // Priority 4: Regular customer - no quick actions, they see full menu
        return [];
    }

    /**
     * Get section title for quick actions.
     */
    public static function getQuickActionsSectionTitle(?User $user): string
    {
        if (!$user || !$user->registered_at) {
            return '🚀 Get Started';
        }

        if ($user->type === UserType::FISH_SELLER || $user->fishSeller !== null) {
            return '🐟 Fish Seller';
        }

        if ($user->jobWorker !== null) {
            return '👷 Worker';
        }

        if ($user->type === UserType::SHOP) {
            return '🏪 Your Shop';
        }

        return '⚡ Quick Actions';
    }

    /**
     * Format rows for WhatsApp list (enforce character limits).
     */
    private static function formatRows(array $items): array
    {
        return array_map(fn($item) => [
            'id' => $item['id'],
            'title' => self::truncate($item['title'], 24),
            'description' => self::truncate($item['description'] ?? '', 72),
        ], $items);
    }

    /*
    |--------------------------------------------------------------------------
    | Quick Buttons (Alternative to List)
    |--------------------------------------------------------------------------
    */

    /**
     * Build quick action buttons (max 3).
     * Used for returning users who don't need full menu.
     */
    public static function buildQuickButtons(?User $user): array
    {
        // Unregistered
        if (!$user || !$user->registered_at) {
            return [
                ['id' => 'register', 'title' => '📝 Register'],
                ['id' => 'browse_offers', 'title' => '🛍️ Browse'],
                ['id' => 'about', 'title' => 'ℹ️ About'],
            ];
        }

        // Fish seller
        if ($user->type === UserType::FISH_SELLER || $user->fishSeller !== null) {
            return [
                ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                ['id' => 'fish_update_stock', 'title' => '📦 Stock'],
                ['id' => 'more', 'title' => '📋 Full Menu'],
            ];
        }

        // Worker
        if ($user->jobWorker !== null) {
            return [
                ['id' => 'job_browse', 'title' => '🔍 Find Work'],
                ['id' => 'job_worker_menu', 'title' => '👷 My Jobs'],
                ['id' => 'more', 'title' => '📋 Full Menu'],
            ];
        }

        // Shop owner
        if ($user->type === UserType::SHOP) {
            return [
                ['id' => 'upload_offer', 'title' => '📤 Upload'],
                ['id' => 'product_requests', 'title' => '📬 Requests'],
                ['id' => 'more', 'title' => '📋 Full Menu'],
            ];
        }

        // Regular customer
        return [
            ['id' => 'browse_offers', 'title' => '🛍️ Offers'],
            ['id' => 'search_product', 'title' => '🔍 Search'],
            ['id' => 'more', 'title' => '📋 Full Menu'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Greeting Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Get time-based greeting.
     */
    public static function getTimeBasedGreeting(string $name): string
    {
        $hour = (int) now()->format('H');
        $firstName = explode(' ', trim($name))[0];

        if ($hour >= 5 && $hour < 12) {
            return "🌅 Suprabhaatham, {$firstName}!";
        } elseif ($hour >= 12 && $hour < 17) {
            return "☀️ Hii {$firstName}!";
        } elseif ($hour >= 17 && $hour < 21) {
            return "🌆 Good evening, {$firstName}!";
        } else {
            return "🌙 Hii {$firstName}!";
        }
    }

    /**
     * Get welcome message for first-time users.
     */
    public static function getWelcomeMessage(): string
    {
        return "🙏 *NearBuy-ലേക്ക് സ്വാഗതം!*\n\n" .
            "Kerala's own local marketplace 🛒\n\n" .
            "🛍️ Shop Offers nearby\n" .
            "🐟 Fresh Fish alerts\n" .
            "👷 Find work or post tasks\n" .
            "⚡ Flash Deals\n" .
            "📝 Digital Agreements\n\n" .
            "_No app download • 100% WhatsApp_";
    }

    /**
     * Get about message.
     */
    public static function getAboutMessage(): string
    {
        return "ℹ️ *About NearBuy*\n\n" .
            "Kerala's hyperlocal marketplace on WhatsApp!\n\n" .
            "🛍️ *Shop Offers* - Deals from shops near you\n" .
            "🐟 *Pacha Meen* - Fresh fish arrival alerts\n" .
            "👷 *Njaanum Panikkar* - Find work or post tasks\n" .
            "⚡ *Flash Deals* - Group discounts\n" .
            "📝 *Agreements* - Digital karar\n\n" .
            "_Free • No app • Made for Kerala_";
    }

    /**
     * Get help message.
     */
    public static function getHelpMessage(): string
    {
        return "ℹ️ *Help*\n\n" .
            "*Commands:*\n" .
            "• *menu* - Main menu\n" .
            "• *cancel* - Stop current action\n" .
            "• *back* - Go back one step\n\n" .
            "*Quick words:*\n" .
            "• *offers* / *browse* - See deals\n" .
            "• *fish* / *meen* - Fresh fish\n" .
            "• *jobs* / *work* - Find work\n" .
            "• *search* - Find product\n\n" .
            "_Questions? Just ask!_";
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Truncate text to fit WhatsApp limits.
     */
    private static function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1) . '…';
    }
}