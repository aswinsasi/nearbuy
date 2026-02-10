<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Steps in the fish subscription flow.
 *
 * Flow: Fish Types → Location → Radius → Time → Confirm → Done
 *
 * @srs-ref PM-011 to PM-015 Customer Subscription
 */
enum FishSubscriptionStep: string
{
    // Setup flow
    case ASK_FISH_TYPES = 'ask_fish_types';
    case ASK_LOCATION = 'ask_location';
    case ASK_RADIUS = 'ask_radius';
    case ASK_TIME = 'ask_time';
    case CONFIRM = 'confirm';
    case DONE = 'done';

    // Management steps (PM-015)
    case MANAGE = 'manage';
    case CHANGE_FISH = 'change_fish';
    case CHANGE_LOCATION = 'change_location';
    case CHANGE_RADIUS = 'change_radius';
    case CHANGE_TIME = 'change_time';

    /**
     * Get step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::ASK_FISH_TYPES => 1,
            self::ASK_LOCATION => 2,
            self::ASK_RADIUS => 3,
            self::ASK_TIME => 4,
            self::CONFIRM => 5,
            self::DONE => 6,
            default => 0,
        };
    }

    /**
     * Get next step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::ASK_FISH_TYPES => self::ASK_LOCATION,
            self::ASK_LOCATION => self::ASK_RADIUS,
            self::ASK_RADIUS => self::ASK_TIME,
            self::ASK_TIME => self::CONFIRM,
            self::CONFIRM => self::DONE,
            default => null,
        };
    }

    /**
     * Get previous step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::ASK_LOCATION => self::ASK_FISH_TYPES,
            self::ASK_RADIUS => self::ASK_LOCATION,
            self::ASK_TIME => self::ASK_RADIUS,
            self::CONFIRM => self::ASK_TIME,
            default => null,
        };
    }

    /**
     * Check if setup step.
     */
    public function isSetupStep(): bool
    {
        return in_array($this, [
            self::ASK_FISH_TYPES,
            self::ASK_LOCATION,
            self::ASK_RADIUS,
            self::ASK_TIME,
            self::CONFIRM,
            self::DONE,
        ]);
    }

    /**
     * Get expected input type.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::ASK_FISH_TYPES => 'list',
            self::ASK_LOCATION => 'location',
            self::ASK_RADIUS => 'button',
            self::ASK_TIME => 'button',
            self::CONFIRM => 'button',
            default => 'any',
        };
    }

    /**
     * Get display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ASK_FISH_TYPES => 'Select Fish',
            self::ASK_LOCATION => 'Share Location',
            self::ASK_RADIUS => 'Set Radius',
            self::ASK_TIME => 'Alert Time',
            self::CONFIRM => 'Confirm',
            self::DONE => 'Done',
            self::MANAGE => 'Manage',
            self::CHANGE_FISH => 'Change Fish',
            self::CHANGE_LOCATION => 'Change Location',
            self::CHANGE_RADIUS => 'Change Radius',
            self::CHANGE_TIME => 'Change Time',
        };
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}