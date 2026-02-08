<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Agreement Purpose Types.
 *
 * @srs-ref Appendix 8.2 - Agreement Purpose Types
 *
 * 🤝 Loan — Lending to friend/family
 * 🔧 Advance — Advance for work (painting, repair, service)
 * 🏠 Deposit — Rent, booking, purchase deposit
 * 💼 Business — Vendor, supplier payment
 * 📝 Other — Other purposes
 */
enum AgreementPurpose: string
{
    case LOAN = 'loan';
    case ADVANCE = 'advance';
    case DEPOSIT = 'deposit';
    case BUSINESS = 'business';
    case OTHER = 'other';

    /**
     * Icon (from SRS Appendix 8.2).
     */
    public function icon(): string
    {
        return match ($this) {
            self::LOAN => '🤝',
            self::ADVANCE => '🔧',
            self::DEPOSIT => '🏠',
            self::BUSINESS => '💼',
            self::OTHER => '📝',
        };
    }

    /**
     * Display label (English).
     */
    public function label(): string
    {
        return match ($this) {
            self::LOAN => 'Loan',
            self::ADVANCE => 'Advance',
            self::DEPOSIT => 'Deposit',
            self::BUSINESS => 'Business',
            self::OTHER => 'Other',
        };
    }

    /**
     * Display label (Malayalam).
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::LOAN => 'വായ്പ',
            self::ADVANCE => 'അഡ്വാൻസ്',
            self::DEPOSIT => 'ഡെപ്പോസിറ്റ്',
            self::BUSINESS => 'ബിസിനസ്',
            self::OTHER => 'മറ്റുള്ളവ',
        };
    }

    /**
     * Description (from SRS Appendix 8.2).
     */
    public function description(): string
    {
        return match ($this) {
            self::LOAN => 'Lending to friend/family',
            self::ADVANCE => 'Advance for work - painting, repair, service',
            self::DEPOSIT => 'Deposit - rent, booking, purchase',
            self::BUSINESS => 'Business - vendor, supplier payment',
            self::OTHER => 'Other purposes',
        };
    }

    /**
     * Description (Malayalam).
     */
    public function descriptionMl(): string
    {
        return match ($this) {
            self::LOAN => 'സുഹൃത്ത്/കുടുംബത്തിന് കടം',
            self::ADVANCE => 'പണി അഡ്വാൻസ് - പെയിന്റിംഗ്, റിപ്പയർ',
            self::DEPOSIT => 'വാടക, ബുക്കിംഗ് ഡെപ്പോസിറ്റ്',
            self::BUSINESS => 'വെണ്ടർ, സപ്ലയർ പേയ്മെന്റ്',
            self::OTHER => 'മറ്റ് ആവശ്യങ്ങൾ',
        };
    }

    /**
     * Display with icon.
     */
    public function displayWithIcon(): string
    {
        return "{$this->icon()} {$this->label()}";
    }

    /**
     * Hint text for description step.
     */
    public function descriptionHint(): string
    {
        return match ($this) {
            self::LOAN => 'Eg: Personal loan, will return in installments',
            self::ADVANCE => 'Eg: Painting work advance, remaining after completion',
            self::DEPOSIT => 'Eg: House rent deposit for 1 year',
            self::BUSINESS => 'Eg: Material purchase for shop',
            self::OTHER => 'Eg: Describe the purpose',
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
     * Get as list rows for WhatsApp (FR-AGR-05).
     */
    public static function toListRows(): array
    {
        return array_map(fn(self $p) => [
            'id' => $p->value,
            'title' => mb_substr($p->displayWithIcon(), 0, 24),
            'description' => mb_substr($p->description(), 0, 72),
        ], self::cases());
    }
}