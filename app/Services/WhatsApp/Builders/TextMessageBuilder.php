<?php

namespace App\Services\WhatsApp\Builders;

use Illuminate\Support\Facades\Log;

/**
 * Builder for WhatsApp text messages.
 *
 * UX Guards:
 * - Soft limit warning at 300 chars (logged for review)
 * - Hard truncate at 4096 chars with "…" indicator
 * - appendMenuHint() adds universal menu shortcut
 * - appendFooter() adds styled footer line
 *
 * @example
 * $message = TextMessageBuilder::create('919876543210')
 *     ->body('Hello! Welcome to NearBuy.')
 *     ->appendMenuHint()
 *     ->previewUrl(true)
 *     ->build();
 */
class TextMessageBuilder
{
    private string $to;
    private string $body = '';
    private bool $previewUrl = false;
    private ?string $replyTo = null;

    /**
     * Hard limit — WhatsApp enforced.
     */
    public const MAX_BODY_LENGTH = 4096;

    /**
     * Soft limit — readability target for everyday messages.
     * Messages exceeding this are logged for review but still sent.
     */
    public const SOFT_BODY_LENGTH = 300;

    /**
     * Truncation indicator appended when hard-truncating.
     */
    private const TRUNCATION_SUFFIX = '…';

    /**
     * Standard menu hint appended to messages.
     */
    private const MENU_HINT = "\n\n💡 Type *menu* for Main Menu";

    /**
     * Malayalam variant of the menu hint.
     */
    private const MENU_HINT_ML = "\n\n💡 *menu* എന്ന് ടൈപ്പ് ചെയ്യൂ";

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
     *
     * Applies soft-limit logging and hard truncation automatically.
     * - Body > 300 chars: warning logged (readability concern)
     * - Body > 4096 chars: hard truncated with "…"
     */
    public function body(string $body): self
    {
        $length = mb_strlen($body);

        // Hard truncate at WhatsApp limit
        if ($length > self::MAX_BODY_LENGTH) {
            Log::warning('WhatsApp TextMessage: body hard-truncated', [
                'to' => $this->to,
                'original_length' => $length,
                'limit' => self::MAX_BODY_LENGTH,
                'preview' => mb_substr($body, 0, 80) . '…',
            ]);

            $body = mb_substr($body, 0, self::MAX_BODY_LENGTH - mb_strlen(self::TRUNCATION_SUFFIX))
                  . self::TRUNCATION_SUFFIX;
        }
        // Soft limit — log for readability review but don't truncate
        elseif ($length > self::SOFT_BODY_LENGTH) {
            Log::info('WhatsApp TextMessage: body exceeds soft limit', [
                'to' => $this->to,
                'length' => $length,
                'soft_limit' => self::SOFT_BODY_LENGTH,
                'preview' => mb_substr($body, 0, 60) . '…',
            ]);
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
     * Append the universal menu hint to the body.
     *
     * Tells the user they can type "menu" at any time to return
     * to the main menu (NFR-U-04 compliance).
     *
     * @param string $lang Language code: 'en' or 'ml'
     */
    public function appendMenuHint(string $lang = 'en'): self
    {
        $hint = ($lang === 'ml') ? self::MENU_HINT_ML : self::MENU_HINT;

        $this->body = $this->safeTruncateAppend($this->body, $hint);
        return $this;
    }

    /**
     * Append a styled footer line to the body.
     *
     * Useful for attribution, tips, or secondary info that
     * shouldn't be in a separate message.
     *
     * @example ->appendFooter('Powered by NearBuy')
     * @example ->appendFooter('Showing 3 of 12 results')
     */
    public function appendFooter(string $text): self
    {
        $footer = "\n\n_{$text}_";

        $this->body = $this->safeTruncateAppend($this->body, $footer);
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
     * Get the current body text (for inspection/testing).
     */
    public function getBody(): string
    {
        return $this->body;
    }

    /**
     * Get the recipient phone number.
     */
    public function getTo(): string
    {
        return $this->to;
    }

    /*
    |--------------------------------------------------------------------------
    | Internal Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Safely append text to body without exceeding the hard limit.
     *
     * If appending would exceed 4096, the original body is trimmed
     * so the appended portion always fits in full.
     */
    private function safeTruncateAppend(string $body, string $append): string
    {
        $combined = $body . $append;

        if (mb_strlen($combined) <= self::MAX_BODY_LENGTH) {
            return $combined;
        }

        // Trim body to make room for the append + truncation indicator
        $available = self::MAX_BODY_LENGTH - mb_strlen($append) - mb_strlen(self::TRUNCATION_SUFFIX);
        $available = max($available, 0);

        return mb_substr($body, 0, $available) . self::TRUNCATION_SUFFIX . $append;
    }
}