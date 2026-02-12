<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Status of a job application.
 *
 * @srs-ref NP-015 to NP-021 - Worker Application & Selection
 * @values pending, accepted, rejected, withdrawn
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobApplicationStatus: string
{
    case PENDING = 'pending';
    case ACCEPTED = 'accepted';
    case REJECTED = 'rejected';
    case WITHDRAWN = 'withdrawn';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::ACCEPTED => 'Accepted',
            self::REJECTED => 'Rejected',
            self::WITHDRAWN => 'Withdrawn',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::PENDING => 'കാത്തിരിക്കുന്നു',
            self::ACCEPTED => 'അംഗീകരിച്ചു',
            self::REJECTED => 'നിരസിച്ചു',
            self::WITHDRAWN => 'പിൻവലിച്ചു',
        };
    }

    /**
     * Get emoji for display.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::PENDING => '⏳',
            self::ACCEPTED => '✅',
            self::REJECTED => '❌',
            self::WITHDRAWN => '↩️',
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
            self::PENDING => 'yellow',
            self::ACCEPTED => 'green',
            self::REJECTED => 'red',
            self::WITHDRAWN => 'gray',
        };
    }

    /**
     * Get Tailwind color class.
     */
    public function tailwindColor(): string
    {
        return match ($this) {
            self::PENDING => 'bg-yellow-100 text-yellow-800',
            self::ACCEPTED => 'bg-green-100 text-green-800',
            self::REJECTED => 'bg-red-100 text-red-800',
            self::WITHDRAWN => 'bg-gray-100 text-gray-500',
        };
    }

    /**
     * Check if application is still active (awaiting response).
     */
    public function isActive(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if application was successful.
     */
    public function isSuccessful(): bool
    {
        return $this === self::ACCEPTED;
    }

    /**
     * Check if application can be withdrawn by worker.
     */
    public function canWithdraw(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Check if poster can respond to this application.
     *
     * @srs-ref NP-018 - Task giver reviews applications
     * @srs-ref NP-019 - Task giver selects worker
     */
    public function canRespond(): bool
    {
        return $this === self::PENDING;
    }

    /**
     * Get message for worker about status.
     */
    public function workerMessage(): string
    {
        return match ($this) {
            self::PENDING => 'Your application is pending review',
            self::ACCEPTED => 'Congratulations! Your application was accepted',
            self::REJECTED => 'Sorry, your application was not selected',
            self::WITHDRAWN => 'You withdrew your application',
        };
    }

    /**
     * Get message in Malayalam for worker.
     */
    public function workerMessageMl(): string
    {
        return match ($this) {
            self::PENDING => 'നിങ്ങളുടെ അപേക്ഷ പരിശോധിക്കുന്നു',
            self::ACCEPTED => 'അഭിനന്ദനങ്ങൾ! നിങ്ങളുടെ അപേക്ഷ സ്വീകരിച്ചു',
            self::REJECTED => 'ക്ഷമിക്കണം, നിങ്ങളുടെ അപേക്ഷ തിരഞ്ഞെടുത്തില്ല',
            self::WITHDRAWN => 'നിങ്ങൾ അപേക്ഷ പിൻവലിച്ചു',
        };
    }

    /**
     * Get bilingual message for worker.
     */
    public function workerMessageBilingual(): string
    {
        return $this->workerMessage() . "\n" . $this->workerMessageMl();
    }

    /**
     * Get WhatsApp notification for this status.
     *
     * @srs-ref NP-020 - Notify selected worker
     * @srs-ref NP-021 - Notify rejected workers
     */
    public function notificationMessage(string $jobTitle): string
    {
        return match ($this) {
            self::PENDING => "⏳ *Application Submitted*\n*അപേക്ഷ സമർപ്പിച്ചു*\n\n{$jobTitle}\n\nWaiting for response...\nപ്രതികരണത്തിനായി കാത്തിരിക്കുന്നു...",
            self::ACCEPTED => "✅ *You Got the Job!*\n*ജോലി ലഭിച്ചു!*\n\n{$jobTitle}\n\nContact details shared below.\nബന്ധപ്പെടാനുള്ള വിവരങ്ങൾ താഴെ.",
            self::REJECTED => "ℹ️ *Position Filled*\n*സ്ഥാനം നിറഞ്ഞു*\n\n{$jobTitle}\n\nDon't worry, more opportunities await!\nവിഷമിക്കേണ്ട, കൂടുതൽ അവസരങ്ങൾ ഉണ്ട്!",
            self::WITHDRAWN => "↩️ *Application Withdrawn*\n*അപേക്ഷ പിൻവലിച്ചു*\n\n{$jobTitle}",
        };
    }

    /**
     * Check if status can transition to target status.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::PENDING => in_array($target, [self::ACCEPTED, self::REJECTED, self::WITHDRAWN]),
            self::ACCEPTED => false,
            self::REJECTED => false,
            self::WITHDRAWN => false,
        };
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get statuses that count as "active" applications for a job.
     */
    public static function activeStatuses(): array
    {
        return [self::PENDING];
    }

    /**
     * Get statuses that represent final outcomes.
     */
    public static function terminalStatuses(): array
    {
        return [self::ACCEPTED, self::REJECTED, self::WITHDRAWN];
    }
}