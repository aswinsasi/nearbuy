<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Steps in the job posting flow.
 *
 * Simplified flow:
 * 1. Category → 2. Location → 3. Coordinates → 4. Date → 5. Time
 * → 6. Duration → 7. Pay → 8. Instructions → 9. Review → Done
 *
 * @srs-ref NP-006 to NP-014: Job Posting Flow
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobPostingStep: string
{
    case START = 'start';
    case ASK_CATEGORY = 'ask_category';
    case ASK_CUSTOM_CATEGORY = 'ask_custom_category';
    case ASK_LOCATION = 'ask_location';
    case ASK_COORDINATES = 'ask_coordinates';
    case ASK_DATE = 'ask_date';
    case ASK_CUSTOM_DATE = 'ask_custom_date';
    case ASK_TIME = 'ask_time';
    case ASK_DURATION = 'ask_duration';
    case ASK_PAY = 'ask_pay';
    case ASK_INSTRUCTIONS = 'ask_instructions';
    case REVIEW = 'review';
    case DONE = 'done';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::START => 'Start',
            self::ASK_CATEGORY => 'Job Type',
            self::ASK_CUSTOM_CATEGORY => 'Custom Job Type',
            self::ASK_LOCATION => 'Location',
            self::ASK_COORDINATES => 'Share Location',
            self::ASK_DATE => 'Date',
            self::ASK_CUSTOM_DATE => 'Custom Date',
            self::ASK_TIME => 'Time',
            self::ASK_DURATION => 'Duration',
            self::ASK_PAY => 'Payment',
            self::ASK_INSTRUCTIONS => 'Instructions',
            self::REVIEW => 'Review',
            self::DONE => 'Complete',
        };
    }

    /**
     * Get the step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::START => 0,
            self::ASK_CATEGORY => 1,
            self::ASK_CUSTOM_CATEGORY => 1,
            self::ASK_LOCATION => 2,
            self::ASK_COORDINATES => 3,
            self::ASK_DATE => 4,
            self::ASK_CUSTOM_DATE => 4,
            self::ASK_TIME => 5,
            self::ASK_DURATION => 6,
            self::ASK_PAY => 7,
            self::ASK_INSTRUCTIONS => 8,
            self::REVIEW => 9,
            self::DONE => 10,
        };
    }

    /**
     * Get total steps (for progress display).
     */
    public function totalSteps(): int
    {
        return 9;
    }

    /**
     * Get progress percentage.
     */
    public function progress(): int
    {
        $step = $this->stepNumber();
        $total = $this->totalSteps();
        return $total > 0 ? (int) round(($step / $total) * 100) : 0;
    }

    /**
     * Get the next step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::START => self::ASK_CATEGORY,
            self::ASK_CATEGORY => self::ASK_LOCATION, // Handler overrides for "Other"
            self::ASK_CUSTOM_CATEGORY => self::ASK_LOCATION,
            self::ASK_LOCATION => self::ASK_COORDINATES,
            self::ASK_COORDINATES => self::ASK_DATE,
            self::ASK_DATE => self::ASK_TIME, // Handler overrides for custom date
            self::ASK_CUSTOM_DATE => self::ASK_TIME,
            self::ASK_TIME => self::ASK_DURATION,
            self::ASK_DURATION => self::ASK_PAY,
            self::ASK_PAY => self::ASK_INSTRUCTIONS,
            self::ASK_INSTRUCTIONS => self::REVIEW,
            self::REVIEW => self::DONE,
            self::DONE => null,
        };
    }

    /**
     * Get the previous step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::START => null,
            self::ASK_CATEGORY => null,
            self::ASK_CUSTOM_CATEGORY => self::ASK_CATEGORY,
            self::ASK_LOCATION => self::ASK_CATEGORY,
            self::ASK_COORDINATES => self::ASK_LOCATION,
            self::ASK_DATE => self::ASK_COORDINATES,
            self::ASK_CUSTOM_DATE => self::ASK_DATE,
            self::ASK_TIME => self::ASK_DATE,
            self::ASK_DURATION => self::ASK_TIME,
            self::ASK_PAY => self::ASK_DURATION,
            self::ASK_INSTRUCTIONS => self::ASK_PAY,
            self::REVIEW => self::ASK_INSTRUCTIONS,
            self::DONE => self::REVIEW,
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
            self::START => 'none',
            self::ASK_CATEGORY => 'list',
            self::ASK_CUSTOM_CATEGORY => 'text',
            self::ASK_LOCATION => 'text',
            self::ASK_COORDINATES => 'location',
            self::ASK_DATE => 'button',
            self::ASK_CUSTOM_DATE => 'text',
            self::ASK_TIME => 'text',
            self::ASK_DURATION => 'button',
            self::ASK_PAY => 'text',
            self::ASK_INSTRUCTIONS => 'text',
            self::REVIEW => 'button',
            self::DONE => 'none',
        };
    }

    /**
     * Check if step is optional (can skip).
     */
    public function isOptional(): bool
    {
        return in_array($this, [
            self::ASK_COORDINATES,
            self::ASK_INSTRUCTIONS,
        ]);
    }

    /**
     * Check if step can be skipped.
     */
    public function canSkip(): bool
    {
        return $this->isOptional();
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}