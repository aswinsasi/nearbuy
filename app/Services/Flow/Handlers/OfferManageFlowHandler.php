<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\OfferStep;
use App\Enums\OfferValidity;
use App\Models\ConversationSession;
use App\Models\Offer;
use App\Services\Offers\OfferService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Offer Manage Flow Handler - Shop owner manages their offers.
 *
 * FLOW:
 * 1. SHOW_MY_OFFERS → List with view counts + [📸 Upload New]
 * 2. MANAGE_OFFER → [📊 Stats] [🔄 Extend] [❌ Delete]
 * 3. DELETE_CONFIRM → Confirm before delete
 * 4. EXTEND_VALIDITY → Select new validity period
 *
 * @srs-ref FR-OFR-06 - Track offer view counts and location tap metrics
 */
class OfferManageFlowHandler implements FlowHandlerInterface
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected OfferService $offerService,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return FlowType::OFFERS_MANAGE->value;
    }

    /**
     * {@inheritdoc}
     */
    public function canHandleStep(string $step): bool
    {
        $offerStep = OfferStep::tryFrom($step);
        return $offerStep !== null && $offerStep->isManageStep();
    }

    /**
     * {@inheritdoc}
     */
    public function getExpectedInputType(string $step): string
    {
        return OfferStep::tryFrom($step)?->expectedInput() ?? 'button';
    }

    /*
    |--------------------------------------------------------------------------
    | Entry Point
    |--------------------------------------------------------------------------
    */

    /**
     * Start manage flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Check: is shop owner?
        if (!$user || !$user->isShopOwner()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                "⚠️ Shop owners mathram offers manage cheyyaan pattuu.\n\nShop register cheyyuka first.",
                [
                    ['id' => 'register_shop', 'title' => '🏪 Register Shop'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Clear previous state
        $this->sessionManager->removeTempData($session, 'manage_offer_id');

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_MANAGE,
            OfferStep::SHOW_MY_OFFERS->value
        );

        $this->showMyOffers($session);

        Log::info('Offer manage started', ['phone' => $this->maskPhone($session->phone)]);
    }

    /*
    |--------------------------------------------------------------------------
    | Main Handler
    |--------------------------------------------------------------------------
    */

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = OfferStep::tryFrom($session->current_step);

        if (!$step || !$step->isManageStep()) {
            $this->start($session);
            return;
        }

        match ($step) {
            OfferStep::SHOW_MY_OFFERS => $this->handleOfferSelect($message, $session),
            OfferStep::MANAGE_OFFER => $this->handleManageAction($message, $session),
            OfferStep::DELETE_CONFIRM => $this->handleDeleteConfirm($message, $session),
            OfferStep::EXTEND_VALIDITY => $this->handleExtendValidity($message, $session),
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
            OfferStep::MANAGE_OFFER => $this->showManageOptions($session),
            OfferStep::DELETE_CONFIRM => $this->showDeleteConfirm($session),
            OfferStep::EXTEND_VALIDITY => $this->showExtendOptions($session),
            default => $this->start($session),
        };
    }

    /**
     * Handle timeout.
     */
    public function handleTimeout(ConversationSession $session): void
    {
        $this->whatsApp->sendText(
            $session->phone,
            "⏰ Session expired. Type *my offers* to manage again."
        );
        $this->sessionManager->resetToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Show My Offers
    |--------------------------------------------------------------------------
    */

    protected function handleOfferSelect(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = null;

        if ($message->isListReply() || $message->isButtonReply()) {
            $selection = $message->getSelectionId();
        }

        // Handle navigation
        if ($selection === 'upload_new') {
            $this->goToUpload($session);
            return;
        }

        if ($selection === 'main_menu' || $selection === 'menu') {
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Handle offer selection
        if ($selection && str_starts_with($selection, 'manage_')) {
            $offerId = (int) str_replace('manage_', '', $selection);

            // Verify ownership
            $user = $this->getUser($session);
            $offer = Offer::find($offerId);

            if (!$offer || $offer->shop_id !== $user->shop?->id) {
                $this->whatsApp->sendText($session->phone, "❌ Offer not found.");
                $this->showMyOffers($session);
                return;
            }

            $this->sessionManager->setTempData($session, 'manage_offer_id', $offerId);
            $this->sessionManager->setStep($session, OfferStep::MANAGE_OFFER->value);
            $this->showManageOptions($session);
            return;
        }

        $this->showMyOffers($session);
    }

    /**
     * Show shop owner's offers with stats.
     */
    protected function showMyOffers(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $shop = $user->shop;

        $offers = $this->offerService->getShopOffers($shop);

        if ($offers->isEmpty()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                "📭 *No active offers*\n\nUpload cheythu customers-നെ attract cheyyuka!",
                [
                    ['id' => 'upload_new', 'title' => '📸 Upload Offer'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        // Build header with total stats
        $totalViews = $offers->sum('view_count');
        $totalTaps = $offers->sum('location_tap_count');

        $header = "🏷️ *Ninte Offers*\n\n" .
            "📊 Total: 👀 {$totalViews} views | 📍 {$totalTaps} taps\n\n" .
            "{$offers->count()} active offer(s):";

        // Build rows with stats
        $rows = [];
        foreach ($offers as $i => $offer) {
            $expiry = $this->formatExpiry($offer->expires_at);
            $views = $offer->view_count;

            $rows[] = [
                'id' => "manage_{$offer->id}",
                'title' => mb_substr("Offer #" . ($i + 1), 0, 24),
                'description' => mb_substr("👀 {$views} views • {$expiry}", 0, 72),
            ];
        }

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '🏷️ Manage',
            [['title' => 'Your Offers', 'rows' => array_slice($rows, 0, 10)]],
            'Select to manage'
        );

        // Upload button
        $this->whatsApp->sendButtons(
            $session->phone,
            "Select an offer above, or:",
            [
                ['id' => 'upload_new', 'title' => '📸 Upload New'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Manage Single Offer
    |--------------------------------------------------------------------------
    */

    protected function handleManageAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = mb_strtolower(trim($message->text ?? ''));
            if (str_contains($text, 'delete') || str_contains($text, 'remove')) {
                $action = 'delete';
            } elseif (str_contains($text, 'extend') || str_contains($text, 'renew')) {
                $action = 'extend';
            } elseif (str_contains($text, 'stat')) {
                $action = 'stats';
            } elseif (str_contains($text, 'back')) {
                $action = 'back';
            }
        }

        match ($action) {
            'stats' => $this->showOfferStats($session),
            'extend' => $this->showExtendOptions($session),
            'delete' => $this->showDeleteConfirm($session),
            'back' => $this->backToMyOffers($session),
            'main_menu', 'menu' => $this->sessionManager->resetToMainMenu($session),
            default => $this->showManageOptions($session),
        };
    }

    /**
     * Show manage options for single offer.
     */
    protected function showManageOptions(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'manage_offer_id');
        $offer = Offer::with('shop')->find($offerId);

        if (!$offer) {
            $this->whatsApp->sendText($session->phone, "❌ Offer not found.");
            $this->backToMyOffers($session);
            return;
        }

        // Show offer preview
        if ($offer->isImage()) {
            $this->whatsApp->sendImage(
                $session->phone,
                $offer->media_url,
                $offer->caption ?? "Offer #{$offer->id}"
            );
        } elseif ($offer->isPdf()) {
            $this->whatsApp->sendDocument(
                $session->phone,
                $offer->media_url,
                'Offer.pdf',
                $offer->caption
            );
        }

        // Stats summary
        $expiry = $this->formatExpiry($offer->expires_at);
        $stats = "📊 *Stats*\n\n" .
            "👀 Views: {$offer->view_count}\n" .
            "📍 Location taps: {$offer->location_tap_count}\n" .
            "⏰ Expires: {$expiry}";

        if ($offer->isExpired()) {
            $stats .= "\n\n⚠️ *This offer has expired*";
        }

        $this->whatsApp->sendButtons(
            $session->phone,
            $stats,
            [
                ['id' => 'extend', 'title' => '🔄 Extend Validity'],
                ['id' => 'delete', 'title' => '❌ Delete'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );
    }

    /**
     * Show detailed offer stats.
     */
    protected function showOfferStats(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'manage_offer_id');
        $offer = Offer::find($offerId);

        if (!$offer) {
            $this->backToMyOffers($session);
            return;
        }

        $created = $offer->created_at->format('M j, Y g:i A');
        $expiry = $this->formatExpiry($offer->expires_at);

        $stats = "📊 *Offer Statistics*\n\n" .
            "👀 *Views:* {$offer->view_count}\n" .
            "📍 *Location taps:* {$offer->location_tap_count}\n" .
            "📅 *Created:* {$created}\n" .
            "⏰ *Expires:* {$expiry}\n" .
            "📝 *Validity:* {$offer->validity_type->label()}\n\n" .
            ($offer->isExpired() ? "❌ This offer has expired." : "✅ This offer is active.");

        $this->whatsApp->sendButtons(
            $session->phone,
            $stats,
            [
                ['id' => 'extend', 'title' => '🔄 Extend'],
                ['id' => 'delete', 'title' => '❌ Delete'],
                ['id' => 'back', 'title' => '⬅️ Back'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Delete Confirmation
    |--------------------------------------------------------------------------
    */

    protected function handleDeleteConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = mb_strtolower(trim($message->text ?? ''));
            if (in_array($text, ['yes', 'confirm', 'delete', '1', 'aam', 'ആം'])) {
                $action = 'confirm_delete';
            } elseif (in_array($text, ['no', 'cancel', '2', 'venda', 'വേണ്ട'])) {
                $action = 'cancel';
            }
        }

        match ($action) {
            'confirm_delete' => $this->deleteOffer($session),
            'cancel' => $this->cancelDelete($session),
            default => $this->showDeleteConfirm($session),
        };
    }

    protected function showDeleteConfirm(ConversationSession $session): void
    {
        $this->sessionManager->setStep($session, OfferStep::DELETE_CONFIRM->value);

        $this->whatsApp->sendButtons(
            $session->phone,
            "🗑️ *Offer delete cheyyano?*\n\nIth undo cheyyaan pattilla.",
            [
                ['id' => 'confirm_delete', 'title' => '✅ Yes, Delete'],
                ['id' => 'cancel', 'title' => '❌ No, Keep'],
            ]
        );
    }

    protected function deleteOffer(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'manage_offer_id');
        $offer = Offer::find($offerId);

        if (!$offer) {
            $this->whatsApp->sendText($session->phone, "❌ Offer not found.");
            $this->backToMyOffers($session);
            return;
        }

        $deleted = $this->offerService->deleteOffer($offer);

        if ($deleted) {
            $this->whatsApp->sendButtons(
                $session->phone,
                "✅ Offer deleted.",
                [
                    ['id' => 'upload_new', 'title' => '📸 Upload New'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );

            Log::info('Offer deleted by owner', [
                'offer_id' => $offerId,
                'phone' => $this->maskPhone($session->phone),
            ]);
        } else {
            $this->whatsApp->sendText($session->phone, "❌ Delete failed. Try again.");
        }

        $this->sessionManager->removeTempData($session, 'manage_offer_id');
        $this->sessionManager->setStep($session, OfferStep::SHOW_MY_OFFERS->value);
    }

    protected function cancelDelete(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, "✅ Delete cancelled.");
        $this->sessionManager->setStep($session, OfferStep::MANAGE_OFFER->value);
        $this->showManageOptions($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Extend Validity
    |--------------------------------------------------------------------------
    */

    protected function handleExtendValidity(IncomingMessage $message, ConversationSession $session): void
    {
        $validity = null;

        if ($message->isButtonReply()) {
            $validity = OfferValidity::tryFrom($message->getSelectionId() ?? '');
        } elseif ($message->isText()) {
            $validity = OfferValidity::fromText($message->text ?? '');
        }

        if ($validity === null) {
            if ($message->getSelectionId() === 'cancel' || $message->getSelectionId() === 'back') {
                $this->sessionManager->setStep($session, OfferStep::MANAGE_OFFER->value);
                $this->showManageOptions($session);
                return;
            }

            $this->showExtendOptions($session);
            return;
        }

        $this->extendOffer($session, $validity);
    }

    protected function showExtendOptions(ConversationSession $session): void
    {
        $this->sessionManager->setStep($session, OfferStep::EXTEND_VALIDITY->value);

        $this->whatsApp->sendButtons(
            $session->phone,
            "🔄 *Extend Validity*\n\nNew validity select cheyyuka:",
            [
                ['id' => OfferValidity::TODAY->value, 'title' => '⏰ Today'],
                ['id' => OfferValidity::THREE_DAYS->value, 'title' => '📅 3 Days'],
                ['id' => OfferValidity::THIS_WEEK->value, 'title' => '🗓️ This Week'],
            ]
        );
    }

    protected function extendOffer(ConversationSession $session, OfferValidity $validity): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'manage_offer_id');
        $offer = Offer::find($offerId);

        if (!$offer) {
            $this->whatsApp->sendText($session->phone, "❌ Offer not found.");
            $this->backToMyOffers($session);
            return;
        }

        $offer->extendValidity($validity);
        $expiry = $this->formatExpiry($offer->expires_at);

        $this->whatsApp->sendButtons(
            $session->phone,
            "✅ *Offer extended!*\n\n⏰ New expiry: {$expiry}",
            [
                ['id' => 'back', 'title' => '⬅️ Back'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );

        Log::info('Offer extended', [
            'offer_id' => $offerId,
            'validity' => $validity->value,
            'phone' => $this->maskPhone($session->phone),
        ]);

        $this->sessionManager->setStep($session, OfferStep::MANAGE_OFFER->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    protected function backToMyOffers(ConversationSession $session): void
    {
        $this->sessionManager->removeTempData($session, 'manage_offer_id');
        $this->sessionManager->setStep($session, OfferStep::SHOW_MY_OFFERS->value);
        $this->showMyOffers($session);
    }

    protected function goToUpload(ConversationSession $session): void
    {
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_UPLOAD,
            OfferStep::ASK_IMAGE->value
        );

        // Let the upload handler take over
        app(OfferUploadFlowHandler::class)->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function getUser(ConversationSession $session): ?\App\Models\User
    {
        if ($session->user_id) {
            return \App\Models\User::find($session->user_id);
        }
        return \App\Models\User::where('phone', $session->phone)->first();
    }

    protected function formatExpiry(\Carbon\Carbon $date): string
    {
        if ($date->isPast()) {
            return 'Expired';
        }
        if ($date->isToday()) {
            return 'Today ' . $date->format('g:i A');
        }
        if ($date->isTomorrow()) {
            return 'Tomorrow';
        }
        if ($date->diffInDays(now()) < 7) {
            return $date->format('l');
        }
        return $date->format('M j');
    }

    protected function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 6) return str_repeat('*', $len);
        return substr($phone, 0, 3) . str_repeat('*', $len - 6) . substr($phone, -3);
    }
}