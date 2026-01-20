<?php

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\AgreementStep;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Services\Agreements\AgreementService;
use App\Services\PDF\AgreementPDFService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\AgreementMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handles the agreement creation flow.
 *
 * Flow Steps:
 * 1. ask_direction - Giving or receiving money
 * 2. ask_amount - Enter amount
 * 3. ask_other_party_name - Enter other party's name
 * 4. ask_other_party_phone - Enter their phone
 * 5. ask_purpose - Select purpose
 * 6. ask_description - Optional description
 * 7. ask_due_date - Select due date
 * 8. confirm_create - Review and confirm
 * 9. create_complete - Success
 */
class AgreementCreateFlowHandler implements FlowHandlerInterface
{
    public function __construct(
        protected SessionManager $sessionManager,
        protected WhatsAppService $whatsApp,
        protected AgreementService $agreementService,
        protected AgreementPDFService $pdfService,
    ) {}

    /**
     * Get flow name.
     */
    public function getName(): string
    {
        return FlowType::AGREEMENT_CREATE->value;
    }

    /**
     * Check if can handle step.
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, [
            AgreementStep::ASK_DIRECTION->value,
            AgreementStep::ASK_AMOUNT->value,
            AgreementStep::ASK_OTHER_PARTY_NAME->value,
            AgreementStep::ASK_OTHER_PARTY_PHONE->value,
            AgreementStep::ASK_PURPOSE->value,
            AgreementStep::ASK_DESCRIPTION->value,
            AgreementStep::ASK_DUE_DATE->value,
            AgreementStep::CONFIRM_CREATE->value,
            AgreementStep::CREATE_COMPLETE->value,
        ]);
    }

    /**
     * Start the flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->sessionManager->getUser($session);

        if (!$user) {
            $this->whatsApp->sendText(
                $session->phone,
                "⚠️ Please register first to create agreements."
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        // Clear previous data
        $this->clearTempData($session);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_CREATE,
            AgreementStep::ASK_DIRECTION->value
        );

        $this->askDirection($session);
    }

    /**
     * Handle incoming message.
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        $step = AgreementStep::tryFrom($session->current_step);

        if (!$step) {
            $this->start($session);
            return;
        }

        match ($step) {
            AgreementStep::ASK_DIRECTION => $this->handleDirectionSelection($message, $session),
            AgreementStep::ASK_AMOUNT => $this->handleAmountInput($message, $session),
            AgreementStep::ASK_OTHER_PARTY_NAME => $this->handleNameInput($message, $session),
            AgreementStep::ASK_OTHER_PARTY_PHONE => $this->handlePhoneInput($message, $session),
            AgreementStep::ASK_PURPOSE => $this->handlePurposeSelection($message, $session),
            AgreementStep::ASK_DESCRIPTION => $this->handleDescriptionInput($message, $session),
            AgreementStep::ASK_DUE_DATE => $this->handleDueDateSelection($message, $session),
            AgreementStep::CONFIRM_CREATE => $this->handleConfirmation($message, $session),
            AgreementStep::CREATE_COMPLETE => $this->handlePostCreate($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Handle invalid input.
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $step = AgreementStep::tryFrom($session->current_step);

        match ($step) {
            AgreementStep::ASK_DIRECTION => $this->askDirection($session, true),
            AgreementStep::ASK_AMOUNT => $this->askAmount($session, true),
            AgreementStep::ASK_OTHER_PARTY_NAME => $this->askName($session, true),
            AgreementStep::ASK_OTHER_PARTY_PHONE => $this->askPhone($session, true),
            AgreementStep::ASK_PURPOSE => $this->askPurpose($session, true),
            AgreementStep::ASK_DUE_DATE => $this->askDueDate($session, true),
            AgreementStep::CONFIRM_CREATE => $this->askConfirmation($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    protected function handleDirectionSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $direction = null;

        if ($message->isButtonReply()) {
            $direction = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (str_contains($text, 'giving') || str_contains($text, 'give') || $text === '1') {
                $direction = 'giving';
            } elseif (str_contains($text, 'receiving') || str_contains($text, 'receive') || $text === '2') {
                $direction = 'receiving';
            }
        }

        if (!in_array($direction, ['giving', 'receiving'])) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->sessionManager->setTempData($session, 'direction', $direction);

        $this->sessionManager->setStep($session, AgreementStep::ASK_AMOUNT->value);
        $this->askAmount($session);
    }

    protected function handleAmountInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $input = trim($message->text ?? '');

        // Remove currency symbols and commas
        $cleaned = preg_replace('/[₹,\s]/', '', $input);

        if (!is_numeric($cleaned) || !$this->agreementService->isValidAmount($cleaned)) {
            $this->whatsApp->sendText($session->phone, AgreementMessages::ERROR_INVALID_AMOUNT);
            return;
        }

        $amount = (float) $cleaned;
        $this->sessionManager->setTempData($session, 'amount', $amount);

        $this->sessionManager->setStep($session, AgreementStep::ASK_OTHER_PARTY_NAME->value);
        $this->askName($session);
    }

    protected function handleNameInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $name = trim($message->text ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $this->whatsApp->sendText($session->phone, AgreementMessages::ERROR_INVALID_NAME);
            return;
        }

        $this->sessionManager->setTempData($session, 'other_party_name', $name);

        $this->sessionManager->setStep($session, AgreementStep::ASK_OTHER_PARTY_PHONE->value);
        $this->askPhone($session);
    }

    protected function handlePhoneInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $phone = trim($message->text ?? '');

        if (!$this->agreementService->isValidPhone($phone)) {
            $this->whatsApp->sendText($session->phone, AgreementMessages::ERROR_INVALID_PHONE);
            return;
        }

        // Check for self-agreement
        $user = $this->sessionManager->getUser($session);
        $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($normalizedPhone) === 10) {
            $normalizedPhone = '91' . $normalizedPhone;
        }

        if ($normalizedPhone === $user->phone) {
            $this->whatsApp->sendText($session->phone, AgreementMessages::ERROR_SELF_AGREEMENT);
            return;
        }

        $this->sessionManager->setTempData($session, 'other_party_phone', $phone);

        $this->sessionManager->setStep($session, AgreementStep::ASK_PURPOSE->value);
        $this->askPurpose($session);
    }

    protected function handlePurposeSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $purpose = null;

        if ($message->isListReply()) {
            $purpose = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            $purpose = $this->matchPurpose($text);
        }

        $validPurposes = ['loan', 'advance', 'deposit', 'business', 'personal', 'other'];
        if (!in_array($purpose, $validPurposes)) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->sessionManager->setTempData($session, 'purpose', $purpose);

        $this->sessionManager->setStep($session, AgreementStep::ASK_DESCRIPTION->value);
        $this->askDescription($session);
    }

    protected function handleDescriptionInput(IncomingMessage $message, ConversationSession $session): void
    {
        $description = null;

        if ($message->isText()) {
            $text = trim($message->text ?? '');

            if (strtolower($text) !== 'skip' && mb_strlen($text) > 0) {
                if (mb_strlen($text) > 500) {
                    $this->whatsApp->sendText(
                        $session->phone,
                        "⚠️ Description is too long. Please keep it under 500 characters."
                    );
                    return;
                }
                $description = $text;
            }
        }

        $this->sessionManager->setTempData($session, 'description', $description);

        $this->sessionManager->setStep($session, AgreementStep::ASK_DUE_DATE->value);
        $this->askDueDate($session);
    }

    protected function handleDueDateSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = null;

        if ($message->isListReply()) {
            $selection = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            $selection = $this->matchDueDate($text);
        }

        $validSelections = ['1week', '2weeks', '1month', '3months', '6months', 'none'];
        if (!in_array($selection, $validSelections)) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $dueDate = AgreementMessages::getDueDateFromSelection($selection);
        $this->sessionManager->setTempData($session, 'due_date', $dueDate?->toDateString());
        $this->sessionManager->setTempData($session, 'due_date_selection', $selection);

        $this->sessionManager->setStep($session, AgreementStep::CONFIRM_CREATE->value);
        $this->askConfirmation($session);
    }

    protected function handleConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (in_array($text, ['confirm', 'create', 'yes', '1'])) {
                $action = 'confirm';
            } elseif (in_array($text, ['edit', 'change', '2'])) {
                $action = 'edit';
            } elseif (in_array($text, ['cancel', 'no', '3'])) {
                $action = 'cancel';
            }
        }

        match ($action) {
            'confirm' => $this->createAgreement($session),
            'edit' => $this->restartCreation($session),
            'cancel' => $this->cancelCreation($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    protected function handlePostCreate(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'view_agreement' => $this->showAgreement($session),
            'create_another' => $this->start($session),
            default => $this->goToMainMenu($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Prompt Methods
    |--------------------------------------------------------------------------
    */

