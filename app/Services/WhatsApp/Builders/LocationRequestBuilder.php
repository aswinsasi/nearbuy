<?php

namespace App\Services\WhatsApp\Builders;

/**
 * Builder for WhatsApp location request messages.
 *
 * UX Principles:
 * - Friendly, non-technical language
 * - Clear privacy assurance (NFR-S-04 spirit)
 * - Bilingual support: English and Malayalam (NFR-U-05)
 * - Context-specific messages for different flows
 *
 * Sends a message asking the user to share their location.
 * Uses the interactive location_request_message type (Section 7.2.2).
 *
 * @example
 * // Registration flow
 * $message = LocationRequestBuilder::create('919876543210')
 *     ->forRegistration('ml')
 *     ->build();
 *
 * // Nearby offers
 * $message = LocationRequestBuilder::create($phone)
 *     ->forNearbyOffers()
 *     ->build();
 */
class LocationRequestBuilder
{
    private string $to;
    private string $body = '';
    private ?string $replyTo = null;

    /**
     * Maximum body length (WhatsApp enforced).
     */
    public const MAX_BODY_LENGTH = 1024;

    /*
    |--------------------------------------------------------------------------
    | Pre-built Messages (English)
    |--------------------------------------------------------------------------
    */

    /**
     * Generic location request — English.
     */
    private const MSG_GENERIC_EN = "📍 Please share your location.\n\nThis helps us show you nearby options. Your location is kept private and secure. 🔒";

    /**
     * Registration flow — English.
     */
    private const MSG_REGISTRATION_EN = "📍 Please share your location to complete registration.\n\nWe'll use this to show you offers and shops nearby. Your location stays private. 🔒";

    /**
     * Shop registration — English.
     */
    private const MSG_SHOP_REGISTRATION_EN = "📍 Please share your shop location.\n\nCustomers will see this when browsing nearby offers. Make sure you're at your shop! 🏪";

    /**
     * Nearby offers — English.
     */
    private const MSG_OFFERS_EN = "📍 Share your location to see nearby offers.\n\nWe'll show you the best deals within 5 km. Your location is only used for this search. 🔒";

    /**
     * Product search — English.
     */
    private const MSG_PRODUCT_SEARCH_EN = "📍 Share your location so we can find shops near you.\n\nWe'll send your request to nearby shops only. 🔒";

    /**
     * Fish alerts — English.
     */
    private const MSG_FISH_ALERTS_EN = "📍 Share your location for fresh fish alerts.\n\nWe'll notify you when fresh catch arrives at markets near you. 🐟";

    /**
     * Jobs — English.
     */
    private const MSG_JOBS_EN = "📍 Share your location to find jobs nearby.\n\nWe'll match you with tasks within your area. 👷";

    /**
     * Update location — English.
     */
    private const MSG_UPDATE_EN = "📍 Want to update your location?\n\nTap the button below to share your current location. 🔄";

    /*
    |--------------------------------------------------------------------------
    | Pre-built Messages (Malayalam)
    |--------------------------------------------------------------------------
    */

    /**
     * Generic location request — Malayalam.
     */
    private const MSG_GENERIC_ML = "📍 നിങ്ങളുടെ ലൊക്കേഷൻ ഷെയർ ചെയ്യാമോ?\n\nസമീപത്തെ ഓപ്ഷനുകൾ കാണിക്കാൻ ഇത് സഹായിക്കും. നിങ്ങളുടെ ലൊക്കേഷൻ സുരക്ഷിതമാണ്. 🔒";

    /**
     * Registration flow — Malayalam.
     */
    private const MSG_REGISTRATION_ML = "📍 രജിസ്ട്രേഷൻ പൂർത്തിയാക്കാൻ ലൊക്കേഷൻ ഷെയർ ചെയ്യൂ.\n\nസമീപത്തെ ഓഫറുകളും കടകളും കാണിക്കാൻ ഇത് ഉപയോഗിക്കും. നിങ്ങളുടെ ലൊക്കേഷൻ രഹസ്യമായി സൂക്ഷിക്കും. 🔒";

