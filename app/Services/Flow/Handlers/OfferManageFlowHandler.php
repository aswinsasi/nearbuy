<?php

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\OfferStep;
use App\Models\ConversationSession;
use App\Models\Offer;
use App\Services\Offers\OfferService;
use App\Services\WhatsApp\Messages\MessageTemplates;
use App\Services\WhatsApp\Messages\OfferMessages;

/**
 * ENHANCED Offer Manage Flow Handler.
 *
 * Key improvements:
 * 1. Extends AbstractFlowHandler for consistent menu buttons
 * 2. Uses sendTextWithMenu/sendButtonsWithMenu patterns
 * 3. Main Menu button on all messages
 */
class OfferManageFlowHandler extends AbstractFlowHandler
{
    public function __construct(
        \App\Services\Session\SessionManager $sessionManager,
        \App\Services\WhatsApp\WhatsAppService $whatsApp,
        protected OfferService $offerService,
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::OFFERS_MANAGE;
    }

    protected function getSteps(): array
    {
        return [
            OfferStep::SHOW_MY_OFFERS->value,
            OfferStep::MANAGE_OFFER->value,
            OfferStep::DELETE_CONFIRM->value,
        ];
    }

    /**
     * Start the manage flow.
     */
    public function start(ConversationSession $session): void
    {
        // Verify user is a shop owner
        $user = $this->getUser($session);

        if (!$user || !$user->isShopOwner()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "⚠️ *Shop Owner Required*\n\nOnly shop owners can manage offers.",
                [['id' => 'register', 'title' => '📝 Register Shop']]
            );
            $this->goToMainMenu($session);
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
        // Handle common navigation (menu, cancel, etc.)
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = OfferStep::tryFrom($session->current_step);

        if (!$step) {
            $this->logError('Invalid offer manage step', ['step' => $session->current_step]);
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

    /**
     * Get expected input type.
     */
    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            OfferStep::SHOW_MY_OFFERS->value => 'list',
            default => 'button',
        };
    }

