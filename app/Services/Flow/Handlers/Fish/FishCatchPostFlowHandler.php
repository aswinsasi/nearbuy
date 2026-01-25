<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\FishType;
use App\Models\FishSeller;
use App\Services\Fish\FishCatchService;
use App\Services\Fish\FishAlertService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FishMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for the fish catch posting flow.
 *
 * Flow Steps:
 * 1. SELECT_FISH - Choose fish type
 * 2. ENTER_QUANTITY - Select quantity range
 * 3. ENTER_PRICE - Enter price per kg
 * 4. UPLOAD_PHOTO - Optional photo upload
 * 5. CONFIRM - Confirm and post
 * 6. ADD_ANOTHER - Ask to add more fish
 *
 * @srs-ref Pacha Meen Module - Section 2.5.1 Seller Catch Posting Flow
 */
class FishCatchPostFlowHandler extends AbstractFlowHandler
{
    protected const STEP_SELECT_FISH = 'select_fish';
    protected const STEP_ENTER_QUANTITY = 'enter_quantity';
    protected const STEP_ENTER_PRICE = 'enter_price';
    protected const STEP_UPLOAD_PHOTO = 'upload_photo';
    protected const STEP_CONFIRM = 'confirm';
    protected const STEP_ADD_ANOTHER = 'add_another';

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
        return FlowType::FISH_POST_CATCH;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_SELECT_FISH,
            self::STEP_ENTER_QUANTITY,
            self::STEP_ENTER_PRICE,
            self::STEP_UPLOAD_PHOTO,
            self::STEP_CONFIRM,
            self::STEP_ADD_ANOTHER,
        ];
    }

    public function start(ConversationSession $session): void
    {
        // Ensure user is a fish seller
        $seller = $this->getFishSeller($session);
        if (!$seller) {
            $this->sendTextWithMenu($session->phone, "❌ You must be a registered fish seller to post catches.");
            return;
        }

        $this->nextStep($session, self::STEP_SELECT_FISH);
        $this->clearTemp($session);

        $response = FishMessages::startCatchPosting();
        $this->sendFishMessage($session->phone, $response);
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        // Ensure user is a fish seller
        $seller = $this->getFishSeller($session);
        if (!$seller) {
            $this->sendTextWithMenu($session->phone, "❌ You must be a registered fish seller to post catches.");
            return;
        }

        $step = $session->current_step;

        Log::debug('FishCatchPostFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
        ]);

        match ($step) {
            self::STEP_SELECT_FISH => $this->handleSelectFish($message, $session),
            self::STEP_ENTER_QUANTITY => $this->handleEnterQuantity($message, $session),
            self::STEP_ENTER_PRICE => $this->handleEnterPrice($message, $session),
            self::STEP_UPLOAD_PHOTO => $this->handleUploadPhoto($message, $session),
            self::STEP_CONFIRM => $this->handleConfirm($message, $session),
            self::STEP_ADD_ANOTHER => $this->handleAddAnother($message, $session),
            default => $this->start($session),
        };
    }

    protected function handleSelectFish(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Check for fish type selection from list
        if ($selectionId && str_starts_with($selectionId, 'fish_')) {
            $fishType = FishType::findByListId($selectionId);
            if ($fishType) {
                $this->setTemp($session, 'fish_type_id', $fishType->id);
                $this->setTemp($session, 'fish_type_name', $fishType->display_name);
                $this->nextStep($session, self::STEP_ENTER_QUANTITY);

                $response = FishMessages::askQuantity($fishType);
                $this->sendFishMessage($session->phone, $response);
                return;
            }
        }

        // Check text input for fish name search
        $text = $this->getTextContent($message);
        if ($text) {
            $fishType = $this->searchFishByName($text);
            if ($fishType) {
                $this->setTemp($session, 'fish_type_id', $fishType->id);
                $this->setTemp($session, 'fish_type_name', $fishType->display_name);
                $this->nextStep($session, self::STEP_ENTER_QUANTITY);

                $response = FishMessages::askQuantity($fishType);
                $this->sendFishMessage($session->phone, $response);
                return;
            }
        }

        // Show fish selection again
        $response = FishMessages::selectFishType();
        $this->sendFishMessage($session->phone, $response);
    }

    protected function handleEnterQuantity(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        $quantity = null;

        // Check for quantity range button selection
        if ($selectionId && str_starts_with($selectionId, 'qty_')) {
            $quantity = $this->extractQuantityFromButton($selectionId);
        }

        // Check for text input (e.g., "5", "10kg", "5-10")
        if (!$quantity && $text) {
            $quantity = $this->extractQuantityFromText($text);
        }

        $fishType = FishType::find($this->getTemp($session, 'fish_type_id'));

        if ($quantity) {
            $this->setTemp($session, 'quantity_min', $quantity['min']);
            $this->setTemp($session, 'quantity_max', $quantity['max']);
            $this->nextStep($session, self::STEP_ENTER_PRICE);

            $response = FishMessages::askPrice($fishType);
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        // Re-prompt
        $response = FishMessages::askQuantity($fishType);
        $this->sendFishMessage($session->phone, $response);
    }

    protected function handleEnterPrice(IncomingMessage $message, ConversationSession $session): void
    {
        $text = $this->getTextContent($message);

        if ($text) {
            $price = $this->extractPrice($text);
            if ($price && $price > 0 && $price < 100000) {
                $this->setTemp($session, 'price_per_kg', $price);
                $this->nextStep($session, self::STEP_UPLOAD_PHOTO);

                $fishType = FishType::find($this->getTemp($session, 'fish_type_id'));
                $response = FishMessages::askPhoto($fishType);
                $this->sendFishMessage($session->phone, $response);
                return;
            }
        }

        // Invalid price
        $this->sendTextWithMenu(
            $session->phone,
            "❌ Please enter a valid price (e.g., 450, ₹450, 450/kg)"
        );
    }

    protected function handleUploadPhoto(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for skip
        if ($this->isSkip($message)) {
            $this->setTemp($session, 'photo_url', null);
            $this->proceedToConfirm($session);
            return;
        }

        // Check for image
        $mediaId = $this->getMediaId($message);
        if ($mediaId && $message->type === 'image') {
            $this->setTemp($session, 'photo_media_id', $mediaId);
            $this->proceedToConfirm($session);
            return;
        }

        // Re-prompt
        $fishType = FishType::find($this->getTemp($session, 'fish_type_id'));
        $response = FishMessages::askPhoto($fishType);
        $this->sendFishMessage($session->phone, $response);
    }

    protected function proceedToConfirm(ConversationSession $session): void
    {
        $this->nextStep($session, self::STEP_CONFIRM);

        $fishType = FishType::find($this->getTemp($session, 'fish_type_id'));
        $quantityMin = $this->getTemp($session, 'quantity_min');
        $quantityMax = $this->getTemp($session, 'quantity_max');
        $price = $this->getTemp($session, 'price_per_kg');
        $hasPhoto = (bool) $this->getTemp($session, 'photo_media_id');

        $catchData = [
            'quantity_min' => $quantityMin,
            'quantity_max' => $quantityMax,
            'price_per_kg' => $price,
            'has_photo' => $hasPhoto,
        ];

        $response = FishMessages::confirmCatchPosting($catchData, $fishType);
        $this->sendFishMessage($session->phone, $response);
    }

    protected function handleConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'confirm_post' || $selectionId === 'yes') {
            $this->createCatch($session);
            return;
        }

        if ($selectionId === 'cancel_post' || $selectionId === 'no') {
            $this->clearTemp($session);
            $this->sendTextWithMenu($session->phone, "❌ Catch posting cancelled.");
            $this->goToMainMenu($session);
            return;
        }

        if ($selectionId === 'edit_details') {
            $this->nextStep($session, self::STEP_SELECT_FISH);
            $this->start($session);
            return;
        }

        // Re-show confirmation
        $this->proceedToConfirm($session);
    }

    protected function createCatch(ConversationSession $session): void
    {
        $seller = $this->getFishSeller($session);

        try {
            $catch = $this->catchService->createCatch($seller, [
                'fish_type_id' => $this->getTemp($session, 'fish_type_id'),
                'quantity_kg_min' => $this->getTemp($session, 'quantity_min'),
                'quantity_kg_max' => $this->getTemp($session, 'quantity_max'),
                'price_per_kg' => $this->getTemp($session, 'price_per_kg'),
                'photo_media_id' => $this->getTemp($session, 'photo_media_id'),
                'catch_latitude' => $seller->latitude ?? $seller->user->latitude,
                'catch_longitude' => $seller->longitude ?? $seller->user->longitude,
            ]);

            // Process alerts - returns array with counts
            $alertResult = $this->alertService->processNewCatch($catch);
            $subscriberCount = $alertResult['total_subscribers'] ?? 0;

            $this->nextStep($session, self::STEP_ADD_ANOTHER);

            $response = FishMessages::catchPostedSuccess($catch, $subscriberCount);
            $this->sendFishMessage($session->phone, $response);

        } catch (\Exception $e) {
            Log::error('Failed to create catch', [
                'error' => $e->getMessage(),
                'seller_id' => $seller->id,
            ]);
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed to post catch. Please try again."
            );
        }
    }

    protected function handleAddAnother(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'add_another' || $selectionId === 'yes') {
            $this->clearTemp($session);
            $this->start($session);
            return;
        }

        $this->clearTemp($session);
        $this->goToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    protected function getFishSeller(ConversationSession $session): ?FishSeller
    {
        $user = $this->getUser($session);
        return $user?->fishSeller;
    }

    protected function searchFishByName(string $name): ?FishType
    {
        return FishType::where('name_en', 'LIKE', "%{$name}%")
            ->orWhere('name_ml', 'LIKE', "%{$name}%")
            ->orWhere('local_names', 'LIKE', "%{$name}%")
            ->first();
    }

    protected function extractQuantityFromButton(string $buttonId): ?array
    {
        // Format: qty_5_10 or qty_5
        if (preg_match('/^qty_(\d+)(?:_(\d+))?$/', $buttonId, $matches)) {
            $min = (int) $matches[1];
            $max = isset($matches[2]) ? (int) $matches[2] : $min;
            return ['min' => $min, 'max' => $max];
        }
        return null;
    }

    protected function extractQuantityFromText(string $text): ?array
    {
        // Clean input
        $text = preg_replace('/[^\d\-\.]/', '', $text);

        // Range: 5-10
        if (preg_match('/^(\d+)-(\d+)$/', $text, $matches)) {
            return ['min' => (int) $matches[1], 'max' => (int) $matches[2]];
        }

        // Single number
        if (preg_match('/^(\d+)$/', $text, $matches)) {
            $qty = (int) $matches[1];
            return ['min' => $qty, 'max' => $qty];
        }

        return null;
    }

    protected function extractPrice(string $text): ?float
    {
        // Remove currency symbols and text
        $cleaned = preg_replace('/[^\d.]/', '', $text);

        if (is_numeric($cleaned)) {
            return (float) $cleaned;
        }

        return null;
    }

    protected function sendFishMessage(string $phone, array $response): void
    {
        $type = $response['type'] ?? 'text';

        switch ($type) {
            case 'text':
                $this->sendText($phone, $response['text']);
                break;

            case 'buttons':
                $this->sendButtons(
                    $phone,
                    $response['body'] ?? $response['text'] ?? '',
                    $response['buttons'] ?? [],
                    $response['header'] ?? null,
                    $response['footer'] ?? null
                );
                break;

            case 'list':
                $this->sendList(
                    $phone,
                    $response['body'] ?? '',
                    $response['button'] ?? 'Select',
                    $response['sections'] ?? [],
                    $response['header'] ?? null,
                    $response['footer'] ?? null
                );
                break;

            default:
                $this->sendText($phone, $response['text'] ?? 'Message sent.');
        }
    }
}
