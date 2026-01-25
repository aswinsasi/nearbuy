<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\FishType;
use App\Services\Fish\FishSubscriptionService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\FishMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handler for fish alert subscription flow.
 *
 * @srs-ref Pacha Meen Module - Customer Subscription Flow
 */
class FishSubscriptionFlowHandler extends AbstractFlowHandler
{
    protected const STEP_LOCATION = 'select_location';
    protected const STEP_RADIUS = 'set_radius';
    protected const STEP_FISH_TYPES = 'select_fish_types';
    protected const STEP_FREQUENCY = 'set_frequency';
    protected const STEP_CONFIRM = 'confirm';

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
        protected FishSubscriptionService $subscriptionService
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::FISH_SUBSCRIBE;
    }

    protected function getSteps(): array
    {
        return [
            self::STEP_LOCATION,
            self::STEP_RADIUS,
            self::STEP_FISH_TYPES,
            self::STEP_FREQUENCY,
            self::STEP_CONFIRM,
        ];
    }

    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        // Check if user already has location
        if ($user && $user->latitude && $user->longitude) {
            $this->setTemp($session, 'latitude', $user->latitude);
            $this->setTemp($session, 'longitude', $user->longitude);
            $this->nextStep($session, self::STEP_RADIUS);
            $this->showRadiusOptions($session);
            return;
        }

        $this->nextStep($session, self::STEP_LOCATION);
        $response = FishMessages::subscriptionWelcome();
        $this->sendFishMessage($session->phone, $response);
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = $session->current_step;

        Log::debug('FishSubscriptionFlowHandler', [
            'step' => $step,
            'message_type' => $message->type,
        ]);

        match ($step) {
            self::STEP_LOCATION => $this->handleLocation($message, $session),
            self::STEP_RADIUS => $this->handleRadius($message, $session),
            self::STEP_FISH_TYPES => $this->handleFishTypes($message, $session),
            self::STEP_FREQUENCY => $this->handleFrequency($message, $session),
            self::STEP_CONFIRM => $this->handleConfirm($message, $session),
            default => $this->start($session),
        };
    }

    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $location = $this->getLocation($message);

        if ($location) {
            $this->setTemp($session, 'latitude', $location['latitude']);
            $this->setTemp($session, 'longitude', $location['longitude']);

            // Update user location
            $user = $this->getUser($session);
            if ($user) {
                $user->update([
                    'latitude' => $location['latitude'],
                    'longitude' => $location['longitude'],
                ]);
            }

            $this->nextStep($session, self::STEP_RADIUS);
            $this->showRadiusOptions($session);
            return;
        }

        $response = FishMessages::askSubscriptionLocation();
        $this->sendFishMessage($session->phone, $response);
    }

    protected function handleRadius(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        $radius = null;

        if ($selectionId && preg_match('/^radius_(\d+)$/', $selectionId, $matches)) {
            $radius = (int) $matches[1];
        } elseif ($text && is_numeric(trim($text))) {
            $radius = (int) trim($text);
        }

        if ($radius && $radius >= 1 && $radius <= 50) {
            $this->setTemp($session, 'radius_km', $radius);
            $this->nextStep($session, self::STEP_FISH_TYPES);
            $this->showFishTypeOptions($session);
            return;
        }

        $this->showRadiusOptions($session);
    }

    protected function handleFishTypes(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'all_fish') {
            $this->setTemp($session, 'fish_type_ids', []);
            $this->setTemp($session, 'all_fish_types', true);
            $this->nextStep($session, self::STEP_FREQUENCY);
            $this->showFrequencyOptions($session);
            return;
        }

        if ($selectionId && str_starts_with($selectionId, 'fish_')) {
            $fishType = FishType::findByListId($selectionId);
            if ($fishType) {
                $currentIds = $this->getTemp($session, 'fish_type_ids', []);
                $currentIds[] = $fishType->id;
                $this->setTemp($session, 'fish_type_ids', array_unique($currentIds));
            }
        }

        if ($selectionId === 'done_selecting') {
            $fishTypeIds = $this->getTemp($session, 'fish_type_ids', []);
            if (empty($fishTypeIds)) {
                $this->setTemp($session, 'all_fish_types', true);
            }
            $this->nextStep($session, self::STEP_FREQUENCY);
            $this->showFrequencyOptions($session);
            return;
        }

        $this->showFishTypeOptions($session);
    }

    protected function handleFrequency(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        $frequency = match ($selectionId) {
            'freq_instant' => 'instant',
            'freq_hourly' => 'hourly',
            'freq_daily' => 'daily',
            default => null,
        };

        if ($frequency) {
            $this->setTemp($session, 'frequency', $frequency);
            $this->nextStep($session, self::STEP_CONFIRM);
            $this->showConfirmation($session);
            return;
        }

        $this->showFrequencyOptions($session);
    }

    protected function handleConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'confirm_subscribe') {
            $this->createSubscription($session);
            return;
        }

        if ($selectionId === 'cancel_subscribe') {
            $this->clearTemp($session);
            $this->sendTextWithMenu($session->phone, "❌ Subscription cancelled.");
            $this->goToMainMenu($session);
            return;
        }

        $this->showConfirmation($session);
    }

    protected function createSubscription(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        try {
            $subscription = $this->subscriptionService->createSubscription($user, [
                'latitude' => $this->getTemp($session, 'latitude'),
                'longitude' => $this->getTemp($session, 'longitude'),
                'radius_km' => $this->getTemp($session, 'radius_km'),
                'fish_type_ids' => $this->getTemp($session, 'fish_type_ids', []),
                'all_fish_types' => $this->getTemp($session, 'all_fish_types', false),
                'alert_frequency' => $this->getTemp($session, 'frequency'),
            ]);

            $this->clearTemp($session);
            $response = FishMessages::subscriptionCreated($subscription);
            $this->sendFishMessage($session->phone, $response);
            $this->goToMainMenu($session);

        } catch (\Exception $e) {
            Log::error('Failed to create subscription', ['error' => $e->getMessage()]);
            $this->sendErrorWithOptions($session->phone, "❌ Failed to create subscription. Please try again.");
        }
    }

    protected function showRadiusOptions(ConversationSession $session): void
    {
        $response = FishMessages::askAlertRadius();
        $this->sendFishMessage($session->phone, $response);
    }

    protected function showFishTypeOptions(ConversationSession $session): void
    {
        $response = FishMessages::askFishPreferences();
        $this->sendFishMessage($session->phone, $response);
    }

    protected function showFrequencyOptions(ConversationSession $session): void
    {
        $response = FishMessages::askAlertFrequency();
        $this->sendFishMessage($session->phone, $response);
    }

    protected function showConfirmation(ConversationSession $session): void
    {
        $radius = $this->getTemp($session, 'radius_km');
        $frequency = $this->getTemp($session, 'frequency');
        $allFish = $this->getTemp($session, 'all_fish_types', false);
        $fishTypeIds = $this->getTemp($session, 'fish_type_ids', []);

        $fishNames = $allFish ? ['All fish types'] : FishType::whereIn('id', $fishTypeIds)->pluck('display_name')->toArray();

        $subData = [
            'radius_km' => $radius,
            'frequency' => $frequency,
            'fish_names' => $fishNames,
            'all_fish_types' => $allFish,
        ];

        $response = FishMessages::confirmSubscription($subData);
        $this->sendFishMessage($session->phone, $response);
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
