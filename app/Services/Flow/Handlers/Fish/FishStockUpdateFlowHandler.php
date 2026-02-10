<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FishCatchStatus;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\FishSeller;
use App\Models\FishCatchResponse;
use App\Services\Fish\FishCatchService;
use App\Services\Fish\FishAlertService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Flow\FlowRouter;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Handler for fish stock update flow.
 *
 * @srs-ref PM-022 - Seller updates status
 * @srs-ref PM-023 - Sold Out alternatives notification
 */
class FishStockUpdateFlowHandler extends AbstractFlowHandler
{
    protected const STEP_SELECT_CATCH = 'select_catch';
    protected const STEP_SELECT_STATUS = 'select_status';
    protected const STEP_CONFIRM = 'confirm';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected FishCatchService $catchService,
        protected FishAlertService $alertService,
        protected FlowRouter $flowRouter
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::FISH_STOCK_UPDATE;
    }

    protected function getSteps(): array
    {
        return [self::STEP_SELECT_CATCH, self::STEP_SELECT_STATUS, self::STEP_CONFIRM];
    }

    public function start(ConversationSession $session): void
    {
        $seller = $this->getFishSeller($session);
        if (!$seller) {
            $this->sendText($session->phone, "❌ Fish seller aayi register cheyyuka.");
            return;
        }

        $this->clearTempData($session);
        $this->nextStep($session, self::STEP_SELECT_CATCH);
        $this->showActiveCatches($session, $seller);
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $seller = $this->getFishSeller($session);
        if (!$seller) {
            $this->sendText($session->phone, "❌ Fish seller aayi register cheyyuka.");
            return;
        }

        $selectionId = $this->getSelectionId($message);
        if ($this->handleCrossFlowNavigation($selectionId, $session)) {
            return;
        }

        match ($session->current_step) {
            self::STEP_SELECT_CATCH => $this->handleSelectCatch($message, $session, $seller),
            self::STEP_SELECT_STATUS => $this->handleSelectStatus($message, $session),
            self::STEP_CONFIRM => $this->handleConfirm($message, $session),
            default => $this->start($session),
        };
    }

    protected function handleCrossFlowNavigation(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }
        
        return match ($selectionId) {
            'fish_post_catch' => (function() use ($session) {
                $this->flowRouter->handleMenuSelection('fish_post_catch', $session);
                return true;
            })(),
            'update_another' => (function() use ($session) {
                $this->start($session);
                return true;
            })(),
            'main_menu' => (function() use ($session) {
                $this->goToMenu($session);
                return true;
            })(),
            default => false,
        };
    }

    protected function handleSelectCatch(IncomingMessage $message, ConversationSession $session, FishSeller $seller): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId && preg_match('/^catch_(\d+)$/', $selectionId, $matches)) {
            $catch = $this->catchService->findById((int) $matches[1]);

            if ($catch && $catch->seller_id === $seller->id) {
                $this->setTempData($session, 'catch_id', $catch->id);
                $this->setTempData($session, 'fish_name', $catch->fishType->display_name);
                $this->setTempData($session, 'current_status', $catch->status->value);
                $this->nextStep($session, self::STEP_SELECT_STATUS);
                $this->showStatusOptions($session, $catch);
                return;
            }
        }

        $this->showActiveCatches($session, $seller);
    }

    protected function handleSelectStatus(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        $status = match ($selectionId) {
            'status_available' => FishCatchStatus::AVAILABLE,
            'status_low_stock' => FishCatchStatus::LOW_STOCK,
            'status_sold_out' => FishCatchStatus::SOLD_OUT,
            default => null,
        };

        if ($status) {
            $this->setTempData($session, 'new_status', $status->value);
            $this->updateStock($session); // Skip confirmation for speed
            return;
        }

        $catchId = $this->getTempData($session, 'catch_id');
        $catch = $catchId ? $this->catchService->findById($catchId) : null;
        $this->showStatusOptions($session, $catch);
    }

    protected function handleConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'confirm_update') {
            $this->updateStock($session);
            return;
        }

        if ($selectionId === 'cancel_update') {
            $this->clearTempData($session);
            $this->sendText($session->phone, "❌ Cancelled");
            $this->goToMenu($session);
            return;
        }
    }

    /**
     * Update stock and handle sold-out alternatives.
     * @srs-ref PM-022 - Status update
     * @srs-ref PM-023 - Sold out alternatives
     */
    protected function updateStock(ConversationSession $session): void
    {
        $catchId = $this->getTempData($session, 'catch_id');
        $newStatusValue = $this->getTempData($session, 'new_status');

        if (!$catchId || !$newStatusValue) {
            $this->sendText($session->phone, "❌ Session expired. Try again.");
            $this->start($session);
            return;
        }

        $catch = $this->catchService->findById($catchId);
        if (!$catch) {
            $this->sendText($session->phone, "❌ Catch not found.");
            $this->clearTempData($session);
            return;
        }

        $newStatus = FishCatchStatus::from($newStatusValue);
        $oldStatus = $catch->status;

        // Check if status unchanged
        if ($oldStatus === $newStatus) {
            $this->sendButtons(
                $session->phone,
                "ℹ️ Already {$newStatus->label()}!",
                [
                    ['id' => 'update_another', 'title' => '📦 Update Another'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            $this->clearTempData($session);
            return;
        }

        try {
            $this->catchService->updateStatus($catch, $newStatus);

            // PM-023: If sold out, notify customers with alternatives
            if ($newStatus === FishCatchStatus::SOLD_OUT) {
                $this->notifyCustomersWithAlternatives($catch);
            }

            // Low stock alerts
            if ($newStatus === FishCatchStatus::LOW_STOCK) {
                $this->alertService->sendLowStockAlerts($catch);
            }

            $fishName = $this->getTempData($session, 'fish_name');
            $this->clearTempData($session);

            // IMPROVED: Quick success with next actions
            $this->sendButtons(
                $session->phone,
                "✅ *Updated!*\n\n{$fishName} → {$newStatus->display()}",
                [
                    ['id' => 'update_another', 'title' => '📦 Update Another'],
                    ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );

        } catch (\Exception $e) {
            Log::error('Stock update failed', ['error' => $e->getMessage()]);
            $this->sendButtons(
                $session->phone,
                "❌ Update failed. Try again.",
                [
                    ['id' => 'update_another', 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
        }
    }

    /**
     * Notify customers who were coming that fish is sold out, with alternatives.
     * @srs-ref PM-023
     */
    protected function notifyCustomersWithAlternatives(\App\Models\FishCatch $soldOutCatch): void
    {
        // Get customers who said "I'm Coming"
        $responses = FishCatchResponse::getUsersToNotifyForSoldOut($soldOutCatch->id);

        if ($responses->isEmpty()) {
            return;
        }

        // Find alternatives (same fish type, nearby, available)
        $alternatives = $this->catchService->findAlternatives(
            $soldOutCatch->fish_type_id,
            (float) $soldOutCatch->latitude,
            (float) $soldOutCatch->longitude,
            $soldOutCatch->id,
            3
        );

        $fishName = $soldOutCatch->fishType->display_name;
        $sellerName = $soldOutCatch->seller->business_name;

        foreach ($responses as $response) {
            $user = $response->user;
            if (!$user?->phone) {
                continue;
            }

            // Build alternatives text
            if ($alternatives->isNotEmpty()) {
                $altText = "But check these nearby:\n\n";
                foreach ($alternatives as $alt) {
                    $distance = $this->calculateDistance(
                        (float) $soldOutCatch->latitude,
                        (float) $soldOutCatch->longitude,
                        (float) $alt->latitude,
                        (float) $alt->longitude
                    );
                    $distKm = $distance < 1 ? round($distance * 1000) . "m" : round($distance, 1) . "km";
                    $altText .= "🐟 {$alt->fishType->display_name} — ₹{$alt->price_per_kg}/kg\n";
                    $altText .= "📍 {$alt->seller->business_name} ({$distKm})\n\n";
                }

                $this->sendButtons(
                    $user->phone,
                    "😕 *Sold Out!*\n\n{$fishName} @ {$sellerName} theernu!\n\n{$altText}",
                    [
                        ['id' => 'fish_browse', 'title' => '🐟 Browse Fish'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ]
                );
            } else {
                // No alternatives available
                $this->sendButtons(
                    $user->phone,
                    "😕 *Sold Out!*\n\n{$fishName} @ {$sellerName} theernu!\n\nNearby alternatives illa ippo.",
                    [
                        ['id' => 'fish_subscribe', 'title' => '🔔 Get Alerts'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ]
                );
            }

            // Mark as notified
            $response->markSoldOutNotified();
        }

        Log::info('Sold out notifications sent', [
            'catch_id' => $soldOutCatch->id,
            'customers_notified' => $responses->count(),
            'alternatives_found' => $alternatives->count(),
        ]);
    }

    protected function showActiveCatches(ConversationSession $session, FishSeller $seller): void
    {
        $catches = $this->catchService->getSellerActiveCatches($seller);

        if ($catches->isEmpty()) {
            // IMPROVED: Shorter empty state
            $this->sendButtons(
                $session->phone,
                "📦 *Stock Update*\n\nActive catches illa!\n\nPuthiya catch post cheyyuka.",
                [
                    ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        // IMPROVED: Compact list with coming count
        $sections = [[
            'title' => "Select Catch ({$catches->count()})",
            'rows' => $catches->take(10)->map(fn($catch) => [
                'id' => "catch_{$catch->id}",
                'title' => "{$catch->fishType->display_name} — ₹{$catch->price_per_kg}",
                'description' => "{$catch->status->emoji()} {$catch->status->label()} • 👥 {$catch->customers_coming}",
            ])->toArray(),
        ]];

        $this->sendList(
            $session->phone,
            "📦 *Stock Update*\n\nUpdate cheyyaan catch select cheyyuka:",
            'Select Catch',
            $sections
        );
    }

    protected function showStatusOptions(ConversationSession $session, ?\App\Models\FishCatch $catch): void
    {
        if (!$catch) {
            $this->start($session);
            return;
        }

        $fishName = $catch->fishType->display_name;
        $currentStatus = $catch->status->display();
        $comingCount = $catch->customers_coming ?? 0;

        // IMPROVED: Compact status selection
        $this->sendButtons(
            $session->phone,
            "📦 *{$fishName}*\n\nNow: {$currentStatus}\n👥 {$comingCount} customers coming\n\nNew status?",
            [
                ['id' => 'status_available', 'title' => '✅ Available'],
                ['id' => 'status_low_stock', 'title' => '⚠️ Low Stock'],
                ['id' => 'status_sold_out', 'title' => '❌ Sold Out'],
            ]
        );
    }

    protected function getFishSeller(ConversationSession $session): ?FishSeller
    {
        return $this->getUser($session)?->fishSeller;
    }

    protected function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);
        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}