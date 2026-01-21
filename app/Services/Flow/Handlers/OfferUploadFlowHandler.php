<?php

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\OfferStep;
use App\Models\ConversationSession;
use App\Services\Media\MediaService;
use App\Services\Offers\OfferService;
use App\Services\WhatsApp\Messages\MessageTemplates;
use App\Services\WhatsApp\Messages\OfferMessages;

/**
 * ENHANCED Offer Upload Flow Handler.
 *
 * Key improvements:
 * 1. Extends AbstractFlowHandler for consistent menu buttons
 * 2. Uses sendTextWithMenu/sendButtonsWithMenu patterns
 * 3. Main Menu button on all messages
 */
class OfferUploadFlowHandler extends AbstractFlowHandler
{
    public function __construct(
        \App\Services\Session\SessionManager $sessionManager,
        \App\Services\WhatsApp\WhatsAppService $whatsApp,
        protected OfferService $offerService,
        protected MediaService $mediaService,
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::OFFERS_UPLOAD;
    }

    protected function getSteps(): array
    {
        return [
            OfferStep::UPLOAD_IMAGE->value,
            OfferStep::ADD_CAPTION->value,
            OfferStep::SELECT_VALIDITY->value,
            OfferStep::CONFIRM_UPLOAD->value,
            OfferStep::UPLOAD_COMPLETE->value,
        ];
    }

    /**
     * Start the upload flow.
     */
    public function start(ConversationSession $session): void
    {
        // Verify user is a shop owner
        $user = $this->getUser($session);

        if (!$user || !$user->isShopOwner()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "⚠️ *Shop Owner Required*\n\nOnly shop owners can upload offers.\n\nPlease register as a shop owner first.",
                [['id' => 'register', 'title' => '📝 Register Shop']]
            );
            $this->goToMainMenu($session);
            return;
        }

        $shop = $user->shop;

        // Check if can upload more offers
        if (!$this->offerService->canUploadOffer($shop)) {
            $maxOffers = config('nearbuy.offers.max_active_per_shop', 5);
            $message = OfferMessages::format(OfferMessages::MAX_OFFERS_REACHED, [
                'max' => $maxOffers,
            ]);

            $this->sendButtonsWithMenu(
                $session->phone,
                $message,
                [['id' => 'my_offers', 'title' => '🏷️ Manage Offers']]
            );

            $this->goToMainMenu($session);
            return;
        }

        // Clear temp data and start
        $this->clearTemp($session);
        $this->setTemp($session, 'shop_id', $shop->id);

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
        // Handle common navigation (menu, cancel, etc.)
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = OfferStep::tryFrom($session->current_step);

