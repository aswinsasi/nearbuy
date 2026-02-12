<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Steps in the job application flow (worker applying to a job).
 *
 * Simplified flow:
 * 1. VIEW_JOB - Worker sees job details from notification
 * 2. ENTER_MESSAGE - Optional message entry (only if "Apply + Message" tapped)
 * 3. APPLIED - Application submitted confirmation
 *
 * Entry points:
 * - Worker taps [✅ Apply] → instant apply → APPLIED
 * - Worker taps [💬 Apply + Message] → ENTER_MESSAGE → APPLIED
 * - Worker taps [📋 View Details] → VIEW_JOB → buttons
 *
 * @srs-ref NP-015 to NP-017
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobApplicationStep: string
{
    case VIEW_JOB = 'view_job';
    case ENTER_MESSAGE = 'enter_message';
    case APPLIED = 'applied';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::VIEW_JOB => 'View Job Details',
            self::ENTER_MESSAGE => 'Add Message',
            self::APPLIED => 'Application Sent',
        };
    }

    /**
     * Get the step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::VIEW_JOB => 1,
            self::ENTER_MESSAGE => 2,
            self::APPLIED => 3,
        };
    }

    /**
     * Get total steps count.
     */
    public static function totalSteps(): int
    {
        return 3;
    }

    /**
     * Get progress percentage.
     */
    public function progress(): int
    {
        return match ($this) {
            self::VIEW_JOB => 33,
            self::ENTER_MESSAGE => 66,
            self::APPLIED => 100,
        };
    }

    /**
     * Get the next step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::VIEW_JOB => self::ENTER_MESSAGE,
            self::ENTER_MESSAGE => self::APPLIED,
            self::APPLIED => null,
        };
    }

    /**
     * Get the previous step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::VIEW_JOB => null,
            self::ENTER_MESSAGE => self::VIEW_JOB,
            self::APPLIED => self::ENTER_MESSAGE,
        };
    }

    /**
     * Check if this step can go back.
     */
    public function canGoBack(): bool
    {
        return $this->previous() !== null && $this !== self::APPLIED;
    }

    /**
     * Get expected input type.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::VIEW_JOB => 'button',
            self::ENTER_MESSAGE => 'text',
            self::APPLIED => 'button',
        };
    }

    /**
     * Check if step is optional.
     */
    public function isOptional(): bool
    {
        return $this === self::ENTER_MESSAGE;
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