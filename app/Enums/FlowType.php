<?php

namespace App\Enums;

/**
 * Available conversation flow types in NearBuy.
 */
enum FlowType: string
{
    case REGISTRATION = 'registration';
    case MAIN_MENU = 'main_menu';
    case OFFERS_BROWSE = 'offers_browse';
    case OFFERS_UPLOAD = 'offers_upload';
    case OFFERS_MANAGE = 'offers_manage';
    case PRODUCT_SEARCH = 'product_search';
    case PRODUCT_RESPOND = 'product_respond';
    case AGREEMENT_CREATE = 'agreement_create';
    case AGREEMENT_CONFIRM = 'agreement_confirm';
    case AGREEMENT_LIST = 'agreement_list';
    case SETTINGS = 'settings';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::REGISTRATION => 'Registration',
            self::MAIN_MENU => 'Main Menu',
            self::OFFERS_BROWSE => 'Browse Offers',
            self::OFFERS_UPLOAD => 'Upload Offer',
            self::OFFERS_MANAGE => 'Manage Offers',
            self::PRODUCT_SEARCH => 'Product Search',
            self::PRODUCT_RESPOND => 'Respond to Request',
            self::AGREEMENT_CREATE => 'Create Agreement',
            self::AGREEMENT_CONFIRM => 'Confirm Agreement',
            self::AGREEMENT_LIST => 'My Agreements',
            self::SETTINGS => 'Settings',
        };
    }

    /**
     * Get the handler class name.
     */
    public function handlerClass(): string
    {
        return match ($this) {
            self::REGISTRATION => \App\Services\Flow\Handlers\RegistrationHandler::class,
            self::MAIN_MENU => \App\Services\Flow\Handlers\MainMenuHandler::class,
            self::OFFERS_BROWSE => \App\Services\Flow\Handlers\OffersBrowseHandler::class,
            self::OFFERS_UPLOAD => \App\Services\Flow\Handlers\OffersUploadHandler::class,
            self::OFFERS_MANAGE => \App\Services\Flow\Handlers\OffersManageHandler::class,
            self::PRODUCT_SEARCH => \App\Services\Flow\Handlers\ProductSearchHandler::class,
            self::PRODUCT_RESPOND => \App\Services\Flow\Handlers\ProductRespondHandler::class,
            self::AGREEMENT_CREATE => \App\Services\Flow\Handlers\AgreementCreateHandler::class,
            self::AGREEMENT_CONFIRM => \App\Services\Flow\Handlers\AgreementConfirmHandler::class,
            self::AGREEMENT_LIST => \App\Services\Flow\Handlers\AgreementListHandler::class,
            self::SETTINGS => \App\Services\Flow\Handlers\SettingsHandler::class,
        };
    }

    /**
     * Check if this flow requires authentication (registered user).
     */
    public function requiresAuth(): bool
    {
        return match ($this) {
            self::REGISTRATION => false,
            self::MAIN_MENU => false,
            default => true,
        };
    }

    /**
     * Check if this flow is for shop owners only.
     */
    public function isShopOnly(): bool
    {
        return in_array($this, [
            self::OFFERS_UPLOAD,
            self::OFFERS_MANAGE,
            self::PRODUCT_RESPOND,
        ]);
    }

    /**
     * Get the initial step for this flow.
     */
    public function initialStep(): string
    {
        return match ($this) {
            self::REGISTRATION => RegistrationStep::ASK_TYPE->value,
            self::MAIN_MENU => 'show_menu',
            self::OFFERS_BROWSE => OfferStep::SELECT_CATEGORY->value,
            self::OFFERS_UPLOAD => OfferStep::UPLOAD_IMAGE->value,
            self::PRODUCT_SEARCH => ProductSearchStep::ASK_DESCRIPTION->value,
            self::PRODUCT_RESPOND => ProductSearchStep::VIEW_REQUEST->value,
            self::AGREEMENT_CREATE => AgreementStep::ASK_DIRECTION->value,
            self::AGREEMENT_CONFIRM => AgreementStep::SHOW_PENDING->value,
            self::AGREEMENT_LIST => AgreementStep::SHOW_LIST->value,
            self::SETTINGS => 'show_settings',
            default => 'start',
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