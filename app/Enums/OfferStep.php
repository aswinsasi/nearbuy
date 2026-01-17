<?php

namespace App\Enums;

/**
 * Offer flow steps (browse and upload).
 */
enum OfferStep: string
{
    // Browse flow steps
    case SELECT_CATEGORY = 'select_category';
    case SELECT_RADIUS = 'select_radius';
    case SHOW_OFFERS = 'show_offers';
    case VIEW_OFFER = 'view_offer';
    case SHOW_LOCATION = 'show_location';

    // Upload flow steps
    case UPLOAD_IMAGE = 'upload_image';
    case ADD_CAPTION = 'add_caption';
    case SELECT_VALIDITY = 'select_validity';
    case CONFIRM_UPLOAD = 'confirm_upload';
    case UPLOAD_COMPLETE = 'upload_complete';

    // Manage flow steps
    case SHOW_MY_OFFERS = 'show_my_offers';
    case MANAGE_OFFER = 'manage_offer';
    case DELETE_CONFIRM = 'delete_confirm';

    /**
     * Get the prompt message for this step.
     */
    public function prompt(): string
    {
        return match ($this) {
            self::SELECT_CATEGORY => "🛍️ *Browse Offers*\n\nSelect a category to see offers:",
            self::SELECT_RADIUS => "📍 How far would you like to search?",
            self::SHOW_OFFERS => "Here are the latest offers near you:",
            self::VIEW_OFFER => "Offer details:",
            self::SHOW_LOCATION => "📍 Shop location:",

            self::UPLOAD_IMAGE => "📤 *Upload Offer*\n\nPlease send an image or PDF of your offer:",
            self::ADD_CAPTION => "Add a caption for your offer (or type 'skip'):",
            self::SELECT_VALIDITY => "How long should this offer be valid?",
            self::CONFIRM_UPLOAD => "Please confirm your offer:",
            self::UPLOAD_COMPLETE => "✅ Your offer has been uploaded successfully!",

            self::SHOW_MY_OFFERS => "🏷️ *My Offers*\n\nHere are your active offers:",
            self::MANAGE_OFFER => "What would you like to do with this offer?",
            self::DELETE_CONFIRM => "Are you sure you want to delete this offer?",
        };
    }

    /**
     * Get the expected input type for this step.
     */
    public function expectedInput(): string
    {
        return match ($this) {
            self::SELECT_CATEGORY => 'list',
            self::SELECT_RADIUS => 'button',
            self::SHOW_OFFERS => 'list',
            self::VIEW_OFFER => 'button',
            self::SHOW_LOCATION => 'button',

            self::UPLOAD_IMAGE => 'image',
            self::ADD_CAPTION => 'text',
            self::SELECT_VALIDITY => 'button',
            self::CONFIRM_UPLOAD => 'button',
            self::UPLOAD_COMPLETE => 'none',

            self::SHOW_MY_OFFERS => 'list',
            self::MANAGE_OFFER => 'button',
            self::DELETE_CONFIRM => 'button',
        };
    }

    /**
     * Check if this is a browse step.
     */
    public function isBrowseStep(): bool
    {
        return in_array($this, [
            self::SELECT_CATEGORY,
            self::SELECT_RADIUS,
            self::SHOW_OFFERS,
            self::VIEW_OFFER,
            self::SHOW_LOCATION,
        ]);
    }

    /**
     * Check if this is an upload step.
     */
    public function isUploadStep(): bool
    {
        return in_array($this, [
            self::UPLOAD_IMAGE,
            self::ADD_CAPTION,
            self::SELECT_VALIDITY,
            self::CONFIRM_UPLOAD,
            self::UPLOAD_COMPLETE,
        ]);
    }

    /**
     * Check if this is a manage step.
     */
    public function isManageStep(): bool
    {
        return in_array($this, [
            self::SHOW_MY_OFFERS,
            self::MANAGE_OFFER,
            self::DELETE_CONFIRM,
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