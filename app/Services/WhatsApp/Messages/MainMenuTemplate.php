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
 * IMPORTANT: Fish seller and Job worker options should be shown to ANY user
 * who has a fishSeller or jobWorker profile, not just users with specific types.
 * A SHOP or CUSTOMER user can also have fishSeller and/or jobWorker profiles.
 *
 * @srs-ref Section 6.2 - Unified Menu Structure
 * @srs-ref Section 2.2 - Any user can become a fish seller
 * @srs-ref Section 3 - Jobs Marketplace Module (Njaanum Panikkar)
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
            'title' => '🛍️ Shop Offers',
            'description' => 'Browse nearby deals',
        ],
        [
            'id' => 'search_product',
            'title' => '🔍 Find Product',
            'description' => 'Search for items',
        ],
        [
            'id' => 'fish_browse',
            'title' => '🐟 Fresh Fish',
            'description' => 'Pacha Meen alerts',
        ],
        [
            'id' => 'job_browse',
            'title' => '👷 Jobs',
            'description' => 'Post or find work',
        ],
        [
            'id' => 'my_requests',
            'title' => '📬 My Requests',
            'description' => 'Check responses from shops',
        ],
        [
            'id' => 'create_agreement',
            'title' => '📝 Agreements',
            'description' => 'Digital contracts',
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
        [
            'id' => 'job_browse',
            'title' => '👷 Jobs',
            'description' => 'Post or find work',
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
            'id' => 'job_browse',
            'title' => '👷 Jobs',
            'description' => 'Post or find work',
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
     * Job worker menu options (for users with type=JOB_WORKER or jobWorker profile) - 8 items.
     *
     * @srs-ref Section 3 - Jobs Marketplace Module
     */
    public const JOB_WORKER_MENU = [
        [
            'id' => 'job_browse',
            'title' => '🔍 Find Work',
            'description' => 'Browse available tasks',
        ],
        [
            'id' => 'job_worker_menu',
            'title' => '👷 My Jobs',
            'description' => 'View assigned tasks',
        ],
        [
            'id' => 'job_post',
            'title' => '📋 Post Task',
            'description' => 'Need help? Post a task',
        ],
        [
            'id' => 'job_poster_menu',
            'title' => '📊 My Posted Tasks',
            'description' => 'Manage tasks you posted',
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
            'id' => 'settings',
            'title' => '⚙️ Settings',
            'description' => 'Update your profile',
        ],
    ];

    /**
     * Unregistered user menu - 5 items.
     */
    public const UNREGISTERED_MENU = [
        [
            'id' => 'register',
            'title' => '📝 Register',
            'description' => 'Create your free account',
        ],
        [
            'id' => 'browse_offers',
            'title' => '🛍️ Shop Offers',
            'description' => 'Browse nearby deals',
        ],
        [
            'id' => 'fish_browse',
            'title' => '🐟 Fresh Fish',
            'description' => 'Pacha Meen alerts',
        ],
        [
            'id' => 'job_browse',
            'title' => '👷 Jobs',
            'description' => 'Post or find work',
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
     * 2. If user has type=JOB_WORKER → show JOB_WORKER_MENU
     * 3. If user has fishSeller profile (but type=SHOP or CUSTOMER) → show their menu WITH fish seller options
     * 4. If user has jobWorker profile (but type=SHOP or CUSTOMER) → show their menu WITH job worker options
     * 5. Otherwise → show normal menu based on type
     *
     * @srs-ref Section 2.2: Any user can become a fish seller
     * @srs-ref Section 3: Any user can become a job worker
     */
    public static function getMenuForUser(?User $user): array
    {
        if (!$user || !$user->registered_at) {
            return self::UNREGISTERED_MENU;
        }

        // Users with PRIMARY type FISH_SELLER get dedicated fish seller menu
        if ($user->type === UserType::FISH_SELLER) {
            return self::addJobWorkerOptionsIfNeeded($user, self::FISH_SELLER_MENU);
        }

        // Check if user has fish seller profile (can sell fish)
        $isFishSeller = $user->fishSeller !== null;
        
        // Check if user has job worker profile (can do tasks)
        $isJobWorker = $user->jobWorker !== null;
        
        // Check subscription status for alerts option
        $hasSubscription = method_exists($user, 'activeFishSubscriptions') 
            ? $user->activeFishSubscriptions()->exists() 
            : false;

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
        }

        // If user is a job worker, add job worker options near the top
        if ($isJobWorker) {
            $adjustedMenu[] = [
                'id' => 'job_worker_menu',
                'title' => '👷 My Jobs',
                'description' => 'View assigned tasks',
            ];
        }

        // Add base menu items
        foreach ($baseMenu as $item) {
            // Skip fish_browse if user is fish seller (they have their own fish options)
            if ($isFishSeller && $item['id'] === 'fish_browse') {
                continue;
            }

            // Replace job_browse with worker dashboard if user is a worker
            if ($isJobWorker && $item['id'] === 'job_browse') {
                $adjustedMenu[] = [
                    'id' => 'job_browse',
                    'title' => '🔍 Find More Work',
                    'description' => 'Browse available tasks',
                ];
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
            // Find a good position (before settings)
            $settingsIndex = array_search('settings', array_column($adjustedMenu, 'id'));
            $shopProfileIndex = array_search('shop_profile', array_column($adjustedMenu, 'id'));
            $insertIndex = $settingsIndex !== false ? $settingsIndex : ($shopProfileIndex !== false ? $shopProfileIndex : count($adjustedMenu));
            
            array_splice($adjustedMenu, $insertIndex, 0, [[
                'id' => 'fish_seller_register',
                'title' => '🎣 Sell Fish',
                'description' => 'Register to sell fresh fish',
            ]]);
        }

        // Add "Become Worker" option ONLY if user is NOT already a job worker and we have room
        if (!$isJobWorker && count($adjustedMenu) < 10) {
            // Find a good position (before settings)
            $settingsIndex = array_search('settings', array_column($adjustedMenu, 'id'));
            $shopProfileIndex = array_search('shop_profile', array_column($adjustedMenu, 'id'));
            $insertIndex = $settingsIndex !== false ? $settingsIndex : ($shopProfileIndex !== false ? $shopProfileIndex : count($adjustedMenu));
            
            array_splice($adjustedMenu, $insertIndex, 0, [[
                'id' => 'job_worker_register',
                'title' => '👷 Become Worker',
                'description' => 'Register to do tasks',
            ]]);
        }

        // STRICT ENFORCEMENT: Never exceed 10 items
        return array_slice($adjustedMenu, 0, 10);
    }

    /**
     * Add fish seller options to a menu if user has fish seller profile.
     */
    private static function addFishSellerOptionsIfNeeded(?User $user, array $menu): array
    {
        if (!$user || $user->fishSeller === null) {
            return array_slice($menu, 0, 10);
        }

        // Add fish seller options at a reasonable position
        $fishOptions = [
            [
                'id' => 'fish_post_catch',
                'title' => '🎣 Post Catch',
                'description' => 'Add fresh fish posting',
            ],
        ];

        // Insert after main job options
        array_splice($menu, 4, 0, $fishOptions);

        return array_slice($menu, 0, 10);
    }

    /**
     * Add job worker options to a menu if user has job worker profile.
     */
    private static function addJobWorkerOptionsIfNeeded(?User $user, array $menu): array
    {
        if (!$user || $user->jobWorker === null) {
            return array_slice($menu, 0, 10);
        }

        // Add job worker options at a reasonable position
        $jobOptions = [
            [
                'id' => 'job_worker_menu',
                'title' => '👷 My Jobs',
                'description' => 'View assigned tasks',
            ],
        ];

        // Insert after main fish options
        array_splice($menu, 4, 0, $jobOptions);

        return array_slice($menu, 0, 10);
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
            $extra = '';
            if ($user->jobWorker) {
                $extra = "\n👷 Also working as: *" . ($user->jobWorker->display_name ?? 'Worker') . "*";
            }
            return $greeting . "\n\n🐟 *{$businessName}*{$extra}\n\n" . self::getFishSellerMenuText();
        }

        // Shop owner (may also be fish seller and/or job worker)
        if ($user->type === UserType::SHOP) {
            $shopName = $user->shop?->shop_name ?? 'Your Shop';
            $extra = '';
            if ($user->fishSeller) {
                $extra .= "\n🐟 Selling fish as: *{$user->fishSeller->business_name}*";
            }
            if ($user->jobWorker) {
                $extra .= "\n👷 Working as: *" . ($user->jobWorker->display_name ?? 'Worker') . "*";
            }
            return $greeting . "\n\n🏪 *{$shopName}*{$extra}\n\n" . MessageTemplates::MAIN_MENU_SHOP;
        }

        // Customer (may also be fish seller and/or job worker)
        $extra = '';
        if ($user->fishSeller) {
            $extra .= "\n\n🐟 Selling as: *{$user->fishSeller->business_name}*";
        }
        if ($user->jobWorker) {
            $extra .= "\n👷 Working as: *" . ($user->jobWorker->display_name ?? 'Worker') . "*";
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
     * Get job worker menu text.
     *
     * @srs-ref Section 3 - Jobs Marketplace Module
     */
    public static function getJobWorkerMenuText(): string
    {
        return "Find tasks nearby and earn money helping others!";
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
     * Get time-based greeting.
     */
    public static function getTimeBasedGreeting(string $name): string
    {
        $hour = (int) now()->format('H');
        
        if ($hour < 12) {
            return "🌅 Good morning, {$name}!";
        } elseif ($hour < 17) {
            return "☀️ Good afternoon, {$name}!";
        } else {
            return "🌙 Good evening, {$name}!";
        }
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
                $title = '🏪 Shop Menu';
                if ($user->fishSeller && $user->jobWorker) {
                    $sectionTitle = '🏪 Shop & More';
                } elseif ($user->fishSeller) {
                    $sectionTitle = '🏪 Shop & Fish Menu';
                } elseif ($user->jobWorker) {
                    $sectionTitle = '🏪 Shop & Jobs';
                } else {
                    $sectionTitle = $title;
                }
            } else {
                // CUSTOMER type - check for additional profiles
                if ($user->fishSeller && $user->jobWorker) {
                    $sectionTitle = '📋 Menu';
                } elseif ($user->fishSeller) {
                    $sectionTitle = '🐟 Menu';
                } elseif ($user->jobWorker) {
                    $sectionTitle = '👷 Menu';
                } else {
                    $sectionTitle = '📋 Menu';
                }
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

        // Shop owner who is also a job worker
        if ($user->type === UserType::SHOP && $user->jobWorker) {
            return [
                ['id' => 'job_browse', 'title' => '🔍 Find Work'],
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

        // Customer who is also a job worker
        if ($user->jobWorker) {
            return [
                ['id' => 'job_browse', 'title' => '🔍 Find Work'],
                ['id' => 'browse_offers', 'title' => '🛍️ Browse'],
                ['id' => 'more', 'title' => '📋 More Options'],
            ];
        }

        // Regular customer
        return [
            ['id' => 'browse_offers', 'title' => '🛍️ Browse'],
            ['id' => 'job_browse', 'title' => '👷 Jobs'],
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
            "• 👷 Post tasks or find work\n" .
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
            "🛍️ *Shop Offers*\n" .
            "Browse deals from shops near you\n\n" .
            "🐟 *Fresh Fish (Pacha Meen)*\n" .
            "Get alerts when fresh fish arrives nearby\n\n" .
            "👷 *Jobs (Njaanum Panikkar)*\n" .
            "Post tasks or find work in your area\n\n" .
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
            "• Type *jobs* - Find work or post tasks\n" .
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