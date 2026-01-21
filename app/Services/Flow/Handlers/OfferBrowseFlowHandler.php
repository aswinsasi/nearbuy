<?php

declare(strict_types=1);

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
 * Flow Steps (FR-OFR-10 to FR-OFR-16):
 * 1. select_category - Choose a category to browse
 * 2. show_offers - Display list of nearby offers (sorted by distance)
 * 3. view_offer - Show offer details with actions
 * 4. show_location - Send shop location
 *
 * ENHANCEMENTS:
 * - "All Categories" quick browse option
 * - Better location handling and recovery
 * - View/location tap analytics
 * - Improved category matching
 * - Session state recovery
 *
 * @see SRS Section 3.2.3 - Customer Offer Browsing Requirements
 */
class OfferBrowseFlowHandler implements FlowHandlerInterface
{
    /**
     * Default search radius in kilometers.
     */
    protected const DEFAULT_RADIUS_KM = 5;

    /**
     * Maximum search radius in kilometers.
     */
    protected const MAX_RADIUS_KM = 50;

    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected OfferService $offerService,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return FlowType::OFFERS_BROWSE->value;
    }

    /**
     * {@inheritdoc}
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, [
            OfferStep::SELECT_CATEGORY->value,
            OfferStep::SELECT_RADIUS->value,
            OfferStep::SHOW_OFFERS->value,
            OfferStep::VIEW_OFFER->value,
            OfferStep::SHOW_LOCATION->value,
        ], true);
    }

    /**
     * Start the browse flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);

        // Check if user has location (FR-OFR-11: spatial queries require location)
        if (!$user || !$this->hasValidLocation($user)) {
            $this->requestLocation($session);
            return;
        }

        // Initialize session data
        $this->sessionManager->mergeTempData($session, [
            'user_lat' => $user->latitude,
            'user_lng' => $user->longitude,
            'radius' => config('nearbuy.offers.default_radius_km', self::DEFAULT_RADIUS_KM),
        ]);

        // Clear previous browsing state
        $this->sessionManager->removeTempData($session, 'current_offer_id');
        $this->sessionManager->removeTempData($session, 'selected_category');

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_BROWSE,
            OfferStep::SELECT_CATEGORY->value
        );

        $this->showCategorySelection($session);

        Log::info('Offer browse started', [
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = OfferStep::tryFrom($session->current_step);

        if (!$step) {
            Log::warning('Invalid offer browse step', [
                'step' => $session->current_step,
                'phone' => $this->maskPhone($session->phone),
            ]);
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
     * Handle invalid input with helpful re-prompting.
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
     * Handle category selection (FR-OFR-10).
     */
    protected function handleCategorySelection(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle location share during category selection
        if ($message->isLocation()) {
            $this->handleLocationShare($message, $session);
            $this->showCategorySelection($session);
            return;
        }

        $category = $this->extractCategory($message);

        if (!$category) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->sessionManager->setTempData($session, 'selected_category', $category);

        Log::debug('Category selected', [
            'category' => $category,
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Show offers for this category
        $this->sessionManager->setStep($session, OfferStep::SHOW_OFFERS->value);
        $this->showOffersList($session);
    }

    /**
     * Handle radius selection.
     */
    protected function handleRadiusSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $radius = $this->extractRadius($message);

        if (!$radius) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->sessionManager->setTempData($session, 'radius', $radius);

        Log::debug('Radius selected', [
            'radius' => $radius,
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Show offers with new radius
        $this->sessionManager->setStep($session, OfferStep::SHOW_OFFERS->value);
        $this->showOffersList($session);
    }

    /**
     * Handle offer selection from list (FR-OFR-13).
     */
    protected function handleOfferSelection(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for navigation actions
        if ($message->isListReply() || $message->isButtonReply()) {
            $selectionId = $message->getSelectionId();

            if (str_starts_with($selectionId, 'offer_')) {
                $offerId = (int) str_replace('offer_', '', $selectionId);
                $this->viewOffer($session, $offerId);
                return;
            }

            match ($selectionId) {
                'change_radius' => $this->showRadiusSelection($session),
                'change_category' => $this->showCategorySelection($session),
                'menu' => $this->goToMainMenu($session),
                default => $this->handleInvalidInput($message, $session),
            };
            return;
        }

        // Text input - try to match offer number
        if ($message->isText()) {
            $text = trim($message->text ?? '');

            // If user types a number, try to select that offer
            if (is_numeric($text)) {
                $offerIndex = (int) $text - 1;
                $offers = $this->getStoredOffers($session);

                if (isset($offers[$offerIndex])) {
                    $this->viewOffer($session, $offers[$offerIndex]['id']);
                    return;
                }
            }
        }

        $this->handleInvalidInput($message, $session);
    }

    /**
     * Handle actions on viewed offer (FR-OFR-15).
     */
    protected function handleOfferAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $this->extractAction($message, ['location', 'contact', 'back', 'menu']);

        match ($action) {
            'location' => $this->showShopLocation($session),    // FR-OFR-16
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
        $action = $this->extractAction($message, ['contact', 'back', 'menu']);

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

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_BROWSE,
            OfferStep::SELECT_CATEGORY->value
        );
    }

    /**
     * Show category selection (FR-OFR-10).
     */
    protected function showCategorySelection(ConversationSession $session, bool $isRetry = false): void
    {
        $lat = $this->sessionManager->getTempData($session, 'user_lat');
        $lng = $this->sessionManager->getTempData($session, 'user_lng');
        $radius = $this->sessionManager->getTempData($session, 'radius', self::DEFAULT_RADIUS_KM);

        // Get offer counts by category for display
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

        $this->sessionManager->setStep($session, OfferStep::SELECT_CATEGORY->value);
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

        $this->sessionManager->setStep($session, OfferStep::SELECT_RADIUS->value);
    }

    /**
     * Show list of offers (FR-OFR-11, FR-OFR-12, FR-OFR-13).
     */
    protected function showOffersList(ConversationSession $session): void
    {
        $lat = $this->sessionManager->getTempData($session, 'user_lat');
        $lng = $this->sessionManager->getTempData($session, 'user_lng');
        $radius = $this->sessionManager->getTempData($session, 'radius', self::DEFAULT_RADIUS_KM);
        $category = $this->sessionManager->getTempData($session, 'selected_category');

        // Verify location
        if (!$lat || !$lng) {
            $this->requestLocation($session);
            return;
        }

        // FR-OFR-11: Query offers within configurable radius using spatial queries
        // FR-OFR-12: Sort results by distance (nearest first)
        $offers = $this->offerService->getOffersNearLocation($lat, $lng, $radius, $category);

        if ($offers->isEmpty()) {
            $this->showNoOffersMessage($session, $category, $radius);
            return;
        }

        // Store offers for potential text selection
        $this->sessionManager->setTempData($session, 'offers_list', $offers->toArray());

        // Build header message
        $categoryLabel = $category && $category !== 'all'
            ? OfferMessages::getCategoryLabel($category)
            : '🔍 All Categories';

        $header = OfferMessages::format(OfferMessages::OFFERS_LIST_HEADER, [
            'category' => $categoryLabel,
            'count' => $offers->count(),
        ]);

        // FR-OFR-13: Display shop list with distance and validity information
        $rows = [];
        foreach ($offers as $offer) {
            $shop = $offer->shop;
            $distance = OfferMessages::formatDistance($offer->distance_km);
            $expiry = OfferMessages::formatExpiry($offer->expires_at);

            $rows[] = [
                'id' => 'offer_' . $offer->id,
                'title' => OfferMessages::truncate($shop->shop_name, 24),
                'description' => OfferMessages::truncate("{$distance} • {$expiry}", 72),
            ];
        }

        $sections = [
            [
                'title' => 'Nearby Offers',
                'rows' => array_slice($rows, 0, 10), // WhatsApp limit
            ],
        ];

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '👀 View Offers',
            $sections,
            null,
            "Within {$radius}km • Tap to view"
        );

        Log::info('Offers list shown', [
            'count' => $offers->count(),
            'category' => $category,
            'radius' => $radius,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Show no offers message with options.
     */
    protected function showNoOffersMessage(ConversationSession $session, ?string $category, int $radius): void
    {
        $categoryLabel = $category && $category !== 'all'
            ? OfferMessages::getCategoryLabel($category)
            : 'any category';

        $message = OfferMessages::format(OfferMessages::NO_OFFERS_IN_CATEGORY, [
            'category' => $categoryLabel,
            'radius' => $radius,
        ]);

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            OfferMessages::getNoOffersButtons()
        );
    }

    /**
     * View a specific offer (FR-OFR-14).
     */
    protected function viewOffer(ConversationSession $session, int $offerId): void
    {
        $this->sessionManager->setTempData($session, 'current_offer_id', $offerId);
        $this->sessionManager->setStep($session, OfferStep::VIEW_OFFER->value);
        $this->showCurrentOffer($session);
    }

    /**
     * Show current offer details (FR-OFR-14).
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

        // FR-OFR-06: Track offer view counts
        $this->offerService->incrementViewCount($offer);

        $shop = $offer->shop;

        // Build offer card caption
        $caption = OfferMessages::buildOfferCard([
            'shop' => ['shop_name' => $shop->shop_name],
            'expires_at' => $offer->expires_at,
            'caption' => $offer->caption,
        ], $offer->distance_km ?? 0);

        // FR-OFR-14: Send offer image with caption containing shop details
        if ($offer->media_type === 'image' && $offer->media_url) {
            $this->whatsApp->sendImage($session->phone, $offer->media_url, $caption);
        } elseif ($offer->media_type === 'pdf' && $offer->media_url) {
            $this->whatsApp->sendDocument(
                $session->phone,
                $offer->media_url,
                "{$shop->shop_name}_Offer.pdf",
                $caption
            );
        } else {
            $this->whatsApp->sendText($session->phone, $caption);
        }

        // FR-OFR-15: Provide Get Location and Call Shop action buttons
        $this->whatsApp->sendButtons(
            $session->phone,
            "What would you like to do?",
            OfferMessages::getOfferActionButtons()
        );

        Log::info('Offer viewed', [
            'offer_id' => $offer->id,
            'shop_id' => $shop->id,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Show shop location (FR-OFR-16).
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

        // FR-OFR-06: Track location tap metrics
        $this->offerService->incrementLocationTap($offer);

        // FR-OFR-16: Send shop location as WhatsApp location message type
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
                ['id' => 'contact', 'title' => '📞 Call Shop'],
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
        $phone = $owner?->phone ?? 'Not available';

        // Format phone for display
        $displayPhone = $this->formatPhoneForDisplay($phone);

        $message = OfferMessages::format(OfferMessages::SHOP_CONTACT, [
            'shop_name' => $shop->shop_name,
            'phone' => $displayPhone,
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
        app(MainMenuHandler::class)->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Handle location share during flow.
     */
    protected function handleLocationShare(IncomingMessage $message, ConversationSession $session): void
    {
        $coords = $message->getCoordinates();

        if ($coords) {
            $this->sessionManager->mergeTempData($session, [
                'user_lat' => $coords['latitude'],
                'user_lng' => $coords['longitude'],
            ]);

            // Update user location
            $user = $this->sessionManager->getUser($session);
            if ($user) {
                $user->update([
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                ]);
            }
        }
    }

    /**
     * Check if user has valid location.
     */
    protected function hasValidLocation($user): bool
    {
        return $user->latitude !== null
            && $user->longitude !== null
            && abs($user->latitude) <= 90
            && abs($user->longitude) <= 180;
    }

    /**
     * Extract category from message.
     */
    protected function extractCategory(IncomingMessage $message): ?string
    {
        if ($message->isListReply() || $message->isButtonReply()) {
            return $message->getSelectionId();
        }

        if ($message->isText()) {
            return $this->matchCategory(strtolower(trim($message->text ?? '')));
        }

        return null;
    }

    /**
     * Extract radius from message.
     */
    protected function extractRadius(IncomingMessage $message): ?int
    {
        $value = null;

        if ($message->isButtonReply()) {
            $value = (int) $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = trim($message->text ?? '');
            if (is_numeric($text)) {
                $value = (int) $text;
            }
        }

        // Validate radius
        if ($value && $value >= 1 && $value <= self::MAX_RADIUS_KM) {
            return $value;
        }

        return null;
    }

    /**
     * Extract action from message.
     */
    protected function extractAction(IncomingMessage $message, array $validActions): ?string
    {
        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
            if (in_array($action, $validActions, true)) {
                return $action;
            }
        }

        if ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));

            $keywords = [
                'location' => ['location', 'map', 'where', 'directions', 'navigate'],
                'contact' => ['contact', 'call', 'phone', 'number'],
                'back' => ['back', 'more', 'list', 'other'],
                'menu' => ['menu', 'home', 'main'],
            ];

            foreach ($keywords as $action => $terms) {
                if (in_array($action, $validActions, true)) {
                    foreach ($terms as $term) {
                        if (str_contains($text, $term)) {
                            return $action;
                        }
                    }
                }
            }
        }

        return null;
    }

    /**
     * Match text to category.
     */
    protected function matchCategory(string $text): ?string
    {
        $mappings = [
            'grocery' => ['grocery', 'grocer', 'kirana', 'supermarket', 'provisions', 'vegetable'],
            'electronics' => ['electronics', 'electronic', 'gadget', 'tech', 'computer', 'laptop'],
            'clothes' => ['clothes', 'clothing', 'fashion', 'garment', 'dress', 'textile'],
            'medical' => ['medical', 'medicine', 'pharmacy', 'drug', 'chemist', 'health'],
            'furniture' => ['furniture', 'wood', 'sofa', 'bed', 'table', 'chair'],
            'mobile' => ['mobile', 'phone', 'cellphone', 'smartphone'],
            'appliances' => ['appliance', 'electrical', 'ac', 'fridge', 'washing'],
            'hardware' => ['hardware', 'tools', 'building', 'plumbing', 'paint'],
            'restaurant' => ['restaurant', 'food', 'hotel', 'cafe', 'eat', 'dining'],
            'bakery' => ['bakery', 'bake', 'bread', 'cake', 'sweet'],
            'stationery' => ['stationery', 'book', 'office', 'paper'],
            'beauty' => ['beauty', 'cosmetic', 'salon', 'parlor', 'makeup'],
            'automotive' => ['automotive', 'auto', 'car', 'vehicle', 'bike', 'garage'],
            'jewelry' => ['jewelry', 'jewellery', 'gold', 'ornament', 'silver'],
            'sports' => ['sports', 'sport', 'fitness', 'gym'],
            'all' => ['all', 'any', 'everything', 'browse'],
        ];

        foreach ($mappings as $category => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $category;
                }
            }
        }

        return null;
    }

    /**
     * Get stored offers from session.
     */
    protected function getStoredOffers(ConversationSession $session): array
    {
        return $this->sessionManager->getTempData($session, 'offers_list', []);
    }

    /**
     * Format phone number for display.
     */
    protected function formatPhoneForDisplay(string $phone): string
    {
        // Remove country code for display if Indian number
        if (str_starts_with($phone, '91') && strlen($phone) === 12) {
            $phone = substr($phone, 2);
            return '+91 ' . substr($phone, 0, 5) . ' ' . substr($phone, 5);
        }

        return '+' . $phone;
    }

    /**
     * Mask phone number for logging.
     */
    protected function maskPhone(string $phone): string
    {
        $length = strlen($phone);
        if ($length < 6) {
            return str_repeat('*', $length);
        }
        return substr($phone, 0, 3) . str_repeat('*', $length - 6) . substr($phone, -3);
    }
}