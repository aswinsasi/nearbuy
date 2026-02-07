<?php

namespace App\Enums;

/**
 * All conversation flow types in NearBuy.
 *
 * COMPLETE LIST supporting:
 * - Registration (customer + shop)
 * - Offers (upload, browse, manage)
 * - Product Search (customer search, shop response)
 * - Agreements (create, confirm, list)
 * - Fish/Pacha Meen (seller reg, catch post, subscription, browse, manage, stock update, seller menu)
 * - Jobs/Njaanum Panikkar (worker reg, job post, apply, selection, execution, worker menu, poster menu)
 * - Flash Mob Deals (create, claim, manage)
 * - Settings
 *
 * @srs-ref Section 7.1 High-Level Architecture - Flow Controllers
 * @srs-ref Section 2 - Pacha Meen Module
 * @srs-ref Section 3 - Njaanum Panikkar Module
 * @srs-ref Section 4 - Flash Mob Deals Module
 */
enum FlowType: string
{
    /*
    |--------------------------------------------------------------------------
    | Core Flows
    |--------------------------------------------------------------------------
    */

    case MAIN_MENU = 'main_menu';
    case REGISTRATION = 'registration';
    case SETTINGS = 'settings';

    /*
    |--------------------------------------------------------------------------
    | Offers Flows
    |--------------------------------------------------------------------------
    */

    case OFFERS_BROWSE = 'offers_browse';
    case OFFERS_UPLOAD = 'offers_upload';
    case OFFERS_MANAGE = 'offers_manage';

    /*
    |--------------------------------------------------------------------------
    | Product Search Flows
    |--------------------------------------------------------------------------
    */

    case PRODUCT_SEARCH = 'product_search';
    case PRODUCT_RESPOND = 'product_respond';

    /*
    |--------------------------------------------------------------------------
    | Agreement Flows
    |--------------------------------------------------------------------------
    */

    case AGREEMENT_CREATE = 'agreement_create';
    case AGREEMENT_CONFIRM = 'agreement_confirm';
    case AGREEMENT_LIST = 'agreement_list';

    /*
    |--------------------------------------------------------------------------
    | Pacha Meen (Fish Alert) Flows
    |--------------------------------------------------------------------------
    | @srs-ref Section 2 - Pacha Meen Module
    */

    case FISH_SELLER_REGISTER = 'fish_seller_register';
    case FISH_POST_CATCH = 'fish_post_catch';
    case FISH_SUBSCRIBE = 'fish_subscribe';
    case FISH_BROWSE = 'fish_browse';
    case FISH_MANAGE_SUBSCRIPTION = 'fish_manage_subscription';
    case FISH_STOCK_UPDATE = 'fish_stock_update';
    case FISH_SELLER_MENU = 'fish_seller_menu';

    /*
    |--------------------------------------------------------------------------
    | Njaanum Panikkar (Jobs Marketplace) Flows
    |--------------------------------------------------------------------------
    | @srs-ref Section 3 - Njaanum Panikkar Module
    */

    case JOB_WORKER_REGISTER = 'job_worker_register';
    case JOB_POST = 'job_post';
    case JOB_BROWSE = 'job_browse';
    case JOB_APPLICATION = 'job_application';
    case JOB_SELECTION = 'job_selection';
    case JOB_EXECUTION = 'job_execution';
    case JOB_WORKER_MENU = 'job_worker_menu';
    case JOB_POSTER_MENU = 'job_poster_menu';
    case JOB_APPLICATIONS = 'job_applications';

    /*
    |--------------------------------------------------------------------------
    | Flash Mob Deals Flows
    |--------------------------------------------------------------------------
    | @srs-ref Section 4 - Flash Mob Deals Module
    */

    case FLASH_DEAL_CREATE = 'flash_deal_create';
    case FLASH_DEAL_CLAIM = 'flash_deal_claim';
    case FLASH_DEAL_MANAGE = 'flash_deal_manage';

