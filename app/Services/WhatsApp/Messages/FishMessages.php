<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use App\Models\FishCatch;
use App\Models\FishSeller;
use App\Models\FishSubscription;
use App\Models\FishType;
use App\Models\FishAlert;
use App\Enums\FishQuantityRange;
use App\Enums\FishSellerType;
use App\Enums\FishAlertFrequency;
use Illuminate\Support\Collection;

/**
 * WhatsApp message templates for Pacha Meen (Fish Alert) module.
 *
 * Contains all message formats for:
 * - Fish seller registration
 * - Catch posting flow
 * - Customer subscription flow
 * - Alert messages
 * - Browse/search results
 *
 * ENHANCED: All response messages now include main menu button
 * @srs-ref NFR-U-04: Main menu shall be accessible from any flow state
 * @srs-ref Pacha Meen Module - Section 2.5 Message Formats
 */
class FishMessages
{
    /*
    |--------------------------------------------------------------------------
    | Fish Seller Registration Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Welcome message for fish seller registration.
     */
    public static function sellerRegistrationWelcome(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🐟 Pacha Meen',
            'body' => "🐟 *Welcome to Pacha Meen!*\n\n" .
                "Register as a fish seller to:\n" .
                "• Post your fresh catch\n" .
                "• Reach customers instantly\n" .
                "• Manage your sales\n\n" .
                "Let's get you started! 🎣",
            'buttons' => [
                ['id' => 'continue_registration', 'title' => '✅ Continue'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Ask for seller type.
     */
    public static function askSellerType(): array
    {
        return [
            'type' => 'list',
            'header' => '🐟 Seller Type',
            'body' => "What type of fish seller are you?\n\nSelect the option that best describes your business:",
            'button' => 'Select Type',
            'sections' => [
                [
                    'title' => 'Seller Types',
                    'rows' => array_merge(
                        FishSellerType::toListItems(),
                        [['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu']]
                    ),
                ],
            ],
        ];
    }

    /**
     * Ask for business name.
     */
    public static function askBusinessName(FishSellerType $sellerType): array
    {
        $example = match ($sellerType) {
            FishSellerType::FISHERMAN => 'e.g., "Raghavan Fresh Catch"',
            FishSellerType::HARBOUR_VENDOR => 'e.g., "Kochi Harbour Fish Stall"',
            FishSellerType::FISH_SHOP => 'e.g., "Malabar Sea Foods"',
            FishSellerType::WHOLESALER => 'e.g., "Kerala Fish Wholesale"',
        };

        return [
            'type' => 'buttons',
            'header' => '📝 Business Name',
            'body' => "📝 *Business Name*\n\n" .
                "What's your business/stall name?\n\n" .
                "{$example}\n\n" .
                "_Type your business name:_",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Ask for location.
     */
    public static function askSellerLocation(): array
    {
        return [
            'type' => 'buttons',
            'header' => '📍 Your Location',
            'body' => "📍 *Your Location*\n\n" .
                "Share your selling location so customers can find you.\n\n" .
                "Tap the 📎 attachment button and select *Location* to share.",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Ask for market/harbour name.
     */
    public static function askMarketName(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🏪 Market/Harbour',
            'body' => "🏪 *Market/Harbour Name*\n\n" .
                "Which market or harbour do you sell at?\n\n" .
                "_e.g., Fort Kochi Harbour, Vypeen Fish Market_\n\n" .
                "Type the name or tap 'Skip':",
            'buttons' => [
                ['id' => 'skip_market', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Seller registration complete.
     *
     * @srs-ref NFR-U-04: Main menu accessible from any flow state
     */
    public static function sellerRegistrationComplete(FishSeller $seller): array
    {
        return [
            'type' => 'buttons',
            'header' => '✅ Registration Complete',
            'body' => "✅ *Registration Complete!*\n\n" .
                "Welcome to Pacha Meen, *{$seller->business_name}*! 🎉\n\n" .
                "📍 Location: {$seller->location_display}\n" .
                "🏷️ Type: {$seller->seller_type->label()}\n\n" .
                "You can now post your fresh catches and reach customers nearby!",
            'buttons' => [
                ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Fish Catch Posting Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Start catch posting flow.
     */
    public static function startCatchPosting(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🐟 Post Fresh Catch',
            'body' => "🐟 *Post Fresh Catch*\n\n" .
                "Let's add your fresh fish to notify nearby customers!\n\n" .
                "First, select the type of fish you have:",
            'buttons' => [
                ['id' => 'select_fish', 'title' => '🐟 Select Fish'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Fish category selection.
     *
     * Step 1: User selects a category (shows categories with fish count)
     * Only shows categories that have active fish types.
     */
    public static function selectFishCategory(): array
    {
        // Get counts for each category
        $categories = [
            FishType::CATEGORY_SEA_FISH => [
                'icon' => '🌊',
                'title' => 'Sea Fish',
                'examples' => 'Sardine, Mackerel, Tuna',
            ],
            FishType::CATEGORY_FRESHWATER => [
                'icon' => '🏞️',
                'title' => 'Freshwater',
                'examples' => 'Tilapia, Rohu, Catfish',
            ],
            FishType::CATEGORY_SHELLFISH => [
                'icon' => '🐚',
                'title' => 'Shellfish',
                'examples' => 'Mussels, Clams, Oysters',
            ],
            FishType::CATEGORY_CRUSTACEAN => [
                'icon' => '🦐',
                'title' => 'Crustacean',
                'examples' => 'Prawns, Crabs, Lobster',
            ],
        ];

        $rows = [];
        $totalFish = 0;

        foreach ($categories as $categoryKey => $categoryInfo) {
            $count = FishType::active()->where('category', $categoryKey)->count();
            if ($count > 0) {
                $totalFish += $count;
                $rows[] = [
                    'id' => 'cat_' . $categoryKey,
                    'title' => "{$categoryInfo['icon']} {$categoryInfo['title']}",
                    'description' => "{$count} types - {$categoryInfo['examples']}",
                ];
            }
        }

        // Add main menu
        $rows[] = [
            'id' => 'main_menu',
            'title' => '🏠 Main Menu',
            'description' => 'Return to main menu',
        ];

        return [
            'type' => 'list',
            'header' => '🐟 Select Category',
            'body' => "What fish do you have today?\n\nSelect a category to browse fish types.\n\n📊 Total: {$totalFish} fish types available",
            'button' => 'Choose Category',
            'sections' => [
                [
                    'title' => '📂 Fish Categories',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Fish selection from category with pagination.
     *
     * Step 2: Shows fish from selected category (max 8 fish + navigation)
     *
     * @param string $category Category constant (sea_fish, freshwater, etc.)
     * @param int $page Page number (0-based)
     */
    public static function selectFishFromCategory(string $category, int $page = 0): array
    {
        $perPage = 8; // 8 fish + navigation options = max 10 items
        $offset = $page * $perPage;

        // Get fish from this category
        $query = FishType::active()
            ->where('category', $category)
            ->orderByDesc('is_popular')
            ->orderBy('sort_order')
            ->orderBy('name_en');

        $totalInCategory = $query->count();
        $fishTypes = (clone $query)->skip($offset)->take($perPage)->get();

        $hasMore = ($offset + $perPage) < $totalInCategory;
        $hasPrevious = $page > 0;

        // Build rows with fish items
        $rows = $fishTypes->map(fn($fish) => $fish->toListItem())->toArray();

        // Add navigation options
        if ($hasMore) {
            $remaining = $totalInCategory - $offset - $perPage;
            $rows[] = [
                'id' => "cat_{$category}_page_" . ($page + 1),
                'title' => '➡️ More Fish',
                'description' => "Show next " . min($perPage, $remaining) . " fish",
            ];
        }

        if ($hasPrevious) {
            $rows[] = [
                'id' => "cat_{$category}_page_" . ($page - 1),
                'title' => '⬅️ Previous',
                'description' => 'Show previous fish',
            ];
        }

        // Always add back to categories
        $rows[] = [
            'id' => 'back_to_categories',
            'title' => '🔙 Back to Categories',
            'description' => 'Choose different category',
        ];

        // Ensure we don't exceed 10 items
        $rows = array_slice($rows, 0, 10);

        $categoryLabels = [
            'sea_fish' => '🌊 Sea Fish',
            'freshwater' => '🏞️ Freshwater',
            'shellfish' => '🐚 Shellfish',
            'crustacean' => '🦐 Crustacean',
        ];

        $categoryLabel = $categoryLabels[$category] ?? '🐟 Fish';
        $showingStart = $offset + 1;
        $showingEnd = min($offset + $perPage, $totalInCategory);
        $pageInfo = $totalInCategory > $perPage 
            ? "\n\n📄 Showing {$showingStart}-{$showingEnd} of {$totalInCategory}" 
            : "\n\n📄 {$totalInCategory} fish types";

        return [
            'type' => 'list',
            'header' => $categoryLabel,
            'body' => "Select your fish:{$pageInfo}",
            'button' => 'Choose Fish',
            'sections' => [
                [
                    'title' => $categoryLabel,
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Fish type selection list (legacy - for backward compatibility).
     *
     * CRITICAL: WhatsApp limits lists to 10 items total across all sections.
     */
    public static function selectFishType(array $sections = null): array
    {
        // Default to category selection for better UX
        return self::selectFishCategory();
    }

    /**
     * Popular fish quick selection.
     *
     * CRITICAL: WhatsApp limits lists to 10 items total.
     */
    public static function selectPopularFish(): array
    {
        // Use model's method - 9 fish + 1 main menu = 10
        $popular = FishType::getPopularListItems(9);
        $popular[] = ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'];

        return [
            'type' => 'list',
            'header' => '🐟 Popular Fish',
            'body' => "Quick selection of popular fish types:\n\n_Or type the fish name to search_",
            'button' => 'Select Fish',
            'sections' => [
                [
                    'title' => '⭐ Popular',
                    'rows' => $popular,
                ],
            ],
        ];
    }

    /**
     * Ask for quantity.
     */
    public static function askQuantity(FishType $fishType): array
    {
        return [
            'type' => 'buttons',
            'header' => "📦 Quantity",
            'body' => "How much *{$fishType->name_en}* ({$fishType->name_ml}) do you have?\n\nSelect approximate quantity:",
            'buttons' => array_slice(FishQuantityRange::toButtons(), 0, 3), // WhatsApp allows max 3 buttons
        ];
    }

    /**
     * Ask for quantity (list for more options).
     */
    public static function askQuantityList(FishType $fishType): array
    {
        $rows = array_map(fn($range) => [
            'id' => 'qty_' . $range->value,
            'title' => $range->label(),
            'description' => $range->approximateDisplay(),
        ], FishQuantityRange::cases());

        $rows[] = ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'];

        return [
            'type' => 'list',
            'header' => '📦 Quantity',
            'body' => "How much *{$fishType->name_en}* do you have?",
            'button' => 'Select Quantity',
            'sections' => [
                [
                    'title' => 'Quantity Range',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Ask for price.
     */
    public static function askPrice(FishType $fishType): array
    {
        $priceHint = $fishType->price_range
            ? "Typical price: {$fishType->price_range}"
            : "Enter your price per kg";

        return [
            'type' => 'buttons',
            'header' => '💰 Price',
            'body' => "💰 *Price per kg*\n\n" .
                "{$fishType->emoji} {$fishType->name_en}\n\n" .
                "{$priceHint}\n\n" .
                "_Type the price (just the number):_\n" .
                "e.g., 180",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Ask for photo.
     */
    public static function askPhoto(FishType $fishType): array
    {
        return [
            'type' => 'buttons',
            'header' => '📸 Photo',
            'body' => "Add a photo of your *{$fishType->name_en}* to attract more customers!\n\n" .
                "📎 Tap attachment → Camera/Gallery\n\n" .
                "Or skip if you don't have a photo ready.",
            'buttons' => [
                ['id' => 'skip_photo', 'title' => '⏭️ Skip Photo'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Confirm catch posting.
     */
    public static function confirmCatchPosting(array $catchData, FishType $fishType): array
    {
        // Format quantity range for display
        $qtyRange = $catchData['quantity_range'] ?? 'unknown';
        $qty = self::formatQuantityRange($qtyRange);

        $price = number_format($catchData['price_per_kg'] ?? 0);
        $hasPhoto = !empty($catchData['has_photo']);
        $photoStatus = $hasPhoto ? '✅ Photo added' : '📷 No photo';

        // Build buttons - WhatsApp max 3 buttons
        $buttons = [
            ['id' => 'confirm_post', 'title' => '✅ Post Now'],
        ];

        // Add photo edit option
        if ($hasPhoto) {
            $buttons[] = ['id' => 'edit_photo', 'title' => '📷 Change Photo'];
        } else {
            $buttons[] = ['id' => 'edit_photo', 'title' => '📷 Add Photo'];
        }

        $buttons[] = ['id' => 'edit_details', 'title' => '✏️ Edit All'];

        return [
            'type' => 'buttons',
            'header' => '✅ Confirm Posting',
            'body' => "Please confirm your catch details:\n\n" .
                "{$fishType->emoji} *{$fishType->name_en}*\n" .
                "({$fishType->name_ml})\n\n" .
                "📦 Quantity: {$qty}\n" .
                "💰 Price: ₹{$price}/kg\n" .
                "{$photoStatus}\n\n" .
                "Post this catch?",
            'buttons' => $buttons,
        ];
    }

    /**
     * Format quantity range for display.
     */
    protected static function formatQuantityRange(string $range): string
    {
        // Handle common FishQuantityRange enum formats
        return match ($range) {
            'under_2kg', 'small' => 'Under 2 kg',
            '2_5kg', '2_5' => '2-5 kg',
            '5_10kg', '5_10' => '5-10 kg',
            '10_20kg', '10_20' => '10-20 kg',
            '20_50kg', '20_50' => '20-50 kg',
            'above_50kg', 'large' => 'Above 50 kg',
            default => str_replace('_', '-', $range) . ' kg',
        };
    }

    /**
     * Catch posted successfully.
     *
     * @srs-ref NFR-U-04: Main menu accessible from any flow state
     */
    public static function catchPostedSuccess(FishCatch $catch, int $subscriberCount): array
    {
        $alertMsg = $subscriberCount > 0
            ? "📢 *{$subscriberCount} customers* will be notified!"
            : "📢 Waiting for subscribers nearby...";

        return [
            'type' => 'buttons',
            'header' => '🎉 Posted!',
            'body' => "Your catch has been posted!\n\n" .
                "{$catch->fishType->emoji} *{$catch->fishType->name_en}*\n" .
                "📦 {$catch->quantity_display}\n" .
                "💰 {$catch->price_display}\n" .
                "⏰ Expires: {$catch->time_remaining}\n\n" .
                "{$alertMsg}",
            'buttons' => [
                ['id' => 'add_another', 'title' => '➕ Add Another'],
                ['id' => 'view_my_catches', 'title' => '📋 My Catches'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Ask to add another fish.
     */
    public static function askAddAnother(): array
    {
        return [
            'type' => 'buttons',
            'body' => "Would you like to add another type of fish?",
            'buttons' => [
                ['id' => 'add_another_yes', 'title' => '➕ Yes, Add More'],
                ['id' => 'add_another_no', 'title' => '✅ Done'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Stock Update Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Show seller's active catches for stock update.
     */
    public static function selectCatchForUpdate(Collection $catches): array
    {
        if ($catches->isEmpty()) {
            return [
                'type' => 'buttons',
                'header' => '📋 No Active Catches',
                'body' => "📋 *No Active Catches*\n\n" .
                    "You don't have any active catches to update.\n\n" .
                    "Post a new catch to get started!",
                'buttons' => [
                    ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ],
            ];
        }

        $rows = $catches->map(fn($catch) => $catch->toListItem())->toArray();
        $rows[] = ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'];

        return [
            'type' => 'list',
            'header' => '📋 Update Stock',
            'body' => "Select a catch to update its status:",
            'button' => 'Select Catch',
            'sections' => [
                [
                    'title' => 'Active Catches',
                    'rows' => array_slice($rows, 0, 10),
                ],
            ],
        ];
    }

    /**
     * Stock update options.
     */
    public static function stockUpdateOptions(FishCatch $catch): array
    {
        return [
            'type' => 'buttons',
            'header' => '📦 Update Stock',
            'body' => "{$catch->fishType->emoji} *{$catch->fishType->name_en}*\n" .
                "Current: {$catch->status->display()}\n\n" .
                "Select new status:",
            'buttons' => [
                ['id' => 'status_available', 'title' => '✅ Available'],
                ['id' => 'status_low_stock', 'title' => '⚠️ Low Stock'],
                ['id' => 'status_sold_out', 'title' => '❌ Sold Out'],
            ],
        ];
    }

    /**
     * Stock updated confirmation.
     *
     * @srs-ref NFR-U-04: Main menu accessible from any flow state
     */
    public static function stockUpdated(FishCatch $catch): array
    {
        return [
            'type' => 'buttons',
            'header' => '✅ Stock Updated',
            'body' => "✅ *Stock Updated*\n\n" .
                "{$catch->fishType->emoji} {$catch->fishType->name_en}\n" .
                "Status: {$catch->status->display()}\n\n" .
                "Customers have been notified.",
            'buttons' => [
                ['id' => 'fish_update_stock', 'title' => '📦 Update Another'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Customer Subscription Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Subscription welcome.
     */
    public static function subscriptionWelcome(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🐟 Fish Alerts',
            'body' => "🐟 *Fresh Fish Alerts*\n\n" .
                "Get notified when fresh fish arrives near you!\n\n" .
                "• Choose your preferred fish types\n" .
                "• Set your location & radius\n" .
                "• Receive instant alerts\n\n" .
                "Let's set up your subscription! 📍",
            'buttons' => [
                ['id' => 'continue_subscribe', 'title' => '✅ Continue'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Ask for subscription location.
     */
    public static function askSubscriptionLocation(): array
    {
        return [
            'type' => 'buttons',
            'header' => '📍 Your Location',
            'body' => "📍 *Your Location*\n\n" .
                "Share your location to receive alerts for fresh fish nearby.\n\n" .
                "Tap 📎 → *Location* to share where you want alerts.",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Ask for alert radius.
     */
    public static function askAlertRadius(): array
    {
        return [
            'type' => 'list',
            'header' => '📍 Alert Radius',
            'body' => "How far should we look for fresh fish?\n\nSelect your preferred radius:",
            'button' => 'Select Radius',
            'sections' => [
                [
                    'title' => 'Distance',
                    'rows' => [
                        ['id' => 'radius_3', 'title' => '3 km', 'description' => 'Nearby only'],
                        ['id' => 'radius_5', 'title' => '5 km', 'description' => 'Recommended'],
                        ['id' => 'radius_10', 'title' => '10 km', 'description' => 'Wider area'],
                        ['id' => 'radius_15', 'title' => '15 km', 'description' => 'Extended area'],
                        ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Ask for fish type preferences.
     */
    public static function askFishPreferences(): array
    {
        return [
            'type' => 'buttons',
            'header' => '🐟 Fish Preferences',
            'body' => "What fish do you want alerts for?\n\n" .
                "You can select specific types or get alerts for all fresh fish.",
            'buttons' => [
                ['id' => 'fish_pref_all', 'title' => '🐟 All Fish Types'],
                ['id' => 'fish_pref_select', 'title' => '✅ Select Types'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Select specific fish types for alerts.
     *
     * Uses category selection for better UX.
     */
    public static function selectFishPreferences(): array
    {
        return self::selectFishPreferencesCategory();
    }

    /**
     * Fish category selection for alert preferences.
     */
    public static function selectFishPreferencesCategory(): array
    {
        $categories = [
            FishType::CATEGORY_SEA_FISH => ['icon' => '🌊', 'title' => 'Sea Fish'],
            FishType::CATEGORY_FRESHWATER => ['icon' => '🏞️', 'title' => 'Freshwater'],
            FishType::CATEGORY_SHELLFISH => ['icon' => '🐚', 'title' => 'Shellfish'],
            FishType::CATEGORY_CRUSTACEAN => ['icon' => '🦐', 'title' => 'Crustacean'],
        ];

        $rows = [];

        foreach ($categories as $categoryKey => $categoryInfo) {
            $count = FishType::active()->where('category', $categoryKey)->count();
            if ($count > 0) {
                $rows[] = [
                    'id' => 'pref_cat_' . $categoryKey,
                    'title' => "{$categoryInfo['icon']} {$categoryInfo['title']}",
                    'description' => "{$count} types available",
                ];
            }
        }

        $rows[] = [
            'id' => 'pref_done',
            'title' => '✅ Done',
            'description' => 'Finish selection',
        ];

        $rows[] = [
            'id' => 'main_menu',
            'title' => '🏠 Main Menu',
            'description' => 'Return to main menu',
        ];

        return [
            'type' => 'list',
            'header' => '🐟 Fish Preferences',
            'body' => "Select fish categories you want alerts for:\n\n_Select categories, then tap 'Done' when finished._",
            'button' => 'Select Category',
            'sections' => [
                [
                    'title' => '📂 Categories',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Fish selection from category for preferences.
     *
     * @param string $category
     * @param int $page
     * @param array $selectedIds Already selected fish IDs
     */
    public static function selectFishPreferencesFromCategory(string $category, int $page = 0, array $selectedIds = []): array
    {
        $perPage = 7; // 7 fish + navigation options
        $offset = $page * $perPage;

        $query = FishType::active()
            ->where('category', $category)
            ->orderByDesc('is_popular')
            ->orderBy('name_en');

        $totalInCategory = $query->count();
        $fishTypes = (clone $query)->skip($offset)->take($perPage)->get();

        $hasMore = ($offset + $perPage) < $totalInCategory;
        $hasPrevious = $page > 0;

        // Build rows with selection indicator
        $rows = $fishTypes->map(function ($fish) use ($selectedIds) {
            $isSelected = in_array($fish->id, $selectedIds);
            return [
                'id' => 'pref_fish_' . $fish->id,
                'title' => ($isSelected ? '✅ ' : '') . substr($fish->display_name, 0, 22),
                'description' => $fish->name_ml,
            ];
        })->toArray();

        // Navigation
        if ($hasMore) {
            $rows[] = [
                'id' => "pref_cat_{$category}_page_" . ($page + 1),
                'title' => '➡️ More Fish',
                'description' => 'Show more fish',
            ];
        }

        if ($hasPrevious) {
            $rows[] = [
                'id' => "pref_cat_{$category}_page_" . ($page - 1),
                'title' => '⬅️ Previous',
                'description' => 'Show previous fish',
            ];
        }

        $rows[] = [
            'id' => 'pref_back_categories',
            'title' => '🔙 Back to Categories',
            'description' => 'Select from other categories',
        ];

        $rows = array_slice($rows, 0, 10);

        $categoryLabels = [
            'sea_fish' => '🌊 Sea Fish',
            'freshwater' => '🏞️ Freshwater',
            'shellfish' => '🐚 Shellfish',
            'crustacean' => '🦐 Crustacean',
        ];

        $categoryLabel = $categoryLabels[$category] ?? '🐟 Fish';
        $selectedCount = count($selectedIds);
        $selectedInfo = $selectedCount > 0 ? "\n\n✅ {$selectedCount} fish selected" : "";

        return [
            'type' => 'list',
            'header' => $categoryLabel,
            'body' => "Select fish for alerts:{$selectedInfo}",
            'button' => 'Choose Fish',
            'sections' => [
                [
                    'title' => $categoryLabel,
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Ask for alert frequency.
     */
    public static function askAlertFrequency(): array
    {
        $rows = FishAlertFrequency::toListItems();
        $rows[] = ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'];

        return [
            'type' => 'list',
            'header' => '🔔 Alert Frequency',
            'body' => "How often would you like to receive alerts?",
            'button' => 'Select Frequency',
            'sections' => [
                [
                    'title' => 'Frequency Options',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Confirm subscription.
     */
    public static function confirmSubscription(array $subData): array
    {
        $radius = $subData['radius_km'] ?? 5;
        $fishTypes = $subData['all_fish_types'] ?? true
            ? 'All fish types'
            : count($subData['fish_type_ids'] ?? []) . ' selected types';
        $frequency = isset($subData['alert_frequency'])
            ? ($subData['alert_frequency'] instanceof FishAlertFrequency
                ? $subData['alert_frequency']->label()
                : FishAlertFrequency::from($subData['alert_frequency'])->label())
            : 'Immediate';

        return [
            'type' => 'buttons',
            'header' => '✅ Confirm Subscription',
            'body' => "Your alert preferences:\n\n" .
                "📍 Radius: {$radius} km\n" .
                "🐟 Fish: {$fishTypes}\n" .
                "🔔 Frequency: {$frequency}\n\n" .
                "Start receiving alerts?",
            'buttons' => [
                ['id' => 'confirm_subscription', 'title' => '✅ Subscribe'],
                ['id' => 'edit_subscription', 'title' => '✏️ Edit'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Subscription created successfully.
     *
     * @srs-ref NFR-U-04: Main menu accessible from any flow state
     * @srs-ref PM-015: Modify subscriptions (manage alerts option)
     */
    public static function subscriptionCreated(FishSubscription $subscription): array
    {
        return [
            'type' => 'buttons',
            'header' => '🎉 Subscribed!',
            'body' => "🎉 *Subscribed!*\n\n" .
                "You'll receive fresh fish alerts:\n\n" .
                "📍 Within {$subscription->radius_km} km\n" .
                "🐟 {$subscription->fish_types_display}\n" .
                "🔔 {$subscription->frequency_display}\n\n" .
                "We'll notify you when fresh fish arrives nearby! 🐟",
            'buttons' => [
                ['id' => 'fish_browse', 'title' => '🔍 Browse Fish'],
                ['id' => 'fish_manage_alerts', 'title' => '⚙️ Manage Alerts'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Alert Messages
    |--------------------------------------------------------------------------
    */

    /**
     * New catch alert message.
     *
     * @srs-ref Section 2.5.2 - Customer Alert Message Format
     * @srs-ref NFR-U-04: Main menu accessible
     */
    public static function newCatchAlert(FishCatch $catch, FishAlert $alert): array
    {
        $seller = $catch->seller;
        $fishType = $catch->fishType;
        $distance = $alert->distance_km
            ? ($alert->distance_km < 1
                ? round($alert->distance_km * 1000) . 'm'
                : round($alert->distance_km, 1) . ' km')
            : '';

        $body = "{$fishType->emoji} *{$fishType->name_en}*\n" .
            "({$fishType->name_ml})\n\n" .
            "💰 *{$catch->price_display}*\n" .
            "📦 {$catch->quantity_display}\n" .
            "⏰ {$catch->freshness_display}\n\n" .
            "📍 *{$seller->business_name}*\n" .
            "{$catch->location_display}";

        if ($distance) {
            $body .= "\n🚗 {$distance} away";
        }

        if ($seller->rating_count > 0) {
            $body .= "\n{$seller->short_rating}";
        }

        $buttons = [
            ['id' => "fish_coming_{$catch->id}_{$alert->id}", 'title' => "🏃 I'm Coming!"],
            ['id' => "fish_location_{$catch->id}_{$alert->id}", 'title' => '📍 Get Location'],
            ['id' => 'main_menu', 'title' => '🏠 Menu'],
        ];

        $message = [
            'type' => 'buttons',
            'header' => '🐟 Fresh Fish Alert!',
            'body' => $body,
            'buttons' => $buttons,
        ];

        // Add image if available
        if ($catch->photo_url) {
            $message['image'] = $catch->photo_url;
        }

        return $message;
    }

    /**
     * Low stock alert message.
     *
     * @srs-ref NFR-U-04: Main menu accessible
     */
    public static function lowStockAlert(FishCatch $catch, FishAlert $alert): array
    {
        return [
            'type' => 'buttons',
            'header' => '⚠️ Low Stock Alert!',
            'body' => "{$catch->fishType->emoji} *{$catch->fishType->name_en}* is running low!\n\n" .
                "📍 {$catch->seller->business_name}\n" .
                "💰 {$catch->price_display}\n\n" .
                "Hurry if you want to get some! 🏃",
            'buttons' => [
                ['id' => "fish_coming_{$catch->id}_{$alert->id}", 'title' => "🏃 On My Way!"],
                ['id' => "fish_location_{$catch->id}_{$alert->id}", 'title' => '📍 Location'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Batch digest message.
     *
     * @srs-ref NFR-U-04: Main menu accessible
     */
    public static function batchDigest(Collection $catches, FishSubscription $subscription): array
    {
        $lines = ["Fresh catches near " . ($subscription->location_label ?? 'you') . ":\n"];

        foreach ($catches->take(5) as $catch) {
            $lines[] = "{$catch->fishType->emoji} *{$catch->fishType->name_en}* - {$catch->price_display}";
            $lines[] = "   📍 {$catch->seller->business_name} • {$catch->freshness_display}\n";
        }

        if ($catches->count() > 5) {
            $more = $catches->count() - 5;
            $lines[] = "_+{$more} more catches available_";
        }

        return [
            'type' => 'buttons',
            'header' => '🐟 Fish Alert Digest',
            'body' => implode("\n", $lines),
            'buttons' => [
                ['id' => 'fish_browse_all', 'title' => '🔍 View All'],
                ['id' => 'fish_manage_alerts', 'title' => '⚙️ Settings'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Coming confirmation to customer.
     *
     * @srs-ref NFR-U-04: Main menu accessible from any flow state
     */
    public static function comingConfirmation(FishCatch $catch): array
    {
        return [
            'type' => 'buttons',
            'header' => "🏃 You're on your way!",
            'body' => "🏃 *You're on your way!*\n\n" .
                "The seller has been notified.\n\n" .
                "{$catch->fishType->emoji} {$catch->fishType->name_en}\n" .
                "📍 {$catch->seller->business_name}\n" .
                "📞 {$catch->seller->user->formatted_phone}\n\n" .
                "Safe travels! 🚗",
            'buttons' => [
                ['id' => "fish_location_{$catch->id}_0", 'title' => '📍 Get Directions'],
                ['id' => 'fish_browse', 'title' => '🔍 Browse More'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Seller location message.
     */
    public static function sellerLocation(FishSeller $seller): array
    {
        return [
            'type' => 'location',
            'latitude' => $seller->latitude,
            'longitude' => $seller->longitude,
            'name' => $seller->business_name,
            'address' => $seller->location_display,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Browse Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Browse results.
     *
     * @srs-ref NFR-U-04: Main menu accessible from any flow state
     */
    public static function browseResults(Collection $catches, string $location = 'your area'): array
    {
        if ($catches->isEmpty()) {
            return [
                'type' => 'buttons',
                'header' => '🐟 No Fresh Fish Nearby',
                'body' => "No active catches found in {$location}.\n\n" .
                    "Subscribe to get alerts when fresh fish arrives!",
                'buttons' => [
                    ['id' => 'fish_subscribe', 'title' => '🔔 Subscribe'],
                    ['id' => 'fish_refresh', 'title' => '🔄 Refresh'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ],
            ];
        }

        $rows = $catches->take(9)->map(fn($catch) => [
            'id' => 'catch_' . $catch->id,
            'title' => substr($catch->fishType->display_name, 0, 24),
            'description' => substr("{$catch->price_display} • {$catch->freshness_display}", 0, 72),
        ])->toArray();

        // Add main menu option
        $rows[] = ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'];

        return [
            'type' => 'list',
            'header' => '🐟 Fresh Fish Nearby',
            'body' => "{$catches->count()} catches available in {$location}:",
            'button' => 'View Fish',
            'sections' => [
                [
                    'title' => 'Available Now',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /**
     * Catch detail view.
     *
     * @srs-ref NFR-U-04: Main menu accessible from any flow state
     */
    public static function catchDetail(FishCatch $catch, ?float $distanceKm = null): array
    {
        $distance = $distanceKm
            ? ($distanceKm < 1 ? round($distanceKm * 1000) . 'm' : round($distanceKm, 1) . ' km') . ' away'
            : '';

        $body = "{$catch->fishType->emoji} *{$catch->fishType->name_en}*\n" .
            "({$catch->fishType->name_ml})\n\n" .
            "💰 *{$catch->price_display}*\n" .
            "📦 {$catch->quantity_display}\n" .
            "⏰ {$catch->freshness_display}\n" .
            "📊 Status: {$catch->status->display()}\n\n" .
            "📍 *{$catch->seller->business_name}*\n" .
            "{$catch->location_display}";

        if ($distance) {
            $body .= "\n🚗 {$distance}";
        }

        $body .= "\n{$catch->seller->short_rating}";

        $message = [
            'type' => 'buttons',
            'body' => $body,
            'buttons' => [
                ['id' => "fish_coming_{$catch->id}_0", 'title' => "🏃 I'm Coming!"],
                ['id' => "fish_location_{$catch->id}_0", 'title' => '📍 Get Location'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];

        if ($catch->photo_url) {
            $message['image'] = $catch->photo_url;
        }

        return $message;
    }

    /*
    |--------------------------------------------------------------------------
    | Menu Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Fish seller menu.
     *
     * @srs-ref NFR-U-04: Main menu accessible
     */
    public static function fishSellerMenu(FishSeller $seller): array
    {
        $activeCatches = $seller->getActiveCatchCount();

        return [
            'type' => 'list',
            'header' => '🐟 Fish Seller Menu',
            'body' => "Welcome, {$seller->business_name}!\n\n" .
                "📊 Active catches: {$activeCatches}\n" .
                "⭐ Rating: {$seller->short_rating}",
            'button' => 'Select Option',
            'sections' => [
                [
                    'title' => 'Actions',
                    'rows' => [
                        ['id' => 'fish_post_catch', 'title' => '🐟 Post New Catch', 'description' => 'Add fresh fish'],
                        ['id' => 'fish_update_stock', 'title' => '📦 Update Stock', 'description' => 'Change availability'],
                        ['id' => 'fish_my_catches', 'title' => '📋 My Catches', 'description' => 'View active posts'],
                        ['id' => 'fish_my_stats', 'title' => '📊 My Stats', 'description' => 'Sales & ratings'],
                        ['id' => 'fish_settings', 'title' => '⚙️ Settings', 'description' => 'Profile & alerts'],
                        ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Customer fish menu.
     *
     * @srs-ref NFR-U-04: Main menu accessible
     * @srs-ref PM-015: Manage subscription option
     */
    public static function customerFishMenu(bool $hasSubscription = false): array
    {
        $rows = [
            ['id' => 'fish_browse', 'title' => '🔍 Browse Fresh Fish', 'description' => 'See what\'s available nearby'],
        ];

        if ($hasSubscription) {
            // User has subscription - show manage option (unsubscribe, edit, etc.)
            // @srs-ref PM-015: Allow subscription modification
            $rows[] = ['id' => 'fish_manage_alerts', 'title' => '⚙️ Manage Alerts', 'description' => 'Edit or unsubscribe'];
            $rows[] = ['id' => 'fish_pause_alerts', 'title' => '⏸️ Pause Alerts', 'description' => 'Temporarily stop'];
        } else {
            $rows[] = ['id' => 'fish_subscribe', 'title' => '🔔 Get Fish Alerts', 'description' => 'Subscribe to notifications'];
        }

        // Always add main menu option
        $rows[] = ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'];

        return [
            'type' => 'list',
            'header' => '🐟 Fresh Fish',
            'body' => "What would you like to do?",
            'button' => 'Select',
            'sections' => [
                [
                    'title' => 'Options',
                    'rows' => $rows,
                ],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Invalid fish type error.
     *
     * @srs-ref NFR-U-04: Main menu accessible
     */
    public static function errorInvalidFishType(): array
    {
        return [
            'type' => 'buttons',
            'body' => "❌ Invalid fish type selected.\n\nPlease choose from the list or type a valid fish name.",
            'buttons' => [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Invalid price error.
     *
     * @srs-ref NFR-U-04: Main menu accessible
     */
    public static function errorInvalidPrice(): array
    {
        return [
            'type' => 'buttons',
            'body' => "❌ Invalid price.\n\nPlease enter a valid price in rupees.\n_e.g., 180_",
            'buttons' => [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Location required error.
     *
     * @srs-ref NFR-U-04: Main menu accessible
     */
    public static function errorLocationRequired(): array
    {
        return [
            'type' => 'buttons',
            'body' => "📍 Please share your location.\n\nTap 📎 → *Location* to share.",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Not a fish seller error.
     *
     * @srs-ref NFR-U-04: Main menu accessible
     */
    public static function errorNotFishSeller(): array
    {
        return [
            'type' => 'buttons',
            'body' => "🐟 This feature is for registered fish sellers.\n\nWould you like to register as a fish seller?",
            'buttons' => [
                ['id' => 'fish_seller_register', 'title' => '✅ Register Now'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Daily limit reached error.
     *
     * @srs-ref NFR-U-04: Main menu accessible
     */
    public static function errorDailyLimitReached(): array
    {
        return [
            'type' => 'buttons',
            'header' => '⚠️ Daily Limit',
            'body' => "⚠️ *Daily Limit Reached*\n\n" .
                "You've reached the maximum number of catch postings for today.\n\n" .
                "Try again tomorrow!",
            'buttons' => [
                ['id' => 'fish_my_catches', 'title' => '📋 My Catches'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }
}