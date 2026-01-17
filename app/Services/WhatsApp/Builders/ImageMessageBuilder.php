<?php

namespace App\Services\WhatsApp\Builders;

/**
 * Builder for WhatsApp image messages.
 *
 * Can send images via URL or media ID.
 *
 * @example
 * // Send image by URL
 * $message = ImageMessageBuilder::create('919876543210')
 *     ->url('https://example.com/offer.jpg')
 *     ->caption('🎉 Special offer! 20% off on all items.')
 *     ->build();
 *
 * // Send image by media ID (previously uploaded)
 * $message = ImageMessageBuilder::create('919876543210')
 *     ->mediaId('1234567890')
 *     ->caption('Check out this product!')
 *     ->build();
 */
class ImageMessageBuilder
{
    private string $to;
    private ?string $url = null;
    private ?string $mediaId = null;
    private ?string $caption = null;
    private ?string $replyTo = null;

    /**
     * Maximum caption length.
     */
    public const MAX_CAPTION_LENGTH = 1024;

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
     * Set image URL.
     * The URL must be publicly accessible.
     */
    public function url(string $url): self
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid URL provided');
        }

        $this->url = $url;
        $this->mediaId = null; // Clear media ID if URL is set
        return $this;
    }

    /**
     * Set image media ID.
     * Use this for images already uploaded to WhatsApp.
     */
    public function mediaId(string $mediaId): self
    {
        $this->mediaId = $mediaId;
        $this->url = null; // Clear URL if media ID is set
        return $this;
    }

    /**
     * Set the image caption.
     */
    public function caption(string $caption): self
    {
        if (mb_strlen($caption) > self::MAX_CAPTION_LENGTH) {
            throw new \InvalidArgumentException(
                "Caption must not exceed " . self::MAX_CAPTION_LENGTH . " characters"
            );
        }

        $this->caption = $caption;
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
        if (empty($this->url) && empty($this->mediaId)) {
            throw new \InvalidArgumentException('Either URL or media ID is required');
        }

        $image = [];

        if ($this->url) {
            $image['link'] = $this->url;
        } else {
            $image['id'] = $this->mediaId;
        }

        if ($this->caption) {
            $image['caption'] = $this->caption;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->to,
            'type' => 'image',
            'image' => $image,
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