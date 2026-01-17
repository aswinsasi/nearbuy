<?php

namespace App\Services\WhatsApp\Builders;

/**
 * Builder for WhatsApp location messages.
 *
 * Sends a location pin to the user with optional name and address.
 *
 * @example
 * $message = LocationMessageBuilder::create('919876543210')
 *     ->coordinates(9.5916, 76.5222)
 *     ->name('Suresh Supermarket')
 *     ->address('Main Road, Kottayam, Kerala')
 *     ->build();
 */
class LocationMessageBuilder
{
    private string $to;
    private float $latitude;
    private float $longitude;
    private ?string $name = null;
    private ?string $address = null;
    private ?string $replyTo = null;

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
        // Validate latitude range
        if ($latitude < -90 || $latitude > 90) {
            throw new \InvalidArgumentException('Latitude must be between -90 and 90');
        }

        // Validate longitude range
        if ($longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException('Longitude must be between -180 and 180');
        }

        $this->latitude = $latitude;
        $this->longitude = $longitude;
        return $this;
    }

    /**
     * Set latitude.
     */
    public function latitude(float $latitude): self
    {
        if ($latitude < -90 || $latitude > 90) {
            throw new \InvalidArgumentException('Latitude must be between -90 and 90');
        }

        $this->latitude = $latitude;
        return $this;
    }

    /**
     * Set longitude.
     */
    public function longitude(float $longitude): self
    {
        if ($longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException('Longitude must be between -180 and 180');
        }

        $this->longitude = $longitude;
        return $this;
    }

    /**
     * Set the location name.
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Set the location address.
     */
    public function address(string $address): self
    {
        $this->address = $address;
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
        if (!isset($this->latitude) || !isset($this->longitude)) {
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
}