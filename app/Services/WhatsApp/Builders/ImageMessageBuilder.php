<?php

namespace App\Services\WhatsApp\Builders;

use Illuminate\Support\Facades\Log;

/**
 * Builder for WhatsApp image messages.
 *
 * UX Guards:
 * - Caption soft limit: 200 chars for mobile readability (logged if exceeded)
 * - Caption hard limit: 1024 chars (WhatsApp enforced)
 * - Helper methods for common NearBuy caption patterns
 *
 * Can send images via URL or media ID.
 *
 * @example
 * // Send offer image with formatted caption
 * $message = ImageMessageBuilder::create('919876543210')
 *     ->url('https://cdn.nearbuy.in/offers/abc123.jpg')
 *     ->buildOfferCaption('Suresh Supermarket', '1.2 km', 'Today')
 *     ->build();
 *
 * // Send fish alert image
 * $message = ImageMessageBuilder::create('919876543210')
 *     ->url($catchPhotoUrl)
 *     ->buildFishAlertCaption('Mathi', 'Rajan Fisheries', 280, '800m')
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
     * Maximum caption length (WhatsApp enforced).
     */
    public const MAX_CAPTION_LENGTH = 1024;

    /**
     * Soft limit for mobile readability.
     * Captions beyond this are logged but still sent.
     */
    public const SOFT_CAPTION_LENGTH = 200;

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
     *
     * Applies soft-limit logging and hard truncation:
     * - Caption > 200 chars: warning logged (readability concern)
     * - Caption > 1024 chars: hard truncated with "…"
     */
    public function caption(string $caption): self
    {
        $length = mb_strlen($caption);

        // Hard truncate at WhatsApp limit
        if ($length > self::MAX_CAPTION_LENGTH) {
            Log::warning('WhatsApp ImageMessage: caption hard-truncated', [
                'to' => $this->to,
                'original_length' => $length,
                'limit' => self::MAX_CAPTION_LENGTH,
            ]);

            $caption = mb_substr($caption, 0, self::MAX_CAPTION_LENGTH - mb_strlen(self::TRUNCATION_SUFFIX))
                     . self::TRUNCATION_SUFFIX;
        }
        // Soft limit — log for readability review
        elseif ($length > self::SOFT_CAPTION_LENGTH) {
            Log::info('WhatsApp ImageMessage: caption exceeds soft limit', [
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
    | Caption Builder Helpers
    |--------------------------------------------------------------------------
    |
    | Pre-formatted captions for common NearBuy use cases.
    | These ensure consistent, scannable formatting across the platform.
    |
    */

    /**
     * Build caption for shop offer images (FR-OFR-14).
     *
     * Format:
     * 🛍️ *Shop Name*
     * 📍 1.2 km away
     * ⏰ Valid: Today
     *
     * @param string $shopName   Shop display name
     * @param string $distance   Formatted distance (e.g., "1.2 km", "800m")
     * @param string $validTill  Validity period (e.g., "Today", "3 Days", "This Week")
     * @param string|null $extra Optional extra line (e.g., category, phone)
     */
    public function buildOfferCaption(
        string $shopName,
        string $distance,
        string $validTill,
        ?string $extra = null
    ): self {
        $caption = "🛍️ *{$shopName}*\n"
                 . "📍 {$distance} away\n"
                 . "⏰ Valid: {$validTill}";

        if ($extra) {
            $caption .= "\n{$extra}";
        }

        return $this->caption($caption);
    }

    /**
     * Build caption for fish alert images (Pacha Meen module PM-017).
     *
     * Format:
     * 🐟 *Fresh Mathi*
     * 📍 Rajan Fisheries — 800m
     * 💰 ₹280/kg
     * ⏰ Arrived: 15 mins ago
     *
     * @param string $fishName    Fish name (English or Malayalam)
     * @param string $sellerName  Seller/market name
     * @param int|float $pricePerKg  Price per kilogram
     * @param string $distance    Distance from customer
     * @param string|null $arrivedAgo  Time since arrival (e.g., "15 mins ago")
     * @param string|null $quantity    Available quantity (e.g., "~25 kg")
     */
    public function buildFishAlertCaption(
        string $fishName,
        string $sellerName,
        int|float $pricePerKg,
        string $distance,
        ?string $arrivedAgo = null,
        ?string $quantity = null
    ): self {
        $caption = "🐟 *Fresh {$fishName}*\n"
                 . "📍 {$sellerName} — {$distance}\n"
                 . "💰 ₹{$pricePerKg}/kg";

        if ($quantity) {
            $caption .= "\n📦 Available: {$quantity}";
        }

        if ($arrivedAgo) {
            $caption .= "\n⏰ Arrived: {$arrivedAgo}";
        }

        return $this->caption($caption);
    }

    /**
     * Build caption for product response photos (FR-PRD-33).
     *
     * Format:
     * 🏪 *Shop Name*
     * 💰 ₹5,500
     * 📍 2.1 km away
     *
     * @param string $shopName   Shop name
     * @param int|float $price   Product price
     * @param string $distance   Distance from customer
     * @param string|null $model Optional model/variant info
     */
    public function buildProductPhotoCaption(
        string $shopName,
        int|float $price,
        string $distance,
        ?string $model = null
    ): self {
        $formattedPrice = number_format($price);

        $caption = "🏪 *{$shopName}*\n"
                 . "💰 ₹{$formattedPrice}";

        if ($model) {
            $caption .= " ({$model})";
        }

        $caption .= "\n📍 {$distance} away";

        return $this->caption($caption);
    }

    /**
     * Build caption for job completion photos (Njaanum Panikkar NP-022).
     *
     * Format:
     * ✅ Worker arrived
     * 📍 RTO Kakkanad
     * ⏰ 9:15 AM
     *
     * @param string $locationName  Job location name
     * @param string $timestamp     Arrival time
     * @param string $workerName    Optional worker name
     */
    public function buildArrivalPhotoCaption(
        string $locationName,
        string $timestamp,
        ?string $workerName = null
    ): self {
        $caption = "✅ Worker arrived";

        if ($workerName) {
            $caption = "✅ *{$workerName}* arrived";
        }

        $caption .= "\n📍 {$locationName}\n"
                  . "⏰ {$timestamp}";

        return $this->caption($caption);
    }

    /**
     * Build caption for Flash Deal images (FD-010).
     *
     * Format:
     * ⚡ *50% OFF All Shirts!*
     * 🏪 Fashion Hub — 1.5 km
     * ⏰ 25 mins left | 18/30 claimed
     *
     * @param string $dealTitle     Deal headline
     * @param string $shopName      Shop name
     * @param string $distance      Distance
     * @param int $minutesLeft      Minutes remaining
     * @param int $currentClaims    Current claim count
     * @param int $targetClaims     Target to activate
     */
    public function buildFlashDealCaption(
        string $dealTitle,
        string $shopName,
        string $distance,
        int $minutesLeft,
        int $currentClaims,
        int $targetClaims
    ): self {
        $caption = "⚡ *{$dealTitle}*\n"
                 . "🏪 {$shopName} — {$distance}\n"
                 . "⏰ {$minutesLeft} mins left | {$currentClaims}/{$targetClaims} claimed";

        return $this->caption($caption);
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

    /**
     * Get the current caption (for inspection/testing).
     */
    public function getCaption(): ?string
    {
        return $this->caption;
    }
}