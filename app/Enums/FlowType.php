<?php

namespace App\Enums;

/**
 * Available conversation flow types in NearBuy.
 *
 * @srs-ref Section 7.1 High-Level Architecture - Flow Controllers
 */
enum FlowType: string
{
    case REGISTRATION = 'registration';
    case MAIN_MENU = 'main_menu';
    case OFFERS_BROWSE = 'offers_browse';
    case OFFERS_UPLOAD = 'offers_upload';
    case OFFERS_MANAGE = 'offers_manage';
    case PRODUCT_SEARCH = 'product_search';
    case PRODUCT_RESPOND = 'product_respond';
    case AGREEMENT_CREATE = 'agreement_create';
    case AGREEMENT_CONFIRM = 'agreement_confirm';
    case AGREEMENT_LIST = 'agreement_list';
    case SETTINGS = 'settings';

    /*
    |--------------------------------------------------------------------------
    | Pacha Meen (Fish Alert) Flows
    |--------------------------------------------------------------------------
    |
    | @srs-ref Pacha Meen Module
    */
    case FISH_SELLER_REGISTER = 'fish_seller_register';
    case FISH_POST_CATCH = 'fish_post_catch';
    case FISH_STOCK_UPDATE = 'fish_stock_update';
    case FISH_SUBSCRIBE = 'fish_subscribe';
    case FISH_BROWSE = 'fish_browse';
    case FISH_MANAGE_SUBSCRIPTION = 'fish_manage_subscription';
    case FISH_SELLER_MENU = 'fish_seller_menu';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::REGISTRATION => 'Registration',
            self::MAIN_MENU => 'Main Menu',
            self::OFFERS_BROWSE => 'Browse Offers',
            self::OFFERS_UPLOAD => 'Upload Offer',
            self::OFFERS_MANAGE => 'Manage Offers',
            self::PRODUCT_SEARCH => 'Product Search',
            self::PRODUCT_RESPOND => 'Respond to Request',
            self::AGREEMENT_CREATE => 'Create Agreement',
            self::AGREEMENT_CONFIRM => 'Confirm Agreement',
            self::AGREEMENT_LIST => 'My Agreements',
            self::SETTINGS => 'Settings',
            // Fish flows
            self::FISH_SELLER_REGISTER => 'Fish Seller Registration',
            self::FISH_POST_CATCH => 'Post Fish Catch',
            self::FISH_STOCK_UPDATE => 'Update Fish Stock',
            self::FISH_SUBSCRIBE => 'Fish Alert Subscription',
            self::FISH_BROWSE => 'Browse Fresh Fish',
            self::FISH_MANAGE_SUBSCRIPTION => 'Manage Fish Alerts',
            self::FISH_SELLER_MENU => 'Fish Seller Menu',
        };
    }

    /**
     * Get the emoji icon for this flow.
     */
    public function icon(): string
    {
        return match ($this) {
            self::REGISTRATION => '📝',
            self::MAIN_MENU => '🏠',
            self::OFFERS_BROWSE => '🏷️',
            self::OFFERS_UPLOAD => '📤',
            self::OFFERS_MANAGE => '⚙️',
            self::PRODUCT_SEARCH => '🔍',
            self::PRODUCT_RESPOND => '📦',
            self::AGREEMENT_CREATE => '📝',
            self::AGREEMENT_CONFIRM => '✅',
            self::AGREEMENT_LIST => '📋',
            self::SETTINGS => '⚙️',
            // Fish flows
            self::FISH_SELLER_REGISTER => '🐟',
            self::FISH_POST_CATCH => '🎣',
            self::FISH_STOCK_UPDATE => '📦',
            self::FISH_SUBSCRIBE => '🔔',
            self::FISH_BROWSE => '🐟',
            self::FISH_MANAGE_SUBSCRIPTION => '⚙️',
            self::FISH_SELLER_MENU => '🐟',
        };
    }

    /**
     * Get the labeled icon (icon + label).
     */
    public function labeledIcon(): string
    {
        return $this->icon() . ' ' . $this->label();
    }

    /**
     * Get the handler class name.
     */
    public function handlerClass(): string
    {
        return match ($this) {
            self::REGISTRATION => \App\Services\Flow\Handlers\RegistrationFlowHandler::class,
            self::MAIN_MENU => \App\Services\Flow\Handlers\MainMenuHandler::class,
            self::OFFERS_BROWSE => \App\Services\Flow\Handlers\OfferBrowseFlowHandler::class,
            self::OFFERS_UPLOAD => \App\Services\Flow\Handlers\OfferUploadFlowHandler::class,
            self::OFFERS_MANAGE => \App\Services\Flow\Handlers\OfferManageFlowHandler::class,
            self::PRODUCT_SEARCH => \App\Services\Flow\Handlers\ProductSearchFlowHandler::class,
            self::PRODUCT_RESPOND => \App\Services\Flow\Handlers\ProductResponseFlowHandler::class,
            self::AGREEMENT_CREATE => \App\Services\Flow\Handlers\AgreementCreateFlowHandler::class,
            self::AGREEMENT_CONFIRM => \App\Services\Flow\Handlers\AgreementConfirmFlowHandler::class,
            self::AGREEMENT_LIST => \App\Services\Flow\Handlers\AgreementListFlowHandler::class,
            self::SETTINGS => \App\Services\Flow\Handlers\SettingsFlowHandler::class,
            // Fish flows - in Fish subdirectory
            self::FISH_SELLER_REGISTER => \App\Services\Flow\Handlers\Fish\FishSellerRegistrationFlowHandler::class,
            self::FISH_POST_CATCH => \App\Services\Flow\Handlers\Fish\FishCatchPostFlowHandler::class,
            self::FISH_STOCK_UPDATE => \App\Services\Flow\Handlers\Fish\FishStockUpdateFlowHandler::class,
            self::FISH_SUBSCRIBE => \App\Services\Flow\Handlers\Fish\FishSubscriptionFlowHandler::class,
            self::FISH_BROWSE => \App\Services\Flow\Handlers\Fish\FishBrowseFlowHandler::class,
            self::FISH_MANAGE_SUBSCRIPTION => \App\Services\Flow\Handlers\Fish\FishManageSubscriptionHandler::class,
            self::FISH_SELLER_MENU => \App\Services\Flow\Handlers\Fish\FishSellerMenuHandler::class,
        };
    }

    /**
     * Check if this flow requires authentication (registered user).
     *
     * @srs-ref FR-REG-01 New user detection
     */
    public function requiresAuth(): bool
    {
        return match ($this) {
            self::REGISTRATION => false,
            self::MAIN_MENU => false,
            self::FISH_BROWSE => false, // Allow browsing without auth
            default => true,
        };
    }

    /**
     * Check if this flow is for shop owners only.
     *
     * @srs-ref Section 2.3.2 Shop Owners
     */
    public function isShopOnly(): bool
    {
        return in_array($this, [
            self::OFFERS_UPLOAD,
            self::OFFERS_MANAGE,
            self::PRODUCT_RESPOND,
        ]);
    }

    /**
     * Check if this flow is for fish sellers only.
     *
     * @srs-ref Pacha Meen Module
     */
    public function isFishSellerOnly(): bool
    {
        return in_array($this, [
            self::FISH_POST_CATCH,
            self::FISH_STOCK_UPDATE,
            self::FISH_SELLER_MENU,
        ]);
    }

    /**
     * Check if this flow is for customers only.
     *
     * @srs-ref Section 2.3.1 Customers
     */
    public function isCustomerOnly(): bool
    {
        return in_array($this, [
            self::OFFERS_BROWSE,
            self::PRODUCT_SEARCH,
        ]);
    }

    /**
     * Check if this is a fish-related flow.
     *
     * @srs-ref Pacha Meen Module
     */
    public function isFishFlow(): bool
    {
        return in_array($this, [
            self::FISH_SELLER_REGISTER,
            self::FISH_POST_CATCH,
            self::FISH_STOCK_UPDATE,
            self::FISH_SUBSCRIBE,
            self::FISH_BROWSE,
            self::FISH_MANAGE_SUBSCRIPTION,
            self::FISH_SELLER_MENU,
        ]);
    }

    /**
     * Check if this flow is available to all users.
     */
    public function isUniversal(): bool
    {
        return in_array($this, [
            self::REGISTRATION,
            self::MAIN_MENU,
            self::AGREEMENT_CREATE,
            self::AGREEMENT_CONFIRM,
            self::AGREEMENT_LIST,
            self::SETTINGS,
            self::FISH_BROWSE,
            self::FISH_SUBSCRIBE,
            self::FISH_SELLER_REGISTER,
        ]);
    }

    /**
     * Get the initial step for this flow.
     */
    public function initialStep(): string
    {
        return match ($this) {
            self::REGISTRATION => RegistrationStep::ASK_TYPE->value,
            self::MAIN_MENU => 'show_menu',
            self::OFFERS_BROWSE => OfferStep::SELECT_CATEGORY->value,
            self::OFFERS_UPLOAD => OfferStep::UPLOAD_IMAGE->value,
            self::OFFERS_MANAGE => 'show_offers',
            self::PRODUCT_SEARCH => ProductSearchStep::ASK_CATEGORY->value,
            self::PRODUCT_RESPOND => ProductSearchStep::VIEW_REQUEST->value,
            self::AGREEMENT_CREATE => AgreementStep::ASK_DIRECTION->value,
            self::AGREEMENT_CONFIRM => AgreementStep::SHOW_PENDING->value,
            self::AGREEMENT_LIST => AgreementStep::SHOW_LIST->value,
            self::SETTINGS => 'show_settings',
            // Fish flows
            self::FISH_SELLER_REGISTER => 'select_type',
            self::FISH_POST_CATCH => FishCatchStep::SELECT_FISH->value,
            self::FISH_STOCK_UPDATE => 'select_catch',
            self::FISH_SUBSCRIBE => FishSubscriptionStep::SELECT_LOCATION->value,
            self::FISH_BROWSE => 'show_nearby',
            self::FISH_MANAGE_SUBSCRIPTION => 'show_subscription',
            self::FISH_SELLER_MENU => 'show_menu',
        };
    }

    /**
     * Get the initial FlowStep enum for this flow.
     */
    public function initialFlowStep(): ?FlowStep
    {
        return match ($this) {
            self::REGISTRATION => FlowStep::REG_ASK_NAME,
            self::MAIN_MENU => FlowStep::MAIN_MENU,
            self::OFFERS_BROWSE => FlowStep::OFFERS_SELECT_CATEGORY,
            self::OFFERS_UPLOAD => FlowStep::OFFERS_UPLOAD_IMAGE,
            self::OFFERS_MANAGE => FlowStep::OFFERS_MANAGE,
            self::PRODUCT_SEARCH => FlowStep::PRODUCT_ASK_CATEGORY,
            self::PRODUCT_RESPOND => FlowStep::PRODUCT_VIEW_REQUEST,
            self::AGREEMENT_CREATE => FlowStep::AGREE_ASK_DIRECTION,
            self::AGREEMENT_CONFIRM => FlowStep::AGREE_CONFIRM_RECEIVED,
            self::AGREEMENT_LIST => FlowStep::AGREE_VIEW_LIST,
            self::SETTINGS => FlowStep::SETTINGS_MENU,
            // Fish flows - return null as they use their own step enums
            self::FISH_SELLER_REGISTER,
            self::FISH_POST_CATCH,
            self::FISH_STOCK_UPDATE,
            self::FISH_SUBSCRIBE,
            self::FISH_BROWSE,
            self::FISH_MANAGE_SUBSCRIPTION,
            self::FISH_SELLER_MENU => null,
        };
    }

    /**
     * Get menu item configuration for this flow.
     *
     * @return array{id: string, title: string, description: string}|null
     */
    public function menuItem(): ?array
    {
        return match ($this) {
            self::OFFERS_BROWSE => [
                'id' => 'menu_offers',
                'title' => '🏷️ Browse Offers',
                'description' => 'See offers from nearby shops',
            ],
            self::PRODUCT_SEARCH => [
                'id' => 'menu_search',
                'title' => '🔍 Find Product',
                'description' => 'Search for products nearby',
            ],
            self::AGREEMENT_CREATE => [
                'id' => 'menu_agreement',
                'title' => '📝 New Agreement',
                'description' => 'Create a payment agreement',
            ],
            self::AGREEMENT_LIST => [
                'id' => 'menu_my_agreements',
                'title' => '📋 My Agreements',
                'description' => 'View your agreements',
            ],
            self::SETTINGS => [
                'id' => 'menu_settings',
                'title' => '⚙️ Settings',
                'description' => 'Manage your preferences',
            ],
            // Shop-only menu items
            self::OFFERS_UPLOAD => [
                'id' => 'menu_upload_offer',
                'title' => '📤 Upload Offer',
                'description' => 'Post a new offer',
            ],
            self::OFFERS_MANAGE => [
                'id' => 'menu_manage_offers',
                'title' => '📊 My Offers',
                'description' => 'Manage your offers',
            ],
            // Fish menu items - for customers
            self::FISH_BROWSE => [
                'id' => 'menu_fish_browse',
                'title' => '🐟 Fresh Fish',
                'description' => 'Browse fresh fish nearby',
            ],
            self::FISH_SUBSCRIBE => [
                'id' => 'menu_fish_subscribe',
                'title' => '🔔 Fish Alerts',
                'description' => 'Get notified when fish arrives',
            ],
            self::FISH_MANAGE_SUBSCRIPTION => [
                'id' => 'menu_fish_manage',
                'title' => '⚙️ Manage Alerts',
                'description' => 'Manage your fish alerts',
            ],
            // Fish seller menu items
            self::FISH_SELLER_REGISTER => [
                'id' => 'menu_fish_seller_register',
                'title' => '🐟 Become Fish Seller',
                'description' => 'Register as a fish seller',
            ],
            self::FISH_POST_CATCH => [
                'id' => 'menu_fish_post',
                'title' => '🎣 Post Catch',
                'description' => 'Post your fresh catch',
            ],
            self::FISH_STOCK_UPDATE => [
                'id' => 'menu_fish_stock',
                'title' => '📦 Update Stock',
                'description' => 'Update catch availability',
            ],
            self::FISH_SELLER_MENU => [
                'id' => 'menu_fish_dashboard',
                'title' => '🐟 Seller Dashboard',
                'description' => 'View your fish seller dashboard',
            ],
            default => null,
        };
    }

    /**
     * Get button configuration for main menu.
     *
     * @return array{id: string, title: string}|null
     */
    public function menuButton(): ?array
    {
        $item = $this->menuItem();
        if (!$item) {
            return null;
        }

        return [
            'id' => $item['id'],
            'title' => substr($item['title'], 0, 20), // WhatsApp button title limit
        ];
    }

    /**
     * Get timeout in minutes for this flow.
     *
     * @srs-ref NFR-U-01 Registration within 5 interactions
     * @srs-ref FR-PRD-06 Request expiration time (default 2 hours)
     */
    public function timeout(): int
    {
        return match ($this) {
            self::REGISTRATION => 60,    // 1 hour
            self::PRODUCT_SEARCH => 120, // 2 hours (FR-PRD-06)
            self::PRODUCT_RESPOND => 120,
            self::AGREEMENT_CREATE => 30,
            self::AGREEMENT_CONFIRM => 30,
            self::OFFERS_UPLOAD => 30,
            self::SETTINGS => 15,
            // Fish flows
            self::FISH_SELLER_REGISTER => 30,
            self::FISH_POST_CATCH => 15,
            self::FISH_STOCK_UPDATE => 10,
            self::FISH_SUBSCRIBE => 15,
            self::FISH_BROWSE => 30,
            self::FISH_MANAGE_SUBSCRIPTION => 15,
            self::FISH_SELLER_MENU => 15,
            default => config('nearbuy.session.timeout_minutes', 30),
        };
    }

    /**
     * Get estimated steps count for this flow.
     *
     * @srs-ref NFR-U-01 Registration flow shall complete within 5 interactions
     */
    public function estimatedSteps(): int
    {
        return match ($this) {
            self::REGISTRATION => 5,     // NFR-U-01: within 5 interactions
            self::OFFERS_BROWSE => 3,
            self::OFFERS_UPLOAD => 4,
            self::PRODUCT_SEARCH => 4,
            self::PRODUCT_RESPOND => 4,
            self::AGREEMENT_CREATE => 8,
            self::AGREEMENT_CONFIRM => 2,
            self::AGREEMENT_LIST => 2,
            self::SETTINGS => 2,
            // Fish flows
            self::FISH_SELLER_REGISTER => 5,
            self::FISH_POST_CATCH => 6,
            self::FISH_STOCK_UPDATE => 2,
            self::FISH_SUBSCRIBE => 5,
            self::FISH_BROWSE => 3,
            self::FISH_MANAGE_SUBSCRIPTION => 3,
            self::FISH_SELLER_MENU => 1,
            default => 1,
        };
    }

    /**
     * Get the description of this flow.
     */
    public function description(): string
    {
        return match ($this) {
            self::REGISTRATION => 'Register as a customer or shop owner',
            self::MAIN_MENU => 'Access all NearBuy features',
            self::OFFERS_BROWSE => 'Browse daily offers from nearby shops',
            self::OFFERS_UPLOAD => 'Upload promotional offers for your shop',
            self::OFFERS_MANAGE => 'View and manage your active offers',
            self::PRODUCT_SEARCH => 'Search for products from nearby shops',
            self::PRODUCT_RESPOND => 'Respond to customer product requests',
            self::AGREEMENT_CREATE => 'Create a digital payment agreement',
            self::AGREEMENT_CONFIRM => 'Confirm a pending agreement',
            self::AGREEMENT_LIST => 'View and manage your agreements',
            self::SETTINGS => 'Manage your preferences and profile',
            // Fish flows
            self::FISH_SELLER_REGISTER => 'Register as a fish seller',
            self::FISH_POST_CATCH => 'Post your fresh fish catch',
            self::FISH_STOCK_UPDATE => 'Update fish stock availability',
            self::FISH_SUBSCRIBE => 'Subscribe to fresh fish alerts',
            self::FISH_BROWSE => 'Browse fresh fish nearby',
            self::FISH_MANAGE_SUBSCRIPTION => 'Manage your fish alert preferences',
            self::FISH_SELLER_MENU => 'Fish seller dashboard and options',
        };
    }

    /**
     * Check if this flow can be started from main menu.
     */
    public function isMenuAccessible(): bool
    {
        return !in_array($this, [
            self::REGISTRATION,
            self::MAIN_MENU,
            self::AGREEMENT_CONFIRM, // Triggered by incoming confirmation
            self::PRODUCT_RESPOND,   // Triggered by incoming request
        ]);
    }

    /**
     * Check if this flow handles notifications/incoming triggers.
     */
    public function isTriggeredFlow(): bool
    {
        return in_array($this, [
            self::AGREEMENT_CONFIRM,
            self::PRODUCT_RESPOND,
        ]);
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get flows available for a user type.
     *
     * @param string $userType 'customer', 'shop', or 'fish_seller'
     * @return array<self>
     */
    public static function forUserType(string $userType): array
    {
        return array_filter(self::cases(), function (self $flow) use ($userType) {
            if ($flow->isUniversal()) {
                return true;
            }
            if ($userType === 'shop' && $flow->isShopOnly()) {
                return true;
            }
            if ($userType === 'customer' && $flow->isCustomerOnly()) {
                return true;
            }
            if ($userType === 'fish_seller' && $flow->isFishSellerOnly()) {
                return true;
            }
            return false;
        });
    }

    /**
     * Get menu items for a user type.
     *
     * @param string $userType 'customer', 'shop', or 'fish_seller'
     * @return array<array{id: string, title: string, description: string}>
     */
    public static function menuItemsForUserType(string $userType): array
    {
        $flows = self::forUserType($userType);

        return array_values(array_filter(
            array_map(fn(self $flow) => $flow->menuItem(), $flows)
        ));
    }

    /**
     * Get customer menu items.
     *
     * @return array<array{id: string, title: string, description: string}>
     */
    public static function customerMenuItems(): array
    {
        return [
            self::OFFERS_BROWSE->menuItem(),
            self::PRODUCT_SEARCH->menuItem(),
            self::FISH_BROWSE->menuItem(),
            self::FISH_SUBSCRIBE->menuItem(),
            self::AGREEMENT_CREATE->menuItem(),
            self::AGREEMENT_LIST->menuItem(),
            self::SETTINGS->menuItem(),
        ];
    }

    /**
     * Get shop owner menu items.
     *
     * @return array<array{id: string, title: string, description: string}>
     */
    public static function shopMenuItems(): array
    {
        return [
            self::OFFERS_UPLOAD->menuItem(),
            self::OFFERS_MANAGE->menuItem(),
            self::OFFERS_BROWSE->menuItem(),
            self::PRODUCT_SEARCH->menuItem(),
            self::FISH_BROWSE->menuItem(),
            self::AGREEMENT_CREATE->menuItem(),
            self::AGREEMENT_LIST->menuItem(),
            self::SETTINGS->menuItem(),
        ];
    }

    /**
     * Get fish seller menu items.
     *
     * @return array<array{id: string, title: string, description: string}>
     */
    public static function fishSellerMenuItems(): array
    {
        return [
            self::FISH_POST_CATCH->menuItem(),
            self::FISH_STOCK_UPDATE->menuItem(),
            self::FISH_SELLER_MENU->menuItem(),
            self::FISH_BROWSE->menuItem(),
            self::AGREEMENT_CREATE->menuItem(),
            self::AGREEMENT_LIST->menuItem(),
            self::SETTINGS->menuItem(),
        ];
    }

    /**
     * Find flow by menu item ID.
     */
    public static function fromMenuId(string $menuId): ?self
    {
        foreach (self::cases() as $flow) {
            $item = $flow->menuItem();
            if ($item && $item['id'] === $menuId) {
                return $flow;
            }
        }
        return null;
    }

    /**
     * Get all agreement-related flows.
     */
    public static function agreementFlows(): array
    {
        return [
            self::AGREEMENT_CREATE,
            self::AGREEMENT_CONFIRM,
            self::AGREEMENT_LIST,
        ];
    }

    /**
     * Get all offer-related flows.
     */
    public static function offerFlows(): array
    {
        return [
            self::OFFERS_BROWSE,
            self::OFFERS_UPLOAD,
            self::OFFERS_MANAGE,
        ];
    }

    /**
     * Get all product-related flows.
     */
    public static function productFlows(): array
    {
        return [
            self::PRODUCT_SEARCH,
            self::PRODUCT_RESPOND,
        ];
    }

    /**
     * Get all fish-related flows.
     *
     * @srs-ref Pacha Meen Module
     */
    public static function fishFlows(): array
    {
        return [
            self::FISH_SELLER_REGISTER,
            self::FISH_POST_CATCH,
            self::FISH_STOCK_UPDATE,
            self::FISH_SUBSCRIBE,
            self::FISH_BROWSE,
            self::FISH_MANAGE_SUBSCRIPTION,
            self::FISH_SELLER_MENU,
        ];
    }
}
