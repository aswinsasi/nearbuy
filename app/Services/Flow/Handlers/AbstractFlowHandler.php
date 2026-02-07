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
 * Abstract base class for all flow handlers.
 *
 * Provides common methods that ALL handlers inherit:
 * - Message sending (text, buttons, list, image, document, location)
 * - Session management (step, temp data, navigation)
 * - Input validation and extraction
 * - Error handling
 * - Logging
 *
 * @srs-ref Section 7.1 Flow Controllers
 * @srs-ref NFR-U-04 Main menu accessible from any flow state
 */
abstract class AbstractFlowHandler implements FlowHandlerInterface
{
    /*
    |--------------------------------------------------------------------------
    | Constants
    |--------------------------------------------------------------------------
    */

    protected const MENU_BUTTON = ['id' => 'main_menu', 'title' => '🏠 Menu'];
    protected const CANCEL_BUTTON = ['id' => 'cancel', 'title' => '❌ Cancel'];
    protected const BACK_BUTTON = ['id' => 'back', 'title' => '⬅️ Back'];
    protected const SKIP_BUTTON = ['id' => 'skip', 'title' => '⏭️ Skip'];
    protected const RETRY_BUTTON = ['id' => 'retry', 'title' => '🔄 Try Again'];
    protected const DONE_BUTTON = ['id' => 'done', 'title' => '✅ Done'];

    /**
     * Cross-flow navigation button IDs that route to other flows.
     */
    protected const CROSS_FLOW_BUTTONS = [
        'main_menu', 'menu', 'home',
        'register', 'browse_offers', 'upload_offer', 'my_offers',
        'search_product', 'my_requests', 'product_requests',
        'create_agreement', 'my_agreements', 'pending_agreements',
        'fish_browse', 'fish_alerts', 'fish_post_catch', 'fish_seller_menu',
        'job_browse', 'job_post', 'job_worker_menu', 'job_poster_menu',
        'flash_deal_create', 'flash_deal_manage',
        'settings',
    ];

    /*
    |--------------------------------------------------------------------------
    | Properties
    |--------------------------------------------------------------------------
    */

    protected SessionManager $sessionManager;
    protected WhatsAppService $whatsApp;

    public function __construct(
        SessionManager $sessionManager,
        WhatsAppService $whatsApp,
    ) {
        $this->sessionManager = $sessionManager;
        $this->whatsApp = $whatsApp;
    }

    /*
    |--------------------------------------------------------------------------
    | Abstract Methods (Must implement in subclasses)
    |--------------------------------------------------------------------------
    */

    /**
     * Get the flow type this handler manages.
     */
    abstract protected function getFlowType(): FlowType;

    /**
     * Get the available steps for this flow.
     *
     * @return array<string> List of step names
     */
    abstract protected function getSteps(): array;

