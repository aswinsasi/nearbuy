<?php

namespace App\Enums;

/**
 * Product request status values.
 */
enum RequestStatus: string
{
    case OPEN = 'open';
    case COLLECTING = 'collecting';
    case CLOSED = 'closed';
    case EXPIRED = 'expired';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::COLLECTING => 'Collecting Responses',
            self::CLOSED => 'Closed',
            self::EXPIRED => 'Expired',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::OPEN => 'തുറന്നിരിക്കുന്നു',
            self::COLLECTING => 'പ്രതികരണങ്ങൾ ശേഖരിക്കുന്നു',
            self::CLOSED => 'അടച്ചു',
            self::EXPIRED => 'കാലഹരണപ്പെട്ടു',
        };
    }

    /**
     * Get icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::OPEN => '🟢',
            self::COLLECTING => '🔄',
            self::CLOSED => '🔴',
            self::EXPIRED => '⏰',
        };
    }

    /**
     * Check if request is still accepting responses.
     */
    public function acceptsResponses(): bool
    {
        return in_array($this, [self::OPEN, self::COLLECTING]);
    }

    /**
     * Check if request is active.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::OPEN, self::COLLECTING]);
    }

    /**
     * Check if request is terminal.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::CLOSED, self::EXPIRED]);
    }

    /**
     * Get next valid statuses from current status.
     */
    public function validTransitions(): array
    {
        return match ($this) {
            self::OPEN => [self::COLLECTING, self::CLOSED, self::EXPIRED],
            self::COLLECTING => [self::CLOSED, self::EXPIRED],
            self::CLOSED => [],
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