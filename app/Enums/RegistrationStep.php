<?php

namespace App\Enums;

/**
 * Registration flow steps.
 *
 * NATURAL FLOW ORDER (feels like conversation, not form):
 * 1. ASK_NAME - First thing we ask, builds rapport
 * 2. ASK_LOCATION - Needed for all features, explain why
 * 3. ASK_TYPE - Customer or Shop Owner (determines next steps)
 * 4. COMPLETE - Customer done, Shop owner redirects to shop registration
 *
 * @srs-ref FR-REG-01 through FR-REG-07
 */
enum RegistrationStep: string
{
    // Core registration steps (ALL users)
    case ASK_NAME = 'ask_name';
    case ASK_LOCATION = 'ask_location';
    case ASK_TYPE = 'ask_type';
    case COMPLETE = 'complete';

    /**
     * Get the next step in flow.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::ASK_NAME => self::ASK_LOCATION,
            self::ASK_LOCATION => self::ASK_TYPE,
            self::ASK_TYPE => self::COMPLETE,
            self::COMPLETE => null,
        };
    }

    /**
     * Get expected input type for validation.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::ASK_NAME => 'text',
            self::ASK_LOCATION => 'location',
            self::ASK_TYPE => 'button',
            self::COMPLETE => 'none',
        };
    }

    /**
     * Get step number for progress indication.
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::ASK_NAME => 1,
            self::ASK_LOCATION => 2,
            self::ASK_TYPE => 3,
            self::COMPLETE => 3,
        };
    }

    /**
     * Total steps in customer registration.
     */
    public static function totalSteps(): int
    {
        return 3;
    }

    /**
     * Check if step is the final one.
     */
    public function isFinal(): bool
    {
        return $this === self::COMPLETE;
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}