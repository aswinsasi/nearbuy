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
 * Provides common functionality for all flow handlers including
 * session management, message sending, and error handling.
 */
abstract class AbstractFlowHandler implements FlowHandlerInterface
{
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
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $step = $session->current_step;
        $expectedType = $this->getExpectedInputType($step);

        $errorMessage = ErrorTemplate::invalidInput($expectedType);

        $this->sendText($session->phone, $errorMessage);

        // Re-prompt the current step
        $this->promptCurrentStep($session);
    }

    /**
     * Get the expected input type for a step.
     */
    protected function getExpectedInputType(string $step): string
    {
        return 'text'; // Override in subclasses
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
    | Message Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Send a text message.
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
        return $this->whatsApp->sendButtons($to, $body, $buttons, $header, $footer);
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
        return $this->whatsApp->sendList($to, $body, $buttonText, $sections, $header, $footer);
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
     */
    protected function sendError(string $to, string $message): array
    {
        return $this->sendText($to, $message);
    }

    /**
     * Send error with retry buttons.
     */
    protected function sendErrorWithRetry(string $to, string $message): array
    {
        $error = ErrorTemplate::withRetry($message);
        return $this->sendButtons($to, $error['message'], $error['buttons']);
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
        if (!$message->isText()) {
            return false;
        }

        return strtolower(trim($message->text ?? '')) === 'skip';
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