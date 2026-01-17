<?php

namespace App\Services\WhatsApp\Messages;

/**
 * Centralized message templates for NearBuy.
 *
 * All user-facing message strings are stored here for easy
 * maintenance and future translation support.
 */
class MessageTemplates
{
    /*
    |--------------------------------------------------------------------------
    | Welcome & Registration Messages
    |--------------------------------------------------------------------------
    */

    public const WELCOME = "👋 Welcome to *NearBuy*!\n\nYour local marketplace on WhatsApp.\n\nI can help you:\n• Browse offers from nearby shops\n• Search for products locally\n• Create digital agreements";

    public const WELCOME_BACK = "👋 Welcome back to *NearBuy*, {name}!\n\nHow can I help you today?";

    public const REGISTRATION_START = "Welcome to *NearBuy*! 🛒\n\nLet's get you set up. Are you registering as a customer or a shop owner?";

    public const REGISTRATION_COMPLETE_CUSTOMER = "✅ Registration complete!\n\nWelcome to NearBuy, *{name}*!\n\nYou can now browse offers and search for products from local shops.";

    public const REGISTRATION_COMPLETE_SHOP = "✅ Registration complete!\n\nWelcome to NearBuy, *{name}*!\n\nYour shop *{shop_name}* is now registered.\n\nYou can upload offers and respond to customer requests.";

    public const ASK_NAME = "Great! Please enter your name:";

    public const ASK_LOCATION = "📍 Please share your location so we can show you nearby shops and offers.\n\nTap the button below to share:";

    public const LOCATION_SAVED = "✅ Location saved!";

    /*
    |--------------------------------------------------------------------------
    | Main Menu Messages
    |--------------------------------------------------------------------------
    */

    public const MAIN_MENU_HEADER = "🛒 NearBuy";

    public const MAIN_MENU_CUSTOMER = "How can I help you today?";

    public const MAIN_MENU_SHOP = "Welcome back! What would you like to do?";

    public const MAIN_MENU_FOOTER = "Reply 'menu' anytime to return here";

    /*
    |--------------------------------------------------------------------------
    | Offers Messages
    |--------------------------------------------------------------------------
    */

    public const OFFERS_BROWSE_HEADER = "🛍️ Browse Offers";

    public const OFFERS_SELECT_CATEGORY = "Select a category to see offers from nearby shops:";

    public const OFFERS_SELECT_RADIUS = "📍 How far would you like to search?";

    public const OFFERS_EMPTY = "😕 No offers found in this category within {radius}km.\n\nTry a different category or expand your search radius.";

    public const OFFERS_LIST_INTRO = "Here are the latest offers near you:";

    public const OFFERS_UPLOAD_START = "📤 *Upload New Offer*\n\nSend an image or PDF of your offer.\n\n(Supported: JPG, PNG, PDF)";

    public const OFFERS_UPLOAD_CAPTION = "Add a caption for your offer:\n\n(Max 500 characters, or type 'skip')";

    public const OFFERS_SELECT_VALIDITY = "How long should this offer be valid?";

    public const OFFERS_UPLOAD_CONFIRM = "📋 *Confirm Your Offer*\n\n{caption}\n\nValid: {validity}\n\nLooks good?";

    public const OFFERS_UPLOAD_SUCCESS = "✅ Your offer has been uploaded!\n\nCustomers within {radius}km can now see it.";

    public const OFFERS_MAX_REACHED = "⚠️ You've reached the maximum of {max} active offers.\n\nPlease delete an old offer to upload a new one.";

    /*
    |--------------------------------------------------------------------------
    | Product Search Messages
    |--------------------------------------------------------------------------
    */

    public const PRODUCT_SEARCH_HEADER = "🔍 Product Search";

    public const PRODUCT_ASK_DESCRIPTION = "Describe the product you're looking for:\n\n(Be specific for better results)";

    public const PRODUCT_ASK_CATEGORY = "Select a category to target specific shops:\n\n(Or select 'All Categories')";

    public const PRODUCT_ASK_IMAGE = "Would you like to add a reference image?\n\nSend an image or type 'skip'.";

    public const PRODUCT_SELECT_RADIUS = "How far should we search for shops?";

    public const PRODUCT_CONFIRM_REQUEST = "📋 *Confirm Request*\n\n*Product:* {description}\n*Category:* {category}\n*Radius:* {radius}km\n\nSend to {shop_count} nearby shops?";

    public const PRODUCT_REQUEST_SENT = "✅ Your request has been sent to {count} nearby shops!\n\nRequest #: *{request_number}*\n\nWe'll notify you when shops respond. Usually takes 1-24 hours.";

    public const PRODUCT_NO_SHOPS = "😕 No shops found in this category within {radius}km.\n\nTry expanding your radius or selecting a different category.";

    public const PRODUCT_RESPONSES_HEADER = "📬 Responses for #{request_number}";

    public const PRODUCT_NO_RESPONSES = "No responses yet for this request.\n\nShops have been notified. Please check back later.";

    /*
    |--------------------------------------------------------------------------
    | Shop Response Messages
    |--------------------------------------------------------------------------
    */

    public const SHOP_NEW_REQUEST = "📬 *New Product Request*\n\nA customer near you is looking for:\n\n*{description}*\n\nDistance: {distance}km\nRequest #: {request_number}";

