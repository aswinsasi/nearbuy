<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
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
        $subscription = $user?->activeFishSubscription;

        if (!$subscription) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "🔔 *Fish Alerts*\n\nYou don't have an active subscription.\n\nSubscribe to get notified when fresh fish is available nearby!",
                [['id' => 'menu_fish_subscribe', 'title' => '🔔 Subscribe Now']]
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

        $this->requestLocation(
            $session->phone,
            "📍 Share your new location for fish alerts.\n\nTap 📎 → *Location*"
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
            $subscription->fishTypes()->detach();
            $subscription->update(['all_fish_types' => true]);

            $this->sendTextWithMenu($session->phone, "✅ You'll now receive alerts for all fish types!");
            $this->start($session);
            return;
        }

        if ($selectionId === 'done_selecting') {
            $this->start($session);
            return;
        }

        if ($selectionId && str_starts_with($selectionId, 'fish_')) {
            $fishType = FishType::findByListId($selectionId);
            if ($fishType) {
                $subscription = $this->getSubscription($session);
                $subscription->fishTypes()->toggle([$fishType->id]);
                $subscription->update(['all_fish_types' => false]);

                $this->sendText($session->phone, "✅ {$fishType->display_name} toggled!");
            }
        }

        $this->showFishOptions($session);
    }

    protected function handleChangeFrequency(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        $frequency = match ($selectionId) {
            'freq_instant' => 'instant',
            'freq_hourly' => 'hourly',
            'freq_daily' => 'daily',
            default => null,
        };

        if ($frequency) {
            $subscription = $this->getSubscription($session);
            $subscription->update(['alert_frequency' => $frequency]);

            $label = ucfirst($frequency);
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
            $this->sendTextWithMenu($session->phone, "✅ Subscription deleted. You will no longer receive fish alerts.");
            $this->goToMainMenu($session);
            return;
        }

        $this->start($session);
    }

    protected function startChangeLocation(ConversationSession $session): void
    {
        $this->nextStep($session, self::STEP_CHANGE_LOCATION);
        $this->requestLocation(
            $session->phone,
            "📍 Share your new location for fish alerts.\n\nTap 📎 → *Location*"
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
        $fishTypes = $subscription->all_fish_types
            ? 'All fish types'
            : $subscription->fishTypes->pluck('display_name')->join(', ');

        $status = $subscription->is_paused ? '⏸️ Paused' : '✅ Active';
        $frequency = ucfirst($subscription->frequency);

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

        $this->sendButtons(
            $session->phone,
            $text,
            [
                ['id' => 'toggle_pause', 'title' => $pauseLabel],
                ['id' => 'change_radius', 'title' => '📏 Change Radius'],
                ['id' => 'delete_subscription', 'title' => '🗑️ Delete'],
            ],
            '🐟 Fish Alerts'
        );
    }

    protected function showRadiusOptions(ConversationSession $session): void
    {
        $subscription = $this->getSubscription($session);

        $this->sendButtons(
            $session->phone,
            "📏 *Change Alert Radius*\n\nCurrent: {$subscription->radius_km} km\n\nSelect new radius:",
            [
                ['id' => 'radius_3', 'title' => '3 km'],
                ['id' => 'radius_5', 'title' => '5 km'],
                ['id' => 'radius_10', 'title' => '10 km'],
            ]
        );
    }

    protected function showFishOptions(ConversationSession $session): void
    {
        $subscription = $this->getSubscription($session);
        $selectedIds = $subscription->fishTypes->pluck('id')->toArray();

        $sections = FishType::getListSections();

        // Mark selected fish
        foreach ($sections as &$section) {
            foreach ($section['rows'] as &$row) {
                $fishType = FishType::findByListId($row['id']);
                if ($fishType && in_array($fishType->id, $selectedIds)) {
                    $row['title'] = "✅ " . $row['title'];
                }
            }
        }

        // Add options at top
        array_unshift($sections[0]['rows'], [
            'id' => 'all_fish',
            'title' => '🐟 All Fish Types',
            'description' => 'Get alerts for all fish',
        ]);

        $sections[0]['rows'][] = [
            'id' => 'done_selecting',
            'title' => '✅ Done',
            'description' => 'Save selection',
        ];

        $this->sendList(
            $session->phone,
            "🐟 *Select Fish Types*\n\nTap to toggle. Selected fish are marked with ✅",
            'Select Fish',
            $sections
        );
    }

    protected function showFrequencyOptions(ConversationSession $session): void
    {
        $subscription = $this->getSubscription($session);

        $this->sendButtons(
            $session->phone,
            "⏰ *Change Alert Frequency*\n\nCurrent: " . ucfirst($subscription->frequency) . "\n\nSelect new frequency:",
            [
                ['id' => 'freq_instant', 'title' => '⚡ Instant'],
                ['id' => 'freq_hourly', 'title' => '🕐 Hourly'],
                ['id' => 'freq_daily', 'title' => '📅 Daily'],
            ]
        );
    }

    protected function getSubscription(ConversationSession $session): ?FishSubscription
    {
        $subscriptionId = $this->getTemp($session, 'subscription_id');
        return FishSubscription::find($subscriptionId);
    }
}
