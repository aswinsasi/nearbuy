<?php

namespace App\Services\WhatsApp\Builders;

use App\DTOs\ButtonOption;
use Illuminate\Support\Facades\Log;

/**
 * Builder for WhatsApp interactive button messages.
 *
 * UX Guards:
 * - Button titles auto-truncated at 20 chars with "…" (NFR-U-02)
 * - Exceeding 3 buttons throws exception (WhatsApp hard limit)
 * - Title overflow logged as warning for developer review
 * - yesNo() / confirmCancel() / confirmEditCancel() shortcuts
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
 *
 * // Quick confirmation:
 * $message = ButtonMessageBuilder::create($phone)
 *     ->body('Send request to 12 shops nearby?')
 *     ->confirmCancel()
 *     ->build();
 */
class ButtonMessageBuilder
{
    private string $to;
    private ?string $header = null;
    private string $body = '';
    private ?string $footer = null;
    private array $buttons = [];
    private ?string $replyTo = null;

    /**
     * Maximum buttons allowed by WhatsApp.
     */
    public const MAX_BUTTONS = 3;

    /**
     * Maximum button title length (WhatsApp enforced, NFR-U-02).
     */
    public const MAX_BUTTON_TITLE_LENGTH = 20;

    /**
     * Maximum lengths for header/body/footer.
     */
    public const MAX_HEADER_LENGTH = 60;
    public const MAX_BODY_LENGTH = 1024;
    public const MAX_FOOTER_LENGTH = 60;

    /**
     * Truncation indicator.
     */
    private const TRUNCATION_SUFFIX = '…';

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
     * Add a button with automatic title validation and truncation.
     *
     * If the title exceeds 20 characters:
     * - A warning is logged (so devs can fix the label)
     * - The title is auto-truncated with "…" to prevent API errors
     *
     * @throws \InvalidArgumentException if more than 3 buttons added
     */
    public function addButton(string $id, string $title): self
    {
        $this->guardButtonLimit();

        $title = $this->sanitizeButtonTitle($title, $id);

        $this->buttons[] = ButtonOption::make($id, $title);
        return $this;
    }

    /**
     * Add a ButtonOption instance.
     *
     * @throws \InvalidArgumentException if more than 3 buttons added
     */
    public function addButtonOption(ButtonOption $button): self
    {
        $this->guardButtonLimit();

        $this->buttons[] = $button;
        return $this;
    }

    /**
     * Set buttons from array.
     *
     * Each button title is validated and auto-truncated.
     *
     * @param array<int, array{id: string, title: string}> $buttons
     * @throws \InvalidArgumentException if more than 3 buttons provided
     */
    public function buttons(array $buttons): self
    {
        if (count($buttons) > self::MAX_BUTTONS) {
            throw new \InvalidArgumentException(
                "Cannot have more than " . self::MAX_BUTTONS . " buttons. "
                . count($buttons) . " provided. WhatsApp will reject this message."
            );
        }

        $this->buttons = array_map(
            fn(array $btn) => ButtonOption::make(
                $btn['id'],
                $this->sanitizeButtonTitle($btn['title'], $btn['id'])
            ),
            $buttons
        );

        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | UX Shortcut Helpers
    |--------------------------------------------------------------------------
    |
    | Pre-built button combos for common interaction patterns.
    | These reduce boilerplate and enforce consistent labelling.
    |
    */

    /**
     * Add Yes / No buttons.
     *
     * @param string $yesLabel Custom label for Yes (default: "✅ Yes")
     * @param string $noLabel  Custom label for No (default: "❌ No")
     * @param string $yesId    Button ID for Yes (default: "yes")
     * @param string $noId     Button ID for No (default: "no")
     */
    public function yesNo(
        string $yesLabel = '✅ Yes',
        string $noLabel = '❌ No',
        string $yesId = 'yes',
        string $noId = 'no'
    ): self {
        $this->buttons = [];

        return $this->addButton($yesId, $yesLabel)
                     ->addButton($noId, $noLabel);
    }

    /**
     * Add Confirm / Cancel buttons.
     *
     * @param string $confirmLabel Custom confirm label (default: "✅ Confirm")
     * @param string $cancelLabel  Custom cancel label (default: "❌ Cancel")
     */
    public function confirmCancel(
        string $confirmLabel = '✅ Confirm',
        string $cancelLabel = '❌ Cancel'
    ): self {
        $this->buttons = [];

        return $this->addButton('confirm', $confirmLabel)
                     ->addButton('cancel', $cancelLabel);
    }

    /**
     * Add Confirm / Edit / Cancel buttons.
     *
     * Common pattern for review screens before submission
     * (e.g., agreement review, product request review).
     */
    public function confirmEditCancel(
        string $confirmLabel = '✅ Confirm',
        string $editLabel = '✏️ Edit',
        string $cancelLabel = '❌ Cancel'
    ): self {
        $this->buttons = [];

        return $this->addButton('confirm', $confirmLabel)
                     ->addButton('edit', $editLabel)
                     ->addButton('cancel', $cancelLabel);
    }

    /**
     * Add Send / Edit / Cancel buttons (for product requests per SRS FR-PRD-04).
     */
    public function sendEditCancel(): self
    {
        $this->buttons = [];

        return $this->addButton('send', '📤 Send')
                     ->addButton('edit', '✏️ Edit')
                     ->addButton('cancel', '❌ Cancel');
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

    /**
     * Get current button count (for inspection/testing).
     */
    public function getButtonCount(): int
    {
        return count($this->buttons);
    }

    /*
    |--------------------------------------------------------------------------
    | Internal Guards & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Guard: throw if adding would exceed 3 buttons.
     *
     * @throws \InvalidArgumentException
     */
    private function guardButtonLimit(): void
    {
        if (count($this->buttons) >= self::MAX_BUTTONS) {
            throw new \InvalidArgumentException(
                "Cannot add more than " . self::MAX_BUTTONS . " buttons. "
                . "WhatsApp will reject messages with more than 3 reply buttons."
            );
        }
    }

    /**
     * Validate and auto-truncate a button title to 20 characters.
     *
     * Logs a warning when truncation occurs so developers can
     * fix the label at the source rather than relying on truncation.
     */
    private function sanitizeButtonTitle(string $title, string $buttonId): string
    {
        $originalLength = mb_strlen($title);

        if ($originalLength <= self::MAX_BUTTON_TITLE_LENGTH) {
            return $title;
        }

        // Log warning — developer should fix the label
        Log::warning('WhatsApp ButtonMessage: title auto-truncated (NFR-U-02)', [
            'to' => $this->to,
            'button_id' => $buttonId,
            'original_title' => $title,
            'original_length' => $originalLength,
            'max_allowed' => self::MAX_BUTTON_TITLE_LENGTH,
        ]);

        // Truncate with "…" to stay within 20 chars
        return mb_substr($title, 0, self::MAX_BUTTON_TITLE_LENGTH - mb_strlen(self::TRUNCATION_SUFFIX))
             . self::TRUNCATION_SUFFIX;
    }
}