    /*
    |--------------------------------------------------------------------------
    | Display Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get display label.
     */
    public function label(): string
    {
        return match ($this) {
            // Core
            self::MAIN_MENU => 'Main Menu',
            self::REGISTRATION => 'Registration',
            self::SETTINGS => 'Settings',
            // Offers
            self::OFFERS_BROWSE => 'Browse Offers',
            self::OFFERS_UPLOAD => 'Upload Offer',
            self::OFFERS_MANAGE => 'Manage Offers',
            // Products
            self::PRODUCT_SEARCH => 'Product Search',
            self::PRODUCT_RESPOND => 'Respond to Request',
            // Agreements
            self::AGREEMENT_CREATE => 'Create Agreement',
            self::AGREEMENT_CONFIRM => 'Confirm Agreement',
            self::AGREEMENT_LIST => 'My Agreements',
            // Fish
            self::FISH_SELLER_REGISTER => 'Fish Seller Registration',
            self::FISH_POST_CATCH => 'Post Fish Catch',
            self::FISH_SUBSCRIBE => 'Fish Alert Subscription',
            self::FISH_BROWSE => 'Browse Fresh Fish',
            self::FISH_MANAGE_SUBSCRIPTION => 'Manage Fish Alerts',
            self::FISH_STOCK_UPDATE => 'Update Fish Stock',
            self::FISH_SELLER_MENU => 'Fish Seller Dashboard',
            // Jobs
            self::JOB_WORKER_REGISTER => 'Worker Registration',
            self::JOB_POST => 'Post Task',
            self::JOB_BROWSE => 'Browse Tasks',
            self::JOB_APPLICATION => 'Apply for Job',
            self::JOB_SELECTION => 'Select Worker',
            self::JOB_EXECUTION => 'Task Execution',
            self::JOB_WORKER_MENU => 'Worker Dashboard',
            self::JOB_POSTER_MENU => 'My Posted Tasks',
            self::JOB_APPLICATIONS => 'View Applications',
            // Flash Deals
            self::FLASH_DEAL_CREATE => 'Create Flash Deal',
            self::FLASH_DEAL_CLAIM => 'Claim Flash Deal',
            self::FLASH_DEAL_MANAGE => 'Manage Flash Deals',
        };
    }

    /**
     * Get emoji icon.
     */
    public function icon(): string
    {
        return match ($this) {
            // Core
            self::MAIN_MENU => '🏠',
            self::REGISTRATION => '📝',
            self::SETTINGS => '⚙️',
            // Offers
            self::OFFERS_BROWSE => '🛍️',
            self::OFFERS_UPLOAD => '📤',
            self::OFFERS_MANAGE => '📊',
            // Products
            self::PRODUCT_SEARCH => '🔍',
            self::PRODUCT_RESPOND => '📦',
            // Agreements
            self::AGREEMENT_CREATE => '📝',
            self::AGREEMENT_CONFIRM => '✅',
            self::AGREEMENT_LIST => '📋',
            // Fish
            self::FISH_SELLER_REGISTER => '🐟',
            self::FISH_POST_CATCH => '🎣',
            self::FISH_SUBSCRIBE => '🔔',
            self::FISH_BROWSE => '🐟',
            self::FISH_MANAGE_SUBSCRIPTION => '⚙️',
            self::FISH_STOCK_UPDATE => '📦',
            self::FISH_SELLER_MENU => '🐟',
            // Jobs
            self::JOB_WORKER_REGISTER => '👷',
            self::JOB_POST => '📋',
            self::JOB_BROWSE => '🔍',
            self::JOB_APPLICATION => '✋',
            self::JOB_SELECTION => '👆',
            self::JOB_EXECUTION => '✅',
            self::JOB_WORKER_MENU => '👷',
            self::JOB_POSTER_MENU => '📋',
            self::JOB_APPLICATIONS => '📝',
            // Flash Deals
            self::FLASH_DEAL_CREATE => '⚡',
            self::FLASH_DEAL_CLAIM => '🎯',
            self::FLASH_DEAL_MANAGE => '📊',
        };
    }

    /**
     * Get icon + label.
     */
    public function labeledIcon(): string
    {
        return $this->icon() . ' ' . $this->label();
    }

    /*
    |--------------------------------------------------------------------------
    | Handler Mapping
    |--------------------------------------------------------------------------
    */

