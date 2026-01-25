<?php

namespace App\Enums;

/**
 * Steps in the fish catch posting flow.
 *
 * @srs-ref Section 2.5.1 - Seller Catch Posting Flow (11 steps)
 */
enum FishCatchStep: string
{
    case SELECT_FISH = 'select_fish';
    case ENTER_QUANTITY = 'enter_quantity';
    case ENTER_PRICE = 'enter_price';
    case UPLOAD_PHOTO = 'upload_photo';
    case CONFIRM = 'confirm';
    case ADD_ANOTHER = 'add_another';
    case COMPLETE = 'complete';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::SELECT_FISH => 'Select Fish Type',
            self::ENTER_QUANTITY => 'Enter Quantity',
            self::ENTER_PRICE => 'Enter Price',
            self::UPLOAD_PHOTO => 'Upload Photo',
            self::CONFIRM => 'Confirm Posting',
            self::ADD_ANOTHER => 'Add Another Fish',
            self::COMPLETE => 'Complete',
        };
    }

    /**
     * Get the step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::SELECT_FISH => 1,
            self::ENTER_QUANTITY => 2,
            self::ENTER_PRICE => 3,
            self::UPLOAD_PHOTO => 4,
            self::CONFIRM => 5,
            self::ADD_ANOTHER => 6,
            self::COMPLETE => 7,
        };
    }

    /**
     * Get progress percentage.
     */
    public function progress(): int
    {
        return match ($this) {
            self::SELECT_FISH => 15,
            self::ENTER_QUANTITY => 30,
            self::ENTER_PRICE => 50,
            self::UPLOAD_PHOTO => 70,
            self::CONFIRM => 85,
            self::ADD_ANOTHER => 95,
            self::COMPLETE => 100,
        };
    }

    /**
     * Get the next step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::SELECT_FISH => self::ENTER_QUANTITY,
            self::ENTER_QUANTITY => self::ENTER_PRICE,
            self::ENTER_PRICE => self::UPLOAD_PHOTO,
            self::UPLOAD_PHOTO => self::CONFIRM,
            self::CONFIRM => self::ADD_ANOTHER,
            self::ADD_ANOTHER => self::COMPLETE,
            self::COMPLETE => null,
        };
    }

    /**
     * Get the previous step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::SELECT_FISH => null,
            self::ENTER_QUANTITY => self::SELECT_FISH,
            self::ENTER_PRICE => self::ENTER_QUANTITY,
            self::UPLOAD_PHOTO => self::ENTER_PRICE,
            self::CONFIRM => self::UPLOAD_PHOTO,
            self::ADD_ANOTHER => self::CONFIRM,
            self::COMPLETE => self::ADD_ANOTHER,
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
            self::SELECT_FISH => 'list',
            self::ENTER_QUANTITY => 'button',
            self::ENTER_PRICE => 'text',
            self::UPLOAD_PHOTO => 'image',
            self::CONFIRM => 'button',
            self::ADD_ANOTHER => 'button',
            self::COMPLETE => 'none',
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
