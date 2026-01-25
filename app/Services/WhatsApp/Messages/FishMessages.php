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
            'type' => 'text',
            'text' => "🐟 *Welcome to Pacha Meen!*\n\n" .
                "Register as a fish seller to:\n" .
                "• Post your fresh catch\n" .
                "• Reach customers instantly\n" .
                "• Manage your sales\n\n" .
                "Let's get you started! 🎣",
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
                    'rows' => FishSellerType::toListItems(),
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
            'type' => 'text',
            'text' => "📝 *Business Name*\n\n" .
                "What's your business/stall name?\n\n" .
                "{$example}\n\n" .
                "_Type your business name:_",
        ];
    }

    /**
     * Ask for location.
     */
    public static function askSellerLocation(): array
    {
        return [
            'type' => 'text',
            'text' => "📍 *Your Location*\n\n" .
                "Share your selling location so customers can find you.\n\n" .
                "Tap the 📎 attachment button and select *Location* to share.",
        ];
    }

    /**
     * Ask for market/harbour name.
     */
    public static function askMarketName(): array
    {
        return [
            'type' => 'text',
            'text' => "🏪 *Market/Harbour Name*\n\n" .
                "Which market or harbour do you sell at?\n\n" .
                "_e.g., Fort Kochi Harbour, Vypeen Fish Market_\n\n" .
                "Type the name or send 'skip' to continue:",
        ];
    }

    /**
     * Seller registration complete.
     */
    public static function sellerRegistrationComplete(FishSeller $seller): array
    {
        return [
            'type' => 'text',
            'text' => "✅ *Registration Complete!*\n\n" .
                "Welcome to Pacha Meen, *{$seller->business_name}*! 🎉\n\n" .
                "📍 Location: {$seller->location_display}\n" .
                "🏷️ Type: {$seller->seller_type->label()}\n\n" .
                "You can now post your fresh catches and reach customers nearby!\n\n" .
                "Type *menu* to see your options.",
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
            'type' => 'text',
            'text' => "🐟 *Post Fresh Catch*\n\n" .
                "Let's add your fresh fish to notify nearby customers!\n\n" .
                "First, select the type of fish you have:",
        ];
    }

    /**
     * Fish type selection list.
     */
    public static function selectFishType(array $sections = null): array
    {
        if (!$sections) {
            $sections = FishType::getListSections();
        }

        return [
            'type' => 'list',
            'header' => '🐟 Select Fish',
            'body' => "What fish do you have today?\n\nSelect from the list below:",
            'button' => 'Choose Fish',
            'sections' => $sections,
        ];
    }

    /**
     * Popular fish quick selection.
     */
    public static function selectPopularFish(): array
    {
        $popular = FishType::getPopularListItems(10);

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
        return [
            'type' => 'list',
            'header' => '📦 Quantity',
            'body' => "How much *{$fishType->name_en}* do you have?",
            'button' => 'Select Quantity',
            'sections' => [
                [
                    'title' => 'Quantity Range',
                    'rows' => array_map(fn($range) => [
                        'id' => 'qty_' . $range->value,
                        'title' => $range->label(),
                        'description' => $range->approximateDisplay(),
                    ], FishQuantityRange::cases()),
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
            'type' => 'text',
            'text' => "💰 *Price per kg*\n\n" .
                "{$fishType->emoji} {$fishType->name_en}\n\n" .
                "{$priceHint}\n\n" .
                "_Type the price (just the number):_\n" .
                "e.g., 180",
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
            ],
        ];
    }

    /**
     * Confirm catch posting.
     */
    public static function confirmCatchPosting(array $catchData, FishType $fishType): array
    {
        $qty = $catchData['quantity_range'] instanceof FishQuantityRange
            ? $catchData['quantity_range']->label()
            : FishQuantityRange::from($catchData['quantity_range'])->label();

        $price = number_format($catchData['price_per_kg']);
        $hasPhoto = !empty($catchData['photo_url']) ? '✅ Photo added' : '📷 No photo';

        return [
            'type' => 'buttons',
            'header' => '✅ Confirm Posting',
            'body' => "Please confirm your catch details:\n\n" .
                "{$fishType->emoji} *{$fishType->name_en}*\n" .
                "({$fishType->name_ml})\n\n" .
                "📦 Quantity: {$qty}\n" .
                "💰 Price: ₹{$price}/kg\n" .
                "{$hasPhoto}\n\n" .
                "Post this catch?",
            'buttons' => [
                ['id' => 'confirm_catch', 'title' => '✅ Post Now'],
                ['id' => 'edit_catch', 'title' => '✏️ Edit'],
                ['id' => 'cancel_catch', 'title' => '❌ Cancel'],
            ],
        ];
    }

    /**
     * Catch posted successfully.
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
                ['id' => 'add_another_fish', 'title' => '➕ Add Another'],
                ['id' => 'view_my_catches', 'title' => '📋 My Catches'],
                ['id' => 'back_to_menu', 'title' => '🏠 Menu'],
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
                'type' => 'text',
                'text' => "📋 *No Active Catches*\n\n" .
                    "You don't have any active catches to update.\n\n" .
                    "Post a new catch to get started!",
            ];
        }

        $rows = $catches->map(fn($catch) => $catch->toListItem())->toArray();

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
                ['id' => 'stock_low', 'title' => '⚠️ Low Stock'],
                ['id' => 'stock_soldout', 'title' => '❌ Sold Out'],
                ['id' => 'stock_available', 'title' => '✅ Available'],
            ],
        ];
    }

    /**
     * Stock updated confirmation.
     */
    public static function stockUpdated(FishCatch $catch): array
    {
        return [
            'type' => 'text',
            'text' => "✅ *Stock Updated*\n\n" .
                "{$catch->fishType->emoji} {$catch->fishType->name_en}\n" .
                "Status: {$catch->status->display()}\n\n" .
                "Customers have been notified.",
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
            'type' => 'text',
            'text' => "🐟 *Fresh Fish Alerts*\n\n" .
                "Get notified when fresh fish arrives near you!\n\n" .
                "• Choose your preferred fish types\n" .
                "• Set your location & radius\n" .
                "• Receive instant alerts\n\n" .
                "Let's set up your subscription! 📍",
        ];
    }

    /**
     * Ask for subscription location.
     */
    public static function askSubscriptionLocation(): array
    {
        return [
            'type' => 'text',
            'text' => "📍 *Your Location*\n\n" .
                "Share your location to receive alerts for fresh fish nearby.\n\n" .
                "Tap 📎 → *Location* to share where you want alerts.",
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
            ],
        ];
    }

    /**
     * Select specific fish types.
     */
    public static function selectFishPreferences(): array
    {
        $sections = FishType::getListSections();

        return [
            'type' => 'list',
            'header' => '🐟 Select Fish Types',
            'body' => "Choose the fish you want alerts for:\n\n_You can select multiple. Send 'done' when finished._",
            'button' => 'Select Fish',
            'sections' => $sections,
        ];
    }

    /**
     * Ask for alert frequency.
     */
    public static function askAlertFrequency(): array
    {
        return [
            'type' => 'list',
            'header' => '🔔 Alert Frequency',
            'body' => "How often would you like to receive alerts?",
            'button' => 'Select Frequency',
            'sections' => [
                [
                    'title' => 'Frequency Options',
                    'rows' => FishAlertFrequency::toListItems(),
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
                ['id' => 'cancel_subscription', 'title' => '❌ Cancel'],
            ],
        ];
    }

    /**
     * Subscription created successfully.
     */
    public static function subscriptionCreated(FishSubscription $subscription): array
    {
        return [
            'type' => 'text',
            'text' => "🎉 *Subscribed!*\n\n" .
                "You'll receive fresh fish alerts:\n\n" .
                "📍 Within {$subscription->radius_km} km\n" .
                "🐟 {$subscription->fish_types_display}\n" .
                "🔔 {$subscription->frequency_display}\n\n" .
                "We'll notify you when fresh fish arrives nearby! 🐟",
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
            ],
        ];
    }

    /**
     * Batch digest message.
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
            ],
        ];
    }

    /**
     * Coming confirmation to customer.
     */
    public static function comingConfirmation(FishCatch $catch): array
    {
        return [
            'type' => 'text',
            'text' => "🏃 *You're on your way!*\n\n" .
                "The seller has been notified.\n\n" .
                "{$catch->fishType->emoji} {$catch->fishType->name_en}\n" .
                "📍 {$catch->seller->business_name}\n" .
                "📞 {$catch->seller->user->formatted_phone}\n\n" .
                "Safe travels! 🚗",
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
     */
    public static function browseResults(Collection $catches, string $location = 'your area'): array
    {
        if ($catches->isEmpty()) {
            return [
                'type' => 'text',
                'text' => "🐟 *No Fresh Fish Nearby*\n\n" .
                    "No active catches found in {$location}.\n\n" .
                    "Subscribe to get alerts when fish arrives!",
            ];
        }

        $rows = $catches->take(10)->map(fn($catch) => [
            'id' => 'catch_' . $catch->id,
            'title' => substr($catch->fishType->display_name, 0, 24),
            'description' => substr("{$catch->price_display} • {$catch->freshness_display}", 0, 72),
        ])->toArray();

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
                ['id' => "fish_call_{$catch->id}", 'title' => '📞 Call Seller'],
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
                    ],
                ],
            ],
        ];
    }

    /**
     * Customer fish menu.
     */
    public static function customerFishMenu(bool $hasSubscription = false): array
    {
        $rows = [
            ['id' => 'fish_browse', 'title' => '🔍 Browse Fresh Fish', 'description' => 'See what\'s available nearby'],
        ];

        if ($hasSubscription) {
            $rows[] = ['id' => 'fish_manage_alerts', 'title' => '⚙️ Manage Alerts', 'description' => 'Edit preferences'];
            $rows[] = ['id' => 'fish_pause_alerts', 'title' => '⏸️ Pause Alerts', 'description' => 'Temporarily stop'];
        } else {
            $rows[] = ['id' => 'fish_subscribe', 'title' => '🔔 Get Fish Alerts', 'description' => 'Subscribe to notifications'];
        }

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
     */
    public static function errorInvalidFishType(): array
    {
        return [
            'type' => 'text',
            'text' => "❌ Invalid fish type selected.\n\nPlease choose from the list or type a valid fish name.",
        ];
    }

    /**
     * Invalid price error.
     */
    public static function errorInvalidPrice(): array
    {
        return [
            'type' => 'text',
            'text' => "❌ Invalid price.\n\nPlease enter a valid price in rupees.\n_e.g., 180_",
        ];
    }

    /**
     * Location required error.
     */
    public static function errorLocationRequired(): array
    {
        return [
            'type' => 'text',
            'text' => "📍 Please share your location.\n\nTap 📎 → *Location* to share.",
        ];
    }

    /**
     * Not a fish seller error.
     */
    public static function errorNotFishSeller(): array
    {
        return [
            'type' => 'buttons',
            'body' => "🐟 This feature is for registered fish sellers.\n\nWould you like to register as a fish seller?",
            'buttons' => [
                ['id' => 'fish_register_seller', 'title' => '✅ Register Now'],
                ['id' => 'back_to_menu', 'title' => '🏠 Back to Menu'],
            ],
        ];
    }

    /**
     * Daily limit reached error.
     */
    public static function errorDailyLimitReached(): array
    {
        return [
            'type' => 'text',
            'text' => "⚠️ *Daily Limit Reached*\n\n" .
                "You've reached the maximum number of catch postings for today.\n\n" .
                "Try again tomorrow!",
        ];
    }
}