    /*
    |--------------------------------------------------------------------------
    | Interface Implementation
    |--------------------------------------------------------------------------
    */

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
        $expectedType = $this->getExpectedInputType($session->current_step);
        $this->sendError($session->phone, $expectedType);
    }

    /**
     * Handle timeout recovery.
     */
    public function handleTimeout(ConversationSession $session): void
    {
        $this->sendTextWithMenu(
            $session->phone,
            "⏰ Your session timed out.\n\nWould you like to start over?"
        );
        $this->goToMenu($session);
    }

    /**
     * Get expected input type for a step.
     * Override in subclasses for specific step types.
     */
    public function getExpectedInputType(string $step): string
    {
        return 'text';
    }

    /*
    |--------------------------------------------------------------------------
    | Session Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get the phone number from session.
     */
    protected function getPhone(ConversationSession $session): string
    {
        return $session->phone;
    }

    /**
     * Get the associated user.
     */
    protected function getUser(ConversationSession $session): ?User
    {
        return $this->sessionManager->getUser($session);
    }

    /**
     * Get current step.
     */
    protected function getStep(ConversationSession $session): string
    {
        return $session->current_step ?? '';
    }

    /**
     * Get current flow.
     */
    protected function getFlow(ConversationSession $session): string
    {
        return $session->current_flow ?? '';
    }

    /**
     * Get language preference.
     */
    protected function getLanguage(ConversationSession $session): string
    {
        return $session->language ?? 'en';
    }

    /*
    |--------------------------------------------------------------------------
    | Step Management
    |--------------------------------------------------------------------------
    */

    /**
     * Set the current step.
     */
    protected function setStep(ConversationSession $session, string $step): void
    {
        $this->sessionManager->setStep($session, $step);
        $this->logStep($step);
    }

    /**
     * Set flow and step together.
     */
    protected function setFlowStep(ConversationSession $session, FlowType $flow, string $step): void
    {
        $this->sessionManager->setFlowStep($session, $flow, $step);
        $this->logStep($step, $flow->value);
    }

    /**
     * Move to next step (saves previous step for goBack).
     */
    protected function nextStep(ConversationSession $session, string $step): void
    {
        $this->sessionManager->savePreviousStep($session);
        $this->setStep($session, $step);
    }

    /**
     * Go back to previous step.
     */
    protected function goBack(ConversationSession $session): void
    {
        $this->sessionManager->goBack($session);
    }

    /**
     * Go to main menu.
     *
     * @srs-ref NFR-U-04 Main menu accessible from any flow state
     */
    protected function goToMenu(ConversationSession $session): void
    {
        $this->clearTempData($session);
        $this->sessionManager->resetToMainMenu($session);
    }

    /**
     * Complete flow and return to menu.
     */
    protected function completeFlow(ConversationSession $session): void
    {
        $this->clearTempData($session);
        $this->sessionManager->completeFlow($session);
    }

    /**
     * Go to a different flow.
     */
    protected function goToFlow(ConversationSession $session, FlowType $flow, ?string $step = null): void
    {
        $this->clearTempData($session);
        $this->sessionManager->setFlowStep(
            $session,
            $flow,
            $step ?? $flow->initialStep()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Temp Data Management
    |--------------------------------------------------------------------------
    */

    /**
     * Set temp data value.
     */
    protected function setTempData(ConversationSession $session, string $key, mixed $value): void
    {
        $this->sessionManager->setTempData($session, $key, $value);
    }

    /**
     * Get temp data value.
     */
    protected function getTempData(ConversationSession $session, string $key, mixed $default = null): mixed
    {
        return $this->sessionManager->getTempData($session, $key, $default);
    }

    /**
     * Check if temp data has key.
     */
    protected function hasTempData(ConversationSession $session, string $key): bool
    {
        return $this->sessionManager->hasTempData($session, $key);
    }

    /**
     * Get all temp data.
     */
    protected function getAllTempData(ConversationSession $session): array
    {
        return $this->sessionManager->getAllTempData($session);
    }

    /**
     * Merge temp data.
     */
    protected function mergeTempData(ConversationSession $session, array $data): void
    {
        $this->sessionManager->mergeTempData($session, $data);
    }

    /**
     * Remove temp data key.
     */
    protected function removeTempData(ConversationSession $session, string $key): void
    {
        $this->sessionManager->removeTempData($session, $key);
    }

    /**
     * Clear all temp data.
     */
    protected function clearTempData(ConversationSession $session): void
    {
        $this->sessionManager->clearTempData($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Message Sending — Core
    |--------------------------------------------------------------------------
    */

    /**
     * Send text message.
     */
    protected function sendText(string $to, string $body): array
    {
        return $this->whatsApp->sendText($to, $body);
    }

    /**
     * Send text with main menu button.
     */
    protected function sendTextWithMenu(string $to, string $body, ?string $header = null): array
    {
        return $this->sendButtons($to, $body, [self::MENU_BUTTON], $header);
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
        return $this->whatsApp->sendButtons(
            $to,
            $body,
            $buttons,
            $header,
            $footer ?? MessageTemplates::GLOBAL_FOOTER
        );
    }

    /**
     * Send buttons with automatic menu option.
     */
    protected function sendButtonsWithMenu(
        string $to,
        string $body,
        array $buttons,
        ?string $header = null
    ): array {
        if (count($buttons) < 3) {
            $buttons[] = self::MENU_BUTTON;
        }
        return $this->sendButtons($to, $body, $buttons, $header);
    }

    /**
     * Send buttons with back and menu.
     */
    protected function sendButtonsWithBackAndMenu(
        string $to,
        string $body,
        array $buttons,
        ?string $header = null
    ): array {
        if (count($buttons) < 2) {
            $buttons[] = self::BACK_BUTTON;
        }
        if (count($buttons) < 3) {
            $buttons[] = self::MENU_BUTTON;
        }
        return $this->sendButtons($to, $body, $buttons, $header);
    }

    /**
     * Send list message.
     */
    protected function sendList(
        string $to,
        string $body,
        string $buttonText,
        array $sections,
        ?string $header = null,
        ?string $footer = null
    ): array {
        return $this->whatsApp->sendList(
            $to,
            $body,
            $buttonText,
            $sections,
            $header,
            $footer ?? MessageTemplates::GLOBAL_FOOTER
        );
    }

    /**
     * Send image.
     */
    protected function sendImage(string $to, string $url, ?string $caption = null): array
    {
        return $this->whatsApp->sendImage($to, $url, $caption);
    }

    /**
     * Send image with follow-up buttons.
     */
    protected function sendImageWithButtons(
        string $to,
        string $url,
        ?string $caption,
        array $buttons
    ): array {
        $result = $this->sendImage($to, $url, $caption);
        $this->sendButtonsWithMenu($to, "What would you like to do?", $buttons);
        return $result;
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
     * Request location from user.
     */
    protected function requestLocation(string $to, string $body): array
    {
        return $this->whatsApp->requestLocation($to, $body);
    }

    /*
    |--------------------------------------------------------------------------
    | Error Handling
    |--------------------------------------------------------------------------
    */

    /**
     * Send error message with retry options.
     */
    protected function sendError(string $to, string $type = 'generic'): array
    {
        $error = ErrorTemplate::get($type);

        return $this->sendButtons(
            $to,
            $error['message'],
            $error['buttons'] ?? [self::RETRY_BUTTON, self::MENU_BUTTON]
        );
    }

    /**
     * Send custom error with buttons.
     */
    protected function sendErrorWithButtons(string $to, string $message, ?array $buttons = null): array
    {
        return $this->sendButtons(
            $to,
            $message,
            $buttons ?? [self::RETRY_BUTTON, self::MENU_BUTTON]
        );
    }

    /**
     * Send validation error.
     */
    protected function sendValidationError(string $to, string $field): array
    {
        $error = ErrorTemplate::validation($field);

        return $this->sendButtons(
            $to,
            $error['message'],
            $error['buttons'] ?? [self::RETRY_BUTTON, self::MENU_BUTTON]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Success Messages
    |--------------------------------------------------------------------------
    */

    /**
     * Send success with next actions.
     */
    protected function sendSuccess(string $to, string $message, array $nextActions, ?string $header = null): array
    {
        if (count($nextActions) < 3) {
            $nextActions[] = self::MENU_BUTTON;
        }
        return $this->sendButtons($to, $message, $nextActions, $header);
    }

    /**
     * Send completion message and return to menu.
     */
    protected function sendCompletionAndMenu(
        ConversationSession $session,
        string $message,
        ?array $nextActions = null
    ): void {
        $buttons = $nextActions ?? [];
        $buttons[] = self::MENU_BUTTON;

        $this->sendButtons(
            $session->phone,
            $message,
            array_slice($buttons, 0, 3)
        );

        $this->completeFlow($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Menu Hint
    |--------------------------------------------------------------------------
    */

    /**
     * Append menu hint to message.
     *
     * @srs-ref NFR-U-04 Main menu accessible from any flow state
     */
    protected function appendMenuHint(string $message, string $lang = 'en'): string
    {
        $hint = $lang === 'ml'
            ? MessageTemplates::MENU_HINT_ML
            : MessageTemplates::MENU_HINT_EN;

        return $message . "\n\n" . $hint;
    }

    /**
     * Send message with menu hint appended.
     */
    protected function sendWithMenuHint(string $to, string $message, string $lang = 'en'): array
    {
        return $this->sendText($to, $this->appendMenuHint($message, $lang));
    }

    /*
    |--------------------------------------------------------------------------
    | Input Extraction
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
     * Get button ID.
     */
    protected function getButtonId(IncomingMessage $message): ?string
    {
        return $message->getButtonId();
    }

    /**
     * Get list selection ID.
     */
    protected function getListId(IncomingMessage $message): ?string
    {
        return $message->getListId();
    }

    /**
     * Get location coordinates.
     */
    protected function getLocation(IncomingMessage $message): ?array
    {
        return $message->getCoordinates();
    }

    /**
     * Get media ID.
     */
    protected function getMediaId(IncomingMessage $message): ?string
    {
        return $message->getMediaId();
    }

    /*
    |--------------------------------------------------------------------------
    | Input Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if user wants to skip.
     */
    protected function isSkip(IncomingMessage $message): bool
    {
        if ($message->isInteractive()) {
            return in_array($this->getSelectionId($message), ['skip', 'skip_image', 'skip_step']);
        }
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
            return in_array($this->getSelectionId($message), ['main_menu', 'menu', 'home']);
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
     * Check if this is a cross-flow navigation button.
     */
    protected function isCrossFlowNavigation(IncomingMessage $message): bool
    {
        if (!$message->isInteractive()) {
            return false;
        }

        $id = $this->getSelectionId($message);
        return $id && in_array($id, self::CROSS_FLOW_BUTTONS);
    }

    /*
    |--------------------------------------------------------------------------
    | Common Navigation Handler
    |--------------------------------------------------------------------------
    */

    /**
     * Handle common navigation inputs.
     *
     * Returns true if navigation was handled (caller should return).
     */
    protected function handleCommonNavigation(IncomingMessage $message, ConversationSession $session): bool
    {
        // Main menu
        if ($this->isMainMenu($message)) {
            $this->goToMenu($session);
            app(\App\Services\Flow\FlowRouter::class)->goToMainMenu($session);
            return true;
        }

        // Cancel
        if ($this->isCancel($message)) {
            $this->sendTextWithMenu($session->phone, "❌ *Cancelled*\n\nAction cancelled.");
            $this->goToMenu($session);
            return true;
        }

        // Back
        if ($this->isBack($message)) {
            $this->goBack($session);
            $this->promptCurrentStep($session);
            return true;
        }

        // Retry - re-prompt current step
        if ($this->isRetry($message)) {
            $this->promptCurrentStep($session);
            return true;
        }

        // Cross-flow navigation
        if ($this->isCrossFlowNavigation($message)) {
            $selectionId = $this->getSelectionId($message);
            $this->clearTempData($session);
            app(\App\Services\Flow\FlowRouter::class)->handleMenuSelection($selectionId, $session);
            return true;
        }

        return false;
    }

    /**
     * Re-prompt the current step.
     * Override in subclasses to re-send the appropriate prompt.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        // Default: do nothing. Subclasses should override.
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Validate phone number.
     */
    protected function validatePhone(string $phone): bool
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);
        return strlen($cleaned) >= 10 && strlen($cleaned) <= 15;
    }

    /**
     * Normalize phone number (add India country code if needed).
     */
    protected function normalizePhone(string $phone): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phone);

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
        $cleaned = preg_replace('/[^0-9.]/', '', $amount);

        if (!is_numeric($cleaned)) {
            return null;
        }

        $value = (float) $cleaned;

        if ($value <= 0 || $value > 100000000) {
            return null;
        }

        return $value;
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
    protected function validateDescription(string $description, int $min = 5, int $max = 500): bool
    {
        $length = mb_strlen(trim($description));
        return $length >= $min && $length <= $max;
    }

    /**
     * Validate date (DD/MM/YYYY).
     */
    protected function validateDate(string $date): ?\Carbon\Carbon
    {
        try {
            $parsed = \Carbon\Carbon::createFromFormat('d/m/Y', trim($date));
            return ($parsed && $parsed->isValid() && $parsed->isFuture()) ? $parsed : null;
        } catch (\Exception $e) {
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Formatting Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Format amount in Indian rupees.
     */
    protected function formatAmount(float $amount): string
    {
        return '₹' . number_format($amount, 0, '.', ',');
    }

    /**
     * Format distance.
     */
    protected function formatDistance(float $km): string
    {
        if ($km < 1) {
            return round($km * 1000) . 'm';
        }
        return round($km, 1) . 'km';
    }

    /**
     * Format template message.
     */
    protected function formatMessage(string $template, array $replacements): string
    {
        return MessageTemplates::format($template, $replacements);
    }

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    */

    /**
     * Log step change.
     */
    protected function logStep(string $step, ?string $flow = null): void
    {
        Log::debug("[{$this->getName()}] Step: {$step}", [
            'flow' => $flow ?? $this->getName(),
            'step' => $step,
        ]);
    }

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
     * Log debug.
     */
    protected function logDebug(string $message, array $context = []): void
    {
        Log::debug("[{$this->getName()}] {$message}", $context);
    }

    /**
     * Mask phone for logging.
     */
    protected function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return $phone;
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }
}