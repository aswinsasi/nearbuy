<?php

namespace App\Enums;

/**
 * Shop categories available in NearBuy.
 */
enum ShopCategory: string
{
    case GROCERY = 'grocery';
    case ELECTRONICS = 'electronics';
    case CLOTHING = 'clothing';
    case PHARMACY = 'pharmacy';
    case HARDWARE = 'hardware';
    case RESTAURANT = 'restaurant';
    case BAKERY = 'bakery';
    case STATIONERY = 'stationery';
    case BEAUTY = 'beauty';
    case AUTOMOTIVE = 'automotive';
    case JEWELRY = 'jewelry';
    case FURNITURE = 'furniture';
    case SPORTS = 'sports';
    case PET_STORE = 'pet_store';
    case FLOWERS = 'flowers';
    case OTHER = 'other';

    /**
     * Get the display name.
     */
    public function label(): string
    {
        return config("nearbuy.shop_categories.{$this->value}.name", ucfirst($this->value));
    }

    /**
     * Get the Malayalam name.
     */
    public function labelMalayalam(): string
    {
        return config("nearbuy.shop_categories.{$this->value}.name_ml", $this->label());
    }

    /**
     * Get the emoji icon.
     */
    public function icon(): string
    {
        return config("nearbuy.shop_categories.{$this->value}.icon", '🏪');
    }

    /**
     * Get description.
     */
    public function description(): string
    {
        return config("nearbuy.shop_categories.{$this->value}.description", '');
    }

    /**
     * Get formatted display with icon.
     */
    public function displayWithIcon(): string
    {
        return "{$this->icon()} {$this->label()}";
    }

    /**
     * Get all categories for WhatsApp list selection.
     *
     * @return array<int, array{id: string, title: string, description: string}>
     */
    public static function toListItems(): array
    {
        return array_map(fn (self $category) => [
            'id' => $category->value,
            'title' => substr($category->displayWithIcon(), 0, 24), // WhatsApp limit
            'description' => substr($category->description(), 0, 72), // WhatsApp limit
        ], self::cases());
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}