    /**
     * Shop registration — Malayalam.
     */
    private const MSG_SHOP_REGISTRATION_ML = "📍 നിങ്ങളുടെ കടയുടെ ലൊക്കേഷൻ ഷെയർ ചെയ്യൂ.\n\nഓഫറുകൾ ബ്രൗസ് ചെയ്യുമ്പോൾ കസ്റ്റമേഴ്സ് ഇത് കാണും. നിങ്ങൾ കടയിൽ ഉണ്ടെന്ന് ഉറപ്പാക്കൂ! 🏪";

    /**
     * Nearby offers — Malayalam.
     */
    private const MSG_OFFERS_ML = "📍 സമീപത്തെ ഓഫറുകൾ കാണാൻ ലൊക്കേഷൻ ഷെയർ ചെയ്യൂ.\n\n5 km ചുറ്റളവിലെ മികച്ച ഡീലുകൾ കാണിക്കാം. ഈ സെർച്ചിന് മാത്രമേ ലൊക്കേഷൻ ഉപയോഗിക്കൂ. 🔒";

    /**
     * Product search — Malayalam.
     */
    private const MSG_PRODUCT_SEARCH_ML = "📍 സമീപത്തെ കടകൾ കണ്ടെത്താൻ ലൊക്കേഷൻ ഷെയർ ചെയ്യൂ.\n\nനിങ്ങളുടെ അഭ്യർത്ഥന സമീപത്തെ കടകളിലേക്ക് മാത്രം അയക്കും. 🔒";

    /**
     * Fish alerts — Malayalam.
     */
    private const MSG_FISH_ALERTS_ML = "📍 പച്ച മീൻ അലർട്ടുകൾക്ക് ലൊക്കേഷൻ ഷെയർ ചെയ്യൂ.\n\nസമീപത്തെ മാർക്കറ്റുകളിൽ പുതിയ മീൻ എത്തുമ്പോൾ അറിയിക്കാം. 🐟";

    /**
     * Jobs — Malayalam.
     */
    private const MSG_JOBS_ML = "📍 സമീപത്തെ ജോലികൾ കണ്ടെത്താൻ ലൊക്കേഷൻ ഷെയർ ചെയ്യൂ.\n\nനിങ്ങളുടെ ഏരിയയിലെ ടാസ്കുകൾ മാച്ച് ചെയ്യാം. 👷";

    /**
     * Update location — Malayalam.
     */
    private const MSG_UPDATE_ML = "📍 ലൊക്കേഷൻ അപ്ഡേറ്റ് ചെയ്യണോ?\n\nതാഴെയുള്ള ബട്ടൺ ടാപ്പ് ചെയ്ത് നിലവിലെ ലൊക്കേഷൻ ഷെയർ ചെയ്യൂ. 🔄";

    public function __construct(string $to)
    {
        $this->to = $to;
    }

    /**
     * Create a new builder instance.
     */
    public static function create(string $to): self
    {
        return new self($to);
    }

    /**
     * Set the message body directly.
     */
    public function body(string $body): self
    {
        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            throw new \InvalidArgumentException(
                "Body must not exceed " . self::MAX_BODY_LENGTH . " characters"
            );
        }

        $this->body = $body;
        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Context-Specific Request Helpers
    |--------------------------------------------------------------------------
    |
    | Pre-built, friendly messages for different NearBuy flows.
    | Each includes privacy assurance to build trust.
    |
    */

    /**
     * Generic location request with privacy assurance.
     *
     * @param string $lang 'en' or 'ml'
     */
    public function forGeneric(string $lang = 'en'): self
    {
        $this->body = ($lang === 'ml') ? self::MSG_GENERIC_ML : self::MSG_GENERIC_EN;
        return $this;
    }

