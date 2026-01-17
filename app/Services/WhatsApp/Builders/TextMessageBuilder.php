<?php

namespace App\Services\WhatsApp\Builders;

/**
 * Builder for WhatsApp text messages.
 *
 * @example
 * $message = TextMessageBuilder::create('919876543210')
 *     ->body('Hello! Welcome to NearBuy.')
 *     ->previewUrl(true)
 *     ->build();
 */
class TextMessageBuilder
{
    private string $to;
    private string $body;
    private bool $previewUrl = false;
    private ?string $replyTo = null;

    /**
     * Maximum body length allowed by WhatsApp.
     */
    public const MAX_BODY_LENGTH = 4096;

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
                "Message body must not exceed " . self::MAX_BODY_LENGTH . " characters"
            );
        }

        $this->body = $body;
        return $this;
    }

    /**
     * Enable URL preview in message.
     */
    public function previewUrl(bool $preview = true): self
    {
        $this->previewUrl = $preview;
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
            'type' => 'text',
            'text' => [
                'body' => $this->body,
                'preview_url' => $this->previewUrl,
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