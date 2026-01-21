<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\RegistrationStep;
use App\Models\ConversationSession;
use App\Models\User;
use App\Services\Registration\RegistrationService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\RegistrationMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handles the user registration flow.
 *
 * VIRAL ADOPTION OPTIMIZATIONS:
 * - Progress indicators reduce abandonment (NFR-U-01: ≤5 interactions)
 * - Incomplete registration recovery
 * - Referral tracking for organic growth
 * - "Same location" shortcut for shop owners
 * - Flexible input matching (buttons + text)
 *
 * CUSTOMER FLOW (3 steps):
 * 1. ask_type → 2. ask_name → 3. ask_location → complete
 *
 * SHOP OWNER FLOW (5 steps):
 * 1. ask_type → 2. ask_name → 3. ask_location →
 * 4. ask_shop_name → 5. ask_shop_category →
 * 6. ask_shop_location → 7. ask_notification_pref → complete
 *
 * @see SRS Section 3.1 - User Registration Requirements
 */
class RegistrationFlowHandler implements FlowHandlerInterface
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected RegistrationService $registrationService,
    ) {}

    /**
     * {@inheritdoc}
     */
    public function getName(): string
    {
        return FlowType::REGISTRATION->value;
    }

    /**
     * {@inheritdoc}
     */
    public function canHandleStep(string $step): bool
    {
        return RegistrationStep::tryFrom($step) !== null;
    }

    /**
     * Start the registration flow.
     *
     * Handles:
     * - New users: Full registration flow
     * - Already registered: Redirect to main menu
     * - Incomplete: Offer to continue or restart
     */
    public function start(ConversationSession $session): void
    {
        // Check if already registered (FR-REG-01)
        if ($this->registrationService->isRegistered($session->phone)) {
            $this->whatsApp->sendText(
                $session->phone,
                RegistrationMessages::get('error_phone_exists', $this->getLanguage($session))
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Check for incomplete registration
        $tempData = $session->temp_data ?? [];
        if (!empty($tempData) && isset($tempData['user_type'])) {
            $this->offerContinueOrRestart($session, $tempData);
            return;
        }

        // Fresh start
        $this->sessionManager->clearTempData($session);
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::REGISTRATION,
            RegistrationStep::ASK_TYPE->value
        );

        // Track registration started
        Log::info('Registration started', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        $this->sendWelcomeMessage($session);
    }

    /**
     * Handle an incoming message during registration.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = RegistrationStep::tryFrom($session->current_step);

        if (!$step) {
            Log::warning('Invalid registration step', [
                'step' => $session->current_step,
                'phone' => $this->maskPhone($session->phone),
            ]);
            $this->start($session);
            return;
        }

        match ($step) {
            RegistrationStep::ASK_TYPE => $this->handleUserTypeSelection($message, $session),
            RegistrationStep::ASK_NAME => $this->handleNameInput($message, $session),
            RegistrationStep::ASK_LOCATION => $this->handleLocationInput($message, $session),
            RegistrationStep::ASK_SHOP_NAME => $this->handleShopNameInput($message, $session),
            RegistrationStep::ASK_SHOP_CATEGORY => $this->handleCategorySelection($message, $session),
            RegistrationStep::ASK_SHOP_LOCATION => $this->handleShopLocationInput($message, $session),
            RegistrationStep::ASK_NOTIFICATION_PREF => $this->handleNotificationPrefSelection($message, $session),
            RegistrationStep::CONFIRM => $this->handleConfirmation($message, $session),
            RegistrationStep::COMPLETE => $this->handleComplete($session),
        };
    }

    /**
     * Handle invalid input with helpful re-prompting.
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $step = RegistrationStep::tryFrom($session->current_step);

        if (!$step) {
            $this->start($session);
            return;
        }

        // Re-prompt for current step with error indication
        match ($step) {
            RegistrationStep::ASK_TYPE => $this->promptUserType($session, true),
            RegistrationStep::ASK_NAME => $this->promptName($session, true),
            RegistrationStep::ASK_LOCATION => $this->promptLocation($session, true),
            RegistrationStep::ASK_SHOP_NAME => $this->promptShopName($session, true),
            RegistrationStep::ASK_SHOP_CATEGORY => $this->promptShopCategory($session, true),
            RegistrationStep::ASK_SHOP_LOCATION => $this->promptShopLocation($session, true),
            RegistrationStep::ASK_NOTIFICATION_PREF => $this->promptNotificationPref($session, true),
            RegistrationStep::CONFIRM => $this->promptConfirmation($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    /**
     * Handle user type selection (FR-REG-02).
     */
    protected function handleUserTypeSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = $this->extractUserType($message);

        if (!$selection) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Store user type
        $this->sessionManager->setTempData($session, 'user_type', $selection);

        Log::info('Registration: User type selected', [
            'phone' => $this->maskPhone($session->phone),
            'type' => $selection,
        ]);

        // Move to name step
        $this->sessionManager->setStep($session, RegistrationStep::ASK_NAME->value);
        $this->promptName($session);
    }

    /**
     * Handle name input (FR-REG-03).
     */
    protected function handleNameInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $name = trim($message->text ?? '');

        if (!$this->registrationService->isValidName($name)) {
            $this->whatsApp->sendText(
                $session->phone,
                RegistrationMessages::get('error_invalid_name', $this->getLanguage($session))
            );
            $this->promptName($session);
            return;
        }

        // Store name
        $this->sessionManager->setTempData($session, 'name', $name);

        Log::info('Registration: Name entered', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Move to location step
        $this->sessionManager->setStep($session, RegistrationStep::ASK_LOCATION->value);
        $this->promptLocation($session);
    }

    /**
     * Handle location input (FR-REG-04, FR-REG-05).
     */
    protected function handleLocationInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isLocation()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $coords = $message->getCoordinates();

        if (!$coords || !isset($coords['latitude'], $coords['longitude'])) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Validate coordinates
        if (!$this->registrationService->isValidCoordinates($coords['latitude'], $coords['longitude'])) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Store location (FR-REG-05)
        $this->sessionManager->mergeTempData($session, [
            'latitude' => $coords['latitude'],
            'longitude' => $coords['longitude'],
            'address' => $message->location['address'] ?? $message->location['name'] ?? null,
        ]);

        Log::info('Registration: Location saved', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Branch based on user type
        $userType = $this->sessionManager->getTempData($session, 'user_type');

        if ($userType === 'shop') {
            // Continue to shop details
            $this->sessionManager->setStep($session, RegistrationStep::ASK_SHOP_NAME->value);
            $this->promptShopName($session);
        } else {
            // Customer complete - go directly to completion (skip confirmation for speed)
            $this->completeRegistration($session);
        }
    }

    /**
     * Handle shop name input (FR-SHOP-01).
     */
    protected function handleShopNameInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $shopName = trim($message->text ?? '');

        if (!$this->registrationService->isValidName($shopName)) {
            $this->whatsApp->sendText(
                $session->phone,
                RegistrationMessages::get('error_invalid_shop_name', $this->getLanguage($session))
            );
            $this->promptShopName($session);
            return;
        }

        // Store shop name
        $this->sessionManager->setTempData($session, 'shop_name', $shopName);

        Log::info('Registration: Shop name entered', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Move to category selection
        $this->sessionManager->setStep($session, RegistrationStep::ASK_SHOP_CATEGORY->value);
        $this->promptShopCategory($session);
    }

    /**
     * Handle category selection (FR-SHOP-02).
     */
    protected function handleCategorySelection(IncomingMessage $message, ConversationSession $session): void
    {
        $category = $this->extractCategory($message);

        if (!$category || !$this->registrationService->isValidCategory($category)) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Store category
        $this->sessionManager->setTempData($session, 'shop_category', $category);

        Log::info('Registration: Category selected', [
            'phone' => $this->maskPhone($session->phone),
            'category' => $category,
        ]);

        // Move to shop location - offer "same location" option
        $this->sessionManager->setStep($session, RegistrationStep::ASK_SHOP_LOCATION->value);
        $this->promptShopLocationWithOption($session);
    }

    /**
     * Handle shop location input (FR-SHOP-03).
     */
    protected function handleShopLocationInput(IncomingMessage $message, ConversationSession $session): void
    {
        // Check for "same location" button
        if ($message->isButtonReply()) {
            $buttonId = $message->getSelectionId();

            if ($buttonId === 'same_location') {
                // Use same coordinates as personal location
                $this->sessionManager->mergeTempData($session, [
                    'shop_latitude' => $this->sessionManager->getTempData($session, 'latitude'),
                    'shop_longitude' => $this->sessionManager->getTempData($session, 'longitude'),
                    'shop_address' => $this->sessionManager->getTempData($session, 'address'),
                ]);

                Log::info('Registration: Shop location same as personal', [
                    'phone' => $this->maskPhone($session->phone),
                ]);

                // Move to notification preferences
                $this->sessionManager->setStep($session, RegistrationStep::ASK_NOTIFICATION_PREF->value);
                $this->promptNotificationPref($session);
                return;
            }

            if ($buttonId === 'different') {
                // Request different location
                $this->promptShopLocation($session);
                return;
            }
        }

        // Handle actual location share
        if (!$message->isLocation()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $coords = $message->getCoordinates();

        if (!$coords || !$this->registrationService->isValidCoordinates(
            $coords['latitude'],
            $coords['longitude']
        )) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Store shop location
        $this->sessionManager->mergeTempData($session, [
            'shop_latitude' => $coords['latitude'],
            'shop_longitude' => $coords['longitude'],
            'shop_address' => $message->location['address'] ?? $message->location['name'] ?? null,
        ]);

        Log::info('Registration: Shop location saved', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Move to notification preferences
        $this->sessionManager->setStep($session, RegistrationStep::ASK_NOTIFICATION_PREF->value);
        $this->promptNotificationPref($session);
    }

    /**
     * Handle notification preference selection (FR-SHOP-04).
     */
    protected function handleNotificationPrefSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $pref = $this->extractNotificationPref($message);

        if (!$pref || !$this->registrationService->isValidNotificationFrequency($pref)) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Store preference
        $this->sessionManager->setTempData($session, 'notification_frequency', $pref);

        Log::info('Registration: Notification pref selected', [
            'phone' => $this->maskPhone($session->phone),
            'pref' => $pref,
        ]);

        // Complete registration (skip confirmation for speed)
        $this->completeRegistration($session);
    }

    /**
     * Handle confirmation response.
     */
    protected function handleConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $this->extractConfirmationAction($message);

        match ($action) {
            'confirm' => $this->completeRegistration($session),
            'edit' => $this->restartRegistration($session),
            'cancel' => $this->cancelRegistration($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    /**
     * Handle completion step.
     */
    protected function handleComplete(ConversationSession $session): void
    {
        // Already complete, redirect to main menu
        $this->sessionManager->resetToMainMenu($session);

        $mainMenuHandler = app(MainMenuHandler::class);
        $mainMenuHandler->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Prompt Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Send welcome message with referral awareness.
     */
    protected function sendWelcomeMessage(ConversationSession $session): void
    {
        $lang = $this->getLanguage($session);
        $referrerPhone = $this->sessionManager->getTempData($session, 'referrer_phone');

        if ($referrerPhone) {
            $referrer = $this->registrationService->findByPhone($referrerPhone);
            if ($referrer) {
                $userCount = $this->registrationService->getTotalUserCount();
                $message = RegistrationMessages::getFormatted('welcome_referred', [
                    'referrer_name' => $referrer->name,
                    'user_count' => number_format($userCount),
                ], $lang);

                $this->whatsApp->sendText($session->phone, $message);
                $this->promptUserType($session);
                return;
            }
        }

        // Standard welcome
        $this->whatsApp->sendButtons(
            $session->phone,
            RegistrationMessages::get('welcome_new', $lang) . "\n\n" .
            RegistrationMessages::get('ask_type', $lang),
            RegistrationMessages::getUserTypeButtons()
        );
    }

    /**
     * Offer to continue incomplete registration.
     */
    protected function offerContinueOrRestart(ConversationSession $session, array $tempData): void
    {
        $lastStep = $session->current_step ?? 'ask_type';
        $stepDescription = RegistrationMessages::getStepDescription($lastStep);

        $message = RegistrationMessages::getFormatted('welcome_back_incomplete', [
            'last_step' => $stepDescription,
        ], $this->getLanguage($session));

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            RegistrationMessages::getContinueButtons()
        );

        // Set a temporary step to handle the continue/restart choice
        $this->sessionManager->setTempData($session, 'awaiting_continue_choice', true);
    }

    /**
     * Prompt for user type (FR-REG-02).
     */
    protected function promptUserType(ConversationSession $session, bool $isRetry = false): void
    {
        $lang = $this->getLanguage($session);
        $message = $isRetry
            ? RegistrationMessages::get('error_select_type', $lang)
            : RegistrationMessages::get('ask_type', $lang);

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            RegistrationMessages::getUserTypeButtons()
        );
    }

    /**
     * Prompt for name.
     */
    protected function promptName(ConversationSession $session, bool $isRetry = false): void
    {
        $lang = $this->getLanguage($session);
        $userType = $this->sessionManager->getTempData($session, 'user_type');

        $key = ($userType === 'shop') ? 'ask_name_shop' : 'ask_name_customer';

        $this->whatsApp->sendText($session->phone, RegistrationMessages::get($key, $lang));
    }

    /**
     * Prompt for location (FR-REG-04).
     */
    protected function promptLocation(ConversationSession $session, bool $isRetry = false): void
    {
        $lang = $this->getLanguage($session);
        $userType = $this->sessionManager->getTempData($session, 'user_type');

        $key = ($userType === 'shop') ? 'ask_location_shop_owner' : 'ask_location_customer';
        $message = $isRetry
            ? RegistrationMessages::get('error_location_required', $lang)
            : RegistrationMessages::get($key, $lang);

        $this->whatsApp->requestLocation($session->phone, $message);
    }

    /**
     * Prompt for shop name (FR-SHOP-01).
     */
    protected function promptShopName(ConversationSession $session, bool $isRetry = false): void
    {
        $lang = $this->getLanguage($session);
        $this->whatsApp->sendText(
            $session->phone,
            RegistrationMessages::get('ask_shop_name', $lang)
        );
    }

    /**
     * Prompt for shop category (FR-SHOP-02).
     */
    protected function promptShopCategory(ConversationSession $session, bool $isRetry = false): void
    {
        $lang = $this->getLanguage($session);
        $message = $isRetry
            ? RegistrationMessages::get('error_select_category', $lang) . "\n\n" .
              RegistrationMessages::get('ask_shop_category', $lang)
            : RegistrationMessages::get('ask_shop_category', $lang);

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            '📦 Select Category',
            RegistrationMessages::getCategorySections()
        );
    }

    /**
     * Prompt for shop location with "same location" option.
     * This reduces friction for shop owners (FR-SHOP-03).
     */
    protected function promptShopLocationWithOption(ConversationSession $session): void
    {
        $lang = $this->getLanguage($session);

        $this->whatsApp->sendButtons(
            $session->phone,
            RegistrationMessages::get('ask_shop_location_same', $lang),
            RegistrationMessages::getShopLocationButtons()
        );
    }

    /**
     * Prompt for different shop location.
     */
    protected function promptShopLocation(ConversationSession $session, bool $isRetry = false): void
    {
        $lang = $this->getLanguage($session);
        $message = $isRetry
            ? RegistrationMessages::get('error_location_required', $lang)
            : RegistrationMessages::get('ask_shop_location', $lang);

        $this->whatsApp->requestLocation($session->phone, $message);
    }

    /**
     * Prompt for notification preference (FR-SHOP-04).
     */
    protected function promptNotificationPref(ConversationSession $session, bool $isRetry = false): void
    {
        $lang = $this->getLanguage($session);
        $message = $isRetry
            ? RegistrationMessages::get('error_select_notification', $lang) . "\n\n" .
              RegistrationMessages::get('ask_notification_pref', $lang)
            : RegistrationMessages::get('ask_notification_pref', $lang);

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            '🔔 Select Frequency',
            RegistrationMessages::getNotificationSections()
        );
    }

    /**
     * Prompt for confirmation.
     */
    protected function promptConfirmation(ConversationSession $session): void
    {
        $lang = $this->getLanguage($session);
        $userType = $this->sessionManager->getTempData($session, 'user_type');
        $name = $this->sessionManager->getTempData($session, 'name');

        if ($userType === 'shop') {
            $message = RegistrationMessages::getFormatted('confirm_shop', [
                'name' => $name,
                'shop_name' => $this->sessionManager->getTempData($session, 'shop_name'),
                'category' => RegistrationMessages::getCategoryLabel(
                    $this->sessionManager->getTempData($session, 'shop_category')
                ),
                'notification_pref' => RegistrationMessages::getNotificationLabel(
                    $this->sessionManager->getTempData($session, 'notification_frequency')
                ),
            ], $lang);
        } else {
            $message = RegistrationMessages::getFormatted('confirm_customer', [
                'name' => $name,
            ], $lang);
        }

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            RegistrationMessages::getConfirmButtons()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Completion Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Complete the registration (FR-REG-06, FR-SHOP-05).
     */
    protected function completeRegistration(ConversationSession $session): void
    {
        $userType = $this->sessionManager->getTempData($session, 'user_type');

        try {
            $user = ($userType === 'shop')
                ? $this->createShopOwner($session)
                : $this->createCustomer($session);

            // Link session to user
            $this->registrationService->linkSessionToUser($session, $user);

            // Clear temp data
            $this->sessionManager->clearTempData($session);

            // Mark as complete
            $this->sessionManager->setStep($session, RegistrationStep::COMPLETE->value);

            // Send success message with next actions (FR-REG-07)
            $this->sendCompletionMessage($session, $user, $userType);

            Log::info('Registration completed', [
                'user_id' => $user->id,
                'phone' => $this->maskPhone($session->phone),
                'type' => $userType,
            ]);

        } catch (\Exception $e) {
            Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            $this->whatsApp->sendText(
                $session->phone,
                "❌ Registration failed. Please try again.\n\nError: " . $e->getMessage()
            );

            $this->restartRegistration($session);
        }
    }

    /**
     * Create customer from session data.
     */
    protected function createCustomer(ConversationSession $session): User
    {
        return $this->registrationService->createCustomer([
            'phone' => $session->phone,
            'name' => $this->sessionManager->getTempData($session, 'name'),
            'latitude' => $this->sessionManager->getTempData($session, 'latitude'),
            'longitude' => $this->sessionManager->getTempData($session, 'longitude'),
            'address' => $this->sessionManager->getTempData($session, 'address'),
            'language' => $this->getLanguage($session),
            'referrer_phone' => $this->sessionManager->getTempData($session, 'referrer_phone'),
        ]);
    }

    /**
     * Create shop owner from session data.
     */
    protected function createShopOwner(ConversationSession $session): User
    {
        return $this->registrationService->createShopOwner([
            'phone' => $session->phone,
            'name' => $this->sessionManager->getTempData($session, 'name'),
            'latitude' => $this->sessionManager->getTempData($session, 'latitude'),
            'longitude' => $this->sessionManager->getTempData($session, 'longitude'),
            'address' => $this->sessionManager->getTempData($session, 'address'),
            'shop_name' => $this->sessionManager->getTempData($session, 'shop_name'),
            'shop_category' => $this->sessionManager->getTempData($session, 'shop_category'),
            'shop_latitude' => $this->sessionManager->getTempData($session, 'shop_latitude'),
            'shop_longitude' => $this->sessionManager->getTempData($session, 'shop_longitude'),
            'shop_address' => $this->sessionManager->getTempData($session, 'shop_address'),
            'notification_frequency' => $this->sessionManager->getTempData($session, 'notification_frequency'),
            'language' => $this->getLanguage($session),
            'referrer_phone' => $this->sessionManager->getTempData($session, 'referrer_phone'),
        ]);
    }

    /**
     * Send completion message with immediate action options.
     */
    protected function sendCompletionMessage(ConversationSession $session, User $user, string $userType): void
    {
        $lang = $this->getLanguage($session);

        if ($userType === 'shop') {
            $message = RegistrationMessages::getFormatted('complete_shop', [
                'name' => $user->name,
                'shop_name' => $user->shop->shop_name,
            ], $lang);
            $buttons = RegistrationMessages::getShopNextButtons();
        } else {
            $message = RegistrationMessages::getFormatted('complete_customer', [
                'name' => $user->name,
            ], $lang);
            $buttons = RegistrationMessages::getCustomerNextButtons();
        }

        $this->whatsApp->sendButtons($session->phone, $message, $buttons);
    }

    /**
     * Restart registration from beginning.
     */
    protected function restartRegistration(ConversationSession $session): void
    {
        $this->whatsApp->sendText(
            $session->phone,
            RegistrationMessages::get('restart_message', $this->getLanguage($session))
        );

        $this->sessionManager->clearTempData($session);
        $this->start($session);
    }

    /**
     * Cancel registration.
     */
    protected function cancelRegistration(ConversationSession $session): void
    {
        // Track abandonment for analytics
        $this->registrationService->trackIncompleteRegistration(
            $session->phone,
            $session->current_step,
            $session->temp_data ?? []
        );

        $this->sessionManager->clearTempData($session);
        $this->sessionManager->resetToMainMenu($session);

        $this->whatsApp->sendText(
            $session->phone,
            RegistrationMessages::get('cancel_message', $this->getLanguage($session))
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Input Extraction Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Extract user type from message.
     */
    protected function extractUserType(IncomingMessage $message): ?string
    {
        if ($message->isButtonReply()) {
            $id = $message->getSelectionId();
            if (in_array($id, ['customer', 'shop'])) {
                return $id;
            }
        }

        if ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));

            if (str_contains($text, 'customer') || $text === '1' || $text === 'c') {
                return 'customer';
            }

            if (str_contains($text, 'shop') || str_contains($text, 'owner') ||
                str_contains($text, 'business') || $text === '2' || $text === 's') {
                return 'shop';
            }
        }

        return null;
    }

    /**
     * Extract category from message.
     */
    protected function extractCategory(IncomingMessage $message): ?string
    {
        if ($message->isListReply()) {
            return $message->getSelectionId();
        }

        if ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            return $this->matchCategory($text);
        }

        return null;
    }

    /**
     * Extract notification preference from message.
     */
    protected function extractNotificationPref(IncomingMessage $message): ?string
    {
        if ($message->isListReply()) {
            return $message->getSelectionId();
        }

        if ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            return $this->matchNotificationPref($text);
        }

        return null;
    }

    /**
     * Extract confirmation action from message.
     */
    protected function extractConfirmationAction(IncomingMessage $message): ?string
    {
        if ($message->isButtonReply()) {
            return $message->getSelectionId();
        }

        if ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));

            if (in_array($text, ['yes', 'confirm', 'ok', 'y', '1', 'correct', 'right'])) {
                return 'confirm';
            }

            if (in_array($text, ['edit', 'change', 'modify', 'e', '2'])) {
                return 'edit';
            }

            if (in_array($text, ['no', 'cancel', 'stop', 'n', '3'])) {
                return 'cancel';
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
            'grocery' => ['grocery', 'grocer', 'kirana', 'supermarket', 'provision', 'general'],
            'electronics' => ['electronics', 'electronic', 'gadget', 'computer', 'laptop'],
            'clothes' => ['clothes', 'clothing', 'fashion', 'garment', 'textile', 'dress'],
            'medical' => ['medical', 'medicine', 'pharmacy', 'drug', 'chemist', 'health'],
            'furniture' => ['furniture', 'wood', 'sofa', 'bed', 'table', 'chair'],
            'mobile' => ['mobile', 'phone', 'cellphone', 'smartphone'],
            'appliances' => ['appliance', 'appliances', 'electrical', 'ac', 'fridge'],
            'hardware' => ['hardware', 'tools', 'building', 'plumbing', 'paint'],
            'restaurant' => ['restaurant', 'food', 'hotel', 'cafe', 'eatery', 'mess'],
            'bakery' => ['bakery', 'bake', 'bread', 'cake', 'sweet', 'confection'],
            'stationery' => ['stationery', 'book', 'office', 'paper', 'pen'],
            'beauty' => ['beauty', 'cosmetic', 'salon', 'parlor', 'makeup'],
            'automotive' => ['automotive', 'auto', 'car', 'vehicle', 'bike', 'garage'],
            'jewelry' => ['jewelry', 'jewellery', 'gold', 'ornament', 'silver'],
            'sports' => ['sports', 'sport', 'fitness', 'gym', 'exercise'],
            'other' => ['other', 'misc', 'different', 'else'],
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
     * Match text to notification preference.
     */
    protected function matchNotificationPref(string $text): ?string
    {
        $mappings = [
            'immediate' => ['immediate', 'instant', 'now', 'always', 'every', '1'],
            '2hours' => ['2 hour', '2hour', 'two hour', 'every 2', 'batch', '2'],
            'twice_daily' => ['twice', 'twice daily', 'morning evening', 'two times', '3'],
            'daily' => ['daily', 'once', 'once daily', 'morning only', 'one time', '4'],
        ];

        foreach ($mappings as $pref => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $pref;
                }
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Utility Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get user's preferred language.
     */
    protected function getLanguage(ConversationSession $session): string
    {
        return $this->sessionManager->getTempData($session, 'language') ?? 'en';
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