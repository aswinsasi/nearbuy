<?php

namespace App\Enums;

/**
 * Steps in the fish subscription flow.
 *
 * @srs-ref Section 2.3.3 - Customer Subscription
 */
enum FishSubscriptionStep: string
{
    case SELECT_LOCATION = 'select_location';
    case SELECT_FISH_TYPES = 'select_fish_types';
    case SET_RADIUS = 'set_radius';
    case SET_FREQUENCY = 'set_frequency';
    case CONFIRM = 'confirm';
    case COMPLETE = 'complete';
    case MANAGE = 'manage';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::SELECT_LOCATION => 'Select Location',
            self::SELECT_FISH_TYPES => 'Select Fish Types',
            self::SET_RADIUS => 'Set Alert Radius',
            self::SET_FREQUENCY => 'Alert Frequency',
            self::CONFIRM => 'Confirm Subscription',
            self::COMPLETE => 'Complete',
            self::MANAGE => 'Manage Subscriptions',
        };
    }

    /**
     * Get the step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::SELECT_LOCATION => 1,
            self::SELECT_FISH_TYPES => 2,
            self::SET_RADIUS => 3,
            self::SET_FREQUENCY => 4,
            self::CONFIRM => 5,
            self::COMPLETE => 6,
            self::MANAGE => 0, // Not part of setup flow
        };
    }

    /**
     * Get progress percentage.
     */
    public function progress(): int
    {
        return match ($this) {
            self::SELECT_LOCATION => 20,
            self::SELECT_FISH_TYPES => 40,
            self::SET_RADIUS => 60,
            self::SET_FREQUENCY => 80,
            self::CONFIRM => 90,
            self::COMPLETE => 100,
            self::MANAGE => 100,
        };
    }

    /**
     * Get the next step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::SELECT_LOCATION => self::SELECT_FISH_TYPES,
            self::SELECT_FISH_TYPES => self::SET_RADIUS,
            self::SET_RADIUS => self::SET_FREQUENCY,
            self::SET_FREQUENCY => self::CONFIRM,
            self::CONFIRM => self::COMPLETE,
            self::COMPLETE => null,
            self::MANAGE => null,
        };
    }

    /**
     * Get the previous step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::SELECT_LOCATION => null,
            self::SELECT_FISH_TYPES => self::SELECT_LOCATION,
            self::SET_RADIUS => self::SELECT_FISH_TYPES,
            self::SET_FREQUENCY => self::SET_RADIUS,
            self::CONFIRM => self::SET_FREQUENCY,
            self::COMPLETE => self::CONFIRM,
            self::MANAGE => null,
        };
    }

    /**
     * Check if this step can go back.
     */
    public function canGoBack(): bool
    {
        return $this->previous() !== null;
    }

    /**
     * Get expected input type.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::SELECT_LOCATION => 'location',
            self::SELECT_FISH_TYPES => 'list',
            self::SET_RADIUS => 'button',
            self::SET_FREQUENCY => 'list',
            self::CONFIRM => 'button',
            self::COMPLETE => 'none',
            self::MANAGE => 'list',
        };
    }

    /**
     * Check if step is part of setup flow.
     */
    public function isSetupStep(): bool
    {
        return !in_array($this, [self::MANAGE]);
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
