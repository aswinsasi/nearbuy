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
use App\Services\Flow\FlowRouter;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FishMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for the fish catch posting flow.
 *
 * Flow Steps:
 * 1. SELECT_CATEGORY - Choose fish category (Sea Fish, Freshwater, etc.)
 * 2. SELECT_FISH - Choose fish type from category (with pagination)
 * 3. ENTER_QUANTITY - Select quantity range
 * 4. ENTER_PRICE - Enter price per kg
 * 5. UPLOAD_PHOTO - Optional photo upload
 * 6. CONFIRM - Confirm and post
 * 7. ADD_ANOTHER - Ask to add more fish
 *
 * @srs-ref Pacha Meen Module - Section 2.5.1 Seller Catch Posting Flow
 */
class FishCatchPostFlowHandler extends AbstractFlowHandler
{
    protected const STEP_SELECT_CATEGORY = 'select_category';
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
        protected FishAlertService $alertService,
        protected FlowRouter $flowRouter
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
            self::STEP_SELECT_CATEGORY,
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

        $this->clearTemp($session);
        $this->nextStep($session, self::STEP_SELECT_CATEGORY);

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

        // Handle cross-flow navigation buttons
        $selectionId = $this->getSelectionId($message);
        if ($this->handleCrossFlowNavigation($selectionId, $session)) {
            return;
        }

        $step = $session->current_step;

