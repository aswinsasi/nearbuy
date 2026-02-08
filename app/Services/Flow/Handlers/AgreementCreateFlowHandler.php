<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\AgreementStep;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Services\Agreements\AgreementService;
use App\Services\WhatsApp\Messages\AgreementMessages;

/**
 * Agreement Create Flow Handler.
 *
 * 8-step creation flow with friendly bilingual conversation.
 *
 * FLOW (FR-AGR-01 to FR-AGR-08):
 * 1. Direction → Giving or Receiving
 * 2. Amount → Numeric input
 * 3. Name → Counterparty name
 * 4. Phone → 10-digit WhatsApp
 * 5. Purpose → 5 types via list
 * 6. Description → Optional details
 * 7. Due Date → 5 options via list
 * 8. Review → Confirm/Edit/Cancel
 *
 * @srs-ref FR-AGR-01 to FR-AGR-15
 */
class AgreementCreateFlowHandler extends AbstractFlowHandler
{
    public function __construct(
        \App\Services\Session\SessionManager $sessionManager,
        \App\Services\WhatsApp\WhatsAppService $whatsApp,
        protected AgreementService $agreementService,
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::AGREEMENT_CREATE;
    }

    protected function getSteps(): array
    {
        return array_map(fn($s) => $s->value, AgreementStep::createFlowSteps());
    }