        if (!$step) {
            $this->logError('Invalid offer upload step', ['step' => $session->current_step]);
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

    /**
     * Get expected input type.
     */
    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            OfferStep::UPLOAD_IMAGE->value => 'media',
            OfferStep::ADD_CAPTION->value => 'text',
            OfferStep::SELECT_VALIDITY->value => 'button',
            OfferStep::CONFIRM_UPLOAD->value => 'button',
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
            OfferStep::UPLOAD_IMAGE => $this->askForMedia($session),
            OfferStep::ADD_CAPTION => $this->askForCaption($session),
            OfferStep::SELECT_VALIDITY => $this->askForValidity($session),
            OfferStep::CONFIRM_UPLOAD => $this->askForConfirmation($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    protected function handleMediaUpload(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for image or document
        if (!$message->isImage() && !$message->isDocument()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $mediaId = $this->getMediaId($message);

        if (!$mediaId) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Download from WhatsApp and upload to S3
        $this->sendTextWithMenu($session->phone, "⏳ Processing your media...");

        $result = $this->mediaService->downloadAndStore(
            mediaId: $mediaId,
            folder: 'offers',
            filename: null
        );

        if (!$result['success']) {
            $this->logError('Offer media upload failed', [
                'phone' => $session->phone,
                'error' => $result['error'] ?? 'Unknown error',
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed to process media. Please try again.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );
            $this->askForMedia($session);
            return;
        }

        // Validate media type
        $mediaType = $this->mediaService->getMediaType($result['mime_type']);

        if (!in_array($mediaType, ['image', 'pdf'])) {
            $this->sendErrorWithOptions(
                $session->phone,
                OfferMessages::INVALID_MEDIA,
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );
            $this->askForMedia($session);
            return;
        }

        // Store media info
        $this->sessionManager->mergeTempData($session, [
            'media_url' => $result['url'],
            'media_type' => $mediaType,
        ]);

        $this->logInfo('Offer media uploaded', [
            'phone' => $this->maskPhone($session->phone),
            'url' => $result['url'],
            'type' => $mediaType,
        ]);

        // Move to caption step
        $this->nextStep($session, OfferStep::ADD_CAPTION->value);
        $this->askForCaption($session);
    }

    protected function handleCaptionInput(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for skip button
        if ($this->isSkip($message)) {
            $this->setTemp($session, 'caption', null);
            $this->nextStep($session, OfferStep::SELECT_VALIDITY->value);
            $this->askForValidity($session);
            return;
        }

        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $text = trim($message->text ?? '');

        // Check for skip text
        if (strtolower($text) === 'skip') {
            $this->setTemp($session, 'caption', null);
        } else {
            // Validate caption length
            if (mb_strlen($text) > 500) {
                $this->sendErrorWithOptions(
                    $session->phone,
                    OfferMessages::CAPTION_TOO_LONG,
                    [
                        ['id' => 'skip', 'title' => '⏭️ Skip Caption'],
                        self::MENU_BUTTON,
                    ]
                );
                $this->askForCaption($session);
                return;
            }

            $this->setTemp($session, 'caption', $text);
        }

        // Move to validity selection
        $this->nextStep($session, OfferStep::SELECT_VALIDITY->value);
        $this->askForValidity($session);
    }

    protected function handleValiditySelection(IncomingMessage $message, ConversationSession $session): void
    {
        $validity = null;

        if ($message->isInteractive()) {
            $validity = $this->getSelectionId($message);
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            $validity = $this->matchValidity($text);
        }

        if (!in_array($validity, ['today', '3days', 'week'])) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->setTemp($session, 'validity', $validity);

        // Move to confirmation
        $this->nextStep($session, OfferStep::CONFIRM_UPLOAD->value);
        $this->askForConfirmation($session);
    }

    protected function handleConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);
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

    protected function handlePostUpload(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);
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

    protected function askForMedia(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry ? OfferMessages::INVALID_MEDIA : OfferMessages::UPLOAD_START;

        $this->sendButtonsWithMenu(
            $session->phone,
            $message,
            []
        );
    }

    protected function askForCaption(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please type a caption or tap Skip."
            : OfferMessages::ASK_CAPTION;

        $this->sendButtonsWithMenu(
            $session->phone,
            $message,
            [['id' => 'skip', 'title' => '⏭️ Skip Caption']]
        );
    }

    protected function askForValidity(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select how long this offer should be valid:"
            : OfferMessages::ASK_VALIDITY;

        $this->sendButtonsWithMenu(
            $session->phone,
            $message,
            OfferMessages::getValidityButtons()
        );
    }

    protected function askForConfirmation(ConversationSession $session): void
    {
        $caption = $this->getTemp($session, 'caption');
        $validity = $this->getTemp($session, 'validity');
        $mediaUrl = $this->getTemp($session, 'media_url');
        $mediaType = $this->getTemp($session, 'media_type');

        // Calculate estimated reach
        $user = $this->getUser($session);
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
            $this->sendImage($session->phone, $mediaUrl);
        } else {
            $this->sendDocument($session->phone, $mediaUrl, 'Offer.pdf');
        }

        // Then send confirmation buttons with menu
        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'publish', 'title' => '✅ Publish'],
                ['id' => 'edit', 'title' => '✏️ Edit'],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
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

    protected function publishOffer(ConversationSession $session): void
    {
        try {
            $user = $this->getUser($session);
            $shop = $user->shop;

            $offer = $this->offerService->createOffer($shop, [
                'media_url' => $this->getTemp($session, 'media_url'),
                'media_type' => $this->getTemp($session, 'media_type'),
                'caption' => $this->getTemp($session, 'caption'),
                'validity' => $this->getTemp($session, 'validity'),
            ]);

            $radius = config('nearbuy.offers.default_radius_km', 5);
            $reach = $this->offerService->calculateEstimatedReach($shop, $radius);

            $message = OfferMessages::format(OfferMessages::UPLOAD_SUCCESS, [
                'radius' => $radius,
                'reach' => $reach,
                'expiry_date' => OfferMessages::formatExpiry($offer->expires_at),
            ]);

            $this->sendButtonsWithMenu(
                $session->phone,
                $message,
                [
                    ['id' => 'upload_another', 'title' => '📤 Upload Another'],
                    ['id' => 'my_offers', 'title' => '🏷️ My Offers'],
                ]
            );

            // Clear temp data
            $this->clearTemp($session);

            // Update step
            $this->nextStep($session, OfferStep::UPLOAD_COMPLETE->value);

            $this->logInfo('Offer published', [
                'offer_id' => $offer->id,
                'shop_id' => $shop->id,
                'phone' => $this->maskPhone($session->phone),
            ]);

        } catch (\Exception $e) {
            $this->logError('Offer publish failed', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed to publish offer: " . $e->getMessage() . "\n\nPlease try again.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );

            $this->restartUpload($session);
        }
    }

    protected function restartUpload(ConversationSession $session): void
    {
        // Delete uploaded media if exists
        $mediaUrl = $this->getTemp($session, 'media_url');
        if ($mediaUrl) {
            $this->mediaService->deleteFromStorage($mediaUrl);
        }

        $this->sendTextWithMenu(
            $session->phone,
            "🔄 Let's start over. Your previous upload has been cleared."
        );

        $this->start($session);
    }

    protected function cancelUpload(ConversationSession $session): void
    {
        // Delete uploaded media if exists
        $mediaUrl = $this->getTemp($session, 'media_url');
        if ($mediaUrl) {
            $this->mediaService->deleteFromStorage($mediaUrl);
        }

        $this->clearTemp($session);

        $this->sendTextWithMenu($session->phone, OfferMessages::UPLOAD_CANCELLED);

        $this->goToMainMenu($session);
    }

    protected function goToMyOffers(ConversationSession $session): void
    {
        $this->goToFlow($session, FlowType::OFFERS_MANAGE, OfferStep::SHOW_MY_OFFERS->value);
        app(OfferManageFlowHandler::class)->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
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
}