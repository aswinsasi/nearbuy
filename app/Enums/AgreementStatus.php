<?php

namespace App\Enums;

/**
 * Agreement status values.
 *
 * @srs-ref Section 6.3 Enumeration Values - agreements.status
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
     * Get short label (for list items).
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
     * Get Malayalam label.
     *
     * @srs-ref NFR-U-05 Support English and Malayalam
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
     * Get label by language code.
     */
    public function labelByLang(string $lang = 'en'): string
    {
        return match ($lang) {
            'ml' => $this->labelMl(),
            default => $this->label(),
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
     * Get badge (icon + label) for display.
     */
    public function badge(): string
    {
        return $this->icon() . ' ' . $this->label();
    }

    /**
     * Get short badge (icon + short label).
     */
    public function shortBadge(): string
    {
        return $this->icon() . ' ' . $this->shortLabel();
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
     * Get CSS/Tailwind class for badge.
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
     * Get description of the status.
     */
    public function description(): string
    {
        return match ($this) {
            self::PENDING => 'Waiting for the other party to confirm',
            self::CONFIRMED => 'Both parties have confirmed the agreement',
            self::COMPLETED => 'The agreement has been settled',
            self::DISPUTED => 'There is a dispute about this agreement',
            self::REJECTED => 'The other party rejected the agreement details',
            self::CANCELLED => 'This agreement was cancelled',
            self::EXPIRED => 'The confirmation period has expired',
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
     * Check if agreement is pending.
     */
    public function isPending(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if agreement is completed.
     */
    public function isCompleted(): bool
    {
        return $this === self::COMPLETED;
    }

    /**
     * Check if agreement is in a problem state.
     */
    public function isProblem(): bool
    {
        return in_array($this, [self::DISPUTED, self::REJECTED, self::EXPIRED]);
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
        return in_array($this, [self::PENDING, self::CONFIRMED]);
    }

    /**
     * Check if agreement can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if agreement can be rejected.
     */
    public function canBeRejected(): bool
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
     * Check if status allows editing.
     */
    public function allowsEditing(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if PDF should be generated for this status.
     */
    public function shouldGeneratePdf(): bool
    {
        return $this === self::CONFIRMED;
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
     * Check if can transition to a specific status.
     */
    public function canTransitionTo(self $newStatus): bool
    {
        return in_array($newStatus, $this->validTransitions());
    }

    /**
     * Get sort order for status (for ordering in lists).
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

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get statuses that are considered "active" (not terminal).
     */
    public static function activeStatuses(): array
    {
        return [self::PENDING, self::CONFIRMED, self::DISPUTED];
    }

    /**
     * Get terminal statuses.
     */
    public static function terminalStatuses(): array
    {
        return [self::COMPLETED, self::CANCELLED, self::REJECTED, self::EXPIRED];
    }

    /**
     * Get statuses for filtering in admin.
     */
    public static function filterOptions(): array
    {
        return array_map(fn(self $status) => [
            'value' => $status->value,
            'label' => $status->label(),
            'icon' => $status->icon(),
        ], self::cases());
    }
}