    /*
    |--------------------------------------------------------------------------
    | Flow Entry
    |--------------------------------------------------------------------------
    */

    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sendButtons(
                $session->phone,
                "⚠️ *Register cheyyuka*\n\nAgreement undaakkaan munpu register cheyyuka.",
                [['id' => 'register', 'title' => '📝 Register']]
            );
            $this->goToMenu($session);
            return;
        }

        $this->clearTempData($session);
        $this->sessionManager->setFlowStep($session, FlowType::AGREEMENT_CREATE, AgreementStep::ASK_DIRECTION->value);
        $this->askDirection($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Message Handler
    |--------------------------------------------------------------------------
    */

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle navigation
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        if ($this->isBackButton($message)) {
            $this->handleBack($session);
            return;
        }

        $step = AgreementStep::tryFrom($session->current_step);

        if (!$step) {
            $this->start($session);
            return;
        }

        match ($step) {
            AgreementStep::ASK_DIRECTION => $this->handleDirection($message, $session),
            AgreementStep::ASK_AMOUNT => $this->handleAmount($message, $session),
            AgreementStep::ASK_NAME => $this->handleName($message, $session),
            AgreementStep::ASK_PHONE => $this->handlePhone($message, $session),
            AgreementStep::ASK_PURPOSE => $this->handlePurpose($message, $session),
            AgreementStep::ASK_DESCRIPTION => $this->handleDescription($message, $session),
            AgreementStep::ASK_DUE_DATE => $this->handleDueDate($message, $session),
            AgreementStep::REVIEW => $this->handleReview($message, $session),
            AgreementStep::DONE => $this->handlePostCreate($message, $session),
            default => $this->start($session),
        };
    }

    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $this->promptCurrentStep($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Direction (FR-AGR-01)
    |--------------------------------------------------------------------------
    */

    protected function askDirection(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            AgreementMessages::ASK_DIRECTION,
            AgreementMessages::getDirectionButtons()
        );
    }

    protected function handleDirection(IncomingMessage $message, ConversationSession $session): void
    {
        $direction = null;

        if ($message->isInteractive()) {
            $direction = $this->getSelectionId($message);
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (str_contains($text, 'giv') || str_contains($text, 'koduk')) {
                $direction = 'giving';
            } elseif (str_contains($text, 'receiv') || str_contains($text, 'vaang')) {
                $direction = 'receiving';
            }
        }

        if (!in_array($direction, ['giving', 'receiving'])) {
            $this->askDirection($session);
            return;
        }

        $this->setTempData($session, 'direction', $direction);
        $this->goToStep($session, AgreementStep::ASK_AMOUNT);
        $this->askAmount($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Amount (FR-AGR-02)
    |--------------------------------------------------------------------------
    */

    protected function askAmount(ConversationSession $session): void
    {
        $direction = $this->getTempData($session, 'direction');
        $dirText = $direction === 'giving' ? 'kodukkunnu' : 'vaangunnu';

        $msg = "💰 *Ethra amount?*\n\n" .
            "Nee *{$dirText}*.\n\n" .
            "Amount type cheyyuka:\n" .
            "_Eg: 20000_";

        $this->sendButtons($session->phone, $msg, [
            ['id' => 'back', 'title' => '⬅️ Back'],
        ]);
    }

    protected function handleAmount(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->askAmount($session);
            return;
        }

        $input = preg_replace('/[₹,\s]/', '', $message->text ?? '');

        if (!$this->agreementService->isValidAmount($input)) {
            $this->sendText($session->phone, AgreementMessages::ERROR_INVALID_AMOUNT);
            return;
        }

        $this->setTempData($session, 'amount', (float) $input);
        $this->goToStep($session, AgreementStep::ASK_NAME);
        $this->askName($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 3: Name (FR-AGR-03)
    |--------------------------------------------------------------------------
    */

    protected function askName(ConversationSession $session): void
    {
        $direction = $this->getTempData($session, 'direction');
        $otherRole = $direction === 'giving' ? 'kittunnathu' : 'thararuthu';

        $msg = "👤 *Aarude koode?*\n\n" .
            "Aaraanu {$otherRole}? Full name type cheyyuka:";

        $this->sendButtons($session->phone, $msg, [
            ['id' => 'back', 'title' => '⬅️ Back'],
        ]);
    }

    protected function handleName(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->askName($session);
            return;
        }

        $name = trim($message->text ?? '');

        if (mb_strlen($name) < 2 || mb_strlen($name) > 100) {
            $this->sendText($session->phone, AgreementMessages::ERROR_INVALID_NAME);
            return;
        }

        $this->setTempData($session, 'other_party_name', $name);
        $this->goToStep($session, AgreementStep::ASK_PHONE);
        $this->askPhone($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 4: Phone (FR-AGR-04)
    |--------------------------------------------------------------------------
    */

    protected function askPhone(ConversationSession $session): void
    {
        $name = $this->getTempData($session, 'other_party_name');

        $msg = AgreementMessages::format(AgreementMessages::ASK_PHONE, [
            'name' => $name,
        ]);

        $this->sendButtons($session->phone, $msg, [
            ['id' => 'back', 'title' => '⬅️ Back'],
        ]);
    }

    protected function handlePhone(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->askPhone($session);
            return;
        }

        $phone = trim($message->text ?? '');

        if (!$this->agreementService->isValidPhone($phone)) {
            $this->sendText($session->phone, AgreementMessages::ERROR_INVALID_PHONE);
            return;
        }

        // Check self-agreement
        $user = $this->getUser($session);
        $normalized = $this->agreementService->normalizePhone($phone);

        if ($normalized === $user->phone) {
            $this->sendText($session->phone, AgreementMessages::ERROR_SELF_AGREEMENT);
            return;
        }

        $this->setTempData($session, 'other_party_phone', $phone);
        $this->goToStep($session, AgreementStep::ASK_PURPOSE);
        $this->askPurpose($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 5: Purpose (FR-AGR-05)
    |--------------------------------------------------------------------------
    */

    protected function askPurpose(ConversationSession $session): void
    {
        $this->sendList(
            $session->phone,
            AgreementMessages::ASK_PURPOSE,
            '📋 Select Purpose',
            AgreementMessages::getPurposeSections()
        );
    }

    protected function handlePurpose(IncomingMessage $message, ConversationSession $session): void
    {
        $purpose = null;

        if ($message->isListReply()) {
            $purpose = $this->getSelectionId($message);
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            $purpose = $this->matchPurpose($text);
        }

        $valid = ['loan', 'advance', 'deposit', 'business', 'other'];
        if (!in_array($purpose, $valid)) {
            $this->askPurpose($session);
            return;
        }

        $this->setTempData($session, 'purpose', $purpose);
        $this->goToStep($session, AgreementStep::ASK_DESCRIPTION);
        $this->askDescription($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 6: Description (FR-AGR-06)
    |--------------------------------------------------------------------------
    */

    protected function askDescription(ConversationSession $session): void
    {
        $purpose = $this->getTempData($session, 'purpose');
        $hint = AgreementMessages::getDescriptionHint($purpose);

        $msg = AgreementMessages::format(AgreementMessages::ASK_DESCRIPTION, [
            'hint' => $hint,
        ]);

        $this->sendButtons($session->phone, $msg, AgreementMessages::getSkipButtons());
    }

    protected function handleDescription(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle skip
        if ($this->isSkipButton($message) || 
            ($message->isText() && strtolower(trim($message->text ?? '')) === 'skip')) {
            $this->setTempData($session, 'description', null);
            $this->goToStep($session, AgreementStep::ASK_DUE_DATE);
            $this->askDueDate($session);
            return;
        }

        if ($message->isText()) {
            $desc = trim($message->text ?? '');
            if (mb_strlen($desc) > 500) {
                $this->sendText($session->phone, "⚠️ 500 characters-ൽ kuravakkanam.");
                return;
            }
            $this->setTempData($session, 'description', $desc);
        }

        $this->goToStep($session, AgreementStep::ASK_DUE_DATE);
        $this->askDueDate($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 7: Due Date (FR-AGR-07)
    |--------------------------------------------------------------------------
    */

    protected function askDueDate(ConversationSession $session): void
    {
        $this->sendList(
            $session->phone,
            AgreementMessages::ASK_DUE_DATE,
            '📅 Select Due Date',
            AgreementMessages::getDueDateSections()
        );
    }

    protected function handleDueDate(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = null;

        if ($message->isListReply()) {
            $selection = $this->getSelectionId($message);
        } elseif ($message->isText()) {
            $selection = $this->matchDueDate(strtolower(trim($message->text ?? '')));
        }

        $valid = ['1week', '2weeks', '1month', '3months', 'none'];
        if (!in_array($selection, $valid)) {
            $this->askDueDate($session);
            return;
        }

        $dueDate = AgreementMessages::getDueDateFromSelection($selection);
        $this->setTempData($session, 'due_date', $dueDate?->toDateString());
        $this->setTempData($session, 'due_date_selection', $selection);

        $this->goToStep($session, AgreementStep::REVIEW);
        $this->showReview($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step 8: Review (FR-AGR-08)
    |--------------------------------------------------------------------------
    */

    protected function showReview(ConversationSession $session): void
    {
        $direction = $this->getTempData($session, 'direction');
        $amount = $this->getTempData($session, 'amount');
        $name = $this->getTempData($session, 'other_party_name');
        $phone = $this->getTempData($session, 'other_party_phone');
        $purpose = $this->getTempData($session, 'purpose');
        $description = $this->getTempData($session, 'description');
        $dueDateSelection = $this->getTempData($session, 'due_date_selection');

        $dueDate = AgreementMessages::getDueDateFromSelection($dueDateSelection ?? 'none');

        $msg = AgreementMessages::format(AgreementMessages::REVIEW, [
            'direction_icon' => AgreementMessages::getDirectionIcon($direction),
            'direction_text' => AgreementMessages::getDirectionText($direction),
            'amount' => number_format((float) $amount),
            'arrow' => AgreementMessages::getDirectionArrow($direction),
            'name' => $name,
            'phone' => $phone,
            'purpose' => AgreementMessages::getPurposeLabel($purpose),
            'description' => $description ?? 'None',
            'due_date' => AgreementMessages::formatDueDate($dueDate),
        ]);

        $this->sendButtons($session->phone, $msg, AgreementMessages::getReviewButtons());
    }

    protected function handleReview(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (in_array($text, ['confirm', 'yes', 'sheri', '1'])) {
                $action = 'confirm';
            } elseif (in_array($text, ['edit', 'change', '2'])) {
                $action = 'edit';
            } elseif (in_array($text, ['cancel', 'no', 'venda', '3'])) {
                $action = 'cancel';
            }
        }

        match ($action) {
            'confirm' => $this->createAgreement($session),
            'edit' => $this->restart($session),
            'cancel' => $this->cancel($session),
            default => $this->showReview($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Create Agreement
    |--------------------------------------------------------------------------
    */

    protected function createAgreement(ConversationSession $session): void
    {
        try {
            $user = $this->getUser($session);

            $dueDateString = $this->getTempData($session, 'due_date');
            $dueDate = $dueDateString ? \Carbon\Carbon::parse($dueDateString) : null;

            $agreement = $this->agreementService->createAgreement($user, [
                'direction' => $this->getTempData($session, 'direction'),
                'amount' => $this->getTempData($session, 'amount'),
                'other_party_name' => $this->getTempData($session, 'other_party_name'),
                'other_party_phone' => $this->getTempData($session, 'other_party_phone'),
                'purpose' => $this->getTempData($session, 'purpose'),
                'description' => $this->getTempData($session, 'description'),
                'due_date' => $dueDate,
            ]);

            $this->setTempData($session, 'created_agreement_id', $agreement->id);

            // Success message
            $direction = $this->getTempData($session, 'direction');
            $dirText = $direction === 'giving' ? '→' : '←';

            $msg = AgreementMessages::format(AgreementMessages::CREATED, [
                'agreement_number' => $agreement->agreement_number,
                'amount' => number_format((float) $agreement->amount),
                'direction' => $dirText,
                'name' => $agreement->to_name,
            ]);

            $this->sendButtons($session->phone, $msg, AgreementMessages::getPostCreateButtons());

            // Send confirmation request to counterparty
            $this->sendConfirmationRequest($agreement);

            $this->goToStep($session, AgreementStep::DONE);

            $this->logInfo('Agreement created', [
                'agreement_id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number,
            ]);

        } catch (\Exception $e) {
            $this->logError('Agreement creation failed', ['error' => $e->getMessage()]);
            $this->sendText($session->phone, "❌ *Error:* {$e->getMessage()}\n\nVeendum try cheyyuka.");
            $this->start($session);
        }
    }

    /**
     * Send confirmation request to counterparty (FR-AGR-12).
     */
    protected function sendConfirmationRequest($agreement): void
    {
        $creator = $agreement->creator;
        $direction = $agreement->direction ?? 'giving';

        // Invert direction for counterparty perspective
        $counterpartyDirection = $direction === 'giving' ? 'receiving' : 'giving';

        $purpose = is_object($agreement->purpose_type)
            ? $agreement->purpose_type->value
            : ($agreement->purpose_type ?? 'other');

        $dueDate = $agreement->due_date 
            ? \Carbon\Carbon::parse($agreement->due_date) 
            : null;

        $msg = AgreementMessages::format(AgreementMessages::CONFIRM_REQUEST, [
            'creator_name' => $creator->name ?? 'Someone',
            'direction_icon' => AgreementMessages::getDirectionIcon($counterpartyDirection, false),
            'direction_text' => AgreementMessages::getDirectionText($counterpartyDirection, false),
            'amount' => number_format((float) $agreement->amount),
            'purpose' => AgreementMessages::getPurposeLabel($purpose),
            'description' => $agreement->description ?? 'None',
            'due_date' => AgreementMessages::formatDueDate($dueDate),
            'agreement_number' => $agreement->agreement_number,
        ]);

        // Set up recipient's session for confirmation flow
        $recipientSession = $this->sessionManager->getOrCreate($agreement->to_phone);
        $this->sessionManager->setFlowStep(
            $recipientSession,
            FlowType::AGREEMENT_CONFIRM,
            AgreementStep::AWAITING_CONFIRM->value
        );
        $this->setTempData($recipientSession, 'confirm_agreement_id', $agreement->id);

        $this->sendButtons($agreement->to_phone, $msg, AgreementMessages::getConfirmButtons());
    }

    /*
    |--------------------------------------------------------------------------
    | Post-Create Handler
    |--------------------------------------------------------------------------
    */

    protected function handlePostCreate(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

        match ($action) {
            'view' => $this->viewCreatedAgreement($session),
            'another' => $this->start($session),
            default => $this->goToMenu($session),
        };
    }

    protected function viewCreatedAgreement(ConversationSession $session): void
    {
        // Switch to agreement list flow
        $this->goToFlow($session, FlowType::AGREEMENT_LIST, AgreementStep::MY_LIST->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    protected function handleBack(ConversationSession $session): void
    {
        $step = AgreementStep::tryFrom($session->current_step);
        $prev = $step?->previousStep();

        if ($prev) {
            $this->goToStep($session, $prev);
            $this->promptCurrentStep($session);
        } else {
            $this->start($session);
        }
    }

    protected function restart(ConversationSession $session): void
    {
        $this->sendText($session->phone, "🔄 Veendum thudangaam...");
        $this->start($session);
    }

    protected function cancel(ConversationSession $session): void
    {
        $this->clearTempData($session);
        $this->sendText($session->phone, "❌ *Cancelled*\n\nAgreement undaakkiyilla.");
        $this->goToMenu($session);
    }

    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = AgreementStep::tryFrom($session->current_step);

        match ($step) {
            AgreementStep::ASK_DIRECTION => $this->askDirection($session),
            AgreementStep::ASK_AMOUNT => $this->askAmount($session),
            AgreementStep::ASK_NAME => $this->askName($session),
            AgreementStep::ASK_PHONE => $this->askPhone($session),
            AgreementStep::ASK_PURPOSE => $this->askPurpose($session),
            AgreementStep::ASK_DESCRIPTION => $this->askDescription($session),
            AgreementStep::ASK_DUE_DATE => $this->askDueDate($session),
            AgreementStep::REVIEW => $this->showReview($session),
            default => $this->start($session),
        };
    }

    protected function goToStep(ConversationSession $session, AgreementStep $step): void
    {
        $this->sessionManager->setFlowStep($session, FlowType::AGREEMENT_CREATE, $step->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    protected function matchPurpose(string $text): ?string
    {
        $map = [
            'loan' => ['loan', 'lend', 'vaaypa', 'kadam'],
            'advance' => ['advance', 'salary', 'pani'],
            'deposit' => ['deposit', 'rent', 'vaadaka', 'booking'],
            'business' => ['business', 'supplier', 'vendor'],
            'other' => ['other', 'mattullava'],
        ];

        foreach ($map as $purpose => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($text, $kw)) return $purpose;
            }
        }

        return null;
    }

    protected function matchDueDate(string $text): ?string
    {
        if (str_contains($text, '1') && str_contains($text, 'week')) return '1week';
        if (str_contains($text, '2') && str_contains($text, 'week')) return '2weeks';
        if (str_contains($text, '1') && str_contains($text, 'month')) return '1month';
        if (str_contains($text, '3') && str_contains($text, 'month')) return '3months';
        if (str_contains($text, 'no') || str_contains($text, 'none') || str_contains($text, 'open')) return 'none';
        return null;
    }

    /**
     * Check if skip button was pressed.
     */
    protected function isSkipButton(IncomingMessage $message): bool
    {
        if ($message->isInteractive()) {
            return $this->getSelectionId($message) === 'skip';
        }
        return false;
    }

    /**
     * Check if back button was pressed.
     */
    protected function isBackButton(IncomingMessage $message): bool
    {
        if ($message->isInteractive()) {
            return $this->getSelectionId($message) === 'back';
        }
        if ($message->isText()) {
            return strtolower(trim($message->text ?? '')) === 'back';
        }
        return false;
    }
}