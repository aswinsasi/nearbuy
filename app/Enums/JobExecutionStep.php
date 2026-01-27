<?php

namespace App\Enums;

/**
 * Steps in the job execution/verification flow.
 *
 * @srs-ref Section 3.5 - Job Verification & Completion
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobExecutionStep: string
{
    // Worker arrival
    case ARRIVAL_PHOTO = 'arrival_photo';
    case NOTIFY_POSTER_ARRIVAL = 'notify_poster_arrival';
    
    // Work in progress
    case WORK_IN_PROGRESS = 'work_in_progress';
    
    // Completion
    case COMPLETION_PHOTO = 'completion_photo';
    case COMPLETION_CONFIRM_WORKER = 'completion_confirm_worker';
    case COMPLETION_CONFIRM_POSTER = 'completion_confirm_poster';
    
    // Payment
    case SELECT_PAYMENT_METHOD = 'select_payment_method';
    case CONFIRM_PAYMENT = 'confirm_payment';
    
    // Rating
    case RATE_WORKER = 'rate_worker';
    case RATE_POSTER = 'rate_poster';
    
    // Complete
    case COMPLETE = 'complete';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => 'Arrival Photo',
            self::NOTIFY_POSTER_ARRIVAL => 'Notify Arrival',
            self::WORK_IN_PROGRESS => 'Work In Progress',
            self::COMPLETION_PHOTO => 'Completion Photo',
            self::COMPLETION_CONFIRM_WORKER => 'Worker Confirms',
            self::COMPLETION_CONFIRM_POSTER => 'Poster Confirms',
            self::SELECT_PAYMENT_METHOD => 'Payment Method',
            self::CONFIRM_PAYMENT => 'Confirm Payment',
            self::RATE_WORKER => 'Rate Worker',
            self::RATE_POSTER => 'Rate Poster',
            self::COMPLETE => 'Complete',
        };
    }

    /**
     * Get the step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => 1,
            self::NOTIFY_POSTER_ARRIVAL => 2,
            self::WORK_IN_PROGRESS => 3,
            self::COMPLETION_PHOTO => 4,
            self::COMPLETION_CONFIRM_WORKER => 5,
            self::COMPLETION_CONFIRM_POSTER => 6,
            self::SELECT_PAYMENT_METHOD => 7,
            self::CONFIRM_PAYMENT => 8,
            self::RATE_WORKER => 9,
            self::RATE_POSTER => 10,
            self::COMPLETE => 11,
        };
    }

    /**
     * Get progress percentage.
     */
    public function progress(): int
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => 10,
            self::NOTIFY_POSTER_ARRIVAL => 15,
            self::WORK_IN_PROGRESS => 30,
            self::COMPLETION_PHOTO => 50,
            self::COMPLETION_CONFIRM_WORKER => 60,
            self::COMPLETION_CONFIRM_POSTER => 70,
            self::SELECT_PAYMENT_METHOD => 80,
            self::CONFIRM_PAYMENT => 85,
            self::RATE_WORKER => 90,
            self::RATE_POSTER => 95,
            self::COMPLETE => 100,
        };
    }

    /**
     * Get WhatsApp instruction message.
     */
    public function instruction(): string
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => "📸 *Arrival Verification*\n\nPlease send a photo to confirm you've arrived at the location\n\nനിങ്ങൾ സ്ഥലത്ത് എത്തിയതിന്റെ ഫോട്ടോ അയക്കുക",
            self::NOTIFY_POSTER_ARRIVAL => "📍 *Worker Arrived*\n\nThe worker has arrived at the location!\n\nപണിക്കാരൻ സ്ഥലത്ത് എത്തി!",
            self::WORK_IN_PROGRESS => "⏳ *Work In Progress*\n\nThe job is in progress. Please send a photo when completed.\n\nപണി നടക്കുന്നു. കഴിഞ്ഞാൽ ഫോട്ടോ അയക്കുക.",
            self::COMPLETION_PHOTO => "📸 *Completion Photo*\n\nPlease send a photo showing the completed work\n\nപണി കഴിഞ്ഞതിന്റെ ഫോട്ടോ അയക്കുക",
            self::COMPLETION_CONFIRM_WORKER => "✅ *Confirm Completion*\n\nHave you completed the job?\n\nപണി പൂർത്തിയായോ?",
            self::COMPLETION_CONFIRM_POSTER => "✅ *Confirm Work*\n\nThe worker has marked the job as complete. Please verify and confirm.\n\nപണിക്കാരൻ പണി കഴിഞ്ഞെന്ന് പറഞ്ഞു. ദയവായി സ്ഥിരീകരിക്കുക.",
            self::SELECT_PAYMENT_METHOD => "💳 *Payment Method*\n\nHow will you pay the worker?\n\nപണിക്കാരന് എങ്ങനെ പണം കൊടുക്കും?",
            self::CONFIRM_PAYMENT => "💰 *Confirm Payment*\n\nPlease confirm that payment of ₹{amount} has been made\n\n₹{amount} പേയ്മെന്റ് നടന്നതായി സ്ഥിരീകരിക്കുക",
            self::RATE_WORKER => "⭐ *Rate Worker*\n\nHow was the worker? Please rate 1-5 stars\n\nപണിക്കാരനെ റേറ്റ് ചെയ്യുക (1-5)",
            self::RATE_POSTER => "⭐ *Rate Job Poster*\n\nHow was your experience? Please rate 1-5 stars\n\nജോബ് പോസ്റ്ററിനെ റേറ്റ് ചെയ്യുക (1-5)",
            self::COMPLETE => "🎉 *Job Completed!*\n\nThank you! The job has been completed successfully.\n\nനന്ദി! പണി വിജയകരമായി പൂർത്തിയായി.",
        };
    }

    /**
     * Get the next step for worker flow.
     */
    public function nextForWorker(): ?self
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => self::WORK_IN_PROGRESS,
            self::WORK_IN_PROGRESS => self::COMPLETION_PHOTO,
            self::COMPLETION_PHOTO => self::COMPLETION_CONFIRM_WORKER,
            self::COMPLETION_CONFIRM_WORKER => self::CONFIRM_PAYMENT,
            self::CONFIRM_PAYMENT => self::RATE_POSTER,
            self::RATE_POSTER => self::COMPLETE,
            default => null,
        };
    }

    /**
     * Get the next step for poster flow.
     */
    public function nextForPoster(): ?self
    {
        return match ($this) {
            self::NOTIFY_POSTER_ARRIVAL => self::COMPLETION_CONFIRM_POSTER,
            self::COMPLETION_CONFIRM_POSTER => self::SELECT_PAYMENT_METHOD,
            self::SELECT_PAYMENT_METHOD => self::CONFIRM_PAYMENT,
            self::CONFIRM_PAYMENT => self::RATE_WORKER,
            self::RATE_WORKER => self::COMPLETE,
            default => null,
        };
    }

    /**
     * Get expected input type.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => 'image',
            self::NOTIFY_POSTER_ARRIVAL => 'none',
            self::WORK_IN_PROGRESS => 'none',
            self::COMPLETION_PHOTO => 'image',
            self::COMPLETION_CONFIRM_WORKER => 'button',
            self::COMPLETION_CONFIRM_POSTER => 'button',
            self::SELECT_PAYMENT_METHOD => 'button',
            self::CONFIRM_PAYMENT => 'button',
            self::RATE_WORKER => 'button',
            self::RATE_POSTER => 'button',
            self::COMPLETE => 'none',
        };
    }

    /**
     * Check if step is for worker.
     */
    public function isWorkerStep(): bool
    {
        return in_array($this, [
            self::ARRIVAL_PHOTO,
            self::WORK_IN_PROGRESS,
            self::COMPLETION_PHOTO,
            self::COMPLETION_CONFIRM_WORKER,
            self::RATE_POSTER,
        ]);
    }

    /**
     * Check if step is for poster.
     */
    public function isPosterStep(): bool
    {
        return in_array($this, [
            self::NOTIFY_POSTER_ARRIVAL,
            self::COMPLETION_CONFIRM_POSTER,
            self::SELECT_PAYMENT_METHOD,
            self::RATE_WORKER,
        ]);
    }

    /**
     * Check if step is shared.
     */
    public function isSharedStep(): bool
    {
        return in_array($this, [
            self::CONFIRM_PAYMENT,
            self::COMPLETE,
        ]);
    }

    /**
     * Check if step is optional.
     */
    public function isOptional(): bool
    {
        return in_array($this, [
            self::ARRIVAL_PHOTO,
            self::COMPLETION_PHOTO,
            self::RATE_WORKER,
            self::RATE_POSTER,
        ]);
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get worker flow steps in order.
     */
    public static function workerFlow(): array
    {
        return [
            self::ARRIVAL_PHOTO,
            self::WORK_IN_PROGRESS,
            self::COMPLETION_PHOTO,
            self::COMPLETION_CONFIRM_WORKER,
            self::CONFIRM_PAYMENT,
            self::RATE_POSTER,
            self::COMPLETE,
        ];
    }

    /**
     * Get poster flow steps in order.
     */
    public static function posterFlow(): array
    {
        return [
            self::NOTIFY_POSTER_ARRIVAL,
            self::COMPLETION_CONFIRM_POSTER,
            self::SELECT_PAYMENT_METHOD,
            self::CONFIRM_PAYMENT,
            self::RATE_WORKER,
            self::COMPLETE,
        ];
    }
}