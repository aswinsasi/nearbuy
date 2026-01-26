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
 * Supports multi-select fish types with category browsing.
 *
 * @srs-ref Pacha Meen Module - Customer Subscription Flow
 */
class FishSubscriptionFlowHandler extends AbstractFlowHandler
{
    protected const STEP_LOCATION = 'select_location';
    protected const STEP_RADIUS = 'set_radius';
    protected const STEP_FISH_TYPES = 'select_fish_types';
    protected const STEP_SELECT_CATEGORY = 'select_category';
    protected const STEP_SELECT_FISH_IN_CATEGORY = 'select_fish_in_category';
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
            self::STEP_SELECT_CATEGORY,
            self::STEP_SELECT_FISH_IN_CATEGORY,
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
            self::STEP_SELECT_CATEGORY => $this->handleCategorySelection($message, $session),
            self::STEP_SELECT_FISH_IN_CATEGORY => $this->handleFishInCategorySelection($message, $session),
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

        // Handle "All Fish Types" button
        if ($selectionId === 'fish_pref_all') {
            $this->setTemp($session, 'fish_type_ids', []);
            $this->setTemp($session, 'all_fish_types', true);
            $this->nextStep($session, self::STEP_FREQUENCY);
            $this->showFrequencyOptions($session);
            return;
        }

        // Handle "Select Specific Types" button - show categories
        if ($selectionId === 'fish_pref_select') {
            $this->nextStep($session, self::STEP_SELECT_CATEGORY);
            $this->showCategorySelection($session);
            return;
        }

