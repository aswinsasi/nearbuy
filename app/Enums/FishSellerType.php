<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Types of fish sellers in Pacha Meen.
 *
 * Simplified to 3 types per SRS PM-001:
 * - Fisherman (മത്സ്യത്തൊഴിലാളി) - catches fish directly
 * - Fish Shop (മീൻ കട) - has a permanent shop
 * - Vendor (വെണ്ടർ) - sells at harbour/market stall
 *
 * @srs-ref PM-001 Seller type: shop/fisherman/vendor
 */
enum FishSellerType: string
{
    case FISHERMAN = 'fisherman';
    case FISH_SHOP = 'fish_shop';
    case VENDOR = 'vendor';

    /**
     * Display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::FISHERMAN => 'Fisherman',
            self::FISH_SHOP => 'Fish Shop',
            self::VENDOR => 'Vendor',
        };
    }

    /**
     * Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::FISHERMAN => 'മത്സ്യത്തൊഴിലാളി',
            self::FISH_SHOP => 'മീൻ കട',
            self::VENDOR => 'വെണ്ടർ',
        };
    }

    /**
     * Bilingual label.
     */
    public function bilingualLabel(): string
    {
        return $this->label() . '/' . $this->labelMl();
    }

    /**
     * Icon/emoji.
     */
    public function icon(): string
    {
        return match ($this) {
            self::FISHERMAN => '🎣',
            self::FISH_SHOP => '🏪',
            self::VENDOR => '🚶',
        };
    }

    /**
     * Display with icon.
     */
    public function display(): string
    {
        return $this->icon() . ' ' . $this->label();
    }

    /**
     * Display with icon (bilingual).
     */
    public function displayBilingual(): string
    {
        return $this->icon() . ' ' . $this->bilingualLabel();
    }

    /**
     * Description.
     */
    public function description(): string
    {
        return match ($this) {
            self::FISHERMAN => 'I catch fish from the sea',
            self::FISH_SHOP => 'I have a fish shop',
            self::VENDOR => 'I sell at harbour/market',
        };
    }

    /**
     * Malayalam description.
     */
    public function descriptionMl(): string
    {
        return match ($this) {
            self::FISHERMAN => 'കടലിൽ നിന്ന് മീൻ പിടിക്കുന്നു',
            self::FISH_SHOP => 'മീൻ കട ഉണ്ട്',
            self::VENDOR => 'തുറമുഖത്ത്/മാർക്കറ്റിൽ വിൽക്കുന്നു',
        };
    }

    /**
     * Verification photo prompt.
     *
     * @srs-ref PM-002 Photo verification type by seller
     */
    public function verificationPhotoPrompt(): string
    {
        return match ($this) {
            self::FISHERMAN => "📸 Boat or fishing license photo ayakkuka:\n_ബോട്ട് അല്ലെങ്കിൽ ലൈസൻസ് ഫോട്ടോ_",
            self::FISH_SHOP => "📸 Shop front photo ayakkuka:\n_കട മുൻഭാഗം ഫോട്ടോ_",
            self::VENDOR => "📸 Stall or ID photo ayakkuka:\n_സ്റ്റാൾ അല്ലെങ്കിൽ ID ഫോട്ടോ_",
        };
    }

    /**
     * Location prompt based on type.
     */
    public function locationPrompt(): string
    {
        return match ($this) {
            self::FISHERMAN => "📍 Ninte harbour location share cheyyuka:",
            self::FISH_SHOP => "📍 Ninte shop location share cheyyuka:",
            self::VENDOR => "📍 Ninte market/stall location share cheyyuka:",
        };
    }

    /**
     * Location name prompt based on type.
     */
    public function locationNamePrompt(): string
    {
        return match ($this) {
            self::FISHERMAN => "Harbour name type cheyyuka:\n_ഉദാ: Vizhinjam Harbour_",
            self::FISH_SHOP => "Shop name type cheyyuka:\n_ഉദാ: Varma Fish Mart_",
            self::VENDOR => "Market/Stall name type cheyyuka:\n_ഉദാ: Karamana Market_",
        };
    }

    /**
     * Has fixed location?
     */
    public function hasFixedLocation(): bool
    {
        return $this === self::FISH_SHOP;
    }

    /**
     * Default notification radius (km).
     */
    public function defaultAlertRadius(): int
    {
        return match ($this) {
            self::FISHERMAN => 10,
            self::FISH_SHOP => 5,
            self::VENDOR => 5,
        };
    }

    /**
     * Convert to WhatsApp button.
     */
    public function toButton(): array
    {
        return [
            'id' => 'seller_' . $this->value,
            'title' => mb_substr($this->displayBilingual(), 0, 20),
        ];
    }

    /**
     * Get all as WhatsApp buttons (max 3 = perfect!).
     */
    public static function toButtons(): array
    {
        return array_map(fn(self $type) => $type->toButton(), self::cases());
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Create from button ID.
     */
    public static function fromButtonId(string $buttonId): ?self
    {
        $value = str_replace('seller_', '', $buttonId);
        return self::tryFrom($value);
    }
}