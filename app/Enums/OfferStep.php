<?php

namespace App\Enums;

/**
 * Offer flow steps.
 *
 * THREE FLOWS:
 * 1. BROWSE - Customer discovers nearby deals
 * 2. UPLOAD - Shop owner publishes offers (simplified 3-step)
 * 3. MANAGE - Shop owner manages their offers
 *
 * @srs-ref FR-OFR-01 to FR-OFR-16
 */
enum OfferStep: string
{
    /*
    |--------------------------------------------------------------------------
    | Browse Flow Steps (FR-OFR-10 to FR-OFR-16)
    |--------------------------------------------------------------------------
    */

    /** Show category list with offer counts (FR-OFR-10) */
    case SELECT_CATEGORY = 'select_category';

    /** Show offers list sorted by distance (FR-OFR-12, FR-OFR-13) */
    case SHOW_OFFERS = 'show_offers';

    /** View single offer with image + shop details (FR-OFR-14) */
    case VIEW_OFFER = 'view_offer';

    /** After sending location (FR-OFR-16) */
    case SHOW_LOCATION = 'show_location';

    /*
    |--------------------------------------------------------------------------
    | Upload Flow Steps (FR-OFR-01 to FR-OFR-06)
    |--------------------------------------------------------------------------
    */

    /** Step 1: Ask for image/PDF upload */
    case ASK_IMAGE = 'ask_image';

    /** Step 2: Ask validity period */
    case ASK_VALIDITY = 'ask_validity';

    /** Step 3: Upload complete */
    case DONE = 'done';

    /*
    |--------------------------------------------------------------------------
    | Manage Flow Steps
    |--------------------------------------------------------------------------
    */

    /** Show shop owner's offers with stats */
    case SHOW_MY_OFFERS = 'show_my_offers';

    /** Manage single offer (stats, delete, extend) */
    case MANAGE_OFFER = 'manage_offer';

    /** Confirm deletion */
    case DELETE_CONFIRM = 'delete_confirm';

    /** Extend validity selection */
    case EXTEND_VALIDITY = 'extend_validity';

    /**
     * Get expected input type.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            // Browse
            self::SELECT_CATEGORY => 'list',
            self::SHOW_OFFERS => 'list',
            self::VIEW_OFFER => 'button',
            self::SHOW_LOCATION => 'button',

            // Upload
            self::ASK_IMAGE => 'media',
            self::ASK_VALIDITY => 'button',
            self::DONE => 'button',

            // Manage
            self::SHOW_MY_OFFERS => 'list',
            self::MANAGE_OFFER => 'button',
            self::DELETE_CONFIRM => 'button',
            self::EXTEND_VALIDITY => 'button',
        };
    }

    /**
     * Check if browse step.
     */
    public function isBrowseStep(): bool
    {
        return in_array($this, [
            self::SELECT_CATEGORY,
            self::SHOW_OFFERS,
            self::VIEW_OFFER,
            self::SHOW_LOCATION,
        ]);
    }

    /**
     * Check if upload step.
     */
    public function isUploadStep(): bool
    {
        return in_array($this, [
            self::ASK_IMAGE,
            self::ASK_VALIDITY,
            self::DONE,
        ]);
    }

    /**
     * Check if manage step.
     */
    public function isManageStep(): bool
    {
        return in_array($this, [
            self::SHOW_MY_OFFERS,
            self::MANAGE_OFFER,
            self::DELETE_CONFIRM,
            self::EXTEND_VALIDITY,
        ]);
    }

    /**
     * Get all browse steps.
     */
    public static function browseSteps(): array
    {
        return [
            self::SELECT_CATEGORY,
            self::SHOW_OFFERS,
            self::VIEW_OFFER,
            self::SHOW_LOCATION,
        ];
    }

    /**
     * Get all upload steps.
     */
    public static function uploadSteps(): array
    {
        return [
            self::ASK_IMAGE,
            self::ASK_VALIDITY,
            self::DONE,
        ];
    }

    /**
     * Get all manage steps.
     */
    public static function manageSteps(): array
    {
        return [
            self::SHOW_MY_OFFERS,
            self::MANAGE_OFFER,
            self::DELETE_CONFIRM,
            self::EXTEND_VALIDITY,
        ];
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}