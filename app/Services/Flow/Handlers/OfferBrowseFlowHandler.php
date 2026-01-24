<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\OfferStep;
use App\Models\ConversationSession;
use App\Models\Offer;
use App\Services\Offers\OfferService;
use App\Services\WhatsApp\Messages\MessageTemplates;
use App\Services\WhatsApp\Messages\OfferMessages;

/**
 * ENHANCED Offer Browse Flow Handler.
 *
 * Key improvements:
 * 1. Extends AbstractFlowHandler for consistent menu buttons
 * 2. Uses sendTextWithMenu/sendButtonsWithMenu patterns
 * 3. Main Menu button on all messages
 */
class OfferBrowseFlowHandler extends AbstractFlowHandler
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
        \App\Services\Session\SessionManager $sessionManager,
        \App\Services\WhatsApp\WhatsAppService $whatsApp,
        protected OfferService $offerService,
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::OFFERS_BROWSE;
    }

    protected function getSteps(): array
    {
        return [
            OfferStep::SELECT_CATEGORY->value,
            OfferStep::SELECT_RADIUS->value,
            OfferStep::SHOW_OFFERS->value,
            OfferStep::VIEW_OFFER->value,
            OfferStep::SHOW_LOCATION->value,
        ];
    }

    /**
     * Start the browse flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Check if user has location
        if (!$user || !$this->hasValidLocation($user)) {
            $this->requestLocationPrompt($session);
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

        $this->logInfo('Offer browse started', [
            'phone' => $this->maskPhone($session->phone),
        ]);
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
            $this->logError('Invalid offer browse step', [
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

    /**
     * Get expected input type.
     */
    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            OfferStep::SELECT_CATEGORY->value => 'list',
            OfferStep::SELECT_RADIUS->value => 'button',
            OfferStep::SHOW_OFFERS->value => 'list',
            OfferStep::VIEW_OFFER->value => 'button',
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
            OfferStep::SELECT_CATEGORY => $this->showCategorySelection($session),
            OfferStep::SELECT_RADIUS => $this->showRadiusSelection($session),
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

        $this->setTemp($session, 'selected_category', $category);

        $this->logInfo('Category selected', [
            'category' => $category,
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Show offers for this category
        $this->nextStep($session, OfferStep::SHOW_OFFERS->value);
        $this->showOffersList($session);
    }

    protected function handleRadiusSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $radius = $this->extractRadius($message);

        if (!$radius) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->setTemp($session, 'radius', $radius);

        $this->logInfo('Radius selected', [
            'radius' => $radius,
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Show offers with new radius
        $this->nextStep($session, OfferStep::SHOW_OFFERS->value);
        $this->showOffersList($session);
    }

    protected function handleOfferSelection(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for navigation actions
        if ($message->isListReply() || $message->isInteractive()) {
            $selectionId = $this->getSelectionId($message);

            if (str_starts_with($selectionId, 'offer_')) {
                $offerId = (int) str_replace('offer_', '', $selectionId);
                $this->viewOffer($session, $offerId);
                return;
            }

            match ($selectionId) {
                'change_radius' => $this->showRadiusSelection($session),
                'change_category' => $this->showCategorySelection($session),
                'menu', 'main_menu' => $this->goToMainMenu($session),
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

    protected function handleOfferAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $this->extractAction($message, ['location', 'contact', 'back', 'menu', 'main_menu']);

        match ($action) {
            'location' => $this->showShopLocation($session),
            'contact' => $this->showShopContact($session),
            'back' => $this->goBackToOffers($session),
            'menu', 'main_menu' => $this->goToMainMenu($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    protected function handleLocationAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $this->extractAction($message, ['contact', 'back', 'menu', 'main_menu']);

        match ($action) {
            'contact' => $this->showShopContact($session),
            'back' => $this->goBackToOffers($session),
            'menu', 'main_menu' => $this->goToMainMenu($session),
            default => $this->goBackToOffers($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Display Methods
    |--------------------------------------------------------------------------
    */

    protected function requestLocationPrompt(ConversationSession $session): void
    {
        $this->requestLocation(
            $session->phone,
            OfferMessages::BROWSE_NO_LOCATION
        );

        // Send follow-up with menu button
        $this->sendButtonsWithMenu(
            $session->phone,
            "📍 Share your location to see nearby offers, or return to menu.",
            []
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_BROWSE,
            OfferStep::SELECT_CATEGORY->value
        );
    }

    protected function showCategorySelection(ConversationSession $session, bool $isRetry = false): void
    {
        $lat = $this->getTemp($session, 'user_lat');
        $lng = $this->getTemp($session, 'user_lng');
        $radius = $this->getTemp($session, 'radius', self::DEFAULT_RADIUS_KM);

        // Get offer counts by category for display
        $counts = [];
        if ($lat && $lng) {
            $counts = $this->offerService->getOfferCountsByCategory((float) $lat, (float) $lng, $radius);
        }

        $message = $isRetry
            ? "Please select a category from the list:"
            : OfferMessages::BROWSE_START;

        $sections = OfferMessages::getCategorySections($counts);

        $this->sendListWithFooter(
            $session->phone,
            $message,
            '📦 Select Category',
            $sections,
            '📍 Offers Near You'
        );

        // Send follow-up with main menu button
        $this->sendButtonsWithMenu(
            $session->phone,
            "Select a category from the list above.",
            []  // Empty array = only Menu button will be added
        );

        $this->nextStep($session, OfferStep::SELECT_CATEGORY->value);
    }

    protected function showRadiusSelection(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select a search radius:"
            : OfferMessages::SELECT_RADIUS;

        $this->sendButtonsWithMenu(
            $session->phone,
            $message,
            OfferMessages::getRadiusButtons()
        );

        $this->nextStep($session, OfferStep::SELECT_RADIUS->value);
    }

    protected function showOffersList(ConversationSession $session): void
    {
        $lat = $this->getTemp($session, 'user_lat');
        $lng = $this->getTemp($session, 'user_lng');
        $radius = $this->getTemp($session, 'radius', self::DEFAULT_RADIUS_KM);
        $category = $this->getTemp($session, 'selected_category');

        // Verify location
        if (!$lat || !$lng) {
            $this->requestLocationPrompt($session);
            return;
        }

        $offers = $this->offerService->getOffersNearLocation((float) $lat, (float) $lng, $radius, $category);

        if ($offers->isEmpty()) {
            $this->showNoOffersMessage($session, $category, $radius);
            return;
        }

        // Store offers for potential text selection
        $this->setTemp($session, 'offers_list', $offers->toArray());

        // Build header message
        $categoryLabel = $category && $category !== 'all'
            ? OfferMessages::getCategoryLabel($category)
            : '🔍 All Categories';

        $header = OfferMessages::format(OfferMessages::OFFERS_LIST_HEADER, [
            'category' => $categoryLabel,
            'count' => $offers->count(),
        ]);

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
                'rows' => array_slice($rows, 0, 10),
            ],
        ];

        $this->sendListWithFooter(
            $session->phone,
            $header,
            '👀 View Offers',
            $sections,
            "Within {$radius}km"
        );

        // Send follow-up with navigation buttons
        $this->sendButtonsWithMenu(
            $session->phone,
            "Select an offer above, or:",
            [
                ['id' => 'change_category', 'title' => '📦 Change Category'],
                ['id' => 'change_radius', 'title' => '📏 Change Radius'],
            ]
        );

        $this->logInfo('Offers list shown', [
            'count' => $offers->count(),
            'category' => $category,
            'radius' => $radius,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    protected function showNoOffersMessage(ConversationSession $session, ?string $category, int $radius): void
    {
        $categoryLabel = $category && $category !== 'all'
            ? OfferMessages::getCategoryLabel($category)
            : 'any category';

        $message = OfferMessages::format(OfferMessages::NO_OFFERS_IN_CATEGORY, [
            'category' => $categoryLabel,
            'radius' => $radius,
        ]);

        $this->sendButtonsWithMenu(
            $session->phone,
            $message,
            OfferMessages::getNoOffersButtons()
        );
    }

    protected function viewOffer(ConversationSession $session, int $offerId): void
    {
        $this->setTemp($session, 'current_offer_id', $offerId);
        $this->nextStep($session, OfferStep::VIEW_OFFER->value);
        $this->showCurrentOffer($session);
    }

    protected function showCurrentOffer(ConversationSession $session): void
    {
        $offerId = $this->getTemp($session, 'current_offer_id');
        $lat = $this->getTemp($session, 'user_lat');
        $lng = $this->getTemp($session, 'user_lng');

        if (!$offerId) {
            $this->goBackToOffers($session);
            return;
        }

        $offer = $this->offerService->getOfferWithDistance($offerId, (float) $lat, (float) $lng);

        if (!$offer) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Offer not found or has expired.",
                [
                    ['id' => 'back', 'title' => '⬅️ More Offers'],
                    self::MENU_BUTTON,
                ]
            );
            $this->goBackToOffers($session);
            return;
        }

        // Track offer view counts
        $this->offerService->incrementViewCount($offer);

        $shop = $offer->shop;

        // Build offer card caption
        $caption = OfferMessages::buildOfferCard([
            'shop' => ['shop_name' => $shop->shop_name],
            'expires_at' => $offer->expires_at,
            'caption' => $offer->caption,
        ], $offer->distance_km ?? 0);

        // Send offer image with caption
        if ($offer->media_type === 'image' && $offer->media_url) {
            $this->sendImage($session->phone, $offer->media_url, $caption);
        } elseif ($offer->media_type === 'pdf' && $offer->media_url) {
            $this->sendDocument(
                $session->phone,
                $offer->media_url,
                "{$shop->shop_name}_Offer.pdf",
                $caption
            );
        } else {
            $this->sendTextWithMenu($session->phone, $caption);
        }

        // Provide action buttons with menu
        $this->sendButtonsWithMenu(
            $session->phone,
            "What would you like to do?",
            [
                ['id' => 'location', 'title' => '📍 Get Location'],
                ['id' => 'contact', 'title' => '📞 Call Shop'],
            ]
        );

        $this->logInfo('Offer viewed', [
            'offer_id' => $offer->id,
            'shop_id' => $shop->id,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    protected function showShopLocation(ConversationSession $session): void
    {
        $offerId = $this->getTemp($session, 'current_offer_id');
        $offer = Offer::with('shop')->find($offerId);

        if (!$offer || !$offer->shop) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Shop information not available.",
                [
                    ['id' => 'back', 'title' => '⬅️ More Offers'],
                    self::MENU_BUTTON,
                ]
            );
            $this->goBackToOffers($session);
            return;
        }

        $shop = $offer->shop;

        // Track location tap metrics
        $this->offerService->incrementLocationTap($offer);

        // Send shop location
        $this->sendLocation(
            $session->phone,
            (float) $shop->latitude,
            (float) $shop->longitude,
            $shop->shop_name,
            $shop->address
        );

        $message = OfferMessages::format(OfferMessages::SHOP_LOCATION_SENT, [
            'shop_name' => $shop->shop_name,
        ]);

        $this->sendButtonsWithMenu(
            $session->phone,
            $message,
            [
                ['id' => 'contact', 'title' => '📞 Call Shop'],
                ['id' => 'back', 'title' => '⬅️ More Offers'],
            ]
        );

        $this->nextStep($session, OfferStep::SHOW_LOCATION->value);

        $this->logInfo('Shop location viewed', [
            'offer_id' => $offer->id,
            'shop_id' => $shop->id,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    protected function showShopContact(ConversationSession $session): void
    {
        $offerId = $this->getTemp($session, 'current_offer_id');
        $offer = Offer::with('shop.owner')->find($offerId);

        if (!$offer || !$offer->shop) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Shop information not available.",
                [
                    ['id' => 'back', 'title' => '⬅️ More Offers'],
                    self::MENU_BUTTON,
                ]
            );
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

        $this->sendButtonsWithMenu(
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

    protected function goBackToOffers(ConversationSession $session): void
    {
        $this->sessionManager->removeTempData($session, 'current_offer_id');
        $this->nextStep($session, OfferStep::SHOW_OFFERS->value);
        $this->showOffersList($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
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
            $user = $this->getUser($session);
            if ($user) {
                $user->update([
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                ]);
            }
        }
    }

    protected function hasValidLocation($user): bool
    {
        return $user->latitude !== null
            && $user->longitude !== null
            && abs((float) $user->latitude) <= 90
            && abs((float) $user->longitude) <= 180;
    }

    protected function extractCategory(IncomingMessage $message): ?string
    {
        if ($message->isListReply() || $message->isInteractive()) {
            return $this->getSelectionId($message);
        }

        if ($message->isText()) {
            return $this->matchCategory(strtolower(trim($message->text ?? '')));
        }

        return null;
    }

    protected function extractRadius(IncomingMessage $message): ?int
    {
        $value = null;

        if ($message->isInteractive()) {
            $value = (int) $this->getSelectionId($message);
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

    protected function extractAction(IncomingMessage $message, array $validActions): ?string
    {
        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);
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

    protected function getStoredOffers(ConversationSession $session): array
    {
        return $this->getTemp($session, 'offers_list', []);
    }

    protected function formatPhoneForDisplay(string $phone): string
    {
        // Remove country code for display if Indian number
        if (str_starts_with($phone, '91') && strlen($phone) === 12) {
            $phone = substr($phone, 2);
            return '+91 ' . substr($phone, 0, 5) . ' ' . substr($phone, 5);
        }

        return '+' . $phone;
    }
}