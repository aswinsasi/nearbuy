<?php

namespace App\Enums;

/**
 * Flow steps for conversation state management.
 *
 * Each flow (registration, offers, products, agreements) has
 * its own set of steps that represent the current state in
 * the conversational flow.
 *
 * @srs-ref Section 7.3 Session State Management
 */
enum FlowStep: string
{
    // Initial/Menu States
    case IDLE = 'idle';
    case MAIN_MENU = 'main_menu';

    // Registration Flow Steps (FR-REG-01 to FR-REG-07, FR-SHOP-01 to FR-SHOP-05)
    case REG_ASK_NAME = 'reg_ask_name';
    case REG_ASK_ROLE = 'reg_ask_role';
    case REG_ASK_LOCATION = 'reg_ask_location';
    case REG_ASK_SHOP_NAME = 'reg_ask_shop_name';
    case REG_ASK_SHOP_CATEGORY = 'reg_ask_shop_category';
    case REG_ASK_SHOP_ADDRESS = 'reg_ask_shop_address';
    case REG_ASK_NOTIFICATION_FREQ = 'reg_ask_notification_freq'; // FR-SHOP-04
    case REG_CONFIRM = 'reg_confirm';
    case REG_COMPLETE = 'reg_complete';

    // Offers Flow Steps - Customer (FR-OFR-10 to FR-OFR-16)
    case OFFERS_SELECT_CATEGORY = 'offers_select_category';
    case OFFERS_SELECT_RADIUS = 'offers_select_radius';
    case OFFERS_BROWSE = 'offers_browse';
    case OFFERS_VIEW_DETAIL = 'offers_view_detail';
    case OFFERS_GET_LOCATION = 'offers_get_location'; // FR-OFR-16

    // Offers Flow Steps - Shop Owner (FR-OFR-01 to FR-OFR-06)
    case OFFERS_UPLOAD_START = 'offers_upload_start';
    case OFFERS_UPLOAD_IMAGE = 'offers_upload_image';
    case OFFERS_UPLOAD_CAPTION = 'offers_upload_caption';
    case OFFERS_UPLOAD_VALIDITY = 'offers_upload_validity';
    case OFFERS_UPLOAD_CONFIRM = 'offers_upload_confirm';
    case OFFERS_MANAGE = 'offers_manage';
    case OFFERS_VIEW_STATS = 'offers_view_stats'; // FR-OFR-06 view counts
    case OFFERS_DELETE_CONFIRM = 'offers_delete_confirm';

    // Product Search Flow Steps - Customer (FR-PRD-01 to FR-PRD-06, FR-PRD-30 to FR-PRD-35)
    case PRODUCT_ASK_CATEGORY = 'product_ask_category';
    case PRODUCT_ASK_DESCRIPTION = 'product_ask_description';
    case PRODUCT_ASK_IMAGE = 'product_ask_image';
    case PRODUCT_ASK_LOCATION = 'product_ask_location';
    case PRODUCT_CONFIRM_REQUEST = 'product_confirm_request';
    case PRODUCT_WAITING_RESPONSES = 'product_waiting_responses';
    case PRODUCT_VIEW_RESPONSES = 'product_view_responses';
    case PRODUCT_RESPONSE_DETAIL = 'product_response_detail';
    case PRODUCT_CLOSE_REQUEST = 'product_close_request'; // FR-PRD-35

    // Product Search Flow Steps - Shop Owner (FR-PRD-10 to FR-PRD-23)
    case PRODUCT_VIEW_REQUEST = 'product_view_request';
    case PRODUCT_RESPOND_AVAILABILITY = 'product_respond_availability';
    case PRODUCT_RESPOND_PRICE = 'product_respond_price';
    case PRODUCT_RESPOND_IMAGE = 'product_respond_image';
    case PRODUCT_RESPOND_NOTES = 'product_respond_notes';
    case PRODUCT_RESPOND_CONFIRM = 'product_respond_confirm';