        // Default: show the preference options
        $this->showFishTypeOptions($session);
    }

    /**
     * Handle category selection.
     */
    protected function handleCategorySelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = strtolower(trim($this->getTextContent($message) ?? ''));

        // Handle "Done" - proceed to frequency
        if ($selectionId === 'done_selecting' || $text === 'done') {
            $fishTypeIds = $this->getTemp($session, 'fish_type_ids', []);
            if (empty($fishTypeIds)) {
                $this->setTemp($session, 'all_fish_types', true);
            }
            $this->nextStep($session, self::STEP_FREQUENCY);
            $this->showFrequencyOptions($session);
            return;
        }

        // Handle "Select All Fish"
        if ($selectionId === 'select_all_fish') {
            $this->setTemp($session, 'fish_type_ids', []);
            $this->setTemp($session, 'all_fish_types', true);
            $this->nextStep($session, self::STEP_FREQUENCY);
            $this->showFrequencyOptions($session);
            return;
        }

        // Handle "Popular Fish" selection
        if ($selectionId === 'cat_popular') {
            $this->setTemp($session, 'current_category', '_popular');
            $this->nextStep($session, self::STEP_SELECT_FISH_IN_CATEGORY);
            $this->showFishInCategory($session, '_popular');
            return;
        }

        // Handle category selection (cat_xxx format)
        if ($selectionId && str_starts_with($selectionId, 'cat_')) {
            $categoryKey = str_replace('cat_', '', $selectionId);
            $category = $this->getCategoryByKey($categoryKey);
            
            if ($category) {
                $this->setTemp($session, 'current_category', $category);
                $this->nextStep($session, self::STEP_SELECT_FISH_IN_CATEGORY);
                $this->showFishInCategory($session, $category);
                return;
            }
        }

        // Default: show categories
        $this->showCategorySelection($session);
    }

    /**
     * Handle fish selection within a category (checkbox-style multi-select).
     */
    protected function handleFishInCategorySelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);
        $text = trim($this->getTextContent($message) ?? '');
        $category = $this->getTemp($session, 'current_category');

        // Handle "Back to Categories"
        if ($selectionId === 'back_to_categories') {
            $this->nextStep($session, self::STEP_SELECT_CATEGORY);
            $this->showCategorySelection($session);
            return;
        }

        // Handle "Done with this category"
        if ($selectionId === 'done_category') {
            $this->nextStep($session, self::STEP_SELECT_CATEGORY);
            $this->showCategorySelection($session);
            return;
        }

        // Handle "Finish Selection" - proceed to frequency
        if ($selectionId === 'finish_selection') {
            $fishTypeIds = $this->getTemp($session, 'fish_type_ids', []);
            if (empty($fishTypeIds)) {
                $this->setTemp($session, 'all_fish_types', true);
            }
            $this->nextStep($session, self::STEP_FREQUENCY);
            $this->showFrequencyOptions($session);
            return;
        }

        // Handle "Select All in Category"
        if ($selectionId === 'select_all_category') {
            $this->selectAllInCategory($session, $category);
            $this->showFishInCategory($session, $category);
            return;
        }

        // Handle "Clear Category"
        if ($selectionId === 'clear_category') {
            $this->clearCategorySelection($session, $category);
            $this->showFishInCategory($session, $category);
            return;
        }

        // Handle number-based toggle selection (e.g., "1", "1,3,5", "1 3 5")
        if ($text && preg_match('/^[\d,\s]+$/', $text)) {
            $this->handleNumberToggle($session, $text, $category);
            $this->showFishInCategory($session, $category);
            return;
        }

        // Handle individual fish toggle from list (fish_123 format)
        if ($selectionId && str_starts_with($selectionId, 'fish_')) {
            $fishId = (int) str_replace('fish_', '', $selectionId);
            $this->toggleFishSelection($session, $fishId);
            $this->showFishInCategory($session, $category);
            return;
        }

        // Default: show fish in category
        $this->showFishInCategory($session, $category);
    }

    protected function handleFrequency(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId) {
            // Use the enum's built-in parser
            $frequency = \App\Enums\FishAlertFrequency::fromListId($selectionId);
            
            if ($frequency) {
                $this->setTemp($session, 'frequency', $frequency->value);
                $this->nextStep($session, self::STEP_CONFIRM);
                $this->showConfirmation($session);
                return;
            }
        }

        $this->showFrequencyOptions($session);
    }

    protected function handleConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $selectionId = $this->getSelectionId($message);

        if ($selectionId === 'confirm_subscription') {
            $this->createSubscription($session);
            return;
        }

        if ($selectionId === 'edit_subscription') {
            // Go back to fish type selection
            $this->nextStep($session, self::STEP_SELECT_CATEGORY);
            $this->showCategorySelection($session);
            return;
        }

        if ($selectionId === 'cancel_subscription') {
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
                'latitude' => (float) $this->getTemp($session, 'latitude'),
                'longitude' => (float) $this->getTemp($session, 'longitude'),
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

    /*
    |--------------------------------------------------------------------------
    | Display Methods
    |--------------------------------------------------------------------------
    */

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

    /**
     * Show category selection list.
     */
    protected function showCategorySelection(ConversationSession $session): void
    {
        $categories = FishType::active()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->select('category')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->toArray();

        $selectedIds = $this->getTemp($session, 'fish_type_ids', []);
        $totalSelected = count($selectedIds);

        // Build category rows
        $rows = [];

        // Add "Popular Fish" option first
        $popularCount = FishType::active()->where('is_popular', true)->count();
        $selectedPopular = FishType::active()
            ->where('is_popular', true)
            ->whereIn('id', $selectedIds)
            ->count();
        
        $rows[] = [
            'id' => 'cat_popular',
            'title' => '⭐ Popular Fish',
            'description' => $selectedPopular > 0 
                ? "✅ {$selectedPopular}/{$popularCount} selected" 
                : "{$popularCount} varieties",
        ];

        // Add categories
        foreach (array_slice($categories, 0, 7) as $category) { // Limit to 7 categories + popular + done = 9
            $fishCount = FishType::active()->where('category', $category)->count();
            $selectedInCat = FishType::active()
                ->where('category', $category)
                ->whereIn('id', $selectedIds)
                ->count();
            
            $catKey = $this->getCategoryKey($category);
            
            $rows[] = [
                'id' => 'cat_' . $catKey,
                'title' => substr($category, 0, 24),
                'description' => $selectedInCat > 0 
                    ? "✅ {$selectedInCat}/{$fishCount} selected" 
                    : "{$fishCount} varieties",
            ];
        }

        // Add "Done" option
        $rows[] = [
            'id' => 'done_selecting',
            'title' => '✅ Done Selecting',
            'description' => $totalSelected > 0 
                ? "Continue with {$totalSelected} fish" 
                : 'Continue without specific fish',
        ];

        $bodyText = "📂 *Select a category* to choose fish types.\n\n";
        if ($totalSelected > 0) {
            $bodyText .= "✅ *{$totalSelected} fish selected so far*\n\n";
        }
        $bodyText .= "_Tap a category to see fish, or 'Done' to continue_";

        $response = [
            'type' => 'list',
            'header' => '🐟 Fish Categories',
            'body' => $bodyText,
            'button' => 'Select Category',
            'sections' => [
                [
                    'title' => 'Categories',
                    'rows' => $rows,
                ],
            ],
        ];

        $this->sendFishMessage($session->phone, $response);
    }

    /**
     * Show fish types in a category with checkbox-style display.
     */
    protected function showFishInCategory(ConversationSession $session, string $category): void
    {
        // Get fish in category
        if ($category === '_popular') {
            $fishTypes = FishType::active()
                ->where('is_popular', true)
                ->orderBy('sort_order')
                ->limit(15)
                ->get();
            $categoryTitle = '⭐ Popular Fish';
        } else {
            $fishTypes = FishType::active()
                ->where('category', $category)
                ->orderBy('sort_order')
                ->limit(15)
                ->get();
            $categoryTitle = $category;
        }

        $selectedIds = $this->getTemp($session, 'fish_type_ids', []);
        
        // Store fish map for number-based selection
        $fishMap = $fishTypes->pluck('id')->toArray();
        $this->setTemp($session, 'fish_map', $fishMap);

        // Build checkbox-style text list
        $lines = [];
        $lines[] = "🐟 *{$categoryTitle}*\n";
        $lines[] = "Type numbers to toggle selection:";
        $lines[] = "_Example: 1, 3, 5 or just 1_\n";

        $selectedInCategory = 0;
        foreach ($fishTypes as $index => $fish) {
            $num = $index + 1;
            $isSelected = in_array($fish->id, $selectedIds);
            $checkbox = $isSelected ? '✅' : '⬜';
            
            if ($isSelected) {
                $selectedInCategory++;
            }
            
            $price = $fish->price_range ? " • {$fish->price_range}" : '';
            $lines[] = "{$num}. {$checkbox} {$fish->display_name}{$price}";
        }

        $totalSelected = count($selectedIds);
        $lines[] = "\n━━━━━━━━━━━━━━━";
        $lines[] = "📊 *This category:* {$selectedInCategory}/{$fishTypes->count()} selected";
        $lines[] = "📊 *Total selected:* {$totalSelected} fish";

        // Send text message with fish list
        $this->sendText($session->phone, implode("\n", $lines));

        // Send action buttons
        $buttons = [
            ['id' => 'done_category', 'title' => '📂 More Categories'],
            ['id' => 'finish_selection', 'title' => '✅ Finish'],
        ];

        // Add select all / clear based on current state
        if ($selectedInCategory < $fishTypes->count()) {
            array_unshift($buttons, ['id' => 'select_all_category', 'title' => '☑️ Select All']);
        } else {
            array_unshift($buttons, ['id' => 'clear_category', 'title' => '🔲 Clear All']);
        }

        $this->whatsApp->sendButtons(
            $session->phone,
            "Type numbers (1, 3, 5) to toggle fish,\nor use buttons below:",
            array_slice($buttons, 0, 3), // WhatsApp max 3 buttons
            null,
            null
        );
    }

    protected function showFrequencyOptions(ConversationSession $session): void
    {
        $response = FishMessages::askAlertFrequency();
        $this->sendFishMessage($session->phone, $response);
    }

    protected function showConfirmation(ConversationSession $session): void
    {
        $radius = $this->getTemp($session, 'radius_km');
        $frequencyValue = $this->getTemp($session, 'frequency');
        $allFish = $this->getTemp($session, 'all_fish_types', false);
        $fishTypeIds = $this->getTemp($session, 'fish_type_ids', []);

        // Build fish display
        if ($allFish || empty($fishTypeIds)) {
            $fishDisplay = "🐟 All fish types";
        } else {
            // NEW - Get models first, then access accessor
            $fishTypes = FishType::whereIn('id', $fishTypeIds)->get();
            $fishNames = $fishTypes->map(fn($fish) => $fish->display_name)->toArray();
            
            $count = count($fishNames);
            if ($count <= 5) {
                $fishDisplay = "🐟 " . implode(", ", $fishNames);
            } else {
                $shown = array_slice($fishNames, 0, 5);
                $fishDisplay = "🐟 " . implode(", ", $shown) . "\n   _+" . ($count - 5) . " more types_";
            }
        }

        // Get frequency label from enum
        $frequency = \App\Enums\FishAlertFrequency::tryFrom($frequencyValue);
        $frequencyLabel = $frequency 
            ? $frequency->emoji() . ' ' . $frequency->label()
            : '🔔 ' . ucfirst(str_replace('_', ' ', $frequencyValue ?? 'immediate'));

        $body = "Please confirm your subscription:\n\n" .
            "📍 *Radius:* {$radius} km\n\n" .
            "{$fishDisplay}\n\n" .
            "{$frequencyLabel}\n\n" .
            "Ready to subscribe?";

        $this->whatsApp->sendButtons(
            $session->phone,
            $body,
            [
                ['id' => 'confirm_subscription', 'title' => '✅ Subscribe'],
                ['id' => 'edit_subscription', 'title' => '✏️ Edit Fish'],
                ['id' => 'cancel_subscription', 'title' => '❌ Cancel'],
            ],
            '📋 Confirm Subscription'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Toggle fish selection (add if not selected, remove if selected).
     */
    protected function toggleFishSelection(ConversationSession $session, int $fishId): void
    {
        $selectedIds = $this->getTemp($session, 'fish_type_ids', []);
        
        if (in_array($fishId, $selectedIds)) {
            // Remove
            $selectedIds = array_values(array_diff($selectedIds, [$fishId]));
        } else {
            // Add
            $selectedIds[] = $fishId;
        }
        
        $this->setTemp($session, 'fish_type_ids', array_unique($selectedIds));
        $this->setTemp($session, 'all_fish_types', false);
    }

    /**
     * Handle number-based toggle from text input.
     */
    protected function handleNumberToggle(ConversationSession $session, string $text, string $category): void
    {
        $fishMap = $this->getTemp($session, 'fish_map', []);
        
        if (empty($fishMap)) {
            return;
        }

        // Parse numbers from input (supports "1", "1,3,5", "1 3 5", "1, 3, 5")
        $numbers = array_filter(array_map('intval', preg_split('/[,\s]+/', $text)));
        
        foreach ($numbers as $num) {
            $index = $num - 1; // Convert to 0-based index
            if (isset($fishMap[$index])) {
                $this->toggleFishSelection($session, $fishMap[$index]);
            }
        }
    }

    /**
     * Select all fish in current category.
     */
    protected function selectAllInCategory(ConversationSession $session, string $category): void
    {
        if ($category === '_popular') {
            $fishIds = FishType::active()
                ->where('is_popular', true)
                ->pluck('id')
                ->toArray();
        } else {
            $fishIds = FishType::active()
                ->where('category', $category)
                ->pluck('id')
                ->toArray();
        }

        $selectedIds = $this->getTemp($session, 'fish_type_ids', []);
        $selectedIds = array_unique(array_merge($selectedIds, $fishIds));
        
        $this->setTemp($session, 'fish_type_ids', $selectedIds);
        $this->setTemp($session, 'all_fish_types', false);
    }

    /**
     * Clear all selections in current category.
     */
    protected function clearCategorySelection(ConversationSession $session, string $category): void
    {
        if ($category === '_popular') {
            $fishIds = FishType::active()
                ->where('is_popular', true)
                ->pluck('id')
                ->toArray();
        } else {
            $fishIds = FishType::active()
                ->where('category', $category)
                ->pluck('id')
                ->toArray();
        }

        $selectedIds = $this->getTemp($session, 'fish_type_ids', []);
        $selectedIds = array_values(array_diff($selectedIds, $fishIds));
        
        $this->setTemp($session, 'fish_type_ids', $selectedIds);
    }

    /**
     * Generate a short key for category name.
     */
    protected function getCategoryKey(string $category): string
    {
        return substr(md5($category), 0, 8);
    }

    /**
     * Get category name from key.
     */
    protected function getCategoryByKey(string $key): ?string
    {
        $categories = FishType::active()
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->select('category')
            ->distinct()
            ->pluck('category')
            ->toArray();

        foreach ($categories as $category) {
            if ($this->getCategoryKey($category) === $key) {
                return $category;
            }
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