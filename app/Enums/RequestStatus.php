<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Product Request Status.
 *
 * @srs-ref SRS 6.3 - Enumeration Values
 * Values: open, collecting, closed, expired
 */
enum RequestStatus: string
{
    /** Request is open, waiting for responses */
    case OPEN = 'open';

    /** Collecting responses (at least one received) */
    case COLLECTING = 'collecting';

    /** Customer closed the request */
    case CLOSED = 'closed';

    /** Request expired without being closed */
    case EXPIRED = 'expired';

    /**
     * Display label (English).
     */
    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::COLLECTING => 'Collecting',
            self::CLOSED => 'Closed',
            self::EXPIRED => 'Expired',
        };
    }

    /**
     * Display label (Malayalam).
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::OPEN => 'തുറന്നിരിക്കുന്നു',
            self::COLLECTING => 'ശേഖരിക്കുന്നു',
            self::CLOSED => 'അടച്ചു',
            self::EXPIRED => 'കാലഹരണപ്പെട്ടു',
        };
    }

    /**
     * Status icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::OPEN => '🟢',
            self::COLLECTING => '🟡',
            self::CLOSED => '✅',
            self::EXPIRED => '⏰',
        };
    }

    /**
     * Check if request accepts new responses.
     */
    public function acceptsResponses(): bool
    {
        return in_array($this, [self::OPEN, self::COLLECTING], true);
    }

    /**
     * Check if request is active.
     */
    public function isActive(): bool
    {
        return $this->acceptsResponses();
    }

    /**
     * Check if request is terminal (final state).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::CLOSED, self::EXPIRED], true);
    }

    /**
     * Valid transitions from this status.
     */
    public function validTransitions(): array
    {
        return match ($this) {
            self::OPEN => [self::COLLECTING, self::CLOSED, self::EXPIRED],
            self::COLLECTING => [self::CLOSED, self::EXPIRED],
            self::CLOSED, self::EXPIRED => [],
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
     * Get active statuses.
     */
    public static function activeStatuses(): array
    {
        return [self::OPEN, self::COLLECTING];
    }
}