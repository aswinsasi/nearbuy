<?php

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\AgreementStep;
use App\Enums\AgreementStatus;
use App\Enums\FlowType;
use App\Jobs\GenerateAgreementPDF;
use App\Models\Agreement;
use App\Models\ConversationSession;
use App\Services\Agreements\AgreementService;
use App\Services\WhatsApp\Messages\AgreementMessages;
use App\Services\WhatsApp\Messages\MessageTemplates;

/**
 * ENHANCED Agreement Confirmation Flow Handler.
 *
 * Key improvements:
 * 1. Uses Job for async PDF generation (no timeout!)
 * 2. Uses sendTextWithMenu/sendButtonsWithMenu patterns
 * 3. Better error messages with recovery options
 * 4. Consistent footer on all messages
 */
class AgreementConfirmFlowHandler extends AbstractFlowHandler
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
        return FlowType::AGREEMENT_CONFIRM;
    }

    protected function getSteps(): array
    {
        return [
            AgreementStep::SHOW_PENDING->value,
            AgreementStep::VIEW_PENDING->value,
            AgreementStep::CONFIRM_AGREEMENT->value,
            AgreementStep::CONFIRMATION_COMPLETE->value,
        ];
    }

    /**
     * Start the flow - show pending confirmations.
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

        // Get pending confirmations
        $pending = $this->agreementService->getPendingConfirmations($user);

        if ($pending->isEmpty()) {
            $this->sendButtonsWithMenu(
                $session->phone,
                "✅ *No Pending Confirmations*\n\nYou're all caught up! No agreements waiting for your confirmation.",
                [['id' => 'my_agreements', 'title' => '📋 My Agreements']],
                '📋 Agreements'
            );
            return;
        }

        $this->showPendingList($session, $pending);
    }

    /**
     * Start with a specific agreement (from notification).
     */
    public function startWithAgreement(ConversationSession $session, Agreement $agreement): void
    {
        $this->setTemp($session, 'confirm_agreement_id', $agreement->id);

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_CONFIRM,
            AgreementStep::CONFIRM_AGREEMENT->value
        );

        $this->showAgreementForConfirmation($session, $agreement);
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
            AgreementStep::SHOW_PENDING => $this->handlePendingSelection($message, $session),
            AgreementStep::VIEW_PENDING => $this->handleViewAction($message, $session),
            AgreementStep::CONFIRM_AGREEMENT => $this->handleConfirmationChoice($message, $session),
            AgreementStep::CONFIRMATION_COMPLETE => $this->handlePostConfirmation($message, $session),
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
            AgreementStep::CONFIRM_AGREEMENT => $this->showConfirmationButtons($session),
            default => $this->start($session),
        };
    }

    /**
     * Get expected input type.
     */
    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            AgreementStep::SHOW_PENDING->value => 'list',
            AgreementStep::CONFIRM_AGREEMENT->value => 'button',
            default => 'button',
        };
    }

    /**
     * Re-prompt current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = AgreementStep::tryFrom($session->current_step);

        match ($step) {
            AgreementStep::CONFIRM_AGREEMENT => $this->showConfirmationButtons($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    protected function handlePendingSelection(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isListReply()) {
            $selectionId = $this->getSelectionId($message);

            if (str_starts_with($selectionId, 'pending_')) {
                $agreementId = (int) str_replace('pending_', '', $selectionId);
                $this->setTemp($session, 'confirm_agreement_id', $agreementId);
                $this->nextStep($session, AgreementStep::VIEW_PENDING->value);
                $this->showPendingDetail($session);
                return;
            }
        }

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);

            match ($action) {
                'my_agreements' => $this->goToAgreementList($session),
                default => $this->goToMainMenu($session),
            };
            return;
        }

        // Default: re-show list
        $this->start($session);
    }

    protected function handleViewAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

        match ($action) {
            'confirm' => $this->proceedToConfirmation($session),
            'back' => $this->start($session),
            default => $this->showPendingDetail($session),
        };
    }

    protected function handleConfirmationChoice(IncomingMessage $message, ConversationSession $session): void
    {
        $choice = null;

        if ($message->isInteractive()) {
            $choice = $this->getSelectionId($message);
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (in_array($text, ['yes', 'confirm', '1'])) {
                $choice = 'confirm';
            } elseif (in_array($text, ['no', 'reject', 'incorrect', '2'])) {
                $choice = 'reject';
            } elseif (in_array($text, ['unknown', 'dont know', "don't know", '3'])) {
                $choice = 'unknown';
            }
        }

        match ($choice) {
            'confirm' => $this->confirmAgreement($session),
            'reject' => $this->rejectAgreement($session),
            'unknown' => $this->disputeAgreement($session),
            default => $this->handleInvalidInput($message, $session),
        };
    }

    protected function handlePostConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

        match ($action) {
            'more_pending' => $this->start($session),
            'my_agreements' => $this->goToAgreementList($session),
            default => $this->goToMainMenu($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Display Methods
    |--------------------------------------------------------------------------
    */

    protected function showPendingList(ConversationSession $session, $pending): void
    {
        $count = $pending->count();
        $header = "⏳ *Pending Confirmations*\n\n" .
            "You have *{$count}* agreement(s) waiting for your confirmation:";

        $rows = [];
        foreach ($pending as $agreement) {
            $amount = number_format($agreement->amount);
            $creator = $agreement->creator->name ?? $agreement->from_name ?? 'Unknown';

            $rows[] = [
                'id' => 'pending_' . $agreement->id,
                'title' => mb_substr("₹{$amount} from {$creator}", 0, 24),
                'description' => mb_substr("⏳ #{$agreement->agreement_number}", 0, 72),
            ];
        }

        $sections = [
            [
                'title' => 'Tap to Review',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];

        $this->sendListWithFooter(
            $session->phone,
            $header,
            '📋 View Agreements',
            $sections,
            '📋 Pending'
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_CONFIRM,
            AgreementStep::SHOW_PENDING->value
        );
    }

    protected function showPendingDetail(ConversationSession $session): void
    {
        $agreementId = $this->getTemp($session, 'confirm_agreement_id');
        $agreement = Agreement::with('creator')->find($agreementId);

        if (!$agreement) {
            $this->sendErrorWithOptions(
                $session->phone,
                "❌ *Agreement Not Found*\n\nThis agreement may have been cancelled or expired.",
                [
                    ['id' => 'more_pending', 'title' => '📋 View Pending'],
                    self::MENU_BUTTON,
                ]
            );
            $this->start($session);
            return;
        }

        $this->showAgreementForConfirmation($session, $agreement);
    }

    protected function showAgreementForConfirmation(ConversationSession $session, Agreement $agreement): void
    {
        $creator = $agreement->creator;
        
        // Determine direction from counterparty's perspective
        $direction = $agreement->direction->value ?? 'giving';
        $counterpartyDirection = $direction === 'giving' ? 'receiving' : 'giving';

        // Get purpose value
        $purposeValue = is_object($agreement->purpose_type) 
            ? $agreement->purpose_type->value 
            : ($agreement->purpose_type ?? $agreement->purpose ?? 'other');

        $message = "📋 *Agreement Confirmation Request*\n\n" .
            "*{$creator->name}* wants to record this agreement with you:\n\n" .
            AgreementMessages::getDirectionEmoji($counterpartyDirection) . 
            " *" . AgreementMessages::getDirectionLabel($counterpartyDirection, false) . "*\n\n" .
            "💰 *Amount:* ₹" . number_format($agreement->amount) . "\n" .
            "📝 *Purpose:* " . AgreementMessages::getPurposeLabel($purposeValue) . "\n" .
            "📅 *Due Date:* " . AgreementMessages::formatDueDate($agreement->due_date) . "\n" .
            "📄 *Description:* " . ($agreement->description ?? 'None') . "\n\n" .
            "📋 Agreement #: *{$agreement->agreement_number}*\n\n" .
            "⚠️ *Is this correct?*";

        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'confirm', 'title' => '✅ Yes, Confirm'],
                ['id' => 'reject', 'title' => '❌ No, Incorrect'],
                ['id' => 'unknown', 'title' => "❓ Don't Know"],
            ],
            '📋 Confirm Agreement',
            MessageTemplates::GLOBAL_FOOTER
        );

        $this->nextStep($session, AgreementStep::CONFIRM_AGREEMENT->value);
    }

    protected function showConfirmationButtons(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "Please select an option:\n\n" .
            "✅ *Yes, Confirm* - The details are correct\n" .
            "❌ *No, Incorrect* - Something is wrong\n" .
            "❓ *Don't Know* - I don't know this person",
            [
                ['id' => 'confirm', 'title' => '✅ Yes, Confirm'],
                ['id' => 'reject', 'title' => '❌ No, Incorrect'],
                ['id' => 'unknown', 'title' => "❓ Don't Know"],
            ],
            null,
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Action Methods
    |--------------------------------------------------------------------------
    */

    protected function proceedToConfirmation(ConversationSession $session): void
    {
        $this->nextStep($session, AgreementStep::CONFIRM_AGREEMENT->value);
        $this->showConfirmationButtons($session);
    }

    protected function confirmAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->getTemp($session, 'confirm_agreement_id');
            $agreement = Agreement::with(['creator'])->find($agreementId);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            // Link counterparty user if not already linked
            $user = $this->getUser($session);
            if (!$agreement->to_user_id && $user) {
                $agreement->update(['to_user_id' => $user->id]);
            }

            // Confirm the agreement
            $agreement = $this->agreementService->confirmByCounterparty($agreement);

            // Send immediate success message
            $this->sendButtons(
                $session->phone,
                "🎉 *Agreement Confirmed!*\n\n" .
                "📋 Agreement #: *{$agreement->agreement_number}*\n\n" .
                "✅ Both parties have now confirmed.\n\n" .
                "📄 *Generating your PDF document...*\n" .
                "_This will be sent to you in a moment._",
                [
                    ['id' => 'more_pending', 'title' => '📋 More Pending'],
                    ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                    self::MENU_BUTTON,
                ],
                '✅ Confirmed',
                MessageTemplates::GLOBAL_FOOTER
            );

            // DISPATCH JOB for PDF generation (async - won't timeout!)
            GenerateAgreementPDF::dispatch($agreement, notifyParties: true);

            // Notify creator immediately (PDF will follow from Job)
            $this->notifyCreatorConfirmed($agreement);

            $this->nextStep($session, AgreementStep::CONFIRMATION_COMPLETE->value);

            $this->logInfo('Agreement confirmed by counterparty', [
                'agreement_id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number,
            ]);

        } catch (\Exception $e) {
            $this->logError('Agreement confirmation failed', [
                'error' => $e->getMessage(),
            ]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ *Confirmation Failed*\n\n" . $e->getMessage() . "\n\nPlease try again.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );

            $this->start($session);
        }
    }

    protected function rejectAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->getTemp($session, 'confirm_agreement_id');
            $agreement = Agreement::with(['creator'])->find($agreementId);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            $agreement = $this->agreementService->rejectByCounterparty($agreement, 'Details are incorrect');

            $this->sendButtonsWithMenu(
                $session->phone,
                "❌ *Agreement Rejected*\n\n" .
                "You have rejected this agreement.\n\n" .
                "*{$agreement->creator->name}* will be notified.\n\n" .
                "If this was a mistake, please contact them directly.",
                [['id' => 'more_pending', 'title' => '📋 More Pending']],
                '❌ Rejected'
            );

            // Notify creator
            $this->notifyCreatorRejected($agreement);

            $this->nextStep($session, AgreementStep::CONFIRMATION_COMPLETE->value);

        } catch (\Exception $e) {
            $this->logError('Agreement rejection failed', ['error' => $e->getMessage()]);
            $this->sendErrorWithOptions($session->phone, "❌ Failed to reject: " . $e->getMessage());
            $this->start($session);
        }
    }

    protected function disputeAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->getTemp($session, 'confirm_agreement_id');
            $agreement = Agreement::with(['creator'])->find($agreementId);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            $agreement = $this->agreementService->markDisputed($agreement);

            $this->sendButtonsWithMenu(
                $session->phone,
                "⚠️ *Agreement Flagged*\n\n" .
                "You've indicated you don't know *{$agreement->creator->name}*.\n\n" .
                "This agreement has been flagged for review.\n" .
                "They will be notified.",
                [['id' => 'more_pending', 'title' => '📋 More Pending']],
                '⚠️ Flagged'
            );

            // Notify creator
            $this->notifyCreatorDisputed($agreement);

            $this->nextStep($session, AgreementStep::CONFIRMATION_COMPLETE->value);

        } catch (\Exception $e) {
            $this->logError('Agreement dispute failed', ['error' => $e->getMessage()]);
            $this->sendErrorWithOptions($session->phone, "❌ Error: " . $e->getMessage());
            $this->start($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Notification Methods
    |--------------------------------------------------------------------------
    */

    protected function notifyCreatorConfirmed(Agreement $agreement): void
    {
        $creator = $agreement->creator;
        if (!$creator?->phone) return;

        $message = "🎉 *Agreement Confirmed!*\n\n" .
            "*{$agreement->to_name}* has confirmed your agreement.\n\n" .
            "📋 Agreement #: *{$agreement->agreement_number}*\n\n" .
            "📄 *PDF document will be sent shortly...*";

        $this->sendButtons(
            $creator->phone,
            $message,
            [
                ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                self::MENU_BUTTON,
            ],
            '✅ Confirmed',
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    protected function notifyCreatorRejected(Agreement $agreement): void
    {
        $creator = $agreement->creator;
        if (!$creator?->phone) return;

        $message = "❌ *Agreement Rejected*\n\n" .
            "*{$agreement->to_name}* has rejected your agreement.\n\n" .
            "📋 Agreement #: *{$agreement->agreement_number}*\n" .
            "📝 Reason: Details are incorrect\n\n" .
            "Please verify the details and create a new agreement if needed.";

        $this->sendButtonsWithMenu(
            $creator->phone,
            $message,
            [
                ['id' => 'create_agreement', 'title' => '📝 Create New'],
                ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
            ],
            '❌ Rejected'
        );
    }

    protected function notifyCreatorDisputed(Agreement $agreement): void
    {
        $creator = $agreement->creator;
        if (!$creator?->phone) return;

        $message = "⚠️ *Agreement Disputed*\n\n" .
            "*{$agreement->to_name}* claims they don't know you.\n\n" .
            "📋 Agreement #: *{$agreement->agreement_number}*\n\n" .
            "This agreement has been flagged.\n" .
            "Please ensure you have the correct contact details.";

        $this->sendButtonsWithMenu(
            $creator->phone,
            $message,
            [['id' => 'my_agreements', 'title' => '📋 My Agreements']],
            '⚠️ Disputed'
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation Methods
    |--------------------------------------------------------------------------
    */

    protected function goToAgreementList(ConversationSession $session): void
    {
        $this->goToFlow($session, FlowType::AGREEMENT_LIST, AgreementStep::SHOW_LIST->value);
        // Would need AgreementListFlowHandler - trigger via FlowRouter
        app(\App\Services\Flow\FlowRouter::class)->startFlow($session, FlowType::AGREEMENT_LIST);
    }
}