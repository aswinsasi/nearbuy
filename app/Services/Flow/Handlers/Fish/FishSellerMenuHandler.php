<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\FishSeller;
use App\Services\Fish\FishSellerService;
use App\Services\Fish\FishCatchService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FishMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for fish seller menu/dashboard.
 *
 * @srs-ref Pacha Meen Module - Seller Dashboard
 */
class FishSellerMenuHandler extends AbstractFlowHandler
{
    protected const STEP_MENU = 'show_menu';
    protected const STEP_MY_CATCHES = 'my_catches';
    protected const STEP_MY_STATS = 'my_stats';
    protected const STEP_SETTINGS = 'settings';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected FishSellerService $sellerService,
        protected FishCatchService $catchService
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::FISH_SELLER_MENU;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_MENU,
            self::STEP_MY_CATCHES,
            self::STEP_MY_STATS,
            self::STEP_SETTINGS,
        ];
    }

    public function start(ConversationSession $session): void
    {
        $seller = $this->getFishSeller($session);
        if (!$seller) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "🐟 *Fish Seller Features*\n\nYou're not registered as a fish seller yet.",
                [['id' => 'menu_fish_seller_register', 'title' => '🐟 Register Now']]
            );
            return;
        }

        $this->nextStep($session, self::STEP_MENU);
        $this->showSellerMenu($session, $seller);
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $seller = $this->getFishSeller($session);
        if (!$seller) {
            $this->start($session);
            return;
        }

        $selectionId = $this->getSelectionId($message);
        $step = $session->current_step;

        // Handle menu selections
        if ($step === self::STEP_MENU) {
            $this->handleMenuSelection($selectionId, $session, $seller);
            return;
        }

        // Handle back button from sub-views
        if ($selectionId === 'back_to_menu') {
            $this->start($session);
            return;
        }

        match ($step) {
            self::STEP_MY_CATCHES => $this->handleMyCatches($message, $session, $seller),
            self::STEP_MY_STATS => $this->handleMyStats($message, $session, $seller),
            self::STEP_SETTINGS => $this->handleSettings($message, $session, $seller),
            default => $this->start($session),
        };
    }

    protected function handleMenuSelection(?string $selectionId, ConversationSession $session, FishSeller $seller): void
    {
        match ($selectionId) {
            'fish_post_catch', 'menu_fish_post' => $this->redirectToFlow($session, FlowType::FISH_POST_CATCH),
            'fish_update_stock', 'menu_fish_stock' => $this->redirectToFlow($session, FlowType::FISH_STOCK_UPDATE),
            'fish_my_catches' => $this->showMyCatches($session, $seller),
            'fish_my_stats' => $this->showMyStats($session, $seller),
            'fish_settings' => $this->showSettings($session, $seller),
            default => $this->showSellerMenu($session, $seller),
        };
    }

    protected function handleMyCatches(IncomingMessage $message, ConversationSession $session, FishSeller $seller): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId && preg_match('/^catch_(\d+)$/', $selectionId, $matches)) {
            $catchId = (int) $matches[1];
            // Redirect to stock update for this catch
            $this->setTemp($session, 'preselected_catch_id', $catchId);
            $this->redirectToFlow($session, FlowType::FISH_STOCK_UPDATE);
            return;
        }

        $this->showMyCatches($session, $seller);
    }

    protected function handleMyStats(IncomingMessage $message, ConversationSession $session, FishSeller $seller): void
    {
        $this->showMyStats($session, $seller);
    }

    protected function handleSettings(IncomingMessage $message, ConversationSession $session, FishSeller $seller): void
    {
        $selectionId = $this->getSelectionId($message);

        match ($selectionId) {
            'toggle_notifications' => $this->toggleNotifications($session, $seller),
            'update_location' => $this->promptLocationUpdate($session),
            default => $this->showSettings($session, $seller),
        };
    }

    protected function showSellerMenu(ConversationSession $session, FishSeller $seller): void
    {
        $response = FishMessages::fishSellerMenu($seller);
        $this->sendFishMessage($session->phone, $response);
    }

    protected function showMyCatches(ConversationSession $session, FishSeller $seller): void
    {
        $this->nextStep($session, self::STEP_MY_CATCHES);

        $catches = $this->catchService->getSellerActiveCatches($seller);

        if ($catches->isEmpty()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "📋 *My Catches*\n\nNo active catches.\n\nPost your fresh catch to start getting customers!",
                [
                    ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                    ['id' => 'back_to_menu', 'title' => '⬅️ Back'],
                ]
            );
            return;
        }

        $sections = [[
            'title' => 'Active Catches',
            'rows' => $catches->take(10)->map(fn($catch) => [
                'id' => "catch_{$catch->id}",
                'title' => $catch->fishType->display_name,
                'description' => "₹{$catch->price_per_kg}/kg • {$catch->status->label()}",
            ])->toArray(),
        ]];

        $this->sendList(
            $session->phone,
            "📋 *My Catches* ({$catches->count()} active)\n\nSelect a catch to update:",
            'View Catches',
            $sections,
            '🐟 My Catches'
        );
    }

    protected function showMyStats(ConversationSession $session, FishSeller $seller): void
    {
        $this->nextStep($session, self::STEP_MY_STATS);

        $stats = $this->sellerService->getSellerStats($seller);

        $text = "📊 *My Statistics*\n\n" .
            "📅 *Today*\n" .
            "Catches Posted: {$stats['today_catches']}\n" .
            "Views: {$stats['today_views']}\n" .
            "Coming Responses: {$stats['today_coming']}\n\n" .
            "📈 *This Week*\n" .
            "Catches Posted: {$stats['week_catches']}\n" .
            "Total Views: {$stats['week_views']}\n" .
            "Coming Responses: {$stats['week_coming']}\n\n" .
            "🏆 *All Time*\n" .
            "Total Catches: {$stats['total_catches']}\n" .
            "Total Views: {$stats['total_views']}\n" .
            "Average Rating: " . ($stats['avg_rating'] ? number_format($stats['avg_rating'], 1) . "⭐" : "No ratings yet");

        $this->sendButtonsWithMenu(
            $session->phone,
            $text,
            [['id' => 'back_to_menu', 'title' => '⬅️ Back']]
        );
    }

    protected function showSettings(ConversationSession $session, FishSeller $seller): void
    {
        $this->nextStep($session, self::STEP_SETTINGS);

        $notifStatus = $seller->notifications_enabled ? '✅ On' : '❌ Off';

        $this->sendButtons(
            $session->phone,
            "⚙️ *Seller Settings*\n\n" .
            "🔔 Notifications: {$notifStatus}\n" .
            "📍 Location: Set\n\n" .
            "Select an option to update:",
            [
                ['id' => 'toggle_notifications', 'title' => "🔔 Toggle Notifications"],
                ['id' => 'update_location', 'title' => '📍 Update Location'],
                ['id' => 'back_to_menu', 'title' => '⬅️ Back'],
            ]
        );
    }

    protected function toggleNotifications(ConversationSession $session, FishSeller $seller): void
    {
        $newState = !$seller->notifications_enabled;
        $seller->update(['notifications_enabled' => $newState]);

        $status = $newState ? 'enabled ✅' : 'disabled ❌';
        $this->sendTextWithMenu($session->phone, "🔔 Notifications {$status}");

        $this->showSettings($session, $seller->fresh());
    }

    protected function promptLocationUpdate(ConversationSession $session): void
    {
        $this->requestLocation(
            $session->phone,
            "📍 Share your new selling location.\n\nTap 📎 → *Location*"
        );
    }

    protected function redirectToFlow(ConversationSession $session, FlowType $flowType): void
    {
        $this->goToFlow($session, $flowType);
        app(\App\Services\Flow\FlowRouter::class)->startFlow($session, $flowType);
    }

    protected function getFishSeller(ConversationSession $session): ?FishSeller
    {
        $user = $this->getUser($session);
        return $user?->fishSeller;
    }

    protected function sendFishMessage(string $phone, array $response): void
    {
        $type = $response['type'] ?? 'text';

        switch ($type) {
            case 'text':
                $this->sendText($phone, $response['text']);
                break;
            case 'buttons':
                $this->sendButtons($phone, $response['body'] ?? '', $response['buttons'] ?? [], $response['header'] ?? null, $response['footer'] ?? null);
                break;
            case 'list':
                $this->sendList($phone, $response['body'] ?? '', $response['button'] ?? 'Select', $response['sections'] ?? [], $response['header'] ?? null, $response['footer'] ?? null);
                break;
            default:
                $this->sendText($phone, $response['text'] ?? '');
        }
    }
}
