<?php

namespace App\Services\WhatsApp\Builders;

use Illuminate\Support\Facades\Log;

/**
 * Builder for WhatsApp document messages (PDFs, etc.).
 *
 * UX Guards:
 * - Caption soft limit: 150 chars for mobile readability
 * - Human-readable filename helpers for agreements
 * - Consistent naming: "NearBuy-Agreement-{number}.pdf"
 *
 * Can send documents via URL or media ID.
 *
 * @example
 * // Send agreement PDF with formatted caption
 * $message = DocumentMessageBuilder::create('919876543210')
 *     ->url($pdfUrl)
 *     ->agreementFilename('NB-AG-2024-0042')
 *     ->agreementCaption('NB-AG-2024-0042', 25000, 'Rajan K')
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
     * Maximum caption length (WhatsApp enforced).
     */
    public const MAX_CAPTION_LENGTH = 1024;

    /**
     * Soft limit for mobile readability.
     */
    public const SOFT_CAPTION_LENGTH = 150;

    /**
     * Maximum filename length (WhatsApp enforced).
     */
    public const MAX_FILENAME_LENGTH = 240;

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
            Log::warning('WhatsApp DocumentMessage: filename truncated', [
                'to' => $this->to,
                'original_length' => mb_strlen($filename),
                'limit' => self::MAX_FILENAME_LENGTH,
            ]);

            $filename = mb_substr($filename, 0, self::MAX_FILENAME_LENGTH - 4) . '.pdf';
        }

        $this->filename = $filename;
        return $this;
    }

    /**
     * Set the document caption.
     *
     * Applies soft-limit logging and hard truncation.
     */
    public function caption(string $caption): self
    {
        $length = mb_strlen($caption);

        // Hard truncate at WhatsApp limit
        if ($length > self::MAX_CAPTION_LENGTH) {
            Log::warning('WhatsApp DocumentMessage: caption hard-truncated', [
                'to' => $this->to,
                'original_length' => $length,
                'limit' => self::MAX_CAPTION_LENGTH,
            ]);

            $caption = mb_substr($caption, 0, self::MAX_CAPTION_LENGTH - mb_strlen(self::TRUNCATION_SUFFIX))
                     . self::TRUNCATION_SUFFIX;
        }
        // Soft limit — log for review
        elseif ($length > self::SOFT_CAPTION_LENGTH) {
            Log::info('WhatsApp DocumentMessage: caption exceeds soft limit', [
                'to' => $this->to,
                'length' => $length,
                'soft_limit' => self::SOFT_CAPTION_LENGTH,
            ]);
        }

        $this->caption = $caption;
        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Agreement Document Helpers (FR-AGR-20 to FR-AGR-25)
    |--------------------------------------------------------------------------
    |
    | Pre-formatted filenames and captions for Digital Agreements.
    | Ensures consistent, professional presentation.
    |
    */

    /**
     * Set human-readable filename for agreements.
     *
     * Format: "NearBuy-Agreement-NB-AG-2024-0042.pdf"
     *
     * @param string $agreementNumber  Agreement number (e.g., "NB-AG-2024-0042")
     */
    public function agreementFilename(string $agreementNumber): self
    {
        $filename = "NearBuy-Agreement-{$agreementNumber}.pdf";
        return $this->filename($filename);
    }

    /**
     * Set short, scannable caption for agreements.
     *
     * Format:
     * 📝 Agreement #NB-AG-2024-0042
     * 💰 ₹25,000 — Rajan K
     * ✅ Confirmed by both parties
     *
     * @param string $agreementNumber  Agreement number
     * @param int|float $amount        Amount in rupees
     * @param string $counterpartyName Other party's name
     * @param bool $isConfirmed        Whether both parties confirmed
     */
    public function agreementCaption(
        string $agreementNumber,
        int|float $amount,
        string $counterpartyName,
        bool $isConfirmed = true
    ): self {
        $formattedAmount = number_format($amount);

        $caption = "📝 Agreement #{$agreementNumber}\n"
                 . "💰 ₹{$formattedAmount} — {$counterpartyName}";

        if ($isConfirmed) {
            $caption .= "\n✅ Confirmed by both parties";
        } else {
            $caption .= "\n⏳ Awaiting confirmation";
        }

        return $this->caption($caption);
    }

    /**
     * Set Malayalam caption for agreements.
     *
     * @param string $agreementNumber  Agreement number
     * @param int|float $amount        Amount in rupees
     * @param string $counterpartyName Other party's name
     */
    public function agreementCaptionMl(
        string $agreementNumber,
        int|float $amount,
        string $counterpartyName
    ): self {
        $formattedAmount = number_format($amount);

        $caption = "📝 കരാർ #{$agreementNumber}\n"
                 . "💰 ₹{$formattedAmount} — {$counterpartyName}\n"
                 . "✅ രണ്ടു പേരും സ്ഥിരീകരിച്ചു";

        return $this->caption($caption);
    }

    /**
     * Build caption for agreement with amount in words (FR-AGR-22).
     *
     * Format:
     * 📝 Agreement #NB-AG-2024-0042
     * 💰 ₹25,000 (Twenty-Five Thousand)
     * 🤝 Between: You ↔ Rajan K
     * ✅ Digitally verified
     *
     * @param string $agreementNumber
     * @param int|float $amount
     * @param string $amountInWords    Pre-formatted words (e.g., "Twenty-Five Thousand")
     * @param string $counterpartyName
     */
    public function agreementCaptionFull(
        string $agreementNumber,
        int|float $amount,
        string $amountInWords,
        string $counterpartyName
    ): self {
        $formattedAmount = number_format($amount);

        $caption = "📝 Agreement #{$agreementNumber}\n"
                 . "💰 ₹{$formattedAmount} ({$amountInWords})\n"
                 . "🤝 Between: You ↔ {$counterpartyName}\n"
                 . "✅ Digitally verified";

        return $this->caption($caption);
    }

    /*
    |--------------------------------------------------------------------------
    | Other Document Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Set filename and caption for shop offer PDFs.
     *
     * @param string $shopName Shop name
     * @param string $date     Offer date (e.g., "2024-01-15")
     */
    public function offerDocument(string $shopName, string $date): self
    {
        // Sanitize shop name for filename
        $safeShopName = preg_replace('/[^a-zA-Z0-9\-]/', '-', $shopName);
        $safeShopName = preg_replace('/-+/', '-', $safeShopName);
        $safeShopName = trim($safeShopName, '-');

        $this->filename("Offer-{$safeShopName}-{$date}.pdf");
        $this->caption("🛍️ *{$shopName}*\n📅 Offer valid: {$date}");

        return $this;
    }

    /**
     * Set filename and caption for invoice/receipt PDFs.
     *
     * @param string $invoiceNumber
     * @param int|float $amount
     * @param string $merchantName
     */
    public function invoiceDocument(
        string $invoiceNumber,
        int|float $amount,
        string $merchantName
    ): self {
        $formattedAmount = number_format($amount);

        $this->filename("Invoice-{$invoiceNumber}.pdf");
        $this->caption("🧾 Invoice #{$invoiceNumber}\n💰 ₹{$formattedAmount}\n🏪 {$merchantName}");

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

    /**
     * Get the current filename (for inspection/testing).
     */
    public function getFilename(): ?string
    {
        return $this->filename;
    }

    /**
     * Get the current caption (for inspection/testing).
     */
    public function getCaption(): ?string
    {
        return $this->caption;
    }
}