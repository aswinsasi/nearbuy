<?php

namespace App\Services\WhatsApp\Messages;

/**
 * Centralized message templates for NearBuy.
 *
 * All user-facing message strings are stored here for easy
 * maintenance and future translation support.
 * 
 * ENHANCED: Added consistent navigation, emojis, and viral-ready messaging
 */
class MessageTemplates
{
    /*
    |--------------------------------------------------------------------------
    | Global Navigation Constants (NEW)
    |--------------------------------------------------------------------------
    */

    /** Footer shown on EVERY message for easy navigation */
    public const GLOBAL_FOOTER = "💡 Type 'menu' anytime to go home";

    /** Main menu button - added to most responses */
    public const MENU_BUTTON = ['id' => 'main_menu', 'title' => '🏠 Main Menu'];

    /** Cancel button */
    public const CANCEL_BUTTON = ['id' => 'cancel', 'title' => '❌ Cancel'];

    /** Back button */
    public const BACK_BUTTON = ['id' => 'back', 'title' => '⬅️ Back'];

    /** Retry button */
    public const RETRY_BUTTON = ['id' => 'retry', 'title' => '🔄 Try Again'];

    /** Skip button */
    public const SKIP_BUTTON = ['id' => 'skip', 'title' => '⏭️ Skip'];

    /*
    |--------------------------------------------------------------------------
    | Welcome & Registration Messages
    |--------------------------------------------------------------------------
    */

    public const WELCOME = "🙏 *NearBuy-ലേക്ക് സ്വാഗതം!*\n\n" .
        "Your local marketplace on WhatsApp 🛒\n\n" .
        "I can help you:\n" .
        "• 🛍️ Browse offers from nearby shops\n" .
        "• 🔍 Search for products locally\n" .
        "• 📝 Create digital agreements\n\n" .
        "_No app download needed!_";

    public const WELCOME_BACK = "🙏 Welcome back, *{name}*!\n\nHow can I help you today?";

    public const REGISTRATION_START = "🎉 *Welcome to NearBuy!*\n\n" .
        "Let's get you set up in just 2 minutes.\n\n" .
        "Are you a customer or shop owner?";

    public const REGISTRATION_TYPE_BUTTONS = [
        ['id' => 'customer', 'title' => '🛒 Customer'],
        ['id' => 'shop', 'title' => '🏪 Shop Owner'],
    ];

    public const REGISTRATION_COMPLETE_CUSTOMER = "🎉 *Registration Complete!*\n\n" .
        "Welcome to NearBuy, *{name}*!\n\n" .
        "✅ You can now:\n" .
        "• Browse offers from nearby shops\n" .
        "• Search for products\n" .
        "• Create digital agreements\n\n" .
        "_Let's explore!_";

    public const REGISTRATION_COMPLETE_SHOP = "🎉 *Registration Complete!*\n\n" .
        "Welcome to NearBuy, *{name}*!\n\n" .
        "Your shop *{shop_name}* is now live! 🏪\n\n" .
        "✅ You can now:\n" .
        "• Upload offers for customers\n" .
        "• Respond to product requests\n" .
        "• Create digital agreements\n\n" .
        "_Start by uploading your first offer!_";

    public const ASK_NAME = "👤 *What's your name?*\n\n" .
        "Please type your name:";

    public const ASK_LOCATION = "📍 *Share Your Location*\n\n" .
        "This helps us show you nearby shops and offers.\n\n" .
        "Tap the button below to share:";

    public const ASK_LOCATION_BUTTON = "📍 Share Location";

    public const LOCATION_SAVED = "✅ *Location saved!*\n\nNow we can show you nearby shops.";

    public const ASK_SHOP_NAME = "🏪 *What's your shop name?*\n\nPlease type your shop name:";

    public const ASK_SHOP_CATEGORY = "📂 *Select your shop category:*\n\n" .
        "This helps customers find you easily.";

    public const ASK_NOTIFICATION_FREQUENCY = "🔔 *Notification Preference*\n\n" .
        "How often would you like to receive product request alerts?";

