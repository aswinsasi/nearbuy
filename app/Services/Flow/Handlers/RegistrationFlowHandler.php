<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\NotificationFrequency;
use App\Enums\RegistrationStep;
use App\Enums\ShopCategory;
use App\Models\ConversationSession;
use App\Services\Registration\RegistrationService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\Messages\RegistrationMessages;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Registration Flow Handler - Natural, conversational registration.
 *
 * CUSTOMER FLOW (3 steps, feels like chatting):
 * 1. "Hii! Welcome! Ninte peru entha?" → User types name
 * 2. "Thanks [Name]! Location share cheyyamo?" → User shares location
 * 3. "Perfect! Ningal aara?" → [Customer] [Shop Owner]
 *
 * Then:
 * - Customer → Complete! Show menu (FR-REG-07)
 * - Shop Owner → Continue to shop registration sub-flow
 *
 * SHOP OWNER SUB-FLOW (FR-SHOP-01 to FR-SHOP-05):
 * 4. "Shop details koodi tharamo?" → [Continue] [Pinne]
 * 5. "🏪 Shop name entha?" (FR-SHOP-01)
 * 6. Category list - 8 options (FR-SHOP-02)
 * 7. Shop location - DISTINCT from personal (FR-SHOP-03)
 * 8. Notification frequency - 4 options (FR-SHOP-04)
 * 9. Complete! Create linked records (FR-SHOP-05)
 *
 * @srs-ref FR-REG-01 to FR-REG-07
 * @srs-ref FR-SHOP-01 to FR-SHOP-05
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
        // Handle both RegistrationStep enum AND shop registration string steps
        return RegistrationStep::tryFrom($step) !== null
            || in_array($step, [
                'shop_continue_choice',
                'ask_shop_name',
                'ask_shop_category',
                'ask_shop_location',
                'ask_notification_pref',
            ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Entry Point
    |--------------------------------------------------------------------------
    */

    /**
     * Start registration flow.
     */
    public function start(ConversationSession $session): void
    {
        // FR-REG-01: Check if already registered
        if ($this->registrationService->isRegistered($session->phone)) {
            $user = $this->registrationService->findByPhone($session->phone);
            $this->whatsApp->sendText(
                $session->phone,
                RegistrationMessages::alreadyRegistered($user?->name ?? '')
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Clear any previous temp data
        $this->sessionManager->clearTempData($session);

        // Set flow and step
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::REGISTRATION,
            RegistrationStep::ASK_NAME->value
        );

        // Send welcome message asking for name
        $referrerPhone = $this->sessionManager->getTempData($session, 'referrer_phone');
        if ($referrerPhone) {
            $referrer = $this->registrationService->findByPhone($referrerPhone);
            if ($referrer) {
                $this->whatsApp->sendText(
                    $session->phone,
                    RegistrationMessages::welcomeReferred($referrer->name)
                );
                return;
            }
        }

        $this->whatsApp->sendText($session->phone, RegistrationMessages::welcome());

        Log::info('Registration started', ['phone' => $this->maskPhone($session->phone)]);
    }

    /*
    |--------------------------------------------------------------------------
    | Main Handler
    |--------------------------------------------------------------------------
    */

    /**
     * Handle incoming message during registration.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = $session->current_step;

        // First check RegistrationStep enum
        $regStep = RegistrationStep::tryFrom($step);
        if ($regStep) {
            match ($regStep) {
                RegistrationStep::ASK_NAME => $this->handleName($message, $session),
                RegistrationStep::ASK_LOCATION => $this->handleLocation($message, $session),
                RegistrationStep::ASK_TYPE => $this->handleType($message, $session),
                RegistrationStep::COMPLETE => $this->handleComplete($session),
            };
            return;
        }

        // Then handle shop registration string steps
        match ($step) {
            'shop_continue_choice' => $this->handleShopContinueChoice($message, $session),
            'ask_shop_name' => $this->handleShopName($message, $session),
            'ask_shop_category' => $this->handleShopCategory($message, $session),
            'ask_shop_location' => $this->handleShopLocation($message, $session),
            'ask_notification_pref' => $this->handleNotificationPref($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Handle invalid input for current step.
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $step = $session->current_step;

        $regStep = RegistrationStep::tryFrom($step);
        if ($regStep) {
            match ($regStep) {
                RegistrationStep::ASK_NAME => $this->promptName($session),
                RegistrationStep::ASK_LOCATION => $this->promptLocation($session),
                RegistrationStep::ASK_TYPE => $this->promptType($session),
                default => $this->start($session),
            };
            return;
        }

        // Shop registration steps
        match ($step) {
            'shop_continue_choice' => $this->promptShopContinue($session),
            'ask_shop_name' => $this->promptShopName($session),
            'ask_shop_category' => $this->promptShopCategory($session),
            'ask_shop_location' => $this->promptShopLocation($session),
            'ask_notification_pref' => $this->promptNotificationPref($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Name (FR-REG-03)
    |--------------------------------------------------------------------------
    */

    /**
     * Handle name input.
     */
    protected function handleName(IncomingMessage $message, ConversationSession $session): void
    {
        // Must be text
        if (!$message->isText()) {
            $this->whatsApp->sendText($session->phone, RegistrationMessages::askNameRetry());
            return;
        }

        $name = trim($message->text ?? '');

        // Validate name
        if (!$this->registrationService->isValidName($name)) {
            $this->whatsApp->sendText($session->phone, RegistrationMessages::invalidName());
            return;
        }

        // Store name
        $this->sessionManager->setTempData($session, 'name', $name);

        Log::info('Name collected', ['phone' => $this->maskPhone($session->phone)]);

        // Move to location step (FR-REG-04)
        $this->sessionManager->setStep($session, RegistrationStep::ASK_LOCATION->value);

        // Request location
        $this->whatsApp->requestLocation(
            $session->phone,
            RegistrationMessages::askLocation($name)
        );
    }

    /**
     * Prompt for name again.
     */
    protected function promptName(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, RegistrationMessages::askNameRetry());
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Location (FR-REG-04, FR-REG-05)
    |--------------------------------------------------------------------------
    */

    /**
     * Handle location input.
     */
    protected function handleLocation(IncomingMessage $message, ConversationSession $session): void
    {
        // Must be location
        if (!$message->isLocation()) {
            $this->whatsApp->sendText($session->phone, RegistrationMessages::expectedLocation());
            $this->promptLocation($session);
            return;
        }

        // FR-REG-05: Extract coordinates
        $coords = $message->getCoordinates();

        if (!$coords || !$this->registrationService->isValidCoordinates(
            $coords['latitude'] ?? 0,
            $coords['longitude'] ?? 0
        )) {
            $this->whatsApp->sendText($session->phone, RegistrationMessages::expectedLocation());
            return;
        }

        // Store location
        $this->sessionManager->mergeTempData($session, [
            'latitude' => $coords['latitude'],
            'longitude' => $coords['longitude'],
            'address' => $message->getLocationAddress() ?? $message->getLocationName(),
        ]);

        Log::info('Location collected', ['phone' => $this->maskPhone($session->phone)]);

        // Move to type selection (FR-REG-02)
        $this->sessionManager->setStep($session, RegistrationStep::ASK_TYPE->value);

        $name = $this->sessionManager->getTempData($session, 'name') ?? 'Friend';

        $this->whatsApp->sendButtons(
            $session->phone,
            RegistrationMessages::askType($name),
            RegistrationMessages::typeButtons()
        );
    }

    /**
     * Prompt for location again.
     */
    protected function promptLocation(ConversationSession $session): void
    {
        $this->whatsApp->requestLocation(
            $session->phone,
            RegistrationMessages::askLocationRetry()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: User Type (FR-REG-02)
    |--------------------------------------------------------------------------
    */

    /**
     * Handle user type selection.
     */
    protected function handleType(IncomingMessage $message, ConversationSession $session): void
    {
        $type = $this->extractUserType($message);

        if (!$type) {
            $this->promptType($session);
            return;
        }

        // Store type
        $this->sessionManager->setTempData($session, 'user_type', $type);

        Log::info('User type selected', [
            'phone' => $this->maskPhone($session->phone),
            'type' => $type,
        ]);

        if ($type === 'customer') {
            // Complete customer registration
            $this->completeCustomerRegistration($session);
        } else {
            // Shop owner - create base user first, then prompt for shop details
            $this->createBaseUserAndPromptShop($session);
        }
    }

    /**
     * Prompt for type selection again.
     */
    protected function promptType(ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $session->phone,
            RegistrationMessages::askTypeRetry(),
            RegistrationMessages::typeButtons()
        );
    }

    /**
     * Extract user type from message.
     */
    protected function extractUserType(IncomingMessage $message): ?string
    {
        // From button
        if ($message->isButtonReply()) {
            $id = $message->getSelectionId();
            if (in_array($id, ['customer', 'shop'])) {
                return $id;
            }
        }

        // From text (flexible matching)
        if ($message->isText()) {
            $text = mb_strtolower(trim($message->text ?? ''));

            // Customer keywords
            if (preg_match('/^(customer|1|c|buy|shopping|user)$/i', $text)) {
                return 'customer';
            }

            // Shop keywords
            if (preg_match('/^(shop|2|s|owner|business|store|seller|kadakar|kada)$/i', $text)) {
                return 'shop';
            }
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Customer Registration Complete (FR-REG-06, FR-REG-07)
    |--------------------------------------------------------------------------
    */

    /**
     * Complete customer registration.
     */
    protected function completeCustomerRegistration(ConversationSession $session): void
    {
        try {
            // FR-REG-06: Store registration data
            $user = $this->registrationService->createCustomer([
                'phone' => $session->phone,
                'name' => $this->sessionManager->getTempData($session, 'name'),
                'latitude' => $this->sessionManager->getTempData($session, 'latitude'),
                'longitude' => $this->sessionManager->getTempData($session, 'longitude'),
                'address' => $this->sessionManager->getTempData($session, 'address'),
                'referrer_phone' => $this->sessionManager->getTempData($session, 'referrer_phone'),
            ]);

            // Link session to user
            $this->registrationService->linkSessionToUser($session, $user);

            // Clear temp data
            $this->sessionManager->clearTempData($session);

            // Mark complete
            $this->sessionManager->setStep($session, RegistrationStep::COMPLETE->value);

            Log::info('Customer registration complete', ['user_id' => $user->id]);

            // FR-REG-07: Show success with menu options
            $this->whatsApp->sendButtons(
                $session->phone,
                RegistrationMessages::completeCustomer($user->name),
                RegistrationMessages::customerMenuButtons()
            );

        } catch (\Exception $e) {
            Log::error('Registration failed', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            $this->whatsApp->sendText(
                $session->phone,
                RegistrationMessages::registrationFailed()
            );
            $this->start($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Registration - Step 4: Continue/Skip Choice
    |--------------------------------------------------------------------------
    */

    /**
     * Create base user record and ask if they want to add shop details.
     */
    protected function createBaseUserAndPromptShop(ConversationSession $session): void
    {
        $name = $this->sessionManager->getTempData($session, 'name') ?? 'Friend';

        // First, create the customer record (can upgrade later)
        try {
            $user = $this->registrationService->createCustomer([
                'phone' => $session->phone,
                'name' => $name,
                'latitude' => $this->sessionManager->getTempData($session, 'latitude'),
                'longitude' => $this->sessionManager->getTempData($session, 'longitude'),
                'address' => $this->sessionManager->getTempData($session, 'address'),
                'referrer_phone' => $this->sessionManager->getTempData($session, 'referrer_phone'),
            ]);

            $this->registrationService->linkSessionToUser($session, $user);

            Log::info('Base user created for shop owner', ['user_id' => $user->id]);

        } catch (\Exception $e) {
            Log::error('Failed to create base user', ['error' => $e->getMessage()]);
            $this->whatsApp->sendText($session->phone, RegistrationMessages::registrationFailed());
            return;
        }

        // Ask if they want to add shop details now
        $this->whatsApp->sendButtons(
            $session->phone,
            RegistrationMessages::shopOwnerContinue($name),
            RegistrationMessages::shopContinueButtons()
        );

        // Update step to handle their choice
        $this->sessionManager->setStep($session, 'shop_continue_choice');
    }

    /**
     * Handle continue/skip choice for shop registration.
     */
    protected function handleShopContinueChoice(IncomingMessage $message, ConversationSession $session): void
    {
        $choice = null;

        if ($message->isButtonReply()) {
            $choice = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = mb_strtolower(trim($message->text ?? ''));
            if (in_array($text, ['continue', 'yes', '1', 'ok', 'athe', 'continue_shop'])) {
                $choice = 'continue_shop';
            } elseif (in_array($text, ['later', 'skip', 'no', '2', 'pinne'])) {
                $choice = 'later';
            }
        }

        if ($choice === 'continue_shop') {
            // Start shop registration - Step 5: Ask shop name
            $this->sessionManager->setStep($session, 'ask_shop_name');
            $this->whatsApp->sendText($session->phone, RegistrationMessages::askShopName());

        } elseif ($choice === 'later') {
            // Skip shop registration, go to main menu
            $name = $this->sessionManager->getTempData($session, 'name') ?? 'Friend';
            $this->whatsApp->sendButtons(
                $session->phone,
                RegistrationMessages::shopSkipped($name),
                [
                    ['id' => 'browse_offers', 'title' => '🛍️ Offers kaanuka'],
                    ['id' => 'main_menu', 'title' => '📋 Menu'],
                ]
            );
            $this->sessionManager->resetToMainMenu($session);

        } else {
            // Invalid input, re-prompt
            $this->promptShopContinue($session);
        }
    }

    /**
     * Prompt for shop continue choice.
     */
    protected function promptShopContinue(ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $session->phone,
            "👆 Button tap cheyyuka:",
            RegistrationMessages::shopContinueButtons()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Registration - Step 5: Shop Name (FR-SHOP-01)
    |--------------------------------------------------------------------------
    */

    /**
     * Handle shop name input.
     */
    protected function handleShopName(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->whatsApp->sendText($session->phone, "🏪 Shop-inte peru type cheyyuka.");
            return;
        }

        $shopName = trim($message->text ?? '');

        if (!$this->registrationService->isValidName($shopName)) {
            $this->whatsApp->sendText($session->phone, RegistrationMessages::invalidShopName());
            return;
        }

        // Store shop name
        $this->sessionManager->setTempData($session, 'shop_name', $shopName);

        Log::info('Shop name collected', ['shop_name' => $shopName]);

        // Move to category (FR-SHOP-02)
        $this->sessionManager->setStep($session, 'ask_shop_category');
        $this->whatsApp->sendList(
            $session->phone,
            RegistrationMessages::askShopCategory($shopName),
            '📦 Select Category',
            ShopCategory::toListSections()
        );
    }

    /**
     * Prompt for shop name.
     */
    protected function promptShopName(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, RegistrationMessages::askShopName());
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Registration - Step 6: Category (FR-SHOP-02)
    |--------------------------------------------------------------------------
    */

    /**
     * Handle category selection.
     */
    protected function handleShopCategory(IncomingMessage $message, ConversationSession $session): void
    {
        $categoryId = null;

        if ($message->isListReply()) {
            $categoryId = $message->getSelectionId();
        } elseif ($message->isText()) {
            $categoryId = $this->matchCategoryFromText($message->text ?? '');
        }

        if (!$categoryId || !ShopCategory::isValid($categoryId)) {
            $this->whatsApp->sendText($session->phone, RegistrationMessages::askCategoryRetry());
            $this->promptShopCategory($session);
            return;
        }

        // Store category
        $this->sessionManager->setTempData($session, 'shop_category', $categoryId);

        $category = ShopCategory::from($categoryId);
        Log::info('Shop category selected', ['category' => $categoryId]);

        // Move to shop location (FR-SHOP-03)
        $this->sessionManager->setStep($session, 'ask_shop_location');
        $this->whatsApp->sendButtons(
            $session->phone,
            RegistrationMessages::askShopLocation($category->displayWithIcon()),
            RegistrationMessages::shopLocationButtons()
        );
    }

    /**
     * Prompt for category selection.
     */
    protected function promptShopCategory(ConversationSession $session): void
    {
        $shopName = $this->sessionManager->getTempData($session, 'shop_name') ?? 'Shop';
        $this->whatsApp->sendList(
            $session->phone,
            RegistrationMessages::askShopCategory($shopName),
            '📦 Select Category',
            ShopCategory::toListSections()
        );
    }

    /**
     * Match category from text input.
     */
    protected function matchCategoryFromText(string $text): ?string
    {
        $text = mb_strtolower(trim($text));

        $mappings = [
            'grocery' => ['grocery', 'grocer', 'kirana', 'palacharakku', 'supermarket', 'vegetables', '1'],
            'electronics' => ['electronics', 'electronic', 'tv', 'laptop', 'gadget', '2'],
            'clothes' => ['clothes', 'clothing', 'fashion', 'textile', 'dress', 'vastram', '3'],
            'medical' => ['medical', 'medicine', 'pharmacy', 'drug', 'chemist', 'marunnu', '4'],
            'furniture' => ['furniture', 'sofa', 'table', 'chair', 'bed', '5'],
            'mobile' => ['mobile', 'phone', 'smartphone', '6'],
            'appliances' => ['appliance', 'appliances', 'ac', 'fridge', 'refrigerator', 'washing', '7'],
            'hardware' => ['hardware', 'tool', 'construction', 'cement', 'paint', '8'],
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

    /*
    |--------------------------------------------------------------------------
    | Shop Registration - Step 7: Shop Location (FR-SHOP-03)
    |--------------------------------------------------------------------------
    */

    /**
     * Handle shop location input.
     * IMPORTANT: This is SHOP location, distinct from personal location.
     */
    protected function handleShopLocation(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle "same location" or "different location" buttons
        if ($message->isButtonReply()) {
            $buttonId = $message->getSelectionId();

            if ($buttonId === 'same_location') {
                // Use personal location for shop
                $this->sessionManager->mergeTempData($session, [
                    'shop_latitude' => $this->sessionManager->getTempData($session, 'latitude'),
                    'shop_longitude' => $this->sessionManager->getTempData($session, 'longitude'),
                    'shop_address' => $this->sessionManager->getTempData($session, 'address'),
                ]);

                Log::info('Shop location same as personal');

                // Move to notification pref (FR-SHOP-04)
                $this->sessionManager->setStep($session, 'ask_notification_pref');
                $this->whatsApp->sendList(
                    $session->phone,
                    RegistrationMessages::askNotificationPref(),
                    '🔔 Select Frequency',
                    NotificationFrequency::toListSections()
                );
                return;
            }

            if ($buttonId === 'different_location') {
                // Request shop location
                $this->whatsApp->requestLocation(
                    $session->phone,
                    RegistrationMessages::askShopLocationDifferent()
                );
                return;
            }
        }

        // Handle actual location share
        if (!$message->isLocation()) {
            $this->whatsApp->sendText($session->phone, RegistrationMessages::askShopLocationRetry());
            return;
        }

        $coords = $message->getCoordinates();

        if (!$coords || !$this->registrationService->isValidCoordinates(
            $coords['latitude'] ?? 0,
            $coords['longitude'] ?? 0
        )) {
            $this->whatsApp->sendText($session->phone, "❌ Invalid location. Try again.");
            return;
        }

        // Store shop location
        $this->sessionManager->mergeTempData($session, [
            'shop_latitude' => $coords['latitude'],
            'shop_longitude' => $coords['longitude'],
            'shop_address' => $message->getLocationAddress() ?? $message->getLocationName(),
        ]);

        Log::info('Shop location collected');

        // Move to notification pref (FR-SHOP-04)
        $this->sessionManager->setStep($session, 'ask_notification_pref');
        $this->whatsApp->sendList(
            $session->phone,
            RegistrationMessages::askNotificationPref(),
            '🔔 Select Frequency',
            NotificationFrequency::toListSections()
        );
    }

    /**
     * Prompt for shop location.
     */
    protected function promptShopLocation(ConversationSession $session): void
    {
        $categoryId = $this->sessionManager->getTempData($session, 'shop_category');
        $category = $categoryId ? ShopCategory::tryFrom($categoryId) : null;
        $categoryLabel = $category?->displayWithIcon() ?? '✅';

        $this->whatsApp->sendButtons(
            $session->phone,
            RegistrationMessages::askShopLocation($categoryLabel),
            RegistrationMessages::shopLocationButtons()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Shop Registration - Step 8: Notification Preference (FR-SHOP-04)
    |--------------------------------------------------------------------------
    */

    /**
     * Handle notification preference selection.
     */
    protected function handleNotificationPref(IncomingMessage $message, ConversationSession $session): void
    {
        $prefId = null;

        if ($message->isListReply()) {
            $prefId = $message->getSelectionId();
        } elseif ($message->isText()) {
            $prefId = $this->matchNotificationFromText($message->text ?? '');
        }

        if (!$prefId || !NotificationFrequency::isValid($prefId)) {
            $this->whatsApp->sendText($session->phone, RegistrationMessages::askNotificationPrefRetry());
            $this->promptNotificationPref($session);
            return;
        }

        // Store notification preference
        $this->sessionManager->setTempData($session, 'notification_frequency', $prefId);

        Log::info('Notification pref selected', ['frequency' => $prefId]);

        // Complete shop registration (FR-SHOP-05)
        $this->completeShopRegistration($session);
    }

    /**
     * Prompt for notification preference.
     */
    protected function promptNotificationPref(ConversationSession $session): void
    {
        $this->whatsApp->sendList(
            $session->phone,
            RegistrationMessages::askNotificationPref(),
            '🔔 Select Frequency',
            NotificationFrequency::toListSections()
        );
    }

    /**
     * Match notification preference from text input.
     */
    protected function matchNotificationFromText(string $text): ?string
    {
        $text = mb_strtolower(trim($text));

        $mappings = [
            'immediate' => ['immediate', 'udan', 'instant', 'now', '1'],
            '2hours' => ['2hour', '2 hour', 'two hour', 'batch', 'recommended', '2'],
            'twice_daily' => ['twice', 'two time', '2 time', '9am 5pm', '3'],
            'daily' => ['daily', 'once', 'one time', 'morning', '4'],
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
    | Shop Registration - Complete (FR-SHOP-05)
    |--------------------------------------------------------------------------
    */

    /**
     * Complete shop registration - creates linked shop record.
     */
    protected function completeShopRegistration(ConversationSession $session): void
    {
        $name = $this->sessionManager->getTempData($session, 'name');
        $shopName = $this->sessionManager->getTempData($session, 'shop_name');

        try {
            // Get existing user
            $user = $this->registrationService->findByPhone($session->phone);

            if (!$user) {
                throw new \Exception('User not found');
            }

            // FR-SHOP-05: Create linked records in users AND shops tables
            $user = $this->registrationService->upgradeToShopOwner($user, [
                'shop_name' => $shopName,
                'shop_category' => $this->sessionManager->getTempData($session, 'shop_category'),
                'shop_latitude' => $this->sessionManager->getTempData($session, 'shop_latitude'),
                'shop_longitude' => $this->sessionManager->getTempData($session, 'shop_longitude'),
                'shop_address' => $this->sessionManager->getTempData($session, 'shop_address'),
                'notification_frequency' => $this->sessionManager->getTempData($session, 'notification_frequency'),
            ]);

            // Clear temp data
            $this->sessionManager->clearTempData($session);

            // Mark complete
            $this->sessionManager->setStep($session, RegistrationStep::COMPLETE->value);

            Log::info('Shop registration complete', [
                'user_id' => $user->id,
                'shop_name' => $shopName,
            ]);

            // Success message with next actions
            $this->whatsApp->sendButtons(
                $session->phone,
                RegistrationMessages::completeShop($name, $shopName),
                RegistrationMessages::shopMenuButtons()
            );

        } catch (\Exception $e) {
            Log::error('Shop registration failed', [
                'error' => $e->getMessage(),
                'phone' => $this->maskPhone($session->phone),
            ]);

            $this->whatsApp->sendText(
                $session->phone,
                "❌ Error: {$e->getMessage()}\n\nType *hi* to try again."
            );
        }
    }

    /**
     * Handle completion step.
     */
    protected function handleComplete(ConversationSession $session): void
    {
        // Already registered, go to main menu
        $this->sessionManager->resetToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Interface Methods
    |--------------------------------------------------------------------------
    */

    /**
     * {@inheritdoc}
     */
    public function getExpectedInputType(string $step): string
    {
        $regStep = RegistrationStep::tryFrom($step);

        if ($regStep) {
            return match ($regStep) {
                RegistrationStep::ASK_NAME => 'text',
                RegistrationStep::ASK_LOCATION => 'location',
                RegistrationStep::ASK_TYPE => 'button',
                RegistrationStep::COMPLETE => 'any',
            };
        }

        // Shop registration steps
        return match ($step) {
            'shop_continue_choice' => 'button',
            'ask_shop_name' => 'text',
            'ask_shop_category' => 'list',
            'ask_shop_location' => 'location',
            'ask_notification_pref' => 'list',
            default => 'text',
        };
    }

    /**
     * {@inheritdoc}
     */
    public function handleTimeout(ConversationSession $session): void
    {
        $name = $this->sessionManager->getTempData($session, 'name');
        $shopName = $this->sessionManager->getTempData($session, 'shop_name');

        if ($shopName) {
            $firstName = $name ? RegistrationMessages::firstName($name) : 'Friend';
            $this->whatsApp->sendText(
                $session->phone,
                "👋 Welcome back, *{$firstName}*!\n\n*{$shopName}* registration continue cheyyaam..."
            );
        } elseif ($name) {
            $this->whatsApp->sendText(
                $session->phone,
                "👋 Welcome back, *{$name}*!\n\nLet's continue..."
            );
        }

        // Re-prompt current step
        $step = $session->current_step;
        $regStep = RegistrationStep::tryFrom($step);

        if ($regStep) {
            match ($regStep) {
                RegistrationStep::ASK_NAME => $this->promptName($session),
                RegistrationStep::ASK_LOCATION => $this->promptLocation($session),
                RegistrationStep::ASK_TYPE => $this->promptType($session),
                default => $this->start($session),
            };
            return;
        }

        // Shop registration steps
        match ($step) {
            'shop_continue_choice' => $this->promptShopContinue($session),
            'ask_shop_name' => $this->promptShopName($session),
            'ask_shop_category' => $this->promptShopCategory($session),
            'ask_shop_location' => $this->promptShopLocation($session),
            'ask_notification_pref' => $this->promptNotificationPref($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Mask phone for logging.
     */
    protected function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 6) {
            return str_repeat('*', $len);
        }
        return substr($phone, 0, 3) . str_repeat('*', $len - 6) . substr($phone, -3);
    }
}