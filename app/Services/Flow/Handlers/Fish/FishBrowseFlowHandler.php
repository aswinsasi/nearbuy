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
 * Allows customers to:
 * - Browse catches near their location
 * - Filter by fish type
 * - View catch details
 * - Respond (I'm Coming, Get Location)
 *
 * @srs-ref Pacha Meen Module - Browse functionality
 */
class FishBrowseFlowHandler extends AbstractFlowHandler
{
    /**
     * Browse steps.
     */
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

    /**
     * Get the flow type.
     */
    protected function getFlowType(): FlowType
    {
        return FlowType::FISH_BROWSE;
    }

    /**
     * Get the available steps.
     */
    protected function getSteps(): array
    {
        return [
            self::STEP_LOCATION,
            self::STEP_BROWSE,
            self::STEP_FILTER,
            self::STEP_DETAIL,
        ];
    }

    /**
     * Start the flow.
     */
    public function start(ConversationSession $session): void
    {
        // Check if user has location saved
        $user = $this->getUser($session);

        if ($user && $user->latitude && $user->longitude) {
            // Use saved location
            $this->setTemp($session, 'latitude', $user->latitude);
            $this->setTemp($session, 'longitude', $user->longitude);
            $this->nextStep($session, self::STEP_BROWSE);

            $this->showBrowseResults($session);
            return;
        }

        // Ask for location
        $this->nextStep($session, self::STEP_LOCATION);

        $this->requestLocation(
            $session->phone,
            "🐟 *Browse Fresh Fish*\n\n" .
            "Share your location to see fresh fish available nearby.\n\n" .
            "Tap 📎 → *Location* to share."
        );
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

        $step = $session->current_step;

        Log::debug('FishBrowseFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
        ]);

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
     * Handle alert response from FlowRouter (intercepted button clicks).
     *
     * This is called directly by FlowRouter when a fish alert button is clicked
     * from any flow context (not just when user is in FISH_BROWSE flow).
     */
    public function handleAlertResponse(
        ConversationSession $session,
        int $catchId,
        string $action,
        int $alertId
    ): void {
        Log::info('Handling fish alert response', [
            'catch_id' => $catchId,
            'action' => $action,
            'alert_id' => $alertId,
            'phone' => $session->phone,
        ]);

        match ($action) {
            'coming' => $this->handleComingAction($session, $catchId, $alertId),
            'location' => $this->handleLocationAction($session, $catchId),
            default => $this->sendText($session->phone, '❌ Invalid action'),
        };
    }

    /**
     * Handle location sharing.
     */
    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $location = $this->getLocation($message);

        if ($location) {
            $this->setTemp($session, 'latitude', $location['latitude']);
            $this->setTemp($session, 'longitude', $location['longitude']);

            // Update user location if logged in
            $user = $this->getUser($session);
            if ($user) {
                $user->update([
                    'latitude' => $location['latitude'],
                    'longitude' => $location['longitude'],
                ]);
            }

            $this->nextStep($session, self::STEP_BROWSE);
            $this->showBrowseResults($session);
            return;
        }

        $this->sendTextWithMenu(
            $session->phone,
            "📍 Please share your location.\n\nTap 📎 → *Location*"
        );
    }

    /**
     * Handle browse actions.
     */
    protected function handleBrowse(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Check for filter button
        if ($selectionId === 'fish_filter') {
            $this->nextStep($session, self::STEP_FILTER);
            $this->showFilterOptions($session);
            return;
        }

        // Check for refresh button
        if ($selectionId === 'fish_refresh') {
            $this->showBrowseResults($session);
            return;
        }

        // Check for subscribe button - redirect to subscription flow
        if ($selectionId === 'fish_subscribe') {
            $this->sessionManager->setFlowStep($session, FlowType::FISH_SUBSCRIBE, 'start');
            app(FishSubscriptionFlowHandler::class)->start($session);
            return;
        }

        // Check for back to menu (handle both button IDs)
        if ($selectionId === 'back_to_menu' || $selectionId === 'main_menu') {
            $this->goToMainMenu($session);
            return;
        }

        $this->showBrowseResults($session);
    }

    /**
     * Handle filter selection.
     */
    protected function handleFilter(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Check for fish type selection
        if ($selectionId && str_starts_with($selectionId, 'fish_')) {
            $fishType = FishType::findByListId($selectionId);
            if ($fishType) {
                $this->setTemp($session, 'filter_fish_type', $fishType->id);
                $this->nextStep($session, self::STEP_BROWSE);
                $this->showBrowseResults($session);
                return;
            }
        }

        // Check for clear filter
        if ($selectionId === 'clear_filter') {
            $this->setTemp($session, 'filter_fish_type', null);
            $this->nextStep($session, self::STEP_BROWSE);
            $this->showBrowseResults($session);
            return;
        }

        $this->showFilterOptions($session);
    }

    /**
     * Handle detail view actions.
     */
    protected function handleDetail(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        // Check for back to browse
        if ($selectionId === 'fish_back_browse') {
            $this->nextStep($session, self::STEP_BROWSE);
            $this->showBrowseResults($session);
            return;
        }

        $this->showBrowseResults($session);
    }

    /**
     * Handle action buttons (Coming, Location, etc.).
     */
    protected function handleActionButton(IncomingMessage $message, ConversationSession $session): bool
    {
        $selectionId = $this->getSelectionId($message);

        if (!$selectionId) {
            return false;
        }

        // Handle "I'm Coming" button
        if (preg_match('/^fish_coming_(\d+)_(\d+)$/', $selectionId, $matches)) {
            $catchId = (int) $matches[1];
            $alertId = (int) $matches[2];
            $this->handleComingAction($session, $catchId, $alertId);
            return true;
        }

        // Handle "Get Location" button
        if (preg_match('/^fish_location_(\d+)/', $selectionId, $matches)) {
            $catchId = (int) $matches[1];
            $this->handleLocationAction($session, $catchId);
            return true;
        }

        // Handle "Call Seller" button
        if (preg_match('/^fish_call_(\d+)/', $selectionId, $matches)) {
            $catchId = (int) $matches[1];
            $this->handleCallAction($session, $catchId);
            return true;
        }

        return false;
    }

    /**
     * Handle "I'm Coming" action.
     */
    protected function handleComingAction(ConversationSession $session, int $catchId, int $alertId = 0): void
    {
        $catch = $this->catchService->findById($catchId);

        if (!$catch || !$catch->is_active) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ This catch is no longer available.\n\nBrowse for other options."
            );
            return;
        }

        try {
            $user = $this->getUser($session);
            if ($user) {
                $latitude = $this->getTemp($session, 'latitude');
                $longitude = $this->getTemp($session, 'longitude');

                // FIX: Cast lat/lng to float (can be null if not set)
                $this->catchService->recordComingResponse(
                    $catch,
                    $user->id,
                    $alertId ?: null,
                    null,
                    $latitude !== null ? (float) $latitude : null,
                    $longitude !== null ? (float) $longitude : null
                );

                // NEW: Notify the seller that a customer is coming!
                $this->notifySellerCustomerComing($catch, $user, $latitude, $longitude);
            }

            // Send confirmation to customer
            $response = FishMessages::comingConfirmation($catch);
            $this->sendFishMessage($session->phone, $response);

        } catch (\InvalidArgumentException $e) {
            $this->sendTextWithMenu($session->phone, "ℹ️ {$e->getMessage()}");
        }
    }

    /**
     * Notify seller that a customer is coming.
     */
    protected function notifySellerCustomerComing(
        \App\Models\FishCatch $catch,
        \App\Models\User $customer,
        ?float $customerLat = null,
        ?float $customerLng = null
    ): void {
        $seller = $catch->seller;
        if (!$seller || !$seller->user) {
            return;
        }

        $sellerPhone = $seller->user->phone;
        if (!$sellerPhone) {
            return;
        }

        // Calculate distance if we have customer location
        $distance = null;
        if ($customerLat && $customerLng && $catch->catch_latitude && $catch->catch_longitude) {
            $distance = $this->calculateDistance(
                (float) $customerLat,
                (float) $customerLng,
                (float) $catch->catch_latitude,
                (float) $catch->catch_longitude
            );
        }

        // Get updated coming count
        $catch->refresh();
        $comingCount = $catch->coming_count ?? 1;

        // Build notification message
        $response = FishMessages::sellerComingNotification($catch, $customer, $comingCount, $distance);
        $this->sendFishMessage($sellerPhone, $response);

        Log::info('Seller notified of customer coming', [
            'catch_id' => $catch->id,
            'seller_phone' => $this->maskPhone($sellerPhone),
            'customer_id' => $customer->id,
            'coming_count' => $comingCount,
        ]);
    }

    /**
     * Handle "Get Location" action.
     */
    protected function handleLocationAction(ConversationSession $session, int $catchId): void
    {
        $catch = $this->catchService->findById($catchId);

        if (!$catch) {
            $this->sendTextWithMenu($session->phone, '❌ Catch not found');
            return;
        }

        // Send seller location
        $response = FishMessages::sellerLocation($catch->seller);
        $this->sendFishMessage($session->phone, $response);
    }

    /**
     * Handle "Call Seller" action.
     */
    protected function handleCallAction(ConversationSession $session, int $catchId): void
    {
        $catch = $this->catchService->findById($catchId);

        if (!$catch) {
            $this->sendTextWithMenu($session->phone, '❌ Catch not found');
            return;
        }

        $phone = $catch->seller->user->formatted_phone ?? $catch->seller->user->phone;

        $this->sendTextWithMenu(
            $session->phone,
            "📞 *Contact Seller*\n\n" .
            "🏪 {$catch->seller->business_name}\n" .
            "📱 {$phone}\n\n" .
            "Tap the number to call."
        );
    }

    /**
     * Show browse results.
     */
    protected function showBrowseResults(ConversationSession $session): void
    {
        $latitude = $this->getTemp($session, 'latitude');
        $longitude = $this->getTemp($session, 'longitude');

        if (!$latitude || !$longitude) {
            $this->nextStep($session, self::STEP_LOCATION);
            $this->start($session);
            return;
        }

        $fishTypeId = $this->getTemp($session, 'filter_fish_type');
        $radiusKm = 10;

        $catches = $this->catchService->browseNearby(
            (float) $latitude,
            (float) $longitude,
            $radiusKm,
            $fishTypeId
        );

        // Record views
        foreach ($catches as $catch) {
            $this->catchService->recordView($catch);
        }

        $locationLabel = "within {$radiusKm} km";
        $response = FishMessages::browseResults($catches, $locationLabel);
        $this->sendFishMessage($session->phone, $response);
    }

    /**
     * Show filter options.
     */
    protected function showFilterOptions(ConversationSession $session): void
    {
        $sections = FishType::getListSections();

        // Add "All Fish" option
        array_unshift($sections[0]['rows'], [
            'id' => 'clear_filter',
            'title' => '🐟 All Fish Types',
            'description' => 'Show all available fish',
        ]);

        $currentFilter = $this->getTemp($session, 'filter_fish_type');
        $currentName = $currentFilter
            ? FishType::find($currentFilter)?->display_name
            : 'All fish';

        $this->sendList(
            $session->phone,
            "Select a fish type to filter:\n\nCurrently showing: {$currentName}",
            'Select Fish',
            $sections,
            '🔍 Filter by Fish'
        );
    }

    /**
     * Show catch detail.
     */
    protected function showCatchDetail(ConversationSession $session, int $catchId): void
    {
        $catch = $this->catchService->findById($catchId);

        if (!$catch || !$catch->is_active) {
            $this->sendTextWithMenu(
                $session->phone,
                "❌ This catch is no longer available."
            );
            return;
        }

        $latitude = (float) $this->getTemp($session, 'latitude');
        $longitude = (float) $this->getTemp($session, 'longitude');

        $distance = null;
        if ($latitude && $longitude && $catch->catch_latitude && $catch->catch_longitude) {
            $distance = $this->calculateDistance(
                $latitude,
                $longitude,
                (float) $catch->catch_latitude,
                (float) $catch->catch_longitude
            );
        }

        $this->nextStep($session, self::STEP_DETAIL);
        $this->setTemp($session, 'current_catch_id', $catchId);

        $response = FishMessages::catchDetail($catch, $distance);
        $this->sendFishMessage($session->phone, $response);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Send a message based on FishMessages response format.
     */
    protected function sendFishMessage(string $phone, array $response): void
    {
        $type = $response['type'] ?? 'text';

        // Handle image + buttons combo (send image first, then buttons)
        if (isset($response['image']) && !empty($response['image'])) {
            $this->sendImage(
                $phone,
                $response['image'],
                $response['body'] ?? null
            );
            
            // If there are also buttons, send them separately after the image
            if ($type === 'buttons' && !empty($response['buttons'])) {
                $header = $response['header'] ?? 'Fresh Catch';
                $this->sendButtons(
                    $phone,
                    "👆 *{$header}*\n\nഎന്ത് ചെയ്യണം? / What would you like to do?",
                    $response['buttons'],
                    null,
                    $response['footer'] ?? null
                );
            }
            return;
        }

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

            case 'location':
                // FIX: Cast lat/lng to float (they're strings from database)
                $this->sendLocation(
                    $phone,
                    (float) $response['latitude'],
                    (float) $response['longitude'],
                    $response['name'] ?? null,
                    $response['address'] ?? null
                );
                break;

            default:
                $this->sendText($phone, $response['text'] ?? 'Message sent.');
        }
    }

    /**
     * Extract catch ID from message.
     */
    protected function extractCatchId(IncomingMessage $message): ?int
    {
        $id = $this->getSelectionId($message);

        if ($id && str_starts_with($id, 'catch_')) {
            return (int) str_replace('catch_', '', $id);
        }

        return null;
    }

    /**
     * Calculate distance between two points.
     */
    protected function calculateDistance(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371; // km

        $latDiff = deg2rad($lat2 - $lat1);
        $lngDiff = deg2rad($lng2 - $lng1);

        $a = sin($latDiff / 2) * sin($latDiff / 2) +
            cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
            sin($lngDiff / 2) * sin($lngDiff / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }
}