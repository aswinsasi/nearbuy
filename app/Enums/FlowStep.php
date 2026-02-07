<?php

namespace App\Enums;

/**
 * Generic flow steps for conversation state management.
 *
 * These are GENERIC steps that apply across all flows.
 * Specific flows define their own step values as strings stored in session.
 * 
 * Each flow handler defines its own step constants/enums for flow-specific steps.
 * This enum provides common states that all flows share.
 *
 * @srs-ref Section 7.3 Session State Management
 */
enum FlowStep: string
{
    /*
    |--------------------------------------------------------------------------
    | Core Flow States
    |--------------------------------------------------------------------------
    */

    /** Flow has just started */
    case STARTED = 'started';

    /** Flow is actively in progress */
    case IN_PROGRESS = 'in_progress';

    /** Flow completed successfully */
    case COMPLETED = 'completed';

    /*
    |--------------------------------------------------------------------------
    | Menu/Idle States
    |--------------------------------------------------------------------------
    */

    /** User is idle, not in any active flow */
    case IDLE = 'idle';

    /** User is viewing main menu */
    case MAIN_MENU = 'main_menu';

    /** Showing a menu to user */
    case SHOW_MENU = 'show_menu';

    /*
    |--------------------------------------------------------------------------
    | Termination States
    |--------------------------------------------------------------------------
    */

    /** User cancelled the flow */
    case CANCELLED = 'cancelled';

    /** Flow encountered an error */
    case ERROR = 'error';

    /** Flow expired due to timeout */
    case EXPIRED = 'expired';

    /*
    |--------------------------------------------------------------------------
    | Processing States
    |--------------------------------------------------------------------------
    */

    /** System is processing (async operation) */
    case PROCESSING = 'processing';

    /** Waiting for external response */
    case WAITING = 'waiting';

    /** Awaiting user confirmation */
    case CONFIRM = 'confirm';

    /*
    |--------------------------------------------------------------------------
    | State Check Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if this is an idle/menu state.
     */
    public function isIdle(): bool
    {
        return in_array($this, [self::IDLE, self::MAIN_MENU, self::SHOW_MENU]);
    }

    /**
     * Check if this is a terminal state (flow ended).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::ERROR, self::EXPIRED]);
    }

    /**
     * Check if this is an active flow state.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::STARTED, self::IN_PROGRESS, self::PROCESSING, self::WAITING, self::CONFIRM]);
    }

    /**
     * Check if this step can be interrupted by menu command.
     *
     * @srs-ref NFR-U-04 Main menu accessible from any flow state
     */
    public function canBeInterrupted(): bool
    {
        // Processing state should not be interrupted
        return $this !== self::PROCESSING;
    }

    /**
     * Check if flow can resume from this state.
     */
    public function canResume(): bool
    {
        return in_array($this, [self::STARTED, self::IN_PROGRESS, self::WAITING, self::CONFIRM]);
    }

    /*
    |--------------------------------------------------------------------------
    | Display Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get display label for this step.
     */
    public function label(): string
    {
        return match ($this) {
            self::STARTED => 'Started',
            self::IN_PROGRESS => 'In Progress',
            self::COMPLETED => 'Completed',
            self::IDLE => 'Idle',
            self::MAIN_MENU => 'Main Menu',
            self::SHOW_MENU => 'Showing Menu',
            self::CANCELLED => 'Cancelled',
            self::ERROR => 'Error',
            self::EXPIRED => 'Expired',
            self::PROCESSING => 'Processing',
            self::WAITING => 'Waiting',
            self::CONFIRM => 'Confirming',
        };
    }

    /**
     * Get emoji icon for this step.
     */
    public function icon(): string
    {
        return match ($this) {
            self::STARTED => '🚀',
            self::IN_PROGRESS => '⏳',
            self::COMPLETED => '✅',
            self::IDLE => '💤',
            self::MAIN_MENU => '🏠',
            self::SHOW_MENU => '📋',
            self::CANCELLED => '❌',
            self::ERROR => '⚠️',
            self::EXPIRED => '⏰',
            self::PROCESSING => '⚙️',
            self::WAITING => '⏸️',
            self::CONFIRM => '❓',
        };
    }

    /*
    |--------------------------------------------------------------------------
    | Static Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Check if a string value is a valid generic step.
     */
    public static function isValid(string $value): bool
    {
        return in_array($value, self::values());
    }

    /**
     * Try to create from string, returns null if invalid.
     */
    public static function tryFromString(string $value): ?self
    {
        return self::tryFrom($value);
    }

    /**
     * Get all terminal states.
     */
    public static function terminalStates(): array
    {
        return [self::COMPLETED, self::CANCELLED, self::ERROR, self::EXPIRED];
    }

    /**
     * Get all active states.
     */
    public static function activeStates(): array
    {
        return [self::STARTED, self::IN_PROGRESS, self::PROCESSING, self::WAITING, self::CONFIRM];
    }

    /**
     * Get all idle states.
     */
    public static function idleStates(): array
    {
        return [self::IDLE, self::MAIN_MENU, self::SHOW_MENU];
    }
}