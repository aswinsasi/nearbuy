<?php

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\AgreementStep;
use App\Enums\AgreementStatus;
use App\Enums\FlowType;
use App\Models\Agreement;
use App\Models\ConversationSession;
use App\Services\Agreements\AgreementService;
use App\Services\PDF\AgreementPDFService;
use App\Services\WhatsApp\Messages\AgreementMessages;
use App\Services\WhatsApp\Messages\MessageTemplates;

/**
 * ENHANCED Agreement List Flow Handler.
 *
 * Key improvements:
 * 1. Extends AbstractFlowHandler for consistent menu buttons
 * 2. Uses sendTextWithMenu/sendButtonsWithMenu patterns
 * 3. Main Menu button on all messages
 */
class AgreementListFlowHandler extends AbstractFlowHandler
{
    public function __construct(
        \App\Services\Session\SessionManager $sessionManager,
        \App\Services\WhatsApp\WhatsAppService $whatsApp,
        protected AgreementService $agreementService,
        protected AgreementPDFService $pdfService,
    ) {
        parent::__construct($sessionManager, $whatsApp);
    }

    protected function getFlowType(): FlowType
    {
        return FlowType::AGREEMENT_LIST;
    }

    protected function getSteps(): array
    {
        return [
            AgreementStep::SHOW_LIST->value,
            AgreementStep::VIEW_AGREEMENT->value,
            AgreementStep::MARK_COMPLETE->value,
            AgreementStep::DISPUTE->value,
        ];
    }

