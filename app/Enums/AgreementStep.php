<?php

namespace App\Enums;

/**
 * Agreement flow steps (create, confirm, manage).
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
     */
    public function prompt(): string
    {
        return match ($this) {
            self::ASK_DIRECTION => "📝 *Create Agreement*\n\nAre you giving or receiving money?",
            self::ASK_OTHER_PARTY_PHONE => "Enter the other party's WhatsApp number (with country code, e.g., 919876543210):",
            self::ASK_OTHER_PARTY_NAME => "Enter the other party's name:",
            self::ASK_AMOUNT => "Enter the amount (numbers only):",
            self::ASK_PURPOSE => "What is the purpose of this agreement?",
            self::ASK_DESCRIPTION => "Add any notes or description (or type 'skip'):",
            self::ASK_DUE_DATE => "When is this due?",
            self::ASK_CUSTOM_DATE => "Enter the due date (DD/MM/YYYY):",
            self::CONFIRM_CREATE => "Please review and confirm this agreement:",
            self::CREATE_COMPLETE => "✅ Agreement created!\n\nThe other party will be notified to confirm.",

            self::SHOW_PENDING => "⏳ *Pending Agreements*\n\nThese agreements need your confirmation:",
            self::VIEW_PENDING => "Agreement details:",
            self::CONFIRM_AGREEMENT => "Do you confirm this agreement?",
            self::CONFIRMATION_COMPLETE => "✅ Agreement confirmed!\n\nA PDF document has been sent to both parties.",

            self::SHOW_LIST => "📋 *My Agreements*\n\nHere are your agreements:",
            self::VIEW_AGREEMENT => "Agreement details:",
            self::MARK_COMPLETE => "Are you sure you want to mark this as completed?",
            self::DISPUTE => "Are you sure you want to dispute this agreement?",
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
            self::ASK_DUE_DATE => self::CONFIRM_CREATE, // or ASK_CUSTOM_DATE
            self::ASK_CUSTOM_DATE => self::CONFIRM_CREATE,
            self::CONFIRM_CREATE => self::CREATE_COMPLETE,
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
}