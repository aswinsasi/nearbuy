<?php

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\User;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\ErrorTemplate;
use App\Services\WhatsApp\Messages\MessageTemplates;
use Illuminate\Support\Facades\Log;

/**
 * Abstract base class for flow handlers.
 *
 * ENHANCED VERSION - Key improvements:
 * 1. sendWithMenu() - Auto-adds Main Menu button to every response
 * 2. sendTextWithMenu() - Text message with menu button option
 * 3. sendErrorWithButtons() - Interactive error handling
 * 4. Consistent footer on all messages
 * 5. Better input validation with button prompts
 */
abstract class AbstractFlowHandler implements FlowHandlerInterface
{
    /**
     * Default footer added to all messages
     */
    protected const DEFAULT_FOOTER = MessageTemplates::GLOBAL_FOOTER;

    /**
     * Main menu button definition
     */
    protected const MENU_BUTTON = ['id' => 'main_menu', 'title' => '🏠 Menu'];

    /**
     * Cancel button definition
     */
    protected const CANCEL_BUTTON = ['id' => 'cancel', 'title' => '❌ Cancel'];

    /**
     * Back button definition
     */
    protected const BACK_BUTTON = ['id' => 'back', 'title' => '⬅️ Back'];

    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
    ) {}

    /**
     * Get the flow type this handler manages.
     */
    abstract protected function getFlowType(): FlowType;

    /**
     * Get the available steps for this flow.
     */
    abstract protected function getSteps(): array;

    /**
     * Get the flow name.
     */
    public function getName(): string
    {
        return $this->getFlowType()->value;
    }

    /**
     * Check if this handler can process the given step.
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, $this->getSteps());
    }

    /**
     * Handle invalid input for the current step.
     * 
     * ENHANCED: Now uses interactive buttons instead of plain text
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $step = $session->current_step;
        $expectedType = $this->getExpectedInputType($step);

        $errorMessage = ErrorTemplate::invalidInput($expectedType);

        // Send error with retry and menu buttons
        $this->sendErrorWithOptions(
            $session->phone,
            $errorMessage,
            $this->getRetryOptionsForStep($step)
        );
    }

    /**
     * Get the expected input type for a step.
     */
    protected function getExpectedInputType(string $step): string
    {
        return 'text'; // Override in subclasses
    }

    /**
     * Get retry options based on step type.
     */
    protected function getRetryOptionsForStep(string $step): array
    {
        $expectedType = $this->getExpectedInputType($step);

        return match ($expectedType) {
            'button' => [
                ['id' => 'retry', 'title' => '🔄 Show Options'],
                self::MENU_BUTTON,
            ],
            'list' => [
                ['id' => 'retry', 'title' => '🔄 Show List'],
                self::MENU_BUTTON,
            ],
            'location' => [
                ['id' => 'retry', 'title' => '📍 Share Location'],
                self::MENU_BUTTON,
            ],
            default => [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                self::MENU_BUTTON,
            ],
        };
    }

    /**
     * Re-prompt the current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        // Override in subclasses to re-send the current prompt
    }

    /*
    |--------------------------------------------------------------------------
    | Session Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Move to the next step.
     */
    protected function nextStep(ConversationSession $session, string $step): void
    {
        $this->sessionManager->setStep($session, $step);
    }

    /**
     * Move to a different flow.
     */
    protected function goToFlow(ConversationSession $session, FlowType $flow, string $step = null): void
    {
        $this->sessionManager->setFlowStep(
            $session,
            $flow,
            $step ?? $flow->initialStep()
        );
    }

    /**
     * Return to main menu.
     */
    protected function goToMainMenu(ConversationSession $session): void
    {
        $this->sessionManager->resetToMainMenu($session);
    }

    /**
     * Store temp data.
     */
    protected function setTemp(ConversationSession $session, string $key, mixed $value): void
    {
        $this->sessionManager->setTempData($session, $key, $value);
    }

    /**
     * Get temp data.
     */
    protected function getTemp(ConversationSession $session, string $key, mixed $default = null): mixed
    {
        return $this->sessionManager->getTempData($session, $key, $default);
    }

    /**
     * Clear all temp data.
     */
    protected function clearTemp(ConversationSession $session): void
    {
        $this->sessionManager->clearTempData($session);
    }

    /**
     * Get the user for this session.
     */
    protected function getUser(ConversationSession $session): ?User
    {
        return $this->sessionManager->getUser($session);
    }

    /*
    |--------------------------------------------------------------------------
    | ENHANCED Message Helpers - WITH MENU BUTTON
    |--------------------------------------------------------------------------
    */

    /**
     * Send text message with Main Menu button.
     * 
     * This is the PRIMARY method to use for text responses.
     * Ensures users always have a way back to menu.
     */
    protected function sendTextWithMenu(
        string $to,
        string $body,
        ?string $header = null
    ): array {
        return $this->sendButtons(
            $to,
            $body,
            [self::MENU_BUTTON],
            $header,
            self::DEFAULT_FOOTER
        );
    }

    /**
     * Send buttons WITH automatic menu option.
     * 
     * Automatically adds menu button if space allows (max 3 buttons).
     * If buttons array has 3 items, sends as-is.
     * If buttons array has 2 items, adds menu button.
     * If buttons array has 1 item, adds menu button.
     */
    protected function sendButtonsWithMenu(
        string $to,
        string $body,
        array $buttons,
        ?string $header = null,
        bool $addMenu = true
    ): array {
        // Auto-add menu button if space allows
        if ($addMenu && count($buttons) < 3) {
            $buttons[] = self::MENU_BUTTON;
        }

        return $this->sendButtons($to, $body, $buttons, $header, self::DEFAULT_FOOTER);
    }

    /**
     * Send buttons with Back + Menu options.
     * 
     * For multi-step flows where user might want to go back.
     */
    protected function sendButtonsWithBackAndMenu(
        string $to,
        string $body,
        array $buttons,
        ?string $header = null
    ): array {
        // Add back and menu if space allows
        if (count($buttons) < 2) {
            $buttons[] = self::BACK_BUTTON;
        }
        if (count($buttons) < 3) {
            $buttons[] = self::MENU_BUTTON;
        }

        return $this->sendButtons($to, $body, $buttons, $header, self::DEFAULT_FOOTER);
    }

    /**
     * Send list message with consistent footer.
     */
    protected function sendListWithFooter(
        string $to,
        string $body,
        string $buttonText,
        array $sections,
        ?string $header = null
    ): array {
        return $this->sendList($to, $body, $buttonText, $sections, $header, self::DEFAULT_FOOTER);
    }

    /**
     * Send error message with action buttons.
     * 
     * ENHANCED: Errors now have actionable options.
     */
    protected function sendErrorWithOptions(
        string $to,
        string $message,
        ?array $buttons = null
    ): array {
        $buttons = $buttons ?? [
            ['id' => 'retry', 'title' => '🔄 Try Again'],
            self::MENU_BUTTON,
        ];

        return $this->sendButtons($to, $message, $buttons, null, self::DEFAULT_FOOTER);
    }

    /**
     * Send success message with next action buttons.
     */
    protected function sendSuccessWithActions(
        string $to,
        string $message,
        array $nextActions,
        ?string $header = null
    ): array {
        // Ensure we have menu option
        if (count($nextActions) < 3) {
            $nextActions[] = self::MENU_BUTTON;
        }

        return $this->sendButtons($to, $message, $nextActions, $header, self::DEFAULT_FOOTER);
    }

    /**
     * Request location with consistent styling.
     */
    protected function requestLocationWithMenu(string $to, string $body): array
    {
        // First send the location request
        $result = $this->requestLocation($to, $body);

        // Then send a follow-up with menu option (in case they want to cancel)
        // Only if they're in a flow where they might want to bail
        // This is handled by the flow handler itself

        return $result;
    }

    /**
     * Send image with caption and menu button option.
     */
    protected function sendImageWithMenu(
        string $to,
        string $url,
        ?string $caption = null,
        ?array $followUpButtons = null
    ): array {
        // Send the image first
        $imageResult = $this->sendImage($to, $url, $caption);

        // If there are follow-up buttons, send them separately
        if ($followUpButtons) {
            $this->sendButtonsWithMenu(
                $to,
                "What would you like to do next?",
                $followUpButtons
            );
        }

        return $imageResult;
    }

    /**
     * Send document with caption and follow-up options.
     */
    protected function sendDocumentWithFollowUp(
        string $to,
        string $url,
        ?string $filename = null,
        ?string $caption = null,
        ?array $followUpButtons = null
    ): array {
        // Send the document
        $docResult = $this->sendDocument($to, $url, $filename, $caption);

        // Send follow-up buttons if provided
        if ($followUpButtons) {
            $this->sendButtonsWithMenu(
                $to,
                "Document sent! What's next?",
                $followUpButtons
            );
        } else {
            // Always provide menu option after document
            $this->sendTextWithMenu($to, "📄 Document sent successfully!");
        }

        return $docResult;
    }

    /*
    |--------------------------------------------------------------------------
    | Original Message Helpers (kept for backward compatibility)
    |--------------------------------------------------------------------------
    */

    /**
     * Send a text message (without menu button).
     * 
     * Use sendTextWithMenu() instead for most cases.
     */
    protected function sendText(string $to, string $body): array
    {
        return $this->whatsApp->sendText($to, $body);
    }

    /**
     * Send buttons.
     */
    protected function sendButtons(
        string $to,
        string $body,
        array $buttons,
        ?string $header = null,
        ?string $footer = null
    ): array {
        return $this->whatsApp->sendButtons($to, $body, $buttons, $header, $footer ?? self::DEFAULT_FOOTER);
    }

    /**
     * Send a list message.
     */
    protected function sendList(
        string $to,
        string $body,
        string $buttonText,
        array $sections,
        ?string $header = null,
        ?string $footer = null
    ): array {
        return $this->whatsApp->sendList($to, $body, $buttonText, $sections, $header, $footer ?? self::DEFAULT_FOOTER);
    }

    /**
     * Request location.
     */
    protected function requestLocation(string $to, string $body): array
    {
        return $this->whatsApp->requestLocation($to, $body);
    }

    /**
     * Send location.
     */
    protected function sendLocation(
        string $to,
        float $latitude,
        float $longitude,
        ?string $name = null,
        ?string $address = null
    ): array {
        return $this->whatsApp->sendLocation($to, $latitude, $longitude, $name, $address);
    }

    /**
     * Send image.
     */
    protected function sendImage(string $to, string $url, ?string $caption = null): array
    {
        return $this->whatsApp->sendImage($to, $url, $caption);
    }

    /**
     * Send document.
     */
    protected function sendDocument(
        string $to,
        string $url,
        ?string $filename = null,
        ?string $caption = null
    ): array {
        return $this->whatsApp->sendDocument($to, $url, $filename, $caption);
    }

    /**
     * Send an error message.
     * 
     * DEPRECATED: Use sendErrorWithOptions() instead.
     */
    protected function sendError(string $to, string $message): array
    {
        return $this->sendErrorWithOptions($to, $message);
    }

    /**
     * Send error with retry buttons.
     * 
     * DEPRECATED: Use sendErrorWithOptions() instead.
     */
    protected function sendErrorWithRetry(string $to, string $message): array
    {
        return $this->sendErrorWithOptions($to, $message, [
            ['id' => 'retry', 'title' => '🔄 Try Again'],
            self::MENU_BUTTON,
        ]);
    }

    /**
     * Format a template message.
     */
    protected function formatMessage(string $template, array $replacements): string
    {
        return MessageTemplates::format($template, $replacements);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Validate phone number format.
     */
    protected function validatePhone(string $phone): bool
    {
        // Remove any non-numeric characters
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        // Check length (10-15 digits for international)
        if (strlen($cleaned) < 10 || strlen($cleaned) > 15) {
            return false;
        }

        return true;
    }

    /**
     * Normalize phone number (add country code if missing).
     */
    protected function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

        // If 10 digits and starts with valid Indian mobile prefix, add 91
        if (strlen($cleaned) === 10 && in_array($cleaned[0], ['6', '7', '8', '9'])) {
            return '91' . $cleaned;
        }

        return $cleaned;
    }

    /**
     * Validate amount.
     */
    protected function validateAmount(string $amount): ?float
    {
        // Remove currency symbols and whitespace
        $cleaned = preg_replace('/[^0-9.]/', '', $amount);

        if (!is_numeric($cleaned)) {
            return null;
        }

        $value = (float) $cleaned;

        // Check reasonable range
        if ($value <= 0 || $value > 100000000) {
            return null;
        }

        return $value;
    }

    /**
     * Validate date string (DD/MM/YYYY format).
     */
    protected function validateDate(string $date): ?\Carbon\Carbon
    {
        try {
            $parsed = \Carbon\Carbon::createFromFormat('d/m/Y', trim($date));

            if ($parsed && $parsed->isValid() && $parsed->isFuture()) {
                return $parsed;
            }

            return null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Validate name.
     */
    protected function validateName(string $name): bool
    {
        $length = mb_strlen(trim($name));
        return $length >= 2 && $length <= 100;
    }

    /**
     * Validate description.
     */
    protected function validateDescription(string $description, int $minLength = 10, int $maxLength = 500): bool
    {
        $length = mb_strlen(trim($description));
        return $length >= $minLength && $length <= $maxLength;
    }

    /*
    |--------------------------------------------------------------------------
    | Input Extraction Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get text content from message.
     */
    protected function getTextContent(IncomingMessage $message): ?string
    {
        return $message->getTextContent();
    }

    /**
     * Get selection ID from interactive message.
     */
    protected function getSelectionId(IncomingMessage $message): ?string
    {
        return $message->getSelectionId();
    }

    /**
     * Get location from message.
     */
    protected function getLocation(IncomingMessage $message): ?array
    {
        return $message->getCoordinates();
    }

    /**
     * Get media ID from message.
     */
    protected function getMediaId(IncomingMessage $message): ?string
    {
        return $message->getMediaId();
    }

    /**
     * Check if input is "skip".
     */
    protected function isSkip(IncomingMessage $message): bool
    {
        // Check for skip button press
        if ($message->isInteractive()) {
            $id = $this->getSelectionId($message);
            return in_array($id, ['skip', 'skip_image', 'skip_caption']);
        }

        // Check for skip text
        if ($message->isText()) {
            return strtolower(trim($message->text ?? '')) === 'skip';
        }

        return false;
    }

    /**
     * Check if user wants to go back.
     */
    protected function isBack(IncomingMessage $message): bool
    {
        if ($message->isInteractive()) {
            return $this->getSelectionId($message) === 'back';
        }

        if ($message->isText()) {
            return strtolower(trim($message->text ?? '')) === 'back';
        }

        return false;
    }

    /**
     * Check if user selected main menu.
     */
    protected function isMainMenu(IncomingMessage $message): bool
    {
        if ($message->isInteractive()) {
            $id = $this->getSelectionId($message);
            return in_array($id, ['main_menu', 'menu', 'home']);
        }

        return false;
    }

    /**
     * Check if user wants to cancel.
     */
    protected function isCancel(IncomingMessage $message): bool
    {
        if ($message->isInteractive()) {
            return $this->getSelectionId($message) === 'cancel';
        }

        if ($message->isText()) {
            return strtolower(trim($message->text ?? '')) === 'cancel';
        }

        return false;
    }

    /**
     * Check if user wants to retry.
     */
    protected function isRetry(IncomingMessage $message): bool
    {
        if ($message->isInteractive()) {
            return $this->getSelectionId($message) === 'retry';
        }

        return false;
    }

    /**
     * Handle common navigation inputs.
     * 
     * Returns true if navigation was handled (caller should return).
     * Returns false if input should be processed normally.
     */
    protected function handleCommonNavigation(IncomingMessage $message, ConversationSession $session): bool
    {
        // Main menu
        if ($this->isMainMenu($message)) {
            $this->goToMainMenu($session);
            app(\App\Services\Flow\FlowRouter::class)->startFlow($session, FlowType::MAIN_MENU);
            return true;
        }

        // Cancel
        if ($this->isCancel($message)) {
            $this->clearTemp($session);
            $this->sendTextWithMenu($session->phone, "❌ *Cancelled*\n\nAction cancelled.");
            $this->goToMainMenu($session);
            return true;
        }

        // Retry - re-prompt current step
        if ($this->isRetry($message)) {
            $this->promptCurrentStep($session);
            return true;
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Logging Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Log info.
     */
    protected function logInfo(string $message, array $context = []): void
    {
        Log::info("[{$this->getName()}] {$message}", $context);
    }

    /**
     * Log error.
     */
    protected function logError(string $message, array $context = []): void
    {
        Log::error("[{$this->getName()}] {$message}", $context);
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