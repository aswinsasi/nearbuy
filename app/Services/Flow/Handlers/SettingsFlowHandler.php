<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\NotificationFrequency;
use App\Enums\ShopCategory;
use App\Models\ConversationSession;
use App\Models\User;

/**
 * Settings Flow Handler - Clean, role-specific settings management.
 *
 * MAX 2 TAPS to change any setting!
 *
 * Main Menu shows role-specific options:
 * - 📍 Update Location (all users)
 * - 🔔 Notifications (all - different UI per role)
 * - 🏪 My Shop (shop owners)
 * - 🐟 Fish Seller Settings (fish sellers)
 * - 👷 Worker Settings (workers)
 * - 🗑️ Delete Account (all users)
 *
 * @srs-ref SRS Appendix 8.3 - Notification Frequency
 * @module Settings
 */
class SettingsFlowHandler extends AbstractFlowHandler
{
    // =========================================================================
    // STEPS
    // =========================================================================

    protected const STEP_MAIN = 'main';
    protected const STEP_LOCATION = 'location';
    protected const STEP_NOTIFICATION = 'notification';
    protected const STEP_SHOP_MENU = 'shop_menu';
    protected const STEP_SHOP_NAME = 'shop_name';
    protected const STEP_SHOP_CATEGORY = 'shop_category';
    protected const STEP_SHOP_LOCATION = 'shop_location';
    protected const STEP_FISH_MENU = 'fish_menu';
    protected const STEP_WORKER_MENU = 'worker_menu';
    protected const STEP_DELETE_CONFIRM = 'delete_confirm';