    /*
    |--------------------------------------------------------------------------
    | Main Menu Messages
    |--------------------------------------------------------------------------
    */

    public const MAIN_MENU_HEADER = "🛒 NearBuy";

    public const MAIN_MENU_CUSTOMER = "What would you like to do?";

    public const MAIN_MENU_SHOP = "Welcome back! What would you like to do?";

    public const MAIN_MENU_FOOTER = self::GLOBAL_FOOTER;

    public const MAIN_MENU_BUTTON_TEXT = "📋 View Options";

    /*
    |--------------------------------------------------------------------------
    | Offers Messages
    |--------------------------------------------------------------------------
    */

    public const OFFERS_BROWSE_HEADER = "🛍️ Browse Offers";

    public const OFFERS_SELECT_CATEGORY = "📂 *Select a Category*\n\n" .
        "Choose a category to see offers from nearby shops:";

    public const OFFERS_CATEGORY_BUTTON_TEXT = "📂 Select Category";

    public const OFFERS_SELECT_RADIUS = "📍 *Search Distance*\n\nHow far would you like to search?";

    public const OFFERS_RADIUS_OPTIONS = [
        ['id' => 'radius_2', 'title' => '📍 2 km', 'description' => 'Walking distance'],
        ['id' => 'radius_5', 'title' => '📍 5 km', 'description' => 'Nearby area (Recommended)'],
        ['id' => 'radius_10', 'title' => '📍 10 km', 'description' => 'Extended area'],
        ['id' => 'radius_20', 'title' => '📍 20 km', 'description' => 'Wide search'],
    ];

    public const OFFERS_EMPTY = "😕 *No Offers Found*\n\n" .
        "No offers in {category} within {radius}km.\n\n" .
        "Try:\n" .
        "• Different category\n" .
        "• Larger search radius";

    public const OFFERS_LIST_INTRO = "🛍️ *{count} Offers Found*\n\n" .
        "Here are the latest offers near you:";

    public const OFFERS_VIEW_SHOP_BUTTONS = [
        ['id' => 'get_location', 'title' => '📍 Get Location'],
        ['id' => 'call_shop', 'title' => '📞 Call Shop'],
        ['id' => 'main_menu', 'title' => '🏠 Menu'],
    ];

    public const OFFERS_UPLOAD_START = "📤 *Upload New Offer*\n\n" .
        "Send an image or PDF of your offer.\n\n" .
        "✅ Supported: JPG, PNG, PDF\n" .
        "📏 Max size: 5MB";

    public const OFFERS_UPLOAD_CAPTION = "✍️ *Add a Caption*\n\n" .
        "Describe your offer (what's included, price, etc.)\n\n" .
        "_Max 500 characters_";

    public const OFFERS_UPLOAD_CAPTION_BUTTONS = [
        ['id' => 'skip', 'title' => '⏭️ Skip Caption'],
        ['id' => 'cancel', 'title' => '❌ Cancel'],
    ];

    public const OFFERS_SELECT_VALIDITY = "⏰ *Offer Validity*\n\nHow long should this offer be valid?";

    public const OFFERS_VALIDITY_OPTIONS = [
        ['id' => 'validity_today', 'title' => '📅 Today Only', 'description' => 'Expires tonight'],
        ['id' => 'validity_3days', 'title' => '📅 3 Days', 'description' => 'Short promotion'],
        ['id' => 'validity_week', 'title' => '📅 This Week', 'description' => 'Week-long offer'],
        ['id' => 'validity_month', 'title' => '📅 This Month', 'description' => 'Monthly deal'],
    ];

    public const OFFERS_UPLOAD_CONFIRM = "📋 *Confirm Your Offer*\n\n" .
        "📝 *Caption:* {caption}\n" .
        "⏰ *Valid:* {validity}\n" .
        "📍 *Reach:* ~{reach} customers nearby\n\n" .
        "Ready to publish?";

