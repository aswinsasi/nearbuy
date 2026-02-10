<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Steps in the fish catch posting flow.
 *
 * Optimized for SPEED - fishermen do this at 5AM.
 * Each step should take <20 seconds.
 *
 * @srs-ref Section 2.5.1 - Seller Catch Posting Flow
 */
enum FishCatchStep: string
{
    case ASK_FISH_TYPE = 'ask_fish_type';
    case ASK_QUANTITY = 'ask_quantity';
    case ASK_PRICE = 'ask_price';
    case ASK_PHOTO = 'ask_photo';
    case CONFIRM = 'confirm';
    case ADD_MORE = 'add_more';

    /**
     * Display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ASK_FISH_TYPE => 'Select Fish',
            self::ASK_QUANTITY => 'Quantity',
            self::ASK_PRICE => 'Price',
            self::ASK_PHOTO => 'Photo',
            self::CONFIRM => 'Confirm',
            self::ADD_MORE => 'Add More',
        };
    }

    /**
     * Step number (1-based).
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::ASK_FISH_TYPE => 1,
            self::ASK_QUANTITY => 2,
            self::ASK_PRICE => 3,
            self::ASK_PHOTO => 4,
            self::CONFIRM => 5,
            self::ADD_MORE => 6,
        };
    }

    /**
     * Progress percentage.
     */
    public function progress(): int
    {
        return match ($this) {
            self::ASK_FISH_TYPE => 15,
            self::ASK_QUANTITY => 35,
            self::ASK_PRICE => 55,
            self::ASK_PHOTO => 75,
            self::CONFIRM => 90,
            self::ADD_MORE => 100,
        };
    }

    /**
     * Next step.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::ASK_FISH_TYPE => self::ASK_QUANTITY,
            self::ASK_QUANTITY => self::ASK_PRICE,
            self::ASK_PRICE => self::ASK_PHOTO,
            self::ASK_PHOTO => self::CONFIRM,
            self::CONFIRM => self::ADD_MORE,
            self::ADD_MORE => null,
        };
    }

    /**
     * Previous step.
     */
    public function previous(): ?self
    {
        return match ($this) {
            self::ASK_FISH_TYPE => null,
            self::ASK_QUANTITY => self::ASK_FISH_TYPE,
            self::ASK_PRICE => self::ASK_QUANTITY,
            self::ASK_PHOTO => self::ASK_PRICE,
            self::CONFIRM => self::ASK_PHOTO,
            self::ADD_MORE => self::CONFIRM,
        };
    }

    /**
     * Expected input type.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::ASK_FISH_TYPE => 'list',
            self::ASK_QUANTITY => 'button',
            self::ASK_PRICE => 'text',
            self::ASK_PHOTO => 'image',
            self::CONFIRM => 'button',
            self::ADD_MORE => 'button',
        };
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}