<?php

namespace App\Enums;

/**
 * Steps in the job posting flow.
 *
 * @srs-ref Section 3.3 - Job Posting Flow
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobPostingStep: string
{
    case SELECT_CATEGORY = 'select_category';
    case ENTER_TITLE = 'enter_title';
    case ENTER_DESCRIPTION = 'enter_description';
    case ENTER_LOCATION = 'enter_location';
    case REQUEST_LOCATION_COORDS = 'request_location_coords';
    case SELECT_DATE = 'select_date';
    case ENTER_TIME = 'enter_time';
    case SELECT_DURATION = 'select_duration';
    case SUGGEST_PAY = 'suggest_pay';
    case ENTER_PAY = 'enter_pay';
    case ENTER_INSTRUCTIONS = 'enter_instructions';
    case CONFIRM_POST = 'confirm_post';
    case COMPLETE = 'complete';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::SELECT_CATEGORY => 'Select Category',
            self::ENTER_TITLE => 'Job Title',
            self::ENTER_DESCRIPTION => 'Description',
            self::ENTER_LOCATION => 'Location Name',
            self::REQUEST_LOCATION_COORDS => 'Share Location',
            self::SELECT_DATE => 'Select Date',
            self::ENTER_TIME => 'Enter Time',
            self::SELECT_DURATION => 'Duration',
            self::SUGGEST_PAY => 'Suggested Pay',
            self::ENTER_PAY => 'Enter Pay Amount',
            self::ENTER_INSTRUCTIONS => 'Special Instructions',
            self::CONFIRM_POST => 'Confirm Post',
            self::COMPLETE => 'Complete',
        };
    }

    /**
     * Get the step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::SELECT_CATEGORY => 1,
            self::ENTER_TITLE => 2,
            self::ENTER_DESCRIPTION => 3,
            self::ENTER_LOCATION => 4,
            self::REQUEST_LOCATION_COORDS => 5,
            self::SELECT_DATE => 6,
            self::ENTER_TIME => 7,
            self::SELECT_DURATION => 8,
            self::SUGGEST_PAY => 9,
            self::ENTER_PAY => 10,
            self::ENTER_INSTRUCTIONS => 11,
            self::CONFIRM_POST => 12,
            self::COMPLETE => 13,
        };
    }

    /**
     * Get progress percentage.
     */
    public function progress(): int
    {
        return match ($this) {
            self::SELECT_CATEGORY => 8,
            self::ENTER_TITLE => 16,
            self::ENTER_DESCRIPTION => 24,
            self::ENTER_LOCATION => 32,
            self::REQUEST_LOCATION_COORDS => 40,
            self::SELECT_DATE => 48,
            self::ENTER_TIME => 56,
            self::SELECT_DURATION => 64,
            self::SUGGEST_PAY => 72,
            self::ENTER_PAY => 80,
            self::ENTER_INSTRUCTIONS => 88,
            self::CONFIRM_POST => 95,
            self::COMPLETE => 100,
        };
    }

    /**
     * Get WhatsApp instruction message.
     */
    public function instruction(): string
    {
        return match ($this) {
            self::SELECT_CATEGORY => "📋 *Post a Job*\n\nStep 1: Select the type of job you need help with\n\nഎന്ത് പണിക്കാണ് സഹായം വേണ്ടത്?",
            self::ENTER_TITLE => "✏️ *Job Title*\n\nStep 2: Give your job a short title (e.g., 'Stand in queue at RTO')\n\nപണിക്ക് ഒരു ചെറിയ പേര് നൽകുക",
            self::ENTER_DESCRIPTION => "📝 *Description*\n\nStep 3: Describe what needs to be done (optional)\n\nചെയ്യേണ്ട കാര്യം വിവരിക്കുക",
            self::ENTER_LOCATION => "📍 *Location*\n\nStep 4: Where should the worker come? (e.g., 'Collectorate, Ernakulam')\n\nപണിക്കാരൻ എവിടെ വരണം?",
            self::REQUEST_LOCATION_COORDS => "🗺️ *Share Location*\n\nStep 5: Please share the exact location for the job\n\nകൃത്യമായ ലൊക്കേഷൻ ഷെയർ ചെയ്യുക",
            self::SELECT_DATE => "📅 *Job Date*\n\nStep 6: When do you need this done?\n\nഏത് ദിവസം വേണം?",
            self::ENTER_TIME => "⏰ *Job Time*\n\nStep 7: What time should the worker arrive? (e.g., '9:00 AM')\n\nഎത്ര മണിക്ക് എത്തണം?",
            self::SELECT_DURATION => "⏱️ *Duration*\n\nStep 8: How long will this job take approximately?\n\nഏകദേശം എത്ര സമയം എടുക്കും?",
            self::SUGGEST_PAY => "💰 *Suggested Pay*\n\nBased on the job type, typical pay is ₹{min}-₹{max}\n\nDo you want to use the suggested amount?",
            self::ENTER_PAY => "💵 *Payment Amount*\n\nStep 9: How much will you pay? (in ₹)\n\nഎത്ര രൂപ കൊടുക്കും?",
            self::ENTER_INSTRUCTIONS => "📌 *Special Instructions*\n\nStep 10: Any special instructions for the worker? (optional)\n\nപ്രത്യേക നിർദ്ദേശങ്ങൾ ഉണ്ടോ?",
            self::CONFIRM_POST => "✅ *Confirm Job Post*\n\nPlease review your job details and confirm\n\nവിവരങ്ങൾ പരിശോധിച്ച് സ്ഥിരീകരിക്കുക",
            self::COMPLETE => "🎉 *Job Posted!*\n\nYour job has been posted. Workers will apply soon!\n\nJob ID: {job_number}\n\nനിങ്ങളുടെ പണി പോസ്റ്റ് ചെയ്തു!",
        };
    }

    /**
     * Get the next step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::SELECT_CATEGORY => self::ENTER_TITLE,
            self::ENTER_TITLE => self::ENTER_DESCRIPTION,
            self::ENTER_DESCRIPTION => self::ENTER_LOCATION,
            self::ENTER_LOCATION => self::REQUEST_LOCATION_COORDS,
            self::REQUEST_LOCATION_COORDS => self::SELECT_DATE,
            self::SELECT_DATE => self::ENTER_TIME,
            self::ENTER_TIME => self::SELECT_DURATION,
            self::SELECT_DURATION => self::SUGGEST_PAY,
            self::SUGGEST_PAY => self::ENTER_PAY,
            self::ENTER_PAY => self::ENTER_INSTRUCTIONS,
            self::ENTER_INSTRUCTIONS => self::CONFIRM_POST,
            self::CONFIRM_POST => self::COMPLETE,
            self::COMPLETE => null,
        };
    }

    /**
     * Get the previous step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::SELECT_CATEGORY => null,
            self::ENTER_TITLE => self::SELECT_CATEGORY,
            self::ENTER_DESCRIPTION => self::ENTER_TITLE,
            self::ENTER_LOCATION => self::ENTER_DESCRIPTION,
            self::REQUEST_LOCATION_COORDS => self::ENTER_LOCATION,
            self::SELECT_DATE => self::REQUEST_LOCATION_COORDS,
            self::ENTER_TIME => self::SELECT_DATE,
            self::SELECT_DURATION => self::ENTER_TIME,
            self::SUGGEST_PAY => self::SELECT_DURATION,
            self::ENTER_PAY => self::SUGGEST_PAY,
            self::ENTER_INSTRUCTIONS => self::ENTER_PAY,
            self::CONFIRM_POST => self::ENTER_INSTRUCTIONS,
            self::COMPLETE => self::CONFIRM_POST,
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
            self::SELECT_CATEGORY => 'list',
            self::ENTER_TITLE => 'text',
            self::ENTER_DESCRIPTION => 'text',
            self::ENTER_LOCATION => 'text',
            self::REQUEST_LOCATION_COORDS => 'location',
            self::SELECT_DATE => 'button',
            self::ENTER_TIME => 'text',
            self::SELECT_DURATION => 'button',
            self::SUGGEST_PAY => 'button',
            self::ENTER_PAY => 'text',
            self::ENTER_INSTRUCTIONS => 'text',
            self::CONFIRM_POST => 'button',
            self::COMPLETE => 'none',
        };
    }

    /**
     * Check if step is optional.
     */
    public function isOptional(): bool
    {
        return in_array($this, [
            self::ENTER_DESCRIPTION,
            self::ENTER_INSTRUCTIONS,
            self::REQUEST_LOCATION_COORDS,
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