    public const OFFERS_CONFIRM_BUTTONS = [
        ['id' => 'confirm', 'title' => '✅ Publish'],
        ['id' => 'edit', 'title' => '✏️ Edit'],
        ['id' => 'cancel', 'title' => '❌ Cancel'],
    ];

    public const OFFERS_UPLOAD_SUCCESS = "🎉 *Offer Published!*\n\n" .
        "Your offer is now live!\n\n" .
        "📍 Visible to customers within {radius}km\n" .
        "👀 Expected reach: {reach}+ customers\n\n" .
        "_You'll be notified when customers view it._";

    public const OFFERS_MAX_REACHED = "⚠️ *Limit Reached*\n\n" .
        "You have {max} active offers (maximum allowed).\n\n" .
        "Delete an old offer to upload a new one.";

    /*
    |--------------------------------------------------------------------------
    | Product Search Messages
    |--------------------------------------------------------------------------
    */

    public const PRODUCT_SEARCH_HEADER = "🔍 Product Search";

    public const PRODUCT_ASK_CATEGORY = "📂 *Select Category*\n\n" .
        "Choose the type of shop to search:";

    public const PRODUCT_ASK_DESCRIPTION = "📝 *Describe Your Product*\n\n" .
        "What are you looking for?\n\n" .
        "_Be specific for better results_\n" .
        "_Example: \"iPhone 13 128GB black\" or \"Wooden dining table 6 seater\"_";

    public const PRODUCT_ASK_IMAGE = "📷 *Add Reference Image?*\n\n" .
        "A photo helps shops understand exactly what you need.";

    public const PRODUCT_IMAGE_BUTTONS = [
        ['id' => 'skip_image', 'title' => '⏭️ Skip'],
        ['id' => 'cancel', 'title' => '❌ Cancel'],
    ];

    public const PRODUCT_SELECT_RADIUS = "📍 *Search Distance*\n\nHow far should we search for shops?";

    public const PRODUCT_CONFIRM_REQUEST = "📋 *Confirm Request*\n\n" .
        "🔍 *Product:* {description}\n" .
        "📂 *Category:* {category}\n" .
        "📍 *Radius:* {radius}km\n" .
        "🏪 *Shops:* {shop_count} will be notified\n\n" .
        "Send this request?";

    public const PRODUCT_CONFIRM_BUTTONS = [
        ['id' => 'send', 'title' => '📤 Send Request'],
        ['id' => 'edit', 'title' => '✏️ Edit'],
        ['id' => 'cancel', 'title' => '❌ Cancel'],
    ];

    public const PRODUCT_REQUEST_SENT = "✅ *Request Sent!*\n\n" .
        "📋 Request #: *{request_number}*\n" .
        "🏪 Sent to: {count} shops\n" .
        "⏰ Expires: {expiry}\n\n" .
        "_We'll notify you when shops respond._\n" .
        "_Usually takes 1-2 hours._";

    public const PRODUCT_NO_SHOPS = "😕 *No Shops Found*\n\n" .
        "No {category} shops within {radius}km.\n\n" .
        "Try:\n" .
        "• Different category\n" .
        "• Larger radius";

    public const PRODUCT_RESPONSES_HEADER = "📬 *Responses for #{request_number}*";

    public const PRODUCT_NO_RESPONSES = "⏳ *No Responses Yet*\n\n" .
        "Shops have been notified.\n" .
        "Please check back in a few hours.";

    public const PRODUCT_RESPONSE_ITEM = "🏪 *{shop_name}*\n" .
        "💰 Price: ₹{price}\n" .
        "📍 Distance: {distance}km";

    public const PRODUCT_VIEW_RESPONSE_BUTTONS = [
        ['id' => 'get_location', 'title' => '📍 Get Location'],
        ['id' => 'call_shop', 'title' => '📞 Call Shop'],
        ['id' => 'next_response', 'title' => '➡️ Next'],
    ];

    /*
    |--------------------------------------------------------------------------
    | Shop Response Messages
    |--------------------------------------------------------------------------
    */

