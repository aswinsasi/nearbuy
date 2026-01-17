<?php

namespace App\Services\WhatsApp\Builders;

/**
 * Builder for WhatsApp location request messages.
 *
 * Sends a message asking the user to share their location.
 * Uses the interactive location_request_message type.
 *
 * @example
 * $message = LocationRequestBuilder::create('919876543210')
 *     ->body('📍 Please share your location so we can show nearby offers.')
 *     ->build();
 */
class LocationRequestBuilder
{
    private string $to;
    private string $body;
    private ?string $replyTo = null;

    /**
     * Maximum body length.
     */
    public const MAX_BODY_LENGTH = 1024;

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
     * Set the message body.
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
            throw new \InvalidArgumentException('Message body is required');
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
}