<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\FishSellerType;
use App\Enums\UserType;
use App\Models\ConversationSession;
use App\Services\Fish\FishSellerService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FishMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for fish seller registration flow.
 *
 * IMPORTANT: This handler supports TWO registration paths:
 * 1. Existing registered users (customers/shops) - adds fish seller profile
 * 2. New unregistered users - creates new user with FISH_SELLER type
 *
 * @srs-ref Pacha Meen Module - Seller Registration
 * @srs-ref Section 2.2: Any user can become a fish seller
 */
class FishSellerRegistrationFlowHandler extends AbstractFlowHandler
{
    protected const STEP_SELLER_TYPE = 'select_type';
    protected const STEP_BUSINESS_NAME = 'business_name';
    protected const STEP_LOCATION = 'location';
    protected const STEP_MARKET_NAME = 'market_name';
    protected const STEP_CONFIRM = 'confirm';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected FishSellerService $sellerService
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
            self::STEP_SELLER_TYPE,
            self::STEP_BUSINESS_NAME,
            self::STEP_LOCATION,
            self::STEP_MARKET_NAME,
            self::STEP_CONFIRM,
        ];
    }

    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Check if user is already a fish seller
        if ($user && $user->fishSeller) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "🐟 *Already Registered*\n\nYou are already registered as a fish seller!\n\nBusiness: *{$user->fishSeller->business_name}*",
                [
                    ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                    ['id' => 'fish_seller_menu', 'title' => '📋 Seller Menu'],
                ]
            );
            return;
        }

        $this->clearTemp($session);
        $this->nextStep($session, self::STEP_SELLER_TYPE);

        $response = FishMessages::sellerRegistrationWelcome();
        $this->sendFishMessage($session->phone, $response);
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = $session->current_step;

        Log::debug('FishSellerRegistrationFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
        ]);

        match ($step) {
            self::STEP_SELLER_TYPE => $this->handleSellerType($message, $session),
            self::STEP_BUSINESS_NAME => $this->handleBusinessName($message, $session),
            self::STEP_LOCATION => $this->handleLocation($message, $session),
            self::STEP_MARKET_NAME => $this->handleMarketName($message, $session),
            self::STEP_CONFIRM => $this->handleConfirm($message, $session),
            default => $this->start($session),
        };
    }

    protected function handleSellerType(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        $sellerType = match ($selectionId) {
            'seller_type_fisherman', 'seller_fisherman' => FishSellerType::FISHERMAN,
            'seller_type_harbour_vendor', 'seller_vendor' => FishSellerType::HARBOUR_VENDOR,
            'seller_type_fish_shop', 'seller_shop' => FishSellerType::FISH_SHOP,
            'seller_type_wholesaler', 'seller_wholesaler' => FishSellerType::WHOLESALER,
            default => null,
        };

        if ($sellerType) {
            $this->setTemp($session, 'seller_type', $sellerType->value);
            $this->nextStep($session, self::STEP_BUSINESS_NAME);

            $response = FishMessages::askBusinessName($sellerType);
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        $response = FishMessages::askSellerType();
        $this->sendFishMessage($session->phone, $response);
    }

    protected function handleBusinessName(IncomingMessage $message, ConversationSession $session): void
    {
        $text = $this->getTextContent($message);

        if ($text && strlen(trim($text)) >= 2 && strlen(trim($text)) <= 100) {
            $this->setTemp($session, 'business_name', trim($text));
            $this->nextStep($session, self::STEP_LOCATION);

            $response = FishMessages::askSellerLocation();
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        $this->sendTextWithMenu($session->phone, "Please enter a valid business name (2-100 characters).");
    }

    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $location = $this->getLocation($message);

        if ($location) {
            $this->setTemp($session, 'latitude', $location['latitude']);
            $this->setTemp($session, 'longitude', $location['longitude']);

            $sellerType = $this->getTemp($session, 'seller_type');

            if ($sellerType === 'market_stall') {
                $this->nextStep($session, self::STEP_MARKET_NAME);
                $response = FishMessages::askMarketName();
                $this->sendFishMessage($session->phone, $response);
            } else {
                $this->nextStep($session, self::STEP_CONFIRM);
                $this->showConfirmation($session);
            }
            return;
        }

        $response = FishMessages::askSellerLocation();
        $this->sendFishMessage($session->phone, $response);
    }

    protected function handleMarketName(IncomingMessage $message, ConversationSession $session): void
    {
        $text = $this->getTextContent($message);

        if ($this->isSkip($message)) {
            $this->setTemp($session, 'market_name', null);
        } elseif ($text && strlen(trim($text)) >= 2) {
            $this->setTemp($session, 'market_name', trim($text));
        } else {
            $response = FishMessages::askMarketName();
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        $this->nextStep($session, self::STEP_CONFIRM);
        $this->showConfirmation($session);
    }

    protected function handleConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'confirm_register') {
            $this->registerFishSeller($session);
            return;
        }

        if ($selectionId === 'cancel_register') {
            $this->clearTemp($session);
            $this->sendTextWithMenu($session->phone, "❌ Registration cancelled.");
            $this->goToMainMenu($session);
            return;
        }

        $this->showConfirmation($session);
    }

    /**
     * Register the user as a fish seller.
     *
     * FIXED: Now handles TWO cases:
     * 1. Existing registered user → registerExistingUserAsSeller()
     * 2. New unregistered user → createFishSeller()
     *
     * @srs-ref Section 2.2: Any user can become a fish seller
     */
    protected function registerFishSeller(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Get registration data from temp storage
        $sellerTypeValue = $this->getTemp($session, 'seller_type');
        $sellerType = FishSellerType::from($sellerTypeValue);
        $businessName = $this->getTemp($session, 'business_name');
        $latitude = (float) $this->getTemp($session, 'latitude');
        $longitude = (float) $this->getTemp($session, 'longitude');
        $marketName = $this->getTemp($session, 'market_name');

        try {
            // Check if user is already registered (has registered_at set)
            if ($user && $user->registered_at) {
                // EXISTING USER: Add fish seller profile without changing user type
                // User keeps their type (CUSTOMER, SHOP) but also becomes a fish seller
                Log::info('Registering existing user as fish seller', [
                    'user_id' => $user->id,
                    'user_type' => $user->type->value,
                    'seller_type' => $sellerType->value,
                ]);

                $seller = $this->sellerService->registerExistingUserAsSeller(
                    $user,
                    $sellerType,
                    $businessName,
                    $latitude,
                    $longitude,
                    $marketName
                );

                $this->clearTemp($session);

                // Show success message with context
                $userTypeLabel = $user->type === UserType::SHOP ? 'shop owner' : 'customer';
                $this->sendButtons(
                    $session->phone,
                    "✅ *Registration Successful!*\n\n" .
                    "🐟 *{$businessName}*\n" .
                    "Type: {$sellerType->label()}\n\n" .
                    "You can now sell fish while continuing as a {$userTypeLabel}!\n\n" .
                    "Ready to post your first catch?",
                    [
                        ['id' => 'fish_post_catch', 'title' => '🎣 Post Catch'],
                        ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                    ],
                    '🐟 Fish Seller'
                );

            } else {
                // NEW USER: Create new user with FISH_SELLER type
                Log::info('Creating new fish seller user', [
                    'phone' => $session->phone,
                    'seller_type' => $sellerType->value,
                ]);

                $updatedUser = $this->sellerService->createFishSeller([
                    'phone' => $session->phone,
                    'name' => $businessName, // Use business name as user name for new users
                    'seller_type' => $sellerType->value,
                    'business_name' => $businessName,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'market_name' => $marketName,
                ]);

                $seller = $updatedUser->fishSeller;

                // Link session to new user
                $this->sellerService->linkSessionToUser($session, $updatedUser);

                $this->clearTemp($session);

                $response = FishMessages::sellerRegistrationComplete($seller);
                $this->sendFishMessage($session->phone, $response);
            }

        } catch (\InvalidArgumentException $e) {
            Log::error('Fish seller registration validation failed', [
                'error' => $e->getMessage(),
                'user_id' => $user?->id,
            ]);
            
            $this->sendButtons(
                $session->phone,
                "❌ *Registration Failed*\n\n{$e->getMessage()}\n\nPlease try again.",
                [
                    ['id' => 'fish_seller_register', 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ]
            );

        } catch (\Exception $e) {
            Log::error('Failed to create fish seller', [
                'error' => $e->getMessage(),
                'user_id' => $user?->id,
                'trace' => $e->getTraceAsString(),
            ]);
            
            $this->sendButtons(
                $session->phone,
                "❌ *Registration Failed*\n\nSomething went wrong. Please try again later.",
                [
                    ['id' => 'fish_seller_register', 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ]
            );
        }
    }

    protected function showConfirmation(ConversationSession $session): void
    {
        $user = $this->getUser($session);
        $sellerTypeValue = $this->getTemp($session, 'seller_type');
        $sellerType = FishSellerType::from($sellerTypeValue);
        $businessName = $this->getTemp($session, 'business_name');
        $marketName = $this->getTemp($session, 'market_name');

        $typeLabel = $sellerType->label();

        // Show different message for existing users
        $additionalNote = '';
        if ($user && $user->registered_at) {
            $userTypeLabel = $user->type === UserType::SHOP ? 'shop owner' : 'customer';
            $additionalNote = "\n\n_Note: You'll be able to sell fish while continuing as a {$userTypeLabel}._";
        }

        $text = "📋 *Confirm Registration*\n\n" .
            "Type: {$typeLabel}\n" .
            "Business: {$businessName}\n" .
            ($marketName ? "Market: {$marketName}\n" : "") .
            $additionalNote .
            "\n\nIs this correct?";

        $this->sendButtons(
            $session->phone,
            $text,
            [
                ['id' => 'confirm_register', 'title' => '✅ Confirm'],
                ['id' => 'cancel_register', 'title' => '❌ Cancel'],
            ],
            '🐟 Fish Seller Registration'
        );
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