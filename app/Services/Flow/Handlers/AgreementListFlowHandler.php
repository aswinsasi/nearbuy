<?php

namespace App\Services\Flow\Handlers;

use App\Contracts\FlowHandlerInterface;
use App\DTOs\IncomingMessage;
use App\Enums\AgreementStep;
use App\Enums\AgreementStatus;
use App\Enums\FlowType;
use App\Models\Agreement;
use App\Models\ConversationSession;
use App\Services\Agreements\AgreementService;
use App\Services\PDF\AgreementPDFService;
use App\Services\Session\SessionManager;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\AgreementMessages;
use Illuminate\Support\Facades\Log;

/**
 * Handles the agreement list/management flow.
 *
 * Flow Steps:
 * 1. show_list - Show all agreements
 * 2. view_agreement - View specific agreement details
 * 3. mark_complete - Mark agreement as completed
 * 4. dispute - Handle dispute
 */
class AgreementListFlowHandler implements FlowHandlerInterface
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
        return FlowType::AGREEMENT_LIST->value;
    }

    /**
     * Check if can handle step.
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, [
            AgreementStep::SHOW_LIST->value,
            AgreementStep::VIEW_AGREEMENT->value,
            AgreementStep::MARK_COMPLETE->value,
            AgreementStep::DISPUTE->value,
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
                "⚠️ Please register first."
            );
            $this->sessionManager->resetToMainMenu($session);
            return;
        }

        $agreements = $this->agreementService->getAgreementsForUser($user);

        if ($agreements->isEmpty()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                AgreementMessages::MY_AGREEMENTS_EMPTY,
                AgreementMessages::getEmptyAgreementsButtons()
            );
            return;
        }

        $this->showAgreementsList($session, $agreements);
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
            AgreementStep::SHOW_LIST => $this->handleListSelection($message, $session),
            AgreementStep::VIEW_AGREEMENT => $this->handleAgreementAction($message, $session),
            AgreementStep::MARK_COMPLETE => $this->handleMarkCompleteConfirm($message, $session),
            default => $this->start($session),
        };
    }

    /**
     * Handle invalid input.
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $this->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    protected function handleListSelection(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isListReply()) {
            $selectionId = $message->getSelectionId();

            if (str_starts_with($selectionId, 'agreement_')) {
                $agreementId = (int) str_replace('agreement_', '', $selectionId);
                $this->sessionManager->setTempData($session, 'view_agreement_id', $agreementId);
                $this->sessionManager->setStep($session, AgreementStep::VIEW_AGREEMENT->value);
                $this->showAgreementDetail($session);
                return;
            }
        }

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();

            match ($action) {
                'create' => $this->goToCreate($session),
                'pending' => $this->goToPending($session),
                default => $this->goToMainMenu($session),
            };
        }
    }

    protected function handleAgreementAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'download_pdf' => $this->downloadPDF($session),
            'mark_complete' => $this->confirmMarkComplete($session),
            'remind' => $this->sendReminder($session),
            'cancel' => $this->cancelAgreement($session),
            'back' => $this->start($session),
            default => $this->showAgreementDetail($session),
        };
    }

    protected function handleMarkCompleteConfirm(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        if ($action === 'confirm_complete') {
            $this->markComplete($session);
        } else {
            $this->showAgreementDetail($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Display Methods
    |--------------------------------------------------------------------------
    */

    protected function showAgreementsList(ConversationSession $session, $agreements): void
    {
        $user = $this->sessionManager->getUser($session);

        $header = AgreementMessages::format(AgreementMessages::MY_AGREEMENTS_HEADER, [
            'count' => $agreements->count(),
        ]);

        $rows = [];
        foreach ($agreements as $agreement) {
            $statusIcon = AgreementMessages::getStatusIcon($agreement->status->value ?? 'pending');
            $amount = number_format($agreement->amount);

            // Determine other party name
            $otherPartyName = $agreement->creator_id === $user->id
                ? $agreement->counterparty_name
                : $agreement->creator->name ?? 'Unknown';

            $rows[] = [
                'id' => 'agreement_' . $agreement->id,
                'title' => mb_substr("{$statusIcon} ₹{$amount}", 0, 24),
                'description' => mb_substr("{$otherPartyName} • #{$agreement->agreement_number}", 0, 72),
            ];
        }

        $sections = [
            [
                'title' => 'Your Agreements',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '📋 View Details',
            $sections
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_LIST,
            AgreementStep::SHOW_LIST->value
        );
    }

    protected function showAgreementDetail(ConversationSession $session): void
    {
        $agreementId = $this->sessionManager->getTempData($session, 'view_agreement_id');
        $agreement = Agreement::with(['creator', 'counterpartyUser'])->find($agreementId);

        if (!$agreement) {
            $this->whatsApp->sendText($session->phone, AgreementMessages::ERROR_AGREEMENT_NOT_FOUND);
            $this->start($session);
            return;
        }

        $user = $this->sessionManager->getUser($session);
        $isCreator = $agreement->creator_id === $user->id;

        // Determine direction from user's perspective
        $isUserCreditor = $agreement->creditor_id === $user->id;
        $direction = $isUserCreditor ? 'receiving' : 'giving';

        $otherPartyName = $isCreator
            ? $agreement->counterparty_name
            : $agreement->creator->name ?? 'Unknown';

        $otherPartyPhone = $isCreator
            ? $agreement->counterparty_phone
            : $agreement->creator->phone ?? 'Unknown';

        $message = AgreementMessages::format(AgreementMessages::AGREEMENT_DETAIL, [
            'agreement_number' => $agreement->agreement_number,
            'direction_emoji' => AgreementMessages::getDirectionEmoji($direction),
            'direction' => AgreementMessages::getDirectionLabel($direction),
            'other_party_name' => $otherPartyName,
            'other_party_phone' => $this->formatPhone($otherPartyPhone),
            'amount' => number_format($agreement->amount),
            'amount_words' => $agreement->amount_words ?? '',
            'purpose' => AgreementMessages::getPurposeLabel($agreement->purpose->value ?? 'other'),
            'due_date' => AgreementMessages::formatDueDate($agreement->due_date),
            'status' => AgreementMessages::getStatusLabel($agreement->status->value ?? 'pending'),
            'description' => $agreement->description ?? 'None',
        ]);

        $this->whatsApp->sendText($session->phone, $message);

        // Determine available actions based on status and role
        $buttons = $this->getActionButtons($agreement, $user);

        $this->whatsApp->sendButtons(
            $session->phone,
            "What would you like to do?",
            $buttons
        );

        $this->sessionManager->setStep($session, AgreementStep::VIEW_AGREEMENT->value);
    }

    protected function getActionButtons(Agreement $agreement, $user): array
    {
        $isCreator = $agreement->creator_id === $user->id;
        $isCreditor = $agreement->creditor_id === $user->id;
        $status = $agreement->status;

        $buttons = [];

        // PDF download for confirmed agreements
        if ($status === AgreementStatus::CONFIRMED && $agreement->pdf_url) {
            $buttons[] = ['id' => 'download_pdf', 'title' => '📄 Download PDF'];
        }

        // Mark complete (only creditor can mark as complete)
        if ($status === AgreementStatus::CONFIRMED && $isCreditor) {
            $buttons[] = ['id' => 'mark_complete', 'title' => '✅ Mark Complete'];
        }

        // Send reminder (only creator, only if pending)
        if ($status === AgreementStatus::PENDING && $isCreator) {
            $buttons[] = ['id' => 'remind', 'title' => '🔔 Send Reminder'];
        }

        // Cancel (only creator, only if pending)
        if ($status === AgreementStatus::PENDING && $isCreator) {
            $buttons[] = ['id' => 'cancel', 'title' => '❌ Cancel'];
        }

        // Always show back
        $buttons[] = ['id' => 'back', 'title' => '⬅️ Back'];

        return array_slice($buttons, 0, 3); // WhatsApp limit
    }

    /*
    |--------------------------------------------------------------------------
    | Action Methods
    |--------------------------------------------------------------------------
    */

    protected function downloadPDF(ConversationSession $session): void
    {
        $agreementId = $this->sessionManager->getTempData($session, 'view_agreement_id');
        $agreement = Agreement::find($agreementId);

        if (!$agreement || !$agreement->pdf_url) {
            $this->whatsApp->sendText($session->phone, "❌ PDF not available for this agreement.");
            $this->showAgreementDetail($session);
            return;
        }

        $this->whatsApp->sendDocument(
            $session->phone,
            $agreement->pdf_url,
            "Agreement_{$agreement->agreement_number}.pdf",
            "📄 Your agreement document"
        );

        $this->showAgreementDetail($session);
    }

    protected function confirmMarkComplete(ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $session->phone,
            "✅ *Mark as Complete?*\n\nThis will mark the agreement as settled. Are you sure?",
            [
                ['id' => 'confirm_complete', 'title' => '✅ Yes, Complete'],
                ['id' => 'cancel_complete', 'title' => '❌ Cancel'],
            ]
        );

        $this->sessionManager->setStep($session, AgreementStep::MARK_COMPLETE->value);
    }

    protected function markComplete(ConversationSession $session): void
    {
        try {
            $agreementId = $this->sessionManager->getTempData($session, 'view_agreement_id');
            $agreement = Agreement::find($agreementId);
            $user = $this->sessionManager->getUser($session);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            $agreement = $this->agreementService->markCompleted($agreement, $user);

            $this->whatsApp->sendText(
                $session->phone,
                "✅ *Agreement Completed*\n\nAgreement #{$agreement->agreement_number} has been marked as complete."
            );

            // Notify other party
            $otherPartyPhone = $agreement->creator_id === $user->id
                ? $agreement->counterparty_phone
                : $agreement->creator->phone;

            $this->whatsApp->sendText(
                $otherPartyPhone,
                "✅ *Agreement Completed*\n\nAgreement #{$agreement->agreement_number} has been marked as complete by {$user->name}."
            );

            $this->start($session);

        } catch (\Exception $e) {
            $this->whatsApp->sendText(
                $session->phone,
                "❌ Failed: " . $e->getMessage()
            );
            $this->showAgreementDetail($session);
        }
    }

    protected function sendReminder(ConversationSession $session): void
    {
        $agreementId = $this->sessionManager->getTempData($session, 'view_agreement_id');
        $agreement = Agreement::with('creator')->find($agreementId);

        if (!$agreement) {
            $this->start($session);
            return;
        }

        // Resend confirmation request
        $creator = $agreement->creator;
        $direction = $agreement->creditor_id === $creator->id ? 'giving' : 'receiving';
        $counterpartyDirection = $direction === 'giving' ? 'receiving' : 'giving';

        $message = "🔔 *Reminder: Agreement Confirmation*\n\n" .
            AgreementMessages::format(AgreementMessages::CONFIRM_REQUEST, [
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
            $agreement->counterparty_phone,
            $message,
            AgreementMessages::getConfirmButtons()
        );

        $this->agreementService->markReminderSent($agreement);

        $this->whatsApp->sendText($session->phone, "✅ Reminder sent to {$agreement->counterparty_name}.");

        $this->showAgreementDetail($session);
    }

    protected function cancelAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->sessionManager->getTempData($session, 'view_agreement_id');
            $agreement = Agreement::find($agreementId);
            $user = $this->sessionManager->getUser($session);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            $agreement = $this->agreementService->cancelAgreement($agreement, $user);

            $this->whatsApp->sendText(
                $session->phone,
                "❌ Agreement #{$agreement->agreement_number} has been cancelled."
            );

            $this->start($session);

        } catch (\Exception $e) {
            $this->whatsApp->sendText(
                $session->phone,
                "❌ Failed: " . $e->getMessage()
            );
            $this->showAgreementDetail($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation Methods
    |--------------------------------------------------------------------------
    */

    protected function goToCreate(ConversationSession $session): void
    {
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_CREATE,
            AgreementStep::ASK_DIRECTION->value
        );

        app(AgreementCreateFlowHandler::class)->start($session);
    }

    protected function goToPending(ConversationSession $session): void
    {
        app(AgreementConfirmFlowHandler::class)->start($session);
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

    protected function formatPhone(string $phone): string
    {
        if (strlen($phone) === 12 && str_starts_with($phone, '91')) {
            $phone = substr($phone, 2);
        }

        if (strlen($phone) === 10) {
            return substr($phone, 0, 3) . '-' . substr($phone, 3, 3) . '-' . substr($phone, 6);
        }

        return $phone;
    }
}