<?php

namespace App\Enums;

/**
 * Agreement flow steps (create, confirm, manage).
 *
 * @srs-ref FR-AGR-01 to FR-AGR-25
 */
enum AgreementStep: string
{
    // Create flow
    case ASK_DIRECTION = 'ask_direction';
    case ASK_OTHER_PARTY_PHONE = 'ask_other_party_phone';
    case ASK_OTHER_PARTY_NAME = 'ask_other_party_name';
    case ASK_AMOUNT = 'ask_amount';
    case ASK_PURPOSE = 'ask_purpose';
    case ASK_DESCRIPTION = 'ask_description';
    case ASK_DUE_DATE = 'ask_due_date';
    case ASK_CUSTOM_DATE = 'ask_custom_date';
    case CONFIRM_CREATE = 'confirm_create';
    case CREATE_COMPLETE = 'create_complete';

    // Confirm flow (recipient)
    case SHOW_PENDING = 'show_pending';
    case VIEW_PENDING = 'view_pending';
    case CONFIRM_AGREEMENT = 'confirm_agreement';
    case CONFIRMATION_COMPLETE = 'confirmation_complete';

    // List/manage flow
    case SHOW_LIST = 'show_list';
    case VIEW_AGREEMENT = 'view_agreement';
    case MARK_COMPLETE = 'mark_complete';
    case DISPUTE = 'dispute';

    /**
     * Get the prompt message for this step.
     *
     * @srs-ref FR-AGR-01 to FR-AGR-08, FR-AGR-14
     */
    public function prompt(): string
    {
        return match ($this) {
            // Create flow prompts
            self::ASK_DIRECTION => "📝 *Create New Agreement*\n\nWhat is your role in this transaction?",

            // FR-AGR-04: Specify 10-digit phone with country code
            self::ASK_OTHER_PARTY_PHONE => "📱 Enter the other party's WhatsApp number:\n\n_Include country code (e.g., 919876543210 for India)_",

            self::ASK_OTHER_PARTY_NAME => "👤 Enter the other party's full name:",

            // FR-AGR-02: Specify numeric input with currency symbol
            self::ASK_AMOUNT => "💰 Enter the amount (numbers only):\n\n_Example: 5000_",

            // SRS 8.2: Reference purpose types
            self::ASK_PURPOSE => "📋 What is the purpose of this agreement?\n\nSelect from the options below:",

            self::ASK_DESCRIPTION => "📝 Add any notes or description:\n\n_Type 'skip' to proceed without notes_",

            // SRS 8.4: Reference due date options
            self::ASK_DUE_DATE => "📅 When should this be settled?",

            self::ASK_CUSTOM_DATE => "📅 Enter the due date:\n\n_Format: DD/MM/YYYY_",

            self::CONFIRM_CREATE => "📋 *Review Agreement*\n\nPlease verify all details are correct:",

            self::CREATE_COMPLETE => "✅ *Agreement Created Successfully!*\n\nThe other party will receive a confirmation request.",

            // Confirm flow prompts - FR-AGR-12
            self::SHOW_PENDING => "⏳ *Pending Confirmations*\n\nThe following agreements need your confirmation:",

            self::VIEW_PENDING => "📄 *Agreement Details*\n\nReview the details below:",

            // FR-AGR-14: Match button options
            self::CONFIRM_AGREEMENT => "✅ *Confirm Agreement*\n\nDo you confirm that these details are correct?",

            // FR-AGR-25: Reference PDF delivery
            self::CONFIRMATION_COMPLETE => "✅ *Agreement Confirmed!*\n\nA PDF document has been generated and sent to both parties.",

            // List/manage flow prompts
            self::SHOW_LIST => "📋 *My Agreements*\n\nYour active and completed agreements:",

            self::VIEW_AGREEMENT => "📄 *Agreement Details*",

            self::MARK_COMPLETE => "✅ *Mark as Completed*\n\nAre you sure you want to mark this agreement as completed?",

            self::DISPUTE => "⚠️ *Dispute Agreement*\n\nAre you sure you want to raise a dispute for this agreement?\n\n_Both parties will be notified._",
        };
    }

