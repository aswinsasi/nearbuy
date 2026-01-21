<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\NotificationFrequency;
use App\Enums\ShopCategory;
use App\Models\ConversationSession;
use App\Services\WhatsApp\Messages\MessageTemplates;

/**
 * Settings Flow Handler.
 *
 * Handles user settings including:
 * - Location updates
 * - Notification preferences (shop owners)
 * - Shop profile management (shop owners)
 * - Account information
 */
class SettingsFlowHandler extends AbstractFlowHandler
{
    /**
     * Settings steps.
     */
    protected const STEP_SHOW_SETTINGS = 'show_settings';
    protected const STEP_UPDATE_LOCATION = 'update_location';
    protected const STEP_NOTIFICATION_PREFS = 'notification_prefs';
    protected const STEP_SHOP_PROFILE = 'shop_profile';
    protected const STEP_EDIT_SHOP_NAME = 'edit_shop_name';
    protected const STEP_EDIT_SHOP_CATEGORY = 'edit_shop_category';
    protected const STEP_EDIT_SHOP_ADDRESS = 'edit_shop_address';

    protected function getFlowType(): FlowType
    {
        return FlowType::SETTINGS;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_SHOW_SETTINGS,
            self::STEP_UPDATE_LOCATION,
            self::STEP_NOTIFICATION_PREFS,
            self::STEP_SHOP_PROFILE,
            self::STEP_EDIT_SHOP_NAME,
            self::STEP_EDIT_SHOP_CATEGORY,
            self::STEP_EDIT_SHOP_ADDRESS,
        ];
    }

    /**
     * Start the settings flow.
     */
    public function start(ConversationSession $session): void
    {
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::SETTINGS,
            self::STEP_SHOW_SETTINGS
        );

        $this->showSettingsMenu($session);

        $this->logInfo('Settings flow started', [
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle common navigation (menu, cancel, etc.)
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = $session->current_step;

        match ($step) {
            self::STEP_SHOW_SETTINGS => $this->handleSettingsSelection($message, $session),
            self::STEP_UPDATE_LOCATION => $this->handleLocationUpdate($message, $session),
            self::STEP_NOTIFICATION_PREFS => $this->handleNotificationSelection($message, $session),
            self::STEP_SHOP_PROFILE => $this->handleShopProfileSelection($message, $session),
            self::STEP_EDIT_SHOP_NAME => $this->handleShopNameInput($message, $session),
            self::STEP_EDIT_SHOP_CATEGORY => $this->handleShopCategorySelection($message, $session),
            self::STEP_EDIT_SHOP_ADDRESS => $this->handleShopAddressInput($message, $session),
            default => $this->showSettingsMenu($session),
        };
    }

    /**
     * Handle invalid input.
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $this->showSettingsMenu($session);
    }

    /**
     * Get expected input type.
     */
    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            self::STEP_UPDATE_LOCATION, self::STEP_EDIT_SHOP_ADDRESS => 'location',
            self::STEP_EDIT_SHOP_NAME => 'text',
            self::STEP_EDIT_SHOP_CATEGORY => 'list',
            default => 'button',
        };
    }

    /**
     * Re-prompt current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $this->showSettingsMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Settings Menu
    |--------------------------------------------------------------------------
    */

    protected function showSettingsMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $isShopOwner = $user?->isShopOwner() ?? false;

        $rows = [
            [
                'id' => 'update_location',
                'title' => '📍 Update Location',
                'description' => 'Change your current location',
            ],
            [
                'id' => 'view_profile',
                'title' => '👤 View Profile',
                'description' => 'See your account details',
            ],
        ];

        // Add shop-specific options
        if ($isShopOwner) {
            $rows[] = [
                'id' => 'shop_profile',
                'title' => '🏪 Shop Profile',
                'description' => 'Manage your shop details',
            ];
            $rows[] = [
                'id' => 'notification_prefs',
                'title' => '🔔 Notifications',
                'description' => 'Product request alerts',
            ];
        }

        $sections = [
            [
                'title' => 'Settings',
                'rows' => $rows,
            ],
        ];

        $this->sendListWithFooter(
            $session->phone,
            "⚙️ *Settings*\n\nManage your NearBuy preferences:",
            '⚙️ Select Option',
            $sections,
            'Settings'
        );

        $this->nextStep($session, self::STEP_SHOW_SETTINGS);
    }

    protected function handleSettingsSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        match ($selection) {
            'update_location' => $this->askLocationUpdate($session),
            'view_profile' => $this->showUserProfile($session),
            'shop_profile' => $this->showShopProfile($session),
            'notification_prefs' => $this->showNotificationPrefs($session),
            default => $this->showSettingsMenu($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Location Update
    |--------------------------------------------------------------------------
    */

    protected function askLocationUpdate(ConversationSession $session): void
    {
        $this->requestLocation(
            $session->phone,
            "📍 *Update Location*\n\nShare your new location to update your profile."
        );

        $this->sendButtonsWithMenu(
            $session->phone,
            "Tap the location button to share your current location.",
            []
        );

        $this->nextStep($session, self::STEP_UPDATE_LOCATION);
    }

    protected function handleLocationUpdate(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isLocation()) {
            $this->sendTextWithMenu(
                $session->phone,
                "📍 Please share your location using the button."
            );
            return;
        }

        $coords = $message->getCoordinates();

        if (!$coords) {
            $this->askLocationUpdate($session);
            return;
        }

        $user = $this->getUser($session);

        if ($user) {
            $user->update([
                'latitude' => $coords['latitude'],
                'longitude' => $coords['longitude'],
            ]);

            // Also update shop location if shop owner
            if ($user->isShopOwner() && $user->shop) {
                $user->shop->update([
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                ]);
            }
        }

        $this->sendButtonsWithMenu(
            $session->phone,
            "✅ *Location Updated!*\n\nYour location has been updated successfully.",
            [['id' => 'back_settings', 'title' => '⬅️ Back to Settings']]
        );

        $this->logInfo('User location updated', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        $this->nextStep($session, self::STEP_SHOW_SETTINGS);
    }

    /*
    |--------------------------------------------------------------------------
    | User Profile
    |--------------------------------------------------------------------------
    */

    protected function showUserProfile(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sendTextWithMenu($session->phone, "❌ User profile not found.");
            $this->goToMainMenu($session);
            return;
        }

        $userType = $user->isShopOwner() ? '🏪 Shop Owner' : '👤 Customer';
        $location = ($user->latitude && $user->longitude) ? '✅ Set' : '❌ Not set';
        $registeredAt = $user->registered_at?->format('d M Y') ?? 'N/A';

        $message = "👤 *Your Profile*\n\n"
            . "📛 *Name:* {$user->name}\n"
            . "📱 *Phone:* +{$user->phone}\n"
            . "🏷️ *Type:* {$userType}\n"
            . "📍 *Location:* {$location}\n"
            . "📅 *Member since:* {$registeredAt}";

        $buttons = [
            ['id' => 'update_location', 'title' => '📍 Update Location'],
        ];

        if ($user->isShopOwner()) {
            $buttons[] = ['id' => 'shop_profile', 'title' => '🏪 Shop Profile'];
        }

        $this->sendButtonsWithMenu(
            $session->phone,
            $message,
            $buttons,
            '👤 Profile'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Profile
    |--------------------------------------------------------------------------
    */

    protected function showShopProfile(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user?->isShopOwner() || !$user->shop) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ Shop profile not available. This feature is for shop owners only."
            );
            $this->showSettingsMenu($session);
            return;
        }

        $shop = $user->shop;
        $category = $shop->category instanceof ShopCategory
            ? $shop->category->label()
            : ($shop->category ?? 'Not set');

        $message = "🏪 *Shop Profile*\n\n"
            . "📛 *Name:* {$shop->shop_name}\n"
            . "📂 *Category:* {$category}\n"
            . "📍 *Address:* " . ($shop->address ?? 'Not set') . "\n"
            . "📊 *Status:* " . ($shop->is_active ? '✅ Active' : '❌ Inactive');

        $rows = [
            [
                'id' => 'edit_shop_name',
                'title' => '✏️ Edit Shop Name',
                'description' => "Current: {$shop->shop_name}",
            ],
            [
                'id' => 'edit_shop_category',
                'title' => '📂 Change Category',
                'description' => "Current: {$category}",
            ],
            [
                'id' => 'edit_shop_address',
                'title' => '📍 Update Shop Location',
                'description' => 'Change shop address',
            ],
            [
                'id' => 'back_settings',
                'title' => '⬅️ Back to Settings',
                'description' => 'Return to settings menu',
            ],
        ];

        $sections = [['title' => 'Edit Options', 'rows' => $rows]];

        // First send the profile info
        $this->sendTextWithMenu($session->phone, $message);

        // Then send the edit options
        $this->sendListWithFooter(
            $session->phone,
            "What would you like to update?",
            '✏️ Edit Shop',
            $sections,
            '🏪 Shop Profile'
        );

        $this->nextStep($session, self::STEP_SHOP_PROFILE);
    }

    protected function handleShopProfileSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        match ($selection) {
            'edit_shop_name' => $this->askShopName($session),
            'edit_shop_category' => $this->askShopCategory($session),
            'edit_shop_address' => $this->askShopAddress($session),
            'back_settings' => $this->showSettingsMenu($session),
            default => $this->showShopProfile($session),
        };
    }

    protected function askShopName(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $currentName = $user?->shop?->shop_name ?? 'N/A';

        $this->sendButtonsWithMenu(
            $session->phone,
            "✏️ *Edit Shop Name*\n\nCurrent name: *{$currentName}*\n\nType your new shop name:",
            []
        );

        $this->nextStep($session, self::STEP_EDIT_SHOP_NAME);
    }

    protected function handleShopNameInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->askShopName($session);
            return;
        }

        $newName = trim($message->text ?? '');

        if (mb_strlen($newName) < 2 || mb_strlen($newName) > 100) {
            $this->sendTextWithMenu(
                $session->phone,
                "⚠️ Shop name must be between 2 and 100 characters. Please try again."
            );
            return;
        }

        $user = $this->getUser($session);

        if ($user?->shop) {
            $user->shop->update(['shop_name' => $newName]);

            $this->sendButtonsWithMenu(
                $session->phone,
                "✅ *Shop name updated!*\n\nNew name: *{$newName}*",
                [['id' => 'shop_profile', 'title' => '🏪 Back to Shop Profile']]
            );

            $this->logInfo('Shop name updated', [
                'shop_id' => $user->shop->id,
                'phone' => $this->maskPhone($session->phone),
            ]);
        }

        $this->nextStep($session, self::STEP_SHOP_PROFILE);
    }

    protected function askShopCategory(ConversationSession $session): void
    {
        $sections = ShopCategory::toListSections();

        $this->sendListWithFooter(
            $session->phone,
            "📂 *Change Shop Category*\n\nSelect your new shop category:",
            '📂 Select Category',
            $sections,
            'Edit Category'
        );

        $this->nextStep($session, self::STEP_EDIT_SHOP_CATEGORY);
    }

    protected function handleShopCategorySelection(IncomingMessage $message, ConversationSession $session): void
    {
        $categoryValue = $this->getSelectionId($message);
        $category = ShopCategory::tryFrom(strtolower($categoryValue));

        if (!$category) {
            $this->sendTextWithMenu(
                $session->phone,
                "⚠️ Invalid category selected. Please try again."
            );
            $this->askShopCategory($session);
            return;
        }

        $user = $this->getUser($session);

        if ($user?->shop) {
            $user->shop->update(['category' => $category]);

            $this->sendButtonsWithMenu(
                $session->phone,
                "✅ *Category updated!*\n\nNew category: *{$category->label()}*",
                [['id' => 'shop_profile', 'title' => '🏪 Back to Shop Profile']]
            );

            $this->logInfo('Shop category updated', [
                'shop_id' => $user->shop->id,
                'category' => $category->value,
                'phone' => $this->maskPhone($session->phone),
            ]);
        }

        $this->nextStep($session, self::STEP_SHOP_PROFILE);
    }

    protected function askShopAddress(ConversationSession $session): void
    {
        $this->requestLocation(
            $session->phone,
            "📍 *Update Shop Location*\n\nShare your shop's location to update the address."
        );

        $this->sendButtonsWithMenu(
            $session->phone,
            "Tap the location button to share your shop's location.",
            []
        );

        $this->nextStep($session, self::STEP_EDIT_SHOP_ADDRESS);
    }

    protected function handleShopAddressInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isLocation()) {
            $this->sendTextWithMenu(
                $session->phone,
                "📍 Please share your shop location using the location button."
            );
            return;
        }

        $coords = $message->getCoordinates();

        if (!$coords) {
            $this->askShopAddress($session);
            return;
        }

        $user = $this->getUser($session);

        if ($user?->shop) {
            $user->shop->update([
                'latitude' => $coords['latitude'],
                'longitude' => $coords['longitude'],
            ]);

            $this->sendButtonsWithMenu(
                $session->phone,
                "✅ *Shop location updated!*\n\nYour shop's location has been updated on the map.",
                [['id' => 'shop_profile', 'title' => '🏪 Back to Shop Profile']]
            );

            $this->logInfo('Shop location updated', [
                'shop_id' => $user->shop->id,
                'phone' => $this->maskPhone($session->phone),
            ]);
        }

        $this->nextStep($session, self::STEP_SHOP_PROFILE);
    }

    /*
    |--------------------------------------------------------------------------
    | Notification Preferences
    |--------------------------------------------------------------------------
    */

    protected function showNotificationPrefs(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user?->isShopOwner() || !$user->shop) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ Notification settings are only available for shop owners."
            );
            $this->showSettingsMenu($session);
            return;
        }

        $shop = $user->shop;
        $currentFreq = 'Instant';
        if ($shop->notification_frequency instanceof NotificationFrequency) {
            $currentFreq = method_exists($shop->notification_frequency, 'label') 
                ? $shop->notification_frequency->label() 
                : ucfirst($shop->notification_frequency->value);
        }

        $rows = [
            [
                'id' => 'notif_immediate',
                'title' => '⚡ Immediate',
                'description' => 'Get notified instantly',
            ],
            [
                'id' => 'notif_2hours',
                'title' => '🕐 Every 2 Hours',
                'description' => 'Batch notifications every 2 hours',
            ],
            [
                'id' => 'notif_twice_daily',
                'title' => '🌅 Twice Daily',
                'description' => 'At 9 AM and 5 PM',
            ],
            [
                'id' => 'notif_daily',
                'title' => '📅 Daily',
                'description' => 'Daily summary at 9 AM',
            ],
        ];

        $sections = [['title' => 'Notification Frequency', 'rows' => $rows]];

        $this->sendListWithFooter(
            $session->phone,
            "🔔 *Notification Preferences*\n\nCurrent setting: *{$currentFreq}*\n\nHow often would you like to receive product request notifications?",
            '🔔 Select Frequency',
            $sections,
            'Notifications'
        );

        $this->nextStep($session, self::STEP_NOTIFICATION_PREFS);
    }

    protected function handleNotificationSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        $frequency = match ($selection) {
            'notif_immediate' => NotificationFrequency::IMMEDIATE,
            'notif_2hours' => NotificationFrequency::EVERY_2_HOURS,
            'notif_twice_daily' => NotificationFrequency::TWICE_DAILY,
            'notif_daily' => NotificationFrequency::DAILY,
            'back_settings' => null,
            default => null,
        };

        if ($selection === 'back_settings') {
            $this->showSettingsMenu($session);
            return;
        }

        if (!$frequency) {
            $this->showNotificationPrefs($session);
            return;
        }

        $user = $this->getUser($session);

        if ($user?->shop) {
            $user->shop->update(['notification_frequency' => $frequency]);

            $freqLabel = method_exists($frequency, 'label') 
                ? $frequency->label() 
                : ucfirst($frequency->value);

            $this->sendButtonsWithMenu(
                $session->phone,
                "✅ *Notification preference updated!*\n\nYou'll now receive notifications: *{$freqLabel}*",
                [['id' => 'back_settings', 'title' => '⬅️ Back to Settings']]
            );

            $this->logInfo('Notification preference updated', [
                'shop_id' => $user->shop->id,
                'frequency' => $frequency->value,
                'phone' => $this->maskPhone($session->phone),
            ]);
        }

        $this->nextStep($session, self::STEP_SHOW_SETTINGS);
    }
}