<?php

namespace App\Enums;

/**
 * Steps in the job worker registration flow.
 *
 * @srs-ref Section 3.2 - Job Worker Registration Flow
 * @module Njaanum Panikkar (Basic Jobs Marketplace)
 */
enum JobWorkerRegistrationStep: string
{
    case ASK_NAME = 'ask_name';
    case ASK_PHOTO = 'ask_photo';
    case ASK_LOCATION = 'ask_location';
    case ASK_VEHICLE = 'ask_vehicle';
    case ASK_JOB_TYPES = 'ask_job_types';
    case ASK_AVAILABILITY = 'ask_availability';
    case CONFIRM_REGISTRATION = 'confirm_registration';
    case COMPLETE = 'complete';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ASK_NAME => 'Enter Name',
            self::ASK_PHOTO => 'Upload Photo',
            self::ASK_LOCATION => 'Share Location',
            self::ASK_VEHICLE => 'Vehicle Type',
            self::ASK_JOB_TYPES => 'Select Job Types',
            self::ASK_AVAILABILITY => 'Set Availability',
            self::CONFIRM_REGISTRATION => 'Confirm Registration',
            self::COMPLETE => 'Complete',
        };
    }

    /**
     * Get the step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::ASK_NAME => 1,
            self::ASK_PHOTO => 2,
            self::ASK_LOCATION => 3,
            self::ASK_VEHICLE => 4,
            self::ASK_JOB_TYPES => 5,
            self::ASK_AVAILABILITY => 6,
            self::CONFIRM_REGISTRATION => 7,
            self::COMPLETE => 8,
        };
    }

    /**
     * Get progress percentage.
     */
    public function progress(): int
    {
        return match ($this) {
            self::ASK_NAME => 12,
            self::ASK_PHOTO => 25,
            self::ASK_LOCATION => 37,
            self::ASK_VEHICLE => 50,
            self::ASK_JOB_TYPES => 62,
            self::ASK_AVAILABILITY => 75,
            self::CONFIRM_REGISTRATION => 87,
            self::COMPLETE => 100,
        };
    }

    /**
     * Get WhatsApp instruction message.
     */
    public function instruction(): string
    {
        return match ($this) {
            self::ASK_NAME => "🧑‍💼 *Worker Registration*\n\nStep 1/7: Please enter your full name\n\nനിങ്ങളുടെ മുഴുവൻ പേര് എഴുതുക",
            self::ASK_PHOTO => "📸 *Profile Photo*\n\nStep 2/7: Please send a clear photo of yourself\n\nനിങ്ങളുടെ ഫോട്ടോ അയക്കുക",
            self::ASK_LOCATION => "📍 *Your Location*\n\nStep 3/7: Please share your location so we can find jobs near you\n\nനിങ്ങളുടെ ലൊക്കേഷൻ ഷെയർ ചെയ്യുക",
            self::ASK_VEHICLE => "🚗 *Vehicle Type*\n\nStep 4/7: Do you have a vehicle for transportation?\n\nയാത്രയ്ക്ക് വാഹനം ഉണ്ടോ?",
            self::ASK_JOB_TYPES => "💼 *Job Types*\n\nStep 5/7: Select the types of jobs you can do\n\nനിങ്ങൾക്ക് ചെയ്യാൻ കഴിയുന്ന പണികൾ തിരഞ്ഞെടുക്കുക",
            self::ASK_AVAILABILITY => "🕐 *Availability*\n\nStep 6/7: When are you available for work?\n\nനിങ്ങൾ എപ്പോൾ ലഭ്യമാണ്?",
            self::CONFIRM_REGISTRATION => "✅ *Confirm Registration*\n\nStep 7/7: Please review your details and confirm\n\nവിവരങ്ങൾ പരിശോധിച്ച് സ്ഥിരീകരിക്കുക",
            self::COMPLETE => "🎉 *Registration Complete!*\n\nYou are now registered as a worker. You'll receive job alerts soon!\n\nനിങ്ങൾ ഇപ്പോൾ ഒരു പണിക്കാരനായി രജിസ്റ്റർ ചെയ്തു!",
        };
    }

    /**
     * Get the next step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::ASK_NAME => self::ASK_PHOTO,
            self::ASK_PHOTO => self::ASK_LOCATION,
            self::ASK_LOCATION => self::ASK_VEHICLE,
            self::ASK_VEHICLE => self::ASK_JOB_TYPES,
            self::ASK_JOB_TYPES => self::ASK_AVAILABILITY,
            self::ASK_AVAILABILITY => self::CONFIRM_REGISTRATION,
            self::CONFIRM_REGISTRATION => self::COMPLETE,
            self::COMPLETE => null,
        };
    }

    /**
     * Get the previous step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::ASK_NAME => null,
            self::ASK_PHOTO => self::ASK_NAME,
            self::ASK_LOCATION => self::ASK_PHOTO,
            self::ASK_VEHICLE => self::ASK_LOCATION,
            self::ASK_JOB_TYPES => self::ASK_VEHICLE,
            self::ASK_AVAILABILITY => self::ASK_JOB_TYPES,
            self::CONFIRM_REGISTRATION => self::ASK_AVAILABILITY,
            self::COMPLETE => self::CONFIRM_REGISTRATION,
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
            self::ASK_NAME => 'text',
            self::ASK_PHOTO => 'image',
            self::ASK_LOCATION => 'location',
            self::ASK_VEHICLE => 'button',
            self::ASK_JOB_TYPES => 'list',
            self::ASK_AVAILABILITY => 'list',
            self::CONFIRM_REGISTRATION => 'button',
            self::COMPLETE => 'none',
        };
    }

    /**
     * Check if step is optional.
     */
    public function isOptional(): bool
    {
        return $this === self::ASK_PHOTO;
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}