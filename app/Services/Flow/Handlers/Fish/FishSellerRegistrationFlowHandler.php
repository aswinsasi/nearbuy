<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\FishSellerType;
use App\Enums\FishSellerVerificationStatus;
use App\Models\ConversationSession;
use App\Services\Fish\FishSellerService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Media\MediaService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Fish Seller Registration Flow Handler.
 *
 * Simple, conversational flow for Kerala fishermen who may not be tech-savvy.
 *
 * Flow steps:
 * 1. Ask seller type (3 buttons: Fisherman, Fish Shop, Vendor)
 * 2. Ask location (Send Location button)
 * 3. Ask location name (harbour/market/shop name)
 * 4. Ask verification photo (PM-002)
 * 5. Complete with "pending verification" status
 *
 * @srs-ref PM-001 to PM-004 Fish seller requirements
 */
class FishSellerRegistrationFlowHandler extends AbstractFlowHandler
{
    // Flow steps
    protected const STEP_ASK_TYPE = 'ask_type';
    protected const STEP_ASK_LOCATION = 'ask_location';
    protected const STEP_ASK_LOCATION_NAME = 'ask_location_name';
    protected const STEP_ASK_PHOTO = 'ask_photo';
    protected const STEP_COMPLETE = 'complete';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected FishSellerService $sellerService,
        protected ?MediaService $mediaService = null,
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::FISH_SELLER_REGISTER;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_ASK_TYPE,
            self::STEP_ASK_LOCATION,
            self::STEP_ASK_LOCATION_NAME,
            self::STEP_ASK_PHOTO,
            self::STEP_COMPLETE,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Flow Entry
    |--------------------------------------------------------------------------
    */

    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Check if already a fish seller
        if ($user?->fishSeller) {
            $seller = $user->fishSeller;
            $this->sendButtons(
                $session->phone,
                "🐟 *Already Registered!*\n\n" .
                "Nee already fish seller aayi registered aanu:\n" .
                "*{$seller->location_name}*\n" .
                $seller->seller_type->display() . "\n\n" .
                "Status: {$seller->verification_status->shortBadge()}",
                [
                    ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                    ['id' => 'fish_menu', 'title' => '📋 Seller Menu'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        // Clear any previous temp data
        $this->clearTempData($session);

        // Store user name if available (for existing users)
        if ($user?->name) {
            $this->setTempData($session, 'name', $user->name);
        }

        // Step 1: Ask seller type
        $this->askSellerType($session);
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = $session->current_step;

        Log::debug('FishSellerRegistration step', [
            'step' => $step,
            'type' => $message->type,
        ]);

        match ($step) {
            self::STEP_ASK_TYPE => $this->handleSellerType($message, $session),
            self::STEP_ASK_LOCATION => $this->handleLocation($message, $session),
            self::STEP_ASK_LOCATION_NAME => $this->handleLocationName($message, $session),
            self::STEP_ASK_PHOTO => $this->handlePhoto($message, $session),
            default => $this->start($session),
        };
    }

    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $this->promptCurrentStep($session);
    }

    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = $session->current_step;

        match ($step) {
            self::STEP_ASK_TYPE => $this->askSellerType($session),
            self::STEP_ASK_LOCATION => $this->askLocation($session),
            self::STEP_ASK_LOCATION_NAME => $this->askLocationName($session),
            self::STEP_ASK_PHOTO => $this->askPhoto($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Ask Seller Type
    |--------------------------------------------------------------------------
    */

    protected function askSellerType(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "🐟 *Meen Seller aayi register cheyyaam!*\n\n" .
            "Nee aara?\n" .
            "_Who are you?_",
            FishSellerType::toButtons() // Exactly 3 buttons!
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::FISH_SELLER_REGISTER,
            self::STEP_ASK_TYPE
        );
    }

    protected function handleSellerType(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Try to parse seller type from button ID
        $sellerType = FishSellerType::fromButtonId($selectionId);

        if (!$sellerType) {
            // Try direct value match
            $sellerType = FishSellerType::tryFrom($selectionId);
        }

        if ($sellerType) {
            $this->setTempData($session, 'seller_type', $sellerType->value);

            Log::info('Seller type selected', [
                'phone' => $session->phone,
                'type' => $sellerType->value,
            ]);

            // Step 2: Ask location
            $this->askLocation($session);
            return;
        }

        // Invalid selection - re-ask
        $this->askSellerType($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Ask Location
    |--------------------------------------------------------------------------
    */

    protected function askLocation(ConversationSession $session): void
    {
        $sellerTypeValue = $this->getTempData($session, 'seller_type');
        $sellerType = FishSellerType::from($sellerTypeValue);

        // Use type-specific location prompt
        $this->whatsApp->requestLocation(
            $session->phone,
            $sellerType->locationPrompt() . "\n_Location ayakkuka_"
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::FISH_SELLER_REGISTER,
            self::STEP_ASK_LOCATION
        );
    }

    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $location = $this->getLocation($message);

        if ($location && isset($location['latitude'], $location['longitude'])) {
            $this->setTempData($session, 'latitude', $location['latitude']);
            $this->setTempData($session, 'longitude', $location['longitude']);

            // If location has name, use it as default
            if (!empty($location['name'])) {
                $this->setTempData($session, 'location_name_suggestion', $location['name']);
            }

            Log::info('Location received', [
                'phone' => $session->phone,
                'lat' => $location['latitude'],
                'lng' => $location['longitude'],
            ]);

            // Step 3: Ask location name
            $this->askLocationName($session);
            return;
        }

        // Not a location - re-prompt
        $this->sendText(
            $session->phone,
            "❌ Location kittiyilla. Please 📍 button tap cheythu location share cheyyuka."
        );
        $this->askLocation($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Ask Location Name (PM-001: harbour/market name)
    |--------------------------------------------------------------------------
    */

    protected function askLocationName(ConversationSession $session): void
    {
        $sellerTypeValue = $this->getTempData($session, 'seller_type');
        $sellerType = FishSellerType::from($sellerTypeValue);
        $suggestion = $this->getTempData($session, 'location_name_suggestion');

        $text = $sellerType->locationNamePrompt();

        if ($suggestion) {
            $text .= "\n\n_Suggested: {$suggestion}_";
        }

        $this->sendText($session->phone, $text);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::FISH_SELLER_REGISTER,
            self::STEP_ASK_LOCATION_NAME
        );
    }

    protected function handleLocationName(IncomingMessage $message, ConversationSession $session): void
    {
        $text = $this->getTextContent($message);

        if ($text && mb_strlen(trim($text)) >= 2 && mb_strlen(trim($text)) <= 100) {
            $locationName = trim($text);
            $this->setTempData($session, 'location_name', $locationName);

            Log::info('Location name received', [
                'phone' => $session->phone,
                'name' => $locationName,
            ]);

            // Step 4: Ask for verification photo
            $this->askPhoto($session);
            return;
        }

        // Invalid - re-ask
        $this->sendText($session->phone, "❌ Please valid name type cheyyuka (2-100 characters).");
        $this->askLocationName($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Ask Verification Photo (PM-002)
    |--------------------------------------------------------------------------
    */

    protected function askPhoto(ConversationSession $session): void
    {
        $sellerTypeValue = $this->getTempData($session, 'seller_type');
        $sellerType = FishSellerType::from($sellerTypeValue);

        // Type-specific photo prompt
        $this->sendButtons(
            $session->phone,
            $sellerType->verificationPhotoPrompt() . "\n\n" .
            "_Verification-nu vendiyaanu. Photo nalla quality aayirikkane._",
            [
                ['id' => 'skip_photo', 'title' => '⏭️ Skip for now'],
            ]
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::FISH_SELLER_REGISTER,
            self::STEP_ASK_PHOTO
        );
    }

    protected function handlePhoto(IncomingMessage $message, ConversationSession $session): void
    {
        // Check if skipped
        if ($message->isInteractive()) {
            $selectionId = $this->getSelectionId($message);
            if ($selectionId === 'skip_photo') {
                Log::info('Photo skipped', ['phone' => $session->phone]);
                $this->completeRegistration($session);
                return;
            }
        }

        // Check if image received
        if ($message->isImage() && $message->getMediaId()) {
            // Download and store the image
            $photoUrl = $this->downloadAndStorePhoto($message);

            if ($photoUrl) {
                $this->setTempData($session, 'verification_photo_url', $photoUrl);
                Log::info('Verification photo received', [
                    'phone' => $session->phone,
                    'url' => $photoUrl,
                ]);
            }

            $this->completeRegistration($session);
            return;
        }

        // Not an image - prompt again
        $this->sendText(
            $session->phone,
            "📸 Photo ayakkuka or Skip button tap cheyyuka."
        );
    }

    /**
     * Download photo from WhatsApp and store.
     */
    protected function downloadAndStorePhoto(IncomingMessage $message): ?string
    {
        if (!$this->mediaService) {
            Log::warning('MediaService not available');
            return null;
        }

        try {
            // MediaService::downloadAndStore(mediaId, folder, filename)
            $result = $this->mediaService->downloadAndStore(
                $message->getMediaId(),
                'fish-sellers/verification'
            );

            return $result['url'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to download verification photo', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step 5: Complete Registration
    |--------------------------------------------------------------------------
    */

    protected function completeRegistration(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Gather data
        $sellerTypeValue = $this->getTempData($session, 'seller_type');
        $sellerType = FishSellerType::from($sellerTypeValue);
        $locationName = $this->getTempData($session, 'location_name');
        $latitude = (float) $this->getTempData($session, 'latitude');
        $longitude = (float) $this->getTempData($session, 'longitude');
        $verificationPhotoUrl = $this->getTempData($session, 'verification_photo_url');

        try {
            if ($user && $user->registered_at) {
                // Existing user - add fish seller profile
                $seller = $this->sellerService->registerExistingUserAsSeller(
                    $user,
                    $sellerType,
                    $locationName,
                    $latitude,
                    $longitude,
                    $verificationPhotoUrl
                );
            } else {
                // New user - create user and seller
                $name = $this->getTempData($session, 'name') ?? $locationName;

                $seller = $this->sellerService->registerFishSeller([
                    'phone' => $session->phone,
                    'name' => $name,
                    'seller_type' => $sellerType,
                    'location_name' => $locationName,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'verification_photo_url' => $verificationPhotoUrl,
                ]);

                // Link session to new user
                $this->sellerService->linkSessionToUser($session, $seller->user);
            }

            // Clear temp data
            $this->clearTempData($session);

            // Show success message
            $this->showSuccess($session, $seller);

        } catch (\Exception $e) {
            Log::error('Fish seller registration failed', [
                'phone' => $session->phone,
                'error' => $e->getMessage(),
            ]);

            $this->sendButtons(
                $session->phone,
                "❌ *Registration Failed*\n\n{$e->getMessage()}\n\n" .
                "_Please try again._",
                [
                    ['id' => 'fish_seller_register', 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
        }
    }

    /**
     * Show registration success message.
     */
    protected function showSuccess(ConversationSession $session, $seller): void
    {
        $hasPhoto = !empty($seller->verification_photo_url);
        $photoNote = $hasPhoto
            ? "📸 Photo received!"
            : "📸 No photo - add later for faster verification.";

        $this->sendButtons(
            $session->phone,
            "✅ *Registration Complete!* 🐟\n\n" .
            "*{$seller->location_name}*\n" .
            "{$seller->seller_type->display()}\n\n" .
            "{$photoNote}\n\n" .
            "⏳ *Verification pending*\n" .
            "Team review cheyyum (usually 24hrs).\n\n" .
            "_Ithintidayil catches post cheyyaam!_\n" .
            "_You can post catches while verification is pending._",
            [
                ['id' => 'fish_post_catch', 'title' => '🎣 Post First Catch'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );

        // Update session step
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::FISH_SELLER_REGISTER,
            self::STEP_COMPLETE
        );
    }
}