    /**
     * Get the handler class name.
     */
    public function handlerClass(): string
    {
        $baseNamespace = 'App\\Services\\Flow\\Handlers\\';

        return match ($this) {
            // Core
            self::MAIN_MENU => $baseNamespace . 'MainMenuHandler',
            self::REGISTRATION => $baseNamespace . 'RegistrationFlowHandler',
            self::SETTINGS => $baseNamespace . 'SettingsFlowHandler',
            // Offers
            self::OFFERS_BROWSE => $baseNamespace . 'OfferBrowseFlowHandler',
            self::OFFERS_UPLOAD => $baseNamespace . 'OfferUploadFlowHandler',
            self::OFFERS_MANAGE => $baseNamespace . 'OfferManageFlowHandler',
            // Products
            self::PRODUCT_SEARCH => $baseNamespace . 'ProductSearchFlowHandler',
            self::PRODUCT_RESPOND => $baseNamespace . 'ProductResponseFlowHandler',
            // Agreements
            self::AGREEMENT_CREATE => $baseNamespace . 'AgreementCreateFlowHandler',
            self::AGREEMENT_CONFIRM => $baseNamespace . 'AgreementConfirmFlowHandler',
            self::AGREEMENT_LIST => $baseNamespace . 'AgreementListFlowHandler',
            // Fish - in Fish subdirectory
            self::FISH_SELLER_REGISTER => $baseNamespace . 'Fish\\FishSellerRegistrationFlowHandler',
            self::FISH_POST_CATCH => $baseNamespace . 'Fish\\FishCatchPostFlowHandler',
            self::FISH_SUBSCRIBE => $baseNamespace . 'Fish\\FishSubscriptionFlowHandler',
            self::FISH_BROWSE => $baseNamespace . 'Fish\\FishBrowseFlowHandler',
            self::FISH_MANAGE_SUBSCRIPTION => $baseNamespace . 'Fish\\FishManageSubscriptionHandler',
            self::FISH_STOCK_UPDATE => $baseNamespace . 'Fish\\FishStockUpdateFlowHandler',
            self::FISH_SELLER_MENU => $baseNamespace . 'Fish\\FishSellerMenuHandler',
            // Jobs - in Jobs subdirectory
            self::JOB_WORKER_REGISTER => $baseNamespace . 'Jobs\\JobWorkerRegistrationFlowHandler',
            self::JOB_POST => $baseNamespace . 'Jobs\\JobPostFlowHandler',
            self::JOB_BROWSE => $baseNamespace . 'Jobs\\JobBrowseFlowHandler',
            self::JOB_APPLICATION => $baseNamespace . 'Jobs\\JobApplicationFlowHandler',
            self::JOB_SELECTION => $baseNamespace . 'Jobs\\JobSelectionFlowHandler',
            self::JOB_EXECUTION => $baseNamespace . 'Jobs\\JobExecutionFlowHandler',
            self::JOB_WORKER_MENU => $baseNamespace . 'Jobs\\JobWorkerMenuFlowHandler',
            self::JOB_POSTER_MENU => $baseNamespace . 'Jobs\\JobPosterMenuFlowHandler',
            self::JOB_APPLICATIONS => $baseNamespace . 'Jobs\\JobApplicationsFlowHandler',
            // Flash Deals - in FlashDeals subdirectory
            self::FLASH_DEAL_CREATE => $baseNamespace . 'FlashDeals\\FlashDealCreateFlowHandler',
            self::FLASH_DEAL_CLAIM => $baseNamespace . 'FlashDeals\\FlashDealClaimFlowHandler',
            self::FLASH_DEAL_MANAGE => $baseNamespace . 'FlashDeals\\FlashDealManageFlowHandler',
        };
    }