    /**
     * Get the expected input type for this step.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::ASK_DIRECTION => 'button',
            self::ASK_OTHER_PARTY_PHONE => 'text',
            self::ASK_OTHER_PARTY_NAME => 'text',
            self::ASK_AMOUNT => 'text',
            self::ASK_PURPOSE => 'list',
            self::ASK_DESCRIPTION => 'text',
            self::ASK_DUE_DATE => 'list',
            self::ASK_CUSTOM_DATE => 'text',
            self::CONFIRM_CREATE => 'button',
            self::CREATE_COMPLETE => 'none',

            self::SHOW_PENDING => 'list',
            self::VIEW_PENDING => 'button',
            self::CONFIRM_AGREEMENT => 'button',
            self::CONFIRMATION_COMPLETE => 'none',

            self::SHOW_LIST => 'list',
            self::VIEW_AGREEMENT => 'button',
            self::MARK_COMPLETE, self::DISPUTE => 'button',
        };
    }

    /**
     * Get button options for button-type steps.
     *
     * @srs-ref FR-AGR-01 (direction), FR-AGR-08 (confirm), FR-AGR-14 (recipient confirm)
     * @return array<string, string> Button ID => Label mapping
     */
    public function buttonOptions(): array
    {
        return match ($this) {
            // FR-AGR-01: Direction selection
            self::ASK_DIRECTION => [
                'dir_giving' => '💸 Giving Money',
                'dir_receiving' => '💰 Receiving Money',
            ],

            // FR-AGR-08: Confirm/Edit/Cancel for creation
            self::CONFIRM_CREATE => [
                'create_confirm' => '✅ Confirm',
                'create_edit' => '✏️ Edit',
                'create_cancel' => '❌ Cancel',
            ],

            // FR-AGR-14: Recipient confirmation options
            self::CONFIRM_AGREEMENT => [
                'confirm_yes' => '✅ Yes, Confirm',
                'confirm_no' => '❌ No, Incorrect',
                'confirm_unknown' => '❓ Don\'t Know',
            ],

            // View pending agreement actions
            self::VIEW_PENDING => [
                'view_confirm' => '✅ Confirm',
                'view_reject' => '❌ Reject',
                'view_back' => '◀️ Back',
            ],

            // View agreement actions
            self::VIEW_AGREEMENT => [
                'action_complete' => '✅ Mark Complete',
                'action_dispute' => '⚠️ Dispute',
                'action_download' => '📄 Download PDF',
                'action_back' => '◀️ Back',
            ],

            // Mark complete confirmation
            self::MARK_COMPLETE => [
                'complete_yes' => '✅ Yes, Complete',
                'complete_no' => '❌ No, Cancel',
            ],

            // Dispute confirmation
            self::DISPUTE => [
                'dispute_yes' => '⚠️ Yes, Dispute',
                'dispute_no' => '❌ No, Cancel',
            ],

            default => [],
        };
    }

    /**
     * Get list options for list-type steps.
     *
     * @srs-ref 8.2 (Purpose Types), 8.4 (Due Date Options)
     * @return array<string, array{title: string, description: string}>
     */
    public function listOptions(): array
    {
        return match ($this) {
            // SRS 8.2: Agreement Purpose Types
            self::ASK_PURPOSE => [
                'loan' => [
                    'title' => '🤝 Loan',
                    'description' => 'Lending to friend/family',
                ],
                'advance' => [
                    'title' => '🔧 Advance',
                    'description' => 'Advance for work - painting, repair',
                ],
                'deposit' => [
                    'title' => '🏠 Deposit',
                    'description' => 'Rent, booking, or purchase deposit',
                ],
                'business' => [
                    'title' => '💼 Business',
                    'description' => 'Vendor or supplier payment',
                ],
                'other' => [
                    'title' => '📝 Other',
                    'description' => 'Other purposes',
                ],
            ],

            // SRS 8.4: Due Date Options
            self::ASK_DUE_DATE => [
                'due_1week' => [
                    'title' => '1 Week',
                    'description' => 'Due in 7 days',
                ],
                'due_2weeks' => [
                    'title' => '2 Weeks',
                    'description' => 'Due in 14 days',
                ],
                'due_1month' => [
                    'title' => '1 Month',
                    'description' => 'Due in 30 days',
                ],
                'due_3months' => [
                    'title' => '3 Months',
                    'description' => 'Due in 90 days',
                ],
                'due_none' => [
                    'title' => 'No Fixed Date',
                    'description' => 'Open-ended agreement',
                ],
                'due_custom' => [
                    'title' => 'Custom Date',
                    'description' => 'Enter specific date',
                ],
            ],

            default => [],
        };
    }

    /**
     * Validate input for this step.
     *
     * @param mixed $input The user input to validate
     * @return array{valid: bool, error?: string, sanitized?: mixed}
     */
    public function validateInput(mixed $input): array
    {
        return match ($this) {
            // FR-AGR-04: Phone number validation (10+ digits with country code)
            self::ASK_OTHER_PARTY_PHONE => $this->validatePhone($input),

            // FR-AGR-02: Numeric amount validation
            self::ASK_AMOUNT => $this->validateAmount($input),

            // Custom date validation (DD/MM/YYYY format)
            self::ASK_CUSTOM_DATE => $this->validateDate($input),

            // Name validation
            self::ASK_OTHER_PARTY_NAME => $this->validateName($input),

            default => ['valid' => true, 'sanitized' => $input],
        };
    }