    public const SHOP_NEW_REQUEST = "📬 *New Product Request!*\n\n" .
        "A customer is looking for:\n\n" .
        "📝 *{description}*\n\n" .
        "📍 Distance: {distance}km\n" .
        "📋 Request #: {request_number}\n" .
        "⏰ Expires: {expiry}";

    public const SHOP_RESPOND_BUTTONS = [
        ['id' => 'have_it', 'title' => '✅ I Have It'],
        ['id' => 'dont_have', 'title' => '❌ Don\'t Have'],
        ['id' => 'skip', 'title' => '⏭️ Skip'],
    ];

    public const SHOP_RESPOND_PRICE = "💰 *Enter Your Price*\n\n" .
        "What's your price for this product?\n\n" .
        "_Numbers only, e.g., 1500_";

    public const SHOP_RESPOND_IMAGE = "📷 *Send Product Photo*\n\n" .
        "A photo increases customer interest by 3x!";

    public const SHOP_IMAGE_BUTTONS = [
        ['id' => 'skip_image', 'title' => '⏭️ Skip Photo'],
    ];

    public const SHOP_RESPONSE_CONFIRM = "📋 *Confirm Response*\n\n" .
        "💰 Price: ₹{price}\n" .
        "📷 Photo: {has_photo}\n\n" .
        "Send to customer?";

    public const SHOP_RESPONSE_SENT = "✅ *Response Sent!*\n\n" .
        "The customer will see your response.\n\n" .
        "_You'll be notified if they contact you._";

    /*
    |--------------------------------------------------------------------------
    | Agreement Messages
    |--------------------------------------------------------------------------
    */

    public const AGREEMENT_HEADER = "📝 Digital Agreement";

    public const AGREEMENT_INTRO = "📝 *Create Digital Agreement*\n\n" .
        "Record money transactions securely:\n" .
        "• Loans to friends/family\n" .
        "• Work advances\n" .
        "• Deposits\n" .
        "• Business payments\n\n" .
        "Both parties confirm → PDF generated";

    public const AGREEMENT_ASK_DIRECTION = "💸 *Transaction Direction*\n\n" .
        "Are you giving or receiving money?";

    public const AGREEMENT_DIRECTION_BUTTONS = [
        ['id' => 'giving', 'title' => '📤 Giving Money'],
        ['id' => 'receiving', 'title' => '📥 Receiving Money'],
    ];

    public const AGREEMENT_ASK_AMOUNT = "💰 *Enter Amount*\n\n" .
        "How much is this transaction?\n\n" .
        "_Numbers only, e.g., 5000_";

    public const AGREEMENT_ASK_NAME = "👤 *{direction}'s Name*\n\n" .
        "Enter the other person's name:";

    public const AGREEMENT_ASK_PHONE = "📱 *{direction}'s WhatsApp Number*\n\n" .
        "Enter their WhatsApp number:\n\n" .
        "_With country code, e.g., 919876543210_";

    public const AGREEMENT_ASK_PURPOSE = "📋 *Purpose*\n\nWhat is this transaction for?";

    public const AGREEMENT_PURPOSE_OPTIONS = [
        ['id' => 'loan', 'title' => '🤝 Loan', 'description' => 'Lending to friend/family'],
        ['id' => 'advance', 'title' => '🔧 Work Advance', 'description' => 'Advance for work/service'],
        ['id' => 'deposit', 'title' => '🏠 Deposit', 'description' => 'Rent, booking, purchase'],
        ['id' => 'business', 'title' => '💼 Business', 'description' => 'Vendor/supplier payment'],
        ['id' => 'other', 'title' => '📝 Other', 'description' => 'Other purpose'],
    ];

    public const AGREEMENT_ASK_DESCRIPTION = "📝 *Add Notes (Optional)*\n\n" .
        "Any additional details?\n\n" .
        "_E.g., \"For house repair work\" or \"Monthly installment 1 of 3\"_";

    public const AGREEMENT_DESCRIPTION_BUTTONS = [
        ['id' => 'skip', 'title' => '⏭️ Skip'],
    ];

    public const AGREEMENT_ASK_DUE_DATE = "📅 *Due Date*\n\nWhen should this be completed?";

