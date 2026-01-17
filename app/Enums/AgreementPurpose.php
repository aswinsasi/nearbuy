<?php

namespace App\Enums;

/**
 * Agreement purpose/type values.
 */
enum AgreementPurpose: string
{
    case LOAN = 'loan';
    case ADVANCE = 'advance';
    case DEPOSIT = 'deposit';
    case BUSINESS = 'business';
    case OTHER = 'other';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::LOAN => 'Personal Loan',
            self::ADVANCE => 'Work Advance',
            self::DEPOSIT => 'Security Deposit',
            self::BUSINESS => 'Business Payment',
            self::OTHER => 'Other',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::LOAN => 'വ്യക്തിഗത വായ്പ',
            self::ADVANCE => 'ജോലി അഡ്വാൻസ്',
            self::DEPOSIT => 'സെക്യൂരിറ്റി ഡെപ്പോസിറ്റ്',
            self::BUSINESS => 'ബിസിനസ് പേയ്മെന്റ്',
            self::OTHER => 'മറ്റുള്ളവ',
        };
    }

    /**
     * Get icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::LOAN => '💰',
            self::ADVANCE => '💼',
            self::DEPOSIT => '🏠',
            self::BUSINESS => '🏢',
            self::OTHER => '📝',
        };
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return match ($this) {
            self::LOAN => 'Money lent to or borrowed from someone',
            self::ADVANCE => 'Work-related advance payment',
            self::DEPOSIT => 'Security deposit for rental or service',
            self::BUSINESS => 'Business transaction payment',
            self::OTHER => 'Other financial agreement',
        };
    }

    /**
     * Get formatted display with icon.
     */
    public function displayWithIcon(): string
    {
        return "{$this->icon()} {$this->label()}";
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get options for WhatsApp buttons (max 3).
     */
    public static function toButtons(): array
    {
        $primary = [self::LOAN, self::ADVANCE, self::DEPOSIT];

        return array_map(fn(self $purpose) => [
            'id' => $purpose->value,
            'title' => substr($purpose->displayWithIcon(), 0, 20),
        ], $primary);
    }

    /**
     * Get options for WhatsApp list.
     */
    public static function toListItems(): array
    {
        return array_map(fn(self $purpose) => [
            'id' => $purpose->value,
            'title' => substr($purpose->displayWithIcon(), 0, 24),
            'description' => substr($purpose->description(), 0, 72),
        ], self::cases());
    }
}