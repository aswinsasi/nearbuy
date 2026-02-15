<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Steps in the Flash Deal creation flow.
 *
 * Flow: ASK_TITLE → ASK_IMAGE → ASK_DISCOUNT → ASK_DISCOUNT_CAP →
 *       ASK_TARGET → ASK_TIME_LIMIT → ASK_SCHEDULE → PREVIEW → LAUNCHED
 *
 * @srs-ref FD-001 to FD-008 - Flash Deal Creation Requirements
 * @module Flash Mob Deals
 */
enum FlashDealStep: string
{
    case ASK_TITLE = 'ask_title';
    case ASK_IMAGE = 'ask_image';
    case ASK_DISCOUNT = 'ask_discount';
    case ASK_DISCOUNT_CAP = 'ask_discount_cap';
    case ASK_TARGET = 'ask_target';
    case ASK_TIME_LIMIT = 'ask_time_limit';
    case ASK_SCHEDULE = 'ask_schedule';
    case ASK_CUSTOM_TIME = 'ask_custom_time';
    case PREVIEW = 'preview';
    case EDITING = 'editing';
    case LAUNCHED = 'launched';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ASK_TITLE => 'Deal Title',
            self::ASK_IMAGE => 'Deal Image',
            self::ASK_DISCOUNT => 'Discount Percentage',
            self::ASK_DISCOUNT_CAP => 'Maximum Discount',
            self::ASK_TARGET => 'Target Claims',
            self::ASK_TIME_LIMIT => 'Time Limit',
            self::ASK_SCHEDULE => 'Launch Schedule',
            self::ASK_CUSTOM_TIME => 'Custom Time',
            self::PREVIEW => 'Preview',
            self::EDITING => 'Editing',
            self::LAUNCHED => 'Launched',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::ASK_TITLE => 'ഡീൽ ടൈറ്റിൽ',
            self::ASK_IMAGE => 'ഡീൽ ഇമേജ്',
            self::ASK_DISCOUNT => 'ഡിസ്കൗണ്ട് ശതമാനം',
            self::ASK_DISCOUNT_CAP => 'പരമാവധി ഡിസ്കൗണ്ട്',
            self::ASK_TARGET => 'ടാർഗെറ്റ് ക്ലെയിംസ്',
            self::ASK_TIME_LIMIT => 'സമയ പരിധി',
            self::ASK_SCHEDULE => 'ലോഞ്ച് ഷെഡ്യൂൾ',
            self::ASK_CUSTOM_TIME => 'കസ്റ്റം സമയം',
            self::PREVIEW => 'പ്രിവ്യൂ',
            self::EDITING => 'എഡിറ്റിംഗ്',
            self::LAUNCHED => 'ലോഞ്ച് ചെയ്തു',
        };
    }

    /**
     * Get step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::ASK_TITLE => 1,
            self::ASK_IMAGE => 2,
            self::ASK_DISCOUNT => 3,
            self::ASK_DISCOUNT_CAP => 4,
            self::ASK_TARGET => 5,
            self::ASK_TIME_LIMIT => 6,
            self::ASK_SCHEDULE => 7,
            self::ASK_CUSTOM_TIME => 7, // Sub-step of schedule
            self::PREVIEW => 8,
            self::EDITING => 8, // Back to preview after edit
            self::LAUNCHED => 9,
        };
    }

    /**
     * Get progress percentage.
     */
    public function progress(): int
    {
        return match ($this) {
            self::ASK_TITLE => 10,
            self::ASK_IMAGE => 25,
            self::ASK_DISCOUNT => 40,
            self::ASK_DISCOUNT_CAP => 50,
            self::ASK_TARGET => 65,
            self::ASK_TIME_LIMIT => 75,
            self::ASK_SCHEDULE => 85,
            self::ASK_CUSTOM_TIME => 85,
            self::PREVIEW => 95,
            self::EDITING => 95,
            self::LAUNCHED => 100,
        };
    }

    /**
     * Get expected input type.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::ASK_TITLE => 'text',
            self::ASK_IMAGE => 'image',
            self::ASK_DISCOUNT => 'text', // Numeric
            self::ASK_DISCOUNT_CAP => 'text', // Numeric
            self::ASK_TARGET => 'button',
            self::ASK_TIME_LIMIT => 'button',
            self::ASK_SCHEDULE => 'button',
            self::ASK_CUSTOM_TIME => 'text', // Date/time
            self::PREVIEW => 'button',
            self::EDITING => 'button',
            self::LAUNCHED => 'none',
        };
    }

    /**
     * Get the next step in the flow.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::ASK_TITLE => self::ASK_IMAGE,
            self::ASK_IMAGE => self::ASK_DISCOUNT,
            self::ASK_DISCOUNT => self::ASK_DISCOUNT_CAP,
            self::ASK_DISCOUNT_CAP => self::ASK_TARGET,
            self::ASK_TARGET => self::ASK_TIME_LIMIT,
            self::ASK_TIME_LIMIT => self::ASK_SCHEDULE,
            self::ASK_SCHEDULE => self::PREVIEW,
            self::ASK_CUSTOM_TIME => self::PREVIEW,
            self::PREVIEW => self::LAUNCHED,
            self::EDITING => self::PREVIEW,
            self::LAUNCHED => null,
        };
    }

    /**
     * Get the previous step in the flow.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::ASK_TITLE => null,
            self::ASK_IMAGE => self::ASK_TITLE,
            self::ASK_DISCOUNT => self::ASK_IMAGE,
            self::ASK_DISCOUNT_CAP => self::ASK_DISCOUNT,
            self::ASK_TARGET => self::ASK_DISCOUNT_CAP,
            self::ASK_TIME_LIMIT => self::ASK_TARGET,
            self::ASK_SCHEDULE => self::ASK_TIME_LIMIT,
            self::ASK_CUSTOM_TIME => self::ASK_SCHEDULE,
            self::PREVIEW => self::ASK_SCHEDULE,
            self::EDITING => self::PREVIEW,
            self::LAUNCHED => self::PREVIEW,
        };
    }

    /**
     * Check if step can be skipped.
     */
    public function isSkippable(): bool
    {
        return match ($this) {
            self::ASK_DISCOUNT_CAP => true, // Can skip cap (no maximum)
            self::ASK_CUSTOM_TIME => true, // Only if custom schedule selected
            default => false,
        };
    }

    /**
     * Check if step is terminal.
     */
    public function isTerminal(): bool
    {
        return $this === self::LAUNCHED;
    }

    /**
     * Get validation rules for this step.
     */
    public function validationRules(): array
    {
        return match ($this) {
            self::ASK_TITLE => ['required', 'string', 'min:5', 'max:100'],
            self::ASK_IMAGE => ['required', 'image'],
            self::ASK_DISCOUNT => ['required', 'integer', 'min:5', 'max:90'],
            self::ASK_DISCOUNT_CAP => ['nullable', 'integer', 'min:50', 'max:10000'],
            self::ASK_TARGET => ['required', 'in:10,20,30,50'],
            self::ASK_TIME_LIMIT => ['required', 'in:15,30,60,120'],
            self::ASK_SCHEDULE => ['required', 'in:now,today_6pm,tomorrow_10am,custom'],
            self::ASK_CUSTOM_TIME => ['required', 'date', 'after:now'],
            default => [],
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
     * Get the main flow steps (excluding sub-steps).
     */
    public static function mainFlowSteps(): array
    {
        return [
            self::ASK_TITLE,
            self::ASK_IMAGE,
            self::ASK_DISCOUNT,
            self::ASK_DISCOUNT_CAP,
            self::ASK_TARGET,
            self::ASK_TIME_LIMIT,
            self::ASK_SCHEDULE,
            self::PREVIEW,
            self::LAUNCHED,
        ];
    }

    /**
     * Get editable fields mapping.
     */
    public static function editableFields(): array
    {
        return [
            'title' => self::ASK_TITLE,
            'image' => self::ASK_IMAGE,
            'discount' => self::ASK_DISCOUNT,
            'cap' => self::ASK_DISCOUNT_CAP,
            'target' => self::ASK_TARGET,
            'time' => self::ASK_TIME_LIMIT,
            'schedule' => self::ASK_SCHEDULE,
        ];
    }
}