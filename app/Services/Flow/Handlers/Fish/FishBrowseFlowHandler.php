<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\FishType;
use App\Services\Fish\FishCatchService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FishMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for browsing fresh fish.
 *
 * @srs-ref PM-016 to PM-020 - Alert delivery and response handling
 */
class FishBrowseFlowHandler extends AbstractFlowHandler
{
    protected const STEP_LOCATION = 'location';
    protected const STEP_BROWSE = 'browse';
    protected const STEP_FILTER = 'filter';
    protected const STEP_DETAIL = 'detail';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected FishCatchService $catchService
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::FISH_BROWSE;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_LOCATION,
            self::STEP_BROWSE,
            self::STEP_FILTER,
            self::STEP_DETAIL,
        ];
    }

    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if ($user && $user->latitude && $user->longitude) {
            $this->setTempData($session, 'latitude', $user->latitude);
            $this->setTempData($session, 'longitude', $user->longitude);
            $this->nextStep($session, self::STEP_BROWSE);
            $this->showBrowseResults($session);
            return;
        }

        $this->nextStep($session, self::STEP_LOCATION);

        // IMPROVED: Shorter, friendlier message
        $this->requestLocation(
            $session->phone,
            "🐟 *Pacha Meen Browse*\n\n📍 Location share cheyyuka — nearby fish kaanaam!"
        );
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = $session->current_step;

        // Handle catch selection from any step
        $catchId = $this->extractCatchId($message);
        if ($catchId) {
            $this->showCatchDetail($session, $catchId);
            return;
        }

        // Handle action buttons
        if ($this->handleActionButton($message, $session)) {
            return;
        }

        match ($step) {
            self::STEP_LOCATION => $this->handleLocation($message, $session),
            self::STEP_BROWSE => $this->handleBrowse($message, $session),
            self::STEP_FILTER => $this->handleFilter($message, $session),
            self::STEP_DETAIL => $this->handleDetail($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Handle alert response from FlowRouter.
     */
    public function handleAlertResponse(
        ConversationSession $session,
        int $catchId,
        string $action,
        int $alertId
    ): void {
        Log::info('Fish alert response', compact('catchId', 'action', 'alertId'));

        match ($action) {
            'coming' => $this->handleComingAction($session, $catchId, $alertId),
            'location' => $this->handleLocationAction($session, $catchId),
            default => $this->sendText($session->phone, '❌ Invalid action'),
        };
    }

    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $location = $this->getLocation($message);

        if ($location) {
            $this->setTempData($session, 'latitude', $location['latitude']);
            $this->setTempData($session, 'longitude', $location['longitude']);

            $user = $this->getUser($session);
            $user?->update([
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
            ]);

            $this->nextStep($session, self::STEP_BROWSE);
            $this->showBrowseResults($session);
            return;
        }

        // IMPROVED: Shorter error message
        $this->sendText($session->phone, "📍 Location share cheyyuka please!");
    }

    protected function handleBrowse(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        match ($selectionId) {
            'fish_filter' => $this->showFilterOptions($session),
            'fish_refresh' => $this->showBrowseResults($session),
            'fish_subscribe' => $this->redirectToSubscription($session),
            'back_to_menu', 'main_menu' => $this->goToMenu($session),
            default => $this->showBrowseResults($session),
        };
    }

    protected function handleFilter(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'clear_filter') {
            $this->setTempData($session, 'filter_fish_type', null);
            $this->nextStep($session, self::STEP_BROWSE);
            $this->showBrowseResults($session);
            return;
        }

        if ($selectionId && str_starts_with($selectionId, 'fish_')) {
            $fishType = FishType::findByListId($selectionId);
            if ($fishType) {
                $this->setTempData($session, 'filter_fish_type', $fishType->id);
                $this->nextStep($session, self::STEP_BROWSE);
                $this->showBrowseResults($session);
                return;
            }
        }

        $this->showFilterOptions($session);
    }

    protected function handleDetail(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'fish_back_browse') {
            $this->nextStep($session, self::STEP_BROWSE);
            $this->showBrowseResults($session);
            return;
        }

        $this->showBrowseResults($session);
    }

    protected function handleActionButton(IncomingMessage $message, ConversationSession $session): bool
    {
        $selectionId = $this->getSelectionId($message);
        if (!$selectionId) {
            return false;
        }

        // "I'm Coming" button
        if (preg_match('/^fish_coming_(\d+)_(\d+)$/', $selectionId, $matches)) {
            $this->handleComingAction($session, (int) $matches[1], (int) $matches[2]);
            return true;
        }

        // "Get Location" button
        if (preg_match('/^fish_location_(\d+)/', $selectionId, $matches)) {
            $this->handleLocationAction($session, (int) $matches[1]);
            return true;
        }

        // "Call Seller" button
        if (preg_match('/^fish_call_(\d+)/', $selectionId, $matches)) {
            $this->handleCallAction($session, (int) $matches[1]);
            return true;
        }

        return false;
    }

    /**
     * Handle "I'm Coming" action.
     * @srs-ref PM-018 - Interactive buttons
     * @srs-ref PM-019 - Live claim count social proof
     */
    protected function handleComingAction(ConversationSession $session, int $catchId, int $alertId = 0): void
    {
        $catch = $this->catchService->findById($catchId);

        if (!$catch || !$catch->is_active) {
            // IMPROVED: Short, friendly message
            $this->sendTextWithMenu($session->phone, "😕 Ee meen ippo available alla. Vere nokkoo!");
            return;
        }

        try {
            $user = $this->getUser($session);
            if ($user) {
                // Record the coming response (only takes catch and userId)
                $this->catchService->recordComingResponse($catch, $user->id);

                // Notify seller
                $latitude = $this->getTempData($session, 'latitude');
                $longitude = $this->getTempData($session, 'longitude');
                $this->notifySellerCustomerComing($catch, $user, $latitude, $longitude);
            }

            // IMPROVED: Shorter confirmation with social proof (PM-019)
            $comingCount = $catch->fresh()->customers_coming ?? 1;
            $socialProof = $comingCount > 1 ? "\n👥 {$comingCount} people coming!" : "";

            $this->sendButtons(
                $session->phone,
                "✅ *Seller ariyichittund!*{$socialProof}\n\n📍 {$catch->seller->business_name}",
                [
                    ['id' => "fish_location_{$catchId}", 'title' => '📍 Get Location'],
                    ['id' => "fish_call_{$catchId}", 'title' => '📞 Call Seller'],
                ],
                '🐟 ' . $catch->fishType->display_name
            );

        } catch (\InvalidArgumentException $e) {
            $this->sendText($session->phone, "ℹ️ {$e->getMessage()}");
        }
    }

    /**
     * Notify seller about incoming customer.
     * @srs-ref PM-021 - Notify seller when claim count > 10
     */
    protected function notifySellerCustomerComing(
        \App\Models\FishCatch $catch,
        \App\Models\User $customer,
        ?float $customerLat = null,
        ?float $customerLng = null
    ): void {
        $seller = $catch->seller;
        if (!$seller?->user?->phone) {
            return;
        }

        $catch->refresh();
        $comingCount = $catch->customers_coming ?? 1;

        // Calculate distance if available
        $distanceText = '';
        if ($customerLat && $customerLng && $catch->latitude && $catch->longitude) {
            $distance = $this->calculateDistance(
                (float) $customerLat, (float) $customerLng,
                (float) $catch->latitude, (float) $catch->longitude
            );
            $distanceText = " • " . ($distance < 1 ? round($distance * 1000) . "m" : round($distance, 1) . "km");
        }

        $fishName = $catch->fishType->display_name;

        // PM-021: High demand notification at 10+ claims
        if ($comingCount >= 10 && $comingCount % 5 === 0) {
            // Send special high-demand alert
            $this->sendButtons(
                $seller->user->phone,
                "🔥 *High Demand!*\n\n{$fishName} — 👥 {$comingCount} customers varunnu!\n\nStock update cheyyuka:",
                [
                    ['id' => 'status_available', 'title' => '✅ Available'],
                    ['id' => 'status_low_stock', 'title' => '⚠️ Low Stock'],
                    ['id' => 'status_sold_out', 'title' => '❌ Sold Out'],
                ],
                '🐟 Stock Update'
            );
        } else {
            // Regular notification
            $this->sendText(
                $seller->user->phone,
                "🏃 *Customer varunnu!*\n\n🐟 {$fishName}\n👤 {$customer->name}{$distanceText}\n👥 Total: {$comingCount} coming"
            );
        }

        Log::info('Seller notified', [
            'catch_id' => $catch->id,
            'coming_count' => $comingCount,
        ]);
    }

    protected function handleLocationAction(ConversationSession $session, int $catchId): void
    {
        $catch = $this->catchService->findById($catchId);

        if (!$catch) {
            $this->sendText($session->phone, '❌ Catch not found');
            return;
        }

        $this->sendLocation(
            $session->phone,
            (float) $catch->latitude,
            (float) $catch->longitude,
            $catch->seller->business_name,
            $catch->seller->location_name ?? ''
        );
    }

    protected function handleCallAction(ConversationSession $session, int $catchId): void
    {
        $catch = $this->catchService->findById($catchId);

        if (!$catch) {
            $this->sendText($session->phone, '❌ Catch not found');
            return;
        }

        $phone = $catch->seller->user->phone;
        // IMPROVED: Shorter message
        $this->sendText(
            $session->phone,
            "📞 *{$catch->seller->business_name}*\n📱 {$phone}"
        );
    }

    /**
     * Show browse results.
     */
    protected function showBrowseResults(ConversationSession $session): void
    {
        $latitude = $this->getTempData($session, 'latitude');
        $longitude = $this->getTempData($session, 'longitude');

        if (!$latitude || !$longitude) {
            $this->start($session);
            return;
        }

        $fishTypeId = $this->getTempData($session, 'filter_fish_type');
        $radiusKm = 10;

        $catches = $this->catchService->browseNearby(
            (float) $latitude,
            (float) $longitude,
            $radiusKm,
            $fishTypeId
        );

        if ($catches->isEmpty()) {
            // IMPROVED: Friendly empty state with action
            $this->sendButtons(
                $session->phone,
                "🐟 *Nearby Fish*\n\nIppo nearby fish illa 😕\n\nAlert ON cheyyaam — meen varunna neram ariyikkaam!",
                [
                    ['id' => 'fish_subscribe', 'title' => '🔔 Alert ON'],
                    ['id' => 'fish_refresh', 'title' => '🔄 Refresh'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ],
                '🐟 Pacha Meen'
            );
            return;
        }

        // IMPROVED: Compact list format
        $sections = [[
            'title' => "🐟 Available ({$catches->count()})",
            'rows' => $catches->take(10)->map(function ($catch) use ($latitude, $longitude) {
                $distance = $this->calculateDistance(
                    (float) $latitude, (float) $longitude,
                    (float) $catch->latitude, (float) $catch->longitude
                );
                $distKm = $distance < 1 ? round($distance * 1000) . "m" : round($distance, 1) . "km";
                $status = $catch->status->emoji();

                return [
                    'id' => "catch_{$catch->id}",
                    'title' => "{$catch->fishType->display_name} — ₹{$catch->price_per_kg}",
                    'description' => "{$catch->seller->business_name} • {$distKm} {$status}",
                ];
            })->toArray(),
        ]];

        $this->sendList(
            $session->phone,
            "🐟 *{$radiusKm}km-il ulla fresh fish:*",
            'Fish kaanuka',
            $sections,
            '🐟 Pacha Meen',
            "👆 Select for details"
        );
    }

    protected function showFilterOptions(ConversationSession $session): void
    {
        $this->nextStep($session, self::STEP_FILTER);
        $sections = FishType::getListSections();

        array_unshift($sections[0]['rows'], [
            'id' => 'clear_filter',
            'title' => '🐟 All Fish / എല്ലാ മീനും',
            'description' => 'Show all available',
        ]);

        $this->sendList(
            $session->phone,
            "🔍 *Filter by Fish Type*",
            'Select Fish',
            $sections
        );
    }

    protected function showCatchDetail(ConversationSession $session, int $catchId): void
    {
        $catch = $this->catchService->findById($catchId);

        if (!$catch || !$catch->is_active) {
            $this->sendText($session->phone, "😕 Ee meen ippo available alla.");
            return;
        }

        $latitude = (float) $this->getTempData($session, 'latitude');
        $longitude = (float) $this->getTempData($session, 'longitude');

        $distance = null;
        if ($latitude && $longitude && $catch->latitude && $catch->longitude) {
            $distance = $this->calculateDistance(
                $latitude, $longitude,
                (float) $catch->latitude, (float) $catch->longitude
            );
        }

        $this->nextStep($session, self::STEP_DETAIL);
        $this->setTempData($session, 'current_catch_id', $catchId);

        $distText = $distance ? ($distance < 1 ? round($distance * 1000) . "m" : round($distance, 1) . "km") : '';
        $comingCount = $catch->customers_coming ?? 0;
        $socialProof = $comingCount > 0 ? "\n👥 {$comingCount} people going" : "";

        // IMPROVED: Compact detail view with photo
        if ($catch->photo_url) {
            $this->sendImage(
                $session->phone,
                $catch->photo_url,
                "🐟 *{$catch->fishType->display_name}*\n💰 ₹{$catch->price_per_kg}/kg\n📍 {$catch->seller->business_name} • {$distText}\n{$catch->status->display()}{$socialProof}"
            );
        }

        $this->sendButtons(
            $session->phone,
            "Entha cheyyende?",
            [
                ['id' => "fish_coming_{$catchId}_0", 'title' => '🏃 Njaan varunnu'],
                ['id' => "fish_location_{$catchId}", 'title' => '📍 Location'],
                ['id' => "fish_call_{$catchId}", 'title' => '📞 Call'],
            ]
        );
    }

    protected function redirectToSubscription(ConversationSession $session): void
    {
        $this->sessionManager->setFlowStep($session, FlowType::FISH_SUBSCRIBE, 'start');
        app(FishSubscriptionFlowHandler::class)->start($session);
    }

    protected function extractCatchId(IncomingMessage $message): ?int
    {
        $id = $this->getSelectionId($message);
        if ($id && str_starts_with($id, 'catch_')) {
            return (int) str_replace('catch_', '', $id);
        }
        return null;
    }

    protected function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371;
        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);
        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}