    /**
     * Get agreement due date options with dynamic dates.
     */
    public static function getAgreementDueDateOptions(): array
    {
        return [
            ['id' => 'due_1week', 'title' => '📅 1 Week', 'description' => date('d M Y', strtotime('+1 week'))],
            ['id' => 'due_2weeks', 'title' => '📅 2 Weeks', 'description' => date('d M Y', strtotime('+2 weeks'))],
            ['id' => 'due_1month', 'title' => '📅 1 Month', 'description' => date('d M Y', strtotime('+1 month'))],
            ['id' => 'due_3months', 'title' => '📅 3 Months', 'description' => date('d M Y', strtotime('+3 months'))],
            ['id' => 'due_none', 'title' => '⏳ No Fixed Date', 'description' => 'Open-ended'],
        ];
    }

    public const AGREEMENT_CONFIRM_CREATE = "📋 *Confirm Agreement*\n\n" .
        "👤 *{direction}:* {other_party_name}\n" .
        "📱 *Phone:* {other_party_phone}\n" .
        "💰 *Amount:* ₹{amount}\n" .
        "📋 *Purpose:* {purpose}\n" .
        "📅 *Due:* {due_date}\n" .
        "📝 *Notes:* {description}\n\n" .
        "Create this agreement?";

    public const AGREEMENT_CONFIRM_BUTTONS = [
        ['id' => 'confirm', 'title' => '✅ Create'],
        ['id' => 'edit', 'title' => '✏️ Edit'],
        ['id' => 'cancel', 'title' => '❌ Cancel'],
    ];

    public const AGREEMENT_CREATED = "✅ *Agreement Created!*\n\n" .
        "📋 Agreement #: *{agreement_number}*\n\n" .
        "📤 Confirmation request sent to:\n" .
        "👤 {other_party_name}\n" .
        "📱 {other_party_phone}\n\n" .
        "_Once they confirm, both parties will receive a PDF document._";

    public const AGREEMENT_PENDING_NOTIFICATION = "📝 *Agreement Confirmation Required*\n\n" .
        "*{from_name}* has created an agreement with you:\n\n" .
        "💰 *Amount:* ₹{amount}\n" .
        "📋 *Purpose:* {purpose}\n" .
        "📅 *Due:* {due_date}\n" .
        "💸 *Direction:* {direction}\n\n" .
        "Is this correct?";

    public const AGREEMENT_CONFIRM_COUNTERPARTY_BUTTONS = [
        ['id' => 'confirm', 'title' => '✅ Yes, Confirm'],
        ['id' => 'reject', 'title' => '❌ No, Incorrect'],
        ['id' => 'unknown', 'title' => '❓ Don\'t Know'],
    ];

    public const AGREEMENT_CONFIRMED = "🎉 *Agreement Confirmed!*\n\n" .
        "📋 Agreement #: *{agreement_number}*\n\n" .
        "Both parties have confirmed.\n\n" .
        "📄 *PDF document will be sent shortly...*";

    public const AGREEMENT_PDF_SENT = "📄 *Agreement Document*\n\n" .
        "Agreement #: *{agreement_number}*\n\n" .
        "✅ This is your official record.\n" .
        "🔒 Verified by both parties.\n" .
        "📱 Scan QR code to verify online.";

    public const AGREEMENT_REJECTED = "❌ *Agreement Rejected*\n\n" .
        "The other party has rejected this agreement.\n\n" .
        "📋 Agreement #: {agreement_number}\n" .
        "👤 Rejected by: {rejected_by}";

    public const AGREEMENT_LIST_EMPTY = "📋 *No Agreements*\n\n" .
        "You don't have any agreements yet.\n\n" .
        "Create one to track money transactions securely.";

    public const AGREEMENT_LIST_HEADER = "📋 *Your Agreements*\n\n" .
        "Select an agreement to view details:";

    /*
    |--------------------------------------------------------------------------
    | Error Messages - ENHANCED with Buttons
    |--------------------------------------------------------------------------
    */