    // Agreement Flow Steps (FR-AGR-01 to FR-AGR-25)
    case AGREE_ASK_DIRECTION = 'agree_ask_direction';
    case AGREE_ASK_OTHER_PARTY_PHONE = 'agree_ask_other_party_phone';
    case AGREE_ASK_OTHER_PARTY_NAME = 'agree_ask_other_party_name';
    case AGREE_ASK_AMOUNT = 'agree_ask_amount';
    case AGREE_ASK_PURPOSE = 'agree_ask_purpose';
    case AGREE_ASK_DESCRIPTION = 'agree_ask_description';
    case AGREE_ASK_DUE_DATE = 'agree_ask_due_date';
    case AGREE_ASK_CUSTOM_DATE = 'agree_ask_custom_date';
    case AGREE_ASK_NOTES = 'agree_ask_notes';
    case AGREE_CONFIRM_CREATE = 'agree_confirm_create';
    case AGREE_WAITING_CONFIRMATION = 'agree_waiting_confirmation';
    case AGREE_CONFIRM_RECEIVED = 'agree_confirm_received';
    case AGREE_VIEW_LIST = 'agree_view_list';
    case AGREE_VIEW_DETAIL = 'agree_view_detail';
    case AGREE_MARK_COMPLETE = 'agree_mark_complete';
    case AGREE_DISPUTE = 'agree_dispute';

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
     * Get the FlowType enum for this step's flow.
     */
    public function flowType(): ?FlowType
    {
        return match ($this->flow()) {
            'registration' => FlowType::REGISTRATION,
            'offers' => $this->isShopOfferStep() ? FlowType::OFFERS_UPLOAD : FlowType::OFFERS_BROWSE,
            'products' => $this->isShopProductStep() ? FlowType::PRODUCT_RESPOND : FlowType::PRODUCT_SEARCH,
            'agreements' => $this->determineAgreementFlowType(),
            'settings' => FlowType::SETTINGS,
            'main' => FlowType::MAIN_MENU,
            default => null,
        };
    }

    /**
     * Determine the specific agreement flow type.
     */
    private function determineAgreementFlowType(): FlowType
    {
        if (in_array($this, [
            self::AGREE_ASK_DIRECTION,
            self::AGREE_ASK_OTHER_PARTY_PHONE,
            self::AGREE_ASK_OTHER_PARTY_NAME,
            self::AGREE_ASK_AMOUNT,
            self::AGREE_ASK_PURPOSE,
            self::AGREE_ASK_DESCRIPTION,
            self::AGREE_ASK_DUE_DATE,
            self::AGREE_ASK_CUSTOM_DATE,
            self::AGREE_ASK_NOTES,
            self::AGREE_CONFIRM_CREATE,
            self::AGREE_WAITING_CONFIRMATION,
        ])) {
            return FlowType::AGREEMENT_CREATE;
        }

        if ($this === self::AGREE_CONFIRM_RECEIVED) {
            return FlowType::AGREEMENT_CONFIRM;
        }

        return FlowType::AGREEMENT_LIST;
    }

    /**
     * Check if this is a shop owner's offer step.
     */
    private function isShopOfferStep(): bool
    {
        return in_array($this, [
            self::OFFERS_UPLOAD_START,
            self::OFFERS_UPLOAD_IMAGE,
            self::OFFERS_UPLOAD_CAPTION,
            self::OFFERS_UPLOAD_VALIDITY,
            self::OFFERS_UPLOAD_CONFIRM,
            self::OFFERS_MANAGE,
            self::OFFERS_VIEW_STATS,
            self::OFFERS_DELETE_CONFIRM,
        ]);
    }

    /**
     * Check if this is a shop owner's product response step.
     */
    private function isShopProductStep(): bool
    {
        return in_array($this, [
            self::PRODUCT_VIEW_REQUEST,
            self::PRODUCT_RESPOND_AVAILABILITY,
            self::PRODUCT_RESPOND_PRICE,
            self::PRODUCT_RESPOND_IMAGE,
            self::PRODUCT_RESPOND_NOTES,
            self::PRODUCT_RESPOND_CONFIRM,
        ]);
    }

    /**
     * Check if this is an initial/idle state.
     */
    public function isIdle(): bool
    {
        return in_array($this, [self::IDLE, self::MAIN_MENU]);
    }

    /**
     * Check if this is a terminal/completion state.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::REG_COMPLETE,
            self::OFFERS_UPLOAD_CONFIRM,
            self::PRODUCT_CLOSE_REQUEST,
            self::PRODUCT_RESPOND_CONFIRM,
            self::AGREE_WAITING_CONFIRMATION,
        ]);
    }

    /**
     * Check if this step expects a location response.
     *
     * @srs-ref Section 7.2.2 Location Request Flow
     */
    public function expectsLocation(): bool
    {
        return in_array($this, [
            self::REG_ASK_LOCATION,
            self::REG_ASK_SHOP_ADDRESS,
            self::PRODUCT_ASK_LOCATION,
            self::SETTINGS_LOCATION,
        ]);
    }

    /**
     * Check if this step expects an image response.
     *
     * @srs-ref FR-OFR-01, FR-PRD-20
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
     * Check if this step accepts an optional image.
     */
    public function acceptsOptionalImage(): bool
    {
        return in_array($this, [
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
            self::REG_CONFIRM,
            self::OFFERS_UPLOAD_CONFIRM,
            self::OFFERS_DELETE_CONFIRM,
            self::PRODUCT_CONFIRM_REQUEST,
            self::PRODUCT_RESPOND_AVAILABILITY,
            self::PRODUCT_RESPOND_CONFIRM,
            self::AGREE_ASK_DIRECTION,
            self::AGREE_CONFIRM_CREATE,
            self::AGREE_CONFIRM_RECEIVED,
            self::AGREE_MARK_COMPLETE,
            self::AGREE_DISPUTE,
        ]);
    }

