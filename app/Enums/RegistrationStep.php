<?php

namespace App\Enums;

/**
 * Registration flow steps.
 */
enum RegistrationStep: string
{
    case ASK_TYPE = 'ask_type';
    case ASK_NAME = 'ask_name';
    case ASK_LOCATION = 'ask_location';
    case ASK_SHOP_NAME = 'ask_shop_name';
    case ASK_SHOP_CATEGORY = 'ask_shop_category';
    case ASK_SHOP_LOCATION = 'ask_shop_location';
    case ASK_NOTIFICATION_PREF = 'ask_notification_pref';
    case CONFIRM = 'confirm';
    case COMPLETE = 'complete';

    /**
     * Get the prompt message for this step.
     */
    public function prompt(): string
    {
        return match ($this) {
            self::ASK_TYPE => "Welcome to *NearBuy*! 🛒\n\nAre you registering as a customer or a shop owner?",
            self::ASK_NAME => "Great! Please enter your name:",
            self::ASK_LOCATION => "📍 Please share your location so we can show you nearby shops and offers.",
            self::ASK_SHOP_NAME => "What is your shop name?",
            self::ASK_SHOP_CATEGORY => "Select your shop category:",
            self::ASK_SHOP_LOCATION => "📍 Please share your shop's location.",
            self::ASK_NOTIFICATION_PREF => "How often would you like to receive product request notifications?",
            self::CONFIRM => "Please confirm your details:",
            self::COMPLETE => "✅ Registration complete! Welcome to NearBuy.",
        };
    }

    /**
     * Get the expected input type for this step.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::ASK_TYPE => 'button',
            self::ASK_NAME => 'text',
            self::ASK_LOCATION, self::ASK_SHOP_LOCATION => 'location',
            self::ASK_SHOP_CATEGORY => 'list',
            self::ASK_NOTIFICATION_PREF => 'list',
            self::CONFIRM => 'button',
            self::COMPLETE => 'none',
        };
    }

    /**
     * Get the next step.
     */
    public function next(bool $isShopOwner = false): ?self
    {
        return match ($this) {
            self::ASK_TYPE => self::ASK_NAME,
            self::ASK_NAME => self::ASK_LOCATION,
            self::ASK_LOCATION => $isShopOwner ? self::ASK_SHOP_NAME : self::CONFIRM,
            self::ASK_SHOP_NAME => self::ASK_SHOP_CATEGORY,
            self::ASK_SHOP_CATEGORY => self::ASK_SHOP_LOCATION,
            self::ASK_SHOP_LOCATION => self::ASK_NOTIFICATION_PREF,
            self::ASK_NOTIFICATION_PREF => self::CONFIRM,
            self::CONFIRM => self::COMPLETE,
            self::COMPLETE => null,
        };
    }

    /**
     * Check if this is a shop-specific step.
     */
    public function isShopStep(): bool
    {
        return in_array($this, [
            self::ASK_SHOP_NAME,
            self::ASK_SHOP_CATEGORY,
            self::ASK_SHOP_LOCATION,
            self::ASK_NOTIFICATION_PREF,
        ]);
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}