        Log::debug('FishCatchPostFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
        ]);

        match ($step) {
            self::STEP_SELECT_CATEGORY => $this->handleSelectCategory($message, $session),
            self::STEP_SELECT_FISH => $this->handleSelectFish($message, $session),
            self::STEP_ENTER_QUANTITY => $this->handleEnterQuantity($message, $session),
            self::STEP_ENTER_PRICE => $this->handleEnterPrice($message, $session),
            self::STEP_UPLOAD_PHOTO => $this->handleUploadPhoto($message, $session),
            self::STEP_CONFIRM => $this->handleConfirm($message, $session),
            self::STEP_ADD_ANOTHER => $this->handleAddAnother($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Handle buttons that navigate to other flows or trigger actions.
     */
    protected function handleCrossFlowNavigation(?string $selectionId, ConversationSession $session): bool
    {
        if (!$selectionId) {
            return false;
        }

        if ($selectionId === 'main_menu') {
            $this->clearTemp($session);
            $this->goToMainMenu($session);
            return true;
        }

        if ($selectionId === 'fish_update_stock') {
            $this->clearTemp($session);
            $this->flowRouter->handleMenuSelection('fish_update_stock', $session);
            return true;
        }

        return false;
    }

    /**
     * Handle category selection (Step 1).
     */
    protected function handleSelectCategory(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle "Select Fish" button from start message
        if ($selectionId === 'select_fish') {
            $response = FishMessages::selectFishCategory();
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        // Handle category selection
        $category = match ($selectionId) {
            'cat_sea_fish' => FishType::CATEGORY_SEA_FISH,
            'cat_freshwater' => FishType::CATEGORY_FRESHWATER,
            'cat_shellfish' => FishType::CATEGORY_SHELLFISH,
            'cat_crustacean' => FishType::CATEGORY_CRUSTACEAN,
            default => null,
        };

        if ($category) {
            $this->setTemp($session, 'selected_category', $category);
            $this->setTemp($session, 'category_page', 0);
            $this->nextStep($session, self::STEP_SELECT_FISH);

            $response = FishMessages::selectFishFromCategory($category, 0);
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        // Show category selection
        $response = FishMessages::selectFishCategory();
        $this->sendFishMessage($session->phone, $response);
    }

    /**
     * Handle fish selection from category (Step 2).
     */
    protected function handleSelectFish(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle back to categories
        if ($selectionId === 'back_to_categories') {
            $this->setTemp($session, 'selected_category', null);
            $this->setTemp($session, 'category_page', 0);
            $this->nextStep($session, self::STEP_SELECT_CATEGORY);

            $response = FishMessages::selectFishCategory();
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        // Handle pagination within category
        if ($selectionId && preg_match('/^cat_([a-z_]+)_page_(\d+)$/', $selectionId, $matches)) {
            $category = $matches[1];
            $page = (int) $matches[2];

            $this->setTemp($session, 'selected_category', $category);
            $this->setTemp($session, 'category_page', $page);

            $response = FishMessages::selectFishFromCategory($category, $page);
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        // Handle fish type selection from list (format: fish_1, fish_2, etc.)
        if ($selectionId && str_starts_with($selectionId, 'fish_')) {
            $fishType = FishType::findByListId($selectionId);
            if ($fishType) {
                $this->setFishTypeAndContinue($session, $fishType);
                return;
            }
        }

        // Show fish from current category
        $category = $this->getTemp($session, 'selected_category');
        $page = (int) $this->getTemp($session, 'category_page', 0);

        if ($category) {
            $response = FishMessages::selectFishFromCategory($category, $page);
        } else {
            // Fallback to category selection
            $this->nextStep($session, self::STEP_SELECT_CATEGORY);
            $response = FishMessages::selectFishCategory();
        }

        $this->sendFishMessage($session->phone, $response);
    }

    /**
     * Set selected fish type and move to quantity step.
     */
    protected function setFishTypeAndContinue(ConversationSession $session, FishType $fishType): void
    {
        $this->setTemp($session, 'fish_type_id', $fishType->id);
        $this->setTemp($session, 'fish_type_name', $fishType->display_name);
        $this->nextStep($session, self::STEP_ENTER_QUANTITY);

        $response = FishMessages::askQuantity($fishType);
        $this->sendFishMessage($session->phone, $response);
    }

    protected function handleEnterQuantity(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        $quantityRange = null;

        // Check for quantity range button selection (e.g., qty_small, qty_medium, qty_5_10)
        if ($selectionId && str_starts_with($selectionId, 'qty_')) {
            // Extract the range value (remove 'qty_' prefix)
            $rangeValue = substr($selectionId, 4);
            $quantityRange = $rangeValue;
        }

        // Check for text input - try to map to a range
        if (!$quantityRange && $text) {
            $quantityRange = $this->mapTextToQuantityRange($text);
        }

        $fishType = FishType::find($this->getTemp($session, 'fish_type_id'));

        if ($quantityRange) {
            $this->setTemp($session, 'quantity_range', $quantityRange);
            $this->nextStep($session, self::STEP_ENTER_PRICE);

            $response = FishMessages::askPrice($fishType);
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        // Re-prompt
        $response = FishMessages::askQuantity($fishType);
        $this->sendFishMessage($session->phone, $response);
    }

    /**
     * Map text input to a quantity range value.
     */
    protected function mapTextToQuantityRange(string $text): ?string
    {
        // Clean input - extract number
        $text = preg_replace('/[^\d]/', '', $text);
        
        if (!is_numeric($text)) {
            return null;
        }

        $qty = (int) $text;

        // Map to FishQuantityRange enum values
        // These should match your FishQuantityRange enum
        if ($qty <= 2) {
            return 'under_2kg';
        } elseif ($qty <= 5) {
            return '2_5kg';
        } elseif ($qty <= 10) {
            return '5_10kg';
        } elseif ($qty <= 20) {
            return '10_20kg';
        } elseif ($qty <= 50) {
            return '20_50kg';
        } else {
            return 'above_50kg';
        }
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

    /**
     * Handle photo upload step.
     * 
     * FIX: Now downloads the image from WhatsApp and stores it to get a public URL.
     */
    protected function handleUploadPhoto(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for skip
        if ($this->isSkip($message)) {
            $this->setTemp($session, 'photo_url', null);
            $this->setTemp($session, 'photo_media_id', null);
            $this->proceedToConfirm($session);
            return;
        }

        // Check for image
        $mediaId = $this->getMediaId($message);
        if ($mediaId && $message->type === 'image') {
            $this->setTemp($session, 'photo_media_id', $mediaId);
            
            // FIX: Download and store the image to get a public URL
            $filename = 'fish-catches/' . date('Y/m/d') . '/' . uniqid() . '.jpg';
            $result = $this->whatsApp->downloadAndStoreMedia($mediaId, $filename);
            
            if ($result['success']) {
                $this->setTemp($session, 'photo_url', $result['url']);
                Log::info('Fish photo stored', [
                    'media_id' => $mediaId,
                    'url' => $result['url'],
                ]);
            } else {
                // Log error but continue without photo URL
                Log::warning('Failed to download fish photo', [
                    'media_id' => $mediaId,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
                $this->setTemp($session, 'photo_url', null);
            }
            
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
        $quantityRange = $this->getTemp($session, 'quantity_range');
        $price = $this->getTemp($session, 'price_per_kg');
        $photoUrl = $this->getTemp($session, 'photo_url');
        $photoMediaId = $this->getTemp($session, 'photo_media_id');
        $hasPhoto = !empty($photoUrl) || !empty($photoMediaId);

        // If there's a photo, send it first
        if ($hasPhoto) {
            if ($photoUrl) {
                // Use the stored URL (preferred - works reliably)
                $this->whatsApp->sendImage($session->phone, $photoUrl, "📸 Your fish photo");
            } elseif ($photoMediaId) {
                // Fallback to media ID
                $this->whatsApp->sendImage($session->phone, $photoMediaId, "📸 Your fish photo", true);
            }
        }

        $catchData = [
            'quantity_range' => $quantityRange,
            'price_per_kg' => $price,
            'has_photo' => $hasPhoto,
        ];

        $response = FishMessages::confirmCatchPosting($catchData, $fishType);
        $this->sendFishMessage($session->phone, $response);
    }

    protected function handleConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Handle "Try Again" / back to categories
        if ($selectionId === 'back_to_categories') {
            $this->clearTemp($session);
            $this->nextStep($session, self::STEP_SELECT_CATEGORY);
            $response = FishMessages::selectFishCategory();
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        // Handle main menu
        if ($selectionId === 'main_menu') {
            $this->clearTemp($session);
            $this->goToMainMenu($session);
            return;
        }

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

        // Handle edit photo - go back to photo upload step
        if ($selectionId === 'edit_photo') {
            $this->setTemp($session, 'photo_media_id', null);
            $this->setTemp($session, 'photo_url', null); // Also clear URL
            $this->nextStep($session, self::STEP_UPLOAD_PHOTO);
            $fishType = FishType::find($this->getTemp($session, 'fish_type_id'));
            $response = FishMessages::askPhoto($fishType);
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        if ($selectionId === 'edit_details') {
            $this->clearTemp($session);
            $this->nextStep($session, self::STEP_SELECT_CATEGORY);
            $response = FishMessages::selectFishCategory();
            $this->sendFishMessage($session->phone, $response);
            return;
        }

        // Re-show confirmation only if we have required data
        $fishTypeId = $this->getTemp($session, 'fish_type_id');
        $quantityRange = $this->getTemp($session, 'quantity_range');
        
        if ($fishTypeId && $quantityRange) {
            $this->proceedToConfirm($session);
        } else {
            // Data missing - restart flow
            $this->clearTemp($session);
            $this->nextStep($session, self::STEP_SELECT_CATEGORY);
            $response = FishMessages::selectFishCategory();
            $this->sendFishMessage($session->phone, $response);
        }
    }

    protected function createCatch(ConversationSession $session): void
    {
        $seller = $this->getFishSeller($session);

        try {
            $catch = $this->catchService->createCatch($seller, [
                'fish_type_id' => $this->getTemp($session, 'fish_type_id'),
                'quantity_range' => $this->getTemp($session, 'quantity_range'),
                'price_per_kg' => $this->getTemp($session, 'price_per_kg'),
                'photo_media_id' => $this->getTemp($session, 'photo_media_id'),
                'photo_url' => $this->getTemp($session, 'photo_url'), // FIX: Now passing photo_url
                'latitude' => $seller->latitude ?? $seller->user->latitude,
                'longitude' => $seller->longitude ?? $seller->user->longitude,
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
            $this->sendButtons(
                $session->phone,
                "❌ Failed to post catch. Please try again.",
                [
                    ['id' => 'back_to_categories', 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
                ]
            );
        }
    }

    protected function handleAddAnother(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'add_another' || $selectionId === 'yes') {
            $this->clearTemp($session);
            $this->nextStep($session, self::STEP_SELECT_CATEGORY);
            $response = FishMessages::selectFishCategory();
            $this->sendFishMessage($session->phone, $response);
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