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
 * @srs-ref Pacha Meen Module - Seller Registration
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
            $this->createFishSeller($session);
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

    protected function createFishSeller(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        try {
            // createFishSeller returns User with attached fishSeller
            $updatedUser = $this->sellerService->createFishSeller([
                'user_id' => $user->id,
                'phone' => $user->phone,
                'name' => $user->name,
                'seller_type' => $this->getTemp($session, 'seller_type'),
                'business_name' => $this->getTemp($session, 'business_name'),
                'latitude' => $this->getTemp($session, 'latitude'),
                'longitude' => $this->getTemp($session, 'longitude'),
                'market_name' => $this->getTemp($session, 'market_name'),
            ]);

            $seller = $updatedUser->fishSeller;

            $this->clearTemp($session);
            $response = FishMessages::sellerRegistrationComplete($seller);
            $this->sendFishMessage($session->phone, $response);
            $this->goToMainMenu($session);

        } catch (\Exception $e) {
            Log::error('Failed to create fish seller', ['error' => $e->getMessage()]);
            $this->sendErrorWithOptions($session->phone, "❌ Registration failed. Please try again.");
        }
    }

    protected function showConfirmation(ConversationSession $session): void
    {
        $sellerTypeValue = $this->getTemp($session, 'seller_type');
        $sellerType = FishSellerType::from($sellerTypeValue);
        $businessName = $this->getTemp($session, 'business_name');
        $marketName = $this->getTemp($session, 'market_name');

        $typeLabel = $sellerType->label();

        $text = "📋 *Confirm Registration*\n\n" .
            "Type: {$typeLabel}\n" .
            "Business: {$businessName}\n" .
            ($marketName ? "Market: {$marketName}\n" : "") .
            "\nIs this correct?";

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
