<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Steps in the job execution/verification flow.
 *
 * Simplified flow per SRS requirements:
 * Worker: ARRIVAL_PHOTO → ARRIVAL_CONFIRMED → HANDOVER → COMPLETION_WORKER → RATING → PAYMENT → DONE
 * Poster: (notified) → HANDOVER → COMPLETION_POSTER → RATING → PAYMENT → DONE
 *
 * @srs-ref NP-022 to NP-028 - Job Execution & Verification
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobExecutionStep: string
{
    // Worker arrival verification
    case ARRIVAL_PHOTO = 'arrival_photo';
    case ARRIVAL_CONFIRMED = 'arrival_confirmed';

    // Handover (for queue standing and similar jobs)
    case HANDOVER = 'handover';

    // Completion confirmation
    case COMPLETION_WORKER = 'completion_worker';
    case COMPLETION_POSTER = 'completion_poster';

    // Rating
    case RATING = 'rating';

    // Payment confirmation
    case PAYMENT = 'payment';

    // Final state
    case DONE = 'done';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => 'Send Arrival Photo',
            self::ARRIVAL_CONFIRMED => 'Arrival Confirmed',
            self::HANDOVER => 'Handover',
            self::COMPLETION_WORKER => 'Worker Confirms Done',
            self::COMPLETION_POSTER => 'Poster Confirms Done',
            self::RATING => 'Rate',
            self::PAYMENT => 'Confirm Payment',
            self::DONE => 'Completed',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => 'എത്തിയ ഫോട്ടോ അയക്കുക',
            self::ARRIVAL_CONFIRMED => 'വരവ് സ്ഥിരീകരിച്ചു',
            self::HANDOVER => 'കൈമാറ്റം',
            self::COMPLETION_WORKER => 'പണിക്കാരൻ സ്ഥിരീകരിക്കുന്നു',
            self::COMPLETION_POSTER => 'പോസ്റ്റർ സ്ഥിരീകരിക്കുന്നു',
            self::RATING => 'റേറ്റിംഗ്',
            self::PAYMENT => 'പേയ്മെന്റ് സ്ഥിരീകരിക്കുക',
            self::DONE => 'പൂർത്തിയായി',
        };
    }

    /**
     * Get emoji for display.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => '📸',
            self::ARRIVAL_CONFIRMED => '📍',
            self::HANDOVER => '🤝',
            self::COMPLETION_WORKER => '✅',
            self::COMPLETION_POSTER => '✅',
            self::RATING => '⭐',
            self::PAYMENT => '💰',
            self::DONE => '🎉',
        };
    }

    /**
     * Get the step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => 1,
            self::ARRIVAL_CONFIRMED => 2,
            self::HANDOVER => 3,
            self::COMPLETION_WORKER => 4,
            self::COMPLETION_POSTER => 5,
            self::RATING => 6,
            self::PAYMENT => 7,
            self::DONE => 8,
        };
    }

    /**
     * Get progress percentage.
     */
    public function progress(): int
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => 10,
            self::ARRIVAL_CONFIRMED => 20,
            self::HANDOVER => 35,
            self::COMPLETION_WORKER => 50,
            self::COMPLETION_POSTER => 70,
            self::RATING => 85,
            self::PAYMENT => 95,
            self::DONE => 100,
        };
    }

    /**
     * Get WhatsApp instruction message (bilingual).
     *
     * @srs-ref NP-022 - Worker arrival photo
     * @srs-ref NP-023 - Notify task giver of arrival
     * @srs-ref NP-024 - Handover confirmation
     * @srs-ref NP-025 - Mutual completion confirmation
     * @srs-ref NP-026 - Rating prompt
     * @srs-ref NP-027 - Payment method confirmation
     */
    public function instruction(): string
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => "📸 *Send Arrival Photo*\n*എത്തിയതിന്റെ ഫോട്ടോ അയക്കുക*\n\nPlease send a photo to confirm you've arrived at the job location.\nനിങ്ങൾ ജോലി സ്ഥലത്ത് എത്തിയതായി സ്ഥിരീകരിക്കാൻ ഒരു ഫോട്ടോ അയക്കുക.",

            self::ARRIVAL_CONFIRMED => "📍 *Worker Arrived*\n*പണിക്കാരൻ എത്തി*\n\nThe worker has arrived at the job location!\nപണിക്കാരൻ ജോലി സ്ഥലത്ത് എത്തിയിരിക്കുന്നു!",

            self::HANDOVER => "🤝 *Confirm Handover*\n*കൈമാറ്റം സ്ഥിരീകരിക്കുക*\n\nBoth parties please confirm the handover is complete.\nകൈമാറ്റം പൂർത്തിയായെന്ന് ഇരുകൂട്ടരും സ്ഥിരീകരിക്കുക.",

            self::COMPLETION_WORKER => "✅ *Confirm Job Done*\n*ജോലി കഴിഞ്ഞു എന്ന് സ്ഥിരീകരിക്കുക*\n\nHave you completed the job?\nനിങ്ങൾ ജോലി പൂർത്തിയാക്കിയോ?",

            self::COMPLETION_POSTER => "✅ *Verify Completion*\n*പൂർത്തീകരണം പരിശോധിക്കുക*\n\nThe worker has marked the job as complete. Please verify.\nപണിക്കാരൻ ജോലി പൂർത്തിയായെന്ന് രേഖപ്പെടുത്തി. ദയവായി സ്ഥിരീകരിക്കുക.",

            self::RATING => "⭐ *Rate Your Experience*\n*നിങ്ങളുടെ അനുഭവം റേറ്റ് ചെയ്യുക*\n\nPlease rate your experience (1-5 stars).\nദയവായി നിങ്ങളുടെ അനുഭവം റേറ്റ് ചെയ്യുക (1-5 നക്ഷത്രങ്ങൾ).",

            self::PAYMENT => "💰 *Confirm Payment*\n*പേയ്മെന്റ് സ്ഥിരീകരിക്കുക*\n\nHow was/will the payment be made?\nപേയ്മെന്റ് എങ്ങനെയാണ് നൽകിയത്/നൽകുക?",

            self::DONE => "🎉 *Job Completed!*\n*ജോലി പൂർത്തിയായി!*\n\nThank you! The job has been successfully completed.\nനന്ദി! ജോലി വിജയകരമായി പൂർത്തിയായി.",
        };
    }

    /**
     * Get the next step for worker flow.
     */
    public function nextForWorker(): ?self
    {
        return match ($this) {
            self::ARRIVAL_PHOTO => self::ARRIVAL_CONFIRMED,
            self::ARRIVAL_CONFIRMED => self::HANDOVER,
            self::HANDOVER => self::COMPLETION_WORKER,
            self::COMPLETION_WORKER => self::RATING,
            self::RATING => self::PAYMENT,
            self::PAYMENT => self::DONE,
            default => null,
        };
    }

    /**
     * Get the next step for poster flow.
     */
    public function nextForPoster(): ?self
    {
        return match ($this) {
            self::ARRIVAL_CONFIRMED => self::HANDOVER,
            self::HANDOVER => self::COMPLETION_POSTER,
            self::COMPLETION_POSTER => self::RATING,
            self::RATING => self::PAYMENT,
            self::PAYMENT => self::DONE,
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
            self::ARRIVAL_CONFIRMED => 'none', // Auto-confirmed when photo received
            self::HANDOVER => 'button',
            self::COMPLETION_WORKER => 'button',
            self::COMPLETION_POSTER => 'button',
            self::RATING => 'button', // 1-5 star buttons
            self::PAYMENT => 'button', // Cash/UPI
            self::DONE => 'none',
        };
    }

    /**
     * Get WhatsApp buttons for this step.
     */
    public function buttons(): array
    {
        return match ($this) {
            self::HANDOVER => [
                ['id' => 'handover_confirm', 'title' => '✅ Confirm'],
                ['id' => 'handover_issue', 'title' => '⚠️ Issue'],
            ],
            self::COMPLETION_WORKER => [
                ['id' => 'job_done', 'title' => '✅ Yes, Done'],
                ['id' => 'job_not_done', 'title' => '⏳ Not Yet'],
            ],
            self::COMPLETION_POSTER => [
                ['id' => 'work_approved', 'title' => '✅ Approve'],
                ['id' => 'work_issue', 'title' => '⚠️ Issue'],
            ],
            self::RATING => [
                ['id' => 'rate_5', 'title' => '⭐⭐⭐⭐⭐'],
                ['id' => 'rate_4', 'title' => '⭐⭐⭐⭐'],
                ['id' => 'rate_3', 'title' => '⭐⭐⭐'],
            ],
            self::PAYMENT => [
                ['id' => 'payment_cash', 'title' => '💵 Cash'],
                ['id' => 'payment_upi', 'title' => '📱 UPI'],
            ],
            default => [],
        };
    }

    /**
     * Check if step is for worker.
     */
    public function isWorkerStep(): bool
    {
        return in_array($this, [
            self::ARRIVAL_PHOTO,
            self::COMPLETION_WORKER,
        ]);
    }

    /**
     * Check if step is for poster.
     */
    public function isPosterStep(): bool
    {
        return in_array($this, [
            self::ARRIVAL_CONFIRMED,
            self::COMPLETION_POSTER,
        ]);
    }

    /**
     * Check if step is shared (both parties involved).
     */
    public function isSharedStep(): bool
    {
        return in_array($this, [
            self::HANDOVER,
            self::RATING,
            self::PAYMENT,
            self::DONE,
        ]);
    }

    /**
     * Check if step is optional.
     */
    public function isOptional(): bool
    {
        return in_array($this, [
            self::ARRIVAL_PHOTO, // Can be skipped
            self::HANDOVER, // Only for certain job types
            self::RATING, // Optional but encouraged
        ]);
    }

    /**
     * Check if step requires handover (for queue standing jobs).
     */
    public function requiresHandover(): bool
    {
        return $this === self::HANDOVER;
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
            self::ARRIVAL_CONFIRMED,
            self::HANDOVER,
            self::COMPLETION_WORKER,
            self::RATING,
            self::PAYMENT,
            self::DONE,
        ];
    }

    /**
     * Get poster flow steps in order.
     */
    public static function posterFlow(): array
    {
        return [
            self::ARRIVAL_CONFIRMED,
            self::HANDOVER,
            self::COMPLETION_POSTER,
            self::RATING,
            self::PAYMENT,
            self::DONE,
        ];
    }

    /**
     * Get simplified flow (skipping optional steps).
     */
    public static function minimalFlow(): array
    {
        return [
            self::COMPLETION_WORKER,
            self::COMPLETION_POSTER,
            self::PAYMENT,
            self::DONE,
        ];
    }
}