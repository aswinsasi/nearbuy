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
        return [self::STEP_MENU, self::STEP_MY_CATCHES, self::STEP_MY_STATS];
    }

    public function start(ConversationSession $session): void
    {
        $seller = $this->getFishSeller($session);
        
        if (!$seller) {
            // IMPROVED: Shorter, action-oriented
            $this->sendButtons(
                $session->phone,
                "🐟 *Pacha Meen Seller*\n\nNee register cheythittilla!",
                [
                    ['id' => 'menu_fish_seller_register', 'title' => '🐟 Register Now'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
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

        // Handle menu selections from any step
        if ($this->handleMenuSelection($selectionId, $session, $seller)) {
            return;
        }

        // Handle back button
        if ($selectionId === 'back_to_menu') {
            $this->start($session);
            return;
        }

        match ($session->current_step) {
            self::STEP_MY_CATCHES => $this->handleMyCatches($message, $session, $seller),
            self::STEP_MY_STATS => $this->start($session),
            default => $this->start($session),
        };
    }

    protected function handleMenuSelection(?string $selectionId, ConversationSession $session, FishSeller $seller): bool
    {
        return match ($selectionId) {
            'fish_post_catch', 'menu_fish_post' => $this->redirectToFlow($session, FlowType::FISH_POST_CATCH) ?? true,
            'fish_update_stock', 'menu_fish_stock' => $this->redirectToFlow($session, FlowType::FISH_STOCK_UPDATE) ?? true,
            'fish_my_catches' => $this->showMyCatches($session, $seller) ?? true,
            'fish_my_stats' => $this->showMyStats($session, $seller) ?? true,
            default => false,
        };
    }

    protected function handleMyCatches(IncomingMessage $message, ConversationSession $session, FishSeller $seller): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId && preg_match('/^catch_(\d+)$/', $selectionId, $matches)) {
            $this->setTempData($session, 'preselected_catch_id', (int) $matches[1]);
            $this->redirectToFlow($session, FlowType::FISH_STOCK_UPDATE);
            return;
        }

        $this->showMyCatches($session, $seller);
    }

    /**
     * Show seller menu — compact and action-focused.
     */
    protected function showSellerMenu(ConversationSession $session, FishSeller $seller): void
    {
        // Get quick stats
        $activeCatches = $this->catchService->getSellerActiveCatches($seller)->count();
        $todayStats = $this->sellerService->getSellerStats($seller);
        $totalComing = $todayStats['today_coming'] ?? 0;

        // IMPROVED: Compact menu with inline stats
        $statsLine = $activeCatches > 0 
            ? "📦 {$activeCatches} active | 👥 {$totalComing} coming today"
            : "📦 No active catches";

        $this->sendButtons(
            $session->phone,
            "🐟 *Seller Dashboard*\n\n{$statsLine}\n⭐ Rating: " . ($seller->rating ? number_format($seller->rating, 1) : 'New'),
            [
                ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                ['id' => 'fish_update_stock', 'title' => '📦 Update Stock'],
                ['id' => 'fish_my_stats', 'title' => '📊 My Stats'],
            ],
            '🐟 Pacha Meen'
        );
    }

    /**
     * Show seller's active catches — compact list.
     */
    protected function showMyCatches(ConversationSession $session, FishSeller $seller): void
    {
        $this->nextStep($session, self::STEP_MY_CATCHES);
        $catches = $this->catchService->getSellerActiveCatches($seller);

        if ($catches->isEmpty()) {
            // IMPROVED: Shorter empty state
            $this->sendButtons(
                $session->phone,
                "📦 *My Catches*\n\nActive catches illa.\n\nPuthiya catch post cheyyuka!",
                [
                    ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                    ['id' => 'back_to_menu', 'title' => '⬅️ Back'],
                ]
            );
            return;
        }

        // IMPROVED: Compact list with coming count
        $sections = [[
            'title' => "Active ({$catches->count()})",
            'rows' => $catches->take(10)->map(fn($catch) => [
                'id' => "catch_{$catch->id}",
                'title' => "{$catch->fishType->display_name} — ₹{$catch->price_per_kg}",
                'description' => "{$catch->status->emoji()} {$catch->status->label()} • 👥 {$catch->customers_coming} coming",
            ])->toArray(),
        ]];

        $this->sendList(
            $session->phone,
            "📦 *My Catches*\n\nSelect to update status:",
            'View Catches',
            $sections
        );
    }

    /**
     * Show seller stats — compact format.
     */
    protected function showMyStats(ConversationSession $session, FishSeller $seller): void
    {
        $this->nextStep($session, self::STEP_MY_STATS);
        $stats = $this->sellerService->getSellerStats($seller);

        // IMPROVED: Compact stats in 3 lines
        $rating = $stats['avg_rating'] ? number_format($stats['avg_rating'], 1) . "⭐" : "No ratings";

        $this->sendButtons(
            $session->phone,
            "📊 *My Stats*\n\n" .
            "📅 Today: {$stats['today_catches']} catches • 👥 {$stats['today_coming']} coming\n" .
            "📈 Week: {$stats['week_catches']} catches • {$stats['week_views']} views\n" .
            "🏆 Total: {$stats['total_catches']} catches • {$rating}",
            [['id' => 'back_to_menu', 'title' => '⬅️ Back']]
        );
    }

    protected function redirectToFlow(ConversationSession $session, FlowType $flowType): void
    {
        $this->goToFlow($session, $flowType);
        app(\App\Services\Flow\FlowRouter::class)->startFlow($session, $flowType);
    }

    protected function getFishSeller(ConversationSession $session): ?FishSeller
    {
        return $this->getUser($session)?->fishSeller;
    }
}