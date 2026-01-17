<?php

namespace App\Services\WhatsApp\Builders;

use App\DTOs\ListItem;
use App\DTOs\ListSection;

/**
 * Builder for WhatsApp interactive list messages.
 *
 * WhatsApp allows maximum 10 sections with 10 items each.
 *
 * @example
 * $message = ListMessageBuilder::create('919876543210')
 *     ->header('Shop Categories')
 *     ->body('Select a category to browse offers:')
 *     ->footer('Scroll for more options')
 *     ->buttonText('View Categories')
 *     ->addSection('Popular', [
 *         ['id' => 'grocery', 'title' => '🛒 Grocery', 'description' => 'Daily essentials'],
 *         ['id' => 'electronics', 'title' => '📱 Electronics', 'description' => 'Phones & gadgets'],
 *     ])
 *     ->addSection('More', [
 *         ['id' => 'clothing', 'title' => '👕 Clothing', 'description' => 'Fashion & apparel'],
 *     ])
 *     ->build();
 */
class ListMessageBuilder
{
    private string $to;
    private ?string $header = null;
    private string $body;
    private ?string $footer = null;
    private string $buttonText;
    private array $sections = [];
    private ?string $replyTo = null;

    /**
     * Maximum sections allowed by WhatsApp.
     */
    public const MAX_SECTIONS = 10;

    /**
     * Maximum total items across all sections.
     */
    public const MAX_TOTAL_ITEMS = 10;

    /**
     * Maximum lengths.
     */
    public const MAX_HEADER_LENGTH = 60;
    public const MAX_BODY_LENGTH = 1024;
    public const MAX_FOOTER_LENGTH = 60;
    public const MAX_BUTTON_TEXT_LENGTH = 20;

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
     * Set the button text that opens the list.
     */
    public function buttonText(string $text): self
    {
        if (mb_strlen($text) > self::MAX_BUTTON_TEXT_LENGTH) {
            throw new \InvalidArgumentException(
                "Button text must not exceed " . self::MAX_BUTTON_TEXT_LENGTH . " characters"
            );
        }

        $this->buttonText = $text;
        return $this;
    }

    /**
     * Add a section with items.
     *
     * @param string $title Section title
     * @param array<int, array{id: string, title: string, description?: string}> $items
     */
    public function addSection(string $title, array $items): self
    {
        if (count($this->sections) >= self::MAX_SECTIONS) {
            throw new \InvalidArgumentException(
                "Cannot add more than " . self::MAX_SECTIONS . " sections"
            );
        }

        $listItems = array_map(
            fn(array $item) => ListItem::make(
                $item['id'],
                ListItem::truncateTitle($item['title']),
                isset($item['description']) ? ListItem::truncateDescription($item['description']) : null
            ),
            $items
        );

        $this->sections[] = ListSection::make($title, $listItems);
        return $this;
    }

    /**
     * Add a ListSection instance.
     */
    public function addListSection(ListSection $section): self
    {
        if (count($this->sections) >= self::MAX_SECTIONS) {
            throw new \InvalidArgumentException(
                "Cannot add more than " . self::MAX_SECTIONS . " sections"
            );
        }

        $this->sections[] = $section;
        return $this;
    }

    /**
     * Set sections from array.
     *
     * @param array<int, array{title: string, rows: array}> $sections
     */
    public function sections(array $sections): self
    {
        $this->sections = ListSection::fromArrayMultiple($sections);
        return $this;
    }

    /**
     * Add items without sections (single implicit section).
     *
     * @param array<int, array{id: string, title: string, description?: string}> $items
     */
    public function items(array $items): self
    {
        return $this->addSection('', $items);
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

        if (empty($this->buttonText)) {
            throw new \InvalidArgumentException('Button text is required');
        }

        if (empty($this->sections)) {
            throw new \InvalidArgumentException('At least one section with items is required');
        }

        // Validate total items
        $totalItems = array_reduce(
            $this->sections,
            fn(int $carry, ListSection $section) => $carry + $section->count(),
            0
        );

        if ($totalItems > self::MAX_TOTAL_ITEMS) {
            throw new \InvalidArgumentException(
                "Total items across all sections must not exceed " . self::MAX_TOTAL_ITEMS
            );
        }

        $interactive = [
            'type' => 'list',
            'body' => [
                'text' => $this->body,
            ],
            'action' => [
                'button' => $this->buttonText,
                'sections' => array_map(fn(ListSection $section) => $section->toApi(), $this->sections),
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