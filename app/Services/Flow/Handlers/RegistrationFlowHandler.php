<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Enums\RegistrationStep;
use App\Models\ConversationSession;
use App\Services\Registration\RegistrationService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\Messages\RegistrationMessages;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Support\Facades\Log;

/**
 * Registration Flow Handler - Natural, conversational registration.
 *
 * FLOW (3 steps, feels like chatting):
 * 1. "Hii! Welcome! Ninte peru entha?" → User types name
 * 2. "Thanks [Name]! Location share cheyyamo?" → User shares location
 * 3. "Perfect! Ningal aara?" → [Customer] [Shop Owner]
 *
 * Then:
 * - Customer → Complete! Show menu (FR-REG-07)
 * - Shop Owner → Continue to ShopRegistrationFlow
 *
 * EDGE CASES:
 * - User sends image instead of name → Re-prompt kindly
 * - User sends text instead of location → Re-prompt with help
 * - User already registered → Redirect to menu
 * - Invalid name (too short) → Ask again
 *
 * @srs-ref FR-REG-01 to FR-REG-07
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
        $step = RegistrationStep::tryFrom($session->current_step);

        if (!$step) {
            $this->start($session);
            return;
        }

        match ($step) {
            RegistrationStep::ASK_NAME => $this->handleName($message, $session),
            RegistrationStep::ASK_LOCATION => $this->handleLocation($message, $session),
            RegistrationStep::ASK_TYPE => $this->handleType($message, $session),
            RegistrationStep::COMPLETE => $this->handleComplete($session),
        };
    }

    /**
     * Handle invalid input for current step.
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $step = RegistrationStep::tryFrom($session->current_step);

        match ($step) {
            RegistrationStep::ASK_NAME => $this->promptName($session),
            RegistrationStep::ASK_LOCATION => $this->promptLocation($session),
            RegistrationStep::ASK_TYPE => $this->promptType($session),
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
            // Shop owner - ask if they want to continue
            $this->promptShopContinue($session);
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
    | Completion
    |--------------------------------------------------------------------------
    */

    /**
     * Complete customer registration (FR-REG-06, FR-REG-07).
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
                "❌ Error occurred. Please try again.\n\nType *hi* to restart."
            );
            $this->start($session);
        }
    }

    /**
     * Ask shop owner if they want to continue with shop registration.
     */
    protected function promptShopContinue(ConversationSession $session): void
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

        } catch (\Exception $e) {
            Log::error('Failed to create base user', ['error' => $e->getMessage()]);
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
     * Handle completion step.
     */
    protected function handleComplete(ConversationSession $session): void
    {
        // Already registered, go to main menu
        $this->sessionManager->resetToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

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

        return match ($regStep) {
            RegistrationStep::ASK_NAME => 'text',
            RegistrationStep::ASK_LOCATION => 'location',
            RegistrationStep::ASK_TYPE => 'button',
            RegistrationStep::COMPLETE => 'any',
            default => 'text',
        };
    }

    /**
     * {@inheritdoc}
     */
    public function handleTimeout(ConversationSession $session): void
    {
        // Check if we have partial data
        $name = $this->sessionManager->getTempData($session, 'name');

        if ($name) {
            // Resume from where they left off
            $this->whatsApp->sendText(
                $session->phone,
                "👋 Welcome back, *{$name}*!\n\nLet's continue..."
            );

            // Re-prompt current step directly
            $step = RegistrationStep::tryFrom($session->current_step);

            match ($step) {
                RegistrationStep::ASK_NAME => $this->promptName($session),
                RegistrationStep::ASK_LOCATION => $this->promptLocation($session),
                RegistrationStep::ASK_TYPE => $this->promptType($session),
                default => $this->start($session),
            };
        } else {
            // Start fresh
            $this->start($session);
        }
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