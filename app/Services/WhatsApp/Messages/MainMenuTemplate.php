<?php

namespace App\Services\WhatsApp\Messages;

use App\Models\User;
use App\Enums\UserType;
use App\Services\WhatsApp\Messages\MessageTemplates;

/**
 * Template builder for main menu messages.
 *
 * Generates different menus based on user type (customer vs shop owner).
 */
class MainMenuTemplate
{
    /**
     * Customer menu options.
     */
    public const CUSTOMER_MENU = [
        [
            'id' => 'browse_offers',
            'title' => MessageTemplates::OFFERS_BROWSE_HEADER,
            'description' => 'See offers from nearby shops',
        ],
        [
            'id' => 'search_product',
            'title' => '🔍 Search Product',
            'description' => 'Find products from local shops',
        ],
        [
            'id' => 'my_requests',
            'title' => '📬 My Requests',
            'description' => 'View your product requests',
        ],
        [
            'id' => 'create_agreement',
            'title' => '📝 Create Agreement',
            'description' => 'Create a digital agreement',
        ],
        [
            'id' => 'my_agreements',
            'title' => '📋 My Agreements',
            'description' => 'View your agreements',
        ],
        [
            'id' => 'settings',
            'title' => '⚙️ Settings',
            'description' => 'Update your preferences',
        ],
    ];

    /**
     * Shop owner menu options.
     */
    public const SHOP_MENU = [
        [
            'id' => 'upload_offer',
            'title' => '📤 Upload Offer',
            'description' => 'Share a new offer',
        ],
        [
            'id' => 'my_offers',
            'title' => '🏷️ My Offers',
            'description' => 'Manage your active offers',
        ],
        [
            'id' => 'product_requests',
            'title' => '📬 Product Requests',
            'description' => 'View customer requests',
        ],
        [
            'id' => 'browse_offers',
            'title' => '🛍️ Browse Offers',
            'description' => 'See offers from other shops',
        ],
        [
            'id' => 'create_agreement',
            'title' => '📝 Create Agreement',
            'description' => 'Create a digital agreement',
        ],
        [
            'id' => 'my_agreements',
            'title' => '📋 My Agreements',
            'description' => 'View your agreements',
        ],
        [
            'id' => 'shop_profile',
            'title' => '🏪 Shop Profile',
            'description' => 'Update shop details',
        ],
        [
            'id' => 'settings',
            'title' => '⚙️ Settings',
            'description' => 'Update your preferences',
        ],
    ];

    /**
     * Unregistered user menu (limited options).
     */
    public const UNREGISTERED_MENU = [
        [
            'id' => 'register',
            'title' => '📝 Register',
            'description' => 'Create your account',
        ],
        [
            'id' => 'browse_offers',
            'title' => '🛍️ Browse Offers',
            'description' => 'See offers (limited)',
        ],
        [
            'id' => 'about',
            'title' => 'ℹ️ About NearBuy',
            'description' => 'Learn more about us',
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

        if ($user->type === UserType::SHOP) {
            return $greeting . "\n\n" . MessageTemplates::MAIN_MENU_SHOP;
        }

        return $greeting . "\n\n" . MessageTemplates::MAIN_MENU_CUSTOMER;
    }

    /**
     * Get the menu footer.
     */
    public static function getFooter(): string
    {
        return MessageTemplates::MAIN_MENU_FOOTER;
    }

    /**
     * Get button text for opening list.
     */
    public static function getButtonText(): string
    {
        return "📋 View Options";
    }

    /**
     * Build list sections for WhatsApp list message.
     */
    public static function buildListSections(?User $user): array
    {
        $menu = self::getMenuForUser($user);

        // Split into sections if more than 10 items
        if (count($menu) <= 10) {
            return [
                [
                    'title' => 'Menu',
                    'rows' => array_map(fn($item) => [
                        'id' => $item['id'],
                        'title' => self::truncate($item['title'], 24),
                        'description' => self::truncate($item['description'] ?? '', 72),
                    ], $menu),
                ],
            ];
        }

        // Split into two sections
        $firstHalf = array_slice($menu, 0, 5);
        $secondHalf = array_slice($menu, 5);

        return [
            [
                'title' => 'Main Options',
                'rows' => array_map(fn($item) => [
                    'id' => $item['id'],
                    'title' => self::truncate($item['title'], 24),
                    'description' => self::truncate($item['description'] ?? '', 72),
                ], $firstHalf),
            ],
            [
                'title' => 'More Options',
                'rows' => array_map(fn($item) => [
                    'id' => $item['id'],
                    'title' => self::truncate($item['title'], 24),
                    'description' => self::truncate($item['description'] ?? '', 72),
                ], $secondHalf),
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
                ['id' => 'register', 'title' => '📝 Register'],
                ['id' => 'browse_offers', 'title' => '🛍️ Browse'],
                ['id' => 'about', 'title' => 'ℹ️ About'],
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
            ['id' => 'search_product', 'title' => '🔍 Search'],
            ['id' => 'more', 'title' => '📋 More Options'],
        ];
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