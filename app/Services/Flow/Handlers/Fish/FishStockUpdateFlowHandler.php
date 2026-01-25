<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FishCatchStatus;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\FishSeller;
use App\Services\Fish\FishCatchService;
use App\Services\Fish\FishAlertService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FishMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for fish stock update flow.
 *
 * @srs-ref Pacha Meen Module - Stock Updates
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
        protected FishAlertService $alertService
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::FISH_STOCK_UPDATE;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_SELECT_CATCH,
            self::STEP_SELECT_STATUS,
            self::STEP_CONFIRM,
        ];
    }

    public function start(ConversationSession $session): void
    {
        $seller = $this->getFishSeller($session);
        if (!$seller) {
            $this->sendTextWithMenu($session->phone, "❌ You must be a registered fish seller.");
            return;
        }

        $this->clearTemp($session);
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
            $this->sendTextWithMenu($session->phone, "❌ You must be a registered fish seller.");
            return;
        }

        $step = $session->current_step;

        match ($step) {
            self::STEP_SELECT_CATCH => $this->handleSelectCatch($message, $session, $seller),
            self::STEP_SELECT_STATUS => $this->handleSelectStatus($message, $session),
            self::STEP_CONFIRM => $this->handleConfirm($message, $session),
            default => $this->start($session),
        };
    }

    protected function handleSelectCatch(IncomingMessage $message, ConversationSession $session, FishSeller $seller): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId && preg_match('/^catch_(\d+)$/', $selectionId, $matches)) {
            $catchId = (int) $matches[1];
            $catch = $this->catchService->findById($catchId);

            if ($catch && $catch->fish_seller_id === $seller->id) {
                $this->setTemp($session, 'catch_id', $catchId);
                $this->setTemp($session, 'fish_name', $catch->fishType->display_name);
                $this->nextStep($session, self::STEP_SELECT_STATUS);
                $this->showStatusOptions($session);
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
            $this->setTemp($session, 'new_status', $status->value);
            $this->nextStep($session, self::STEP_CONFIRM);
            $this->showConfirmation($session);
            return;
        }

        $this->showStatusOptions($session);
    }

    protected function handleConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'confirm_update') {
            $this->updateStock($session);
            return;
        }

        if ($selectionId === 'cancel_update') {
            $this->clearTemp($session);
            $this->sendTextWithMenu($session->phone, "❌ Update cancelled.");
            $this->goToMainMenu($session);
            return;
        }

        $this->showConfirmation($session);
    }

    protected function updateStock(ConversationSession $session): void
    {
        $catchId = $this->getTemp($session, 'catch_id');
        $newStatusValue = $this->getTemp($session, 'new_status');
        $newStatus = FishCatchStatus::from($newStatusValue);

        try {
            $catch = $this->catchService->findById($catchId);
            $this->catchService->updateStatus($catch, $newStatus);

            // Send low stock alerts if applicable
            if ($newStatus === FishCatchStatus::LOW_STOCK) {
                $this->alertService->sendLowStockAlerts($catch);
            }

            $statusLabel = $newStatus->label();
            $fishName = $this->getTemp($session, 'fish_name');
            $this->clearTemp($session);

            $this->sendButtonsWithMenu(
                $session->phone,
                "✅ *Stock Updated*\n\n{$fishName}: {$statusLabel}",
                [['id' => 'update_another', 'title' => '📦 Update Another']]
            );

        } catch (\Exception $e) {
            Log::error('Failed to update stock', ['error' => $e->getMessage()]);
            $this->sendErrorWithOptions($session->phone, "❌ Failed to update stock.");
        }
    }

    protected function showActiveCatches(ConversationSession $session, FishSeller $seller): void
    {
        $catches = $this->catchService->getSellerActiveCatches($seller);

        if ($catches->isEmpty()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "📦 *No Active Catches*\n\nYou don't have any active catches to update.",
                [['id' => 'fish_post_catch', 'title' => '🎣 Post Catch']]
            );
            return;
        }

        $response = FishMessages::selectCatchForUpdate($catches);
        $this->sendFishMessage($session->phone, $response);
    }

    protected function showStatusOptions(ConversationSession $session): void
    {
        $catchId = $this->getTemp($session, 'catch_id');
        $catch = $this->catchService->findById($catchId);
        $response = FishMessages::stockUpdateOptions($catch);
        $this->sendFishMessage($session->phone, $response);
    }

    protected function showConfirmation(ConversationSession $session): void
    {
        $fishName = $this->getTemp($session, 'fish_name');
        $newStatusValue = $this->getTemp($session, 'new_status');
        $newStatus = FishCatchStatus::from($newStatusValue);

        $statusLabel = $newStatus->label();

        $this->sendButtons(
            $session->phone,
            "📋 *Confirm Update*\n\n{$fishName} → {$statusLabel}\n\nUpdate stock status?",
            [
                ['id' => 'confirm_update', 'title' => '✅ Confirm'],
                ['id' => 'cancel_update', 'title' => '❌ Cancel'],
            ]
        );
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
