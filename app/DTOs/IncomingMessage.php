<?php

namespace App\DTOs;

use Carbon\Carbon;

/**
 * Data Transfer Object for incoming WhatsApp messages.
 *
 * Clean API for accessing message data regardless of type.
 * Handles: text, interactive (button/list), location, image, document, button (template).
 *
 * @example
 * $message = IncomingMessage::fromWebhook($webhookData, $contact);
 *
 * if ($message->isText()) {
 *     $text = $message->getText();
 * } elseif ($message->isButtonReply()) {
 *     $buttonId = $message->getButtonId();
 * } elseif ($message->isLocation()) {
 *     $lat = $message->getLatitude();
 *     $lng = $message->getLongitude();
 * }
 */
readonly class IncomingMessage
{
    public function __construct(
        public string $messageId,
        public string $from,
        public string $type,
        public Carbon $timestamp,
        public ?string $profileName = null,
        public ?string $text = null,
        public ?array $interactive = null,
        public ?array $location = null,
        public ?array $image = null,
        public ?array $document = null,
        public ?array $button = null,
        public ?array $context = null,
        public ?array $referral = null,
    ) {}

    /**
     * Create from webhook payload.
     */
    public static function fromWebhook(array $message, array $contact = []): self
    {
        $type = $message['type'] ?? 'unknown';

        return new self(
            messageId: $message['id'] ?? '',
            from: $message['from'] ?? '',
            type: $type,
            timestamp: Carbon::createFromTimestamp($message['timestamp'] ?? time()),
            profileName: $contact['profile']['name'] ?? null,
            text: self::extractText($message, $type),
            interactive: self::extractInteractive($message, $type),
            location: self::extractLocation($message, $type),
            image: self::extractImage($message, $type),
            document: self::extractDocument($message, $type),
            button: self::extractButton($message, $type),
            context: $message['context'] ?? null,
            referral: $message['referral'] ?? null,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Extraction Helpers (Private)
    |--------------------------------------------------------------------------
    */

    private static function extractText(array $message, string $type): ?string
    {
        if ($type === 'text') {
            return $message['text']['body'] ?? null;
        }
        return null;
    }

    private static function extractInteractive(array $message, string $type): ?array
    {
        if ($type !== 'interactive') {
            return null;
        }

        $interactive = $message['interactive'] ?? [];
        $interactiveType = $interactive['type'] ?? 'unknown';

        return match ($interactiveType) {
            'button_reply' => [
                'type' => 'button_reply',
                'id' => $interactive['button_reply']['id'] ?? null,
                'title' => $interactive['button_reply']['title'] ?? null,
            ],
            'list_reply' => [
                'type' => 'list_reply',
                'id' => $interactive['list_reply']['id'] ?? null,
                'title' => $interactive['list_reply']['title'] ?? null,
                'description' => $interactive['list_reply']['description'] ?? null,
            ],
            'nfm_reply' => [
                'type' => 'flow_reply',
                'response_json' => $interactive['nfm_reply']['response_json'] ?? null,
                'body' => $interactive['nfm_reply']['body'] ?? null,
            ],
            default => [
                'type' => $interactiveType,
                'raw' => $interactive,
            ],
        };
    }

    private static function extractLocation(array $message, string $type): ?array
    {
        if ($type !== 'location') {
            return null;
        }

        return [
            'latitude' => (float) ($message['location']['latitude'] ?? 0),
            'longitude' => (float) ($message['location']['longitude'] ?? 0),
            'name' => $message['location']['name'] ?? null,
            'address' => $message['location']['address'] ?? null,
        ];
    }

    private static function extractImage(array $message, string $type): ?array
    {
        if ($type !== 'image') {
            return null;
        }

        return [
            'id' => $message['image']['id'] ?? null,
            'mime_type' => $message['image']['mime_type'] ?? null,
            'sha256' => $message['image']['sha256'] ?? null,
            'caption' => $message['image']['caption'] ?? null,
        ];
    }

    private static function extractDocument(array $message, string $type): ?array
    {
        if ($type !== 'document') {
            return null;
        }

        return [
            'id' => $message['document']['id'] ?? null,
            'mime_type' => $message['document']['mime_type'] ?? null,
            'sha256' => $message['document']['sha256'] ?? null,
            'filename' => $message['document']['filename'] ?? null,
            'caption' => $message['document']['caption'] ?? null,
        ];
    }

    private static function extractButton(array $message, string $type): ?array
    {
        if ($type !== 'button') {
            return null;
        }

        return [
            'text' => $message['button']['text'] ?? null,
            'payload' => $message['button']['payload'] ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Type Checkers
    |--------------------------------------------------------------------------
    */

    /**
     * Check if message is a text message.
     */
    public function isText(): bool
    {
        return $this->type === 'text';
    }

    /**
     * Check if message is any interactive type (button or list reply).
     */
    public function isInteractive(): bool
    {
        return $this->type === 'interactive';
    }

    /**
     * Check if message is a button reply.
     */
    public function isButtonReply(): bool
    {
        return $this->type === 'interactive'
            && ($this->interactive['type'] ?? '') === 'button_reply';
    }

    /**
     * Check if message is a list reply.
     */
    public function isListReply(): bool
    {
        return $this->type === 'interactive'
            && ($this->interactive['type'] ?? '') === 'list_reply';
    }

    /**
     * Check if message is a flow reply (WhatsApp Flows).
     */
    public function isFlowReply(): bool
    {
        return $this->type === 'interactive'
            && ($this->interactive['type'] ?? '') === 'flow_reply';
    }

    /**
     * Check if message is a location.
     */
    public function isLocation(): bool
    {
        return $this->type === 'location';
    }

    /**
     * Check if message is an image.
     */
    public function isImage(): bool
    {
        return $this->type === 'image';
    }

    /**
     * Check if message is a document.
     */
    public function isDocument(): bool
    {
        return $this->type === 'document';
    }

    /**
     * Check if message is any media type (image or document).
     */
    public function isMedia(): bool
    {
        return $this->isImage() || $this->isDocument();
    }

    /**
     * Check if message is a template button response.
     */
    public function isTemplateButton(): bool
    {
        return $this->type === 'button';
    }

    /**
     * Check if message type is unknown/unsupported.
     */
    public function isUnknown(): bool
    {
        return !in_array($this->type, [
            'text', 'interactive', 'location', 'image', 'document', 'button'
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Core Getters
    |--------------------------------------------------------------------------
    */

    /**
     * Get the message ID.
     */
    public function getMessageId(): string
    {
        return $this->messageId;
    }

    /**
     * Get the sender's phone number.
     */
    public function getPhoneNumber(): string
    {
        return $this->from;
    }

    /**
     * Get the sender's profile name (if available).
     */
    public function getProfileName(): ?string
    {
        return $this->profileName;
    }

    /**
     * Get the message timestamp.
     */
    public function getTimestamp(): Carbon
    {
        return $this->timestamp;
    }

    /*
    |--------------------------------------------------------------------------
    | Text Getters
    |--------------------------------------------------------------------------
    */

    /**
     * Get text content (for text messages).
     */
    public function getText(): ?string
    {
        return $this->text;
    }

    /**
     * Get text content (alias for getText()).
     * Used by flow handlers for semantic clarity.
     */
    public function getTextContent(): ?string
    {
        return $this->text;
    }

    /**
     * Get text content, trimmed and lowercased.
     */
    public function getTextNormalized(): ?string
    {
        if (!$this->isText() || !$this->text) {
            return null;
        }

        return mb_strtolower(trim($this->text));
    }

    /**
     * Get the text content regardless of message type.
     * Returns button title, list title, or text.
     */
    public function getAnyText(): ?string
    {
        if ($this->isText()) {
            return $this->text;
        }

        if ($this->isButtonReply()) {
            return $this->interactive['title'] ?? null;
        }

        if ($this->isListReply()) {
            return $this->interactive['title'] ?? null;
        }

        if ($this->isTemplateButton()) {
            return $this->button['text'] ?? null;
        }

        return null;
    }

    /**
     * Check if text equals (case-insensitive).
     */
    public function textEquals(string $expected): bool
    {
        return $this->getTextNormalized() === mb_strtolower(trim($expected));
    }

    /**
     * Check if text contains (case-insensitive).
     */
    public function textContains(string $needle): bool
    {
        $text = $this->getTextNormalized();
        return $text && str_contains($text, mb_strtolower($needle));
    }

    /**
     * Check if text matches any of the given values.
     */
    public function textMatches(array $values): bool
    {
        $text = $this->getTextNormalized();
        if (!$text) {
            return false;
        }

        foreach ($values as $value) {
            if ($text === mb_strtolower(trim($value))) {
                return true;
            }
        }

        return false;
    }

    /*
    |--------------------------------------------------------------------------
    | Interactive Getters
    |--------------------------------------------------------------------------
    */

    /**
     * Get button ID (for button replies).
     */
    public function getButtonId(): ?string
    {
        if (!$this->isButtonReply()) {
            return null;
        }

        return $this->interactive['id'] ?? null;
    }

    /**
     * Get button title (for button replies).
     */
    public function getButtonTitle(): ?string
    {
        if (!$this->isButtonReply()) {
            return null;
        }

        return $this->interactive['title'] ?? null;
    }

    /**
     * Get list selection ID (for list replies).
     */
    public function getListId(): ?string
    {
        if (!$this->isListReply()) {
            return null;
        }

        return $this->interactive['id'] ?? null;
    }

    /**
     * Get list selection title (for list replies).
     */
    public function getListTitle(): ?string
    {
        if (!$this->isListReply()) {
            return null;
        }

        return $this->interactive['title'] ?? null;
    }

    /**
     * Get list selection description (for list replies).
     */
    public function getListDescription(): ?string
    {
        if (!$this->isListReply()) {
            return null;
        }

        return $this->interactive['description'] ?? null;
    }

    /**
     * Get the selection ID (works for both button and list).
     */
    public function getSelectionId(): ?string
    {
        if ($this->isButtonReply()) {
            return $this->getButtonId();
        }

        if ($this->isListReply()) {
            return $this->getListId();
        }

        if ($this->isTemplateButton()) {
            return $this->button['payload'] ?? null;
        }

        return null;
    }

    /**
     * Check if selection ID equals expected value.
     */
    public function selectionEquals(string $expected): bool
    {
        return $this->getSelectionId() === $expected;
    }

    /**
     * Check if selection ID starts with prefix.
     */
    public function selectionStartsWith(string $prefix): bool
    {
        $id = $this->getSelectionId();
        return $id && str_starts_with($id, $prefix);
    }

    /**
     * Get interactive type (button_reply, list_reply, flow_reply).
     */
    public function getInteractiveType(): ?string
    {
        if (!$this->isInteractive()) {
            return null;
        }

        return $this->interactive['type'] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | Location Getters
    |--------------------------------------------------------------------------
    */

    /**
     * Get latitude (for location messages).
     */
    public function getLatitude(): ?float
    {
        if (!$this->isLocation()) {
            return null;
        }

        return $this->location['latitude'] ?? null;
    }

    /**
     * Get longitude (for location messages).
     */
    public function getLongitude(): ?float
    {
        if (!$this->isLocation()) {
            return null;
        }

        return $this->location['longitude'] ?? null;
    }

    /**
     * Get coordinates as array [lat, lng].
     */
    public function getCoordinates(): ?array
    {
        if (!$this->isLocation()) {
            return null;
        }

        return [
            'latitude' => $this->location['latitude'],
            'longitude' => $this->location['longitude'],
        ];
    }

    /**
     * Get location name (if provided by user).
     */
    public function getLocationName(): ?string
    {
        if (!$this->isLocation()) {
            return null;
        }

        return $this->location['name'] ?? null;
    }

    /**
     * Get location address (if provided by user).
     */
    public function getLocationAddress(): ?string
    {
        if (!$this->isLocation()) {
            return null;
        }

        return $this->location['address'] ?? null;
    }

    /**
     * Get full location data.
     */
    public function getLocationData(): ?array
    {
        return $this->location;
    }

    /*
    |--------------------------------------------------------------------------
    | Media Getters
    |--------------------------------------------------------------------------
    */

    /**
     * Get media ID (for image or document).
     */
    public function getMediaId(): ?string
    {
        if ($this->isImage()) {
            return $this->image['id'] ?? null;
        }

        if ($this->isDocument()) {
            return $this->document['id'] ?? null;
        }

        return null;
    }

    /**
     * Get media MIME type.
     */
    public function getMediaMimeType(): ?string
    {
        if ($this->isImage()) {
            return $this->image['mime_type'] ?? null;
        }

        if ($this->isDocument()) {
            return $this->document['mime_type'] ?? null;
        }

        return null;
    }

    /**
     * Get media SHA256 hash.
     */
    public function getMediaSha256(): ?string
    {
        if ($this->isImage()) {
            return $this->image['sha256'] ?? null;
        }

        if ($this->isDocument()) {
            return $this->document['sha256'] ?? null;
        }

        return null;
    }

    /**
     * Get media caption (for images) or document caption.
     */
    public function getMediaCaption(): ?string
    {
        if ($this->isImage()) {
            return $this->image['caption'] ?? null;
        }

        if ($this->isDocument()) {
            return $this->document['caption'] ?? null;
        }

        return null;
    }

    /**
     * Get document filename.
     */
    public function getDocumentFilename(): ?string
    {
        if (!$this->isDocument()) {
            return null;
        }

        return $this->document['filename'] ?? null;
    }

    /**
     * Check if media is an image type.
     */
    public function isImageType(): bool
    {
        $mime = $this->getMediaMimeType();
        return $mime && str_starts_with($mime, 'image/');
    }

    /**
     * Check if media is a PDF.
     */
    public function isPdf(): bool
    {
        return $this->getMediaMimeType() === 'application/pdf';
    }

    /*
    |--------------------------------------------------------------------------
    | Context & Reply Getters
    |--------------------------------------------------------------------------
    */

    /**
     * Check if this is a reply to a previous message.
     */
    public function isReply(): bool
    {
        return !empty($this->context['message_id']);
    }

    /**
     * Get the ID of the message being replied to.
     */
    public function getReplyToMessageId(): ?string
    {
        return $this->context['message_id'] ?? null;
    }

    /**
     * Check if message came from a referral (ad, etc).
     */
    public function hasReferral(): bool
    {
        return !empty($this->referral);
    }

    /**
     * Get referral source.
     */
    public function getReferralSource(): ?string
    {
        return $this->referral['source'] ?? null;
    }

    /*
    |--------------------------------------------------------------------------
    | Utility Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Check if the message is empty or contains only whitespace.
     */
    public function isEmpty(): bool
    {
        if ($this->isText()) {
            return empty(trim($this->text ?? ''));
        }

        return false;
    }

    /**
     * Get a human-readable description of the message type.
     */
    public function getTypeDescription(): string
    {
        return match (true) {
            $this->isText() => 'text message',
            $this->isButtonReply() => 'button selection',
            $this->isListReply() => 'list selection',
            $this->isFlowReply() => 'flow response',
            $this->isLocation() => 'location',
            $this->isImage() => 'image',
            $this->isDocument() => 'document',
            $this->isTemplateButton() => 'quick reply',
            default => 'unknown (' . $this->type . ')',
        };
    }

    /**
     * Get a summary for logging.
     */
    public function getSummary(): string
    {
        return match (true) {
            $this->isText() => 'Text: ' . mb_substr($this->text ?? '', 0, 30) . (mb_strlen($this->text ?? '') > 30 ? '...' : ''),
            $this->isButtonReply() => 'Button: ' . ($this->getButtonId() ?? 'unknown'),
            $this->isListReply() => 'List: ' . ($this->getListId() ?? 'unknown'),
            $this->isLocation() => 'Location: ' . round($this->getLatitude() ?? 0, 4) . ', ' . round($this->getLongitude() ?? 0, 4),
            $this->isImage() => 'Image: ' . ($this->image['id'] ?? 'unknown'),
            $this->isDocument() => 'Document: ' . ($this->getDocumentFilename() ?? 'unknown'),
            default => 'Type: ' . $this->type,
        };
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'message_id' => $this->messageId,
            'from' => $this->from,
            'type' => $this->type,
            'timestamp' => $this->timestamp->toIso8601String(),
            'profile_name' => $this->profileName,
            'text' => $this->text,
            'interactive' => $this->interactive,
            'location' => $this->location,
            'image' => $this->image,
            'document' => $this->document,
            'button' => $this->button,
            'context' => $this->context,
            'referral' => $this->referral,
        ];
    }

    /**
     * Convert to JSON string.
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}