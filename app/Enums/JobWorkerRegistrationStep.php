<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Job Worker Registration Steps.
 *
 * Conversational Manglish flow:
 * 1. Location → 2. Photo → 3. Job Types → 4. Vehicle → 5. Availability → Done
 *
 * @srs-ref NP-001 to NP-005: Worker Registration
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobWorkerRegistrationStep: string
{
    case START = 'start';
    case ASK_LOCATION = 'ask_location';
    case ASK_PHOTO = 'ask_photo';
    case ASK_JOB_TYPES = 'ask_job_types';
    case ASK_VEHICLE = 'ask_vehicle';
    case ASK_AVAILABILITY = 'ask_availability';
    case CONFIRM = 'confirm';
    case DONE = 'done';

    /**
     * Step number (1-based for display).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::START => 0,
            self::ASK_LOCATION => 1,
            self::ASK_PHOTO => 2,
            self::ASK_JOB_TYPES => 3,
            self::ASK_VEHICLE => 4,
            self::ASK_AVAILABILITY => 5,
            self::CONFIRM => 6,
            self::DONE => 7,
        };
    }

    /**
     * Total steps for progress display.
     */
    public static function totalSteps(): int
    {
        return 5;
    }

    /**
     * Get next step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::START => self::ASK_LOCATION,
            self::ASK_LOCATION => self::ASK_PHOTO,
            self::ASK_PHOTO => self::ASK_JOB_TYPES,
            self::ASK_JOB_TYPES => self::ASK_VEHICLE,
            self::ASK_VEHICLE => self::ASK_AVAILABILITY,
            self::ASK_AVAILABILITY => self::CONFIRM,
            self::CONFIRM => self::DONE,
            self::DONE => null,
        };
    }

    /**
     * Get previous step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::ASK_LOCATION => self::START,
            self::ASK_PHOTO => self::ASK_LOCATION,
            self::ASK_JOB_TYPES => self::ASK_PHOTO,
            self::ASK_VEHICLE => self::ASK_JOB_TYPES,
            self::ASK_AVAILABILITY => self::ASK_VEHICLE,
            self::CONFIRM => self::ASK_AVAILABILITY,
            default => null,
        };
    }

    /**
     * Expected input type.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::ASK_LOCATION => 'location',
            self::ASK_PHOTO => 'image',
            self::ASK_JOB_TYPES => 'list',
            self::ASK_VEHICLE => 'button',
            self::ASK_AVAILABILITY => 'button',
            self::CONFIRM => 'button',
            default => 'any',
        };
    }

    /**
     * Short label.
     */
    public function label(): string
    {
        return match ($this) {
            self::START => 'Start',
            self::ASK_LOCATION => 'Location',
            self::ASK_PHOTO => 'Photo',
            self::ASK_JOB_TYPES => 'Job Types',
            self::ASK_VEHICLE => 'Vehicle',
            self::ASK_AVAILABILITY => 'Availability',
            self::CONFIRM => 'Confirm',
            self::DONE => 'Done',
        };
    }

    /**
     * Is step optional?
     */
    public function isOptional(): bool
    {
        return $this === self::ASK_PHOTO;
    }

    /**
     * Progress percentage.
     */
    public function progress(): int
    {
        $step = $this->stepNumber();
        return $step > 0 ? (int) round(($step / self::totalSteps()) * 100) : 0;
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}