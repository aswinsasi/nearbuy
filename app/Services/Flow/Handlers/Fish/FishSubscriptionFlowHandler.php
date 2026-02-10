<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers\Fish;

use App\DTOs\IncomingMessage;
use App\Enums\FishAlertFrequency;
use App\Enums\FishSubscriptionStep;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\FishSubscription;
use App\Models\FishType;
use App\Services\Fish\FishSubscriptionService;
use App\Services\Flow\Handlers\AbstractFlowHandler;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Fish Subscription Flow Handler.
 *
 * Flow: Fish Types → Location → Radius → Time → Confirm
 *
 * SHORT bilingual messages (Malayalam + English).
 *
 * @srs-ref PM-011 to PM-014 Customer Subscription
 */
class FishSubscriptionFlowHandler extends AbstractFlowHandler
{
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
        return FishSubscriptionStep::values();
    }

    public function start(ConversationSession $session): void
    {
        // Check if already subscribed
        $user = $this->getUser($session);
        if ($user && $this->subscriptionService->hasActiveSubscription($user)) {
            $this->sendButtons(
                $session->phone,
                "🐟 *Already subscribed!*\n\nManage your alerts?",
                [
                    ['id' => 'fish_manage', 'title' => '⚙️ Manage Alerts'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        // Start: Ask fish types (PM-011)
        $this->nextStep($session, FishSubscriptionStep::ASK_FISH_TYPES->value);
        $this->askFishTypes($session);
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = FishSubscriptionStep::tryFrom($session->current_step);

        match ($step) {
            FishSubscriptionStep::ASK_FISH_TYPES => $this->handleFishTypes($message, $session),
            FishSubscriptionStep::ASK_LOCATION => $this->handleLocation($message, $session),
            FishSubscriptionStep::ASK_RADIUS => $this->handleRadius($message, $session),
            FishSubscriptionStep::ASK_TIME => $this->handleTime($message, $session),
            FishSubscriptionStep::CONFIRM => $this->handleConfirm($message, $session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Fish Types (PM-011)
    |--------------------------------------------------------------------------
    */

    protected function askFishTypes(ConversationSession $session): void
    {
        $popular = $this->subscriptionService->getPopularFishTypes(6);

        $rows = [
            ['id' => 'fish_all', 'title' => '🐟 All Fish', 'description' => 'Get alerts for all types'],
        ];

        foreach ($popular as $fish) {
            $rows[] = [
                'id' => 'fish_' . $fish->id,
                'title' => substr($fish->emoji . ' ' . $fish->name_en, 0, 24),
                'description' => $fish->name_ml,
            ];
        }

        $rows[] = ['id' => 'fish_more', 'title' => '📋 More Types...', 'description' => 'See all categories'];

        $this->sendList(
            $session->phone,
            "🐟 *Pacha Meen Alerts!*\n\nEthodhu meen vendathu?\nWhich fish do you want?",
            'Select Fish',
            [['title' => '🐟 Fish Types', 'rows' => array_slice($rows, 0, 10)]]
        );
    }

    protected function handleFishTypes(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        // All fish
        if ($selection === 'fish_all') {
            $this->setTempData($session, 'all_fish_types', true);
            $this->setTempData($session, 'fish_type_ids', []);
            $this->nextStep($session, FishSubscriptionStep::ASK_LOCATION->value);
            $this->askLocation($session);
            return;
        }

        // More types - show categories
        if ($selection === 'fish_more') {
            $this->showFishCategories($session);
            return;
        }

        // Category selection
        if ($selection && str_starts_with($selection, 'cat_')) {
            $category = str_replace('cat_', '', $selection);
            $this->showFishInCategory($session, $category);
            return;
        }

        // Done selecting
        if ($selection === 'done_fish') {
            $ids = $this->getTempData($session, 'fish_type_ids', []);
            if (empty($ids)) {
                $this->setTempData($session, 'all_fish_types', true);
            }
            $this->nextStep($session, FishSubscriptionStep::ASK_LOCATION->value);
            $this->askLocation($session);
            return;
        }

        // Individual fish toggle
        if ($selection && str_starts_with($selection, 'fish_')) {
            $fishId = (int) str_replace('fish_', '', $selection);
            if ($fishId > 0) {
                $this->toggleFish($session, $fishId);
                
                // Quick feedback and continue
                $ids = $this->getTempData($session, 'fish_type_ids', []);
                $count = count($ids);
                
                $this->sendButtons(
                    $session->phone,
                    "✅ {$count} fish selected.\n\nAdd more or continue?",
                    [
                        ['id' => 'fish_more', 'title' => '➕ Add More'],
                        ['id' => 'done_fish', 'title' => '✅ Continue'],
                    ]
                );
                return;
            }
        }

        // Default
        $this->askFishTypes($session);
    }

    protected function showFishCategories(ConversationSession $session): void
    {
        $selected = $this->getTempData($session, 'fish_type_ids', []);
        $count = count($selected);

        $rows = [
            ['id' => 'cat_sea_fish', 'title' => '🌊 Sea Fish', 'description' => 'Mathi, Ayala, Choora...'],
            ['id' => 'cat_freshwater', 'title' => '🏞️ Freshwater', 'description' => 'Karimeen, Tilapia...'],
            ['id' => 'cat_shellfish', 'title' => '🐚 Shellfish', 'description' => 'Mussels, Clams...'],
            ['id' => 'cat_crustacean', 'title' => '🦐 Prawns & Crabs', 'description' => 'Konju, Njandu...'],
            ['id' => 'done_fish', 'title' => '✅ Done', 'description' => $count > 0 ? "{$count} selected" : 'Continue'],
        ];

        $body = "📂 *Select category:*";
        if ($count > 0) {
            $body .= "\n\n✅ {$count} fish selected";
        }

        $this->sendList($session->phone, $body, 'Categories', [['title' => 'Categories', 'rows' => $rows]]);
    }

    protected function showFishInCategory(ConversationSession $session, string $category): void
    {
        $fishTypes = FishType::active()
            ->where('category', $category)
            ->orderBy('sort_order')
            ->limit(8)
            ->get();

        $selected = $this->getTempData($session, 'fish_type_ids', []);

        $rows = [];
        foreach ($fishTypes as $fish) {
            $isSelected = in_array($fish->id, $selected);
            $prefix = $isSelected ? '✅ ' : '';
            $rows[] = [
                'id' => 'fish_' . $fish->id,
                'title' => substr($prefix . $fish->name_en, 0, 24),
                'description' => $fish->name_ml,
            ];
        }

        $rows[] = ['id' => 'fish_more', 'title' => '⬅️ Back', 'description' => 'Other categories'];
        $rows[] = ['id' => 'done_fish', 'title' => '✅ Done', 'description' => 'Continue'];

        $this->sendList(
            $session->phone,
            "Tap to select/deselect:",
            'Select',
            [['title' => $category, 'rows' => array_slice($rows, 0, 10)]]
        );
    }

    protected function toggleFish(ConversationSession $session, int $fishId): void
    {
        $ids = $this->getTempData($session, 'fish_type_ids', []);
        
        if (in_array($fishId, $ids)) {
            $ids = array_values(array_diff($ids, [$fishId]));
        } else {
            $ids[] = $fishId;
        }
        
        $this->setTempData($session, 'fish_type_ids', array_unique($ids));
        $this->setTempData($session, 'all_fish_types', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Location (PM-012)
    |--------------------------------------------------------------------------
    */

    protected function askLocation(ConversationSession $session): void
    {
        // Check if user already has location
        $user = $this->getUser($session);
        if ($user?->latitude && $user?->longitude) {
            $this->setTempData($session, 'latitude', $user->latitude);
            $this->setTempData($session, 'longitude', $user->longitude);
            $this->nextStep($session, FishSubscriptionStep::ASK_RADIUS->value);
            $this->askRadius($session);
            return;
        }

        $this->sendButtons(
            $session->phone,
            "📍 *Location share cheyyuka*\n\nNearby sellers-nu alert kittaan.\n\n📎 → Location tap cheyyuka",
            [['id' => 'main_menu', 'title' => '🏠 Menu']]
        );
    }

    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        $location = $this->getLocation($message);

        if ($location) {
            $this->setTempData($session, 'latitude', $location['latitude']);
            $this->setTempData($session, 'longitude', $location['longitude']);

            // Save to user
            $user = $this->getUser($session);
            $user?->update([
                'latitude' => $location['latitude'],
                'longitude' => $location['longitude'],
            ]);

            $this->nextStep($session, FishSubscriptionStep::ASK_RADIUS->value);
            $this->askRadius($session);
            return;
        }

        $this->askLocation($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Radius (PM-013)
    |--------------------------------------------------------------------------
    */

    protected function askRadius(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "📏 *Evide vare alerts vendam?*\nHow far to search?",
            [
                ['id' => 'radius_2', 'title' => '2 km - Very Near'],
                ['id' => 'radius_5', 'title' => '5 km - Normal ✓'],
                ['id' => 'radius_10', 'title' => '10 km - Far'],
            ]
        );
    }

    protected function handleRadius(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);
        $text = $this->getTextContent($message);

        $radius = null;

        if ($selection && preg_match('/radius_(\d+)/', $selection, $m)) {
            $radius = (int) $m[1];
        } elseif ($text && is_numeric(trim($text))) {
            $radius = (int) trim($text);
        }

        if ($radius && $radius >= 1 && $radius <= 50) {
            $this->setTempData($session, 'radius_km', $radius);
            $this->nextStep($session, FishSubscriptionStep::ASK_TIME->value);
            $this->askTime($session);
            return;
        }

        $this->askRadius($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Time Preference (PM-014)
    |--------------------------------------------------------------------------
    */

    protected function askTime(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "⏰ *Eppozhaa alert vendam?*\nWhen do you want alerts?",
            [
                ['id' => 'freq_immediate', 'title' => '🔔 Anytime'],
                ['id' => 'freq_morning_only', 'title' => '🌅 Morning (6-8AM)'],
                ['id' => 'freq_twice_daily', 'title' => '☀️ Twice Daily'],
            ]
        );
    }

    protected function handleTime(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        $frequency = match ($selection) {
            'freq_immediate' => FishAlertFrequency::IMMEDIATE,
            'freq_morning_only' => FishAlertFrequency::MORNING_ONLY,
            'freq_twice_daily' => FishAlertFrequency::TWICE_DAILY,
            'freq_weekly_digest' => FishAlertFrequency::WEEKLY_DIGEST,
            default => null,
        };

        if ($frequency) {
            $this->setTempData($session, 'frequency', $frequency->value);
            $this->nextStep($session, FishSubscriptionStep::CONFIRM->value);
            $this->showConfirmation($session);
            return;
        }

        $this->askTime($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 5: Confirm
    |--------------------------------------------------------------------------
    */

    protected function showConfirmation(ConversationSession $session): void
    {
        $allFish = $this->getTempData($session, 'all_fish_types', false);
        $fishIds = $this->getTempData($session, 'fish_type_ids', []);
        $radius = $this->getTempData($session, 'radius_km', 5);
        $freq = $this->getTempData($session, 'frequency', 'immediate');

        // Fish display
        if ($allFish || empty($fishIds)) {
            $fishText = '🐟 All fish';
        } else {
            $count = count($fishIds);
            $fishText = "🐟 {$count} types";
        }

        // Frequency display
        $freqEnum = FishAlertFrequency::tryFrom($freq);
        $freqText = $freqEnum ? $freqEnum->emoji() . ' ' . $freqEnum->label() : '🔔 Anytime';

        $this->sendButtons(
            $session->phone,
            "📋 *Confirm Subscription:*\n\n" .
                "{$fishText}\n" .
                "📏 {$radius} km radius\n" .
                "{$freqText}\n\n" .
                "Subscribe cheyyano?",
            [
                ['id' => 'confirm_yes', 'title' => '✅ Subscribe'],
                ['id' => 'confirm_edit', 'title' => '✏️ Edit'],
                ['id' => 'confirm_cancel', 'title' => '❌ Cancel'],
            ]
        );
    }

    protected function handleConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->getSelectionId($message);

        if ($selection === 'confirm_yes') {
            $this->createSubscription($session);
            return;
        }

        if ($selection === 'confirm_edit') {
            $this->nextStep($session, FishSubscriptionStep::ASK_FISH_TYPES->value);
            $this->askFishTypes($session);
            return;
        }

        if ($selection === 'confirm_cancel') {
            $this->clearTempData($session);
            $this->sendText($session->phone, "❌ Cancelled");
            $this->goToMenu($session);
            return;
        }

        $this->showConfirmation($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Create Subscription
    |--------------------------------------------------------------------------
    */

    protected function createSubscription(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        try {
            $subscription = $this->subscriptionService->createSubscription($user, [
                'latitude' => (float) $this->getTempData($session, 'latitude'),
                'longitude' => (float) $this->getTempData($session, 'longitude'),
                'radius_km' => $this->getTempData($session, 'radius_km', 5),
                'fish_type_ids' => $this->getTempData($session, 'fish_type_ids', []),
                'all_fish_types' => $this->getTempData($session, 'all_fish_types', false),
                'alert_frequency' => $this->getTempData($session, 'frequency', 'immediate'),
            ]);

            $this->clearTempData($session);

            // Success message
            $this->sendButtons(
                $session->phone,
                "✅ *Subscribed!* 🐟🔔\n\n" .
                    "{$subscription->fish_types_display}\n" .
                    "📏 {$subscription->radius_km} km\n" .
                    "{$subscription->alert_frequency->emoji()} {$subscription->frequency_display}\n\n" .
                    "Meen varumbol ariyikkaam!",
                [
                    ['id' => 'fish_browse', 'title' => '🔍 Browse Now'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );

        } catch (\Exception $e) {
            Log::error('Subscription failed', ['error' => $e->getMessage()]);
            $this->sendButtons(
                $session->phone,
                "❌ Error. Try again.",
                [['id' => 'fish_subscribe', 'title' => '🔄 Retry']]
            );
        }
    }
}