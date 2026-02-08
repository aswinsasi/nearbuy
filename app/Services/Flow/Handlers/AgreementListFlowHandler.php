<?php

declare(strict_types=1);

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\AgreementDirection;
use App\Enums\AgreementPurpose;
use App\Enums\AgreementStep;
use App\Enums\AgreementStatus;
use App\Enums\FlowType;
use App\Models\Agreement;
use App\Models\ConversationSession;
use App\Services\Agreements\AgreementService;

/**
 * Agreement List Flow Handler.
 *
 * Displays user's agreements in a compact, scannable format:
 * 📋 Ninte Agreements:
 * 1. ↗️ John — ₹20,000 — 🤝 Loan — ✅ Active
 * 2. ↙️ Mary — ₹5,000 — 🔧 Advance — ⏳ Pending
 *
 * Uses ↗️ for "Gave" (giving) and ↙️ for "Received" (receiving)
 */
class AgreementListFlowHandler extends AbstractFlowHandler
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
        return FlowType::AGREEMENT_LIST;
    }

    protected function getSteps(): array
    {
        return [
            AgreementStep::MY_LIST->value,
            AgreementStep::VIEW_DETAIL->value,
            AgreementStep::MARK_COMPLETE->value,
        ];
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
                "⚠️ *Registration Required*\n\n" .
                "Agreements kaanaan register cheyyuka.",
                [
                    ['id' => 'register', 'title' => '📝 Register'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        // Get user's agreements (by phone number for unregistered counterparty support)
        $agreements = Agreement::involvingPhone($session->phone)
            ->orderByRaw("CASE 
                WHEN status = 'pending' THEN 1 
                WHEN status = 'confirmed' THEN 2 
                WHEN status = 'disputed' THEN 3 
                ELSE 4 
            END")
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        if ($agreements->isEmpty()) {
            $this->sendButtons(
                $session->phone,
                "📋 *Ninte Agreements*\n\n" .
                "Ninakku agreements onnum illa.\n" .
                "_You have no agreements yet._\n\n" .
                "Puthiyathu undaakkaan 📝 New Agreement tap cheyyuka.",
                [
                    ['id' => 'create_agreement', 'title' => '📝 New Agreement'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            return;
        }

        $this->showAgreementsList($session, $agreements);
    }

    public function handle(IncomingMessage $message, ConversationSession $session): void
    {
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        $step = AgreementStep::tryFrom($session->current_step);

        if (!$step) {
            $this->start($session);
            return;
        }

        match ($step) {
            AgreementStep::MY_LIST => $this->handleListSelection($message, $session),
            AgreementStep::VIEW_DETAIL => $this->handleDetailAction($message, $session),
            AgreementStep::MARK_COMPLETE => $this->handleCompleteConfirm($message, $session),
            default => $this->start($session),
        };
    }

    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void
    {
        $this->start($session);
    }

    protected function promptCurrentStep(ConversationSession $session): void
    {
        $this->start($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Display: Compact Agreement List
    |--------------------------------------------------------------------------
    */

    /**
     * Show compact, scannable agreement list.
     *
     * Format:
     * 📋 Ninte Agreements:
     * 1. ↗️ John — ₹20,000 — 🤝 Loan — ✅ Active
     * 2. ↙️ Mary — ₹5,000 — 🔧 Advance — ⏳ Pending
     */
    protected function showAgreementsList(ConversationSession $session, $agreements): void
    {
        $phone = $session->phone;

        // Build compact text list
        $lines = ["📋 *Ninte Agreements:*\n"];

        $index = 1;
        foreach ($agreements->take(10) as $agreement) {
            $lines[] = $this->formatAgreementLine($agreement, $phone, $index);
            $index++;
        }

        $lines[] = "\n_Select number for details._";

        // Send text message first
        $this->sendText($phone, implode("\n", $lines));

        // Build list rows for WhatsApp interactive list
        $rows = [];
        foreach ($agreements->take(10) as $agreement) {
            $rows[] = $this->formatAgreementRow($agreement, $phone);
        }

        $this->sendList(
            $phone,
            "Tap to select an agreement:",
            '📋 Select Agreement',
            [['title' => 'Your Agreements', 'rows' => $rows]]
        );

        // Action buttons
        $this->sendButtons(
            $phone,
            "What would you like to do?",
            [
                ['id' => 'create_agreement', 'title' => '📝 New Agreement'],
                ['id' => 'pending_confirm', 'title' => '⏳ Pending Confirms'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_LIST,
            AgreementStep::MY_LIST->value
        );
    }

    /**
     * Format single agreement line for compact display.
     * Format: 1. ↗️ John — ₹20,000 — 🤝 Loan — ✅ Active
     */
    protected function formatAgreementLine(Agreement $agreement, string $phone, int $index): string
    {
        // Determine direction from user's perspective
        $isCreator = $agreement->from_phone === $phone;
        $directionValue = $agreement->direction ?? 'giving';

        // User's direction (inverted if counterparty)
        if ($isCreator) {
            $userDirection = AgreementDirection::tryFrom($directionValue) ?? AgreementDirection::GIVING;
        } else {
            $baseDir = AgreementDirection::tryFrom($directionValue) ?? AgreementDirection::GIVING;
            $userDirection = $baseDir->opposite();
        }

        // Other party name (truncated)
        $otherName = $isCreator ? $agreement->to_name : $agreement->from_name;
        $otherName = mb_substr($otherName ?? 'Unknown', 0, 10);

        // Amount
        $amount = '₹' . number_format($agreement->amount, 0);

        // Purpose icon
        $purposeValue = $agreement->purpose_type instanceof AgreementPurpose
            ? $agreement->purpose_type->value
            : ($agreement->purpose_type ?? 'other');
        $purposeEnum = AgreementPurpose::tryFrom($purposeValue) ?? AgreementPurpose::OTHER;

        // Status
        $statusEnum = $agreement->status instanceof AgreementStatus
            ? $agreement->status
            : (AgreementStatus::tryFrom($agreement->status ?? 'pending') ?? AgreementStatus::PENDING);

        return "{$index}. {$userDirection->arrow()} {$otherName} — {$amount} — {$purposeEnum->icon()} — {$statusEnum->shortBadge()}";
    }

    /**
     * Format agreement row for WhatsApp list message.
     */
    protected function formatAgreementRow(Agreement $agreement, string $phone): array
    {
        $isCreator = $agreement->from_phone === $phone;
        $directionValue = $agreement->direction ?? 'giving';

        // User's direction
        if ($isCreator) {
            $userDirection = AgreementDirection::tryFrom($directionValue) ?? AgreementDirection::GIVING;
        } else {
            $baseDir = AgreementDirection::tryFrom($directionValue) ?? AgreementDirection::GIVING;
            $userDirection = $baseDir->opposite();
        }

        $otherName = $isCreator ? $agreement->to_name : $agreement->from_name;
        $amount = '₹' . number_format($agreement->amount, 0);

        // Status
        $statusEnum = $agreement->status instanceof AgreementStatus
            ? $agreement->status
            : (AgreementStatus::tryFrom($agreement->status ?? 'pending') ?? AgreementStatus::PENDING);

        return [
            'id' => 'agreement_' . $agreement->id,
            'title' => mb_substr("{$userDirection->arrow()} {$otherName} — {$amount}", 0, 24),
            'description' => mb_substr("#{$agreement->agreement_number} • {$statusEnum->shortBadge()}", 0, 72),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Display: Agreement Detail
    |--------------------------------------------------------------------------
    */

    /**
     * Show agreement detail view.
     *
     * Format:
     * 📝 Agreement #NB-AG-2026-0042
     * ↗️ You → John (987-654-3210)
     * 💰 ₹20,000 (Rupees Twenty Thousand Only)
     * 🤝 Loan — House renovation
     * 📅 Due: Mar 15, 2026 | Status: ✅ Active
     */
    protected function showAgreementDetail(ConversationSession $session): void
    {
        $agreementId = $this->getTempData($session, 'view_agreement_id');
        $agreement = Agreement::with(['fromUser', 'toUser'])->find($agreementId);

        if (!$agreement) {
            $this->sendButtons(
                $session->phone,
                "❌ *Agreement Not Found*\n\nEe agreement kaanan pattilla.",
                [
                    ['id' => 'back', 'title' => '⬅️ Back'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );
            $this->start($session);
            return;
        }

        $phone = $session->phone;
        $isCreator = $agreement->from_phone === $phone;

        // User's direction
        $directionValue = $agreement->direction ?? 'giving';
        if ($isCreator) {
            $userDirection = AgreementDirection::tryFrom($directionValue) ?? AgreementDirection::GIVING;
        } else {
            $baseDir = AgreementDirection::tryFrom($directionValue) ?? AgreementDirection::GIVING;
            $userDirection = $baseDir->opposite();
        }

        // Other party details
        $otherName = $isCreator ? $agreement->to_name : $agreement->from_name;
        $otherPhone = $isCreator ? $agreement->to_phone : $agreement->from_phone;

        // Purpose
        $purposeValue = $agreement->purpose_type instanceof AgreementPurpose
            ? $agreement->purpose_type->value
            : ($agreement->purpose_type ?? 'other');
        $purposeEnum = AgreementPurpose::tryFrom($purposeValue) ?? AgreementPurpose::OTHER;

        // Status
        $statusEnum = $agreement->status instanceof AgreementStatus
            ? $agreement->status
            : (AgreementStatus::tryFrom($agreement->status ?? 'pending') ?? AgreementStatus::PENDING);

        // Due date
        $dueText = $agreement->due_date
            ? $agreement->due_date->format('M j, Y')
            : 'No fixed date';

        // Build detail message
        $message = "📝 *Agreement #{$agreement->agreement_number}*\n\n" .
            "{$userDirection->arrow()} You → *{$otherName}* ({$this->formatPhone($otherPhone)})\n\n" .
            "💰 *₹" . number_format($agreement->amount) . "*\n" .
            "_{$agreement->amount_in_words}_\n\n" .
            "{$purposeEnum->icon()} *{$purposeEnum->label()}*" .
            ($agreement->description ? " — {$agreement->description}" : "") . "\n\n" .
            "📅 Due: {$dueText} | Status: {$statusEnum->shortBadge()}";

        $this->sendText($session->phone, $message);

        // Action buttons based on status and role
        $buttons = $this->getDetailButtons($agreement, $phone, $isCreator);

        $this->sendButtons(
            $session->phone,
            "Entha cheyyaan?",
            $buttons
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_LIST,
            AgreementStep::VIEW_DETAIL->value
        );
    }

    /**
     * Get action buttons for detail view based on status and role.
     */
    protected function getDetailButtons(Agreement $agreement, string $phone, bool $isCreator): array
    {
        $status = $agreement->status instanceof AgreementStatus
            ? $agreement->status
            : (AgreementStatus::tryFrom($agreement->status ?? 'pending') ?? AgreementStatus::PENDING);

        $buttons = [];

        // Download PDF (if confirmed and has PDF)
        if ($status === AgreementStatus::CONFIRMED && $agreement->pdf_url) {
            $buttons[] = ['id' => 'download_pdf', 'title' => '📄 Download PDF'];
        }

        // Mark complete (only confirmed agreements, creditor only)
        if ($status === AgreementStatus::CONFIRMED) {
            // Creditor = creator if giving, counterparty if receiving
            $directionValue = $agreement->direction ?? 'giving';
            $isCreditor = ($directionValue === 'giving' && $isCreator) ||
                         ($directionValue === 'receiving' && !$isCreator);
            if ($isCreditor) {
                $buttons[] = ['id' => 'mark_complete', 'title' => '✔️ Mark Completed'];
            }
        }

        // Dispute (pending or confirmed)
        if ($status->canBeDisputed()) {
            $buttons[] = ['id' => 'dispute', 'title' => '⚠️ Dispute'];
        }

        // Send reminder (pending, creator only)
        if ($status === AgreementStatus::PENDING && $isCreator) {
            $buttons[] = ['id' => 'remind', 'title' => '🔔 Send Reminder'];
        }

        // Cancel (pending, creator only)
        if ($status === AgreementStatus::PENDING && $isCreator) {
            $buttons[] = ['id' => 'cancel', 'title' => '❌ Cancel'];
        }

        // Always add back button if space
        if (count($buttons) < 3) {
            $buttons[] = ['id' => 'back', 'title' => '⬅️ Back'];
        }

        return array_slice($buttons, 0, 3);
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
                $this->setTempData($session, 'view_agreement_id', $agreementId);
                $this->showAgreementDetail($session);
                return;
            }
        }

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);

            match ($action) {
                'create_agreement' => $this->goToCreate($session),
                'pending_confirm' => $this->goToPending($session),
                default => $this->goToMenu($session),
            };
            return;
        }

        $this->start($session);
    }

    protected function handleDetailAction(IncomingMessage $message, ConversationSession $session): void
    {
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

        match ($action) {
            'download_pdf' => $this->downloadPDF($session),
            'mark_complete' => $this->confirmMarkComplete($session),
            'dispute' => $this->confirmDispute($session),
            'remind' => $this->sendReminder($session),
            'cancel' => $this->cancelAgreement($session),
            'back' => $this->start($session),
            default => $this->showAgreementDetail($session),
        };
    }

    protected function handleCompleteConfirm(IncomingMessage $message, ConversationSession $session): void
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
    | Actions
    |--------------------------------------------------------------------------
    */

    protected function downloadPDF(ConversationSession $session): void
    {
        $agreementId = $this->getTempData($session, 'view_agreement_id');
        $agreement = Agreement::find($agreementId);

        if (!$agreement?->pdf_url) {
            $this->sendButtons(
                $session->phone,
                "❌ PDF available alla.",
                [['id' => 'back', 'title' => '⬅️ Back']]
            );
            return;
        }

        $this->whatsApp->sendDocument(
            $session->phone,
            $agreement->pdf_url,
            "Agreement_{$agreement->agreement_number}.pdf",
            "📄 Your Agreement Document"
        );

        $this->sendButtons(
            $session->phone,
            "📄 PDF ayachittund!",
            [
                ['id' => 'back', 'title' => '⬅️ Back'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ]
        );
    }

    protected function confirmMarkComplete(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "✔️ *Mark as Completed?*\n\n" .
            "Ee agreement settle aayi ennu mark cheyyaan aagrahikkunno?\n\n" .
            "_This will mark the agreement as settled._",
            [
                ['id' => 'confirm_complete', 'title' => '✔️ Yes, Complete'],
                ['id' => 'cancel_action', 'title' => '❌ Cancel'],
            ]
        );

        $this->sessionManager->setFlowStep(
            $session,
            FlowType::AGREEMENT_LIST,
            AgreementStep::MARK_COMPLETE->value
        );
    }

    protected function markComplete(ConversationSession $session): void
    {
        try {
            $agreementId = $this->getTempData($session, 'view_agreement_id');
            $agreement = Agreement::find($agreementId);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            $agreement->markAsCompleted();

            $this->sendButtons(
                $session->phone,
                "✔️ *Agreement Completed!*\n\n" .
                "#{$agreement->agreement_number} settle aayi.\n" .
                "_Marked as completed._",
                [
                    ['id' => 'back', 'title' => '📋 My Agreements'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );

            // Notify other party
            $otherPhone = $agreement->from_phone === $session->phone
                ? $agreement->to_phone
                : $agreement->from_phone;

            $this->sendButtons(
                $otherPhone,
                "✔️ *Agreement Completed!*\n\n" .
                "#{$agreement->agreement_number} settle aayi ennu marked cheythittund.",
                [['id' => 'my_agreements', 'title' => '📋 My Agreements']]
            );

            $this->start($session);

        } catch (\Exception $e) {
            $this->sendButtons(
                $session->phone,
                "❌ Error: {$e->getMessage()}",
                [['id' => 'back', 'title' => '⬅️ Back']]
            );
        }
    }

    protected function confirmDispute(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "⚠️ *Dispute Agreement?*\n\n" .
            "Ee agreement-il problem indo?\n" .
            "_This will flag the agreement for review._",
            [
                ['id' => 'confirm_dispute', 'title' => '⚠️ Yes, Dispute'],
                ['id' => 'cancel_action', 'title' => '❌ Cancel'],
            ]
        );
    }

    protected function sendReminder(ConversationSession $session): void
    {
        $agreementId = $this->getTempData($session, 'view_agreement_id');
        $agreement = Agreement::find($agreementId);

        if (!$agreement) {
            $this->start($session);
            return;
        }

        // Send reminder to counterparty
        $this->sendButtons(
            $agreement->to_phone,
            "🔔 *Reminder: Agreement Confirmation*\n\n" .
            "*{$agreement->from_name}* ninnodum oru agreement confirm cheyyaan kaaththirikkunnu:\n\n" .
            "💰 ₹" . number_format($agreement->amount) . "\n" .
            "📋 #{$agreement->agreement_number}\n\n" .
            "Please confirm or reject.",
            [
                ['id' => 'confirm', 'title' => '✅ Confirm'],
                ['id' => 'reject', 'title' => '❌ Reject'],
            ]
        );

        $this->sendButtons(
            $session->phone,
            "✅ Reminder sent to {$agreement->to_name}.",
            [['id' => 'back', 'title' => '⬅️ Back']]
        );
    }

    protected function cancelAgreement(ConversationSession $session): void
    {
        try {
            $agreementId = $this->getTempData($session, 'view_agreement_id');
            $agreement = Agreement::find($agreementId);

            if (!$agreement) {
                throw new \Exception('Agreement not found');
            }

            $agreement->cancel();

            $this->sendButtons(
                $session->phone,
                "❌ Agreement #{$agreement->agreement_number} cancelled.",
                [
                    ['id' => 'back', 'title' => '📋 My Agreements'],
                    ['id' => 'main_menu', 'title' => '🏠 Menu'],
                ]
            );

            $this->start($session);

        } catch (\Exception $e) {
            $this->sendButtons(
                $session->phone,
                "❌ Error: {$e->getMessage()}",
                [['id' => 'back', 'title' => '⬅️ Back']]
            );
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    protected function goToCreate(ConversationSession $session): void
    {
        $this->goToFlow($session, FlowType::AGREEMENT_CREATE, AgreementStep::ASK_DIRECTION->value);
    }

    protected function goToPending(ConversationSession $session): void
    {
        $this->goToFlow($session, FlowType::AGREEMENT_CONFIRM, AgreementStep::PENDING_LIST->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
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