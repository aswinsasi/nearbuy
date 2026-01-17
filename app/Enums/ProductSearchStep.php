<?php

namespace App\Enums;

/**
 * Product search flow steps (customer search and shop response).
 */
enum ProductSearchStep: string
{
    // Customer search flow
    case ASK_DESCRIPTION = 'ask_description';
    case ASK_CATEGORY = 'ask_category';
    case ASK_IMAGE = 'ask_image';
    case ASK_LOCATION = 'ask_location';
    case SELECT_RADIUS = 'select_radius';
    case CONFIRM_REQUEST = 'confirm_request';
    case REQUEST_SENT = 'request_sent';
    case VIEW_RESPONSES = 'view_responses';
    case RESPONSE_DETAIL = 'response_detail';
    case SHOW_SHOP_LOCATION = 'show_shop_location';

    // Shop response flow
    case VIEW_REQUEST = 'view_request';
    case RESPOND_AVAILABILITY = 'respond_availability';
    case RESPOND_PRICE = 'respond_price';
    case RESPOND_IMAGE = 'respond_image';
    case RESPOND_NOTES = 'respond_notes';
    case CONFIRM_RESPONSE = 'confirm_response';
    case RESPONSE_SENT = 'response_sent';

    // My requests
    case SHOW_MY_REQUESTS = 'show_my_requests';

    /**
     * Get the prompt message for this step.
     */
    public function prompt(): string
    {
        return match ($this) {
            self::ASK_DESCRIPTION => "🔍 *Product Search*\n\nDescribe the product you're looking for:",
            self::ASK_CATEGORY => "Select a category to target specific shops (or skip):",
            self::ASK_IMAGE => "Would you like to add a reference image? Send an image or type 'skip':",
            self::ASK_LOCATION => "📍 Please share your location to find nearby shops.",
            self::SELECT_RADIUS => "How far should we search?",
            self::CONFIRM_REQUEST => "Please confirm your product request:",
            self::REQUEST_SENT => "✅ Your request has been sent to nearby shops!\n\nWe'll notify you when shops respond.",
            self::VIEW_RESPONSES => "📬 *Responses*\n\nHere are the responses from shops:",
            self::RESPONSE_DETAIL => "Response details:",
            self::SHOW_SHOP_LOCATION => "📍 Shop location:",

            self::VIEW_REQUEST => "📬 *New Product Request*\n\nA customer is looking for:",
            self::RESPOND_AVAILABILITY => "Is this product available?",
            self::RESPOND_PRICE => "Enter your price for this product (numbers only):",
            self::RESPOND_IMAGE => "Send a photo of the product (or type 'skip'):",
            self::RESPOND_NOTES => "Add any notes for the customer (or type 'skip'):",
            self::CONFIRM_RESPONSE => "Please confirm your response:",
            self::RESPONSE_SENT => "✅ Your response has been sent to the customer!",

            self::SHOW_MY_REQUESTS => "📋 *My Requests*\n\nHere are your product requests:",
        };
    }

    /**
     * Get the expected input type for this step.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::ASK_DESCRIPTION => 'text',
            self::ASK_CATEGORY => 'list',
            self::ASK_IMAGE, self::RESPOND_IMAGE => 'image_or_text',
            self::ASK_LOCATION => 'location',
            self::SELECT_RADIUS => 'button',
            self::CONFIRM_REQUEST, self::CONFIRM_RESPONSE => 'button',
            self::REQUEST_SENT, self::RESPONSE_SENT => 'none',
            self::VIEW_RESPONSES, self::SHOW_MY_REQUESTS => 'list',
            self::RESPONSE_DETAIL => 'button',
            self::SHOW_SHOP_LOCATION => 'button',

            self::VIEW_REQUEST => 'button',
            self::RESPOND_AVAILABILITY => 'button',
            self::RESPOND_PRICE => 'text',
            self::RESPOND_NOTES => 'text',
        };
    }

    /**
     * Check if this is a customer search step.
     */
    public function isCustomerStep(): bool
    {
        return in_array($this, [
            self::ASK_DESCRIPTION,
            self::ASK_CATEGORY,
            self::ASK_IMAGE,
            self::ASK_LOCATION,
            self::SELECT_RADIUS,
            self::CONFIRM_REQUEST,
            self::REQUEST_SENT,
            self::VIEW_RESPONSES,
            self::RESPONSE_DETAIL,
            self::SHOW_SHOP_LOCATION,
            self::SHOW_MY_REQUESTS,
        ]);
    }

    /**
     * Check if this is a shop response step.
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