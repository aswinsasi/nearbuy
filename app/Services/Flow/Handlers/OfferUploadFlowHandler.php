<?php

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\OfferStep;
use App\Models\ConversationSession;
use App\Services\Media\MediaService;
use App\Services\Offers\OfferService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\OfferMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handles the offer upload flow for shop owners.
 *
 * Flow Steps:
 * 1. upload_image - Receive image/PDF from shop owner
 * 2. add_caption - Optional caption for the offer
 * 3. select_validity - How long the offer is valid
 * 4. confirm_upload - Review and confirm
 * 5. upload_complete - Success message
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
     * Get the flow name.
     */
    public function getName(): string
    {
        return FlowType::OFFERS_UPLOAD->value;
    }

    /**
     * Check if this handler can process the given step.
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, [
            OfferStep::UPLOAD_IMAGE->value,
            OfferStep::ADD_CAPTION->value,
            OfferStep::SELECT_VALIDITY->value,
            OfferStep::CONFIRM_UPLOAD->value,
            OfferStep::UPLOAD_COMPLETE->value,
        ]);
    }

    /**
     * Start the upload flow.
     */
    public function start(ConversationSession $session): void
    {
        // Verify user is a shop owner
        $user = $this->sessionManager->getUser($session);

        if (!$user || !$user->isShopOwner()) {
            $this->whatsApp->sendText(
                $session->phone,
                "⚠️ Only shop owners can upload offers.\n\nPlease register as a shop owner first."
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        $shop = $user->shop;

        // Check if can upload more offers
        if (!$this->offerService->canUploadOffer($shop)) {
            $maxOffers = config('nearbuy.offers.max_active_per_shop', 5);
            $message = OfferMessages::format(OfferMessages::MAX_OFFERS_REACHED, [
                'max' => $maxOffers,
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                [
                    ['id' => 'my_offers', 'title' => '🏷️ Manage Offers'],
                    ['id' => 'menu', 'title' => '🏠 Main Menu'],
                ]
            );

            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Clear temp data and start
        $this->sessionManager->clearTempData($session);
        $this->sessionManager->setTempData($session, 'shop_id', $shop->id);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_UPLOAD,
            OfferStep::UPLOAD_IMAGE->value
        );

        $this->askForMedia($session);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = OfferStep::tryFrom($session->current_step);

        if (!$step) {
            Log::warning('Invalid offer upload step', ['step' => $session->current_step]);
            $this->start($session);
            return;
        }

        match ($step) {
            OfferStep::UPLOAD_IMAGE => $this->handleMediaUpload($message, $session),
            OfferStep::ADD_CAPTION => $this->handleCaptionInput($message, $session),
            OfferStep::SELECT_VALIDITY => $this->handleValiditySelection($message, $session),
            OfferStep::CONFIRM_UPLOAD => $this->handleConfirmation($message, $session),
            OfferStep::UPLOAD_COMPLETE => $this->handlePostUpload($message, $session),
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
            OfferStep::UPLOAD_IMAGE => $this->askForMedia($session, true),
            OfferStep::ADD_CAPTION => $this->askForCaption($session, true),
            OfferStep::SELECT_VALIDITY => $this->askForValidity($session, true),
            OfferStep::CONFIRM_UPLOAD => $this->askForConfirmation($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    /**
     * Handle media upload (image or PDF).
     */
    protected function handleMediaUpload(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for image or document
        if (!$message->isImage() && !$message->isDocument()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $mediaId = $message->getMediaId();

        if (!$mediaId) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Download from WhatsApp and upload to S3
        $this->whatsApp->sendText($session->phone, "⏳ Processing your media...");

        $result = $this->mediaService->downloadAndStore(
            mediaId: $mediaId,
            folder: 'offers',
            filename: null
        );

        if (!$result['success']) {
            Log::error('Offer media upload failed', [
                'phone' => $session->phone,
                'error' => $result['error'] ?? 'Unknown error',
            ]);

            $this->whatsApp->sendText(
                $session->phone,
                "❌ Failed to process media. Please try again."
            );
            $this->askForMedia($session);
            return;
        }

        // Validate media type
        $mediaType = $this->mediaService->getMediaType($result['mime_type']);

        if (!in_array($mediaType, ['image', 'pdf'])) {
            $this->whatsApp->sendText($session->phone, OfferMessages::INVALID_MEDIA);
            $this->askForMedia($session);
            return;
        }

        // Store media info
        $this->sessionManager->mergeTempData($session, [
            'media_url' => $result['url'],
            'media_type' => $mediaType,
        ]);

        Log::info('Offer media uploaded', [
            'phone' => $this->maskPhone($session->phone),
            'url' => $result['url'],
            'type' => $mediaType,
        ]);

        // Move to caption step
        $this->sessionManager->setStep($session, OfferStep::ADD_CAPTION->value);
        $this->askForCaption($session);
    }

    /**
     * Handle caption input.
     */
    protected function handleCaptionInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $text = trim($message->text ?? '');

        // Check for skip
        if (strtolower($text) === 'skip') {
            $this->sessionManager->setTempData($session, 'caption', null);
        } else {
            // Validate caption length
            if (mb_strlen($text) > 500) {
                $this->whatsApp->sendText($session->phone, OfferMessages::CAPTION_TOO_LONG);
                $this->askForCaption($session);
                return;
            }

            $this->sessionManager->setTempData($session, 'caption', $text);
        }

        // Move to validity selection
        $this->sessionManager->setStep($session, OfferStep::SELECT_VALIDITY->value);
        $this->askForValidity($session);
    }

    /**
     * Handle validity selection.
     */
    protected function handleValiditySelection(IncomingMessage $message, ConversationSession $session): void
    {
        $validity = null;

        if ($message->isButtonReply()) {
            $validity = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            $validity = $this->matchValidity($text);
        }

        if (!in_array($validity, ['today', '3days', 'week'])) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->sessionManager->setTempData($session, 'validity', $validity);

        // Move to confirmation
        $this->sessionManager->setStep($session, OfferStep::CONFIRM_UPLOAD->value);
        $this->askForConfirmation($session);
    }

    /**
     * Handle confirmation response.
     */
    protected function handleConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (in_array($text, ['yes', 'publish', 'ok', '1'])) {
                $action = 'publish';
            } elseif (in_array($text, ['edit', 'change', '2'])) {
                $action = 'edit';
            } elseif (in_array($text, ['no', 'cancel', '3'])) {
                $action = 'cancel';
            }
        }

        match ($action) {
            'publish' => $this->publishOffer($session),
            'edit' => $this->restartUpload($session),
            'cancel' => $this->cancelUpload($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    /**
     * Handle post-upload actions.
     */
    protected function handlePostUpload(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        }

        match ($action) {
            'upload_another' => $this->start($session),
            'my_offers' => $this->goToMyOffers($session),
            default => $this->goToMainMenu($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Prompt Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Ask for media upload.
     */
    protected function askForMedia(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry ? OfferMessages::INVALID_MEDIA : OfferMessages::UPLOAD_START;

        $this->whatsApp->sendText($session->phone, $message);
    }

    /**
     * Ask for caption.
     */
    protected function askForCaption(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please type a caption or send 'skip'."
            : OfferMessages::ASK_CAPTION;

        $this->whatsApp->sendText($session->phone, $message);
    }

    /**
     * Ask for validity.
     */
    protected function askForValidity(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select how long this offer should be valid:"
            : OfferMessages::ASK_VALIDITY;

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            OfferMessages::getValidityButtons()
        );
    }

    /**
     * Ask for confirmation.
     */
    protected function askForConfirmation(ConversationSession $session): void
    {
        $caption = $this->sessionManager->getTempData($session, 'caption');
        $validity = $this->sessionManager->getTempData($session, 'validity');
        $mediaUrl = $this->sessionManager->getTempData($session, 'media_url');
        $mediaType = $this->sessionManager->getTempData($session, 'media_type');

        // Calculate estimated reach
        $user = $this->sessionManager->getUser($session);
        $shop = $user->shop;
        $radius = config('nearbuy.offers.default_radius_km', 5);
        $reach = $this->offerService->calculateEstimatedReach($shop, $radius);

        $displayCaption = $caption ?: '(No caption)';

        $message = OfferMessages::format(OfferMessages::UPLOAD_CONFIRM, [
            'caption' => $displayCaption,
            'validity' => OfferMessages::formatValidity($validity),
            'reach' => $reach,
        ]);

        // Send the media preview first
        if ($mediaType === 'image') {
            $this->whatsApp->sendImage($session->phone, $mediaUrl);
        } else {
            $this->whatsApp->sendDocument($session->phone, $mediaUrl, 'Offer.pdf');
        }

        // Then send confirmation buttons
        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            OfferMessages::getConfirmButtons()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Action Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Publish the offer.
     */
    protected function publishOffer(ConversationSession $session): void
    {
        try {
            $user = $this->sessionManager->getUser($session);
            $shop = $user->shop;

            $offer = $this->offerService->createOffer($shop, [
                'media_url' => $this->sessionManager->getTempData($session, 'media_url'),
                'media_type' => $this->sessionManager->getTempData($session, 'media_type'),
                'caption' => $this->sessionManager->getTempData($session, 'caption'),
                'validity' => $this->sessionManager->getTempData($session, 'validity'),
            ]);

            $radius = config('nearbuy.offers.default_radius_km', 5);
            $reach = $this->offerService->calculateEstimatedReach($shop, $radius);

            $message = OfferMessages::format(OfferMessages::UPLOAD_SUCCESS, [
                'radius' => $radius,
                'reach' => $reach,
                'expiry_date' => OfferMessages::formatExpiry($offer->expires_at),
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                OfferMessages::getPostUploadButtons()
            );

            // Clear temp data
            $this->sessionManager->clearTempData($session);

            // Update step
            $this->sessionManager->setStep($session, OfferStep::UPLOAD_COMPLETE->value);

            Log::info('Offer published', [
                'offer_id' => $offer->id,
                'shop_id' => $shop->id,
                'phone' => $this->maskPhone($session->phone),
            ]);

        } catch (\Exception $e) {
            Log::error('Offer publish failed', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            $this->whatsApp->sendText(
                $session->phone,
                "❌ Failed to publish offer: " . $e->getMessage() . "\n\nPlease try again."
            );

            $this->restartUpload($session);
        }
    }

    /**
     * Restart the upload flow.
     */
    protected function restartUpload(ConversationSession $session): void
    {
        // Delete uploaded media if exists
        $mediaUrl = $this->sessionManager->getTempData($session, 'media_url');
        if ($mediaUrl) {
            $this->mediaService->deleteFromStorage($mediaUrl);
        }

        $this->whatsApp->sendText(
            $session->phone,
            "🔄 Let's start over. Your previous upload has been cleared."
        );

        $this->start($session);
    }

    /**
     * Cancel the upload.
     */
    protected function cancelUpload(ConversationSession $session): void
    {
        // Delete uploaded media if exists
        $mediaUrl = $this->sessionManager->getTempData($session, 'media_url');
        if ($mediaUrl) {
            $this->mediaService->deleteFromStorage($mediaUrl);
        }

        $this->sessionManager->clearTempData($session);

        $this->whatsApp->sendText($session->phone, OfferMessages::UPLOAD_CANCELLED);

        $this->goToMainMenu($session);
    }

    /**
     * Go to my offers.
     */
    protected function goToMyOffers(ConversationSession $session): void
    {
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_MANAGE,
            OfferStep::SHOW_MY_OFFERS->value
        );

        // Trigger the manage handler
        $manageHandler = app(OfferManageFlowHandler::class);
        $manageHandler->start($session);
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

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Match text input to validity option.
     */
    protected function matchValidity(string $text): ?string
    {
        $map = [
            'today' => ['today', '1', 'one day', '24', '24 hour'],
            '3days' => ['3', '3 day', 'three', '72'],
            'week' => ['week', '7', 'seven'],
        ];

        foreach ($map as $validity => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $validity;
                }
            }
        }

        return null;
    }

    /**
     * Mask phone number for logging.
     */
    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return $phone;
        }

        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }
}