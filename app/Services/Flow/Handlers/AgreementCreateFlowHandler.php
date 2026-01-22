<?php

namespace App\Services\Flow\Handlers;

use App\DTOs\IncomingMessage;
use App\Enums\AgreementStep;
use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Services\Agreements\AgreementService;
use App\Services\WhatsApp\Messages\AgreementMessages;
use App\Services\WhatsApp\Messages\MessageTemplates;

/**
 * ENHANCED Agreement Create Flow Handler.
 *
 * Key improvements:
 * 1. Uses sendTextWithMenu/sendButtonsWithMenu patterns
 * 2. Better error messages with recovery options
 * 3. Consistent footer on all messages
 * 4. Back/Cancel buttons on every step
 * 5. Better validation feedback
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
        return [
            AgreementStep::ASK_DIRECTION->value,
            AgreementStep::ASK_AMOUNT->value,
            AgreementStep::ASK_OTHER_PARTY_NAME->value,
            AgreementStep::ASK_OTHER_PARTY_PHONE->value,
            AgreementStep::ASK_PURPOSE->value,
            AgreementStep::ASK_DESCRIPTION->value,
            AgreementStep::ASK_DUE_DATE->value,
            AgreementStep::CONFIRM_CREATE->value,
            AgreementStep::CREATE_COMPLETE->value,
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
                "⚠️ *Registration Required*\n\nPlease register first to create agreements.",
                [['id' => 'register', 'title' => '📝 Register']]
            );
            $this->goToMainMenu($session);
            return;
        }

        // Clear previous data
        $this->clearTemp($session);

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
        // Handle common navigation (menu, cancel, back)
        if ($this->handleCommonNavigation($message, $session)) {
            return;
        }

        // Handle back navigation for this flow
        if ($this->isBack($message)) {
            $this->handleBack($session);
            return;
        }

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

    /**
     * Get expected input type.
     */
    protected function getExpectedInputType(string $step): string
    {
        return match ($step) {
            AgreementStep::ASK_DIRECTION->value => 'button',
            AgreementStep::ASK_AMOUNT->value => 'text',
            AgreementStep::ASK_OTHER_PARTY_NAME->value => 'text',
            AgreementStep::ASK_OTHER_PARTY_PHONE->value => 'phone',
            AgreementStep::ASK_PURPOSE->value => 'list',
            AgreementStep::ASK_DESCRIPTION->value => 'text',
            AgreementStep::ASK_DUE_DATE->value => 'list',
            AgreementStep::CONFIRM_CREATE->value => 'button',
            default => 'text',
        };
    }

    /**
     * Re-prompt current step.
     */
    protected function promptCurrentStep(ConversationSession $session): void
    {
        $step = AgreementStep::tryFrom($session->current_step);

        match ($step) {
            AgreementStep::ASK_DIRECTION => $this->askDirection($session),
            AgreementStep::ASK_AMOUNT => $this->askAmount($session),
            AgreementStep::ASK_OTHER_PARTY_NAME => $this->askName($session),
            AgreementStep::ASK_OTHER_PARTY_PHONE => $this->askPhone($session),
            AgreementStep::ASK_PURPOSE => $this->askPurpose($session),
            AgreementStep::ASK_DESCRIPTION => $this->askDescription($session),
            AgreementStep::ASK_DUE_DATE => $this->askDueDate($session),
            AgreementStep::CONFIRM_CREATE => $this->askConfirmation($session),
            default => $this->start($session),
        };
    }

    /**
     * Handle back navigation.
     */
    protected function handleBack(ConversationSession $session): void
    {
        $step = AgreementStep::tryFrom($session->current_step);

        $previousStep = match ($step) {
            AgreementStep::ASK_AMOUNT => AgreementStep::ASK_DIRECTION,
            AgreementStep::ASK_OTHER_PARTY_NAME => AgreementStep::ASK_AMOUNT,
            AgreementStep::ASK_OTHER_PARTY_PHONE => AgreementStep::ASK_OTHER_PARTY_NAME,
            AgreementStep::ASK_PURPOSE => AgreementStep::ASK_OTHER_PARTY_PHONE,
            AgreementStep::ASK_DESCRIPTION => AgreementStep::ASK_PURPOSE,
            AgreementStep::ASK_DUE_DATE => AgreementStep::ASK_DESCRIPTION,
            AgreementStep::CONFIRM_CREATE => AgreementStep::ASK_DUE_DATE,
            default => null,
        };

        if ($previousStep) {
            $this->nextStep($session, $previousStep->value);
            $this->promptCurrentStep($session);
        } else {
            $this->start($session);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step Handlers
    |--------------------------------------------------------------------------
    */

    protected function handleDirectionSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $direction = null;

        if ($message->isInteractive()) {
            $direction = $this->getSelectionId($message);
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

        $this->setTemp($session, 'direction', $direction);
        $this->nextStep($session, AgreementStep::ASK_AMOUNT->value);
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

        $amount = $this->validateAmount($cleaned);
        if ($amount === null) {
            $this->sendErrorWithOptions(
                $session->phone,
                "⚠️ *Invalid Amount*\n\n" .
                "Please enter a valid amount (numbers only).\n\n" .
                "_Example: 25000_\n" .
                "_Range: ₹1 to ₹10 Crore_",
                [
                    ['id' => 'back', 'title' => '⬅️ Back'],
                    self::MENU_BUTTON,
                ]
            );
            return;
        }

        $this->setTemp($session, 'amount', $amount);
        $this->nextStep($session, AgreementStep::ASK_OTHER_PARTY_NAME->value);
        $this->askName($session);
    }

    protected function handleNameInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $name = trim($message->text ?? '');

        if (!$this->validateName($name)) {
            $this->sendErrorWithOptions(
                $session->phone,
                "⚠️ *Invalid Name*\n\nPlease enter a valid name (2-100 characters).",
                [
                    ['id' => 'back', 'title' => '⬅️ Back'],
                    self::MENU_BUTTON,
                ]
            );
            return;
        }

        $this->setTemp($session, 'other_party_name', $name);
        $this->nextStep($session, AgreementStep::ASK_OTHER_PARTY_PHONE->value);
        $this->askPhone($session);
    }

    protected function handlePhoneInput(IncomingMessage $message, ConversationSession $session): void
    {
        if (!$message->isText()) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $phone = trim($message->text ?? '');

        if (!$this->validatePhone($phone)) {
            $this->sendErrorWithOptions(
                $session->phone,
                "⚠️ *Invalid Phone Number*\n\n" .
                "Please enter a valid 10-digit mobile number.\n\n" .
                "_Example: 9876543210_",
                [
                    ['id' => 'back', 'title' => '⬅️ Back'],
                    self::MENU_BUTTON,
                ]
            );
            return;
        }

        // Check for self-agreement
        $user = $this->getUser($session);
        $normalizedPhone = $this->normalizePhone($phone);

        if ($normalizedPhone === $user->phone) {
            $this->sendErrorWithOptions(
                $session->phone,
                "⚠️ *Cannot Create Self-Agreement*\n\n" .
                "You cannot create an agreement with yourself.\n" .
                "Please enter the other person's phone number.",
                [
                    ['id' => 'back', 'title' => '⬅️ Back'],
                    self::MENU_BUTTON,
                ]
            );
            return;
        }

        $this->setTemp($session, 'other_party_phone', $phone);
        $this->nextStep($session, AgreementStep::ASK_PURPOSE->value);
        $this->askPurpose($session);
    }

    protected function handlePurposeSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $purpose = null;

        if ($message->isListReply()) {
            $purpose = $this->getSelectionId($message);
        } elseif ($message->isText()) {
            $text = strtolower(trim($message->text ?? ''));
            $purpose = $this->matchPurpose($text);
        }

        $validPurposes = ['loan', 'advance', 'deposit', 'business', 'personal', 'other'];
        if (!in_array($purpose, $validPurposes)) {
            $this->handleInvalidInput($message, $session);
            return;
        }

        $this->setTemp($session, 'purpose', $purpose);
        $this->nextStep($session, AgreementStep::ASK_DESCRIPTION->value);
        $this->askDescription($session);
    }

    protected function handleDescriptionInput(IncomingMessage $message, ConversationSession $session): void
    {
        $description = null;

        // Check for skip button
        if ($this->isSkip($message)) {
            $this->setTemp($session, 'description', null);
            $this->nextStep($session, AgreementStep::ASK_DUE_DATE->value);
            $this->askDueDate($session);
            return;
        }

        if ($message->isText()) {
            $text = trim($message->text ?? '');

            if (strtolower($text) !== 'skip' && mb_strlen($text) > 0) {
                if (mb_strlen($text) > 500) {
                    $this->sendErrorWithOptions(
                        $session->phone,
                        "⚠️ *Too Long*\n\nDescription must be under 500 characters.\n\nYours is " . mb_strlen($text) . " characters.",
                        [
                            ['id' => 'skip', 'title' => '⏭️ Skip'],
                            ['id' => 'back', 'title' => '⬅️ Back'],
                        ]
                    );
                    return;
                }
                $description = $text;
            }
        }

        $this->setTemp($session, 'description', $description);
        $this->nextStep($session, AgreementStep::ASK_DUE_DATE->value);
        $this->askDueDate($session);
    }

    protected function handleDueDateSelection(IncomingMessage $message, ConversationSession $session): void
    {
        $selection = null;

        if ($message->isListReply()) {
            $selection = $this->getSelectionId($message);
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
        $this->setTemp($session, 'due_date', $dueDate?->toDateString());
        $this->setTemp($session, 'due_date_selection', $selection);

        $this->nextStep($session, AgreementStep::CONFIRM_CREATE->value);
        $this->askConfirmation($session);
    }

    protected function handleConfirmation(IncomingMessage $message, ConversationSession $session): void
    {
        $action = null;

        if ($message->isInteractive()) {
            $action = $this->getSelectionId($message);
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
        $action = $message->isInteractive() ? $this->getSelectionId($message) : null;

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
        $intro = $isRetry ? "" : "📋 *Create Digital Agreement*\n\n" .
            "Record a financial agreement with someone.\n" .
            "Both parties confirm → PDF generated.\n\n";

        $message = $intro . "💱 *Transaction Direction*\n\nAre you giving or receiving money?";

        $this->sendButtonsWithBackAndMenu(
            $session->phone,
            $message,
            [
                ['id' => 'giving', 'title' => '💸 Giving Money'],
                ['id' => 'receiving', 'title' => '💰 Receiving'],
            ],
            '📝 New Agreement'
        );
    }

    protected function askAmount(ConversationSession $session, bool $isRetry = false): void
    {
        $direction = $this->getTemp($session, 'direction');
        $directionLabel = $direction === 'giving' ? 'giving' : 'receiving';

        $message = $isRetry
            ? "⚠️ Please enter a valid amount (numbers only)."
            : "💰 *Enter Amount*\n\n" .
              "You are *{$directionLabel}* money.\n\n" .
              "Enter the amount in rupees:\n\n" .
              "_Example: 25000_";

        $this->sendButtonsWithBackAndMenu(
            $session->phone,
            $message,
            [], // No action buttons, just back and menu
            '💰 Amount'
        );
    }

    protected function askName(ConversationSession $session, bool $isRetry = false): void
    {
        $direction = $this->getTemp($session, 'direction');
        $otherPartyRole = $direction === 'giving' ? 'receiving from you' : 'giving to you';

        $message = $isRetry
            ? "⚠️ Please enter a valid name (2-100 characters)."
            : "👤 *Other Party's Name*\n\n" .
              "Who is {$otherPartyRole}?\n\n" .
              "Enter their full name:";

        $this->sendButtonsWithBackAndMenu(
            $session->phone,
            $message,
            [],
            '👤 Name'
        );
    }

    protected function askPhone(ConversationSession $session, bool $isRetry = false): void
    {
        $name = $this->getTemp($session, 'other_party_name');

        $message = $isRetry
            ? "⚠️ Please enter a valid 10-digit mobile number.\n\n_Example: 9876543210_"
            : "📱 *{$name}'s WhatsApp Number*\n\n" .
              "Enter their 10-digit mobile number:\n\n" .
              "_Example: 9876543210_\n\n" .
              "⚠️ They will receive a confirmation request.";

        $this->sendButtonsWithBackAndMenu(
            $session->phone,
            $message,
            [],
            '📱 Phone'
        );
    }

    protected function askPurpose(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select a purpose from the list."
            : "📝 *Purpose of Agreement*\n\nWhat is this transaction for?";

        $this->sendListWithFooter(
            $session->phone,
            $message,
            '📝 Select Purpose',
            AgreementMessages::getPurposeSections(),
            '📝 Purpose'
        );
    }

    protected function askDescription(ConversationSession $session): void
    {
        $this->sendButtons(
            $session->phone,
            "📄 *Description (Optional)*\n\n" .
            "Add any notes about this agreement.\n\n" .
            "_Example: \"For home repair work\" or \"Monthly installment 1 of 3\"_\n\n" .
            "Type your description or tap Skip:",
            [
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'back', 'title' => '⬅️ Back'],
                self::MENU_BUTTON,
            ],
            '📄 Description',
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    protected function askDueDate(ConversationSession $session, bool $isRetry = false): void
    {
        $message = $isRetry
            ? "Please select a due date from the list."
            : "📅 *Due Date*\n\nWhen should this be settled?";

        $this->sendListWithFooter(
            $session->phone,
            $message,
            '📅 Select Due Date',
            AgreementMessages::getDueDateSections(),
            '📅 Due Date'
        );
    }

    protected function askConfirmation(ConversationSession $session): void
    {
        $direction = $this->getTemp($session, 'direction');
        $amount = $this->getTemp($session, 'amount');
        $name = $this->getTemp($session, 'other_party_name');
        $phone = $this->getTemp($session, 'other_party_phone');
        $purpose = $this->getTemp($session, 'purpose');
        $description = $this->getTemp($session, 'description');
        $dueDateSelection = $this->getTemp($session, 'due_date_selection');

        $dueDate = AgreementMessages::getDueDateFromSelection($dueDateSelection);

        $message = "📋 *Review Your Agreement*\n\n" .
            AgreementMessages::getDirectionEmoji($direction) . 
            " *" . AgreementMessages::getDirectionLabel($direction) . "*\n\n" .
            "👤 *Other Party:* {$name}\n" .
            "📱 *Phone:* {$phone}\n\n" .
            "💰 *Amount:* ₹" . number_format($amount) . "\n" .
            "📝 *Purpose:* " . AgreementMessages::getPurposeLabel($purpose) . "\n" .
            "📅 *Due Date:* " . AgreementMessages::formatDueDate($dueDate) . "\n" .
            "📄 *Description:* " . ($description ?? 'None') . "\n\n" .
            "✅ *Is this correct?*";

        $this->sendButtons(
            $session->phone,
            $message,
            [
                ['id' => 'confirm', 'title' => '✅ Create Agreement'],
                ['id' => 'edit', 'title' => '✏️ Edit'],
                ['id' => 'cancel', 'title' => '❌ Cancel'],
            ],
            '📋 Confirm',
            MessageTemplates::GLOBAL_FOOTER
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
            $user = $this->getUser($session);

            $dueDateString = $this->getTemp($session, 'due_date');
            $dueDate = $dueDateString ? \Carbon\Carbon::parse($dueDateString) : null;

            $agreement = $this->agreementService->createAgreement($user, [
                'direction' => $this->getTemp($session, 'direction'),
                'amount' => $this->getTemp($session, 'amount'),
                'other_party_name' => $this->getTemp($session, 'other_party_name'),
                'other_party_phone' => $this->getTemp($session, 'other_party_phone'),
                'purpose' => $this->getTemp($session, 'purpose'),
                'description' => $this->getTemp($session, 'description'),
                'due_date' => $dueDate,
            ]);

            // Store agreement ID
            $this->setTemp($session, 'created_agreement_id', $agreement->id);

            // Send success message
            $this->sendButtonsWithMenu(
                $session->phone,
                "🎉 *Agreement Created!*\n\n" .
                "📋 Agreement #: *{$agreement->agreement_number}*\n\n" .
                "📤 Confirmation request sent to:\n" .
                "👤 *{$agreement->to_name}*\n" .
                "📱 {$agreement->to_phone}\n\n" .
                "⏳ Waiting for their confirmation...\n\n" .
                "_Once confirmed, you'll both receive a PDF document._",
                [
                    ['id' => 'create_another', 'title' => '➕ Create Another'],
                    ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                ],
                '✅ Created'
            );

            // Send confirmation request to counterparty
            $this->sendConfirmationRequest($agreement);

            $this->nextStep($session, AgreementStep::CREATE_COMPLETE->value);

            $this->logInfo('Agreement created via flow', [
                'agreement_id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number,
            ]);

        } catch (\Exception $e) {
            $this->logError('Failed to create agreement', ['error' => $e->getMessage()]);

            $this->sendErrorWithOptions(
                $session->phone,
                "❌ *Creation Failed*\n\n" . $e->getMessage() . "\n\nPlease try again.",
                [
                    ['id' => 'retry', 'title' => '🔄 Try Again'],
                    self::MENU_BUTTON,
                ]
            );

            $this->start($session);
        }
    }

    protected function sendConfirmationRequest($agreement): void
    {
        $creator = $agreement->creator;
        $direction = $agreement->direction->value ?? 'giving';

        // Invert direction for counterparty perspective
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

        // *** FIX: Set up recipient's session for confirmation flow ***
        $recipientSession = $this->sessionManager->getOrCreate($agreement->to_phone);
        $this->sessionManager->setFlowStep(
            $recipientSession,
            FlowType::AGREEMENT_CONFIRM,
            AgreementStep::CONFIRM_AGREEMENT->value
        );
        $this->setTemp($recipientSession, 'confirm_agreement_id', $agreement->id);

        $this->sendButtons(
            $agreement->to_phone,
            $message,
            [
                ['id' => 'confirm', 'title' => '✅ Yes, Confirm'],
                ['id' => 'reject', 'title' => '❌ No, Incorrect'],
                ['id' => 'unknown', 'title' => "❓ Don't Know"],
            ],
            '📋 Confirm Agreement',
            MessageTemplates::GLOBAL_FOOTER
        );
    }

    protected function showAgreement(ConversationSession $session): void
    {
        $this->goToFlow($session, FlowType::AGREEMENT_LIST, AgreementStep::SHOW_LIST->value);
        app(\App\Services\Flow\FlowRouter::class)->startFlow($session, FlowType::AGREEMENT_LIST);
    }

    protected function restartCreation(ConversationSession $session): void
    {
        $this->sendTextWithMenu($session->phone, "🔄 Let's start over with your agreement.");
        $this->start($session);
    }

    protected function cancelCreation(ConversationSession $session): void
    {
        $this->clearTemp($session);
        $this->sendTextWithMenu($session->phone, "❌ *Agreement Cancelled*\n\nNo agreement was created.");
        $this->goToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

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