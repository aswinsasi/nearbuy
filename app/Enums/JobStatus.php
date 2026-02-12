<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a job post.
 *
 * @srs-ref Section 5.2.2 - job_posts.status
 * @values open, assigned, in_progress, completed, cancelled, expired
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobStatus: string
{
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
     * Get bilingual display.
     */
    public function displayBilingual(): string
    {
        return $this->emoji() . ' ' . $this->label() . ' / ' . $this->labelMl();
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::OPEN => 'green',
            self::ASSIGNED => 'blue',
            self::IN_PROGRESS => 'yellow',
            self::COMPLETED => 'emerald',
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
        return in_array($this, [self::OPEN, self::ASSIGNED, self::IN_PROGRESS]);
    }

    /**
     * Check if job accepts applications.
     *
     * @srs-ref NP-016 - Workers can apply to open jobs
     */
    public function acceptsApplications(): bool
    {
        return $this === self::OPEN;
    }

    /**
     * Check if job can be edited by poster.
     */
    public function canEdit(): bool
    {
        return $this === self::OPEN;
    }

    /**
     * Check if job can be cancelled.
     */
    public function canCancel(): bool
    {
        return in_array($this, [self::OPEN, self::ASSIGNED]);
    }

    /**
     * Check if job should be hidden from public browse.
     */
    public function isHidden(): bool
    {
        return in_array($this, [self::CANCELLED, self::EXPIRED, self::COMPLETED]);
    }

    /**
     * Check if job is in a terminal state (no further changes).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::COMPLETED, self::CANCELLED, self::EXPIRED]);
    }

    /**
     * Check if status can transition to target status.
     *
     * @srs-ref NP-019 to NP-028 - Job execution flow
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
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
        return [self::OPEN];
    }

    /**
     * Get statuses for active jobs (worker/poster dashboards).
     */
    public static function activeStatuses(): array
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
     * Get WhatsApp notification message for status change.
     */
    public function notificationMessage(string $jobTitle): string
    {
        return match ($this) {
            self::OPEN => "🟢 *Job Posted*\n*ജോലി പോസ്റ്റ് ചെയ്തു*\n\n{$jobTitle}",
            self::ASSIGNED => "👤 *Worker Assigned*\n*പണിക്കാരനെ നിയമിച്ചു*\n\n{$jobTitle}",
            self::IN_PROGRESS => "⏳ *Job Started*\n*ജോലി തുടങ്ങി*\n\n{$jobTitle}",
            self::COMPLETED => "✅ *Job Completed*\n*ജോലി പൂർത്തിയായി*\n\n{$jobTitle}",
            self::CANCELLED => "❌ *Job Cancelled*\n*ജോലി റദ്ദാക്കി*\n\n{$jobTitle}",
            self::EXPIRED => "⏰ *Job Expired*\n*ജോലി കാലഹരണപ്പെട്ടു*\n\n{$jobTitle}",
        };
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}