<?php

namespace App\Enums;

/**
 * Payment method options for job payments.
 *
 * @srs-ref Section 3.5 - Job Verification & Payment
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum PaymentMethod: string
{
    case CASH = 'cash';
    case UPI = 'upi';
    case OTHER = 'other';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::CASH => 'Cash',
            self::UPI => 'UPI',
            self::OTHER => 'Other',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::CASH => 'പണം',
            self::UPI => 'യുപിഐ',
            self::OTHER => 'മറ്റുള്ളവ',
        };
    }

    /**
     * Get emoji icon.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::CASH => '💵',
            self::UPI => '📱',
            self::OTHER => '💳',
        };
    }

    /**
     * Get display with emoji.
     */
    public function display(): string
    {
        return $this->emoji() . ' ' . $this->label();
    }

    /**
     * Get button title for WhatsApp.
     */
    public function buttonTitle(): string
    {
        return $this->emoji() . ' ' . $this->label();
    }

    /**
     * Check if payment is digital.
     */
    public function isDigital(): bool
    {
        return $this === self::UPI;
    }

    /**
     * Check if payment requires reference number.
     */
    public function requiresReference(): bool
    {
        return $this === self::UPI;
    }

    /**
     * Get instruction for payment.
     */
    public function instruction(): string
    {
        return match ($this) {
            self::CASH => 'Please pay the worker in cash upon completion',
            self::UPI => 'Transfer payment via UPI to the worker',
            self::OTHER => 'Arrange payment with the worker',
        };
    }

    /**
     * Get instruction in Malayalam.
     */
    public function instructionMl(): string
    {
        return match ($this) {
            self::CASH => 'പണി കഴിഞ്ഞാൽ പണം കൊടുക്കുക',
            self::UPI => 'UPI വഴി പണം അയക്കുക',
            self::OTHER => 'പണിക്കാരനുമായി പേയ്മെന്റ് ക്രമീകരിക്കുക',
        };
    }

    /**
     * Convert to WhatsApp button.
     */
    public function toButton(): array
    {
        return [
            'id' => 'pay_' . $this->value,
            'title' => substr($this->buttonTitle(), 0, 20),
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
     * Get all values as array.
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