    /**
     * Start the flow.
     */
    public function start(ConversationSession $session): void
    {
        $user = $this->getUser($session);

        if (!$user) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "⚠️ *Registration Required*\n\nPlease register first to view agreements.",
                [['id' => 'register', 'title' => '📝 Register']]
            );
            $this->goToMainMenu($session);
            return;
        }

        $agreements = $this->agreementService->getAgreementsForUser($user);

        if ($agreements->isEmpty()) {
            $this->sendButtonsWithMenu(
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
        // Handle common navigation (menu, cancel, etc.)
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

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

    /**
     * Get expected input type.
     */
    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            AgreementStep::SHOW_LIST->value => 'list',
            default => 'button',
        };
    }

    /**
     * Re-prompt current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
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
            $selectionId = $this->getSelectionId($message);

            if (str_starts_with($selectionId, 'agreement_')) {
                $agreementId = (int) str_replace('agreement_', '', $selectionId);
                $this->setTemp($session, 'view_agreement_id', $agreementId);
                $this->nextStep($session, AgreementStep::VIEW_AGREEMENT->value);
                $this->showAgreementDetail($session);
                return;
            }
        }

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);

            match ($action) {
                'create' => $this->goToCreate($session),
                'pending' => $this->goToPending($session),
                default => $this->goToMainMenu($session),
            };
            return;
        }

        // Default: re-show list
        $this->start($session);
    }

    protected function handleAgreementAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

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
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

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
        $user = $this->getUser($session);

        $header = AgreementMessages::format(AgreementMessages::MY_AGREEMENTS_HEADER, [
            'count' => $agreements->count(),
        ]);

        $rows = [];
        foreach ($agreements as $agreement) {
            $statusIcon = AgreementMessages::getStatusIcon($agreement->status->value ?? 'pending');
            $amount = number_format($agreement->amount);

            // Determine other party name
            $otherPartyName = $agreement->creator_id === $user->id
                ? $agreement->to_name
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

        $this->sendListWithFooter(
            $session->phone,
            $header,
            '📋 View Details',
            $sections,
            '📋 My Agreements'
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_LIST,
            AgreementStep::SHOW_LIST->value
        );
    }

    protected function showAgreementDetail(ConversationSession $session): void
    {
        $agreementId = $this->getTemp($session, 'view_agreement_id');
        $agreement = Agreement::with(['fromUser', 'toUser'])->find($agreementId);

        if (!$agreement) {
            $this->sendErrorWithOptions(
                $session->phone,
                AgreementMessages::ERROR_AGREEMENT_NOT_FOUND,
                [
                    ['id' => 'back', 'title' => '⬅️ Back'],
                    self::MENU_BUTTON,
                ]
            );
            $this->start($session);
            return;
        }

        $user = $this->getUser($session);
        $isCreator = $agreement->creator_id === $user->id;

        // Determine direction from user's perspective
        $isUserCreditor = $agreement->creditor_id === $user->id;
        $direction = $isUserCreditor ? 'receiving' : 'giving';

        $otherPartyName = $isCreator
            ? $agreement->to_name
            : $agreement->creator->name ?? 'Unknown';

        $otherPartyPhone = $isCreator
            ? $agreement->to_phone
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

        $this->sendTextWithMenu($session->phone, $message);

        // Determine available actions based on status and role
        $buttons = $this->getActionButtons($agreement, $user);

        $this->sendButtons(
            $session->phone,
            "What would you like to do?",
            $buttons,
            null,
            MessageTemplates::GLOBAL_FOOTER
        );

        $this->nextStep($session, AgreementStep::VIEW_AGREEMENT->value);
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

        // Always show back and menu
        if (count($buttons) < 2) {
            $buttons[] = ['id' => 'back', 'title' => '⬅️ Back'];
        }
        if (count($buttons) < 3) {
            $buttons[] = self::MENU_BUTTON;
        }

        return array_slice($buttons, 0, 3); // WhatsApp limit
    }

    /*
    |--------------------------------------------------------------------------
    | Action Methods
    |--------------------------------------------------------------------------
    */

    protected function downloadPDF(ConversationSession $session): void
    {
        $agreementId = $this->getTemp($session, 'view_agreement_id');
        $agreement = Agreement::find($agreementId);

        if (!$agreement || !$agreement->pdf_url) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ PDF not available for this agreement.",
                [
                    ['id' => 'back', 'title' => '⬅️ Back'],
                    self::MENU_BUTTON,
                ]
            );
            $this->showAgreementDetail($session);
            return;
        }

        $this->sendDocumentWithFollowUp(
            $session->phone,
            $agreement->pdf_url,
            "Agreement_{$agreement->agreement_number}.pdf",
            "📄 Your agreement document",
            [['id' => 'back', 'title' => '⬅️ Back']]
        );
    }

    protected function confirmMarkComplete(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "✅ *Mark as Complete?*\n\nThis will mark the agreement as settled. Are you sure?",
            [
                ['id' => 'confirm_complete', 'title' => '✅ Yes, Complete'],
                ['id' => 'cancel_complete', 'title' => '❌ Cancel'],
                self::MENU_BUTTON,
            ],
            null,
            MessageTemplates::GLOBAL_FOOTER
        );

        $this->nextStep($session, AgreementStep::MARK_COMPLETE->value);
    }

    protected function markComplete(ConversationSession $session): void
    {
        try {
            $agreementId = $this->getTemp($session, 'view_agreement_id');
            $agreement = Agreement::find($agreementId);
            $user = $this->getUser($session);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            $agreement = $this->agreementService->markCompleted($agreement, $user);

            $this->sendButtonsWithMenu(
                $session->phone,
                "✅ *Agreement Completed*\n\nAgreement #{$agreement->agreement_number} has been marked as complete.",
                [['id' => 'my_agreements', 'title' => '📋 My Agreements']]
            );

            // Notify other party
            $otherPartyPhone = $agreement->creator_id === $user->id
                ? $agreement->to_phone
                : $agreement->creator->phone;

            $this->sendButtonsWithMenu(
                $otherPartyPhone,
                "✅ *Agreement Completed*\n\nAgreement #{$agreement->agreement_number} has been marked as complete by {$user->name}.",
                [['id' => 'my_agreements', 'title' => '📋 My Agreements']]
            );

            $this->start($session);

        } catch (\Exception $e) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed: " . $e->getMessage(),
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );
            $this->showAgreementDetail($session);
        }
    }

    protected function sendReminder(ConversationSession $session): void
    {
        $agreementId = $this->getTemp($session, 'view_agreement_id');
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

        $this->sendButtons(
            $agreement->to_phone,
            $message,
            AgreementMessages::getConfirmButtons(),
            null,
            MessageTemplates::GLOBAL_FOOTER
        );

        $this->agreementService->markReminderSent($agreement);

        $this->sendButtonsWithMenu(
            $session->phone,
            "✅ Reminder sent to {$agreement->to_name}.",
            [['id' => 'back', 'title' => '⬅️ Back']]
        );

        $this->showAgreementDetail($session);
    }

    protected function cancelAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->getTemp($session, 'view_agreement_id');
            $agreement = Agreement::find($agreementId);
            $user = $this->getUser($session);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            $agreement = $this->agreementService->cancelAgreement($agreement, $user);

            $this->sendButtonsWithMenu(
                $session->phone,
                "❌ Agreement #{$agreement->agreement_number} has been cancelled.",
                [['id' => 'my_agreements', 'title' => '📋 My Agreements']]
            );

            $this->start($session);

        } catch (\Exception $e) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ Failed: " . $e->getMessage(),
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
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
        $this->goToFlow($session, FlowType::AGREEMENT_CREATE, AgreementStep::ASK_DIRECTION->value);
        app(AgreementCreateFlowHandler::class)->start($session);
    }

    protected function goToPending(ConversationSession $session): void
    {
        app(AgreementConfirmFlowHandler::class)->start($session);
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