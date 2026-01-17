<?php

namespace App\Services\WhatsApp\Builders;

use App\DTOs\ButtonOption;

/**
 * Builder for WhatsApp interactive button messages.
 *
 * WhatsApp allows maximum 3 reply buttons per message.
 *
 * @example
 * $message = ButtonMessageBuilder::create('919876543210')
 *     ->header('Welcome!')
 *     ->body('Please select an option:')
 *     ->footer('Powered by NearBuy')
 *     ->addButton('browse', '🛍️ Browse Offers')
 *     ->addButton('search', '🔍 Search Product')
 *     ->addButton('agree', '📝 Agreement')
 *     ->build();
 */
class ButtonMessageBuilder
{
    private string $to;
    private ?string $header = null;
    private string $body;
    private ?string $footer = null;
    private array $buttons = [];
    private ?string $replyTo = null;

    /**
     * Maximum buttons allowed by WhatsApp.
     */
    public const MAX_BUTTONS = 3;

    /**
     * Maximum lengths.
     */
    public const MAX_HEADER_LENGTH = 60;
    public const MAX_BODY_LENGTH = 1024;
    public const MAX_FOOTER_LENGTH = 60;

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
     * Set the header text.
     */
    public function header(string $header): self
    {
        if (mb_strlen($header) > self::MAX_HEADER_LENGTH) {
            throw new \InvalidArgumentException(
                "Header must not exceed " . self::MAX_HEADER_LENGTH . " characters"
            );
        }

        $this->header = $header;
        return $this;
    }

    /**
     * Set the body text.
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
     * Set the footer text.
     */
    public function footer(string $footer): self
    {
        if (mb_strlen($footer) > self::MAX_FOOTER_LENGTH) {
            throw new \InvalidArgumentException(
                "Footer must not exceed " . self::MAX_FOOTER_LENGTH . " characters"
            );
        }

        $this->footer = $footer;
        return $this;
    }

    /**
     * Add a button.
     */
    public function addButton(string $id, string $title): self
    {
        if (count($this->buttons) >= self::MAX_BUTTONS) {
            throw new \InvalidArgumentException(
                "Cannot add more than " . self::MAX_BUTTONS . " buttons"
            );
        }

        $this->buttons[] = ButtonOption::make($id, ButtonOption::truncateTitle($title));
        return $this;
    }

    /**
     * Add a ButtonOption instance.
     */
    public function addButtonOption(ButtonOption $button): self
    {
        if (count($this->buttons) >= self::MAX_BUTTONS) {
            throw new \InvalidArgumentException(
                "Cannot add more than " . self::MAX_BUTTONS . " buttons"
            );
        }

        $this->buttons[] = $button;
        return $this;
    }

    /**
     * Set buttons from array.
     *
     * @param array<int, array{id: string, title: string}> $buttons
     */
    public function buttons(array $buttons): self
    {
        if (count($buttons) > self::MAX_BUTTONS) {
            throw new \InvalidArgumentException(
                "Cannot have more than " . self::MAX_BUTTONS . " buttons"
            );
        }

        $this->buttons = array_map(
            fn(array $btn) => ButtonOption::make(
                $btn['id'],
                ButtonOption::truncateTitle($btn['title'])
            ),
            $buttons
        );

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

        if (empty($this->buttons)) {
            throw new \InvalidArgumentException('At least one button is required');
        }

        $interactive = [
            'type' => 'button',
            'body' => [
                'text' => $this->body,
            ],
            'action' => [
                'buttons' => array_map(fn(ButtonOption $btn) => $btn->toApi(), $this->buttons),
            ],
        ];

        if ($this->header) {
            $interactive['header'] = [
                'type' => 'text',
                'text' => $this->header,
            ];
        }

        if ($this->footer) {
            $interactive['footer'] = [
                'text' => $this->footer,
            ];
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $this->to,
            'type' => 'interactive',
            'interactive' => $interactive,
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