    public const SHOP_RESPOND_AVAILABLE = "Is this product available?";

    public const SHOP_RESPOND_PRICE = "Enter your price for this product:\n\n(Numbers only, e.g., 1500)";

    public const SHOP_RESPOND_IMAGE = "Send a photo of the product:\n\n(Or type 'skip')";

    public const SHOP_RESPONSE_CONFIRM = "📋 *Confirm Response*\n\nAvailable: {available}\nPrice: ₹{price}\n\nSend this to the customer?";

    public const SHOP_RESPONSE_SENT = "✅ Your response has been sent!\n\nThe customer will be notified.";

    /*
    |--------------------------------------------------------------------------
    | Agreement Messages
    |--------------------------------------------------------------------------
    */

    public const AGREEMENT_HEADER = "📝 Digital Agreement";

    public const AGREEMENT_ASK_DIRECTION = "Are you giving or receiving money in this agreement?";

    public const AGREEMENT_ASK_PHONE = "Enter the other party's WhatsApp number:\n\n(With country code, e.g., 919876543210)";

    public const AGREEMENT_ASK_NAME = "Enter {direction}'s name:";

    public const AGREEMENT_ASK_AMOUNT = "Enter the amount:\n\n(Numbers only, e.g., 5000)";

    public const AGREEMENT_ASK_PURPOSE = "What is the purpose of this agreement?";

    public const AGREEMENT_ASK_DESCRIPTION = "Add any notes or description:\n\n(Or type 'skip')";

    public const AGREEMENT_ASK_DUE_DATE = "When is this due?";

    public const AGREEMENT_CONFIRM_CREATE = "📋 *Confirm Agreement*\n\n*{direction}:* {other_party_name}\n*Amount:* ₹{amount}\n*Purpose:* {purpose}\n*Due:* {due_date}\n\nCreate this agreement?";

    public const AGREEMENT_CREATED = "✅ Agreement created!\n\nAgreement #: *{agreement_number}*\n\n{other_party_name} ({other_party_phone}) will be notified to confirm.\n\nOnce confirmed, both parties will receive a PDF document.";

    public const AGREEMENT_PENDING_NOTIFICATION = "📝 *New Agreement Request*\n\n*{from_name}* has created an agreement:\n\n*Amount:* ₹{amount}\n*Purpose:* {purpose}\n*Direction:* {direction}\n\nPlease confirm or reject.";

    public const AGREEMENT_CONFIRMED = "✅ Agreement confirmed!\n\nAgreement #: *{agreement_number}*\n\nA PDF document has been sent to both parties.";

    public const AGREEMENT_REJECTED = "❌ Agreement rejected.\n\n{from_name} will be notified.";

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    */

    public const ERROR_GENERIC = "❌ Oops! Something went wrong. Please try again.\n\nIf the problem persists, type 'menu' to start over.";

    public const ERROR_INVALID_INPUT = "🤔 I didn't understand that.\n\n{expected}";

    public const ERROR_INVALID_PHONE = "⚠️ Invalid phone number. Please enter a valid WhatsApp number with country code.\n\nExample: 919876543210";

    public const ERROR_INVALID_AMOUNT = "⚠️ Invalid amount. Please enter numbers only.\n\nExample: 5000";

    public const ERROR_INVALID_DATE = "⚠️ Invalid date format. Please use DD/MM/YYYY.\n\nExample: 25/12/2024";

    public const ERROR_SESSION_TIMEOUT = "⏰ Your session has timed out due to inactivity.\n\nType 'hi' or 'menu' to start again.";

    public const ERROR_NOT_REGISTERED = "⚠️ You need to register first to use this feature.\n\nWould you like to register now?";

    public const ERROR_SHOP_ONLY = "⚠️ This feature is only available for shop owners.\n\nWould you like to register your shop?";

    public const ERROR_FEATURE_DISABLED = "🚫 This feature is currently unavailable.\n\nPlease try again later.";

    /*
    |--------------------------------------------------------------------------
    | Confirmation Messages
    |--------------------------------------------------------------------------
    */

    public const CONFIRM_YES = "Yes, confirm";
    public const CONFIRM_NO = "No, cancel";
    public const CONFIRM_EDIT = "Edit";
    public const CONFIRM_SKIP = "Skip";

    /*
    |--------------------------------------------------------------------------
    | Navigation Messages
    |--------------------------------------------------------------------------
    */

    public const NAV_BACK = "⬅️ Back";
    public const NAV_MENU = "🏠 Main Menu";
    public const NAV_CANCEL = "❌ Cancel";
    public const NAV_NEXT = "Next ➡️";
    public const NAV_DONE = "✅ Done";

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Replace placeholders in a template.
     */
    public static function format(string $template, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $template = str_replace("{{$key}}", (string) $value, $template);
        }

        return $template;
    }

    /**
     * Get expected input help text.
     */
    public static function getExpectedInputHelp(string $expectedType): string
    {
        return match ($expectedType) {
            'text' => 'Please type your response.',
            'button' => 'Please tap one of the buttons above.',
            'list' => 'Please select an option from the list.',
            'location' => 'Please share your location using the button.',
            'image' => 'Please send an image.',
            'image_or_text' => 'Please send an image or type your response.',
            default => 'Please provide a valid response.',
        };
    }
}