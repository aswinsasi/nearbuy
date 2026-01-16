<?php

namespace App\Enums;

/**
 * Flow steps for conversation state management.
 *
 * Each flow (registration, offers, products, agreements) has
 * its own set of steps that represent the current state in
 * the conversational flow.
 */
enum FlowStep: string
{
    // Initial/Menu States
    case IDLE = 'idle';
    case MAIN_MENU = 'main_menu';

    // Registration Flow Steps
    case REG_ASK_NAME = 'reg_ask_name';
    case REG_ASK_ROLE = 'reg_ask_role';
    case REG_ASK_LOCATION = 'reg_ask_location';
    case REG_ASK_SHOP_NAME = 'reg_ask_shop_name';
    case REG_ASK_SHOP_CATEGORY = 'reg_ask_shop_category';
    case REG_ASK_SHOP_ADDRESS = 'reg_ask_shop_address';
    case REG_CONFIRM = 'reg_confirm';
    case REG_COMPLETE = 'reg_complete';

    // Offers Flow Steps - Customer
    case OFFERS_SELECT_CATEGORY = 'offers_select_category';
    case OFFERS_SELECT_RADIUS = 'offers_select_radius';
    case OFFERS_BROWSE = 'offers_browse';
    case OFFERS_VIEW_DETAIL = 'offers_view_detail';

    // Offers Flow Steps - Shop Owner
    case OFFERS_UPLOAD_START = 'offers_upload_start';
    case OFFERS_UPLOAD_IMAGE = 'offers_upload_image';
    case OFFERS_UPLOAD_CAPTION = 'offers_upload_caption';
    case OFFERS_UPLOAD_VALIDITY = 'offers_upload_validity';
    case OFFERS_UPLOAD_CONFIRM = 'offers_upload_confirm';
    case OFFERS_MANAGE = 'offers_manage';

    // Product Search Flow Steps - Customer
    case PRODUCT_ASK_DESCRIPTION = 'product_ask_description';
    case PRODUCT_ASK_CATEGORY = 'product_ask_category';
    case PRODUCT_ASK_IMAGE = 'product_ask_image';
    case PRODUCT_ASK_LOCATION = 'product_ask_location';
    case PRODUCT_CONFIRM_REQUEST = 'product_confirm_request';
    case PRODUCT_WAITING_RESPONSES = 'product_waiting_responses';
    case PRODUCT_VIEW_RESPONSES = 'product_view_responses';
    case PRODUCT_RESPONSE_DETAIL = 'product_response_detail';

    // Product Search Flow Steps - Shop Owner
    case PRODUCT_VIEW_REQUEST = 'product_view_request';
    case PRODUCT_RESPOND_AVAILABILITY = 'product_respond_availability';
    case PRODUCT_RESPOND_PRICE = 'product_respond_price';
    case PRODUCT_RESPOND_IMAGE = 'product_respond_image';
    case PRODUCT_RESPOND_NOTES = 'product_respond_notes';
    case PRODUCT_RESPOND_CONFIRM = 'product_respond_confirm';

    // Agreement Flow Steps
    case AGREE_ASK_PURPOSE = 'agree_ask_purpose';
    case AGREE_ASK_AMOUNT = 'agree_ask_amount';
    case AGREE_ASK_OTHER_PARTY = 'agree_ask_other_party';
    case AGREE_ASK_DUE_DATE = 'agree_ask_due_date';
    case AGREE_ASK_CUSTOM_DATE = 'agree_ask_custom_date';
    case AGREE_ASK_NOTES = 'agree_ask_notes';
    case AGREE_CONFIRM_CREATE = 'agree_confirm_create';
    case AGREE_WAITING_CONFIRMATION = 'agree_waiting_confirmation';
    case AGREE_CONFIRM_RECEIVED = 'agree_confirm_received';
    case AGREE_VIEW_LIST = 'agree_view_list';
    case AGREE_VIEW_DETAIL = 'agree_view_detail';

    // Settings Flow Steps
    case SETTINGS_MENU = 'settings_menu';
    case SETTINGS_NOTIFICATION = 'settings_notification';
    case SETTINGS_LOCATION = 'settings_location';
    case SETTINGS_LANGUAGE = 'settings_language';
    case SETTINGS_SHOP_PROFILE = 'settings_shop_profile';

    /**
     * Get the flow name this step belongs to.
     */
    public function flow(): string
    {
        return match (true) {
            str_starts_with($this->value, 'reg_') => 'registration',
            str_starts_with($this->value, 'offers_') => 'offers',
            str_starts_with($this->value, 'product_') => 'products',
            str_starts_with($this->value, 'agree_') => 'agreements',
            str_starts_with($this->value, 'settings_') => 'settings',
            default => 'main',
        };
    }

    /**
     * Check if this is an initial/idle state.
     */
    public function isIdle(): bool
    {
        return in_array($this, [self::IDLE, self::MAIN_MENU]);
    }

    /**
     * Check if this step expects a location response.
     */
    public function expectsLocation(): bool
    {
        return in_array($this, [
            self::REG_ASK_LOCATION,
            self::PRODUCT_ASK_LOCATION,
            self::SETTINGS_LOCATION,
        ]);
    }

    /**
     * Check if this step expects an image response.
     */
    public function expectsImage(): bool
    {
        return in_array($this, [
            self::OFFERS_UPLOAD_IMAGE,
            self::PRODUCT_ASK_IMAGE,
            self::PRODUCT_RESPOND_IMAGE,
        ]);
    }

    /**
     * Check if this step expects a button/interactive response.
     */
    public function expectsInteractive(): bool
    {
        return in_array($this, [
            self::REG_ASK_ROLE,
            self::OFFERS_SELECT_CATEGORY,
            self::OFFERS_SELECT_RADIUS,
            self::AGREE_ASK_PURPOSE,
            self::AGREE_ASK_DUE_DATE,
            self::SETTINGS_NOTIFICATION,
        ]);
    }

    /**
     * Get timeout for this step in minutes.
     */
    public function timeout(): int
    {
        return match ($this->flow()) {
            'registration' => 60, // 1 hour for registration
            'agreements' => 30,   // 30 min for agreements
            default => config('nearbuy.session.timeout_minutes', 30),
        };
    }

    /**
     * Get all steps for a specific flow.
     */
    public static function forFlow(string $flow): array
    {
        return array_filter(
            self::cases(),
            fn (self $step) => $step->flow() === $flow
        );
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}