    public const ERROR_GENERIC = "😕 *Oops! Something went wrong.*\n\n" .
        "Please try again or return to the main menu.\n\n" .
        "_If this keeps happening, contact support._";

    public const ERROR_INVALID_INPUT = "🤔 *I didn't understand that.*\n\n{expected}";

    public const ERROR_INVALID_PHONE = "⚠️ *Invalid Phone Number*\n\n" .
        "Please enter a valid WhatsApp number with country code.\n\n" .
        "_Example: 919876543210_";

    public const ERROR_INVALID_AMOUNT = "⚠️ *Invalid Amount*\n\n" .
        "Please enter numbers only.\n\n" .
        "_Example: 5000_";

    public const ERROR_INVALID_DATE = "⚠️ *Invalid Date*\n\n" .
        "Please use DD/MM/YYYY format.\n\n" .
        "_Example: 25/12/2024_";

    public const ERROR_SESSION_TIMEOUT = "⏰ *Session Timed Out*\n\n" .
        "Your session expired due to inactivity.\n\n" .
        "Let's start fresh!";

    public const ERROR_NOT_REGISTERED = "👋 *Registration Required*\n\n" .
        "You need to register to use this feature.\n\n" .
        "It only takes 2 minutes!";

    public const ERROR_SHOP_ONLY = "🏪 *Shop Feature*\n\n" .
        "This feature is only for shop owners.\n\n" .
        "Would you like to register your shop?";

    public const ERROR_FEATURE_DISABLED = "🚫 *Feature Unavailable*\n\n" .
        "This feature is currently under maintenance.\n\n" .
        "Please try again later.";

    public const ERROR_MEDIA_UPLOAD_FAILED = "⚠️ *Upload Failed*\n\n" .
        "Couldn't process your file.\n\n" .
        "Please try:\n" .
        "• Smaller file size (< 5MB)\n" .
        "• Different format (JPG, PNG, PDF)";

    public const ERROR_LOCATION_REQUIRED = "📍 *Location Required*\n\n" .
        "This feature needs your location.\n\n" .
        "Please share your location to continue.";

    /*
    |--------------------------------------------------------------------------
    | Button/Navigation Constants
    |--------------------------------------------------------------------------
    */

    public const CONFIRM_YES = "✅ Yes, Confirm";
    public const CONFIRM_NO = "❌ No, Cancel";
    public const CONFIRM_EDIT = "✏️ Edit";
    public const CONFIRM_SKIP = "⏭️ Skip";

    public const NAV_BACK = "⬅️ Back";
    public const NAV_MENU = "🏠 Main Menu";
    public const NAV_CANCEL = "❌ Cancel";
    public const NAV_NEXT = "➡️ Next";
    public const NAV_DONE = "✅ Done";

    /*
    |--------------------------------------------------------------------------
    | Shop Categories with Emojis
    |--------------------------------------------------------------------------
    */

    public const SHOP_CATEGORIES = [
        ['id' => 'grocery', 'title' => '🛒 Grocery', 'description' => 'Vegetables, fruits, daily needs'],
        ['id' => 'electronics', 'title' => '📱 Electronics', 'description' => 'TV, laptop, gadgets'],
        ['id' => 'clothes', 'title' => '👕 Clothes', 'description' => 'Fashion, textiles'],
        ['id' => 'medical', 'title' => '💊 Medical', 'description' => 'Pharmacy, health products'],
        ['id' => 'furniture', 'title' => '🪑 Furniture', 'description' => 'Home & office furniture'],
        ['id' => 'mobile', 'title' => '📲 Mobile', 'description' => 'Phones & accessories'],
        ['id' => 'appliances', 'title' => '🔌 Appliances', 'description' => 'AC, fridge, washing machine'],
        ['id' => 'hardware', 'title' => '🔧 Hardware', 'description' => 'Tools, construction materials'],
    ];

