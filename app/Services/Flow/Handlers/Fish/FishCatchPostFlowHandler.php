<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FishCatchStep;
use App\Enums\FishQuantityRange;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\FishSeller;
use App\Models\FishType;
use App\Services\Fish\FishCatchService;
use App\Services\Fish\FishAlertService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Media\MediaService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Fish Catch Post Flow Handler.
 *
 * OPTIMIZED FOR SPEED - fishermen do this at 5AM.
 * Target: Complete in under 2 minutes.
 *
 * Flow:
 * 1. Select fish type (list - top 8 popular)
 * 2. Select quantity (3 buttons)
 * 3. Enter price (free text)
 * 4. Upload photo (required - PM-008)
 * 5. Posted! Show subscriber count
 * 6. Add Another or Done
 *
 * @srs-ref PM-005 to PM-010 Catch posting requirements
 * @srs-ref Section 2.5.1 Seller Catch Posting Flow
 */
class FishCatchPostFlowHandler extends AbstractFlowHandler
{
    protected const STEP_ASK_FISH = 'ask_fish';
    protected const STEP_ASK_QTY = 'ask_qty';
    protected const STEP_ASK_PRICE = 'ask_price';
    protected const STEP_ASK_PHOTO = 'ask_photo';
    protected const STEP_CONFIRM = 'confirm';
    protected const STEP_ADD_MORE = 'add_more';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected FishCatchService $catchService,
        protected FishAlertService $alertService,
        protected ?MediaService $mediaService = null,
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
            self::STEP_ASK_FISH,
            self::STEP_ASK_QTY,
            self::STEP_ASK_PRICE,
            self::STEP_ASK_PHOTO,
            self::STEP_CONFIRM,
            self::STEP_ADD_MORE,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Entry Point
    |--------------------------------------------------------------------------
    */

