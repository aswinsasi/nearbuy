<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Agreement Status Values.
 *
 * @srs-ref Section 6.3 Enumeration Values - agreements.status
 * Values: pending, active, completed, disputed, cancelled
 */
enum AgreementStatus: string
{
    case PENDING = 'pending';       // Waiting for counterparty confirmation
    case CONFIRMED = 'confirmed';   // Both parties confirmed (SRS: "active")
    case COMPLETED = 'completed';   // Settlement done
    case DISPUTED = 'disputed';     // Counterparty claims unknown
    case REJECTED = 'rejected';     // Counterparty rejected details
    case CANCELLED = 'cancelled';   // Creator cancelled
    case EXPIRED = 'expired';       // Confirmation period expired

    /**
     * Display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Confirmation',
            self::CONFIRMED => 'Active',
            self::COMPLETED => 'Completed',
            self::DISPUTED => 'Disputed',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
            self::EXPIRED => 'Expired',
        };
    }

    /**
     * Short label for compact lists.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::CONFIRMED => 'Active',
            self::COMPLETED => 'Done',
            self::DISPUTED => 'Disputed',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
            self::EXPIRED => 'Expired',
        };
    }

    /**
     * Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::PENDING => 'കാത്തിരിക്കുന്നു',
            self::CONFIRMED => 'സജീവം',
            self::COMPLETED => 'പൂർത്തിയായി',
            self::DISPUTED => 'തർക്കത്തിൽ',
            self::REJECTED => 'നിരസിച്ചു',
            self::CANCELLED => 'റദ്ദാക്കി',
            self::EXPIRED => 'കാലഹരണപ്പെട്ടു',
        };
    }

    /**
     * Icon for status.
     * ✅ Active, ⏳ Pending, ✔️ Completed, ⚠️ Disputed, ❌ Cancelled
     */
    public function icon(): string
    {
        return match ($this) {
            self::PENDING => '⏳',
            self::CONFIRMED => '✅',
            self::COMPLETED => '✔️',
            self::DISPUTED => '⚠️',
            self::REJECTED => '❌',
            self::CANCELLED => '🚫',
            self::EXPIRED => '⏰',
        };
    }

    /**
     * Badge (icon + label).
     */
    public function badge(): string
    {
        return $this->icon() . ' ' . $this->label();
    }

    /**
     * Short badge (icon + short label) for compact lists.
     */
    public function shortBadge(): string
    {
        return $this->icon() . ' ' . $this->shortLabel();
    }

    /**
     * Description.
     */
    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Waiting for the other party to confirm',
            self::CONFIRMED => 'Both parties have confirmed',
            self::COMPLETED => 'The agreement has been settled',
            self::DISPUTED => 'There is a dispute about this agreement',
            self::REJECTED => 'The other party rejected the details',
            self::CANCELLED => 'This agreement was cancelled',
            self::EXPIRED => 'The confirmation period has expired',
        };
    }

    /**
     * CSS class for web UI.
     */
    public function badgeClass(): string
    {
        return match ($this) {
            self::PENDING => 'bg-yellow-100 text-yellow-800',
            self::CONFIRMED => 'bg-green-100 text-green-800',
            self::COMPLETED => 'bg-blue-100 text-blue-800',
            self::DISPUTED => 'bg-orange-100 text-orange-800',
            self::REJECTED => 'bg-red-100 text-red-800',
            self::CANCELLED => 'bg-gray-100 text-gray-800',
            self::EXPIRED => 'bg-gray-100 text-gray-600',
        };
    }

    /**
     * Color name for UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::PENDING => 'yellow',
            self::CONFIRMED => 'green',
            self::COMPLETED => 'blue',
            self::DISPUTED => 'orange',
            self::REJECTED => 'red',
            self::CANCELLED => 'gray',
            self::EXPIRED => 'gray',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | State Checks
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    public function isActive(): bool
    {
        return $this === self::CONFIRMED;
    }

    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    public function isProblem(): bool
    {
        return in_array($this, [self::DISPUTED, self::REJECTED, self::EXPIRED]);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::REJECTED, self::EXPIRED]);
    }

    /*
    |--------------------------------------------------------------------------
    | Transition Checks
    |--------------------------------------------------------------------------
    */

    public function canBeConfirmed(): bool
    {
        return $this === self::PENDING;
    }

    public function canBeCompleted(): bool
    {
        return $this === self::CONFIRMED;
    }

    public function canBeDisputed(): bool
    {
        return in_array($this, [self::PENDING, self::CONFIRMED]);
    }

    public function canBeCancelled(): bool
    {
        return $this === self::PENDING;
    }

    public function canBeRejected(): bool
    {
        return $this === self::PENDING;
    }

    public function allowsEditing(): bool
    {
        return $this === self::PENDING;
    }

    public function shouldGeneratePdf(): bool
    {
        return $this === self::CONFIRMED;
    }

    /**
     * Valid transitions.
     */
    public function validTransitions(): array
    {
        return match ($this) {
            self::PENDING => [self::CONFIRMED, self::REJECTED, self::DISPUTED, self::CANCELLED, self::EXPIRED],
            self::CONFIRMED => [self::COMPLETED, self::DISPUTED],
            self::COMPLETED => [],
            self::DISPUTED => [],
            self::REJECTED => [],
            self::CANCELLED => [],
            self::EXPIRED => [],
        };
    }

    public function canTransitionTo(self $newStatus): bool
    {
        return in_array($newStatus, $this->validTransitions());
    }

    /**
     * Sort order for lists.
     */
    public function sortOrder(): int
    {
        return match ($this) {
            self::PENDING => 1,
            self::CONFIRMED => 2,
            self::DISPUTED => 3,
            self::COMPLETED => 4,
            self::REJECTED => 5,
            self::CANCELLED => 6,
            self::EXPIRED => 7,
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Static Helpers
    |--------------------------------------------------------------------------
    */

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function activeStatuses(): array
    {
        return [self::PENDING, self::CONFIRMED, self::DISPUTED];
    }

    public static function terminalStatuses(): array
    {
        return [self::COMPLETED, self::CANCELLED, self::REJECTED, self::EXPIRED];
    }

    public static function filterOptions(): array
    {
        return array_map(fn(self $status) => [
            'value' => $status->value,
            'label' => $status->label(),
            'icon' => $status->icon(),
        ], self::cases());
    }
}