    public const NOTIFICATION_FREQUENCIES = [
        ['id' => 'immediate', 'title' => '🔔 Immediately', 'description' => 'Get notified instantly'],
        ['id' => '2hours', 'title' => '⏰ Every 2 Hours', 'description' => 'Batched (Recommended)'],
        ['id' => 'twice_daily', 'title' => '📅 Twice Daily', 'description' => '9 AM & 5 PM'],
        ['id' => 'daily', 'title' => '🌅 Once Daily', 'description' => 'Morning 9 AM only'],
    ];

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
            'button' => 'Please tap one of the buttons above ☝️',
            'list' => 'Please tap the button and select an option from the list.',
            'location' => 'Please tap "Share Location" button above ☝️',
            'image' => 'Please send an image 📷',
            'image_or_text' => 'Please send an image or type your response.',
            'phone' => 'Please enter a valid phone number with country code.\n_Example: 919876543210_',
            'amount' => 'Please enter numbers only.\n_Example: 5000_',
            default => 'Please provide a valid response.',
        };
    }

    /**
     * Get category emoji by ID.
     */
    public static function getCategoryEmoji(string $categoryId): string
    {
        return match ($categoryId) {
            'grocery' => '🛒',
            'electronics' => '📱',
            'clothes' => '👕',
            'medical' => '💊',
            'furniture' => '🪑',
            'mobile' => '📲',
            'appliances' => '🔌',
            'hardware' => '🔧',
            default => '🏪',
        };
    }

    /**
     * Get category display name with emoji.
     */
    public static function getCategoryDisplay(string $categoryId): string
    {
        $emoji = self::getCategoryEmoji($categoryId);
        $name = ucfirst($categoryId);
        return "{$emoji} {$name}";
    }

    /**
     * Format amount in Indian format with rupee symbol.
     */
    public static function formatAmount(float $amount): string
    {
        return '₹' . number_format($amount, 0, '.', ',');
    }

    /**
     * Format distance.
     */
    public static function formatDistance(float $km): string
    {
        if ($km < 1) {
            return round($km * 1000) . 'm';
        }
        return round($km, 1) . 'km';
    }

    /**
     * Get purpose display with emoji.
     */
    public static function getPurposeDisplay(string $purposeId): string
    {
        return match ($purposeId) {
            'loan' => '🤝 Loan',
            'advance' => '🔧 Work Advance',
            'deposit' => '🏠 Deposit',
            'business' => '💼 Business',
            'other' => '📝 Other',
            default => '📋 ' . ucfirst($purposeId),
        };
    }

    /**
     * Convert amount to words (Indian format).
     */
    public static function amountToWords(float $amount): string
    {
        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $amount = (int) $amount;

        if ($amount == 0) {
            return 'Zero Rupees Only';
        }

        $words = '';

        // Crores (1,00,00,000)
        if ($amount >= 10000000) {
            $crores = (int) ($amount / 10000000);
            $words .= self::convertBelowHundred($crores, $ones, $tens) . ' Crore ';
            $amount %= 10000000;
        }

        // Lakhs (1,00,000)
        if ($amount >= 100000) {
            $lakhs = (int) ($amount / 100000);
            $words .= self::convertBelowHundred($lakhs, $ones, $tens) . ' Lakh ';
            $amount %= 100000;
        }

        // Thousands (1,000)
        if ($amount >= 1000) {
            $thousands = (int) ($amount / 1000);
            $words .= self::convertBelowHundred($thousands, $ones, $tens) . ' Thousand ';
            $amount %= 1000;
        }

        // Hundreds
        if ($amount >= 100) {
            $hundreds = (int) ($amount / 100);
            $words .= $ones[$hundreds] . ' Hundred ';
            $amount %= 100;
        }

        // Remaining
        if ($amount > 0) {
            $words .= self::convertBelowHundred($amount, $ones, $tens);
        }

        return 'Rupees ' . trim($words) . ' Only';
    }

    private static function convertBelowHundred(int $num, array $ones, array $tens): string
    {
        if ($num < 20) {
            return $ones[$num];
        }
        return $tens[(int) ($num / 10)] . ($num % 10 ? ' ' . $ones[$num % 10] : '');
    }
}