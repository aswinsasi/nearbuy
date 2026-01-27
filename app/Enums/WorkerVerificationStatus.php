<?php

namespace App\Enums;

/**
 * Worker verification status states.
 *
 * @srs-ref Section 3.2 - Job Workers
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum WorkerVerificationStatus: string
{
    case PENDING = 'pending';
    case SUBMITTED = 'submitted';
    case VERIFIED = 'verified';
    case REJECTED = 'rejected';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::SUBMITTED => 'Submitted',
            self::VERIFIED => 'Verified',
            self::REJECTED => 'Rejected',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::PENDING => 'തീർപ്പുകാത്തിരിക്കുന്നു',
            self::SUBMITTED => 'സമർപ്പിച്ചു',
            self::VERIFIED => 'സ്ഥിരീകരിച്ചു',
            self::REJECTED => 'നിരസിച്ചു',
        };
    }

    /**
     * Get emoji icon.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::PENDING => '⏳',
            self::SUBMITTED => '📤',
            self::VERIFIED => '✅',
            self::REJECTED => '❌',
        };
    }

    /**
     * Get display with emoji.
     */
    public function display(): string
    {
        return $this->emoji() . ' ' . $this->label();
    }

    /**
     * Check if worker can take jobs.
     */
    public function canWork(): bool
    {
        return $this === self::VERIFIED;
    }

    /**
     * Check if status allows resubmission.
     */
    public function canResubmit(): bool
    {
        return $this === self::REJECTED;
    }

    /**
     * Check if verification is in progress.
     */
    public function isInProgress(): bool
    {
        return in_array($this, [self::PENDING, self::SUBMITTED]);
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get statuses that allow working.
     */
    public static function workableStatuses(): array
    {
        return [self::VERIFIED];
    }
}