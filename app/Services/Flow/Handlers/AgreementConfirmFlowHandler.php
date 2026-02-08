<?php

declare(strict_types=1);

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

/**
 * Agreement Confirmation Flow Handler.
 *
 * Handles counterparty confirmation of agreements.
 *
 * KEY FEATURE: Works for UNREGISTERED users (FR-AGR-13)!
 * - Counterparty doesn't need to be registered
 * - We track by phone number, not user ID
 * - They can still confirm/reject via WhatsApp
 *
 * @srs-ref FR-AGR-10 to FR-AGR-15 (Confirmation flow)
 * @srs-ref FR-AGR-20 to FR-AGR-25 (PDF generation)
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
        return array_map(fn($s) => $s->value, AgreementStep::confirmFlowSteps());
    }

    /*
    |--------------------------------------------------------------------------
    | Flow Entry Points
    |--------------------------------------------------------------------------
    */

    /**
     * Start the flow - show pending confirmations.
     */
    public function start(ConversationSession $session): void
    {
        // NOTE: We use phone number, NOT user ID (FR-AGR-13)
        // This works for unregistered users too!
        $phone = $session->phone;

        // Get pending confirmations for this phone
        $pending = Agreement::awaitingConfirmationFrom($phone)
            ->notExpired()
            ->with('creator')
            ->orderByDesc('created_at')
            ->get();

        if ($pending->isEmpty()) {
            $this->sendButtons(
                $phone,
                "✅ *No Pending Confirmations*\n\n" .
                "Ninakku confirm cheyyaan ulla agreements onnum illa.\n" .
                "_No agreements waiting for your confirmation._",
                [
                    ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        $this->showPendingList($session, $pending);
    }

    /**
     * Start with a specific agreement (from confirmation request).
     *
     * Called when counterparty receives confirmation request.
     *
     * @srs-ref FR-AGR-12 Send confirmation request to counterparty
     * @srs-ref FR-AGR-13 Works for unregistered counterparties
     */
    public function startWithAgreement(ConversationSession $session, Agreement $agreement): void
    {
        $this->setTempData($session, 'confirm_agreement_id', $agreement->id);
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_CONFIRM,
            AgreementStep::AWAITING_CONFIRM->value
        );
        $this->showAgreementForConfirmation($session, $agreement);
    }

    /*
    |--------------------------------------------------------------------------
    | Message Handler
    |--------------------------------------------------------------------------
    */

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        // Handle common navigation
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = AgreementStep::tryFrom($session->current_step);

        if (!$step) {
            $this->start($session);
            return;
        }

        match ($step) {
            AgreementStep::PENDING_LIST => $this->handlePendingSelection($message, $session),
            AgreementStep::VIEW_PENDING => $this->handleViewAction($message, $session),
            AgreementStep::AWAITING_CONFIRM => $this->handleConfirmationChoice($message, $session),
            AgreementStep::CONFIRM_DONE => $this->handlePostConfirmation($message, $session),
            default => $this->start($session),
        };
    }

    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $this->promptCurrentStep($session);
    }

    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = AgreementStep::tryFrom($session->current_step);

        match ($step) {
            AgreementStep::AWAITING_CONFIRM => $this->showConfirmationButtons($session),
            default => $this->start($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Step: Pending List
    |--------------------------------------------------------------------------
    */

    protected function showPendingList(ConversationSession $session, $pending): void
    {
        $count = $pending->count();

        $header = "⏳ *Pending Confirmations*\n\n" .
            "*{$count}* agreement(s) ninne kaaththirikkunnu:\n" .
            "_{$count} agreement(s) waiting for your confirmation._";

        $rows = [];
        foreach ($pending as $agreement) {
            $amount = number_format($agreement->amount);
            $creator = $agreement->from_name ?? 'Unknown';

            $rows[] = [
                'id' => 'pending_' . $agreement->id,
                'title' => mb_substr("₹{$amount} - {$creator}", 0, 24),
                'description' => "⏳ #{$agreement->agreement_number}",
            ];
        }

        $this->sendList(
            $session->phone,
            $header,
            '📋 View',
            [['title' => 'Pending Agreements', 'rows' => array_slice($rows, 0, 10)]]
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_CONFIRM,
            AgreementStep::PENDING_LIST->value
        );
    }

    protected function handlePendingSelection(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isListReply()) {
            $selectionId = $this->getSelectionId($message);

            if (str_starts_with($selectionId, 'pending_')) {
                $agreementId = (int) str_replace('pending_', '', $selectionId);
                $agreement = Agreement::with('creator')->find($agreementId);

                if ($agreement && $agreement->isPending()) {
                    $this->setTempData($session, 'confirm_agreement_id', $agreementId);
                    $this->showAgreementForConfirmation($session, $agreement);
                    return;
                }
            }
        }

        // Default: re-show list
        $this->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Step: View Agreement for Confirmation
    |--------------------------------------------------------------------------
    */

    /**
     * Show agreement details with confirmation options.
     *
     * @srs-ref FR-AGR-14 Three options: Yes/No/Don't Know
     */
    protected function showAgreementForConfirmation(ConversationSession $session, Agreement $agreement): void
    {
        $creator = $agreement->creator;
        $creatorName = $creator?->name ?? $agreement->from_name ?? 'Unknown';

        // Determine direction from counterparty's perspective
        $direction = $agreement->direction ?? 'giving';
        $counterpartyDirection = $direction === 'giving' ? 'receiving' : 'giving';
        $dirIcon = $counterpartyDirection === 'receiving' ? '📥' : '💸';
        $dirText = $counterpartyDirection === 'receiving'
            ? 'Neekku kittunnu (You receive)'
            : 'Nee kodukkanam (You give)';

        // Get purpose label - handle both enum and string
        $purposeValue = $agreement->purpose_type instanceof \App\Enums\AgreementPurpose
            ? $agreement->purpose_type->value
            : ($agreement->purpose_type ?? 'other');
        $purposeLabel = AgreementMessages::getPurposeLabel($purposeValue);

        // Format due date
        $dueDate = $agreement->due_date
            ? $agreement->due_date->format('M j, Y')
            : 'No fixed date';

        $message = "📋 *Agreement Confirmation Request!*\n\n" .
            "*{$creatorName}* ninakkum aayi oru agreement record cheyyaan aagrahikkunnu:\n\n" .
            "{$dirIcon} *{$dirText}*\n\n" .
            "💰 *Amount:* ₹" . number_format($agreement->amount) . "\n" .
            "📝 *In Words:* {$agreement->amount_in_words}\n\n" .
            "📋 *Purpose:* {$purposeLabel}\n" .
            "📄 *Details:* " . ($agreement->description ?? 'None') . "\n" .
            "📅 *Due Date:* {$dueDate}\n\n" .
            "📋 Agreement #: *{$agreement->agreement_number}*\n\n" .
            "⚠️ *Ith sheri aano?* _Is this correct?_";

        // FR-AGR-14: Three confirmation options
        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'confirm', 'title' => '✅ Sheri, Confirm'],
                ['id' => 'reject', 'title' => '❌ Alla, Incorrect'],
                ['id' => 'unknown', 'title' => "❓ Ariyilla"],
            ]
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_CONFIRM,
            AgreementStep::AWAITING_CONFIRM->value
        );
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

    protected function showPendingDetail(ConversationSession $session): void
    {
        $agreementId = $this->getTempData($session, 'confirm_agreement_id');
        $agreement = Agreement::with('creator')->find($agreementId);

        if (!$agreement || !$agreement->isPending()) {
            $this->sendButtons(
                $session->phone,
                "❌ *Agreement Not Found*\n\n" .
                "This agreement may have been cancelled or expired.",
                [
                    ['id' => 'more_pending', 'title' => '📋 View Pending'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            $this->start($session);
            return;
        }

        $this->showAgreementForConfirmation($session, $agreement);
    }

    /*
    |--------------------------------------------------------------------------
    | Step: Handle Confirmation Choice (FR-AGR-14)
    |--------------------------------------------------------------------------
    */

    /**
     * Handle counterparty's confirmation choice.
     *
     * @srs-ref FR-AGR-14 Yes Confirm / No Incorrect / Don't Know
     */
    protected function handleConfirmationChoice(IncomingMessage $message, ConversationSession $session): void
    {
        $choice = null;

        if ($message->isInteractive()) {
            $choice = $this->getSelectionId($message);
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            if (in_array($text, ['yes', 'confirm', 'sheri', '1', 'correct'])) {
                $choice = 'confirm';
            } elseif (in_array($text, ['no', 'reject', 'incorrect', 'alla', '2', 'wrong'])) {
                $choice = 'reject';
            } elseif (in_array($text, ['unknown', 'dont know', "don't know", 'ariyilla', '3'])) {
                $choice = 'unknown';
            }
        }

        match ($choice) {
            'confirm' => $this->confirmAgreement($session),
            'reject' => $this->rejectAgreement($session),
            'unknown' => $this->disputeAgreement($session),
            default => $this->showConfirmationButtons($session),
        };
    }

    protected function showConfirmationButtons(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "Please select an option:\n\n" .
            "✅ *Sheri, Confirm* - Details correct aanu\n" .
            "❌ *Alla, Incorrect* - Something wrong aanu\n" .
            "❓ *Ariyilla* - Enikku ee aaline ariyilla",
            [
                ['id' => 'confirm', 'title' => '✅ Sheri, Confirm'],
                ['id' => 'reject', 'title' => '❌ Alla, Incorrect'],
                ['id' => 'unknown', 'title' => "❓ Ariyilla"],
            ]
        );
    }

    protected function proceedToConfirmation(ConversationSession $session): void
    {
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_CONFIRM,
            AgreementStep::AWAITING_CONFIRM->value
        );
        $this->showConfirmationButtons($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Action: Confirm (FR-AGR-15)
    |--------------------------------------------------------------------------
    */

    /**
     * Confirm the agreement.
     *
     * @srs-ref FR-AGR-15 Mark active upon BOTH confirmations
     * @srs-ref FR-AGR-20 Generate PDF on mutual confirmation
     */
    protected function confirmAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->getTempData($session, 'confirm_agreement_id');
            $agreement = Agreement::with('creator')->find($agreementId);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            if (!$agreement->isPending()) {
                throw new \Exception('Agreement is no longer pending');
            }

            if ($agreement->isExpired()) {
                throw new \Exception('Agreement has expired');
            }

            // Link counterparty user if registered (but don't require it! FR-AGR-13)
            $user = $this->getUser($session);
            if ($user && !$agreement->to_user_id) {
                $agreement->update(['to_user_id' => $user->id]);
            }

            // Confirm by counterparty
            $agreement = $this->agreementService->confirmByCounterparty($agreement);

            // Send success message
            $this->sendButtons(
                $session->phone,
                "🎉 *Agreement Confirmed!*\n\n" .
                "📋 Agreement #: *{$agreement->agreement_number}*\n\n" .
                "✅ Randum perrum confirm cheythu!\n" .
                "_Both parties confirmed!_\n\n" .
                "📄 *PDF generate aavunnu...*\n" .
                "_Your PDF document will arrive shortly._",
                [
                    ['id' => 'more_pending', 'title' => '📋 More Pending'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );

            // Dispatch PDF generation job (FR-AGR-20)
            // This runs async so it won't timeout
            GenerateAgreementPDF::dispatch($agreement, notifyParties: true);

            // Notify creator immediately
            $this->notifyCreatorConfirmed($agreement);

            // Update step
            $this->sessionManager->setFlowStep(
                $session,
                FlowType::AGREEMENT_CONFIRM,
                AgreementStep::CONFIRM_DONE->value
            );

            $this->logInfo('Agreement confirmed by counterparty', [
                'agreement_id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number,
                'phone' => $session->phone,
            ]);

        } catch (\Exception $e) {
            $this->logError('Agreement confirmation failed', [
                'error' => $e->getMessage(),
                'phone' => $session->phone,
            ]);

            $this->sendButtons(
                $session->phone,
                "❌ *Confirmation Failed*\n\n{$e->getMessage()}\n\nPlease try again.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Action: Reject
    |--------------------------------------------------------------------------
    */

    /**
     * Reject the agreement (details incorrect).
     */
    protected function rejectAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->getTempData($session, 'confirm_agreement_id');
            $agreement = Agreement::with('creator')->find($agreementId);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            // Mark as rejected
            $agreement = $this->agreementService->rejectByCounterparty($agreement, 'Details are incorrect');

            $creatorName = $agreement->creator?->name ?? $agreement->from_name ?? 'Creator';

            $this->sendButtons(
                $session->phone,
                "❌ *Agreement Rejected*\n\n" .
                "Nee ee agreement reject cheythu.\n\n" .
                "*{$creatorName}*-ne ariyikkum.\n" .
                "_They will be notified._\n\n" .
                "If mistake aayirunnel, please contact them directly.",
                [
                    ['id' => 'more_pending', 'title' => '📋 More Pending'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );

            // Notify creator
            $this->notifyCreatorRejected($agreement);

            $this->sessionManager->setFlowStep(
                $session,
                FlowType::AGREEMENT_CONFIRM,
                AgreementStep::CONFIRM_DONE->value
            );

        } catch (\Exception $e) {
            $this->logError('Agreement rejection failed', ['error' => $e->getMessage()]);
            $this->sendText($session->phone, "❌ Error: {$e->getMessage()}");
            $this->start($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Action: Dispute (Don't Know)
    |--------------------------------------------------------------------------
    */

    /**
     * Mark as disputed (counterparty doesn't know creator).
     */
    protected function disputeAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->getTempData($session, 'confirm_agreement_id');
            $agreement = Agreement::with('creator')->find($agreementId);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            // Mark as disputed
            $agreement = $this->agreementService->markDisputed($agreement);

            $creatorName = $agreement->creator?->name ?? $agreement->from_name ?? 'Creator';

            $this->sendButtons(
                $session->phone,
                "⚠️ *Agreement Flagged*\n\n" .
                "Nee *{$creatorName}*-ne ariyilla ennu paranju.\n\n" .
                "Ee agreement review-nu flag cheythittund.\n" .
                "Avare ariyikkum.",
                [
                    ['id' => 'more_pending', 'title' => '📋 More Pending'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );

            // Notify creator
            $this->notifyCreatorDisputed($agreement);

            $this->sessionManager->setFlowStep(
                $session,
                FlowType::AGREEMENT_CONFIRM,
                AgreementStep::CONFIRM_DONE->value
            );

        } catch (\Exception $e) {
            $this->logError('Agreement dispute failed', ['error' => $e->getMessage()]);
            $this->sendText($session->phone, "❌ Error: {$e->getMessage()}");
            $this->start($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step: Post-Confirmation
    |--------------------------------------------------------------------------
    */

    protected function handlePostConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

        match ($action) {
            'more_pending' => $this->start($session),
            'my_agreements' => $this->goToAgreementList($session),
            default => $this->goToMenu($session),
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Creator Notifications
    |--------------------------------------------------------------------------
    */

    /**
     * Notify creator that agreement was confirmed.
     */
    protected function notifyCreatorConfirmed(Agreement $agreement): void
    {
        $creatorPhone = $agreement->creator?->phone ?? $agreement->from_phone;
        if (!$creatorPhone) return;

        $this->sendButtons(
            $creatorPhone,
            "🎉 *Agreement Confirmed!*\n\n" .
            "*{$agreement->to_name}* confirm cheythu!\n\n" .
            "📋 Agreement #: *{$agreement->agreement_number}*\n\n" .
            "📄 *PDF document udane varum...*\n" .
            "_Your PDF will arrive shortly._",
            [
                ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /**
     * Notify creator that agreement was rejected.
     */
    protected function notifyCreatorRejected(Agreement $agreement): void
    {
        $creatorPhone = $agreement->creator?->phone ?? $agreement->from_phone;
        if (!$creatorPhone) return;

        $this->sendButtons(
            $creatorPhone,
            "❌ *Agreement Rejected*\n\n" .
            "*{$agreement->to_name}* reject cheythu.\n\n" .
            "📋 Agreement #: *{$agreement->agreement_number}*\n" .
            "📝 Reason: Details incorrect\n\n" .
            "Details verify cheythu puthiyathu undaakkuka.",
            [
                ['id' => 'create_agreement', 'title' => '📝 Create New'],
                ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
            ]
        );
    }

    /**
     * Notify creator that agreement was disputed.
     */
    protected function notifyCreatorDisputed(Agreement $agreement): void
    {
        $creatorPhone = $agreement->creator?->phone ?? $agreement->from_phone;
        if (!$creatorPhone) return;

        $this->sendButtons(
            $creatorPhone,
            "⚠️ *Agreement Disputed*\n\n" .
            "*{$agreement->to_name}* paranju avar ninne ariyilla.\n\n" .
            "📋 Agreement #: *{$agreement->agreement_number}*\n\n" .
            "Correct contact details verify cheyyuka.",
            [
                ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    protected function goToAgreementList(ConversationSession $session): void
    {
        $this->goToFlow($session, FlowType::AGREEMENT_LIST, AgreementStep::MY_LIST->value);
    }
}