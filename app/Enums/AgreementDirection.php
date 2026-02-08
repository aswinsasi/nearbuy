<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Agreement Direction (from creator's perspective).
 *
 * @srs-ref FR-AGR-01 Direction: Giving Money / Receiving Money
 */
enum AgreementDirection: string
{
    case GIVING = 'giving';
    case RECEIVING = 'receiving';

    /**
     * Display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::GIVING => 'Giving Money',
            self::RECEIVING => 'Receiving Money',
        };
    }

    /**
     * Short label.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::GIVING => 'Giving',
            self::RECEIVING => 'Receiving',
        };
    }

    /**
     * Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::GIVING => 'പണം കൊടുക്കുന്നു',
            self::RECEIVING => 'പണം വാങ്ങുന്നു',
        };
    }

    /**
     * Icon for direction.
     */
    public function icon(): string
    {
        return match ($this) {
            self::GIVING => '💸',
            self::RECEIVING => '📥',
        };
    }

    /**
     * Arrow for compact list display.
     * ↗️ = gave (outgoing), ↙️ = received (incoming)
     */
    public function arrow(): string
    {
        return match ($this) {
            self::GIVING => '↗️',
            self::RECEIVING => '↙️',
        };
    }

    /**
     * Get past tense verb.
     */
    public function verbPast(): string
    {
        return match ($this) {
            self::GIVING => 'Gave',
            self::RECEIVING => 'Received',
        };
    }

    /**
     * Get present tense verb.
     */
    public function verbPresent(): string
    {
        return match ($this) {
            self::GIVING => 'Give',
            self::RECEIVING => 'Receive',
        };
    }

    /**
     * Display with icon.
     */
    public function displayWithIcon(): string
    {
        return $this->icon() . ' ' . $this->label();
    }

    /**
     * Display with arrow (for compact lists).
     */
    public function displayWithArrow(): string
    {
        return $this->arrow() . ' ' . $this->verbPast();
    }

    /**
     * Get opposite direction.
     */
    public function opposite(): self
    {
        return match ($this) {
            self::GIVING => self::RECEIVING,
            self::RECEIVING => self::GIVING,
        };
    }

    /**
     * Is creator the creditor (lender)?
     */
    public function isCreatorCreditor(): bool
    {
        return $this === self::GIVING;
    }

    /**
     * Get role label for creator.
     */
    public function creatorRole(): string
    {
        return match ($this) {
            self::GIVING => 'Creditor (Lender)',
            self::RECEIVING => 'Debtor (Borrower)',
        };
    }

    /**
     * Get role label for counterparty.
     */
    public function counterpartyRole(): string
    {
        return match ($this) {
            self::GIVING => 'Debtor (Borrower)',
            self::RECEIVING => 'Creditor (Lender)',
        };
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Convert to WhatsApp buttons.
     */
    public static function toButtons(): array
    {
        return [
            [
                'id' => self::GIVING->value,
                'title' => '💸 Giving Money',
            ],
            [
                'id' => self::RECEIVING->value,
                'title' => '📥 Receiving Money',
            ],
        ];
    }
}