    /**
     * User registration flow (FR-REG-04).
     *
     * @param string $lang 'en' or 'ml'
     */
    public function forRegistration(string $lang = 'en'): self
    {
        $this->body = ($lang === 'ml') ? self::MSG_REGISTRATION_ML : self::MSG_REGISTRATION_EN;
        return $this;
    }

    /**
     * Shop registration flow (FR-SHOP-03).
     *
     * @param string $lang 'en' or 'ml'
     */
    public function forShopRegistration(string $lang = 'en'): self
    {
        $this->body = ($lang === 'ml') ? self::MSG_SHOP_REGISTRATION_ML : self::MSG_SHOP_REGISTRATION_EN;
        return $this;
    }

    /**
     * Browsing nearby offers (FR-OFR-11).
     *
     * @param string $lang 'en' or 'ml'
     */
    public function forNearbyOffers(string $lang = 'en'): self
    {
        $this->body = ($lang === 'ml') ? self::MSG_OFFERS_ML : self::MSG_OFFERS_EN;
        return $this;
    }

    /**
     * Product search to find nearby shops (FR-PRD-05).
     *
     * @param string $lang 'en' or 'ml'
     */
    public function forProductSearch(string $lang = 'en'): self
    {
        $this->body = ($lang === 'ml') ? self::MSG_PRODUCT_SEARCH_ML : self::MSG_PRODUCT_SEARCH_EN;
        return $this;
    }

    /**
     * Fish alerts subscription (PM-012).
     *
     * @param string $lang 'en' or 'ml'
     */
    public function forFishAlerts(string $lang = 'en'): self
    {
        $this->body = ($lang === 'ml') ? self::MSG_FISH_ALERTS_ML : self::MSG_FISH_ALERTS_EN;
        return $this;
    }

    /**
     * Job worker registration or job matching (NP-001, NP-008).
     *
     * @param string $lang 'en' or 'ml'
     */
    public function forJobs(string $lang = 'en'): self
    {
        $this->body = ($lang === 'ml') ? self::MSG_JOBS_ML : self::MSG_JOBS_EN;
        return $this;
    }

    /**
     * Location update request.
     *
     * @param string $lang 'en' or 'ml'
     */
    public function forUpdate(string $lang = 'en'): self
    {
        $this->body = ($lang === 'ml') ? self::MSG_UPDATE_ML : self::MSG_UPDATE_EN;
        return $this;
    }

    /**
     * Custom message with privacy footer.
     *
     * Appends standard privacy assurance to any custom message.
     *
     * @param string $message Custom message
     * @param string $lang    'en' or 'ml'
     */
    public function custom(string $message, string $lang = 'en'): self
    {
        $privacyNote = ($lang === 'ml')
            ? "\n\nനിങ്ങളുടെ ലൊക്കേഷൻ സുരക്ഷിതമാണ്. 🔒"
            : "\n\nYour location is kept private and secure. 🔒";

        $this->body = $message . $privacyNote;
        return $this;
    }

    /**
     * Set message to reply to.
     */
    public function replyTo(string $messageId): self
    {
        $this->replyTo = $messageId;
        return $this;
    }

    /**
     * Build the message payload.
     */
    public function build(): array
    {
        if (empty($this->body)) {
            throw new \InvalidArgumentException('Message body is required. Use a helper method like forRegistration() or body().');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->to,
            'type' => 'interactive',
            'interactive' => [
                'type' => 'location_request_message',
                'body' => [
                    'text' => $this->body,
                ],
                'action' => [
                    'name' => 'send_location',
                ],
            ],
        ];

        if ($this->replyTo) {
            $payload['context'] = [
                'message_id' => $this->replyTo,
            ];
        }

        return $payload;
    }

    /**
     * Get the recipient phone number.
     */
    public function getTo(): string
    {
        return $this->to;
    }

    /**
     * Get the current body text (for inspection/testing).
     */
    public function getBody(): string
    {
        return $this->body;
    }
}