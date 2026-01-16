<?php

namespace App\Enums;

/**
 * Agreement status values.
 */
enum AgreementStatus: string
{
    case PENDING = 'pending';
    case CONFIRMED = 'confirmed';
    case REJECTED = 'rejected';
    case EXPIRED = 'expired';
    case COMPLETED = 'completed';
    case DISPUTED = 'disputed';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending Confirmation',
            self::CONFIRMED => 'Confirmed',
            self::REJECTED => 'Rejected',
            self::EXPIRED => 'Expired',
            self::COMPLETED => 'Completed',
            self::DISPUTED => 'Disputed',
        };
    }

    /**
     * Get the Malayalam label.
     */
    public function labelMalayalam(): string
    {
        return match ($this) {
            self::PENDING => 'സ്ഥിരീകരണം കാത്തിരിക്കുന്നു',
            self::CONFIRMED => 'സ്ഥിരീകരിച്ചു',
            self::REJECTED => 'നിരസിച്ചു',
            self::EXPIRED => 'കാലഹരണപ്പെട്ടു',
            self::COMPLETED => 'പൂർത്തിയായി',
            self::DISPUTED => 'തർക്കത്തിൽ',
        };
    }

    /**
     * Get status emoji.
     */
    public function icon(): string
    {
        return match ($this) {
            self::PENDING => '⏳',
            self::CONFIRMED => '✅',
            self::REJECTED => '❌',
            self::EXPIRED => '⏰',
            self::COMPLETED => '🎉',
            self::DISPUTED => '⚠️',
        };
    }

    /**
     * Check if agreement is active.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::PENDING, self::CONFIRMED]);
    }

    /**
     * Check if agreement can be confirmed.
     */
    public function canBeConfirmed(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if agreement can be marked as completed.
     */
    public function canBeCompleted(): bool
    {
        return $this === self::CONFIRMED;
    }

    /**
     * Get all terminal statuses.
     */
    public static function terminalStatuses(): array
    {
        return [self::REJECTED, self::EXPIRED, self::COMPLETED];
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}