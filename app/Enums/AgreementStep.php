<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Agreement Flow Steps.
 *
 * Steps for all agreement-related flows:
 * - CREATE: 8-step creation flow (FR-AGR-01 to FR-AGR-08)
 * - CONFIRM: Counterparty confirmation flow (FR-AGR-10 to FR-AGR-15)
 * - LIST: View and manage agreements
 *
 * @srs-ref FR-AGR-01 to FR-AGR-25
 */
enum AgreementStep: string
{
    /*
    |--------------------------------------------------------------------------
    | Create Flow (FR-AGR-01 to FR-AGR-08)
    |--------------------------------------------------------------------------
    */

    /** FR-AGR-01: Direction selection */
    case ASK_DIRECTION = 'ask_direction';

    /** FR-AGR-02: Amount input */
    case ASK_AMOUNT = 'ask_amount';

    /** FR-AGR-03: Counterparty name */
    case ASK_NAME = 'ask_name';

    /** FR-AGR-04: Counterparty phone (10 digits) */
    case ASK_PHONE = 'ask_phone';

    /** FR-AGR-05: Purpose selection (5 types) */
    case ASK_PURPOSE = 'ask_purpose';

    /** FR-AGR-06: Description */
    case ASK_DESCRIPTION = 'ask_description';

    /** FR-AGR-07: Due date selection (5 options) */
    case ASK_DUE_DATE = 'ask_due_date';

    /** FR-AGR-08: Review and confirm */
    case REVIEW = 'review';

    /** Creation complete */
    case DONE = 'done';

    /*
    |--------------------------------------------------------------------------
    | Confirm Flow (FR-AGR-10 to FR-AGR-15)
    |--------------------------------------------------------------------------
    */

    /** Show list of pending confirmations */
    case PENDING_LIST = 'pending_list';

    /** View a specific pending agreement */
    case VIEW_PENDING = 'view_pending';

    /** FR-AGR-14: Awaiting confirmation choice */
    case AWAITING_CONFIRM = 'awaiting_confirm';

    /** Confirmation complete */
    case CONFIRM_DONE = 'confirm_done';

    /*
    |--------------------------------------------------------------------------
    | List/Manage Flow
    |--------------------------------------------------------------------------
    */

    /** Show user's agreements list */
    case MY_LIST = 'my_list';

    /** View agreement detail */
    case VIEW_DETAIL = 'view_detail';

    /** Mark as completed */
    case MARK_COMPLETE = 'mark_complete';

    /**
     * Get expected input type for this step.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::ASK_DIRECTION => 'button',
            self::ASK_AMOUNT => 'text',
            self::ASK_NAME => 'text',
            self::ASK_PHONE => 'text',
            self::ASK_PURPOSE => 'list',
            self::ASK_DESCRIPTION => 'text',
            self::ASK_DUE_DATE => 'list',
            self::REVIEW => 'button',
            self::DONE => 'button',
            self::PENDING_LIST => 'list',
            self::VIEW_PENDING => 'button',
            self::AWAITING_CONFIRM => 'button',
            self::CONFIRM_DONE => 'button',
            self::MY_LIST => 'list',
            self::VIEW_DETAIL => 'button',
            self::MARK_COMPLETE => 'button',
        };
    }

    /**
     * Check if create flow step.
     */
    public function isCreateStep(): bool
    {
        return in_array($this, self::createFlowSteps(), true);
    }

    /**
     * Check if confirm flow step.
     */
    public function isConfirmStep(): bool
    {
        return in_array($this, self::confirmFlowSteps(), true);
    }

    /**
     * Check if list flow step.
     */
    public function isListStep(): bool
    {
        return in_array($this, self::listFlowSteps(), true);
    }

    /**
     * Get previous step in create flow.
     */
    public function previousStep(): ?self
    {
        return match ($this) {
            self::ASK_AMOUNT => self::ASK_DIRECTION,
            self::ASK_NAME => self::ASK_AMOUNT,
            self::ASK_PHONE => self::ASK_NAME,
            self::ASK_PURPOSE => self::ASK_PHONE,
            self::ASK_DESCRIPTION => self::ASK_PURPOSE,
            self::ASK_DUE_DATE => self::ASK_DESCRIPTION,
            self::REVIEW => self::ASK_DUE_DATE,
            default => null,
        };
    }

    /**
     * Get next step in create flow.
     */
    public function nextStep(): ?self
    {
        return match ($this) {
            self::ASK_DIRECTION => self::ASK_AMOUNT,
            self::ASK_AMOUNT => self::ASK_NAME,
            self::ASK_NAME => self::ASK_PHONE,
            self::ASK_PHONE => self::ASK_PURPOSE,
            self::ASK_PURPOSE => self::ASK_DESCRIPTION,
            self::ASK_DESCRIPTION => self::ASK_DUE_DATE,
            self::ASK_DUE_DATE => self::REVIEW,
            self::REVIEW => self::DONE,
            default => null,
        };
    }

    /**
     * Get field name for this step.
     */
    public function fieldName(): ?string
    {
        return match ($this) {
            self::ASK_DIRECTION => 'direction',
            self::ASK_AMOUNT => 'amount',
            self::ASK_NAME => 'other_party_name',
            self::ASK_PHONE => 'other_party_phone',
            self::ASK_PURPOSE => 'purpose',
            self::ASK_DESCRIPTION => 'description',
            self::ASK_DUE_DATE => 'due_date',
            default => null,
        };
    }

    /**
     * Create flow steps in order.
     */
    public static function createFlowSteps(): array
    {
        return [
            self::ASK_DIRECTION,
            self::ASK_AMOUNT,
            self::ASK_NAME,
            self::ASK_PHONE,
            self::ASK_PURPOSE,
            self::ASK_DESCRIPTION,
            self::ASK_DUE_DATE,
            self::REVIEW,
            self::DONE,
        ];
    }

    /**
     * Confirm flow steps.
     */
    public static function confirmFlowSteps(): array
    {
        return [
            self::PENDING_LIST,
            self::VIEW_PENDING,
            self::AWAITING_CONFIRM,
            self::CONFIRM_DONE,
        ];
    }

    /**
     * List flow steps.
     */
    public static function listFlowSteps(): array
    {
        return [
            self::MY_LIST,
            self::VIEW_DETAIL,
            self::MARK_COMPLETE,
        ];
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}