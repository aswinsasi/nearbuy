<?php

namespace App\Services\WhatsApp\Builders;

/**
 * Builder for WhatsApp location messages.
 *
 * UX Principles:
 * - Clean format: just name + address, no clutter
 * - Name should be recognizable (shop name, not generic)
 * - Address should be actionable (navigable)
 *
 * Sends a location pin to the user with optional name and address.
 *
 * @example
 * // Send shop location (FR-OFR-16)
 * $message = LocationMessageBuilder::create('919876543210')
 *     ->shopLocation('Suresh Supermarket', 9.5916, 76.5222, 'Main Road, Kottayam')
 *     ->build();
 *
 * // Send fish seller location
 * $message = LocationMessageBuilder::create($phone)
 *     ->fishSellerLocation('Rajan Fisheries', 9.9312, 76.2673, 'Harbour Road, Fort Kochi')
 *     ->build();
 */
class LocationMessageBuilder
{
    private string $to;
    private ?float $latitude = null;
    private ?float $longitude = null;
    private ?string $name = null;
    private ?string $address = null;
    private ?string $replyTo = null;

    /**
     * Maximum name length for clean display.
     */
    public const MAX_NAME_LENGTH = 100;

    /**
     * Maximum address length for clean display.
     */
    public const MAX_ADDRESS_LENGTH = 200;

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
     * Set the coordinates.
     */
    public function coordinates(float $latitude, float $longitude): self
    {
        $this->validateLatitude($latitude);
        $this->validateLongitude($longitude);

        $this->latitude = $latitude;
        $this->longitude = $longitude;
        return $this;
    }

    /**
     * Set latitude.
     */
    public function latitude(float $latitude): self
    {
        $this->validateLatitude($latitude);
        $this->latitude = $latitude;
        return $this;
    }

    /**
     * Set longitude.
     */
    public function longitude(float $longitude): self
    {
        $this->validateLongitude($longitude);
        $this->longitude = $longitude;
        return $this;
    }

    /**
     * Set the location name.
     *
     * Truncates if too long to keep the pin clean.
     */
    public function name(string $name): self
    {
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $name = mb_substr($name, 0, self::MAX_NAME_LENGTH - 1) . '…';
        }

        $this->name = $name;
        return $this;
    }

    /**
     * Set the location address.
     *
     * Truncates if too long to keep the pin clean.
     */
    public function address(string $address): self
    {
        if (mb_strlen($address) > self::MAX_ADDRESS_LENGTH) {
            $address = mb_substr($address, 0, self::MAX_ADDRESS_LENGTH - 1) . '…';
        }

        $this->address = $address;
        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Location Helpers for NearBuy Entities
    |--------------------------------------------------------------------------
    |
    | Clean, consistent location formatting for different entity types.
    | Names are business names (not generic), addresses are navigable.
    |
    */

    /**
     * Set shop location (FR-OFR-16).
     *
     * Clean format:
     * Name: "Suresh Supermarket"
     * Address: "Main Road, Kottayam, Kerala"
     *
     * @param string $shopName    Business name (not "Shop" or generic)
     * @param float $latitude     Latitude coordinate
     * @param float $longitude    Longitude coordinate
     * @param string|null $address  Street address for navigation
     */
    public function shopLocation(
        string $shopName,
        float $latitude,
        float $longitude,
        ?string $address = null
    ): self {
        $this->coordinates($latitude, $longitude);
        $this->name($shopName);

        if ($address) {
            $this->address($address);
        }

        return $this;
    }

    /**
     * Set fish seller/market location (Pacha Meen module).
     *
     * Clean format:
     * Name: "Rajan Fisheries"
     * Address: "Harbour Road, Fort Kochi"
     *
     * @param string $sellerName   Seller or market name
     * @param float $latitude
     * @param float $longitude
     * @param string|null $address
     */
    public function fishSellerLocation(
        string $sellerName,
        float $latitude,
        float $longitude,
        ?string $address = null
    ): self {
        return $this->shopLocation($sellerName, $latitude, $longitude, $address);
    }

    /**
     * Set job location (Njaanum Panikkar module NP-008).
     *
     * Clean format:
     * Name: "RTO Kakkanad"
     * Address: "Civil Station Road, Kakkanad, Ernakulam"
     *
     * @param string $locationName  Recognizable place name
     * @param float $latitude
     * @param float $longitude
     * @param string|null $address  Full navigable address
     */
    public function jobLocation(
        string $locationName,
        float $latitude,
        float $longitude,
        ?string $address = null
    ): self {
        $this->coordinates($latitude, $longitude);
        $this->name($locationName);

        if ($address) {
            $this->address($address);
        }

        return $this;
    }

    /**
     * Set user's own location (for confirmation/reference).
     *
     * Used when showing users their registered location.
     *
     * @param float $latitude
     * @param float $longitude
     * @param string|null $address  Address from registration
     * @param string $lang          'en' or 'ml'
     */
    public function myLocation(
        float $latitude,
        float $longitude,
        ?string $address = null,
        string $lang = 'en'
    ): self {
        $this->coordinates($latitude, $longitude);

        $name = ($lang === 'ml') ? 'നിങ്ങളുടെ ലൊക്കേഷൻ' : 'Your Location';
        $this->name($name);

        if ($address) {
            $this->address($address);
        }

        return $this;
    }

    /**
     * Set market/harbour location for fish alerts.
     *
     * @param string $marketName   Market or harbour name
     * @param float $latitude
     * @param float $longitude
     * @param string|null $landmark  Nearby landmark for easier finding
     */
    public function marketLocation(
        string $marketName,
        float $latitude,
        float $longitude,
        ?string $landmark = null
    ): self {
        $this->coordinates($latitude, $longitude);
        $this->name($marketName);

        if ($landmark) {
            $this->address("Near {$landmark}");
        }

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
        if ($this->latitude === null || $this->longitude === null) {
            throw new \InvalidArgumentException('Coordinates (latitude and longitude) are required');
        }

        $location = [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];

        if ($this->name) {
            $location['name'] = $this->name;
        }

        if ($this->address) {
            $location['address'] = $this->address;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->to,
            'type' => 'location',
            'location' => $location,
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
     * Get coordinates as array (for inspection/testing).
     *
     * @return array{latitude: float|null, longitude: float|null}
     */
    public function getCoordinates(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Internal Validators
    |--------------------------------------------------------------------------
    */

    /**
     * Validate latitude range.
     *
     * @throws \InvalidArgumentException
     */
    private function validateLatitude(float $latitude): void
    {
        if ($latitude < -90 || $latitude > 90) {
            throw new \InvalidArgumentException('Latitude must be between -90 and 90');
        }
    }

    /**
     * Validate longitude range.
     *
     * @throws \InvalidArgumentException
     */
    private function validateLongitude(float $longitude): void
    {
        if ($longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException('Longitude must be between -180 and 180');
        }
    }
}