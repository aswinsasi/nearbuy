<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FishAlertFrequency;
use App\Enums\FishSubscriptionStep;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\FishSubscription;
use App\Models\FishType;
use App\Services\Fish\FishSubscriptionService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Manage Fish Subscription Handler.
 *
 * @srs-ref PM-015: Modify subscriptions: add/remove fish, change location, PAUSE alerts
 */
class FishManageSubscriptionHandler extends AbstractFlowHandler
{
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
            FishSubscriptionStep::MANAGE->value,
            FishSubscriptionStep::CHANGE_FISH->value,
            FishSubscriptionStep::CHANGE_LOCATION->value,
            FishSubscriptionStep::CHANGE_RADIUS->value,
            FishSubscriptionStep::CHANGE_TIME->value,
        ];
    }

    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $subscription = $this->subscriptionService->getUserSubscription($user);

        if (!$subscription) {
            $this->sendButtons(
                $session->phone,
                "🐟 No active subscription.\n\nSubscribe to get fish alerts!",
                [
                    ['id' => 'fish_subscribe', 'title' => '🔔 Subscribe'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        $this->setTempData($session, 'subscription_id', $subscription->id);
        $this->nextStep($session, FishSubscriptionStep::MANAGE->value);
        $this->showSettings($session, $subscription);
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = FishSubscriptionStep::tryFrom($session->current_step);

        match ($step) {
            FishSubscriptionStep::MANAGE => $this->handleManage($message, $session),
            FishSubscriptionStep::CHANGE_FISH => $this->handleChangeFish($message, $session),
            FishSubscriptionStep::CHANGE_LOCATION => $this->handleChangeLocation($message, $session),
            FishSubscriptionStep::CHANGE_RADIUS => $this->handleChangeRadius($message, $session),
            FishSubscriptionStep::CHANGE_TIME => $this->handleChangeTime($message, $session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Show Settings (PM-015)
    |--------------------------------------------------------------------------
    */

    protected function showSettings(ConversationSession $session, FishSubscription $subscription): void
    {
        $stats = $this->subscriptionService->getStats($subscription);

        // Build status text
        $statusText = $subscription->is_paused ? '⏸️ Paused' : '🔔 Active';
        $pauseLabel = $subscription->is_paused ? '▶️ Resume' : '⏸️ Pause';

        $body = "🐟 *Ninte Fish Alert Settings:*\n\n" .
            "Fish: {$subscription->fish_types_list}\n" .
            "Radius: {$subscription->radius_km} km\n" .
            "Time: {$subscription->frequency_display}\n" .
            "Status: {$statusText}\n\n" .
            "📊 Alerts: {$stats['alerts_received']} | Clicked: {$stats['click_rate']}%";

        $this->sendList(
            $session->phone,
            $body,
            'Settings',
            [
                [
                    'title' => '⚙️ Change Settings',
                    'rows' => [
                        ['id' => 'change_fish', 'title' => '🐟 Change Fish Types', 'description' => 'Add or remove fish'],
                        ['id' => 'change_radius', 'title' => '📏 Change Radius', 'description' => "Current: {$subscription->radius_km} km"],
                        ['id' => 'change_time', 'title' => '⏰ Change Time', 'description' => $subscription->frequency_display],
                        ['id' => 'change_location', 'title' => '📍 Change Location', 'description' => 'Update alert location'],
                    ],
                ],
                [
                    'title' => '🔔 Status',
                    'rows' => [
                        ['id' => 'toggle_pause', 'title' => $pauseLabel, 'description' => $subscription->is_paused ? 'Resume alerts' : 'Pause temporarily'],
                        ['id' => 'delete_sub', 'title' => '🗑️ Delete', 'description' => 'Stop all alerts'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu', 'description' => 'Main menu'],
                    ],
                ],
            ]
        );
    }

    protected function handleManage(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);
        $subscription = $this->getSubscription($session);

        if (!$subscription) {
            $this->start($session);
            return;
        }

        match ($selection) {
            'change_fish' => $this->startChangeFish($session),
            'change_radius' => $this->startChangeRadius($session),
            'change_time' => $this->startChangeTime($session),
            'change_location' => $this->startChangeLocation($session),
            'toggle_pause' => $this->togglePause($session, $subscription),
            'delete_sub' => $this->confirmDelete($session),
            'confirm_delete' => $this->deleteSubscription($session, $subscription),
            'cancel_delete' => $this->start($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Change Fish Types (PM-015)
    |--------------------------------------------------------------------------
    */

    protected function startChangeFish(ConversationSession $session): void
    {
        $this->nextStep($session, FishSubscriptionStep::CHANGE_FISH->value);
        $this->showFishOptions($session);
    }

    protected function showFishOptions(ConversationSession $session): void
    {
        $subscription = $this->getSubscription($session);
        $selectedIds = $subscription->fish_type_ids ?? [];

        $popular = FishType::active()
            ->where('is_popular', true)
            ->orderBy('sort_order')
            ->limit(6)
            ->get();

        $rows = [
            ['id' => 'fish_all', 'title' => '🐟 All Fish', 'description' => 'Get all alerts'],
        ];

        foreach ($popular as $fish) {
            $isSelected = in_array($fish->id, $selectedIds);
            $prefix = $isSelected ? '✅ ' : '';
            $rows[] = [
                'id' => 'fish_' . $fish->id,
                'title' => substr($prefix . $fish->name_en, 0, 24),
                'description' => $fish->name_ml,
            ];
        }

        $rows[] = ['id' => 'done_fish', 'title' => '✅ Done', 'description' => 'Save changes'];
        $rows[] = ['id' => 'back_manage', 'title' => '⬅️ Back', 'description' => 'Cancel'];

        $count = count($selectedIds);
        $body = $subscription->all_fish_types 
            ? "🐟 Currently: All fish\n\nTap to change:"
            : "🐟 Currently: {$count} types\n\nTap to toggle:";

        $this->sendList($session->phone, $body, 'Select', [['title' => 'Fish Types', 'rows' => $rows]]);
    }

    protected function handleChangeFish(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);
        $subscription = $this->getSubscription($session);

        if ($selection === 'fish_all') {
            $subscription->update(['all_fish_types' => true, 'fish_type_ids' => null]);
            $this->sendText($session->phone, "✅ All fish selected");
            $this->start($session);
            return;
        }

        if ($selection === 'done_fish' || $selection === 'back_manage') {
            $this->start($session);
            return;
        }

        if ($selection && str_starts_with($selection, 'fish_')) {
            $fishId = (int) str_replace('fish_', '', $selection);
            if ($fishId > 0) {
                $ids = $subscription->fish_type_ids ?? [];
                
                if (in_array($fishId, $ids)) {
                    $subscription->removeFishType($fishId);
                    $this->sendText($session->phone, "❌ Removed");
                } else {
                    $subscription->addFishType($fishId);
                    $this->sendText($session->phone, "✅ Added");
                }
                
                $this->showFishOptions($session);
                return;
            }
        }

        $this->showFishOptions($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Change Radius (PM-013/PM-015)
    |--------------------------------------------------------------------------
    */

    protected function startChangeRadius(ConversationSession $session): void
    {
        $this->nextStep($session, FishSubscriptionStep::CHANGE_RADIUS->value);
        
        $subscription = $this->getSubscription($session);

        $this->sendButtons(
            $session->phone,
            "📏 *Change Radius*\n\nCurrent: {$subscription->radius_km} km\n\nSelect new radius:",
            [
                ['id' => 'radius_2', 'title' => '2 km - Very Near'],
                ['id' => 'radius_5', 'title' => '5 km - Normal'],
                ['id' => 'radius_10', 'title' => '10 km - Far'],
            ]
        );
    }

    protected function handleChangeRadius(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        $radius = null;

        if ($selection && preg_match('/radius_(\d+)/', $selection, $m)) {
            $radius = (int) $m[1];
        } elseif ($text && is_numeric(trim($text))) {
            $radius = (int) trim($text);
        }

        if ($radius && $radius >= 1 && $radius <= 50) {
            $subscription = $this->getSubscription($session);
            $this->subscriptionService->setRadius($subscription, $radius);
            $this->sendText($session->phone, "✅ Radius: {$radius} km");
            $this->start($session);
            return;
        }

        $this->startChangeRadius($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Change Time (PM-014/PM-015)
    |--------------------------------------------------------------------------
    */

    protected function startChangeTime(ConversationSession $session): void
    {
        $this->nextStep($session, FishSubscriptionStep::CHANGE_TIME->value);
        
        $subscription = $this->getSubscription($session);

        $this->sendButtons(
            $session->phone,
            "⏰ *Change Alert Time*\n\nCurrent: {$subscription->frequency_display}\n\nSelect new time:",
            [
                ['id' => 'freq_immediate', 'title' => '🔔 Anytime'],
                ['id' => 'freq_morning_only', 'title' => '🌅 Morning (6-8AM)'],
                ['id' => 'freq_twice_daily', 'title' => '☀️ Twice Daily'],
            ]
        );
    }

    protected function handleChangeTime(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        $frequency = match ($selection) {
            'freq_immediate' => FishAlertFrequency::IMMEDIATE,
            'freq_morning_only' => FishAlertFrequency::MORNING_ONLY,
            'freq_twice_daily' => FishAlertFrequency::TWICE_DAILY,
            'freq_weekly_digest' => FishAlertFrequency::WEEKLY_DIGEST,
            default => null,
        };

        if ($frequency) {
            $subscription = $this->getSubscription($session);
            $this->subscriptionService->setFrequency($subscription, $frequency);
            $this->sendText($session->phone, "✅ Time: {$frequency->label()}");
            $this->start($session);
            return;
        }

        $this->startChangeTime($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Change Location (PM-012/PM-015)
    |--------------------------------------------------------------------------
    */

    protected function startChangeLocation(ConversationSession $session): void
    {
        $this->nextStep($session, FishSubscriptionStep::CHANGE_LOCATION->value);

        $this->sendButtons(
            $session->phone,
            "📍 *Change Location*\n\nShare new location:\n📎 → Location",
            [['id' => 'back_manage', 'title' => '⬅️ Cancel']]
        );
    }

    protected function handleChangeLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        if ($selection === 'back_manage') {
            $this->start($session);
            return;
        }

        $location = $this->getLocation($message);

        if ($location) {
            $subscription = $this->getSubscription($session);
            $this->subscriptionService->updateLocation(
                $subscription,
                $location['latitude'],
                $location['longitude']
            );
            $this->sendText($session->phone, "✅ Location updated");
            $this->start($session);
            return;
        }

        $this->startChangeLocation($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Pause/Resume (PM-015)
    |--------------------------------------------------------------------------
    */

    protected function togglePause(ConversationSession $session, FishSubscription $subscription): void
    {
        if ($subscription->is_paused) {
            $this->subscriptionService->resume($subscription);
            $this->sendText($session->phone, "▶️ Alerts resumed!");
        } else {
            $this->subscriptionService->pause($subscription);
            $this->sendText($session->phone, "⏸️ Alerts paused");
        }

        $this->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Delete Subscription
    |--------------------------------------------------------------------------
    */

    protected function confirmDelete(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "⚠️ *Delete subscription?*\n\nYou'll stop getting fish alerts.",
            [
                ['id' => 'confirm_delete', 'title' => '🗑️ Yes, Delete'],
                ['id' => 'cancel_delete', 'title' => '❌ Cancel'],
            ]
        );
    }

    protected function deleteSubscription(ConversationSession $session, FishSubscription $subscription): void
    {
        $this->subscriptionService->delete($subscription);
        $this->clearTempData($session);

        $this->sendButtons(
            $session->phone,
            "✅ Subscription deleted.\n\nYou can subscribe again anytime!",
            [
                ['id' => 'fish_subscribe', 'title' => '🔔 Subscribe'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper
    |--------------------------------------------------------------------------
    */

    protected function getSubscription(ConversationSession $session): ?FishSubscription
    {
        $id = $this->getTempData($session, 'subscription_id');
        return $id ? FishSubscription::find($id) : null;
    }
}