    /**
     * Check if this step expects a list selection.
     */
    public function expectsList(): bool
    {
        return in_array($this, [
            self::REG_ASK_SHOP_CATEGORY,
            self::REG_ASK_NOTIFICATION_FREQ,
            self::OFFERS_SELECT_CATEGORY,
            self::OFFERS_SELECT_RADIUS,
            self::OFFERS_BROWSE,
            self::OFFERS_MANAGE,
            self::PRODUCT_ASK_CATEGORY,
            self::PRODUCT_VIEW_RESPONSES,
            self::AGREE_ASK_PURPOSE,
            self::AGREE_ASK_DUE_DATE,
            self::AGREE_VIEW_LIST,
            self::SETTINGS_MENU,
            self::SETTINGS_NOTIFICATION,
            self::SETTINGS_LANGUAGE,
        ]);
    }

    /**
     * Check if this step expects free text input.
     */
    public function expectsText(): bool
    {
        return in_array($this, [
            self::REG_ASK_NAME,
            self::REG_ASK_SHOP_NAME,
            self::OFFERS_UPLOAD_CAPTION,
            self::PRODUCT_ASK_DESCRIPTION,
            self::PRODUCT_RESPOND_PRICE,
            self::PRODUCT_RESPOND_NOTES,
            self::AGREE_ASK_OTHER_PARTY_PHONE,
            self::AGREE_ASK_OTHER_PARTY_NAME,
            self::AGREE_ASK_AMOUNT,
            self::AGREE_ASK_DESCRIPTION,
            self::AGREE_ASK_CUSTOM_DATE,
            self::AGREE_ASK_NOTES,
        ]);
    }

    /**
     * Check if this step allows skipping.
     */
    public function allowsSkip(): bool
    {
        return in_array($this, [
            self::PRODUCT_ASK_IMAGE,
            self::PRODUCT_RESPOND_IMAGE,
            self::PRODUCT_RESPOND_NOTES,
            self::AGREE_ASK_DESCRIPTION,
            self::AGREE_ASK_NOTES,
        ]);
    }

    /**
     * Check if this step can be interrupted by menu command.
     *
     * @srs-ref NFR-U-04 Main menu accessible from any flow state
     */
    public function canBeInterrupted(): bool
    {
        // Terminal and waiting states can always be interrupted
        if ($this->isTerminal() || $this->isIdle()) {
            return true;
        }

        // These steps should not be interrupted mid-flow
        return !in_array($this, [
            self::OFFERS_UPLOAD_CONFIRM,
            self::PRODUCT_CONFIRM_REQUEST,
            self::AGREE_CONFIRM_CREATE,
            self::AGREE_CONFIRM_RECEIVED,
        ]);
    }

    /**
     * Get timeout for this step in minutes.
     *
     * @srs-ref NFR-P-01 Webhook processing within 5 seconds
     */
    public function timeout(): int
    {
        return match ($this->flow()) {
            'registration' => 60,   // 1 hour for registration
            'agreements' => 30,     // 30 min for agreements
            'products' => 120,      // 2 hours for product requests (FR-PRD-06)
            'offers' => 30,         // 30 min for offers
            'settings' => 15,       // 15 min for settings
            default => config('nearbuy.session.timeout_minutes', 30),
        };
    }