    /**
     * Re-prompt current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
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

    protected function handleOfferSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $offerId = null;

        if ($message->isListReply()) {
            $selectionId = $this->getSelectionId($message);
            if (str_starts_with($selectionId, 'manage_')) {
                $offerId = (int) str_replace('manage_', '', $selectionId);
            } elseif ($selectionId === 'upload_new') {
                $this->goToUpload($session);
                return;
            }
        } elseif ($message->isInteractive()) {
            $action = $this->getSelectionId($message);
            if ($action === 'upload_new') {
                $this->goToUpload($session);
                return;
            } elseif (in_array($action, ['menu', 'main_menu'])) {
                $this->goToMainMenu($session);
                return;
            }
        }

        if (!$offerId) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Verify offer belongs to this shop
        $user = $this->getUser($session);
        $offer = Offer::find($offerId);

        if (!$offer || $offer->shop_id !== $user->shop->id) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Offer not found.",
                [
                    ['id' => 'back', 'title' => '⬅️ My Offers'],
                    self::MENU_BUTTON,
                ]
            );
            $this->showMyOffers($session);
            return;
        }

        $this->setTemp($session, 'manage_offer_id', $offerId);
        $this->nextStep($session, OfferStep::MANAGE_OFFER->value);
        $this->showOfferManagement($session);
    }

    protected function handleManageAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);
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
            'menu', 'main_menu' => $this->goToMainMenu($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    protected function handleDeleteConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);
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

    protected function showMyOffers(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $shop = $user->shop;

        $offers = $this->offerService->getShopOffers($shop);

        if ($offers->isEmpty()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                OfferMessages::MY_OFFERS_EMPTY,
                [['id' => 'upload_new', 'title' => '📤 Upload Offer']]
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

        $this->sendListWithFooter(
            $session->phone,
            $header,
            '🏷️ Manage Offers',
            $sections,
            '🏷️ My Offers'
        );

        // Clear any previous selection
        $this->sessionManager->removeTempData($session, 'manage_offer_id');
        $this->nextStep($session, OfferStep::SHOW_MY_OFFERS->value);
    }

    protected function showOfferManagement(ConversationSession $session): void
    {
        $offerId = $this->getTemp($session, 'manage_offer_id');
        $offer = Offer::find($offerId);

        if (!$offer) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Offer not found.",
                [
                    ['id' => 'back', 'title' => '⬅️ My Offers'],
                    self::MENU_BUTTON,
                ]
            );
            $this->showMyOffers($session);
            return;
        }

        // Show offer preview
        if ($offer->media_type === 'image') {
            $this->sendImage(
                $session->phone,
                $offer->media_url,
                $offer->caption ?: 'Your offer'
            );
        } else {
            $this->sendDocument(
                $session->phone,
                $offer->media_url,
                'Offer.pdf',
                $offer->caption
            );
        }

        // Show stats summary with buttons
        $statsMessage = OfferMessages::format(OfferMessages::OFFER_STATS, [
            'views' => $offer->view_count,
            'location_taps' => $offer->location_tap_count,
            'expiry' => OfferMessages::formatExpiry($offer->expires_at),
        ]);

        $this->sendButtonsWithMenu(
            $session->phone,
            $statsMessage . "\n\nWhat would you like to do?",
            [
                ['id' => 'stats', 'title' => '📊 View Stats'],
                ['id' => 'delete', 'title' => '🗑️ Delete Offer'],
            ]
        );
    }

    protected function showOfferStats(ConversationSession $session): void
    {
        $offerId = $this->getTemp($session, 'manage_offer_id');
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

        $this->sendButtonsWithMenu(
            $session->phone,
            $stats,
            [
                ['id' => 'delete', 'title' => '🗑️ Delete Offer'],
                ['id' => 'back', 'title' => '⬅️ Back to Offers'],
            ]
        );
    }

    protected function confirmDelete(ConversationSession $session): void
    {
        $this->nextStep($session, OfferStep::DELETE_CONFIRM->value);
        $this->showDeleteConfirmation($session);
    }

    protected function showDeleteConfirmation(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            OfferMessages::DELETE_CONFIRM,
            [
                ['id' => 'confirm_delete', 'title' => '✅ Yes, Delete'],
                ['id' => 'cancel_delete', 'title' => '❌ Cancel'],
                self::MENU_BUTTON,
            ],
            null,
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Action Methods
    |--------------------------------------------------------------------------
    */

    protected function deleteOffer(ConversationSession $session): void
    {
        $offerId = $this->getTemp($session, 'manage_offer_id');
        $offer = Offer::find($offerId);

        if (!$offer) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Offer not found.",
                [
                    ['id' => 'back', 'title' => '⬅️ My Offers'],
                    self::MENU_BUTTON,
                ]
            );
            $this->showMyOffers($session);
            return;
        }

        $deleted = $this->offerService->deleteOffer($offer);

        if ($deleted) {
            $this->sendButtonsWithMenu(
                $session->phone,
                OfferMessages::OFFER_DELETED,
                [['id' => 'upload_new', 'title' => '📤 Upload New']]
            );

            $this->logInfo('Offer deleted by owner', [
                'offer_id' => $offerId,
                'phone' => $this->maskPhone($session->phone),
            ]);
        } else {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed to delete offer. Please try again.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );
        }

        $this->sessionManager->removeTempData($session, 'manage_offer_id');
        $this->showMyOffers($session);
    }

    protected function cancelDelete(ConversationSession $session): void
    {
        $this->sendTextWithMenu($session->phone, "✅ Deletion cancelled.");
        $this->nextStep($session, OfferStep::MANAGE_OFFER->value);
        $this->showOfferManagement($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation Methods
    |--------------------------------------------------------------------------
    */

    protected function goToUpload(ConversationSession $session): void
    {
        $this->goToFlow($session, FlowType::OFFERS_UPLOAD, OfferStep::UPLOAD_IMAGE->value);
        app(OfferUploadFlowHandler::class)->start($session);
    }
}