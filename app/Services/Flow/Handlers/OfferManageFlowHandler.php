<?php

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\OfferStep;
use App\Models\ConversationSession;
use App\Models\Offer;
use App\Services\Offers\OfferService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\OfferMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handles the offer management flow for shop owners.
 *
 * Flow Steps:
 * 1. show_my_offers - List all active offers
 * 2. manage_offer - Show offer details and actions
 * 3. delete_confirm - Confirm deletion
 */
class OfferManageFlowHandler implements FlowHandlerInterface
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected OfferService $offerService,
    ) {}

    /**
     * Get the flow name.
     */
    public function getName(): string
    {
        return FlowType::OFFERS_MANAGE->value;
    }

    /**
     * Check if this handler can process the given step.
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, [
            OfferStep::SHOW_MY_OFFERS->value,
            OfferStep::MANAGE_OFFER->value,
            OfferStep::DELETE_CONFIRM->value,
        ]);
    }

    /**
     * Start the manage flow.
     */
    public function start(ConversationSession $session): void
    {
        // Verify user is a shop owner
        $user = $this->sessionManager->getUser($session);

        if (!$user || !$user->isShopOwner()) {
            $this->whatsApp->sendText(
                $session->phone,
                "⚠️ Only shop owners can manage offers."
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_MANAGE,
            OfferStep::SHOW_MY_OFFERS->value
        );

        $this->showMyOffers($session);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = OfferStep::tryFrom($session->current_step);

        if (!$step) {
            Log::warning('Invalid offer manage step', ['step' => $session->current_step]);
            $this->start($session);
            return;
        }

        match ($step) {
            OfferStep::SHOW_MY_OFFERS => $this->handleOfferSelection($message, $session),
            OfferStep::MANAGE_OFFER => $this->handleManageAction($message, $session),
            OfferStep::DELETE_CONFIRM => $this->handleDeleteConfirmation($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Handle invalid input.
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $step = OfferStep::tryFrom($session->current_step);

        match ($step) {
            OfferStep::SHOW_MY_OFFERS => $this->showMyOffers($session),
            OfferStep::MANAGE_OFFER => $this->showOfferManagement($session),
            OfferStep::DELETE_CONFIRM => $this->showDeleteConfirmation($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    /**
     * Handle offer selection from list.
     */
    protected function handleOfferSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $offerId = null;

        if ($message->isListReply()) {
            $selectionId = $message->getSelectionId();
            if (str_starts_with($selectionId, 'manage_')) {
                $offerId = (int) str_replace('manage_', '', $selectionId);
            } elseif ($selectionId === 'upload_new') {
                $this->goToUpload($session);
                return;
            }
        } elseif ($message->isButtonReply()) {
            $action = $message->getSelectionId();
            if ($action === 'upload_new') {
                $this->goToUpload($session);
                return;
            } elseif ($action === 'menu') {
                $this->goToMainMenu($session);
                return;
            }
        }

        if (!$offerId) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Verify offer belongs to this shop
        $user = $this->sessionManager->getUser($session);
        $offer = Offer::find($offerId);

        if (!$offer || $offer->shop_id !== $user->shop->id) {
            $this->whatsApp->sendText($session->phone, "❌ Offer not found.");
            $this->showMyOffers($session);
            return;
        }

        $this->sessionManager->setTempData($session, 'manage_offer_id', $offerId);
        $this->sessionManager->setStep($session, OfferStep::MANAGE_OFFER->value);
        $this->showOfferManagement($session);
    }

    /**
     * Handle management action.
     */
    protected function handleManageAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (str_contains($text, 'delete') || str_contains($text, 'remove')) {
                $action = 'delete';
            } elseif (str_contains($text, 'stat')) {
                $action = 'stats';
            } elseif (str_contains($text, 'back')) {
                $action = 'back';
            }
        }

        match ($action) {
            'stats' => $this->showOfferStats($session),
            'delete' => $this->confirmDelete($session),
            'back' => $this->showMyOffers($session),
            'menu' => $this->goToMainMenu($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    /**
     * Handle delete confirmation.
     */
    protected function handleDeleteConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (in_array($text, ['yes', 'confirm', 'delete', '1'])) {
                $action = 'confirm_delete';
            } elseif (in_array($text, ['no', 'cancel', '2'])) {
                $action = 'cancel_delete';
            }
        }

        match ($action) {
            'confirm_delete' => $this->deleteOffer($session),
            'cancel_delete' => $this->cancelDelete($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Display Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Show list of user's offers.
     */
    protected function showMyOffers(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);
        $shop = $user->shop;

        $offers = $this->offerService->getShopOffers($shop);

        if ($offers->isEmpty()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                OfferMessages::MY_OFFERS_EMPTY,
                [
                    ['id' => 'upload_new', 'title' => '📤 Upload Offer'],
                    ['id' => 'menu', 'title' => '🏠 Main Menu'],
                ]
            );
            return;
        }

        $header = OfferMessages::format(OfferMessages::MY_OFFERS_HEADER, [
            'count' => $offers->count(),
        ]);

        // Build offers list
        $rows = [];
        foreach ($offers as $offer) {
            $caption = $offer->caption ?: 'Offer #' . $offer->id;
            $expiry = OfferMessages::formatExpiry($offer->expires_at);

            $rows[] = [
                'id' => 'manage_' . $offer->id,
                'title' => mb_substr($caption, 0, 24),
                'description' => mb_substr("👁️ {$offer->view_count} views • Expires: {$expiry}", 0, 72),
            ];
        }

        $sections = [
            [
                'title' => 'Your Active Offers',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '🏷️ Manage Offers',
            $sections
        );

        // Clear any previous selection
        $this->sessionManager->removeTempData($session, 'manage_offer_id');
        $this->sessionManager->setStep($session, OfferStep::SHOW_MY_OFFERS->value);
    }

    /**
     * Show offer management options.
     */
    protected function showOfferManagement(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'manage_offer_id');
        $offer = Offer::find($offerId);

        if (!$offer) {
            $this->whatsApp->sendText($session->phone, "❌ Offer not found.");
            $this->showMyOffers($session);
            return;
        }

        // Show offer preview
        if ($offer->media_type === 'image') {
            $this->whatsApp->sendImage(
                $session->phone,
                $offer->media_url,
                $offer->caption ?: 'Your offer'
            );
        } else {
            $this->whatsApp->sendDocument(
                $session->phone,
                $offer->media_url,
                'Offer.pdf',
                $offer->caption
            );
        }

        // Show stats summary
        $statsMessage = OfferMessages::format(OfferMessages::OFFER_STATS, [
            'views' => $offer->view_count,
            'location_taps' => $offer->location_tap_count,
            'expiry' => OfferMessages::formatExpiry($offer->expires_at),
        ]);

        $this->whatsApp->sendButtons(
            $session->phone,
            $statsMessage . "\n\nWhat would you like to do?",
            OfferMessages::getManageButtons()
        );
    }

    /**
     * Show offer statistics.
     */
    protected function showOfferStats(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'manage_offer_id');
        $offer = Offer::find($offerId);

        if (!$offer) {
            $this->showMyOffers($session);
            return;
        }

        $stats = "📊 *Offer Statistics*\n\n" .
            "👁️ *Views:* {$offer->view_count}\n" .
            "📍 *Location taps:* {$offer->location_tap_count}\n" .
            "📅 *Created:* " . $offer->created_at->format('M j, Y') . "\n" .
            "⏰ *Expires:* " . OfferMessages::formatExpiry($offer->expires_at) . "\n\n" .
            ($offer->isExpired() ? "⚠️ This offer has expired." : "✅ This offer is active.");

        $this->whatsApp->sendButtons(
            $session->phone,
            $stats,
            [
                ['id' => 'delete', 'title' => '🗑️ Delete Offer'],
                ['id' => 'back', 'title' => '⬅️ Back to Offers'],
            ]
        );
    }

    /**
     * Confirm deletion.
     */
    protected function confirmDelete(ConversationSession $session): void
    {
        $this->sessionManager->setStep($session, OfferStep::DELETE_CONFIRM->value);
        $this->showDeleteConfirmation($session);
    }

    /**
     * Show delete confirmation.
     */
    protected function showDeleteConfirmation(ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $session->phone,
            OfferMessages::DELETE_CONFIRM,
            OfferMessages::getDeleteConfirmButtons()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Action Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Delete the offer.
     */
    protected function deleteOffer(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'manage_offer_id');
        $offer = Offer::find($offerId);

        if (!$offer) {
            $this->whatsApp->sendText($session->phone, "❌ Offer not found.");
            $this->showMyOffers($session);
            return;
        }

        $deleted = $this->offerService->deleteOffer($offer);

        if ($deleted) {
            $this->whatsApp->sendText($session->phone, OfferMessages::OFFER_DELETED);

            Log::info('Offer deleted by owner', [
                'offer_id' => $offerId,
                'phone' => $this->maskPhone($session->phone),
            ]);
        } else {
            $this->whatsApp->sendText($session->phone, "❌ Failed to delete offer. Please try again.");
        }

        $this->sessionManager->removeTempData($session, 'manage_offer_id');
        $this->showMyOffers($session);
    }

    /**
     * Cancel deletion.
     */
    protected function cancelDelete(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, "✅ Deletion cancelled.");
        $this->sessionManager->setStep($session, OfferStep::MANAGE_OFFER->value);
        $this->showOfferManagement($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Go to upload flow.
     */
    protected function goToUpload(ConversationSession $session): void
    {
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_UPLOAD,
            OfferStep::UPLOAD_IMAGE->value
        );

        $uploadHandler = app(OfferUploadFlowHandler::class);
        $uploadHandler->start($session);
    }

    /**
     * Go to main menu.
     */
    protected function goToMainMenu(ConversationSession $session): void
    {
        $this->sessionManager->resetToMainMenu($session);

        $mainMenuHandler = app(MainMenuHandler::class);
        $mainMenuHandler->start($session);
    }

    /**
     * Mask phone for logging.
     */
    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return $phone;
        }

        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }
}