    /**
     * Get the prompt message for this step.
     */
    public function prompt(): ?string
    {
        return match ($this) {
            // Registration prompts
            self::REG_ASK_NAME => "👤 What's your name?",
            self::REG_ASK_ROLE => "🏪 Are you registering as a customer or shop owner?",
            self::REG_ASK_LOCATION => "📍 Please share your location so we can find shops near you.",
            self::REG_ASK_SHOP_NAME => "🏪 What's your shop name?",
            self::REG_ASK_SHOP_CATEGORY => "📂 Select your shop category:",
            self::REG_ASK_SHOP_ADDRESS => "📍 Please share your shop location.",
            self::REG_ASK_NOTIFICATION_FREQ => "🔔 How often would you like to receive product requests?",
            self::REG_CONFIRM => "✅ Please confirm your registration details:",
            self::REG_COMPLETE => "🎉 Registration complete! Welcome to NearBuy!",

            // Offer prompts - Customer
            self::OFFERS_SELECT_CATEGORY => "📂 Select a category to browse offers:",
            self::OFFERS_SELECT_RADIUS => "📍 Select search radius:",
            self::OFFERS_BROWSE => "🏷️ Here are the latest offers near you:",
            self::OFFERS_VIEW_DETAIL => "📄 Offer details:",

            // Offer prompts - Shop
            self::OFFERS_UPLOAD_IMAGE => "📸 Send an image or PDF of your offer:",
            self::OFFERS_UPLOAD_CAPTION => "✏️ Add a caption for your offer (optional, type 'skip' to continue):",
            self::OFFERS_UPLOAD_VALIDITY => "⏰ How long should this offer be valid?",
            self::OFFERS_UPLOAD_CONFIRM => "✅ Your offer has been published!",
            self::OFFERS_MANAGE => "⚙️ Manage your offers:",
            self::OFFERS_VIEW_STATS => "📊 Offer Statistics:",

            // Product search prompts - Customer
            self::PRODUCT_ASK_CATEGORY => "📂 What category is the product?",
            self::PRODUCT_ASK_DESCRIPTION => "📝 Describe the product you're looking for:",
            self::PRODUCT_ASK_IMAGE => "📸 Send a photo of the product (optional, type 'skip' to continue):",
            self::PRODUCT_ASK_LOCATION => "📍 Please share your location for nearby shops.",
            self::PRODUCT_CONFIRM_REQUEST => "✅ Ready to send your request to nearby shops?",
            self::PRODUCT_WAITING_RESPONSES => "⏳ Your request has been sent! We'll notify you when shops respond.",
            self::PRODUCT_VIEW_RESPONSES => "📦 Here are the responses from nearby shops:",
            self::PRODUCT_RESPONSE_DETAIL => "📄 Response details:",

            // Product response prompts - Shop
            self::PRODUCT_VIEW_REQUEST => "📋 New product request:",
            self::PRODUCT_RESPOND_AVAILABILITY => "Do you have this product?",
            self::PRODUCT_RESPOND_PRICE => "💰 Enter the price:",
            self::PRODUCT_RESPOND_IMAGE => "📸 Send a photo of the product (optional):",
            self::PRODUCT_RESPOND_NOTES => "📝 Add any notes (optional, type 'skip'):",
            self::PRODUCT_RESPOND_CONFIRM => "✅ Your response has been sent to the customer!",

            // Settings prompts
            self::SETTINGS_MENU => "⚙️ Settings:",
            self::SETTINGS_NOTIFICATION => "🔔 Notification preferences:",
            self::SETTINGS_LOCATION => "📍 Update your location:",
            self::SETTINGS_LANGUAGE => "🌐 Select language:",
            self::SETTINGS_SHOP_PROFILE => "🏪 Shop profile settings:",

            default => null,
        };
    }

    /**
     * Get all steps for a specific flow.
     */
    public static function forFlow(string $flow): array
    {
        return array_filter(
            self::cases(),
            fn(self $step) => $step->flow() === $flow
        );
    }

    /**
     * Get all registration steps in order.
     */
    public static function registrationSteps(): array
    {
        return [
            self::REG_ASK_NAME,
            self::REG_ASK_ROLE,
            self::REG_ASK_LOCATION,
            self::REG_ASK_SHOP_NAME,
            self::REG_ASK_SHOP_CATEGORY,
            self::REG_ASK_SHOP_ADDRESS,
            self::REG_ASK_NOTIFICATION_FREQ,
            self::REG_CONFIRM,
            self::REG_COMPLETE,
        ];
    }

    /**
     * Get customer registration steps only.
     */
    public static function customerRegistrationSteps(): array
    {
        return [
            self::REG_ASK_NAME,
            self::REG_ASK_ROLE,
            self::REG_ASK_LOCATION,
            self::REG_CONFIRM,
            self::REG_COMPLETE,
        ];
    }

    /**
     * Get shop owner registration steps only.
     */
    public static function shopRegistrationSteps(): array
    {
        return [
            self::REG_ASK_NAME,
            self::REG_ASK_ROLE,
            self::REG_ASK_LOCATION,
            self::REG_ASK_SHOP_NAME,
            self::REG_ASK_SHOP_CATEGORY,
            self::REG_ASK_SHOP_ADDRESS,
            self::REG_ASK_NOTIFICATION_FREQ,
            self::REG_CONFIRM,
            self::REG_COMPLETE,
        ];
    }

    /**
     * Get all agreement creation steps.
     */
    public static function agreementCreateSteps(): array
    {
        return [
            self::AGREE_ASK_DIRECTION,
            self::AGREE_ASK_OTHER_PARTY_PHONE,
            self::AGREE_ASK_OTHER_PARTY_NAME,
            self::AGREE_ASK_AMOUNT,
            self::AGREE_ASK_PURPOSE,
            self::AGREE_ASK_DESCRIPTION,
            self::AGREE_ASK_DUE_DATE,
            self::AGREE_ASK_CUSTOM_DATE,
            self::AGREE_CONFIRM_CREATE,
        ];
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}