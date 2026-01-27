<?php

namespace App\Enums;

/**
 * Status of a job post.
 *
 * @srs-ref Section 3.3 - Job Post Status Flow
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobStatus: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case ASSIGNED = 'assigned';
    case IN_PROGRESS = 'in_progress';
    case COMPLETED = 'completed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Draft',
            self::OPEN => 'Open',
            self::ASSIGNED => 'Assigned',
            self::IN_PROGRESS => 'In Progress',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            self::EXPIRED => 'Expired',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::DRAFT => 'ഡ്രാഫ്റ്റ്',
            self::OPEN => 'തുറന്നത്',
            self::ASSIGNED => 'നിയമിച്ചു',
            self::IN_PROGRESS => 'പുരോഗമിക്കുന്നു',
            self::COMPLETED => 'പൂർത്തിയായി',
            self::CANCELLED => 'റദ്ദാക്കി',
            self::EXPIRED => 'കാലഹരണപ്പെട്ടു',
        };
    }

    /**
     * Get emoji for display.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::DRAFT => '📝',
            self::OPEN => '🟢',
            self::ASSIGNED => '👤',
            self::IN_PROGRESS => '⏳',
            self::COMPLETED => '✅',
            self::CANCELLED => '❌',
            self::EXPIRED => '⏰',
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
     * Get color for UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::DRAFT => 'gray',
            self::OPEN => 'green',
            self::ASSIGNED => 'blue',
            self::IN_PROGRESS => 'yellow',
            self::COMPLETED => 'green',
            self::CANCELLED => 'red',
            self::EXPIRED => 'gray',
        };
    }

    /**
     * Get Tailwind color class.
     */
    public function tailwindColor(): string
    {
        return match ($this) {
            self::DRAFT => 'bg-gray-100 text-gray-800',
            self::OPEN => 'bg-green-100 text-green-800',
            self::ASSIGNED => 'bg-blue-100 text-blue-800',
            self::IN_PROGRESS => 'bg-yellow-100 text-yellow-800',
            self::COMPLETED => 'bg-emerald-100 text-emerald-800',
            self::CANCELLED => 'bg-red-100 text-red-800',
            self::EXPIRED => 'bg-gray-100 text-gray-500',
        };
    }

    /**
     * Check if job is still active (can be modified/actioned).
     */
    public function isActive(): bool
    {
        return in_array($this, [self::DRAFT, self::OPEN, self::ASSIGNED, self::IN_PROGRESS]);
    }

    /**
     * Check if job accepts applications.
     */
    public function acceptsApplications(): bool
    {
        return $this === self::OPEN;
    }

    /**
     * Check if job can be edited.
     */
    public function canEdit(): bool
    {
        return in_array($this, [self::DRAFT, self::OPEN]);
    }

    /**
     * Check if job can be cancelled.
     */
    public function canCancel(): bool
    {
        return in_array($this, [self::DRAFT, self::OPEN, self::ASSIGNED]);
    }

    /**
     * Check if job should be hidden from public browse.
     */
    public function isHidden(): bool
    {
        return in_array($this, [self::DRAFT, self::CANCELLED, self::EXPIRED, self::COMPLETED]);
    }

    /**
     * Check if status can transition to target status.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => in_array($target, [self::OPEN, self::CANCELLED]),
            self::OPEN => in_array($target, [self::ASSIGNED, self::CANCELLED, self::EXPIRED]),
            self::ASSIGNED => in_array($target, [self::IN_PROGRESS, self::OPEN, self::CANCELLED]),
            self::IN_PROGRESS => in_array($target, [self::COMPLETED, self::CANCELLED]),
            self::COMPLETED => false,
            self::CANCELLED => false,
            self::EXPIRED => false,
        };
    }

    /**
     * Get next possible statuses.
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::DRAFT => [self::OPEN, self::CANCELLED],
            self::OPEN => [self::ASSIGNED, self::CANCELLED, self::EXPIRED],
            self::ASSIGNED => [self::IN_PROGRESS, self::OPEN, self::CANCELLED],
            self::IN_PROGRESS => [self::COMPLETED, self::CANCELLED],
            self::COMPLETED => [],
            self::CANCELLED => [],
            self::EXPIRED => [],
        };
    }

    /**
     * Get statuses that are visible in public listings.
     */
    public static function visibleStatuses(): array
    {
        return [self::OPEN, self::ASSIGNED, self::IN_PROGRESS];
    }

    /**
     * Get terminal statuses (no further changes).
     */
    public static function terminalStatuses(): array
    {
        return [self::COMPLETED, self::CANCELLED, self::EXPIRED];
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}