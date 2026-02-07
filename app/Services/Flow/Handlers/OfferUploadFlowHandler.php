<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\OfferStep;
use App\Enums\OfferValidity;
use App\Models\ConversationSession;
use App\Services\Media\MediaService;
use App\Services\Offers\OfferService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\Messages\OfferMessages;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Offer Upload Flow Handler - Simplified 3-step flow.
 *
 * FLOW (minimal friction):
 * 1. ASK_IMAGE → "🛍️ Offer upload cheyyaam! Photo or PDF ayakkuka 📸"
 * 2. ASK_VALIDITY → "⏰ Evide vare valid?" [Today] [3 Days] [This Week]
 * 3. DONE → "✅ Offer live aayi! 🎉" + reach estimate + metrics
 *
 * No caption step, no confirmation step = Kerala shops just want to share posters fast.
 *
 * @srs-ref FR-OFR-01 - Accept image (JPEG, PNG) AND PDF uploads
 * @srs-ref FR-OFR-02 - Download media using WhatsApp Media API
 * @srs-ref FR-OFR-03 - Store in cloud storage with unique identifiers
 * @srs-ref FR-OFR-04 - Prompt validity period (Today / 3 Days / This Week)
 * @srs-ref FR-OFR-05 - Confirm publication, show estimated customer reach
 * @srs-ref FR-OFR-06 - Track offer view counts and location tap metrics
 */
