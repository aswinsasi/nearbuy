<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use App\Models\FishAlert;
use App\Models\FishCatch;
use App\Models\FishSeller;
use App\Models\FishSubscription;
use App\Models\FishType;
use App\Models\User;
use App\Enums\FishQuantityRange;
use Illuminate\Support\Collection;

/**
 * WhatsApp messages for Pacha Meen module.
 *
 * OPTIMIZED FOR SPEED:
 * - Seller messages: MAX 1-2 lines
 * - Customer alerts: SRS format with social proof
 * - All titles: MAX 24 chars (WhatsApp limit)
 *
 * BILINGUAL: English + Malayalam
 *
 * @srs-ref PM-016 to PM-020 Alert requirements
 * @srs-ref Section 2.5.2 Customer Alert Message Format
 */
class FishMessages
{
    /*
    |--------------------------------------------------------------------------
    | Helper: Safe title (24 char limit)
    |--------------------------------------------------------------------------
    */

    protected static function t(string $text, int $max = 24): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1) . '…';
    }

    /*
    |--------------------------------------------------------------------------
    | SELLER: Catch Posting (SHORT - 1-2 lines each)
    |--------------------------------------------------------------------------
    */

    /**
     * Ask fish type - SHORT.
     */
    public static function askFishType(): array
    {
        $popular = FishType::active()
            ->orderByDesc('popularity')
            ->limit(8)
            ->get();

        $rows = $popular->map(fn($f) => [
            'id' => 'fish_' . $f->id,
            'title' => self::t($f->emoji . ' ' . $f->name_en),
            'description' => $f->name_ml,
        ])->toArray();

        $rows[] = ['id' => 'fish_more', 'title' => '📋 More...', 'description' => 'All categories'];
        $rows[] = ['id' => 'fish_other', 'title' => '✏️ Other', 'description' => 'Type name'];

        return [
            'type' => 'list',
            'body' => "🐟 *Enthu meen?*\nWhich fish?",
            'button' => 'Select',
            'sections' => [['title' => '🐟 Fish', 'rows' => array_slice($rows, 0, 10)]],
        ];
    }

    /**
     * Ask quantity - SHORT with 3 buttons.
     */
    public static function askQuantity(?FishType $fishType = null): array
    {
        $name = $fishType?->display_name ?? 'Fish';

        return [
            'type' => 'buttons',
            'body' => "📦 *{$name}*\nQuantity?",
            'buttons' => [
                ['id' => 'qty_5_10', 'title' => '5-10 kg'],
                ['id' => 'qty_10_25', 'title' => '10-25 kg'],
                ['id' => 'qty_25_plus', 'title' => '25+ kg'],
            ],
        ];
    }

    /**
     * Ask price - ONE LINE.
     */
    public static function askPrice(?FishType $fishType = null): array
    {
        $name = $fishType?->name_en ?? 'Fish';

        return [
            'type' => 'text',
            'body' => "💰 *{$name}* - ₹/kg?",
        ];
    }

    /**
     * Ask photo - ONE LINE.
     */
    public static function askPhoto(?FishType $fishType = null): array
    {
        return [
            'type' => 'buttons',
            'body' => "📸 Photo ayakkuka:",
            'buttons' => [
                ['id' => 'skip_photo', 'title' => '⏭️ Skip'],
            ],
        ];
    }

    /**
     * Catch posted success - with subscriber count (social proof).
     */
    public static function catchPostedSuccess(FishCatch $catch, int $subscriberCount): array
    {
        $fish = $catch->fishType;
        $alert = $subscriberCount > 0
            ? "📢 *{$subscriberCount}* subscribers-nu alert ayachittund!"
            : "📢 Waiting for nearby subscribers...";

        return [
            'type' => 'buttons',
            'body' => "✅ *Posted!* 🐟\n\n" .
                "{$fish->emoji} *{$fish->name_en}* • {$catch->quantity_display} • {$catch->price_display}\n\n" .
                "{$alert}",
            'buttons' => [
                ['id' => 'add_another', 'title' => '🐟 Add Another'],
                ['id' => 'view_catches', 'title' => '📋 My Catches'],
                ['id' => 'main_menu', 'title' => '✅ Done'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | CUSTOMER: Alert Messages (SRS Format + Social Proof)
    |--------------------------------------------------------------------------
    */

    /**
     * Build alert caption for image message.
     *
     * SRS Section 2.5.2 format:
     * 🐟 PACHA MEEN ALERT!
     * പച്ച [Fish Name] just arrived!
     * 📍 [Seller], [Location]
     * ⏰ [X] mins ago
     * 📦 ~[Quantity] kg
     * 💰 ₹[Price]/kg
     * ⭐ [Rating]
     * 👥 [X] people coming! (PM-019)
     *
     * @srs-ref PM-017 Include all info
     * @srs-ref PM-019 Social proof
     */
    public static function buildAlertCaption(FishCatch $catch, FishAlert $alert): string
    {
        $fish = $catch->fishType;
        $seller = $catch->seller;

        $lines = [
            "🐟 *PACHA MEEN ALERT!*",
            "പച്ച {$fish->name_ml} just arrived!",
            "",
            "📍 *{$seller->business_name}*",
            "{$seller->location_display}",
            "",
            "⏰ {$catch->freshness_display}",
            "📦 {$catch->quantity_display}",
            "💰 *{$catch->price_display}*",
        ];

        // Rating
        if ($seller->rating_count > 0) {
            $lines[] = "⭐ {$seller->short_rating}";
        }

        // Distance
        if ($alert->distance_km) {
            $lines[] = "🚗 {$alert->distance_display} away";
        }

        // PM-019: Social proof - show after 5+ people coming
        $coming = $catch->customers_coming ?? 0;
        if ($coming >= 5) {
            $lines[] = "";
            $lines[] = "👥 *{$coming} people already coming!*";
        } elseif ($coming > 0) {
            $lines[] = "";
            $lines[] = "👥 {$coming} people coming";
        }

        return implode("\n", $lines);
    }

    /**
     * Alert buttons (sent separately after image).
     *
     * @srs-ref PM-018 Buttons: I'm Coming, Message Seller, Not Today
     */
    public static function alertButtons(FishCatch $catch, FishAlert $alert): array
    {
        return [
            'type' => 'buttons',
            'body' => "👆 Fresh catch above! Tap to respond:",
            'buttons' => [
                ['id' => "fish_coming_{$catch->id}_{$alert->id}", 'title' => "🏃 I'm Coming!"],
                ['id' => "fish_message_{$catch->id}_{$alert->id}", 'title' => '💬 Message'],
                ['id' => "fish_dismiss_{$catch->id}_{$alert->id}", 'title' => '❌ Not Today'],
            ],
        ];
    }

    /**
     * New catch alert (when no photo - full message with buttons).
     */
    public static function newCatchAlert(FishCatch $catch, FishAlert $alert): array
    {
        $fish = $catch->fishType;
        $seller = $catch->seller;

        // Build body
        $lines = [
            "🐟 *PACHA MEEN ALERT!*",
            "",
            "{$fish->emoji} *{$fish->name_ml}*",
            "({$fish->name_en})",
            "",
            "💰 *{$catch->price_display}*",
            "📦 {$catch->quantity_display}",
            "⏰ {$catch->freshness_display}",
            "",
            "📍 *{$seller->business_name}*",
            "{$seller->location_display}",
        ];

        // Rating
        if ($seller->rating_count > 0) {
            $lines[] = "⭐ {$seller->short_rating}";
        }

        // Distance
        if ($alert->distance_km) {
            $lines[] = "🚗 {$alert->distance_display} away";
        }

        // PM-019: Social proof
        $coming = $catch->customers_coming ?? 0;
        if ($coming >= 5) {
            $lines[] = "";
            $lines[] = "👥 *{$coming} people already coming!*";
        } elseif ($coming > 0) {
            $lines[] = "";
            $lines[] = "👥 {$coming} coming";
        }

        return [
            'type' => 'buttons',
            'body' => implode("\n", $lines),
            'buttons' => [
                ['id' => "fish_coming_{$catch->id}_{$alert->id}", 'title' => "🏃 I'm Coming!"],
                ['id' => "fish_location_{$catch->id}_{$alert->id}", 'title' => '📍 Location'],
                ['id' => "fish_dismiss_{$catch->id}_{$alert->id}", 'title' => '❌ Not Today'],
            ],
        ];
    }

    /**
     * Low stock alert - URGENT.
     */
    public static function lowStockAlert(FishCatch $catch, FishAlert $alert): array
    {
        $coming = $catch->customers_coming ?? 0;
        $urgency = $coming > 0 ? "🏃 *{$coming} people already went!*\n" : "";

        return [
            'type' => 'buttons',
            'body' => "⚠️ *STOCK KURAVANU!*\n\n" .
                "{$catch->fishType->emoji} *{$catch->fishType->name_ml}*\n" .
                "📍 {$catch->seller->business_name}\n\n" .
                "{$urgency}" .
                "Vegam varoo! ⏰",
            'buttons' => [
                ['id' => "fish_coming_{$catch->id}_{$alert->id}", 'title' => "🏃 I'm Going!"],
                ['id' => "fish_location_{$catch->id}_{$alert->id}", 'title' => '📍 Location'],
            ],
        ];
    }

    /**
     * Coming confirmation to customer.
     */
    public static function comingConfirmation(FishCatch $catch): array
    {
        $seller = $catch->seller;

        return [
            'type' => 'buttons',
            'body' => "🏃 *Ningal pokunnu!*\n\n" .
                "Seller-ne ariyichu.\n\n" .
                "📍 {$seller->business_name}\n" .
                "📞 {$seller->user->phone}\n\n" .
                "Safe journey! 🚗",
            'buttons' => [
                ['id' => "fish_share_{$catch->id}", 'title' => '📤 Share'],
                ['id' => "fish_location_{$catch->id}_0", 'title' => '📍 Directions'],
            ],
        ];
    }

    /**
     * Notify seller when customer is coming.
     */
    public static function sellerComingNotification(
        FishCatch $catch,
        User $customer,
        int $totalComing = 1,
        ?float $distance = null
    ): array {
        $phone = $customer->phone ?? '';
        $masked = strlen($phone) > 6 ? substr($phone, 0, -4) . '****' : $phone;

        $distText = '';
        if ($distance !== null) {
            $distText = $distance < 1
                ? "\n📍 " . round($distance * 1000) . "m away"
                : "\n📍 " . round($distance, 1) . " km away";
        }

        $totalText = $totalComing > 1 ? "\n\n👥 *Total {$totalComing} coming!*" : "";

        return [
            'type' => 'buttons',
            'body' => "🏃 *Customer varunnu!*\n\n" .
                "{$catch->fishType->emoji} {$catch->fishType->name_ml}\n\n" .
                "👤 +{$masked}" .
                $distText .
                $totalText,
            'buttons' => [
                ['id' => 'fish_update_stock', 'title' => '📦 Update Stock'],
                ['id' => 'fish_my_catches', 'title' => '📋 My Catches'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | STOCK Updates (SHORT - 2 lines max)
    |--------------------------------------------------------------------------
    */

    /**
     * Stock update options.
     */
    public static function stockUpdateOptions(FishCatch $catch): array
    {
        return [
            'type' => 'buttons',
            'body' => "{$catch->fishType->emoji} *{$catch->fishType->name_ml}*\nStatus?",
            'buttons' => [
                ['id' => 'status_available', 'title' => '✅ Available'],
                ['id' => 'status_low', 'title' => '⚠️ Low Stock'],
                ['id' => 'status_sold', 'title' => '❌ Sold Out'],
            ],
        ];
    }

    /**
     * Stock updated.
     */
    public static function stockUpdated(FishCatch $catch): array
    {
        return [
            'type' => 'buttons',
            'body' => "✅ Updated: {$catch->fishType->emoji} {$catch->status->display()}",
            'buttons' => [
                ['id' => 'fish_update_stock', 'title' => '📦 Update More'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Select catch for update.
     */
    public static function selectCatchForUpdate(Collection $catches): array
    {
        if ($catches->isEmpty()) {
            return [
                'type' => 'buttons',
                'body' => "📋 No active catches.\nPost new fish!",
                'buttons' => [
                    ['id' => 'fish_post_catch', 'title' => '🐟 Post Catch'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
            ];
        }

        $rows = $catches->take(9)->map(fn($c) => [
            'id' => 'catch_' . $c->id,
            'title' => self::t($c->fishType->emoji . ' ' . $c->fishType->name_en),
            'description' => "{$c->price_display} • {$c->status->display()}",
        ])->toArray();

        return [
            'type' => 'list',
            'body' => "📦 Select catch to update:",
            'button' => 'Select',
            'sections' => [['title' => 'Active', 'rows' => $rows]],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | SUBSCRIPTION (SHORT - 2-3 lines)
    |--------------------------------------------------------------------------
    */

    /**
     * Subscribe welcome.
     */
    public static function subscriptionWelcome(): array
    {
        return [
            'type' => 'buttons',
            'body' => "🐟 *Fish Alerts*\n\nGet notified when fresh fish arrives nearby!",
            'buttons' => [
                ['id' => 'continue_subscribe', 'title' => '✅ Setup'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Ask location for subscription.
     */
    public static function askSubscriptionLocation(): array
    {
        return [
            'type' => 'text',
            'body' => "📍 Share your location:\n📎 → Location",
        ];
    }

    /**
     * Ask alert radius.
     */
    public static function askAlertRadius(): array
    {
        return [
            'type' => 'buttons',
            'body' => "📍 Alert distance?",
            'buttons' => [
                ['id' => 'radius_3', 'title' => '3 km'],
                ['id' => 'radius_5', 'title' => '5 km ⭐'],
                ['id' => 'radius_10', 'title' => '10 km'],
            ],
        ];
    }

    /**
     * Ask fish preferences.
     */
    public static function askFishPreferences(): array
    {
        return [
            'type' => 'buttons',
            'body' => "🐟 Which fish alerts?",
            'buttons' => [
                ['id' => 'pref_all', 'title' => '🐟 All Fish'],
                ['id' => 'pref_select', 'title' => '✅ Select Types'],
            ],
        ];
    }

    /**
     * Ask alert frequency.
     * @srs-ref PM-020 Time preferences
     */
    public static function askAlertFrequency(): array
    {
        return [
            'type' => 'list',
            'body' => "🔔 Alert timing?",
            'button' => 'Select',
            'sections' => [[
                'title' => 'Frequency',
                'rows' => [
                    ['id' => 'fish_freq_immediate', 'title' => '🔔 Immediate', 'description' => 'Instant alerts'],
                    ['id' => 'fish_freq_morning_only', 'title' => '🌅 Morning (6-8 AM)', 'description' => 'Morning batch'],
                    ['id' => 'fish_freq_twice_daily', 'title' => '☀️ Twice Daily', 'description' => '6 AM & 4 PM'],
                    ['id' => 'fish_freq_weekly_digest', 'title' => '📅 Weekly', 'description' => 'Sunday summary'],
                ],
            ]],
        ];
    }

    /**
     * Subscription created.
     */
    public static function subscriptionCreated(FishSubscription $subscription): array
    {
        return [
            'type' => 'buttons',
            'body' => "🎉 *Subscribed!*\n\n" .
                "📍 {$subscription->radius_km} km\n" .
                "🔔 {$subscription->frequency_display}\n\n" .
                "Fresh fish varunpol notify cheyyum!",
            'buttons' => [
                ['id' => 'fish_browse', 'title' => '🔍 Browse Now'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | BROWSE
    |--------------------------------------------------------------------------
    */

    /**
     * Browse results.
     */
    public static function browseResults(Collection $catches, string $location = 'nearby'): array
    {
        if ($catches->isEmpty()) {
            return [
                'type' => 'buttons',
                'body' => "🐟 No fresh fish {$location} now.\nSubscribe for alerts!",
                'buttons' => [
                    ['id' => 'fish_subscribe', 'title' => '🔔 Subscribe'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
            ];
        }

        $rows = $catches->take(9)->map(fn($c) => [
            'id' => 'catch_' . $c->id,
            'title' => self::t($c->fishType->emoji . ' ' . $c->fishType->name_en),
            'description' => "{$c->price_display} • {$c->freshness_display}",
        ])->toArray();

        return [
            'type' => 'list',
            'body' => "🐟 {$catches->count()} fish {$location}:",
            'button' => 'View',
            'sections' => [['title' => 'Available', 'rows' => $rows]],
        ];
    }

    /**
     * Catch detail.
     */
    public static function catchDetail(FishCatch $catch, ?float $distance = null): array
    {
        $fish = $catch->fishType;
        $seller = $catch->seller;

        $coming = $catch->customers_coming ?? 0;
        $social = $coming > 0 ? "\n👥 {$coming} people coming" : "";

        $dist = $distance
            ? "\n🚗 " . ($distance < 1 ? round($distance * 1000) . 'm' : round($distance, 1) . ' km')
            : "";

        $body = "{$fish->emoji} *{$fish->name_ml}*\n" .
            "({$fish->name_en})\n\n" .
            "💰 *{$catch->price_display}*\n" .
            "📦 {$catch->quantity_display}\n" .
            "⏰ {$catch->freshness_display}" .
            $social . "\n\n" .
            "📍 *{$seller->business_name}*\n" .
            "{$seller->location_display}" .
            $dist;

        $message = [
            'type' => 'buttons',
            'body' => $body,
            'buttons' => [
                ['id' => "fish_coming_{$catch->id}_0", 'title' => "🏃 I'm Coming!"],
                ['id' => "fish_location_{$catch->id}_0", 'title' => '📍 Location'],
            ],
        ];

        if ($catch->photo_url) {
            $message['image'] = $catch->photo_url;
        }

        return $message;
    }

    /*
    |--------------------------------------------------------------------------
    | SELLER REGISTRATION (SHORT)
    |--------------------------------------------------------------------------
    */

    /**
     * Seller registration welcome.
     */
    public static function sellerRegistrationWelcome(): array
    {
        return [
            'type' => 'buttons',
            'body' => "🐟 *Pacha Meen Seller*\n\nRegister to post fish & reach customers!",
            'buttons' => [
                ['id' => 'continue_registration', 'title' => '✅ Register'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Ask seller type.
     */
    public static function askSellerType(): array
    {
        return [
            'type' => 'buttons',
            'body' => "🐟 Ningal enthu type?",
            'buttons' => [
                ['id' => 'type_fisherman', 'title' => '🚣 Fisherman'],
                ['id' => 'type_shop', 'title' => '🏪 Fish Shop'],
                ['id' => 'type_vendor', 'title' => '⚓ Vendor'],
            ],
        ];
    }

    /**
     * Ask business name.
     */
    public static function askBusinessName(): array
    {
        return [
            'type' => 'text',
            'body' => "📝 Business/Stall name?",
        ];
    }

    /**
     * Ask seller location.
     */
    public static function askSellerLocation(): array
    {
        return [
            'type' => 'text',
            'body' => "📍 Location share cheyyuka:\n📎 → Location",
        ];
    }

    /**
     * Seller registration complete.
     */
    public static function sellerRegistrationComplete(FishSeller $seller): array
    {
        return [
            'type' => 'buttons',
            'body' => "✅ *Registered!*\n\n" .
                "Welcome, {$seller->business_name}! 🐟\n\n" .
                "Post your fresh catch now!",
            'buttons' => [
                ['id' => 'fish_post_catch', 'title' => '🐟 Post Catch'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | MENUS
    |--------------------------------------------------------------------------
    */

    /**
     * Fish seller menu.
     */
    public static function fishSellerMenu(FishSeller $seller): array
    {
        $active = $seller->getActiveCatchCount();

        return [
            'type' => 'list',
            'body' => "🐟 {$seller->business_name}\n📊 {$active} active • {$seller->short_rating}",
            'button' => 'Select',
            'sections' => [[
                'title' => 'Actions',
                'rows' => [
                    ['id' => 'fish_post_catch', 'title' => '🐟 Post Catch', 'description' => 'New fish'],
                    ['id' => 'fish_update_stock', 'title' => '📦 Update Stock', 'description' => 'Change status'],
                    ['id' => 'fish_my_catches', 'title' => '📋 My Catches', 'description' => 'View active'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => ''],
                ],
            ]],
        ];
    }

    /**
     * Customer fish menu.
     */
    public static function customerFishMenu(bool $hasSubscription = false): array
    {
        $rows = [
            ['id' => 'fish_browse', 'title' => '🔍 Browse Fish', 'description' => 'See nearby'],
        ];

        if ($hasSubscription) {
            $rows[] = ['id' => 'fish_manage', 'title' => '⚙️ Manage Alerts', 'description' => 'Edit subscription'];
        } else {
            $rows[] = ['id' => 'fish_subscribe', 'title' => '🔔 Get Alerts', 'description' => 'Subscribe'];
        }

        $rows[] = ['id' => 'main_menu', 'title' => '🏠 Menu', 'description' => ''];

        return [
            'type' => 'list',
            'body' => "🐟 Pacha Meen",
            'button' => 'Select',
            'sections' => [['title' => 'Options', 'rows' => $rows]],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | ERRORS (SHORT)
    |--------------------------------------------------------------------------
    */

    public static function errorInvalidPrice(): array
    {
        return ['type' => 'text', 'body' => "❌ Invalid price. Enter number only (eg: 180)"];
    }

    public static function errorLocationRequired(): array
    {
        return ['type' => 'text', 'body' => "📍 Location required. Tap 📎 → Location"];
    }

    public static function errorNotFishSeller(): array
    {
        return [
            'type' => 'buttons',
            'body' => "❌ Fish seller alla.\nRegister first!",
            'buttons' => [
                ['id' => 'fish_seller_register', 'title' => '✅ Register'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    public static function errorDailyLimitReached(): array
    {
        return [
            'type' => 'buttons',
            'body' => "⚠️ Daily limit reached.\nTry tomorrow!",
            'buttons' => [
                ['id' => 'fish_my_catches', 'title' => '📋 My Catches'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    public static function errorInvalidFishType(): array
    {
        return [
            'type' => 'buttons',
            'body' => "❌ Invalid fish. Select from list.",
            'buttons' => [
                ['id' => 'retry', 'title' => '🔄 Retry'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }
}