<?php

namespace App\Enums;

/**
 * Agreement status values.
 */
enum AgreementStatus: string
{
    case PENDING = 'pending';               // Waiting for counterparty confirmation
    case CONFIRMED = 'confirmed';           // Both parties confirmed
    case COMPLETED = 'completed';           // Settlement done
    case DISPUTED = 'disputed';             // Counterparty claims unknown
    case REJECTED = 'rejected';             // Counterparty rejected details
    case CANCELLED = 'cancelled';           // Creator cancelled
    case EXPIRED = 'expired';               // Confirmation period expired

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Confirmation',
            self::CONFIRMED => 'Confirmed',
            self::COMPLETED => 'Completed',
            self::DISPUTED => 'Disputed',
            self::REJECTED => 'Rejected',
            self::CANCELLED => 'Cancelled',
            self::EXPIRED => 'Expired',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::PENDING => 'സ്ഥിരീകരണം കാത്തിരിക്കുന്നു',
            self::CONFIRMED => 'സ്ഥിരീകരിച്ചു',
            self::COMPLETED => 'പൂർത്തിയായി',
            self::DISPUTED => 'തർക്കത്തിൽ',
            self::REJECTED => 'നിരസിച്ചു',
            self::CANCELLED => 'റദ്ദാക്കി',
            self::EXPIRED => 'കാലഹരണപ്പെട്ടു',
        };
    }

    /**
     * Get icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::PENDING => '⏳',
            self::CONFIRMED => '✅',
            self::COMPLETED => '🎉',
            self::DISPUTED => '⚠️',
            self::REJECTED => '❌',
            self::CANCELLED => '🚫',
            self::EXPIRED => '⏰',
        };
    }

    /**
     * Get badge color for UI.
     */
    public function badgeColor(): string
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

    /**
     * Check if agreement is confirmed/active.
     */
    public function isActive(): bool
    {
        return $this === self::CONFIRMED;
    }

    /**
     * Check if agreement can be confirmed.
     */
    public function canBeConfirmed(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if agreement can be completed.
     */
    public function canBeCompleted(): bool
    {
        return $this === self::CONFIRMED;
    }

    /**
     * Check if agreement can be disputed.
     */
    public function canBeDisputed(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if agreement can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if agreement is terminal (no further changes).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::REJECTED, self::EXPIRED]);
    }

    /**
     * Check if agreement is pending.
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Get valid transitions from current status.
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

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}