class OfferUploadFlowHandler implements FlowHandlerInterface
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected OfferService $offerService,
        protected MediaService $mediaService,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return FlowType::OFFERS_UPLOAD->value;
    }

    /**
     * {@inheritdoc}
     */
    public function canHandleStep(string $step): bool
    {
        $offerStep = OfferStep::tryFrom($step);
        return $offerStep !== null && $offerStep->isUploadStep();
    }

    /*
    |--------------------------------------------------------------------------
    | Entry Point
    |--------------------------------------------------------------------------
    */

    /**
     * Start upload flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Check: is shop owner?
        if (!$user || !$user->isShopOwner()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                OfferMessages::SHOP_REQUIRED,
                [
                    ['id' => 'register_shop', 'title' => '🏪 Register Shop'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        $shop = $user->shop;

        // Check: max offers?
        if (!$this->offerService->canUploadOffer($shop)) {
            $maxOffers = config('nearbuy.offers.max_active_per_shop', 5);
            $this->whatsApp->sendButtons(
                $session->phone,
                OfferMessages::format(OfferMessages::MAX_OFFERS, ['max' => $maxOffers]),
                [
                    ['id' => 'my_offers', 'title' => '🏷️ My Offers'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Clear temp data, store shop_id
        $this->sessionManager->clearTempData($session);
        $this->sessionManager->setTempData($session, 'shop_id', $shop->id);

        // Start: Step 1 - ASK_IMAGE
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_UPLOAD,
            OfferStep::ASK_IMAGE->value
        );

        $this->promptAskImage($session);

        Log::info('Offer upload started', [
            'phone' => $this->maskPhone($session->phone),
            'shop_id' => $shop->id,
        ]);
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

        if (!$step || !$step->isUploadStep()) {
            $this->start($session);
            return;
        }

        match ($step) {
            OfferStep::ASK_IMAGE => $this->handleImage($message, $session),
            OfferStep::ASK_VALIDITY => $this->handleValidity($message, $session),
            OfferStep::DONE => $this->handleDone($message, $session),
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
            OfferStep::ASK_IMAGE => $this->promptAskImage($session, true),
            OfferStep::ASK_VALIDITY => $this->promptAskValidity($session, true),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Image/PDF Upload (FR-OFR-01, FR-OFR-02, FR-OFR-03)
    |--------------------------------------------------------------------------
    */

    protected function handleImage(IncomingMessage $message, ConversationSession $session): void
    {
        // FR-OFR-01: Accept image (JPEG, PNG) AND PDF uploads
        if (!$message->isImage() && !$message->isDocument()) {
            $this->promptAskImage($session, true);
            return;
        }

        $mediaId = $message->getMediaId();

        if (!$mediaId) {
            $this->promptAskImage($session, true);
            return;
        }

        // Show processing
        $this->whatsApp->sendText($session->phone, OfferMessages::PROCESSING);

        // FR-OFR-02: Download using WhatsApp Media API with authentication
        $result = $this->mediaService->downloadAndStore(
            mediaId: $mediaId,
            folder: 'offers',
            filename: null
        );

        if (!$result['success']) {
            Log::error('Offer media upload failed', [
                'phone' => $this->maskPhone($session->phone),
                'error' => $result['error'] ?? 'Unknown',
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                OfferMessages::UPLOAD_FAILED,
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        // Validate media type (image or pdf only)
        $mediaType = $this->mediaService->getMediaType($result['mime_type'] ?? '');

        if (!in_array($mediaType, ['image', 'pdf'])) {
            $this->whatsApp->sendText($session->phone, OfferMessages::INVALID_MEDIA);
            return;
        }

        // FR-OFR-03: Store in cloud storage with unique identifiers
        $this->sessionManager->mergeTempData($session, [
            'media_url' => $result['url'],
            'media_type' => $mediaType,
        ]);

        Log::info('Offer media uploaded', [
            'phone' => $this->maskPhone($session->phone),
            'url' => $result['url'],
            'type' => $mediaType,
        ]);

        // Move to Step 2: ASK_VALIDITY
        $this->sessionManager->setStep($session, OfferStep::ASK_VALIDITY->value);
        $this->promptAskValidity($session);
    }

    protected function promptAskImage(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry ? OfferMessages::INVALID_MEDIA : OfferMessages::ASK_IMAGE;

        // Just text - user needs to send media, not tap button
        $this->whatsApp->sendText($session->phone, $message);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Validity Selection (FR-OFR-04)
    |--------------------------------------------------------------------------
    */

    protected function handleValidity(IncomingMessage $message, ConversationSession $session): void
    {
        $validity = null;

        // From button
        if ($message->isButtonReply()) {
            $validity = OfferValidity::tryFrom($message->getSelectionId() ?? '');
        }

        // From text
        if (!$validity && $message->isText()) {
            $validity = OfferValidity::fromText($message->text ?? '');
        }

        if (!$validity) {
            $this->promptAskValidity($session, true);
            return;
        }

        $this->sessionManager->setTempData($session, 'validity', $validity->value);

        Log::info('Offer validity selected', [
            'phone' => $this->maskPhone($session->phone),
            'validity' => $validity->value,
        ]);

        // Directly publish (no confirmation step = less friction)
        $this->publishOffer($session, $validity);
    }

    protected function promptAskValidity(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry ? OfferMessages::INVALID_VALIDITY : OfferMessages::ASK_VALIDITY;

        // FR-OFR-04: Today / 3 Days / This Week buttons
        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            OfferMessages::validityButtons()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Publish & Done (FR-OFR-05, FR-OFR-06)
    |--------------------------------------------------------------------------
    */

    protected function publishOffer(ConversationSession $session, OfferValidity $validity): void
    {
        try {
            $user = $this->getUser($session);
            $shop = $user->shop;

            // Create offer with view_count=0, location_tap_count=0 (FR-OFR-06)
            $offer = $this->offerService->createOffer($shop, [
                'media_url' => $this->sessionManager->getTempData($session, 'media_url'),
                'media_type' => $this->sessionManager->getTempData($session, 'media_type'),
                'validity' => $validity->value,
            ]);

            // FR-OFR-05: Calculate estimated customer reach
            $radiusKm = config('nearbuy.offers.default_radius_km', 5);
            $reach = $this->offerService->calculateEstimatedReach($shop, $radiusKm);

            // Build success message
            $successMessage = OfferMessages::buildSuccessMessage($reach, $offer->expires_at);

            // Send success with action buttons
            $this->whatsApp->sendButtons(
                $session->phone,
                $successMessage,
                OfferMessages::successButtons()
            );

            // Clear temp data
            $this->sessionManager->clearTempData($session);

            // Mark as DONE
            $this->sessionManager->setStep($session, OfferStep::DONE->value);

            Log::info('Offer published', [
                'offer_id' => $offer->id,
                'shop_id' => $shop->id,
                'validity' => $validity->value,
                'reach' => $reach,
            ]);

        } catch (\Exception $e) {
            Log::error('Offer publish failed', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            // Cleanup uploaded media
            $mediaUrl = $this->sessionManager->getTempData($session, 'media_url');
            if ($mediaUrl) {
                $this->mediaService->deleteFromStorage($mediaUrl);
            }

            $this->whatsApp->sendButtons(
                $session->phone,
                OfferMessages::UPLOAD_FAILED,
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
        }
    }

    protected function handleDone(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        }

        match ($action) {
            'upload_another' => $this->start($session),
            'my_offers' => $this->goToMyOffers($session),
            default => $this->sessionManager->resetToMainMenu($session),
        };
    }

    protected function goToMyOffers(ConversationSession $session): void
    {
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_MANAGE,
            OfferStep::SHOW_MY_OFFERS->value
        );

        // Let the manage handler take over
        // app(OfferManageFlowHandler::class)->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Interface Methods
    |--------------------------------------------------------------------------
    */

    /**
     * {@inheritdoc}
     */
    public function getExpectedInputType(string $step): string
    {
        $offerStep = OfferStep::tryFrom($step);
        return $offerStep?->expectedInput() ?? 'text';
    }

    /**
     * {@inheritdoc}
     */
    public function handleTimeout(ConversationSession $session): void
    {
        $step = OfferStep::tryFrom($session->current_step);

        // If media was uploaded but not completed, cleanup
        if ($step === OfferStep::ASK_VALIDITY) {
            $mediaUrl = $this->sessionManager->getTempData($session, 'media_url');
            if ($mediaUrl) {
                $this->mediaService->deleteFromStorage($mediaUrl);
            }
        }

        $this->whatsApp->sendText(
            $session->phone,
            "⏰ Upload timeout. Type *offer* to start again."
        );

        $this->sessionManager->clearTempData($session);
        $this->sessionManager->resetToMainMenu($session);
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

    protected function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 6) {
            return str_repeat('*', $len);
        }
        return substr($phone, 0, 3) . str_repeat('*', $len - 6) . substr($phone, -3);
    }
}