    /**
     * Validate phone number input.
     *
     * @param mixed $input
     * @return array{valid: bool, error?: string, sanitized?: string}
     */
    private function validatePhone(mixed $input): array
    {
        $phone = preg_replace('/[^0-9]/', '', (string) $input);

        if (strlen($phone) < 10) {
            return [
                'valid' => false,
                'error' => '❌ Phone number too short. Please include country code (e.g., 919876543210)',
            ];
        }

        if (strlen($phone) > 15) {
            return [
                'valid' => false,
                'error' => '❌ Phone number too long. Please enter a valid WhatsApp number.',
            ];
        }

        return [
            'valid' => true,
            'sanitized' => $phone,
        ];
    }

    /**
     * Validate amount input.
     *
     * @param mixed $input
     * @return array{valid: bool, error?: string, sanitized?: float}
     */
    private function validateAmount(mixed $input): array
    {
        // Remove currency symbols, commas, and spaces
        $cleaned = preg_replace('/[₹$,\s]/', '', (string) $input);

        if (!is_numeric($cleaned)) {
            return [
                'valid' => false,
                'error' => '❌ Please enter a valid amount (numbers only, e.g., 5000)',
            ];
        }

        $amount = floatval($cleaned);

        if ($amount <= 0) {
            return [
                'valid' => false,
                'error' => '❌ Amount must be greater than zero.',
            ];
        }

        if ($amount > 99999999.99) {
            return [
                'valid' => false,
                'error' => '❌ Amount exceeds maximum limit.',
            ];
        }

        return [
            'valid' => true,
            'sanitized' => round($amount, 2),
        ];
    }

    /**
     * Validate date input.
     *
     * @param mixed $input
     * @return array{valid: bool, error?: string, sanitized?: \DateTime}
     */
    private function validateDate(mixed $input): array
    {
        $input = trim((string) $input);

        // Try DD/MM/YYYY format
        $date = \DateTime::createFromFormat('d/m/Y', $input);

        if (!$date || $date->format('d/m/Y') !== $input) {
            // Try DD-MM-YYYY format
            $date = \DateTime::createFromFormat('d-m-Y', $input);

            if (!$date || $date->format('d-m-Y') !== str_replace('/', '-', $input)) {
                return [
                    'valid' => false,
                    'error' => '❌ Please enter a valid date in DD/MM/YYYY format (e.g., 25/01/2026)',
                ];
            }
        }

        // Check if date is in the past
        $today = new \DateTime('today');
        if ($date < $today) {
            return [
                'valid' => false,
                'error' => '❌ Due date cannot be in the past. Please enter a future date.',
            ];
        }

        // Check if date is too far in the future (e.g., 5 years)
        $maxDate = (new \DateTime())->modify('+5 years');
        if ($date > $maxDate) {
            return [
                'valid' => false,
                'error' => '❌ Due date is too far in the future. Please enter a date within 5 years.',
            ];
        }

        return [
            'valid' => true,
            'sanitized' => $date,
        ];
    }

    /**
     * Validate name input.
     *
     * @param mixed $input
     * @return array{valid: bool, error?: string, sanitized?: string}
     */
    private function validateName(mixed $input): array
    {
        $name = trim((string) $input);

        if (strlen($name) < 2) {
            return [
                'valid' => false,
                'error' => '❌ Name is too short. Please enter a valid name.',
            ];
        }

        if (strlen($name) > 100) {
            return [
                'valid' => false,
                'error' => '❌ Name is too long. Please enter a shorter name.',
            ];
        }

        // Check for invalid characters
        if (preg_match('/[<>{}[\]\\\\]/', $name)) {
            return [
                'valid' => false,
                'error' => '❌ Name contains invalid characters.',
            ];
        }

        return [
            'valid' => true,
            'sanitized' => $name,
        ];
    }

    /**
     * Check if this is a create flow step.
     */
    public function isCreateStep(): bool
    {
        return in_array($this, [
            self::ASK_DIRECTION,
            self::ASK_OTHER_PARTY_PHONE,
            self::ASK_OTHER_PARTY_NAME,
            self::ASK_AMOUNT,
            self::ASK_PURPOSE,
            self::ASK_DESCRIPTION,
            self::ASK_DUE_DATE,
            self::ASK_CUSTOM_DATE,
            self::CONFIRM_CREATE,
            self::CREATE_COMPLETE,
        ]);
    }

