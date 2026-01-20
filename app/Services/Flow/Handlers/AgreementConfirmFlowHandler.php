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
 * Handles the agreement confirmation flow for counterparty.
 *
 * Flow Steps:
 * 1. show_pending - Show pending agreements
 * 2. view_pending - View specific agreement details
 * 3. confirm_agreement - Handle confirmation choice
 * 4. confirmation_complete - Success message
 */
class AgreementConfirmFlowHandler implements FlowHandlerInterface
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
        return FlowType::AGREEMENT_CONFIRM->value;
    }

    /**
     * Check if can handle step.
     */
    public function canHandleStep(string $step): bool
    {
        return in_array($step, [
            AgreementStep::SHOW_PENDING->value,
            AgreementStep::VIEW_PENDING->value,
            AgreementStep::CONFIRM_AGREEMENT->value,
            AgreementStep::CONFIRMATION_COMPLETE->value,
        ]);
    }

    /**
     * Start the flow - show pending confirmations.
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

        // Get pending confirmations
        $pending = $this->agreementService->getPendingConfirmations($user);

        if ($pending->isEmpty()) {
            $this->whatsApp->sendButtons(
                $session->phone,
                "✅ *No Pending Confirmations*\n\nYou don't have any agreements waiting for your confirmation.",
                [
                    ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                    ['id' => 'menu', 'title' => '🏠 Main Menu'],
                ]
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
        $this->sessionManager->setTempData($session, 'confirm_agreement_id', $agreement->id);

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

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    protected function handlePendingSelection(IncomingMessage $message, ConversationSession $session): void
    {
        if ($message->isListReply()) {
            $selectionId = $message->getSelectionId();

            if (str_starts_with($selectionId, 'pending_')) {
                $agreementId = (int) str_replace('pending_', '', $selectionId);
                $this->sessionManager->setTempData($session, 'confirm_agreement_id', $agreementId);
                $this->sessionManager->setStep($session, AgreementStep::VIEW_PENDING->value);
                $this->showPendingDetail($session);
                return;
            }
        }

        if ($message->isButtonReply()) {
            $action = $message->getSelectionId();

            match ($action) {
                'my_agreements' => $this->goToAgreementList($session),
                default => $this->goToMainMenu($session),
            };
        }
    }

    protected function handleViewAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'confirm' => $this->proceedToConfirmation($session),
            'back' => $this->start($session),
            default => $this->showPendingDetail($session),
        };
    }

    protected function handleConfirmationChoice(IncomingMessage $message, ConversationSession $session): void
    {
        $choice = null;

        if ($message->isButtonReply()) {
            $choice = $message->getSelectionId();
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
        $action = $message->isButtonReply() ? $message->getSelectionId() : null;

        match ($action) {
            'download_pdf' => $this->sendPDF($session),
            'more_pending' => $this->start($session),
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
        $header = AgreementMessages::format(AgreementMessages::PENDING_AGREEMENTS, [
            'count' => $pending->count(),
        ]);

        $rows = [];
        foreach ($pending as $agreement) {
            $amount = number_format($agreement->amount);
            $creator = $agreement->creator->name ?? 'Unknown';

            $rows[] = [
                'id' => 'pending_' . $agreement->id,
                'title' => mb_substr("₹{$amount} from {$creator}", 0, 24),
                'description' => mb_substr("⏳ #{$agreement->agreement_number}", 0, 72),
            ];
        }

        $sections = [
            [
                'title' => 'Pending Confirmations',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];

        $this->whatsApp->sendList(
            $session->phone,
            $header,
            '📋 View Agreements',
            $sections
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_CONFIRM,
            AgreementStep::SHOW_PENDING->value
        );
    }

    protected function showPendingDetail(ConversationSession $session): void
    {
        $agreementId = $this->sessionManager->getTempData($session, 'confirm_agreement_id');
        $agreement = Agreement::with('creator')->find($agreementId);

        if (!$agreement) {
            $this->whatsApp->sendText($session->phone, AgreementMessages::ERROR_AGREEMENT_NOT_FOUND);
            $this->start($session);
            return;
        }

        $this->showAgreementForConfirmation($session, $agreement);
    }

    protected function showAgreementForConfirmation(ConversationSession $session, Agreement $agreement): void
    {
        $creator = $agreement->creator;
        $direction = $agreement->creditor_id === $creator->id ? 'giving' : 'receiving';
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
            $session->phone,
            $message,
            AgreementMessages::getConfirmButtons()
        );

        $this->sessionManager->setStep($session, AgreementStep::CONFIRM_AGREEMENT->value);
    }

    protected function showConfirmationButtons(ConversationSession $session): void
    {
        $this->whatsApp->sendButtons(
            $session->phone,
            "Please select an option:",
            AgreementMessages::getConfirmButtons()
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Action Methods
    |--------------------------------------------------------------------------
    */

    protected function proceedToConfirmation(ConversationSession $session): void
    {
        $this->sessionManager->setStep($session, AgreementStep::CONFIRM_AGREEMENT->value);
        $this->showConfirmationButtons($session);
    }

    protected function confirmAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->sessionManager->getTempData($session, 'confirm_agreement_id');
            $agreement = Agreement::with(['creator'])->find($agreementId);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            // Link counterparty user if not already linked
            $user = $this->sessionManager->getUser($session);
            if (!$agreement->to_user_id && $user) {
                $agreement->update(['to_user_id' => $user->id]);
            }

            // Confirm the agreement
            $agreement = $this->agreementService->confirmByCounterparty($agreement);

            // Generate PDF
            $this->whatsApp->sendText($session->phone, AgreementMessages::PDF_GENERATING);
            $pdfUrl = $this->pdfService->generateAndUpload($agreement);

            // Send success to counterparty
            $message = AgreementMessages::format(AgreementMessages::CONFIRM_SUCCESS, [
                'agreement_number' => $agreement->agreement_number,
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                [
                    ['id' => 'download_pdf', 'title' => '📄 Download PDF'],
                    ['id' => 'menu', 'title' => '🏠 Main Menu'],
                ]
            );

            // Send PDF document
            $this->whatsApp->sendDocument(
                $session->phone,
                $pdfUrl,
                "Agreement_{$agreement->agreement_number}.pdf",
                "📄 Your signed agreement document"
            );

            // Notify creator
            $this->notifyCreatorConfirmed($agreement, $pdfUrl);

            $this->sessionManager->setStep($session, AgreementStep::CONFIRMATION_COMPLETE->value);

            Log::info('Agreement confirmed by counterparty', [
                'agreement_id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number,
            ]);

        } catch (\Exception $e) {
            Log::error('Agreement confirmation failed', [
                'error' => $e->getMessage(),
            ]);

            $this->whatsApp->sendText(
                $session->phone,
                "❌ Failed to confirm: " . $e->getMessage()
            );

            $this->start($session);
        }
    }

    protected function rejectAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->sessionManager->getTempData($session, 'confirm_agreement_id');
            $agreement = Agreement::with(['creator'])->find($agreementId);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            $agreement = $this->agreementService->rejectByCounterparty($agreement, 'Details are incorrect');

            $message = AgreementMessages::format(AgreementMessages::CONFIRM_REJECTED, [
                'creator_name' => $agreement->creator->name,
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                [
                    ['id' => 'more_pending', 'title' => '📋 More Pending'],
                    ['id' => 'menu', 'title' => '🏠 Main Menu'],
                ]
            );

            // Notify creator
            $this->notifyCreatorRejected($agreement);

            $this->sessionManager->setStep($session, AgreementStep::CONFIRMATION_COMPLETE->value);

        } catch (\Exception $e) {
            Log::error('Agreement rejection failed', [
                'error' => $e->getMessage(),
            ]);

            $this->start($session);
        }
    }

    protected function disputeAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->sessionManager->getTempData($session, 'confirm_agreement_id');
            $agreement = Agreement::with(['creator'])->find($agreementId);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            $agreement = $this->agreementService->markDisputed($agreement);

            $message = AgreementMessages::format(AgreementMessages::CONFIRM_DISPUTED, [
                'creator_name' => $agreement->creator->name,
            ]);

            $this->whatsApp->sendButtons(
                $session->phone,
                $message,
                [
                    ['id' => 'more_pending', 'title' => '📋 More Pending'],
                    ['id' => 'menu', 'title' => '🏠 Main Menu'],
                ]
            );

            // Notify creator
            $this->notifyCreatorDisputed($agreement);

            $this->sessionManager->setStep($session, AgreementStep::CONFIRMATION_COMPLETE->value);

        } catch (\Exception $e) {
            Log::error('Agreement dispute failed', [
                'error' => $e->getMessage(),
            ]);

            $this->start($session);
        }
    }

    protected function sendPDF(ConversationSession $session): void
    {
        $agreementId = $this->sessionManager->getTempData($session, 'confirm_agreement_id');
        $agreement = Agreement::find($agreementId);

        if ($agreement && $agreement->pdf_url) {
            $this->whatsApp->sendDocument(
                $session->phone,
                $agreement->pdf_url,
                "Agreement_{$agreement->agreement_number}.pdf",
                "📄 Your signed agreement document"
            );
        } else {
            $this->whatsApp->sendText($session->phone, "❌ PDF not available.");
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Notification Methods
    |--------------------------------------------------------------------------
    */

    protected function notifyCreatorConfirmed(Agreement $agreement, string $pdfUrl): void
    {
        $creator = $agreement->creator;

        $message = AgreementMessages::format(AgreementMessages::CREATOR_NOTIFIED_CONFIRMED, [
            'other_party_name' => $agreement->to_name,
            'agreement_number' => $agreement->agreement_number,
        ]);

        $this->whatsApp->sendText($creator->phone, $message);

        // Send PDF
        $this->whatsApp->sendDocument(
            $creator->phone,
            $pdfUrl,
            "Agreement_{$agreement->agreement_number}.pdf",
            "📄 Your signed agreement document"
        );
    }

    protected function notifyCreatorRejected(Agreement $agreement): void
    {
        $creator = $agreement->creator;

        $message = AgreementMessages::format(AgreementMessages::CREATOR_NOTIFIED_REJECTED, [
            'other_party_name' => $agreement->to_name,
            'agreement_number' => $agreement->agreement_number,
            'reason' => 'Details are incorrect',
        ]);

        $this->whatsApp->sendText($creator->phone, $message);
    }

    protected function notifyCreatorDisputed(Agreement $agreement): void
    {
        $creator = $agreement->creator;

        $message = AgreementMessages::format(AgreementMessages::CREATOR_NOTIFIED_DISPUTED, [
            'other_party_name' => $agreement->to_name,
            'agreement_number' => $agreement->agreement_number,
        ]);

        $this->whatsApp->sendText($creator->phone, $message);
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation Methods
    |--------------------------------------------------------------------------
    */

    protected function goToAgreementList(ConversationSession $session): void
    {
        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_LIST,
            AgreementStep::SHOW_LIST->value
        );

        app(AgreementListFlowHandler::class)->start($session);
    }

    protected function goToMainMenu(ConversationSession $session): void
    {
        $this->sessionManager->resetToMainMenu($session);
        app(MainMenuHandler::class)->start($session);
    }
}