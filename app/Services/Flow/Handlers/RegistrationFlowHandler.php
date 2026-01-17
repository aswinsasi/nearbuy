<?php

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\RegistrationStep;
use App\Models\ConversationSession;
use App\Services\Registration\RegistrationService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\RegistrationMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handles the user registration flow.
 *
 * Supports both customer and shop owner registration with
 * step-by-step data collection and validation.
 *
 * CUSTOMER FLOW:
 * 1. ask_type → 2. ask_name → 3. ask_location → 4. confirm → 5. complete
 *
 * SHOP OWNER FLOW:
 * 1. ask_type → 2. ask_name → 3. ask_location → 4. ask_shop_name →
 * 5. ask_shop_category → 6. ask_shop_location → 7. ask_notification_pref →
 * 8. confirm → 9. complete
 */
class RegistrationFlowHandler implements FlowHandlerInterface
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected RegistrationService $registrationService,
    ) {}

    /**
     * Get the flow name.
     */
    public function getName(): string
    {
        return FlowType::REGISTRATION->value;
    }

    /**
     * Check if this handler can process the given step.
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, RegistrationStep::values());
    }

    /**
     * Start the registration flow.
     */
    public function start(ConversationSession $session): void
    {
        // Check if already registered
        if ($this->registrationService->isRegistered($session->phone)) {
            $this->whatsApp->sendText(
                $session->phone,
                RegistrationMessages::ERROR_PHONE_EXISTS
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Clear any previous temp data
        $this->sessionManager->clearTempData($session);

        // Set to first step
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::REGISTRATION,
            RegistrationStep::ASK_TYPE->value
        );

        // Send welcome and ask user type
        $this->askUserType($session);
    }

    /**
     * Handle an incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = RegistrationStep::tryFrom($session->current_step);

        if (!$step) {
            Log::warning('Invalid registration step', ['step' => $session->current_step]);
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
     * Handle invalid input.
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $step = RegistrationStep::tryFrom($session->current_step);

        if (!$step) {
            $this->start($session);
            return;
        }

        // Send appropriate error and re-prompt
        match ($step) {
            RegistrationStep::ASK_TYPE => $this->askUserType($session, true),
            RegistrationStep::ASK_NAME => $this->askName($session, true),
            RegistrationStep::ASK_LOCATION => $this->askLocation($session, true),
            RegistrationStep::ASK_SHOP_NAME => $this->askShopName($session, true),
            RegistrationStep::ASK_SHOP_CATEGORY => $this->askShopCategory($session, true),
            RegistrationStep::ASK_SHOP_LOCATION => $this->askShopLocation($session, true),
            RegistrationStep::ASK_NOTIFICATION_PREF => $this->askNotificationPref($session, true),
            RegistrationStep::CONFIRM => $this->askConfirmation($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    /**
     * Handle user type selection (customer/shop).
     */
    protected function handleUserTypeSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = null;

        if ($message->isButtonReply()) {
            $selection = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (str_contains($text, 'customer') || $text === '1') {
                $selection = 'customer';
            } elseif (str_contains($text, 'shop') || str_contains($text, 'owner') || $text === '2') {
                $selection = 'shop';
            }
        }

        if (!in_array($selection, ['customer', 'shop'])) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Store user type
        $this->sessionManager->setTempData($session, 'user_type', $selection);

        Log::info('Registration: User type selected', [
            'phone' => $this->maskPhone($session->phone),
            'type' => $selection,
        ]);

        // Move to next step
        $this->sessionManager->setStep($session, RegistrationStep::ASK_NAME->value);
        $this->askName($session);
    }

    /**
     * Handle name input.
     */
    protected function handleNameInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $name = trim($message->text ?? '');

        if (!$this->registrationService->isValidName($name)) {
            $this->whatsApp->sendText($session->phone, RegistrationMessages::ERROR_INVALID_NAME);
            $this->askName($session);
            return;
        }

        // Store name
        $this->sessionManager->setTempData($session, 'name', $name);

        Log::info('Registration: Name entered', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Move to location step
        $this->sessionManager->setStep($session, RegistrationStep::ASK_LOCATION->value);
        $this->askLocation($session);
    }

    /**
     * Handle location input.
     */
    protected function handleLocationInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isLocation()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $coords = $message->getCoordinates();

        if (!$coords || !isset($coords['latitude']) || !isset($coords['longitude'])) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        // Store location
        $this->sessionManager->mergeTempData($session, [
            'latitude' => $coords['latitude'],
            'longitude' => $coords['longitude'],
            'address' => $message->location['address'] ?? null,
        ]);

        Log::info('Registration: Location saved', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Check if customer or shop owner
        $userType = $this->sessionManager->getTempData($session, 'user_type');

        if ($userType === 'shop') {
            // Continue to shop details
            $this->sessionManager->setStep($session, RegistrationStep::ASK_SHOP_NAME->value);
            $this->askShopName($session);
        } else {
            // Customer - go to confirmation
            $this->sessionManager->setStep($session, RegistrationStep::CONFIRM->value);
            $this->askConfirmation($session);
        }
    }

    /**
     * Handle shop name input.
     */
    protected function handleShopNameInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $shopName = trim($message->text ?? '');

        if (!$this->registrationService->isValidName($shopName)) {
            $this->whatsApp->sendText($session->phone, RegistrationMessages::ERROR_INVALID_SHOP_NAME);
            $this->askShopName($session);
            return;
        }

        // Store shop name
        $this->sessionManager->setTempData($session, 'shop_name', $shopName);

        Log::info('Registration: Shop name entered', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Move to category selection
        $this->sessionManager->setStep($session, RegistrationStep::ASK_SHOP_CATEGORY->value);
        $this->askShopCategory($session);
    }

    /**
     * Handle category selection.
     */
    protected function handleCategorySelection(IncomingMessage $message, ConversationSession $session): void
    {
        $category = null;

        if ($message->isListReply()) {
            $category = $message->getSelectionId();
        } elseif ($message->isText()) {
            // Try to match text to category
            $text = strtolower(trim($message->text ?? ''));
            $category = $this->matchCategory($text);
        }

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

        // Move to shop location
        $this->sessionManager->setStep($session, RegistrationStep::ASK_SHOP_LOCATION->value);
        $this->askShopLocation($session);
    }

    /**
     * Handle shop location input.
     */
    protected function handleShopLocationInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isLocation()) {
            // Check if user wants to use same location
            if ($message->isButtonReply() && $message->getSelectionId() === 'same_location') {
                $this->sessionManager->mergeTempData($session, [
                    'shop_latitude' => $this->sessionManager->getTempData($session, 'latitude'),
                    'shop_longitude' => $this->sessionManager->getTempData($session, 'longitude'),
                    'shop_address' => $this->sessionManager->getTempData($session, 'address'),
                ]);
            } else {
                $this->handleInvalidInput($message, $session);
                return;
            }
        } else {
            $coords = $message->getCoordinates();

            if (!$coords) {
                $this->handleInvalidInput($message, $session);
                return;
            }

            // Store shop location
            $this->sessionManager->mergeTempData($session, [
                'shop_latitude' => $coords['latitude'],
                'shop_longitude' => $coords['longitude'],
                'shop_address' => $message->location['address'] ?? null,
            ]);
        }

        Log::info('Registration: Shop location saved', [
            'phone' => $this->maskPhone($session->phone),
        ]);

        // Move to notification preferences
        $this->sessionManager->setStep($session, RegistrationStep::ASK_NOTIFICATION_PREF->value);
        $this->askNotificationPref($session);
    }

    /**
     * Handle notification preference selection.
     */
    protected function handleNotificationPrefSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $pref = null;

        if ($message->isListReply()) {
            $pref = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            $pref = $this->matchNotificationPref($text);
        }

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

        // Move to confirmation
        $this->sessionManager->setStep($session, RegistrationStep::CONFIRM->value);
        $this->askConfirmation($session);
    }

    /**
     * Handle confirmation response.
     */
    protected function handleConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (in_array($text, ['yes', 'confirm', 'ok', '1'])) {
                $action = 'confirm';
            } elseif (in_array($text, ['edit', 'change', '2'])) {
                $action = 'edit';
            } elseif (in_array($text, ['no', 'cancel', '3'])) {
                $action = 'cancel';
            }
        }

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
        // Already complete, show main menu
        $this->sessionManager->resetToMainMenu($session);

        // Trigger main menu handler
        $mainMenuHandler = app(MainMenuHandler::class);
        $mainMenuHandler->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Prompt Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Ask for user type.
     */
    protected function askUserType(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? RegistrationMessages::ERROR_SELECT_TYPE
            : RegistrationMessages::WELCOME_NEW_USER . "\n\n" . RegistrationMessages::ASK_USER_TYPE;

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            RegistrationMessages::getUserTypeButtons()
        );
    }

    /**
     * Ask for name.
     */
    protected function askName(ConversationSession $session, bool $isRetry = false): void
    {
        $userType = $this->sessionManager->getTempData($session, 'user_type');

        $message = $isRetry
            ? RegistrationMessages::ERROR_INVALID_NAME
            : ($userType === 'shop' ? RegistrationMessages::ASK_NAME_SHOP : RegistrationMessages::ASK_NAME);

        $this->whatsApp->sendText($session->phone, $message);
    }

    /**
     * Ask for location.
     */
    protected function askLocation(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? RegistrationMessages::ERROR_LOCATION_REQUIRED
            : RegistrationMessages::ASK_LOCATION;

        $this->whatsApp->requestLocation($session->phone, $message);
    }

    /**
     * Ask for shop name.
     */
    protected function askShopName(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? RegistrationMessages::ERROR_INVALID_SHOP_NAME
            : RegistrationMessages::ASK_SHOP_NAME;

        $this->whatsApp->sendText($session->phone, $message);
    }

    /**
     * Ask for shop category.
     */
    protected function askShopCategory(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? RegistrationMessages::ERROR_SELECT_CATEGORY . "\n\n" . RegistrationMessages::ASK_SHOP_CATEGORY
            : RegistrationMessages::ASK_SHOP_CATEGORY;

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            '📦 Select Category',
            RegistrationMessages::getCategorySections()
        );
    }

    /**
     * Ask for shop location.
     */
    protected function askShopLocation(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? RegistrationMessages::ERROR_LOCATION_REQUIRED
            : RegistrationMessages::ASK_SHOP_LOCATION;

        $this->whatsApp->requestLocation($session->phone, $message);
    }

    /**
     * Ask for notification preference.
     */
    protected function askNotificationPref(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? RegistrationMessages::ERROR_SELECT_NOTIFICATION . "\n\n" . RegistrationMessages::ASK_NOTIFICATION_PREF
            : RegistrationMessages::ASK_NOTIFICATION_PREF;

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            '🔔 Select Frequency',
            RegistrationMessages::getNotificationSections()
        );
    }

    /**
     * Ask for confirmation.
     */
    protected function askConfirmation(ConversationSession $session): void
    {
        $userType = $this->sessionManager->getTempData($session, 'user_type');
        $name = $this->sessionManager->getTempData($session, 'name');

        if ($userType === 'shop') {
            $shopName = $this->sessionManager->getTempData($session, 'shop_name');
            $category = $this->sessionManager->getTempData($session, 'shop_category');
            $notifPref = $this->sessionManager->getTempData($session, 'notification_frequency');

            $message = RegistrationMessages::format(RegistrationMessages::CONFIRM_SHOP, [
                'name' => $name,
                'shop_name' => $shopName,
                'category' => RegistrationMessages::getCategoryLabel($category),
                'notification_pref' => RegistrationMessages::getNotificationLabel($notifPref),
            ]);
        } else {
            $message = RegistrationMessages::format(RegistrationMessages::CONFIRM_CUSTOMER, [
                'name' => $name,
            ]);
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
     * Complete the registration.
     */
    protected function completeRegistration(ConversationSession $session): void
    {
        $userType = $this->sessionManager->getTempData($session, 'user_type');

        try {
            if ($userType === 'shop') {
                $user = $this->createShopOwner($session);
            } else {
                $user = $this->createCustomer($session);
            }

            // Link session to user
            $this->registrationService->linkSessionToUser($session, $user);

            // Clear temp data
            $this->sessionManager->clearTempData($session);

            // Send success message
            $this->sendCompletionMessage($session, $user, $userType);

            // Set to complete step
            $this->sessionManager->setStep($session, RegistrationStep::COMPLETE->value);

            Log::info('Registration completed', [
                'phone' => $this->maskPhone($session->phone),
                'user_id' => $user->id,
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
    protected function createCustomer(ConversationSession $session): \App\Models\User
    {
        return $this->registrationService->createCustomer([
            'phone' => $session->phone,
            'name' => $this->sessionManager->getTempData($session, 'name'),
            'latitude' => $this->sessionManager->getTempData($session, 'latitude'),
            'longitude' => $this->sessionManager->getTempData($session, 'longitude'),
            'address' => $this->sessionManager->getTempData($session, 'address'),
        ]);
    }

    /**
     * Create shop owner from session data.
     */
    protected function createShopOwner(ConversationSession $session): \App\Models\User
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
        ]);
    }

    /**
     * Send completion message.
     */
    protected function sendCompletionMessage(ConversationSession $session, $user, string $userType): void
    {
        if ($userType === 'shop') {
            $message = RegistrationMessages::format(RegistrationMessages::COMPLETE_SHOP, [
                'name' => $user->name,
                'shop_name' => $user->shop->shop_name,
            ]);
            $buttons = RegistrationMessages::getShopNextButtons();
        } else {
            $message = RegistrationMessages::format(RegistrationMessages::COMPLETE_CUSTOMER, [
                'name' => $user->name,
            ]);
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
            "🔄 Let's start over. Your previous answers have been cleared."
        );

        $this->start($session);
    }

    /**
     * Cancel registration.
     */
    protected function cancelRegistration(ConversationSession $session): void
    {
        $this->sessionManager->clearTempData($session);
        $this->sessionManager->resetToMainMenu($session);

        $this->whatsApp->sendText(
            $session->phone,
            "❌ Registration cancelled.\n\nYou can register anytime by typing 'register'."
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Match text input to category.
     */
    protected function matchCategory(string $text): ?string
    {
        $categories = [
            'grocery' => ['grocery', 'grocer', 'kirana', 'supermarket'],
            'electronics' => ['electronics', 'electronic', 'gadget'],
            'clothes' => ['clothes', 'clothing', 'fashion', 'garment', 'textile'],
            'medical' => ['medical', 'medicine', 'pharmacy', 'drug', 'chemist'],
            'furniture' => ['furniture', 'wood', 'sofa'],
            'mobile' => ['mobile', 'phone', 'cellphone'],
            'appliances' => ['appliance', 'appliances', 'electrical'],
            'hardware' => ['hardware', 'tools', 'building'],
            'restaurant' => ['restaurant', 'food', 'hotel', 'cafe'],
            'bakery' => ['bakery', 'bake', 'bread', 'cake', 'sweet'],
            'stationery' => ['stationery', 'book', 'office'],
            'beauty' => ['beauty', 'cosmetic', 'salon', 'parlor'],
            'automotive' => ['automotive', 'auto', 'car', 'vehicle', 'bike'],
            'jewelry' => ['jewelry', 'jewellery', 'gold', 'ornament'],
            'sports' => ['sports', 'sport', 'fitness', 'gym'],
            'other' => ['other', 'misc', 'general'],
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
     * Match text input to notification preference.
     */
    protected function matchNotificationPref(string $text): ?string
    {
        $prefs = [
            'immediate' => ['immediate', 'instant', 'now', 'always', '1'],
            '2hours' => ['2 hour', '2hour', 'two hour', 'every 2', '2'],
            'twice_daily' => ['twice', 'twice daily', 'morning evening', '3'],
            'daily' => ['daily', 'once', 'once daily', 'morning', '4'],
        ];

        foreach ($prefs as $pref => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $pref;
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