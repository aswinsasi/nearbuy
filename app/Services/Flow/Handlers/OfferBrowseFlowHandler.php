<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\OfferStep;
use App\Enums\ShopCategory;
use App\Models\ConversationSession;
use App\Models\Offer;
use App\Services\Offers\OfferService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\Messages\OfferMessages;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Offer Browse Flow Handler - Customer discovers nearby deals.
 *
 * FLOW (feels like scrolling, not forms):
 * 1. SELECT_CATEGORY → "🛍️ Category select cheyyuka:" with offer counts
 * 2. SHOW_OFFERS → List sorted by distance with shop name + validity
 * 3. VIEW_OFFER → Image + caption + [📍 Get Location] [📞 Call Shop]
 * 4. SHOW_LOCATION → WhatsApp location message sent
 *
 * @srs-ref FR-OFR-10 - Display category list with offer counts per category
 * @srs-ref FR-OFR-11 - Query within configurable radius (default 5km) using spatial queries
 * @srs-ref FR-OFR-12 - Sort by distance (nearest first)
 * @srs-ref FR-OFR-13 - Display shop list with distance and validity info
 * @srs-ref FR-OFR-14 - Send offer image with caption containing shop details
 * @srs-ref FR-OFR-15 - Provide "Get Location" and "Call Shop" action buttons
 * @srs-ref FR-OFR-16 - Send shop location as WhatsApp location message type
 */
