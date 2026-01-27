<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FishAlertFrequency;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\FishSubscription;
use App\Models\FishType;
use App\Services\Fish\FishSubscriptionService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FishMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for managing fish alert subscriptions.
 *
 * @srs-ref Pacha Meen Module - Subscription Management
 * @srs-ref PM-015: Allow customers to modify or pause their subscriptions
 * @srs-ref NFR-U-04: Main menu accessible from any flow state
 */
class FishManageSubscriptionHandler extends AbstractFlowHandler
{
    protected const STEP_VIEW = 'view';
    protected const STEP_CHANGE_LOCATION = 'change_location';
    protected const STEP_CHANGE_RADIUS = 'change_radius';
    protected const STEP_CHANGE_FISH = 'change_fish';
    protected const STEP_CHANGE_FREQUENCY = 'change_frequency';
    protected const STEP_CONFIRM_DELETE = 'confirm_delete';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected FishSubscriptionService $subscriptionService
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::FISH_MANAGE_SUBSCRIPTION;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_VIEW,
            self::STEP_CHANGE_LOCATION,
            self::STEP_CHANGE_RADIUS,
            self::STEP_CHANGE_FISH,
            self::STEP_CHANGE_FREQUENCY,
            self::STEP_CONFIRM_DELETE,
        ];
    }

    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        
        // FIXED: Use activeFishSubscriptions() relationship (plural HasMany) and get first
        // OLD: $subscription = $user?->activeFishSubscription;
        $subscription = $user?->activeFishSubscriptions()->first();

        if (!$subscription) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "🔔 *Fish Alerts*\n\nYou don't have an active subscription.\n\nSubscribe to get notified when fresh fish is available nearby!",
                [
                    ['id' => 'menu_fish_subscribe', 'title' => '🔔 Subscribe Now'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ]
            );
            return;
        }

        $this->nextStep($session, self::STEP_VIEW);
        $this->setTemp($session, 'subscription_id', $subscription->id);
        $this->showSubscriptionDetails($session, $subscription);
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = $session->current_step;

        match ($step) {
            self::STEP_VIEW => $this->handleView($message, $session),
            self::STEP_CHANGE_LOCATION => $this->handleChangeLocation($message, $session),
            self::STEP_CHANGE_RADIUS => $this->handleChangeRadius($message, $session),
            self::STEP_CHANGE_FISH => $this->handleChangeFish($message, $session),
            self::STEP_CHANGE_FREQUENCY => $this->handleChangeFrequency($message, $session),
            self::STEP_CONFIRM_DELETE => $this->handleConfirmDelete($message, $session),
            default => $this->start($session),
        };
    }

    protected function handleView(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        match ($selectionId) {
            'change_location' => $this->startChangeLocation($session),
            'change_radius' => $this->startChangeRadius($session),
            'change_fish' => $this->startChangeFish($session),
            'change_frequency' => $this->startChangeFrequency($session),
            'toggle_pause' => $this->togglePause($session),
            'delete_subscription' => $this->startDelete($session),
            'main_menu' => $this->goToMainMenu($session),
            default => $this->start($session),
        };
    }

    protected function handleChangeLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $location = $this->getLocation($message);

        if ($location) {
            $subscription = $this->getSubscription($session);
            $this->subscriptionService->updateLocation(
                $subscription,
                $location['latitude'],
                $location['longitude']
            );

            $this->sendTextWithMenu($session->phone, "✅ Location updated successfully!");
            $this->start($session);
            return;
        }

        $this->sendButtons(
            $session->phone,
            "📍 Share your new location for fish alerts.\n\nTap 📎 → *Location*",
            [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ]
        );
    }

    protected function handleChangeRadius(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        $radius = null;

        if ($selectionId && preg_match('/^radius_(\d+)$/', $selectionId, $matches)) {
            $radius = (int) $matches[1];
        } elseif ($text && is_numeric(trim($text))) {
            $radius = (int) trim($text);
        }

        if ($radius && $radius >= 1 && $radius <= 50) {
            $subscription = $this->getSubscription($session);
            $subscription->update(['radius_km' => $radius]);

            $this->sendTextWithMenu($session->phone, "✅ Alert radius updated to {$radius} km!");
            $this->start($session);
            return;
        }

        $this->showRadiusOptions($session);
    }

    protected function handleChangeFish(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'all_fish') {
            $subscription = $this->getSubscription($session);
            // FIX: Use the correct update method for JSON array
            $subscription->update([
                'fish_type_ids' => null,
                'all_fish_types' => true,
            ]);

            $this->sendTextWithMenu($session->phone, "✅ You'll now receive alerts for all fish types!");
            $this->start($session);
            return;
        }

        if ($selectionId === 'done_selecting') {
            $this->start($session);
            return;
        }

        if ($selectionId === 'main_menu') {
            $this->goToMainMenu($session);
            return;
        }

        // Handle category selection - show fish from that category
        if ($selectionId && str_starts_with($selectionId, 'cat_')) {
            $category = match ($selectionId) {
                'cat_sea_fish' => FishType::CATEGORY_SEA_FISH,
                'cat_freshwater' => FishType::CATEGORY_FRESHWATER,
                'cat_shellfish' => FishType::CATEGORY_SHELLFISH,
                'cat_crustacean' => FishType::CATEGORY_CRUSTACEAN,
                default => null,
            };
            
            if ($category) {
                $this->setTemp($session, 'fish_category', $category);
                $this->showFishFromCategory($session, $category);
                return;
            }
        }

        // Handle back to categories
        if ($selectionId === 'back_to_categories') {
            $this->setTemp($session, 'fish_category', null);
            $this->showFishCategorySelection($session);
            return;
        }

        // Handle fish type toggle
        if ($selectionId && str_starts_with($selectionId, 'fish_')) {
            $fishType = FishType::findByListId($selectionId);
            if ($fishType) {
                $subscription = $this->getSubscription($session);
                
                // FIX: Use JSON array methods instead of relationship
                $currentIds = $subscription->fish_type_ids ?? [];
                
                if (in_array($fishType->id, $currentIds)) {
                    // Remove - use model method
                    $subscription->removeFishType($fishType->id);
                    $this->sendText($session->phone, "❌ {$fishType->display_name} removed");
                } else {
                    // Add - use model method
                    $subscription->addFishType($fishType->id);
                    $this->sendText($session->phone, "✅ {$fishType->display_name} added");
                }
                
                // Show fish from same category again
                $category = $this->getTemp($session, 'fish_category');
                if ($category) {
                    $this->showFishFromCategory($session, $category);
                } else {
                    $this->showFishCategorySelection($session);
                }
                return;
            }
        }

        // Default: show category selection
        $this->showFishCategorySelection($session);
    }

    protected function handleChangeFrequency(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'main_menu') {
            $this->goToMainMenu($session);
            return;
        }

        // FIX: Use FishAlertFrequency::fromListId() to get enum from list ID
        // List IDs are formatted as: fish_freq_{enum_value}
        $frequency = FishAlertFrequency::fromListId($selectionId);

        if ($frequency) {
            $subscription = $this->getSubscription($session);
            $subscription->update(['alert_frequency' => $frequency]);

            $label = $frequency->label();
            $this->sendTextWithMenu($session->phone, "✅ Alert frequency updated to {$label}!");
            $this->start($session);
            return;
        }

        $this->showFrequencyOptions($session);
    }

    protected function handleConfirmDelete(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'confirm_delete') {
            $subscription = $this->getSubscription($session);
            $this->subscriptionService->deleteSubscription($subscription);

            $this->clearTemp($session);
            
            // @srs-ref NFR-U-04: Main menu accessible
            $this->sendButtons(
                $session->phone,
                "✅ Subscription deleted. You will no longer receive fish alerts.\n\nYou can subscribe again anytime!",
                [
                    ['id' => 'menu_fish_subscribe', 'title' => '🔔 Subscribe Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ]
            );
            return;
        }

        // Cancelled - go back to subscription view
        $this->start($session);
    }

    protected function startChangeLocation(ConversationSession $session): void
    {
        $this->nextStep($session, self::STEP_CHANGE_LOCATION);
        $this->sendButtons(
            $session->phone,
            "📍 Share your new location for fish alerts.\n\nTap 📎 → *Location*",
            [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ]
        );
    }

    protected function startChangeRadius(ConversationSession $session): void
    {
        $this->nextStep($session, self::STEP_CHANGE_RADIUS);
        $this->showRadiusOptions($session);
    }

    protected function startChangeFish(ConversationSession $session): void
    {
        $this->nextStep($session, self::STEP_CHANGE_FISH);
        $this->showFishOptions($session);
    }

    protected function startChangeFrequency(ConversationSession $session): void
    {
        $this->nextStep($session, self::STEP_CHANGE_FREQUENCY);
        $this->showFrequencyOptions($session);
    }

    protected function startDelete(ConversationSession $session): void
    {
        $this->nextStep($session, self::STEP_CONFIRM_DELETE);

        $this->sendButtons(
            $session->phone,
            "⚠️ *Delete Subscription?*\n\nYou will stop receiving fish alerts.\n\nThis action cannot be undone.",
            [
                ['id' => 'confirm_delete', 'title' => '🗑️ Yes, Delete'],
                ['id' => 'cancel_delete', 'title' => '❌ Cancel'],
            ]
        );
    }

    protected function togglePause(ConversationSession $session): void
    {
        $subscription = $this->getSubscription($session);
        $newState = !$subscription->is_paused;
        $subscription->update(['is_paused' => $newState]);

        $status = $newState ? 'paused ⏸️' : 'resumed ▶️';
        $this->sendTextWithMenu($session->phone, "✅ Alerts {$status}");
        $this->start($session);
    }

    protected function showSubscriptionDetails(ConversationSession $session, FishSubscription $subscription): void
    {
        // FIX: display_name is an accessor, not a column - must load models first
        $fishTypes = $subscription->all_fish_types
            ? 'All fish types'
            : ($subscription->fish_type_ids 
                ? FishType::whereIn('id', $subscription->fish_type_ids)->get()->pluck('display_name')->join(', ')
                : 'None selected');

        $status = $subscription->is_paused ? '⏸️ Paused' : '✅ Active';
        $frequency = $subscription->alert_frequency?->label() ?? 'Immediate';

        $stats = $this->subscriptionService->getSubscriptionStats($subscription);

        $text = "🔔 *Your Fish Alert Subscription*\n\n" .
            "Status: {$status}\n" .
            "Radius: {$subscription->radius_km} km\n" .
            "Fish Types: {$fishTypes}\n" .
            "Frequency: {$frequency}\n\n" .
            "📊 *Stats*\n" .
            "Alerts Received: {$stats['alerts_received']}\n" .
            "Click Rate: {$stats['click_rate']}%";

        $pauseLabel = $subscription->is_paused ? '▶️ Resume' : '⏸️ Pause';

        // @srs-ref NFR-U-04: Main menu accessible from any flow state
        $this->sendList(
            $session->phone,
            $text,
            'Manage',
            [
                [
                    'title' => '⚙️ Settings',
                    'rows' => [
                        ['id' => 'toggle_pause', 'title' => $pauseLabel, 'description' => $subscription->is_paused ? 'Start receiving alerts' : 'Temporarily stop alerts'],
                        ['id' => 'change_radius', 'title' => '📏 Change Radius', 'description' => "Current: {$subscription->radius_km} km"],
                        ['id' => 'change_fish', 'title' => '🐟 Change Fish Types', 'description' => 'Select which fish to get alerts for'],
                        ['id' => 'change_frequency', 'title' => '⏰ Change Frequency', 'description' => "Current: {$frequency}"],
                        ['id' => 'change_location', 'title' => '📍 Change Location', 'description' => 'Update alert location'],
                    ],
                ],
                [
                    'title' => '🔴 Danger Zone',
                    'rows' => [
                        ['id' => 'delete_subscription', 'title' => '🗑️ Delete Subscription', 'description' => 'Stop all fish alerts'],
                    ],
                ],
                [
                    'title' => '📍 Navigation',
                    'rows' => [
                        ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'],
                    ],
                ],
            ],
            '🐟 Fish Alerts'
        );
    }

    protected function showRadiusOptions(ConversationSession $session): void
    {
        $subscription = $this->getSubscription($session);

        $this->sendList(
            $session->phone,
            "📏 *Change Alert Radius*\n\nCurrent: {$subscription->radius_km} km\n\nSelect new radius:",
            'Select Radius',
            [
                [
                    'title' => 'Distance Options',
                    'rows' => [
                        ['id' => 'radius_3', 'title' => '3 km', 'description' => 'Nearby only'],
                        ['id' => 'radius_5', 'title' => '5 km', 'description' => 'Recommended'],
                        ['id' => 'radius_10', 'title' => '10 km', 'description' => 'Wider area'],
                        ['id' => 'radius_15', 'title' => '15 km', 'description' => 'Extended area'],
                        ['id' => 'radius_20', 'title' => '20 km', 'description' => 'Maximum range'],
                    ],
                ],
                [
                    'title' => '📍 Navigation',
                    'rows' => [
                        ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'],
                    ],
                ],
            ]
        );
    }

    protected function showFishOptions(ConversationSession $session): void
    {
        // Start with category selection
        $this->showFishCategorySelection($session);
    }

    /**
     * Show fish category selection (fits within 10-row limit).
     */
    protected function showFishCategorySelection(ConversationSession $session): void
    {
        $subscription = $this->getSubscription($session);
        // FIX: Use fish_type_ids array instead of fishTypes relationship
        $selectedCount = $subscription->fish_type_ids ? count($subscription->fish_type_ids) : 0;
        $selectedText = $selectedCount > 0 ? "\n\n✅ Selected: {$selectedCount} fish types" : "";

        $this->sendList(
            $session->phone,
            "🐟 *Select Fish Types*\n\nChoose a category to select fish.{$selectedText}",
            'Select',
            [
                [
                    'title' => 'Fish Categories',
                    'rows' => [
                        ['id' => 'all_fish', 'title' => '🐟 All Fish', 'description' => 'Get alerts for all types'],
                        ['id' => 'cat_sea_fish', 'title' => '🌊 Sea Fish', 'description' => 'Tuna, Mackerel, Sardine...'],
                        ['id' => 'cat_freshwater', 'title' => '🏞️ Freshwater', 'description' => 'Tilapia, Catfish...'],
                        ['id' => 'cat_shellfish', 'title' => '🐚 Shellfish', 'description' => 'Mussels, Clams, Oysters...'],
                        ['id' => 'cat_crustacean', 'title' => '🦐 Crustaceans', 'description' => 'Prawns, Crabs, Lobster...'],
                        ['id' => 'done_selecting', 'title' => '✅ Done', 'description' => 'Save and go back'],
                        ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'],
                    ],
                ],
            ]
        );
    }

    /**
     * Show fish from a specific category (paginated to fit 10-row limit).
     */
    protected function showFishFromCategory(ConversationSession $session, string $category): void
    {
        $subscription = $this->getSubscription($session);
        // FIX: Use fish_type_ids array instead of fishTypes relationship
        $selectedIds = $subscription->fish_type_ids ?? [];

        // Get fish from this category
        $fishTypes = FishType::where('category', $category)
            ->where('is_active', true)
            ->orderBy('name_ml')
            ->take(7) // Max 7 fish + Back + Done + Main Menu = 10 rows
            ->get();

        $categoryName = match ($category) {
            FishType::CATEGORY_SEA_FISH => '🌊 Sea Fish',
            FishType::CATEGORY_FRESHWATER => '🏞️ Freshwater',
            FishType::CATEGORY_SHELLFISH => '🐚 Shellfish',
            FishType::CATEGORY_CRUSTACEAN => '🦐 Crustaceans',
            default => '🐟 Fish',
        };

        $rows = [];
        foreach ($fishTypes as $fish) {
            $isSelected = in_array($fish->id, $selectedIds);
            $prefix = $isSelected ? '✅ ' : '';
            $rows[] = [
                'id' => $fish->list_id,
                'title' => $prefix . $fish->name_ml,
                'description' => $fish->name_en,
            ];
        }

        // Add navigation options
        $rows[] = ['id' => 'back_to_categories', 'title' => '⬅️ Back', 'description' => 'Choose another category'];
        $rows[] = ['id' => 'done_selecting', 'title' => '✅ Done', 'description' => 'Save selection'];
        $rows[] = ['id' => 'main_menu', 'title' => '🏠 Menu', 'description' => 'Main menu'];

        $this->sendList(
            $session->phone,
            "{$categoryName}\n\nTap to toggle. ✅ = selected",
            'Select',
            [
                [
                    'title' => $categoryName,
                    'rows' => $rows,
                ],
            ]
        );
    }

    protected function showFrequencyOptions(ConversationSession $session): void
    {
        $subscription = $this->getSubscription($session);
        $currentLabel = $subscription->alert_frequency?->label() ?? 'Immediate';

        // FIX: Use enum's toListItems() to get properly formatted options
        $frequencyRows = FishAlertFrequency::toListItems();

        $this->sendList(
            $session->phone,
            "⏰ *Change Alert Frequency*\n\nCurrent: {$currentLabel}\n\nSelect new frequency:",
            'Select',
            [
                [
                    'title' => 'Frequency Options',
                    'rows' => $frequencyRows,
                ],
                [
                    'title' => '📍 Navigation',
                    'rows' => [
                        ['id' => 'main_menu', 'title' => '🏠 Main Menu', 'description' => 'Return to main menu'],
                    ],
                ],
            ]
        );
    }

    protected function getSubscription(ConversationSession $session): ?FishSubscription
    {
        $subscriptionId = $this->getTemp($session, 'subscription_id');
        return FishSubscription::find($subscriptionId);
    }
}