    protected function getFlowType(): FlowType
    {
        return FlowType::SETTINGS;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_MAIN,
            self::STEP_LOCATION,
            self::STEP_NOTIFICATION,
            self::STEP_SHOP_MENU,
            self::STEP_SHOP_NAME,
            self::STEP_SHOP_CATEGORY,
            self::STEP_SHOP_LOCATION,
            self::STEP_FISH_MENU,
            self::STEP_WORKER_MENU,
            self::STEP_DELETE_CONFIRM,
        ];
    }

    // =========================================================================
    // ENTRY POINTS
    // =========================================================================

    /**
     * Start settings flow.
     */
    public function start(ConversationSession $session): void
    {
        $this->sessionManager->setFlowStep($session, FlowType::SETTINGS, self::STEP_MAIN);
        $this->showMainMenu($session);
        $this->logInfo('Settings flow started', ['phone' => $this->maskPhone($session->phone)]);
    }

    /**
     * Direct entry to shop profile.
     */
    public function startShopProfile(ConversationSession $session): void
    {
        $this->sessionManager->setFlowStep($session, FlowType::SETTINGS, self::STEP_SHOP_MENU);
        $this->showShopMenu($session);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = $session->current_step;

        match ($step) {
            self::STEP_MAIN => $this->handleMainSelection($message, $session),
            self::STEP_LOCATION => $this->handleLocation($message, $session),
            self::STEP_NOTIFICATION => $this->handleNotification($message, $session),
            self::STEP_SHOP_MENU => $this->handleShopMenuSelection($message, $session),
            self::STEP_SHOP_NAME => $this->handleShopName($message, $session),
            self::STEP_SHOP_CATEGORY => $this->handleShopCategory($message, $session),
            self::STEP_SHOP_LOCATION => $this->handleShopLocation($message, $session),
            self::STEP_FISH_MENU => $this->handleFishMenu($message, $session),
            self::STEP_WORKER_MENU => $this->handleWorkerMenu($message, $session),
            self::STEP_DELETE_CONFIRM => $this->handleDeleteConfirm($message, $session),
            default => $this->showMainMenu($session),
        };
    }

    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $this->showMainMenu($session);
    }

    public function getExpectedInputType(string $step): string
    {
        return match ($step) {
            self::STEP_LOCATION, self::STEP_SHOP_LOCATION => 'location',
            self::STEP_SHOP_NAME => 'text',
            default => 'interactive',
        };
    }

    protected function promptCurrentStep(ConversationSession $session): void
    {
        $this->showMainMenu($session);
    }

    // =========================================================================
    // MAIN MENU - Role-specific options
    // =========================================================================

    protected function showMainMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $buttons = $this->buildMainMenuButtons($user);

        $message = "⚙️ *Settings*\n" .
            "*സെറ്റിംഗ്സ്*\n\n" .
            "Manage your NearBuy preferences:\n" .
            "_നിങ്ങളുടെ മുൻഗണനകൾ മാനേജ് ചെയ്യുക:_";

        // Use list for more than 3 options
        if (count($buttons) > 3) {
            $this->sendList(
                $session->phone,
                $message,
                '⚙️ Settings',
                [['title' => 'Options', 'rows' => $buttons]]
            );
        } else {
            $simpleButtons = array_map(fn($b) => [
                'id' => $b['id'],
                'title' => $b['title'],
            ], $buttons);

            $this->sendButtons($session->phone, $message, $simpleButtons);
        }

        $this->nextStep($session, self::STEP_MAIN);
    }

    protected function buildMainMenuButtons(?User $user): array
    {
        $buttons = [
            [
                'id' => 'set_location',
                'title' => '📍 Update Location',
                'description' => 'Change your current location',
            ],
            [
                'id' => 'set_notification',
                'title' => '🔔 Notifications',
                'description' => 'Manage alert preferences',
            ],
        ];

        // Role-specific options
        if ($user?->isShopOwner()) {
            $buttons[] = [
                'id' => 'set_shop',
                'title' => '🏪 My Shop',
                'description' => 'Manage shop profile',
            ];
        }

        if ($user?->isFishSeller()) {
            $buttons[] = [
                'id' => 'set_fish',
                'title' => '🐟 Fish Seller Settings',
                'description' => 'Manage fish selling',
            ];
        }

        if ($user?->isWorker()) {
            $buttons[] = [
                'id' => 'set_worker',
                'title' => '👷 Worker Settings',
                'description' => 'Manage work preferences',
            ];
        }

        // Delete account always at the end
        $buttons[] = [
            'id' => 'set_delete',
            'title' => '🗑️ Delete Account',
            'description' => 'Permanently delete your account',
        ];

        return $buttons;
    }

    protected function handleMainSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        match ($selection) {
            'set_location' => $this->askLocation($session),
            'set_notification' => $this->showNotificationSettings($session),
            'set_shop' => $this->showShopMenu($session),
            'set_fish' => $this->showFishMenu($session),
            'set_worker' => $this->showWorkerMenu($session),
            'set_delete' => $this->askDeleteConfirmation($session),
            default => $this->showMainMenu($session),
        };
    }

    // =========================================================================
    // LOCATION UPDATE (1 tap from menu)
    // =========================================================================

    protected function askLocation(ConversationSession $session): void
    {
        $this->requestLocation(
            $session->phone,
            "📍 *Update Location*\n" .
            "*ലൊക്കേഷൻ അപ്‌ഡേറ്റ് ചെയ്യുക*\n\n" .
            "Share your current location:\n" .
            "_നിങ്ങളുടെ ലൊക്കേഷൻ ഷെയർ ചെയ്യുക:_"
        );

        $this->nextStep($session, self::STEP_LOCATION);
    }

    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isLocation()) {
            $this->sendText($session->phone, "📍 Please share your location using the button.");
            return;
        }

        $coords = $message->getCoordinates();
        if (!$coords) {
            $this->askLocation($session);
            return;
        }

        $user = $this->getUser($session);
        if ($user) {
            $user->update([
                'latitude' => $coords['latitude'],
                'longitude' => $coords['longitude'],
            ]);

            // Update shop location too if shop owner
            if ($user->isShopOwner() && $user->shop) {
                $user->shop->update([
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                ]);
            }
        }

        $this->sendButtons(
            $session->phone,
            "✅ *Location Updated!*\n*ലൊക്കേഷൻ അപ്‌ഡേറ്റ് ആയി!*",
            [
                ['id' => 'back_settings', 'title' => '⬅️ Back'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );

        $this->logInfo('Location updated', ['phone' => $this->maskPhone($session->phone)]);
        $this->nextStep($session, self::STEP_MAIN);
    }

    // =========================================================================
    // NOTIFICATIONS (1 tap to see, 1 tap to change)
    // =========================================================================

    protected function showNotificationSettings(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if ($user?->isShopOwner()) {
            $this->showShopNotificationSettings($session, $user);
        } else {
            $this->showCustomerNotificationSettings($session, $user);
        }
    }

    /**
     * Shop owners get 4 frequency options.
     */
    protected function showShopNotificationSettings(ConversationSession $session, User $user): void
    {
        $shop = $user->shop;
        $current = $shop?->notification_frequency;
        $currentLabel = $current instanceof NotificationFrequency
            ? $current->display()
            : '🔔 Immediately';

        $message = "🔔 *Notification Frequency*\n" .
            "*നോട്ടിഫിക്കേഷൻ ഫ്രീക്വൻസി*\n\n" .
            "Current: *{$currentLabel}*\n\n" .
            "How often do you want product request alerts?\n" .
            "_എത്ര തവണ അലേർട്ടുകൾ വേണം?_";

        $this->sendList(
            $session->phone,
            $message,
            '🔔 Select Frequency',
            NotificationFrequency::toShopListSections()
        );

        $this->nextStep($session, self::STEP_NOTIFICATION);
    }

    /**
     * Customers get simple ON/OFF toggle.
     */
    protected function showCustomerNotificationSettings(ConversationSession $session, ?User $user): void
    {
        $current = $user?->notification_frequency ?? NotificationFrequency::IMMEDIATE;
        $isOn = $current !== NotificationFrequency::OFF;
        $statusText = $isOn ? '🔔 ON' : '🔕 OFF';

        $message = "🔔 *Notifications*\n" .
            "*നോട്ടിഫിക്കേഷൻ*\n\n" .
            "Current: *{$statusText}*\n\n" .
            "Receive deal alerts and updates?\n" .
            "_ഡീൽ അലേർട്ടുകൾ ലഭിക്കണോ?_";

        $this->sendButtons(
            $session->phone,
            $message,
            NotificationFrequency::toCustomerButtons()
        );

        $this->nextStep($session, self::STEP_NOTIFICATION);
    }

    protected function handleNotification(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);
        $frequency = NotificationFrequency::fromSelectionId($selection);

        if (!$frequency) {
            $this->showNotificationSettings($session);
            return;
        }

        $user = $this->getUser($session);

        if ($user?->isShopOwner() && $user->shop) {
            $user->shop->update(['notification_frequency' => $frequency]);
        } elseif ($user) {
            $user->update(['notification_frequency' => $frequency]);
        }

        $this->sendButtons(
            $session->phone,
            "✅ *Notifications Updated!*\n" .
            "*നോട്ടിഫിക്കേഷൻ അപ്‌ഡേറ്റ് ആയി!*\n\n" .
            "Set to: *{$frequency->display()}*",
            [
                ['id' => 'back_settings', 'title' => '⬅️ Back'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );

        $this->logInfo('Notification preference updated', [
            'frequency' => $frequency->value,
            'phone' => $this->maskPhone($session->phone),
        ]);

        $this->nextStep($session, self::STEP_MAIN);
    }

    // =========================================================================
    // SHOP SETTINGS (Shop owners only)
    // =========================================================================

    protected function showShopMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user?->isShopOwner() || !$user->shop) {
            $this->sendText($session->phone, "❌ Shop settings available for shop owners only.");
            $this->showMainMenu($session);
            return;
        }

        $shop = $user->shop;
        $category = $shop->category instanceof ShopCategory
            ? $shop->category->label()
            : ($shop->category ?? 'Not set');

        $message = "🏪 *My Shop*\n" .
            "*എന്റെ കട*\n\n" .
            "📛 *Name:* {$shop->shop_name}\n" .
            "📂 *Category:* {$category}\n" .
            "📍 *Location:* " . ($shop->latitude ? '✅ Set' : '❌ Not set') . "\n" .
            "📊 *Status:* " . ($shop->is_active ? '✅ Active' : '❌ Inactive');

        $this->sendList(
            $session->phone,
            $message,
            '✏️ Edit Shop',
            [[
                'title' => 'Edit Options',
                'rows' => [
                    ['id' => 'shop_name', 'title' => '✏️ Edit Name', 'description' => $shop->shop_name],
                    ['id' => 'shop_category', 'title' => '📂 Change Category', 'description' => $category],
                    ['id' => 'shop_location', 'title' => '📍 Update Location', 'description' => 'Change shop location'],
                    ['id' => 'back_settings', 'title' => '⬅️ Back', 'description' => 'Return to settings'],
                ],
            ]]
        );

        $this->nextStep($session, self::STEP_SHOP_MENU);
    }

    protected function handleShopMenuSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        match ($selection) {
            'shop_name' => $this->askShopName($session),
            'shop_category' => $this->askShopCategory($session),
            'shop_location' => $this->askShopLocation($session),
            'back_settings' => $this->showMainMenu($session),
            default => $this->showShopMenu($session),
        };
    }

    protected function askShopName(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $current = $user?->shop?->shop_name ?? '';

        $this->sendText(
            $session->phone,
            "✏️ *Edit Shop Name*\n" .
            "*കടയുടെ പേര് മാറ്റുക*\n\n" .
            "Current: *{$current}*\n\n" .
            "Type your new shop name:\n" .
            "_പുതിയ പേര് ടൈപ്പ് ചെയ്യുക:_"
        );

        $this->nextStep($session, self::STEP_SHOP_NAME);
    }

    protected function handleShopName(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->askShopName($session);
            return;
        }

        $name = trim($message->text ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $this->sendText($session->phone, "⚠️ Name must be 2-100 characters. Try again.");
            return;
        }

        $user = $this->getUser($session);
        if ($user?->shop) {
            $user->shop->update(['shop_name' => $name]);
        }

        $this->sendButtons(
            $session->phone,
            "✅ *Shop Name Updated!*\n*കടയുടെ പേര് മാറ്റി!*\n\nNew name: *{$name}*",
            [
                ['id' => 'set_shop', 'title' => '🏪 Back to Shop'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );

        $this->logInfo('Shop name updated', ['phone' => $this->maskPhone($session->phone)]);
        $this->nextStep($session, self::STEP_SHOP_MENU);
    }

    protected function askShopCategory(ConversationSession $session): void
    {
        $this->sendList(
            $session->phone,
            "📂 *Change Category*\n*കാറ്റഗറി മാറ്റുക*\n\nSelect your shop category:",
            '📂 Categories',
            ShopCategory::toListSections()
        );

        $this->nextStep($session, self::STEP_SHOP_CATEGORY);
    }

    protected function handleShopCategory(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);
        $category = ShopCategory::tryFrom(strtolower($selection));

        if (!$category) {
            $this->sendText($session->phone, "⚠️ Invalid category. Please try again.");
            $this->askShopCategory($session);
            return;
        }

        $user = $this->getUser($session);
        if ($user?->shop) {
            $user->shop->update(['category' => $category]);
        }

        $this->sendButtons(
            $session->phone,
            "✅ *Category Updated!*\n*കാറ്റഗറി മാറ്റി!*\n\nNew: *{$category->label()}*",
            [
                ['id' => 'set_shop', 'title' => '🏪 Back to Shop'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );

        $this->logInfo('Shop category updated', [
            'category' => $category->value,
            'phone' => $this->maskPhone($session->phone),
        ]);

        $this->nextStep($session, self::STEP_SHOP_MENU);
    }

    protected function askShopLocation(ConversationSession $session): void
    {
        $this->requestLocation(
            $session->phone,
            "📍 *Update Shop Location*\n" .
            "*കടയുടെ ലൊക്കേഷൻ*\n\n" .
            "Share your shop's location:\n" .
            "_കടയുടെ ലൊക്കേഷൻ ഷെയർ ചെയ്യുക:_"
        );

        $this->nextStep($session, self::STEP_SHOP_LOCATION);
    }

    protected function handleShopLocation(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isLocation()) {
            $this->sendText($session->phone, "📍 Please share location using the button.");
            return;
        }

        $coords = $message->getCoordinates();
        if (!$coords) {
            $this->askShopLocation($session);
            return;
        }

        $user = $this->getUser($session);
        if ($user?->shop) {
            $user->shop->update([
                'latitude' => $coords['latitude'],
                'longitude' => $coords['longitude'],
            ]);
        }

        $this->sendButtons(
            $session->phone,
            "✅ *Shop Location Updated!*\n*കടയുടെ ലൊക്കേഷൻ അപ്‌ഡേറ്റ് ആയി!*",
            [
                ['id' => 'set_shop', 'title' => '🏪 Back to Shop'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );

        $this->logInfo('Shop location updated', ['phone' => $this->maskPhone($session->phone)]);
        $this->nextStep($session, self::STEP_SHOP_MENU);
    }

    // =========================================================================
    // FISH SELLER SETTINGS (Fish sellers only)
    // =========================================================================

    protected function showFishMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user?->isFishSeller()) {
            $this->sendText($session->phone, "❌ Fish seller settings not available.");
            $this->showMainMenu($session);
            return;
        }

        $fishSeller = $user->fishSeller;

        $message = "🐟 *Fish Seller Settings*\n" .
            "*മീൻ വിൽപ്പനക്കാരൻ സെറ്റിംഗ്സ്*\n\n" .
            "📍 *Route:* " . ($fishSeller?->default_route ?? 'Not set') . "\n" .
            "⏰ *Usual Time:* " . ($fishSeller?->usual_time ?? 'Not set') . "\n" .
            "🔔 *Status:* " . ($fishSeller?->is_active ? '✅ Active' : '❌ Inactive');

        $this->sendList(
            $session->phone,
            $message,
            '🐟 Edit Settings',
            [[
                'title' => 'Options',
                'rows' => [
                    ['id' => 'fish_route', 'title' => '📍 Update Route', 'description' => 'Change your selling route'],
                    ['id' => 'fish_time', 'title' => '⏰ Usual Time', 'description' => 'Set arrival time'],
                    ['id' => 'fish_toggle', 'title' => $fishSeller?->is_active ? '🔴 Go Inactive' : '🟢 Go Active', 'description' => 'Toggle active status'],
                    ['id' => 'back_settings', 'title' => '⬅️ Back', 'description' => 'Return to settings'],
                ],
            ]]
        );

        $this->nextStep($session, self::STEP_FISH_MENU);
    }

    protected function handleFishMenu(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        match ($selection) {
            'fish_toggle' => $this->toggleFishSellerStatus($session),
            'back_settings' => $this->showMainMenu($session),
            // TODO: Implement fish_route, fish_time
            default => $this->showFishMenu($session),
        };
    }

    protected function toggleFishSellerStatus(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $fishSeller = $user?->fishSeller;

        if ($fishSeller) {
            $newStatus = !$fishSeller->is_active;
            $fishSeller->update(['is_active' => $newStatus]);

            $statusText = $newStatus ? '✅ Active' : '❌ Inactive';
            $this->sendButtons(
                $session->phone,
                "✅ *Status Updated!*\n\nYou are now: *{$statusText}*",
                [
                    ['id' => 'set_fish', 'title' => '🐟 Back'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
        }

        $this->nextStep($session, self::STEP_FISH_MENU);
    }

    // =========================================================================
    // WORKER SETTINGS (Workers only)
    // =========================================================================

    protected function showWorkerMenu(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user?->isWorker()) {
            $this->sendText($session->phone, "❌ Worker settings not available.");
            $this->showMainMenu($session);
            return;
        }

        $worker = $user->worker;

        $message = "👷 *Worker Settings*\n" .
            "*വർക്കർ സെറ്റിംഗ്സ്*\n\n" .
            "📋 *Skills:* " . ($worker?->skills ? implode(', ', $worker->skills) : 'Not set') . "\n" .
            "📍 *Work Radius:* " . ($worker?->work_radius_km ?? 5) . " km\n" .
            "🔔 *Status:* " . ($worker?->is_available ? '✅ Available' : '❌ Unavailable');

        $this->sendList(
            $session->phone,
            $message,
            '👷 Edit Settings',
            [[
                'title' => 'Options',
                'rows' => [
                    ['id' => 'worker_skills', 'title' => '📋 Update Skills', 'description' => 'Change your skill set'],
                    ['id' => 'worker_radius', 'title' => '📍 Work Radius', 'description' => 'Set how far you travel'],
                    ['id' => 'worker_toggle', 'title' => $worker?->is_available ? '🔴 Go Unavailable' : '🟢 Go Available', 'description' => 'Toggle availability'],
                    ['id' => 'back_settings', 'title' => '⬅️ Back', 'description' => 'Return to settings'],
                ],
            ]]
        );

        $this->nextStep($session, self::STEP_WORKER_MENU);
    }

    protected function handleWorkerMenu(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        match ($selection) {
            'worker_toggle' => $this->toggleWorkerStatus($session),
            'back_settings' => $this->showMainMenu($session),
            // TODO: Implement worker_skills, worker_radius
            default => $this->showWorkerMenu($session),
        };
    }

    protected function toggleWorkerStatus(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $worker = $user?->worker;

        if ($worker) {
            $newStatus = !$worker->is_available;
            $worker->update(['is_available' => $newStatus]);

            $statusText = $newStatus ? '✅ Available' : '❌ Unavailable';
            $this->sendButtons(
                $session->phone,
                "✅ *Status Updated!*\n\nYou are now: *{$statusText}*",
                [
                    ['id' => 'set_worker', 'title' => '👷 Back'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
        }

        $this->nextStep($session, self::STEP_WORKER_MENU);
    }

    // =========================================================================
    // DELETE ACCOUNT
    // =========================================================================

    protected function askDeleteConfirmation(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "🗑️ *Delete Account*\n" .
            "*അക്കൗണ്ട് ഡിലീറ്റ് ചെയ്യുക*\n\n" .
            "⚠️ *Warning:* This will permanently delete:\n" .
            "• Your profile and data\n" .
            "• Your shop (if any)\n" .
            "• All your history\n\n" .
            "This action *cannot be undone*.\n" .
            "_ഇത് പഴയപടി ആക്കാൻ കഴിയില്ല._\n\n" .
            "Are you sure?",
            [
                ['id' => 'confirm_delete', 'title' => '🗑️ Yes, Delete'],
                ['id' => 'back_settings', 'title' => '⬅️ No, Go Back'],
            ]
        );

        $this->nextStep($session, self::STEP_DELETE_CONFIRM);
    }

    protected function handleDeleteConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        if ($selection === 'confirm_delete') {
            $user = $this->getUser($session);

            if ($user) {
                // Soft delete or mark as deleted
                $user->update([
                    'is_active' => false,
                    'deleted_at' => now(),
                ]);

                $this->sendText(
                    $session->phone,
                    "✅ *Account Deleted*\n" .
                    "*അക്കൗണ്ട് ഡിലീറ്റ് ആയി*\n\n" .
                    "Your account has been deleted.\n" .
                    "Thank you for using NearBuy.\n\n" .
                    "_To create a new account, just send 'Hi'._"
                );

                $this->logInfo('Account deleted', ['phone' => $this->maskPhone($session->phone)]);

                // Clear session
                $this->sessionManager->clearSession($session);
            }
        } else {
            $this->showMainMenu($session);
        }
    }
}