    /**
     * Check if this is a confirm flow step.
     */
    public function isConfirmStep(): bool
    {
        return in_array($this, [
            self::SHOW_PENDING,
            self::VIEW_PENDING,
            self::CONFIRM_AGREEMENT,
            self::CONFIRMATION_COMPLETE,
        ]);
    }

    /**
     * Check if this is a list/manage flow step.
     */
    public function isListStep(): bool
    {
        return in_array($this, [
            self::SHOW_LIST,
            self::VIEW_AGREEMENT,
            self::MARK_COMPLETE,
            self::DISPUTE,
        ]);
    }

    /**
     * Check if this step is a terminal/completion step.
     */
    public function isTerminalStep(): bool
    {
        return in_array($this, [
            self::CREATE_COMPLETE,
            self::CONFIRMATION_COMPLETE,
        ]);
    }

    /**
     * Check if this step allows going back.
     */
    public function canGoBack(): bool
    {
        return !in_array($this, [
            self::ASK_DIRECTION,
            self::SHOW_PENDING,
            self::SHOW_LIST,
            self::CREATE_COMPLETE,
            self::CONFIRMATION_COMPLETE,
        ]);
    }

    /**
     * Get the previous step in create flow.
     */
    public function previousCreateStep(): ?self
    {
        return match ($this) {
            self::ASK_OTHER_PARTY_PHONE => self::ASK_DIRECTION,
            self::ASK_OTHER_PARTY_NAME => self::ASK_OTHER_PARTY_PHONE,
            self::ASK_AMOUNT => self::ASK_OTHER_PARTY_NAME,
            self::ASK_PURPOSE => self::ASK_AMOUNT,
            self::ASK_DESCRIPTION => self::ASK_PURPOSE,
            self::ASK_DUE_DATE => self::ASK_DESCRIPTION,
            self::ASK_CUSTOM_DATE => self::ASK_DUE_DATE,
            self::CONFIRM_CREATE => self::ASK_DUE_DATE,
            default => null,
        };
    }

    /**
     * Get the next step in create flow.
     */
    public function nextCreateStep(): ?self
    {
        return match ($this) {
            self::ASK_DIRECTION => self::ASK_OTHER_PARTY_PHONE,
            self::ASK_OTHER_PARTY_PHONE => self::ASK_OTHER_PARTY_NAME,
            self::ASK_OTHER_PARTY_NAME => self::ASK_AMOUNT,
            self::ASK_AMOUNT => self::ASK_PURPOSE,
            self::ASK_PURPOSE => self::ASK_DESCRIPTION,
            self::ASK_DESCRIPTION => self::ASK_DUE_DATE,
            self::ASK_DUE_DATE => self::CONFIRM_CREATE, // or ASK_CUSTOM_DATE based on selection
            self::ASK_CUSTOM_DATE => self::CONFIRM_CREATE,
            self::CONFIRM_CREATE => self::CREATE_COMPLETE,
            default => null,
        };
    }

    /**
     * Get the field name this step collects data for.
     */
    public function fieldName(): ?string
    {
        return match ($this) {
            self::ASK_DIRECTION => 'direction',
            self::ASK_OTHER_PARTY_PHONE => 'other_party_phone',
            self::ASK_OTHER_PARTY_NAME => 'other_party_name',
            self::ASK_AMOUNT => 'amount',
            self::ASK_PURPOSE => 'purpose',
            self::ASK_DESCRIPTION => 'description',
            self::ASK_DUE_DATE => 'due_date_option',
            self::ASK_CUSTOM_DATE => 'due_date',
            default => null,
        };
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all create flow steps in order.
     */
    public static function createFlowSteps(): array
    {
        return [
            self::ASK_DIRECTION,
            self::ASK_OTHER_PARTY_PHONE,
            self::ASK_OTHER_PARTY_NAME,
            self::ASK_AMOUNT,
            self::ASK_PURPOSE,
            self::ASK_DESCRIPTION,
            self::ASK_DUE_DATE,
            self::CONFIRM_CREATE,
            self::CREATE_COMPLETE,
        ];
    }

    /**
     * Get all confirm flow steps in order.
     */
    public static function confirmFlowSteps(): array
    {
        return [
            self::SHOW_PENDING,
            self::VIEW_PENDING,
            self::CONFIRM_AGREEMENT,
            self::CONFIRMATION_COMPLETE,
        ];
    }

    /**
     * Get all list/manage flow steps.
     */
    public static function listFlowSteps(): array
    {
        return [
            self::SHOW_LIST,
            self::VIEW_AGREEMENT,
            self::MARK_COMPLETE,
            self::DISPUTE,
        ];
    }
}