    public function start(ConversationSession $session): void
    {
        $seller = $this->getFishSeller($session);

        if (!$seller) {
            $this->sendButtons(
                $session->phone,
                "❌ Fish seller aayi register cheyyuka first.",
                [
                    ['id' => 'fish_seller_register', 'title' => '🐟 Register'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        // Check if can post
        if (!$seller->can_post) {
            $this->sendButtons(
                $session->phone,
                "❌ Cannot post. Account suspended.",
                [['id' => 'main_menu', 'title' => '🏠 Menu']]
            );
            return;
        }

        $this->clearTempData($session);
        $this->askFishType($session);
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $seller = $this->getFishSeller($session);
        if (!$seller) {
            $this->start($session);
            return;
        }

        $step = $session->current_step;

        Log::debug('FishCatchPost step', ['step' => $step, 'type' => $message->type]);

        match ($step) {
            self::STEP_ASK_FISH => $this->handleFishType($message, $session),
            self::STEP_ASK_QTY => $this->handleQuantity($message, $session),
            self::STEP_ASK_PRICE => $this->handlePrice($message, $session),
            self::STEP_ASK_PHOTO => $this->handlePhoto($message, $session),
            self::STEP_CONFIRM => $this->handleConfirm($message, $session),
            self::STEP_ADD_MORE => $this->handleAddMore($message, $session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Fish Type (PM-005)
    |--------------------------------------------------------------------------
    */

    protected function askFishType(ConversationSession $session): void
    {
        // Get popular fish types (top 8)
        $fishTypes = FishType::active()
            ->orderBy('popularity', 'desc')
            ->limit(8)
            ->get();

        $sections = [[
            'title' => '🐟 Popular',
            'rows' => $fishTypes->map(fn($ft) => [
                'id' => 'fish_' . $ft->id,
                'title' => mb_substr($ft->emoji . ' ' . $ft->name_en, 0, 24),
                'description' => mb_substr($ft->name_ml ?? '', 0, 72),
            ])->toArray(),
        ]];

        // Add "More" and "Other" options
        $sections[0]['rows'][] = [
            'id' => 'fish_more',
            'title' => '📋 More fish types...',
            'description' => 'See all categories',
        ];
        $sections[0]['rows'][] = [
            'id' => 'fish_other',
            'title' => '✏️ Other (type name)',
            'description' => 'Not in list? Type it',
        ];

        $this->sendList(
            $session->phone,
            "🐟 *Enthu meen?*\n_Which fish?_",
            'Select Fish',
            $sections
        );

        $this->sessionManager->setFlowStep($session, FlowType::FISH_POST_CATCH, self::STEP_ASK_FISH);
    }

    protected function handleFishType(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        // Handle list selection
        if ($selectionId) {
            if ($selectionId === 'fish_more') {
                $this->showAllFishCategories($session);
                return;
            }

            if ($selectionId === 'fish_other') {
                $this->sendText($session->phone, "🐟 Meen name type cheyyuka:");
                $this->setTempData($session, 'awaiting_fish_name', true);
                return;
            }

            // Parse fish_ID
            if (str_starts_with($selectionId, 'fish_')) {
                $fishTypeId = (int) str_replace('fish_', '', $selectionId);
                $fishType = FishType::find($fishTypeId);

                if ($fishType) {
                    $this->setTempData($session, 'fish_type_id', $fishType->id);
                    $this->setTempData($session, 'fish_name', $fishType->display_name);
                    $this->askQuantity($session, $fishType);
                    return;
                }
            }
        }

        // Handle text input for "Other" fish
        if ($text && $this->getTempData($session, 'awaiting_fish_name')) {
            // Search for matching fish type
            $fishType = FishType::search($text)->first();

            if ($fishType) {
                $this->setTempData($session, 'fish_type_id', $fishType->id);
                $this->setTempData($session, 'fish_name', $fishType->display_name);
            } else {
                // Create custom entry or use generic
                $this->setTempData($session, 'fish_type_id', 1); // Generic fish ID
                $this->setTempData($session, 'fish_name', $text);
                $this->setTempData($session, 'custom_fish_name', $text);
            }

            $this->setTempData($session, 'awaiting_fish_name', false);
            $this->askQuantity($session);
            return;
        }

        // Re-prompt
        $this->askFishType($session);
    }

    protected function showAllFishCategories(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "🐟 *Category select cheyyuka:*",
            [
                ['id' => 'cat_sea', 'title' => '🌊 Sea Fish'],
                ['id' => 'cat_fresh', 'title' => '🏞️ Freshwater'],
                ['id' => 'cat_shell', 'title' => '🦐 Shellfish'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Quantity (PM-006)
    |--------------------------------------------------------------------------
    */

    protected function askQuantity(ConversationSession $session, ?FishType $fishType = null): void
    {
        $fishName = $fishType?->display_name ?? $this->getTempData($session, 'fish_name') ?? 'Fish';

        // Use 3 buttons (WhatsApp limit) - most common ranges
        $this->sendButtons(
            $session->phone,
            "📦 *{$fishName}*\nQuantity?",
            [
                ['id' => 'qty_5_10', 'title' => '5-10 kg'],
                ['id' => 'qty_10_25', 'title' => '10-25 kg'],
                ['id' => 'qty_25_plus', 'title' => '25+ kg'],
            ]
        );

        $this->sessionManager->setFlowStep($session, FlowType::FISH_POST_CATCH, self::STEP_ASK_QTY);
    }

    protected function handleQuantity(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        $quantityRange = null;

        // Handle button selection
        if ($selectionId) {
            $quantityRange = match ($selectionId) {
                'qty_5_10' => FishQuantityRange::RANGE_5_10,
                'qty_10_25' => FishQuantityRange::RANGE_10_25,
                'qty_25_plus', 'qty_25_50' => FishQuantityRange::RANGE_25_50,
                'qty_50_plus' => FishQuantityRange::RANGE_50_PLUS,
                default => FishQuantityRange::fromButtonId($selectionId),
            };
        }

        // Handle text input (e.g., "15" or "15kg")
        if (!$quantityRange && $text) {
            $kg = (int) preg_replace('/[^0-9]/', '', $text);
            if ($kg > 0) {
                $quantityRange = FishQuantityRange::fromKg($kg);
            }
        }

        if ($quantityRange) {
            $this->setTempData($session, 'quantity_range', $quantityRange->value);
            $this->askPrice($session);
            return;
        }

        // Re-prompt
        $this->askQuantity($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Price (PM-007)
    |--------------------------------------------------------------------------
    */

    protected function askPrice(ConversationSession $session): void
    {
        $fishName = $this->getTempData($session, 'fish_name') ?? 'Fish';
        $qty = FishQuantityRange::tryFrom($this->getTempData($session, 'quantity_range'));

        $this->sendText(
            $session->phone,
            "💰 *{$fishName}* ({$qty?->label()})\n₹/kg?"
        );

        $this->sessionManager->setFlowStep($session, FlowType::FISH_POST_CATCH, self::STEP_ASK_PRICE);
    }

    protected function handlePrice(IncomingMessage $message, ConversationSession $session): void
    {
        $text = $this->getTextContent($message);

        if ($text) {
            $price = $this->extractPrice($text);

            if ($price && $price > 0 && $price <= 10000) {
                $this->setTempData($session, 'price_per_kg', $price);
                $this->askPhoto($session);
                return;
            }
        }

        $this->sendText($session->phone, "❌ Valid price enter cheyyuka (1-10000)");
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Photo (PM-008 - REQUIRED)
    |--------------------------------------------------------------------------
    */

    protected function askPhoto(ConversationSession $session): void
    {
        $fishName = $this->getTempData($session, 'fish_name') ?? 'Fish';

        $this->sendText(
            $session->phone,
            "📸 *{$fishName}* photo ayakkuka:\n_Fresh photo = more customers!_"
        );

        $this->sessionManager->setFlowStep($session, FlowType::FISH_POST_CATCH, self::STEP_ASK_PHOTO);
    }

    protected function handlePhoto(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for image
        if ($message->isImage() && $message->getMediaId()) {
            $photoUrl = $this->downloadAndStorePhoto($message);

            if ($photoUrl) {
                $this->setTempData($session, 'photo_url', $photoUrl);
                $this->setTempData($session, 'photo_media_id', $message->getMediaId());
            } else {
                // Store media ID even if download failed
                $this->setTempData($session, 'photo_media_id', $message->getMediaId());
            }

            $this->createAndPostCatch($session);
            return;
        }

        // Allow skip for now (but discourage)
        $selectionId = $this->getSelectionId($message);
        if ($selectionId === 'skip_photo') {
            $this->createAndPostCatch($session);
            return;
        }

        // Re-prompt with skip option
        $this->sendButtons(
            $session->phone,
            "📸 Photo ayakkuka (more customers!):",
            [['id' => 'skip_photo', 'title' => '⏭️ Skip']]
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
            $result = $this->mediaService->downloadAndStore(
                $message->getMediaId(),
                'fish-catches/' . date('Y/m')
            );

            return $result['url'] ?? null;
        } catch (\Exception $e) {
            Log::error('Failed to download catch photo', ['error' => $e->getMessage()]);
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step 5: Create & Post
    |--------------------------------------------------------------------------
    */

    protected function createAndPostCatch(ConversationSession $session): void
    {
        $seller = $this->getFishSeller($session);

        try {
            $catch = $this->catchService->createCatch($seller, [
                'fish_type_id' => $this->getTempData($session, 'fish_type_id'),
                'quantity_range' => $this->getTempData($session, 'quantity_range'),
                'price_per_kg' => $this->getTempData($session, 'price_per_kg'),
                'photo_url' => $this->getTempData($session, 'photo_url'),
                'photo_media_id' => $this->getTempData($session, 'photo_media_id'),
            ]);

            // Send alerts to subscribers
            $alertResult = $this->alertService->processNewCatch($catch);
            $subscriberCount = $alertResult['total_subscribers'] ?? 0;

            $this->showSuccess($session, $catch, $subscriberCount);

        } catch (\Exception $e) {
            Log::error('Failed to create catch', [
                'error' => $e->getMessage(),
                'seller_id' => $seller->id,
            ]);

            $this->sendButtons(
                $session->phone,
                "❌ Failed. Try again.",
                [
                    ['id' => 'fish_post_catch', 'title' => '🔄 Retry'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
        }
    }

    protected function showSuccess(ConversationSession $session, $catch, int $subscriberCount): void
    {
        $fishName = $catch->fishType?->display_name ?? 'Fish';
        $price = $catch->price_display;

        // Show photo if available
        if ($catch->photo_url) {
            $this->whatsApp->sendImage(
                $session->phone,
                $catch->photo_url,
                "✅ Posted!"
            );
        }

        // Success message with social proof
        $alertText = $subscriberCount > 0
            ? "📢 *{$subscriberCount}* subscribers-nu alert ayachittund!"
            : "📢 Alerts will be sent to nearby customers.";

        $this->sendButtons(
            $session->phone,
            "✅ *Posted!* 🐟\n\n" .
            "*{$fishName}* • {$catch->quantity_display} • {$price}\n\n" .
            "{$alertText}\n\n" .
            "_Customers \"I'm Coming\" click cheyyumbol notify cheyyum._",
            [
                ['id' => 'add_another', 'title' => '🐟 Add Another'],
                ['id' => 'fish_menu', 'title' => '📋 My Catches'],
                ['id' => 'main_menu', 'title' => '✅ Done'],
            ]
        );

        $this->sessionManager->setFlowStep($session, FlowType::FISH_POST_CATCH, self::STEP_ADD_MORE);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 6: Add More (PM-009)
    |--------------------------------------------------------------------------
    */

    protected function handleAddMore(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'add_another') {
            $this->clearTempData($session);
            $this->askFishType($session);
            return;
        }

        if ($selectionId === 'fish_menu') {
            $this->clearTempData($session);
            // Route to fish seller menu
            $this->goToMenu($session);
            return;
        }

        $this->clearTempData($session);
        $this->goToMenu($session);
    }

    protected function handleConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        // Confirmation step - redirect to add more
        $this->handleAddMore($message, $session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function getFishSeller(ConversationSession $session): ?FishSeller
    {
        $user = $this->getUser($session);
        return $user?->fishSeller;
    }

    protected function extractPrice(string $text): ?float
    {
        $cleaned = preg_replace('/[^\d.]/', '', $text);
        return is_numeric($cleaned) ? (float) $cleaned : null;
    }
}