class OfferBrowseFlowHandler implements FlowHandlerInterface
{
    /** @srs-ref FR-OFR-11 - Default 5km radius */
    protected const DEFAULT_RADIUS_KM = 5;

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
        $offerStep = OfferStep::tryFrom($step);
        return $offerStep !== null && $offerStep->isBrowseStep();
    }

    /**
     * {@inheritdoc}
     */
    public function getExpectedInputType(string $step): string
    {
        return OfferStep::tryFrom($step)?->expectedInput() ?? 'text';
    }

    /*
    |--------------------------------------------------------------------------
    | Entry Point
    |--------------------------------------------------------------------------
    */

    /**
     * Start browse flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Check location
        if (!$user || !$this->hasLocation($user)) {
            $this->askForLocation($session);
            return;
        }

        // Store user location in temp
        $this->sessionManager->mergeTempData($session, [
            'lat' => (float) $user->latitude,
            'lng' => (float) $user->longitude,
            'radius' => self::DEFAULT_RADIUS_KM,
        ]);

        // Clear previous state
        $this->sessionManager->removeTempData($session, 'offer_id');
        $this->sessionManager->removeTempData($session, 'category');

        // Go to category selection
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_BROWSE,
            OfferStep::SELECT_CATEGORY->value
        );

        $this->showCategories($session);

        Log::info('Offer browse started', ['phone' => $this->maskPhone($session->phone)]);
    }

    /*
    |--------------------------------------------------------------------------
    | Main Handler
    |--------------------------------------------------------------------------
    */

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle location share at any point
        if ($message->isLocation()) {
            $this->handleLocationShare($message, $session);
            return;
        }

        $step = OfferStep::tryFrom($session->current_step);

        if (!$step || !$step->isBrowseStep()) {
            $this->start($session);
            return;
        }

        match ($step) {
            OfferStep::SELECT_CATEGORY => $this->handleCategorySelect($message, $session),
            OfferStep::SHOW_OFFERS => $this->handleOfferSelect($message, $session),
            OfferStep::VIEW_OFFER => $this->handleOfferAction($message, $session),
            OfferStep::SHOW_LOCATION => $this->handlePostLocation($message, $session),
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
            OfferStep::SELECT_CATEGORY => $this->showCategories($session, true),
            OfferStep::SHOW_OFFERS => $this->showOffers($session),
            OfferStep::VIEW_OFFER => $this->showOffer($session),
            default => $this->start($session),
        };
    }

    /**
     * Handle timeout.
     */
    public function handleTimeout(ConversationSession $session): void
    {
        $this->whatsApp->sendText(
            $session->phone,
            "⏰ Session expired. Type *offers* to browse again."
        );
        $this->sessionManager->resetToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Category Selection (FR-OFR-10)
    |--------------------------------------------------------------------------
    */

    protected function handleCategorySelect(IncomingMessage $message, ConversationSession $session): void
    {
        $category = $this->extractCategory($message);

        if (!$category) {
            $this->showCategories($session, true);
            return;
        }

        $this->sessionManager->setTempData($session, 'category', $category);

        // Move to show offers
        $this->sessionManager->setStep($session, OfferStep::SHOW_OFFERS->value);
        $this->showOffers($session);

        Log::info('Category selected', [
            'category' => $category,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Show category list with offer counts.
     *
     * @srs-ref FR-OFR-10 - Display category list with offer counts per category
     */
    protected function showCategories(ConversationSession $session, bool $isRetry = false): void
    {
        $lat = $this->sessionManager->getTempData($session, 'lat');
        $lng = $this->sessionManager->getTempData($session, 'lng');
        $radius = $this->sessionManager->getTempData($session, 'radius', self::DEFAULT_RADIUS_KM);

        // Get offer counts by category (FR-OFR-10)
        $counts = [];
        if ($lat && $lng) {
            $counts = Offer::countsByCategory((float) $lat, (float) $lng, (float) $radius);
        }

        $message = $isRetry
            ? "👆 List-ൽ നിന്ന് select cheyyuka"
            : "🛍️ *Category select cheyyuka:*";

        // Build category rows with counts
        $rows = $this->buildCategoryRows($counts);

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            '📦 Categories',
            [['title' => 'Shop Categories', 'rows' => $rows]],
            "📍 {$radius}km-ൽ ഉള്ള offers"
        );
    }

    protected function buildCategoryRows(array $counts): array
    {
        $categories = [
            'all' => ['icon' => '🔍', 'name' => 'All Offers'],
            'grocery' => ['icon' => '🛒', 'name' => 'Grocery'],
            'electronics' => ['icon' => '📱', 'name' => 'Electronics'],
            'clothes' => ['icon' => '👕', 'name' => 'Clothes'],
            'medical' => ['icon' => '💊', 'name' => 'Medical'],
            'furniture' => ['icon' => '🪑', 'name' => 'Furniture'],
            'mobile' => ['icon' => '📲', 'name' => 'Mobile'],
            'appliances' => ['icon' => '🔌', 'name' => 'Appliances'],
            'hardware' => ['icon' => '🔧', 'name' => 'Hardware'],
        ];

        $rows = [];
        foreach ($categories as $id => $cat) {
            $count = $counts[$id] ?? 0;
            $countText = $count > 0 ? "{$count} offer" . ($count !== 1 ? 's' : '') : 'No offers';

            $rows[] = [
                'id' => "cat_{$id}",
                'title' => mb_substr("{$cat['icon']} {$cat['name']}", 0, 24),
                'description' => $countText,
            ];
        }

        return array_slice($rows, 0, 10); // WhatsApp limit
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Offers List (FR-OFR-11, FR-OFR-12, FR-OFR-13)
    |--------------------------------------------------------------------------
    */

    protected function handleOfferSelect(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = null;

        if ($message->isListReply() || $message->isButtonReply()) {
            $selection = $message->getSelectionId();
        }

        // Handle navigation
        if ($selection === 'back_categories' || $selection === 'change_category') {
            $this->sessionManager->setStep($session, OfferStep::SELECT_CATEGORY->value);
            $this->showCategories($session);
            return;
        }

        if ($selection === 'main_menu' || $selection === 'menu') {
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Handle offer selection
        if ($selection && str_starts_with($selection, 'offer_')) {
            $offerId = (int) str_replace('offer_', '', $selection);
            $this->sessionManager->setTempData($session, 'offer_id', $offerId);
            $this->sessionManager->setStep($session, OfferStep::VIEW_OFFER->value);
            $this->showOffer($session);
            return;
        }

        // Invalid selection
        $this->showOffers($session);
    }

    /**
     * Show offers list sorted by distance.
     *
     * @srs-ref FR-OFR-11 - Query within configurable radius using spatial queries
     * @srs-ref FR-OFR-12 - Sort by distance (nearest first)
     * @srs-ref FR-OFR-13 - Display shop list with distance and validity info
     */
    protected function showOffers(ConversationSession $session): void
    {
        $lat = (float) $this->sessionManager->getTempData($session, 'lat');
        $lng = (float) $this->sessionManager->getTempData($session, 'lng');
        $radius = (float) $this->sessionManager->getTempData($session, 'radius', self::DEFAULT_RADIUS_KM);
        $category = $this->sessionManager->getTempData($session, 'category', 'all');

        // Query offers (FR-OFR-11, FR-OFR-12)
        $offers = Offer::browse($lat, $lng, $radius, $category === 'all' ? null : $category);

        if ($offers->isEmpty()) {
            $this->showNoOffers($session, $category, $radius);
            return;
        }

        // Store for reference
        $this->sessionManager->setTempData($session, 'offers_cache', $offers->pluck('id')->toArray());

        // Build header
        $catLabel = $category === 'all' ? 'All Offers' : ucfirst($category);
        $header = "🛍️ *{$catLabel}*\n\n{$offers->count()} offers found:";

        // Build rows (FR-OFR-13: distance + validity)
        $rows = [];
        foreach ($offers as $offer) {
            $shop = $offer->shop;
            $distance = $this->formatDistance($offer->distance_km);
            $expiry = $this->formatExpiry($offer->expires_at);

            $rows[] = [
                'id' => "offer_{$offer->id}",
                'title' => mb_substr($shop->shop_name, 0, 24),
                'description' => mb_substr("{$distance} • {$expiry}", 0, 72),
            ];
        }

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '👀 View Offers',
            [['title' => 'Nearby Offers', 'rows' => array_slice($rows, 0, 10)]],
            "Within {$radius}km"
        );

        // Navigation buttons
        $this->whatsApp->sendButtons(
            $session->phone,
            "Select an offer above, or:",
            [
                ['id' => 'change_category', 'title' => '📦 Categories'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );

        Log::info('Offers shown', [
            'count' => $offers->count(),
            'category' => $category,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    protected function showNoOffers(ConversationSession $session, string $category, float $radius): void
    {
        $catLabel = $category === 'all' ? 'any category' : $category;

        $this->whatsApp->sendButtons(
            $session->phone,
            "😕 *No offers found*\n\n{$radius}km-ൽ {$catLabel}-ൽ offers illa.\n\nTry another category?",
            [
                ['id' => 'change_category', 'title' => '📦 Categories'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: View Offer (FR-OFR-14, FR-OFR-15)
    |--------------------------------------------------------------------------
    */

    protected function handleOfferAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = mb_strtolower(trim($message->text ?? ''));
            if (str_contains($text, 'location') || str_contains($text, 'map')) {
                $action = 'get_location';
            } elseif (str_contains($text, 'call') || str_contains($text, 'phone')) {
                $action = 'call_shop';
            } elseif (str_contains($text, 'back')) {
                $action = 'back_list';
            }
        }

        match ($action) {
            'get_location' => $this->sendShopLocation($session),
            'call_shop' => $this->showShopContact($session),
            'back_list' => $this->backToOffersList($session),
            'main_menu', 'menu' => $this->sessionManager->resetToMainMenu($session),
            default => $this->showOffer($session),
        };
    }

    /**
     * Show single offer with image and shop details.
     *
     * @srs-ref FR-OFR-14 - Send offer image with caption containing shop details
     * @srs-ref FR-OFR-15 - Provide "Get Location" and "Call Shop" action buttons
     * @srs-ref FR-OFR-06 - Increment view_count
     */
    protected function showOffer(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'offer_id');
        $lat = (float) $this->sessionManager->getTempData($session, 'lat');
        $lng = (float) $this->sessionManager->getTempData($session, 'lng');

        if (!$offerId) {
            $this->backToOffersList($session);
            return;
        }

        // Get offer with distance
        $offer = Offer::query()
            ->select('offers.*')
            ->selectRaw('
                (ST_Distance_Sphere(
                    POINT(shops.longitude, shops.latitude),
                    POINT(?, ?)
                ) / 1000) as distance_km
            ', [$lng, $lat])
            ->join('shops', 'offers.shop_id', '=', 'shops.id')
            ->where('offers.id', $offerId)
            ->with('shop')
            ->first();

        if (!$offer || !$offer->isActive()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                "❌ Offer expired or not found.",
                [
                    ['id' => 'back_list', 'title' => '⬅️ Back'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        // FR-OFR-06: Track view
        $offer->recordView();

        $shop = $offer->shop;
        $distance = $this->formatDistance($offer->distance_km);
        $expiry = $this->formatExpiry($offer->expires_at);

        // FR-OFR-14: Build caption with shop details
        $caption = "🛍️ *{$shop->shop_name}*\n" .
            "📍 {$distance} away\n" .
            "⏰ Valid: {$expiry}";

        if ($offer->caption) {
            $caption .= "\n\n{$offer->caption}";
        }

        // Send offer media with caption
        if ($offer->isImage()) {
            $this->whatsApp->sendImage($session->phone, $offer->media_url, $caption);
        } elseif ($offer->isPdf()) {
            $this->whatsApp->sendDocument(
                $session->phone,
                $offer->media_url,
                "{$shop->shop_name}_Offer.pdf",
                $caption
            );
        } else {
            $this->whatsApp->sendText($session->phone, $caption);
        }

        // FR-OFR-15: Action buttons
        $this->whatsApp->sendButtons(
            $session->phone,
            "What would you like to do?",
            [
                ['id' => 'get_location', 'title' => '📍 Get Location'],
                ['id' => 'call_shop', 'title' => '📞 Call Shop'],
                ['id' => 'back_list', 'title' => '⬅️ More Offers'],
            ]
        );

        Log::info('Offer viewed', [
            'offer_id' => $offer->id,
            'shop_id' => $shop->id,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Location (FR-OFR-16)
    |--------------------------------------------------------------------------
    */

    protected function handlePostLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'call_shop' => $this->showShopContact($session),
            'back_list' => $this->backToOffersList($session),
            'main_menu', 'menu' => $this->sessionManager->resetToMainMenu($session),
            default => $this->backToOffersList($session),
        };
    }

    /**
     * Send shop location as WhatsApp location message.
     *
     * @srs-ref FR-OFR-16 - Send shop location as WhatsApp location message type
     * @srs-ref FR-OFR-06 - Increment location_tap_count
     */
    protected function sendShopLocation(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'offer_id');
        $offer = Offer::with('shop')->find($offerId);

        if (!$offer || !$offer->shop) {
            $this->whatsApp->sendText($session->phone, "❌ Shop info not available.");
            $this->backToOffersList($session);
            return;
        }

        $shop = $offer->shop;

        // FR-OFR-06: Track location tap
        $offer->recordLocationTap();

        // FR-OFR-16: Send WhatsApp location message
        $this->whatsApp->sendLocation(
            $session->phone,
            (float) $shop->latitude,
            (float) $shop->longitude,
            $shop->shop_name,
            $shop->address ?? ''
        );

        $this->whatsApp->sendButtons(
            $session->phone,
            "📍 *{$shop->shop_name}*\n\nMaps-ൽ tap cheythu directions get cheyyuka.",
            [
                ['id' => 'call_shop', 'title' => '📞 Call Shop'],
                ['id' => 'back_list', 'title' => '⬅️ More Offers'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );

        $this->sessionManager->setStep($session, OfferStep::SHOW_LOCATION->value);

        Log::info('Shop location sent', [
            'offer_id' => $offer->id,
            'shop_id' => $shop->id,
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    protected function showShopContact(ConversationSession $session): void
    {
        $offerId = $this->sessionManager->getTempData($session, 'offer_id');
        $offer = Offer::with('shop.owner')->find($offerId);

        if (!$offer || !$offer->shop) {
            $this->backToOffersList($session);
            return;
        }

        $shop = $offer->shop;
        $owner = $shop->owner;
        $phone = $owner?->phone ?? 'Not available';

        // Format for display
        $displayPhone = $this->formatPhoneDisplay($phone);

        $this->whatsApp->sendButtons(
            $session->phone,
            "📞 *{$shop->shop_name}*\n\nPhone: {$displayPhone}\n\n_Tap number to call_",
            [
                ['id' => 'get_location', 'title' => '📍 Get Location'],
                ['id' => 'back_list', 'title' => '⬅️ More Offers'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    protected function backToOffersList(ConversationSession $session): void
    {
        $this->sessionManager->removeTempData($session, 'offer_id');
        $this->sessionManager->setStep($session, OfferStep::SHOW_OFFERS->value);
        $this->showOffers($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Location Handling
    |--------------------------------------------------------------------------
    */

    protected function askForLocation(ConversationSession $session): void
    {
        $this->whatsApp->requestLocation(
            $session->phone,
            "📍 *Location share cheyyuka*\n\nNearby offers kaanaan ninte location vendii."
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::OFFERS_BROWSE,
            OfferStep::SELECT_CATEGORY->value
        );
    }

    protected function handleLocationShare(IncomingMessage $message, ConversationSession $session): void
    {
        $coords = $message->getCoordinates();

        if ($coords) {
            $this->sessionManager->mergeTempData($session, [
                'lat' => $coords['latitude'],
                'lng' => $coords['longitude'],
            ]);

            // Update user location
            $user = $this->getUser($session);
            if ($user) {
                $user->update([
                    'latitude' => $coords['latitude'],
                    'longitude' => $coords['longitude'],
                ]);
            }

            $this->whatsApp->sendText($session->phone, "✅ Location saved!");
        }

        // Continue with category selection
        $this->showCategories($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function getUser(ConversationSession $session): ?\App\Models\User
    {
        if ($session->user_id) {
            return \App\Models\User::find($session->user_id);
        }
        return \App\Models\User::where('phone', $session->phone)->first();
    }

    protected function hasLocation($user): bool
    {
        return $user->latitude !== null
            && $user->longitude !== null
            && abs((float) $user->latitude) <= 90
            && abs((float) $user->longitude) <= 180;
    }

    protected function extractCategory(IncomingMessage $message): ?string
    {
        if ($message->isListReply()) {
            $id = $message->getSelectionId();
            if (str_starts_with($id, 'cat_')) {
                return str_replace('cat_', '', $id);
            }
        }

        if ($message->isText()) {
            $text = mb_strtolower(trim($message->text ?? ''));

            $map = [
                'all' => ['all', 'ellaam', 'എല്ലാം'],
                'grocery' => ['grocery', 'kirana', 'സാധനം'],
                'electronics' => ['electronics', 'electronic'],
                'clothes' => ['clothes', 'clothing', 'dress', 'വസ്ത്രം'],
                'medical' => ['medical', 'medicine', 'pharmacy', 'മരുന്ന്'],
                'furniture' => ['furniture', 'ഫർണിച്ചർ'],
                'mobile' => ['mobile', 'phone', 'ഫോൺ'],
                'appliances' => ['appliances', 'appliance'],
                'hardware' => ['hardware', 'tools'],
            ];

            foreach ($map as $cat => $keywords) {
                foreach ($keywords as $kw) {
                    if (str_contains($text, $kw)) {
                        return $cat;
                    }
                }
            }
        }

        return null;
    }

    protected function formatDistance(float $km): string
    {
        if ($km < 0.1) {
            return 'Very close';
        }
        if ($km < 1) {
            return round($km * 1000) . 'm';
        }
        return round($km, 1) . 'km';
    }

    protected function formatExpiry(\Carbon\Carbon $date): string
    {
        if ($date->isPast()) {
            return 'Expired';
        }
        if ($date->isToday()) {
            return 'Today ' . $date->format('g:i A');
        }
        if ($date->isTomorrow()) {
            return 'Tomorrow';
        }
        if ($date->diffInDays(now()) < 7) {
            return $date->format('l');
        }
        return $date->format('M j');
    }

    protected function formatPhoneDisplay(string $phone): string
    {
        if (str_starts_with($phone, '91') && strlen($phone) === 12) {
            $num = substr($phone, 2);
            return "+91 {$num[0]}{$num[1]}{$num[2]}{$num[3]}{$num[4]} {$num[5]}{$num[6]}{$num[7]}{$num[8]}{$num[9]}";
        }
        return "+{$phone}";
    }

    protected function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 6) return str_repeat('*', $len);
        return substr($phone, 0, 3) . str_repeat('*', $len - 6) . substr($phone, -3);
    }
}