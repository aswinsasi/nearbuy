<?php

namespace App\Enums;

/**
 * Types of fish sellers in Pacha Meen.
 *
 * @srs-ref Section 2.2 - User Classes
 */
enum FishSellerType: string
{
    case FISHERMAN = 'fisherman';
    case HARBOUR_VENDOR = 'harbour_vendor';
    case FISH_SHOP = 'fish_shop';
    case WHOLESALER = 'wholesaler';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::FISHERMAN => 'Fisherman',
            self::HARBOUR_VENDOR => 'Harbour Vendor',
            self::FISH_SHOP => 'Fish Shop',
            self::WHOLESALER => 'Wholesaler',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::FISHERMAN => 'മുക്കുവൻ',
            self::HARBOUR_VENDOR => 'തുറമുഖ വില്പനക്കാരൻ',
            self::FISH_SHOP => 'മീൻ കട',
            self::WHOLESALER => 'മൊത്തവ്യാപാരി',
        };
    }

    /**
     * Get emoji for display.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::FISHERMAN => '🚣',
            self::HARBOUR_VENDOR => '⚓',
            self::FISH_SHOP => '🏪',
            self::WHOLESALER => '🚛',
        };
    }

    /**
     * Get button title for WhatsApp.
     */
    public function buttonTitle(): string
    {
        return $this->emoji() . ' ' . $this->label();
    }

    /**
     * Get description for registration.
     */
    public function description(): string
    {
        return match ($this) {
            self::FISHERMAN => 'I catch fish directly from the sea',
            self::HARBOUR_VENDOR => 'I sell fish at the harbour/market',
            self::FISH_SHOP => 'I have a fish shop in town',
            self::WHOLESALER => 'I sell fish in bulk to retailers',
        };
    }

    /**
     * Get description in Malayalam.
     */
    public function descriptionMl(): string
    {
        return match ($this) {
            self::FISHERMAN => 'കടലിൽ നിന്ന് നേരിട്ട് മീൻ പിടിക്കുന്നു',
            self::HARBOUR_VENDOR => 'തുറമുഖത്ത്/മാർക്കറ്റിൽ മീൻ വിൽക്കുന്നു',
            self::FISH_SHOP => 'പട്ടണത്തിൽ മീൻ കട ഉണ്ട്',
            self::WHOLESALER => 'ചില്ലറ വ്യാപാരികൾക്ക് മൊത്തമായി വിൽക്കുന്നു',
        };
    }

    /**
     * Check if seller type typically has fixed location.
     */
    public function hasFixedLocation(): bool
    {
        return in_array($this, [self::FISH_SHOP, self::HARBOUR_VENDOR]);
    }

    /**
     * Check if seller type can update location per catch.
     */
    public function canUpdateCatchLocation(): bool
    {
        return in_array($this, [self::FISHERMAN, self::WHOLESALER]);
    }

    /**
     * Get default notification radius for subscribers (km).
     */
    public function defaultNotificationRadius(): int
    {
        return match ($this) {
            self::FISHERMAN => 10,
            self::HARBOUR_VENDOR => 5,
            self::FISH_SHOP => 3,
            self::WHOLESALER => 20,
        };
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        return [
            'id' => 'seller_type_' . $this->value,
            'title' => substr($this->buttonTitle(), 0, 24),
            'description' => substr($this->description(), 0, 72),
        ];
    }

    /**
     * Get all as WhatsApp list items.
     */
    public static function toListItems(): array
    {
        return array_map(fn(self $type) => $type->toListItem(), self::cases());
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Create from list item ID.
     */
    public static function fromListId(string $listId): ?self
    {
        $value = str_replace('seller_type_', '', $listId);
        return self::tryFrom($value);
    }
}