    protected function askDirection(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select whether you are giving or receiving money."
            : AgreementMessages::CREATE_START . "\n\n" . AgreementMessages::ASK_DIRECTION;

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            AgreementMessages::getDirectionButtons()
        );
    }

    protected function askAmount(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? AgreementMessages::ERROR_INVALID_AMOUNT
            : AgreementMessages::ASK_AMOUNT;

        $this->whatsApp->sendText($session->phone, $message);
    }

    protected function askName(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? AgreementMessages::ERROR_INVALID_NAME
            : AgreementMessages::ASK_NAME;

        $this->whatsApp->sendText($session->phone, $message);
    }

    protected function askPhone(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? AgreementMessages::ERROR_INVALID_PHONE
            : AgreementMessages::ASK_PHONE;

        $this->whatsApp->sendText($session->phone, $message);
    }

    protected function askPurpose(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select a purpose from the list."
            : AgreementMessages::ASK_PURPOSE;

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            '📝 Select Purpose',
            AgreementMessages::getPurposeSections()
        );
    }

    protected function askDescription(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, AgreementMessages::ASK_DESCRIPTION);
    }

    protected function askDueDate(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select a due date from the list."
            : AgreementMessages::ASK_DUE_DATE;

        $this->whatsApp->sendList(
            $session->phone,
            $message,
            '📅 Select Due Date',
            AgreementMessages::getDueDateSections()
        );
    }

    protected function askConfirmation(ConversationSession $session): void
    {
        $direction = $this->sessionManager->getTempData($session, 'direction');
        $amount = $this->sessionManager->getTempData($session, 'amount');
        $name = $this->sessionManager->getTempData($session, 'other_party_name');
        $phone = $this->sessionManager->getTempData($session, 'other_party_phone');
        $purpose = $this->sessionManager->getTempData($session, 'purpose');
        $description = $this->sessionManager->getTempData($session, 'description');
        $dueDateSelection = $this->sessionManager->getTempData($session, 'due_date_selection');

        $dueDate = AgreementMessages::getDueDateFromSelection($dueDateSelection);

        $message = AgreementMessages::format(AgreementMessages::REVIEW_AGREEMENT, [
            'direction_emoji' => AgreementMessages::getDirectionEmoji($direction),
            'direction' => AgreementMessages::getDirectionLabel($direction),
            'other_party_name' => $name,
            'other_party_phone' => $phone,
            'amount' => number_format($amount),
            'purpose' => AgreementMessages::getPurposeLabel($purpose),
            'due_date' => AgreementMessages::formatDueDate($dueDate),
            'description' => $description ?? 'None',
        ]);

        $this->whatsApp->sendButtons(
            $session->phone,
            $message,
            AgreementMessages::getReviewButtons()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Action Methods
    |--------------------------------------------------------------------------
    */

    protected function createAgreement(ConversationSession $session): void
    {
        try {
            $user = $this->sessionManager->getUser($session);

            $dueDateString = $this->sessionManager->getTempData($session, 'due_date');
            $dueDate = $dueDateString ? \Carbon\Carbon::parse($dueDateString) : null;

            $agreement = $this->agreementService->createAgreement($user, [
                'direction' => $this->sessionManager->getTempData($session, 'direction'),
                'amount' => $this->sessionManager->getTempData($session, 'amount'),
                'other_party_name' => $this->sessionManager->getTempData($session, 'other_party_name'),
                'other_party_phone' => $this->sessionManager->getTempData($session, 'other_party_phone'),
                'purpose' => $this->sessionManager->getTempData($session, 'purpose'),
                'description' => $this->sessionManager->getTempData($session, 'description'),
                'due_date' => $dueDate,
            ]);

            // Store agreement ID
            $this->sessionManager->setTempData($session, 'created_agreement_id', $agreement->id);

            // Send success message
            $message = AgreementMessages::format(AgreementMessages::CREATE_SUCCESS, [
                'agreement_number' => $agreement->agreement_number,
                'other_party_name' => $agreement->to_name,
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                AgreementMessages::getPostCreateButtons()
            );

            // Send confirmation request to counterparty
            $this->sendConfirmationRequest($agreement);

            $this->sessionManager->setStep($session, AgreementStep::CREATE_COMPLETE->value);

            Log::info('Agreement created via flow', [
                'agreement_id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to create agreement', [
                'error' => $e->getMessage(),
            ]);

            $this->whatsApp->sendText(
                $session->phone,
                "❌ Failed to create agreement: " . $e->getMessage()
            );

            $this->start($session);
        }
    }

    protected function sendConfirmationRequest($agreement): void
    {
        $creator = $agreement->creator;
        $direction = $agreement->creditor_id === $creator->id ? 'giving' : 'receiving';

        // Invert direction for counterparty perspective
        $counterpartyDirection = $direction === 'giving' ? 'receiving' : 'giving';

        $message = AgreementMessages::format(AgreementMessages::CONFIRM_REQUEST, [
            'creator_name' => $creator->name,
            'direction_emoji' => AgreementMessages::getDirectionEmoji($counterpartyDirection),
            'direction' => AgreementMessages::getDirectionLabel($counterpartyDirection, false),
            'amount' => number_format($agreement->amount),
            'purpose' => AgreementMessages::getPurposeLabel($agreement->purpose->value ?? 'other'),
            'due_date' => AgreementMessages::formatDueDate($agreement->due_date),
            'description' => $agreement->description ?? 'None',
            'agreement_number' => $agreement->agreement_number,
        ]);

        $this->whatsApp->sendButtons(
            $agreement->to_phone,
            $message,
            AgreementMessages::getConfirmButtons()
        );
    }

    protected function showAgreement(ConversationSession $session): void
    {
        $agreementId = $this->sessionManager->getTempData($session, 'created_agreement_id');
        // Would transition to agreement list flow
        $this->goToMainMenu($session);
    }

    protected function restartCreation(ConversationSession $session): void
    {
        $this->whatsApp->sendText($session->phone, "🔄 Let's start over.");
        $this->start($session);
    }

    protected function cancelCreation(ConversationSession $session): void
    {
        $this->clearTempData($session);
        $this->whatsApp->sendText($session->phone, AgreementMessages::CREATE_CANCELLED);
        $this->goToMainMenu($session);
    }

    protected function goToMainMenu(ConversationSession $session): void
    {
        $this->sessionManager->resetToMainMenu($session);
        app(MainMenuHandler::class)->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    protected function clearTempData(ConversationSession $session): void
    {
        $keys = [
            'direction', 'amount', 'other_party_name', 'other_party_phone',
            'purpose', 'description', 'due_date', 'due_date_selection',
            'created_agreement_id',
        ];

        foreach ($keys as $key) {
            $this->sessionManager->removeTempData($session, $key);
        }
    }

    protected function matchPurpose(string $text): ?string
    {
        $map = [
            'loan' => ['loan', 'lend', 'borrow'],
            'advance' => ['advance', 'salary'],
            'deposit' => ['deposit', 'security', 'rent'],
            'business' => ['business', 'work', 'trade'],
            'personal' => ['personal', 'friend', 'family'],
            'other' => ['other'],
        ];

        foreach ($map as $purpose => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, $keyword)) {
                    return $purpose;
                }
            }
        }

        return null;
    }

    protected function matchDueDate(string $text): ?string
    {
        if (str_contains($text, 'week') && !str_contains($text, '2')) {
            return '1week';
        }
        if (str_contains($text, '2') && str_contains($text, 'week')) {
            return '2weeks';
        }
        if (str_contains($text, 'month') && !str_contains($text, '3') && !str_contains($text, '6')) {
            return '1month';
        }
        if (str_contains($text, '3') && str_contains($text, 'month')) {
            return '3months';
        }
        if (str_contains($text, '6') && str_contains($text, 'month')) {
            return '6months';
        }
        if (str_contains($text, 'no') || str_contains($text, 'none') || str_contains($text, 'open')) {
            return 'none';
        }

        return null;
    }
}