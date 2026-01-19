<?php

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\OfferStep;
use App\Models\ConversationSession;
use App\Models\Offer;
use App\Services\Offers\OfferService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\OfferMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handles the offer browsing flow for customers.
 *
 * Flow Steps:
 * 1. select_category - Choose a category to browse
 * 2. show_offers - Display list of nearby offers
 * 3. view_offer - Show offer details with actions
 * 4. show_location - Send shop location
 */
class OfferBrowseFlowHandler implements FlowHandlerInterface
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected OfferService $offerService,
    ) {}

    /**
     * Get the flow name.
     */
    public function getName(): string
    {
        return FlowType::OFFERS_BROWSE->value;
    }

    /**
     * Check if this handler can process the given step.
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, [
            OfferStep::SELECT_CATEGORY->value,
            OfferStep::SELECT_RADIUS->value,
            OfferStep::SHOW_OFFERS->value,
            OfferStep::VIEW_OFFER->value,
            OfferStep::SHOW_LOCATION->value,
        ]);
    }

    /**
     * Start the browse flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);

        // Check if user has location
        if (!$user || !$user->hasLocation()) {
            $this->requestLocation($session);
            return;
        }

        // Store user location in temp data
        $this->sessionManager->mergeTempData($session, [
            'user_lat' => $user->latitude,
            'user_lng' => $user->longitude,
            'radius' => config('nearbuy.offers.default_radius_km', 5),
        ]);

        // Clear any previous offer data
        $this->sessionManager->removeTempData($session, 'current_offer_id');
        $this->sessionManager->removeTempData($session, 'selected_category');

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_BROWSE,
            OfferStep::SELECT_CATEGORY->value
        );

        $this->showCategorySelection($session);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = OfferStep::tryFrom($session->current_step);

        if (!$step) {
            Log::warning('Invalid offer browse step', ['step' => $session->current_step]);
            $this->start($session);
            return;
        }

        match ($step) {
            OfferStep::SELECT_CATEGORY => $this->handleCategorySelection($message, $session),
            OfferStep::SELECT_RADIUS => $this->handleRadiusSelection($message, $session),
            OfferStep::SHOW_OFFERS => $this->handleOfferSelection($message, $session),
            OfferStep::VIEW_OFFER => $this->handleOfferAction($message, $session),
            OfferStep::SHOW_LOCATION => $this->handleLocationAction($message, $session),
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
            OfferStep::SELECT_CATEGORY => $this->showCategorySelection($session, true),
            OfferStep::SELECT_RADIUS => $this->showRadiusSelection($session, true),
            OfferStep::SHOW_OFFERS => $this->showOffersList($session),
            OfferStep::VIEW_OFFER => $this->showCurrentOffer($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    /**
     * Handle category selection.
     */
    protected function handleCategorySelection(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle location message if user shares location
        if ($message->isLocation()) {
            $coords = $message->getCoordinates();
            $this->sessionManager->mergeTempData($session, [
                'user_lat' => $coords['latitude'],
                'user_lng' => $coords['longitude'],
            ]);
            $this->showCategorySelection($session);
            return;
        }

        $category = null;

        if ($message->isListReply()) {
            $category = $message->getSelectionId();
        } elseif ($message->isButtonReply()) {
            $category = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            $category = $this->matchCategory($text);
        }

        if (!$category) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->sessionManager->setTempData($session, 'selected_category', $category);

        // Show offers for this category
        $this->sessionManager->setStep($session, OfferStep::SHOW_OFFERS->value);
        $this->showOffersList($session);
    }

    /**
     * Handle radius selection.
     */
    protected function handleRadiusSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $radius = null;

        if ($message->isButtonReply()) {
            $radius = (int) $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = trim($message->text ?? '');
            if (is_numeric($text)) {
                $radius = (int) $text;
            }
        }

        if (!$radius || $radius < 1 || $radius > 50) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->sessionManager->setTempData($session, 'radius', $radius);

        // Show offers with new radius
        $this->sessionManager->setStep($session, OfferStep::SHOW_OFFERS->value);
        $this->showOffersList($session);
    }

    /**
     * Handle offer selection from list.
     */
    protected function handleOfferSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $offerId = null;

        if ($message->isListReply()) {
            $selectionId = $message->getSelectionId();
            if (str_starts_with($selectionId, 'offer_')) {
                $offerId = (int) str_replace('offer_', '', $selectionId);
            } elseif ($selectionId === 'change_radius') {
                $this->sessionManager->setStep($session, OfferStep::SELECT_RADIUS->value);
                $this->showRadiusSelection($session);
                return;
            } elseif ($selectionId === 'change_category') {
                $this->sessionManager->setStep($session, OfferStep::SELECT_CATEGORY->value);
                $this->showCategorySelection($session);
                return;
            }
        } elseif ($message->isButtonReply()) {
            $action = $message->getSelectionId();
            if ($action === 'change_radius') {
                $this->sessionManager->setStep($session, OfferStep::SELECT_RADIUS->value);
                $this->showRadiusSelection($session);
                return;
            } elseif ($action === 'change_category') {
                $this->sessionManager->setStep($session, OfferStep::SELECT_CATEGORY->value);
                $this->showCategorySelection($session);
                return;
            } elseif ($action === 'menu') {
                $this->goToMainMenu($session);
                return;
            }
        }

        if (!$offerId) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Store selected offer
        $this->sessionManager->setTempData($session, 'current_offer_id', $offerId);

        // Show offer details
        $this->sessionManager->setStep($session, OfferStep::VIEW_OFFER->value);
        $this->showCurrentOffer($session);
    }

    /**
     * Handle actions on viewed offer.
     */
    protected function handleOfferAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (str_contains($text, 'location') || str_contains($text, 'map')) {
                $action = 'location';
            } elseif (str_contains($text, 'call') || str_contains($text, 'contact') || str_contains($text, 'phone')) {
                $action = 'contact';
            } elseif (str_contains($text, 'back') || str_contains($text, 'more')) {
                $action = 'back';
            }
        }

        match ($action) {
            'location' => $this->showShopLocation($session),
            'contact' => $this->showShopContact($session),
            'back' => $this->goBackToOffers($session),
            'menu' => $this->goToMainMenu($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    /**
     * Handle post-location actions.
     */
    protected function handleLocationAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        }

        match ($action) {
            'contact' => $this->showShopContact($session),
            'back' => $this->goBackToOffers($session),
            'menu' => $this->goToMainMenu($session),
            default => $this->goBackToOffers($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Display Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Request location from user.
     */
    protected function requestLocation(ConversationSession $session): void
    {
        $this->whatsApp->requestLocation(
            $session->phone,
            OfferMessages::BROWSE_NO_LOCATION
        );

        $this->sessionManager->setStep($session, OfferStep::SELECT_CATEGORY->value);
    }

    /**
     * Show category selection.
     */
    protected function showCategorySelection(ConversationSession $session, bool $isRetry = false): void
    {
        $lat = $this->sessionManager->getTempData($session, 'user_lat');
        $lng = $this->sessionManager->getTempData($session, 'user_lng');
        $radius = $this->sessionManager->getTempData($session, 'radius', 5);

        // Get offer counts by category
        $counts = [];
        if ($lat && $lng) {
            $counts = $this->offerService->getOfferCountsByCategory($lat, $lng, $radius);
        }

        $message = $isRetry
            ? "Please select a category from the list:"
            : OfferMessages::BROWSE_START;

        $sections = OfferMessages::getCategorySections($counts);

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            '📦 Select Category',
            $sections,
            '📍 Offers Near You'
        );
    }

    /**
     * Show radius selection.
     */
    protected function showRadiusSelection(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select a search radius:"
            : OfferMessages::SELECT_RADIUS;

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            OfferMessages::getRadiusButtons()
        );
    }

    /**
     * Show list of offers.
     */
    protected function showOffersList(ConversationSession $session): void
    {
        $lat = $this->sessionManager->getTempData($session, 'user_lat');
        $lng = $this->sessionManager->getTempData($session, 'user_lng');
        $radius = $this->sessionManager->getTempData($session, 'radius', 5);
        $category = $this->sessionManager->getTempData($session, 'selected_category');

        if (!$lat || !$lng) {
            $this->requestLocation($session);
            return;
        }

        $offers = $this->offerService->getOffersNearLocation($lat, $lng, $radius, $category);

        if ($offers->isEmpty()) {
            $message = OfferMessages::format(OfferMessages::NO_OFFERS_IN_CATEGORY, [
                'category' => OfferMessages::getCategoryLabel($category ?? 'all'),
                'radius' => $radius,
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                [
                    ['id' => 'change_radius', 'title' => '📍 Change Radius'],
                    ['id' => 'change_category', 'title' => '📦 Other Category'],
                    ['id' => 'menu', 'title' => '🏠 Main Menu'],
                ]
            );
            return;
        }

        $header = OfferMessages::format(OfferMessages::OFFERS_LIST_HEADER, [
            'category' => OfferMessages::getCategoryLabel($category ?? 'all'),
            'count' => $offers->count(),
        ]);

        // Build offers list for WhatsApp
        $rows = [];
        foreach ($offers as $offer) {
            $shop = $offer->shop;
            $distance = OfferMessages::formatDistance($offer->distance_km);

            $rows[] = [
                'id' => 'offer_' . $offer->id,
                'title' => mb_substr($shop->shop_name, 0, 24),
                'description' => mb_substr("{$distance} • " . OfferMessages::formatExpiry($offer->expires_at), 0, 72),
            ];
        }

        $sections = [
            [
                'title' => 'Nearby Offers',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '👀 View Offers',
            $sections,
            null,
            "Within {$radius}km • Tap offer to view"
        );
    }

    /**
     * Show current offer details.
     */
    protected function showCurrentOffer(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'current_offer_id');
        $lat = $this->sessionManager->getTempData($session, 'user_lat');
        $lng = $this->sessionManager->getTempData($session, 'user_lng');

        if (!$offerId) {
            $this->goBackToOffers($session);
            return;
        }

        $offer = $this->offerService->getOfferWithDistance($offerId, $lat, $lng);

        if (!$offer) {
            $this->whatsApp->sendText($session->phone, "❌ Offer not found or has expired.");
            $this->goBackToOffers($session);
            return;
        }

        // Increment view count
        $this->offerService->incrementViewCount($offer);

        $shop = $offer->shop;
        $caption = OfferMessages::buildOfferCard([
            'shop' => ['shop_name' => $shop->shop_name],
            'expires_at' => $offer->expires_at,
            'caption' => $offer->caption,
        ], $offer->distance_km);

        // Send offer media
        if ($offer->media_type === 'image') {
            $this->whatsApp->sendImage($session->phone, $offer->media_url, $caption);
        } else {
            $this->whatsApp->sendDocument(
                $session->phone,
                $offer->media_url,
                'Offer.pdf',
                $caption
            );
        }

        // Send action buttons
        $this->whatsApp->sendButtons(
            $session->phone,
            "What would you like to do?",
            OfferMessages::getOfferActionButtons()
        );

        Log::info('Offer viewed', [
            'offer_id' => $offer->id,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Show shop location.
     */
    protected function showShopLocation(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'current_offer_id');
        $offer = Offer::with('shop')->find($offerId);

        if (!$offer || !$offer->shop) {
            $this->whatsApp->sendText($session->phone, "❌ Shop information not available.");
            $this->goBackToOffers($session);
            return;
        }

        $shop = $offer->shop;

        // Increment location tap count
        $this->offerService->incrementLocationTap($offer);

        // Send location
        $this->whatsApp->sendLocation(
            $session->phone,
            $shop->latitude,
            $shop->longitude,
            $shop->shop_name,
            $shop->address
        );

        $message = OfferMessages::format(OfferMessages::SHOP_LOCATION_SENT, [
            'shop_name' => $shop->shop_name,
        ]);

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'contact', 'title' => '📞 Contact Shop'],
                ['id' => 'back', 'title' => '⬅️ More Offers'],
            ]
        );

        $this->sessionManager->setStep($session, OfferStep::SHOW_LOCATION->value);

        Log::info('Shop location viewed', [
            'offer_id' => $offer->id,
            'shop_id' => $shop->id,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Show shop contact information.
     */
    protected function showShopContact(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'current_offer_id');
        $offer = Offer::with('shop.owner')->find($offerId);

        if (!$offer || !$offer->shop) {
            $this->whatsApp->sendText($session->phone, "❌ Shop information not available.");
            $this->goBackToOffers($session);
            return;
        }

        $shop = $offer->shop;
        $owner = $shop->owner;
        $phone = $owner ? $owner->phone : 'Not available';

        $message = OfferMessages::format(OfferMessages::SHOP_CONTACT, [
            'shop_name' => $shop->shop_name,
            'phone' => $phone,
        ]);

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'location', 'title' => '📍 Get Location'],
                ['id' => 'back', 'title' => '⬅️ More Offers'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Go back to offers list.
     */
    protected function goBackToOffers(ConversationSession $session): void
    {
        $this->sessionManager->removeTempData($session, 'current_offer_id');
        $this->sessionManager->setStep($session, OfferStep::SHOW_OFFERS->value);
        $this->showOffersList($session);
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
     * Match text to category.
     */
    protected function matchCategory(string $text): ?string
    {
        $categories = [
            'grocery' => ['grocery', 'grocer', 'kirana', 'supermarket', 'provisions'],
            'electronics' => ['electronics', 'electronic', 'gadget', 'tech'],
            'clothes' => ['clothes', 'clothing', 'fashion', 'garment', 'dress'],
            'medical' => ['medical', 'medicine', 'pharmacy', 'drug', 'chemist'],
            'furniture' => ['furniture', 'wood', 'sofa', 'bed'],
            'mobile' => ['mobile', 'phone', 'cellphone', 'smartphone'],
            'appliances' => ['appliance', 'electrical'],
            'hardware' => ['hardware', 'tools', 'building'],
            'restaurant' => ['restaurant', 'food', 'hotel', 'cafe', 'eat'],
            'bakery' => ['bakery', 'bake', 'bread', 'cake', 'sweet'],
            'stationery' => ['stationery', 'book', 'office'],
            'beauty' => ['beauty', 'cosmetic', 'salon', 'parlor'],
            'automotive' => ['automotive', 'auto', 'car', 'vehicle', 'bike'],
            'jewelry' => ['jewelry', 'jewellery', 'gold', 'ornament'],
            'sports' => ['sports', 'sport', 'fitness', 'gym'],
            'all' => ['all', 'any', 'everything'],
        ];

        foreach ($categories as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $category;
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