    /**
     * Get initial step for this flow.
     */
    public function initialStep(): string
    {
        return match ($this) {
            // Core
            self::MAIN_MENU => 'show_menu',
            self::REGISTRATION => 'ask_type',
            self::SETTINGS => 'show_settings',
            // Offers
            self::OFFERS_BROWSE => 'select_category',
            self::OFFERS_UPLOAD => 'upload_image',
            self::OFFERS_MANAGE => 'show_offers',
            // Products
            self::PRODUCT_SEARCH => 'ask_category',
            self::PRODUCT_RESPOND => 'view_request',
            // Agreements
            self::AGREEMENT_CREATE => 'ask_direction',
            self::AGREEMENT_CONFIRM => 'show_pending',
            self::AGREEMENT_LIST => 'show_list',
            // Fish
            self::FISH_SELLER_REGISTER => 'select_type',
            self::FISH_POST_CATCH => 'select_fish',
            self::FISH_SUBSCRIBE => 'select_location',
            self::FISH_BROWSE => 'show_nearby',
            self::FISH_MANAGE_SUBSCRIPTION => 'show_subscription',
            self::FISH_STOCK_UPDATE => 'select_catch',
            self::FISH_SELLER_MENU => 'show_menu',
            // Jobs
            self::JOB_WORKER_REGISTER => 'ask_name',
            self::JOB_POST => 'select_category',
            self::JOB_BROWSE => 'show_nearby',
            self::JOB_APPLICATION => 'view_details',
            self::JOB_SELECTION => 'view_applications',
            self::JOB_EXECUTION => 'arrival_photo',
            self::JOB_WORKER_MENU => 'show_menu',
            self::JOB_POSTER_MENU => 'show_tasks',
            self::JOB_APPLICATIONS => 'show_applications',
            // Flash Deals
            self::FLASH_DEAL_CREATE => 'ask_title',
            self::FLASH_DEAL_CLAIM => 'show_deal',
            self::FLASH_DEAL_MANAGE => 'show_deals',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Authorization Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if this flow requires authentication.
     */
    public function requiresAuth(): bool
    {
        return match ($this) {
            self::REGISTRATION => false,
            self::MAIN_MENU => false,
            self::FISH_BROWSE => false,
            self::JOB_BROWSE => false,
            self::FLASH_DEAL_CLAIM => false, // Can browse without auth
            default => true,
        };
    }

    /**
     * Check if this flow is shop-only.
     */
    public function isShopOnly(): bool
    {
        return in_array($this, [
            self::OFFERS_UPLOAD,
            self::OFFERS_MANAGE,
            self::PRODUCT_RESPOND,
            self::FLASH_DEAL_CREATE,
            self::FLASH_DEAL_MANAGE,
        ]);
    }

    /**
     * Check if this flow is fish-seller-only.
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
     * Check if this flow is job-worker-only.
     */
    public function isJobWorkerOnly(): bool
    {
        return in_array($this, [
            self::JOB_WORKER_MENU,
            self::JOB_EXECUTION,
        ]);
    }

    /**
     * Check if this flow is customer-only.
     */
    public function isCustomerOnly(): bool
    {
        return in_array($this, [
            self::OFFERS_BROWSE,
            self::PRODUCT_SEARCH,
        ]);
    }

    /**
     * Check if this flow is universal (available to all users).
     */
    public function isUniversal(): bool
    {
        return in_array($this, [
            self::REGISTRATION,
            self::MAIN_MENU,
            self::SETTINGS,
            self::AGREEMENT_CREATE,
            self::AGREEMENT_CONFIRM,
            self::AGREEMENT_LIST,
            self::FISH_BROWSE,
            self::FISH_SUBSCRIBE,
            self::FISH_SELLER_REGISTER,
            self::FISH_MANAGE_SUBSCRIPTION,
            self::JOB_WORKER_REGISTER,
            self::JOB_BROWSE,
            self::JOB_POST,
            self::JOB_POSTER_MENU,
            self::JOB_APPLICATION,
            self::FLASH_DEAL_CLAIM,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Flow Category Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if this is a fish-related flow.
     */
    public function isFishFlow(): bool
    {
        return in_array($this, self::fishFlows());
    }

    /**
     * Check if this is a job-related flow.
     */
    public function isJobFlow(): bool
    {
        return in_array($this, self::jobFlows());
    }

    /**
     * Check if this is a flash-deal-related flow.
     */
    public function isFlashDealFlow(): bool
    {
        return in_array($this, self::flashDealFlows());
    }

    /**
     * Check if this is an agreement-related flow.
     */
    public function isAgreementFlow(): bool
    {
        return in_array($this, self::agreementFlows());
    }

    /**
     * Check if this is an offer-related flow.
     */
    public function isOfferFlow(): bool
    {
        return in_array($this, self::offerFlows());
    }

    /**
     * Check if this is a product-related flow.
     */
    public function isProductFlow(): bool
    {
        return in_array($this, self::productFlows());
    }

    /*
    |--------------------------------------------------------------------------
    | Timeout & Estimation
    |--------------------------------------------------------------------------
    */

    /**
     * Get timeout in minutes for this flow.
     */
    public function timeout(): int
    {
        return match ($this) {
            self::REGISTRATION => 60,
            self::PRODUCT_SEARCH, self::PRODUCT_RESPOND => 120,
            self::AGREEMENT_CREATE, self::AGREEMENT_CONFIRM => 30,
            self::OFFERS_UPLOAD => 30,
            self::SETTINGS => 15,
            self::FISH_SELLER_REGISTER => 30,
            self::FISH_POST_CATCH => 15,
            self::FISH_STOCK_UPDATE => 10,
            self::FISH_SUBSCRIBE, self::FISH_MANAGE_SUBSCRIPTION => 15,
            self::FISH_BROWSE, self::FISH_SELLER_MENU => 30,
            self::JOB_WORKER_REGISTER => 30,
            self::JOB_POST => 30,
            self::JOB_BROWSE, self::JOB_WORKER_MENU, self::JOB_POSTER_MENU => 30,
            self::JOB_APPLICATION, self::JOB_SELECTION => 30,
            self::JOB_APPLICATIONS => 30,
            self::JOB_EXECUTION => 60,
            self::FLASH_DEAL_CREATE => 30,
            self::FLASH_DEAL_CLAIM => 15,
            self::FLASH_DEAL_MANAGE => 30,
            default => config('nearbuy.session.timeout_minutes', 30),
        };
    }

    /**
     * Get estimated steps count for this flow.
     */
    public function estimatedSteps(): int
    {
        return match ($this) {
            self::REGISTRATION => 5,
            self::OFFERS_BROWSE => 3,
            self::OFFERS_UPLOAD => 4,
            self::OFFERS_MANAGE => 2,
            self::PRODUCT_SEARCH => 4,
            self::PRODUCT_RESPOND => 4,
            self::AGREEMENT_CREATE => 8,
            self::AGREEMENT_CONFIRM => 2,
            self::AGREEMENT_LIST => 2,
            self::FISH_SELLER_REGISTER => 5,
            self::FISH_POST_CATCH => 6,
            self::FISH_SUBSCRIBE => 5,
            self::FISH_BROWSE => 3,
            self::FISH_MANAGE_SUBSCRIPTION => 3,
            self::FISH_STOCK_UPDATE => 2,
            self::FISH_SELLER_MENU => 1,
            self::JOB_WORKER_REGISTER => 7,
            self::JOB_POST => 12,
            self::JOB_BROWSE => 3,
            self::JOB_APPLICATION => 5,
            self::JOB_SELECTION => 4,
            self::JOB_EXECUTION => 5,
            self::JOB_WORKER_MENU => 4,
            self::JOB_POSTER_MENU => 4,
            self::JOB_APPLICATIONS => 3,
            self::FLASH_DEAL_CREATE => 8,
            self::FLASH_DEAL_CLAIM => 3,
            self::FLASH_DEAL_MANAGE => 3,
            default => 1,
        };
    }

    /**
     * Get description of this flow.
     */
    public function description(): string
    {
        return match ($this) {
            self::MAIN_MENU => 'Access all NearBuy features',
            self::REGISTRATION => 'Register as customer or shop owner',
            self::SETTINGS => 'Manage preferences and profile',
            self::OFFERS_BROWSE => 'Browse daily offers from nearby shops',
            self::OFFERS_UPLOAD => 'Upload promotional offers for your shop',
            self::OFFERS_MANAGE => 'View and manage your active offers',
            self::PRODUCT_SEARCH => 'Search for products from nearby shops',
            self::PRODUCT_RESPOND => 'Respond to customer product requests',
            self::AGREEMENT_CREATE => 'Create a digital payment agreement',
            self::AGREEMENT_CONFIRM => 'Confirm a pending agreement',
            self::AGREEMENT_LIST => 'View and manage your agreements',
            self::FISH_SELLER_REGISTER => 'Register as a fish seller',
            self::FISH_POST_CATCH => 'Post your fresh fish catch',
            self::FISH_SUBSCRIBE => 'Subscribe to fresh fish alerts',
            self::FISH_BROWSE => 'Browse fresh fish nearby',
            self::FISH_MANAGE_SUBSCRIPTION => 'Manage your fish alert preferences',
            self::FISH_STOCK_UPDATE => 'Update fish stock availability',
            self::FISH_SELLER_MENU => 'Fish seller dashboard and options',
            self::JOB_WORKER_REGISTER => 'Register to become a job worker',
            self::JOB_POST => 'Post a task for workers',
            self::JOB_BROWSE => 'Browse available tasks nearby',
            self::JOB_APPLICATION => 'Apply for a job as a worker',
            self::JOB_SELECTION => 'Select a worker for your task',
            self::JOB_EXECUTION => 'Track and complete assigned tasks',
            self::JOB_WORKER_MENU => 'View and edit your worker profile',
            self::JOB_POSTER_MENU => 'View and manage your posted tasks',
            self::JOB_APPLICATIONS => 'View worker applications',
            self::FLASH_DEAL_CREATE => 'Create a time-limited flash deal',
            self::FLASH_DEAL_CLAIM => 'Claim a flash deal before it expires',
            self::FLASH_DEAL_MANAGE => 'Manage your flash deals',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Menu Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * Get menu item configuration.
     *
     * @return array{id: string, title: string, description: string}|null
     */
    public function menuItem(): ?array
    {
        return match ($this) {
            self::OFFERS_BROWSE => [
                'id' => 'browse_offers',
                'title' => '🛍️ Browse Offers',
                'description' => 'See offers from nearby shops',
            ],
            self::PRODUCT_SEARCH => [
                'id' => 'search_product',
                'title' => '🔍 Find Product',
                'description' => 'Search for products nearby',
            ],
            self::AGREEMENT_CREATE => [
                'id' => 'create_agreement',
                'title' => '📝 New Agreement',
                'description' => 'Create a payment agreement',
            ],
            self::AGREEMENT_LIST => [
                'id' => 'my_agreements',
                'title' => '📋 My Agreements',
                'description' => 'View your agreements',
            ],
            self::SETTINGS => [
                'id' => 'settings',
                'title' => '⚙️ Settings',
                'description' => 'Manage your preferences',
            ],
            self::OFFERS_UPLOAD => [
                'id' => 'upload_offer',
                'title' => '📤 Upload Offer',
                'description' => 'Post a new offer',
            ],
            self::OFFERS_MANAGE => [
                'id' => 'my_offers',
                'title' => '📊 My Offers',
                'description' => 'Manage your offers',
            ],
            self::FISH_BROWSE => [
                'id' => 'fish_browse',
                'title' => '🐟 Fresh Fish',
                'description' => 'Browse fresh fish nearby',
            ],
            self::FISH_SUBSCRIBE => [
                'id' => 'fish_alerts',
                'title' => '🔔 Fish Alerts',
                'description' => 'Get notified when fish arrives',
            ],
            self::FISH_SELLER_REGISTER => [
                'id' => 'fish_seller_register',
                'title' => '🐟 Become Fish Seller',
                'description' => 'Register as a fish seller',
            ],
            self::FISH_POST_CATCH => [
                'id' => 'fish_post_catch',
                'title' => '🎣 Post Catch',
                'description' => 'Post your fresh catch',
            ],
            self::FISH_SELLER_MENU => [
                'id' => 'fish_seller_menu',
                'title' => '🐟 Seller Dashboard',
                'description' => 'Fish seller options',
            ],
            self::JOB_BROWSE => [
                'id' => 'job_browse',
                'title' => '🔍 Browse Tasks',
                'description' => 'Find tasks near you',
            ],
            self::JOB_WORKER_REGISTER => [
                'id' => 'job_worker_register',
                'title' => '👷 Become Worker',
                'description' => 'Register to do tasks',
            ],
            self::JOB_POST => [
                'id' => 'job_post',
                'title' => '📋 Post Task',
                'description' => 'Post a task for workers',
            ],
            self::JOB_WORKER_MENU => [
                'id' => 'job_worker_menu',
                'title' => '👷 Worker Dashboard',
                'description' => 'Your worker profile & jobs',
            ],
            self::JOB_POSTER_MENU => [
                'id' => 'job_poster_menu',
                'title' => '📋 My Posted Tasks',
                'description' => 'View your posted tasks',
            ],
            self::FLASH_DEAL_CREATE => [
                'id' => 'flash_deal_create',
                'title' => '⚡ Create Flash Deal',
                'description' => 'Create time-limited deal',
            ],
            self::FLASH_DEAL_MANAGE => [
                'id' => 'flash_deal_manage',
                'title' => '📊 My Flash Deals',
                'description' => 'Manage your flash deals',
            ],
            default => null,
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
            self::AGREEMENT_CONFIRM,
            self::PRODUCT_RESPOND,
            self::JOB_EXECUTION,
            self::JOB_SELECTION,
            self::JOB_APPLICATION,
        ]);
    }

    /**
     * Check if this flow is triggered by external event.
     */
    public function isTriggeredFlow(): bool
    {
        return in_array($this, [
            self::AGREEMENT_CONFIRM,
            self::PRODUCT_RESPOND,
            self::JOB_EXECUTION,
            self::JOB_SELECTION,
            self::JOB_APPLICATION,
            self::JOB_APPLICATIONS,
            self::FLASH_DEAL_CLAIM,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Static Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all fish-related flows.
     */
    public static function fishFlows(): array
    {
        return [
            self::FISH_SELLER_REGISTER,
            self::FISH_POST_CATCH,
            self::FISH_SUBSCRIBE,
            self::FISH_BROWSE,
            self::FISH_MANAGE_SUBSCRIPTION,
            self::FISH_STOCK_UPDATE,
            self::FISH_SELLER_MENU,
        ];
    }

    /**
     * Get all job-related flows.
     */
    public static function jobFlows(): array
    {
        return [
            self::JOB_WORKER_REGISTER,
            self::JOB_POST,
            self::JOB_BROWSE,
            self::JOB_APPLICATION,
            self::JOB_SELECTION,
            self::JOB_EXECUTION,
            self::JOB_WORKER_MENU,
            self::JOB_POSTER_MENU,
            self::JOB_APPLICATIONS,
        ];
    }

    /**
     * Get all flash-deal-related flows.
     */
    public static function flashDealFlows(): array
    {
        return [
            self::FLASH_DEAL_CREATE,
            self::FLASH_DEAL_CLAIM,
            self::FLASH_DEAL_MANAGE,
        ];
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
     * Get flows for user type.
     */
    public static function forUserType(string $userType): array
    {
        return array_filter(self::cases(), function (self $flow) use ($userType) {
            if ($flow->isUniversal()) return true;
            if ($userType === 'shop' && $flow->isShopOnly()) return true;
            if ($userType === 'customer' && $flow->isCustomerOnly()) return true;
            if ($userType === 'fish_seller' && $flow->isFishSellerOnly()) return true;
            if ($userType === 'job_worker' && $flow->isJobWorkerOnly()) return true;
            return false;
        });
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
     * Get customer menu items.
     */
    public static function customerMenuItems(): array
    {
        return array_filter([
            self::OFFERS_BROWSE->menuItem(),
            self::PRODUCT_SEARCH->menuItem(),
            self::FISH_BROWSE->menuItem(),
            self::FISH_SUBSCRIBE->menuItem(),
            self::JOB_BROWSE->menuItem(),
            self::JOB_POST->menuItem(),
            self::AGREEMENT_CREATE->menuItem(),
            self::AGREEMENT_LIST->menuItem(),
            self::SETTINGS->menuItem(),
        ]);
    }

    /**
     * Get shop owner menu items.
     */
    public static function shopMenuItems(): array
    {
        return array_filter([
            self::OFFERS_UPLOAD->menuItem(),
            self::OFFERS_MANAGE->menuItem(),
            self::FLASH_DEAL_CREATE->menuItem(),
            self::FLASH_DEAL_MANAGE->menuItem(),
            self::OFFERS_BROWSE->menuItem(),
            self::PRODUCT_SEARCH->menuItem(),
            self::AGREEMENT_CREATE->menuItem(),
            self::AGREEMENT_LIST->menuItem(),
            self::SETTINGS->menuItem(),
        ]);
    }
}