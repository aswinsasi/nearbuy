<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Payment Method Options.
 *
 * Used for job payments and agreement settlements.
 */
enum PaymentMethod: string
{
    case CASH = 'cash';
    case UPI = 'upi';
    case BANK_TRANSFER = 'bank_transfer';
    case OTHER = 'other';

    /**
     * Display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash',
            self::UPI => 'UPI',
            self::BANK_TRANSFER => 'Bank Transfer',
            self::OTHER => 'Other',
        };
    }

    /**
     * Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::CASH => 'പണം',
            self::UPI => 'യുപിഐ',
            self::BANK_TRANSFER => 'ബാങ്ക് ട്രാൻസ്ഫർ',
            self::OTHER => 'മറ്റുള്ളവ',
        };
    }

    /**
     * Icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::CASH => '💵',
            self::UPI => '📱',
            self::BANK_TRANSFER => '🏦',
            self::OTHER => '💳',
        };
    }

    /**
     * Display with icon.
     */
    public function display(): string
    {
        return $this->icon() . ' ' . $this->label();
    }

    /**
     * Is digital payment?
     */
    public function isDigital(): bool
    {
        return in_array($this, [self::UPI, self::BANK_TRANSFER]);
    }

    /**
     * Requires reference number?
     */
    public function requiresReference(): bool
    {
        return in_array($this, [self::UPI, self::BANK_TRANSFER]);
    }

    /**
     * Payment instruction.
     */
    public function instruction(): string
    {
        return match ($this) {
            self::CASH => 'Pay in cash',
            self::UPI => 'Transfer via UPI',
            self::BANK_TRANSFER => 'Transfer to bank account',
            self::OTHER => 'Arrange payment as agreed',
        };
    }

    /**
     * Malayalam instruction.
     */
    public function instructionMl(): string
    {
        return match ($this) {
            self::CASH => 'പണം കൊടുക്കുക',
            self::UPI => 'UPI വഴി അയക്കുക',
            self::BANK_TRANSFER => 'ബാങ്ക് അക്കൗണ്ടിലേക്ക് ട്രാൻസ്ഫർ ചെയ്യുക',
            self::OTHER => 'ധാരണ പ്രകാരം പേയ്മെന്റ് ക്രമീകരിക്കുക',
        };
    }

    /**
     * Convert to WhatsApp button.
     */
    public function toButton(): array
    {
        return [
            'id' => 'pay_' . $this->value,
            'title' => mb_substr($this->display(), 0, 20),
        ];
    }

    /**
     * Get all as WhatsApp buttons.
     */
    public static function toButtons(): array
    {
        return array_map(fn(self $method) => $method->toButton(), self::cases());
    }

    /**
     * Get common payment methods as buttons (max 3 for WhatsApp).
     */
    public static function commonButtons(): array
    {
        return [
            self::CASH->toButton(),
            self::UPI->toButton(),
            self::BANK_TRANSFER->toButton(),
        ];
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Create from button ID.
     */
    public static function fromButtonId(string $buttonId): ?self
    {
        $value = str_replace('pay_', '', $buttonId);
        return self::tryFrom($value);
    }
}