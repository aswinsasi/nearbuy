<?php

namespace App\DTOs;

use Carbon\Carbon;

/**
 * Data Transfer Object for incoming WhatsApp messages.
 *
 * ENHANCED: Added isInteractive() and other helper methods
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

    /**
     * Extract text content.
     */
    private static function extractText(array $message, string $type): ?string
    {
        if ($type === 'text') {
            return $message['text']['body'] ?? null;
        }
        return null;
    }

    /**
     * Extract interactive response.
     */
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

    /**
     * Extract location data.
     */
    private static function extractLocation(array $message, string $type): ?array
    {
        if ($type !== 'location') {
            return null;
        }

        return [
            'latitude' => $message['location']['latitude'] ?? null,
            'longitude' => $message['location']['longitude'] ?? null,
            'name' => $message['location']['name'] ?? null,
            'address' => $message['location']['address'] ?? null,
        ];
    }

    /**
     * Extract image data.
     */
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

    /**
     * Extract document data.
     */
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

    /**
     * Extract button response (quick reply template buttons).
     */
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
    | Helper Methods
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
     * 
     * NEW METHOD - Added for enhanced flow handling
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
     * 
     * NEW METHOD
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
     * 
     * NEW METHOD
     */
    public function isMedia(): bool
    {
        return $this->isImage() || $this->isDocument();
    }

    /**
     * Check if message is a template button response.
     * 
     * NEW METHOD
     */
    public function isTemplateButton(): bool
    {
        return $this->type === 'button';
    }

    /**
     * Get the text content regardless of message type.
     */
    public function getTextContent(): ?string
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
     * Get the interactive selection ID.
     */
    public function getSelectionId(): ?string
    {
        if ($this->isButtonReply() || $this->isListReply()) {
            return $this->interactive['id'] ?? null;
        }

        // Also check for template button payload
        if ($this->isTemplateButton()) {
            return $this->button['payload'] ?? null;
        }

        return null;
    }

    /**
     * Get the interactive type (button_reply, list_reply, flow_reply).
     * 
     * NEW METHOD
     */
    public function getInteractiveType(): ?string
    {
        if (!$this->isInteractive()) {
            return null;
        }

        return $this->interactive['type'] ?? null;
    }

    /**
     * Get location coordinates.
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
     * Get full location data including name and address.
     * 
     * NEW METHOD
     */
    public function getLocationData(): ?array
    {
        if (!$this->isLocation()) {
            return null;
        }

        return $this->location;
    }

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
     * 
     * NEW METHOD
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
     * Get image caption or document filename.
     * 
     * NEW METHOD
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
     * 
     * NEW METHOD
     */
    public function getDocumentFilename(): ?string
    {
        if (!$this->isDocument()) {
            return null;
        }

        return $this->document['filename'] ?? null;
    }

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
     * 
     * NEW METHOD
     */
    public function hasReferral(): bool
    {
        return !empty($this->referral);
    }

    /**
     * Get referral source.
     * 
     * NEW METHOD
     */
    public function getReferralSource(): ?string
    {
        return $this->referral['source'] ?? null;
    }

    /**
     * Check if the message is empty or contains only whitespace.
     * 
     * NEW METHOD
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
     * 
     * NEW METHOD
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
            default => 'unknown message type',
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
     * 
     * NEW METHOD
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }
}