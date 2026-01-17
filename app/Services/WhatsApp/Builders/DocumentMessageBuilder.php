<?php

namespace App\Services\WhatsApp\Builders;

/**
 * Builder for WhatsApp document messages (PDFs, etc.).
 *
 * Can send documents via URL or media ID.
 *
 * @example
 * // Send document by URL
 * $message = DocumentMessageBuilder::create('919876543210')
 *     ->url('https://example.com/agreement.pdf')
 *     ->filename('Agreement_NB-AG-2024-0001.pdf')
 *     ->caption('📄 Your agreement document is ready.')
 *     ->build();
 *
 * // Send document by media ID
 * $message = DocumentMessageBuilder::create('919876543210')
 *     ->mediaId('1234567890')
 *     ->filename('Invoice.pdf')
 *     ->build();
 */
class DocumentMessageBuilder
{
    private string $to;
    private ?string $url = null;
    private ?string $mediaId = null;
    private ?string $filename = null;
    private ?string $caption = null;
    private ?string $replyTo = null;

    /**
     * Maximum caption length.
     */
    public const MAX_CAPTION_LENGTH = 1024;

    /**
     * Maximum filename length.
     */
    public const MAX_FILENAME_LENGTH = 240;

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
     * Set document URL.
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
     * Set document media ID.
     * Use this for documents already uploaded to WhatsApp.
     */
    public function mediaId(string $mediaId): self
    {
        $this->mediaId = $mediaId;
        $this->url = null; // Clear URL if media ID is set
        return $this;
    }

    /**
     * Set the filename displayed to the user.
     */
    public function filename(string $filename): self
    {
        if (mb_strlen($filename) > self::MAX_FILENAME_LENGTH) {
            throw new \InvalidArgumentException(
                "Filename must not exceed " . self::MAX_FILENAME_LENGTH . " characters"
            );
        }

        $this->filename = $filename;
        return $this;
    }

    /**
     * Set the document caption.
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

        $document = [];

        if ($this->url) {
            $document['link'] = $this->url;
        } else {
            $document['id'] = $this->mediaId;
        }

        if ($this->filename) {
            $document['filename'] = $this->filename;
        }

        if ($this->caption) {
            $document['caption'] = $this->caption;
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->to,
            'type' => 'document',
            'document' => $document,
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