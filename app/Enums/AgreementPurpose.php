<?php

namespace App\Enums;

/**
 * Agreement purpose types.
 */
enum AgreementPurpose: string
{
    case LOAN = 'loan';
    case WORK_ADVANCE = 'work_advance';
    case DEPOSIT = 'deposit';
    case BUSINESS_PAYMENT = 'business_payment';
    case OTHER = 'other';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return config("nearbuy.agreements.purposes.{$this->value}.name", ucfirst(str_replace('_', ' ', $this->value)));
    }

    /**
     * Get the Malayalam label.
     */
    public function labelMalayalam(): string
    {
        return config("nearbuy.agreements.purposes.{$this->value}.name_ml", $this->label());
    }

    /**
     * Get purpose emoji.
     */
    public function icon(): string
    {
        return config("nearbuy.agreements.purposes.{$this->value}.icon", '📝');
    }

    /**
     * Get formatted display with icon.
     */
    public function displayWithIcon(): string
    {
        return "{$this->icon()} {$this->label()}";
    }

    /**
     * Get all purposes for WhatsApp button selection.
     *
     * @return array<int, array{id: string, title: string}>
     */
    public static function toButtons(): array
    {
        // WhatsApp allows max 3 buttons, so we return top 3 + use list for more
        $top = [self::LOAN, self::WORK_ADVANCE, self::DEPOSIT];

        return array_map(fn (self $purpose) => [
            'id' => $purpose->value,
            'title' => substr($purpose->displayWithIcon(), 0, 20), // WhatsApp button limit
        ], $top);
    }

    /**
     * Get all purposes for WhatsApp list selection.
     *
     * @return array<int, array{id: string, title: string, description: string}>
     */
    public static function toListItems(): array
    {
        return array_map(fn (self $purpose) => [
            'id' => $purpose->value,
            'title' => substr($purpose->displayWithIcon(), 0, 24),
            'description' => '',
        ], self::cases());
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}