<?php

namespace App\Enums;

/**
 * Steps in the job application flow (worker applying to a job).
 *
 * @srs-ref Section 3.4 - Job Applications
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobApplicationStep: string
{
    case VIEW_DETAILS = 'view_details';
    case ENTER_MESSAGE = 'enter_message';
    case PROPOSE_AMOUNT = 'propose_amount';
    case CONFIRM_APPLICATION = 'confirm_application';
    case COMPLETE = 'complete';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::VIEW_DETAILS => 'View Job Details',
            self::ENTER_MESSAGE => 'Add Message',
            self::PROPOSE_AMOUNT => 'Propose Amount',
            self::CONFIRM_APPLICATION => 'Confirm Application',
            self::COMPLETE => 'Application Sent',
        };
    }

    /**
     * Get the step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::VIEW_DETAILS => 1,
            self::ENTER_MESSAGE => 2,
            self::PROPOSE_AMOUNT => 3,
            self::CONFIRM_APPLICATION => 4,
            self::COMPLETE => 5,
        };
    }

    /**
     * Get progress percentage.
     */
    public function progress(): int
    {
        return match ($this) {
            self::VIEW_DETAILS => 20,
            self::ENTER_MESSAGE => 40,
            self::PROPOSE_AMOUNT => 60,
            self::CONFIRM_APPLICATION => 80,
            self::COMPLETE => 100,
        };
    }

    /**
     * Get WhatsApp instruction message.
     */
    public function instruction(): string
    {
        return match ($this) {
            self::VIEW_DETAILS => "📋 *Job Details*\n\n{job_details}\n\nDo you want to apply for this job?\nഈ പണിക്ക് അപേക്ഷിക്കണോ?",
            self::ENTER_MESSAGE => "✉️ *Your Message*\n\nWant to add a message to the job poster? (optional)\n\nഒരു സന്ദേശം ചേർക്കണോ? (ഓപ്ഷണൽ)\n\nSend your message or tap 'Skip'",
            self::PROPOSE_AMOUNT => "💰 *Propose Amount*\n\nThe posted pay is ₹{amount}\n\nWant to propose a different amount? (optional)\n\nവേറെ തുക നിർദ്ദേശിക്കണോ?",
            self::CONFIRM_APPLICATION => "✅ *Confirm Application*\n\n📋 Job: {job_title}\n💰 Pay: ₹{amount}\n📍 Location: {location}\n\nConfirm your application?\nഅപേക്ഷ സ്ഥിരീകരിക്കണോ?",
            self::COMPLETE => "🎉 *Application Sent!*\n\nYour application has been sent to the job poster. You'll be notified when they respond.\n\nനിങ്ങളുടെ അപേക്ഷ അയച്ചു! മറുപടി വരുമ്പോൾ അറിയിക്കും.",
        };
    }

    /**
     * Get the next step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::VIEW_DETAILS => self::ENTER_MESSAGE,
            self::ENTER_MESSAGE => self::PROPOSE_AMOUNT,
            self::PROPOSE_AMOUNT => self::CONFIRM_APPLICATION,
            self::CONFIRM_APPLICATION => self::COMPLETE,
            self::COMPLETE => null,
        };
    }

    /**
     * Get the previous step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::VIEW_DETAILS => null,
            self::ENTER_MESSAGE => self::VIEW_DETAILS,
            self::PROPOSE_AMOUNT => self::ENTER_MESSAGE,
            self::CONFIRM_APPLICATION => self::PROPOSE_AMOUNT,
            self::COMPLETE => self::CONFIRM_APPLICATION,
        };
    }

    /**
     * Check if this step can go back.
     */
    public function canGoBack(): bool
    {
        return $this->previous() !== null && $this !== self::COMPLETE;
    }

    /**
     * Get expected input type.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::VIEW_DETAILS => 'button',
            self::ENTER_MESSAGE => 'text',
            self::PROPOSE_AMOUNT => 'text',
            self::CONFIRM_APPLICATION => 'button',
            self::COMPLETE => 'none',
        };
    }

    /**
     * Check if step is optional.
     */
    public function isOptional(): bool
    {
        return in_array($this, [
            self::ENTER_MESSAGE,
            self::PROPOSE_AMOUNT,
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