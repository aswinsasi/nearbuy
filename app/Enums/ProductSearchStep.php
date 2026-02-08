<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Product Search Flow Steps.
 *
 * CUSTOMER SEARCH FLOW:
 * 1. ASK_CATEGORY → Select category
 * 2. ASK_DESCRIPTION → Describe product
 * 3. CONFIRM → Review and send
 * 4. WAITING → Waiting for responses
 * 5. VIEW_RESPONSES → Browse responses (FR-PRD-32)
 * 6. RESPONSE_DETAIL → View single response (FR-PRD-33)
 * 7. CLOSE_REQUEST → Confirm close (FR-PRD-35)
 *
 * @srs-ref FR-PRD-01 to FR-PRD-35
 */
enum ProductSearchStep: string
{
    /*
    |--------------------------------------------------------------------------
    | Customer Search Flow (FR-PRD-01 to FR-PRD-06)
    |--------------------------------------------------------------------------
    */

    /** Step 1: Category selection (FR-PRD-01) */
    case ASK_CATEGORY = 'ask_category';

    /** Step 2: Product description (FR-PRD-02) */
    case ASK_DESCRIPTION = 'ask_description';

    /** Step 3: Optional image */
    case ASK_IMAGE = 'ask_image';

    /** Step 4: Search radius */
    case ASK_RADIUS = 'ask_radius';

    /** Step 5: Confirm request (FR-PRD-04) */
    case CONFIRM = 'confirm';

    /** Step 6: Request sent, waiting */
    case WAITING = 'waiting';

    /*
    |--------------------------------------------------------------------------
    | Response Viewing (FR-PRD-30 to FR-PRD-35)
    |--------------------------------------------------------------------------
    */

    /** FR-PRD-32: View responses list (sorted by price) */
    case VIEW_RESPONSES = 'view_responses';

    /** FR-PRD-33: View single response detail */
    case RESPONSE_DETAIL = 'response_detail';

    /** FR-PRD-34: View shop location */
    case SHOP_LOCATION = 'shop_location';

    /** FR-PRD-35: Confirm close request */
    case CLOSE_REQUEST = 'close_request';

    /** View user's requests list */
    case MY_REQUESTS = 'my_requests';

    /** Request detail view */
    case REQUEST_DETAIL = 'request_detail';

    /*
    |--------------------------------------------------------------------------
    | Shop Response Flow (FR-PRD-20 to FR-PRD-23) - Legacy support
    |--------------------------------------------------------------------------
    */

    case VIEW_REQUEST = 'view_request';
    case RESPOND_AVAILABILITY = 'respond_availability';
    case RESPOND_PRICE = 'respond_price';
    case RESPOND_IMAGE = 'respond_image';
    case RESPOND_NOTES = 'respond_notes';
    case CONFIRM_RESPONSE = 'confirm_response';
    case RESPONSE_SENT = 'response_sent';

    /**
     * Get expected input type.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::ASK_CATEGORY => 'list',
            self::ASK_DESCRIPTION => 'text',
            self::ASK_IMAGE => 'image_or_button',
            self::ASK_RADIUS => 'button',
            self::CONFIRM => 'button',
            self::WAITING => 'button',
            self::VIEW_RESPONSES => 'list',
            self::RESPONSE_DETAIL => 'button',
            self::SHOP_LOCATION => 'button',
            self::CLOSE_REQUEST => 'button',
            self::MY_REQUESTS => 'list',
            self::REQUEST_DETAIL => 'button',
            self::VIEW_REQUEST => 'list',
            self::RESPOND_AVAILABILITY => 'button',
            self::RESPOND_PRICE => 'text',
            self::RESPOND_IMAGE => 'image_or_button',
            self::RESPOND_NOTES => 'text',
            self::CONFIRM_RESPONSE => 'button',
            self::RESPONSE_SENT => 'button',
        };
    }

    /**
     * Check if customer search step.
     */
    public function isCustomerStep(): bool
    {
        return in_array($this, [
            self::ASK_CATEGORY,
            self::ASK_DESCRIPTION,
            self::ASK_IMAGE,
            self::ASK_RADIUS,
            self::CONFIRM,
            self::WAITING,
            self::VIEW_RESPONSES,
            self::RESPONSE_DETAIL,
            self::SHOP_LOCATION,
            self::CLOSE_REQUEST,
            self::MY_REQUESTS,
            self::REQUEST_DETAIL,
        ], true);
    }

    /**
     * Check if shop response step.
     */
    public function isShopStep(): bool
    {
        return in_array($this, [
            self::VIEW_REQUEST,
            self::RESPOND_AVAILABILITY,
            self::RESPOND_PRICE,
            self::RESPOND_IMAGE,
            self::RESPOND_NOTES,
            self::CONFIRM_RESPONSE,
            self::RESPONSE_SENT,
        ], true);
    }

    /**
     * Check if response viewing step.
     */
    public function isResponseViewStep(): bool
    {
        return in_array($this, [
            self::VIEW_RESPONSES,
            self::RESPONSE_DETAIL,
            self::SHOP_LOCATION,
            self::CLOSE_REQUEST,
        ], true);
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}