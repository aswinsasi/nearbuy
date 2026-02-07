<?php

namespace App\Services\WhatsApp\Builders;

use App\DTOs\ListItem;
use App\DTOs\ListSection;
use Illuminate\Support\Facades\Log;

/**
 * Builder for WhatsApp interactive list messages.
 *
 * UX Guards:
 * - Max 10 items enforced; auto-paginated with "More ➡️" item
 * - Item titles auto-truncated at 24 chars with "…"
 * - Item descriptions auto-truncated at 72 chars with "…"
 * - addPaginationSupport() for large datasets
 * - All truncations logged for developer review
 *
 * @example
 * // Basic usage
 * $message = ListMessageBuilder::create('919876543210')
 *     ->header('Shop Categories')
 *     ->body('Select a category to browse offers:')
 *     ->buttonText('View Categories')
 *     ->addSection('Popular', [
 *         ['id' => 'grocery', 'title' => '🛒 Grocery', 'description' => 'Daily essentials'],
 *     ])
 *     ->build();
 *
 * // Auto-paginated large list
 * $message = ListMessageBuilder::create($phone)
 *     ->body('Nearby shops with offers:')
 *     ->buttonText('View Shops')
 *     ->addPaginationSupport($allShopItems, page: 1)
 *     ->build();
 */
class ListMessageBuilder
{
    private string $to;
    private ?string $header = null;
    private string $body = '';
    private ?string $footer = null;
    private string $buttonText = '';
    private array $sections = [];
    private ?string $replyTo = null;

    /**
     * Maximum sections allowed by WhatsApp.
     */
    public const MAX_SECTIONS = 10;

    /**
     * Maximum total items across all sections (WhatsApp hard limit).
     */
    public const MAX_TOTAL_ITEMS = 10;

    /**
     * Items per page when auto-paginating (9 items + 1 "More" item).
     */
    public const ITEMS_PER_PAGE = 9;

    /**
     * Maximum lengths — WhatsApp enforced.
     */
    public const MAX_HEADER_LENGTH = 60;
    public const MAX_BODY_LENGTH = 1024;
    public const MAX_FOOTER_LENGTH = 60;
    public const MAX_BUTTON_TEXT_LENGTH = 20;

    /**
     * Maximum lengths — NearBuy UX targets for readability.
     */
    public const MAX_ITEM_TITLE_LENGTH = 24;
    public const MAX_ITEM_DESCRIPTION_LENGTH = 72;

    /**
     * Truncation indicator.
     */
    private const TRUNCATION_SUFFIX = '…';

    /**
     * Pagination "More" item ID prefix.
     */
    private const MORE_ITEM_ID_PREFIX = 'page_next_';

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
     * Item titles and descriptions are auto-sanitized:
     * - Titles truncated at 24 chars
     * - Descriptions truncated at 72 chars
     * - Truncations logged as warnings
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

        $sanitizedItems = array_map(
            fn(array $item) => $this->buildSanitizedListItem($item),
            $items
        );

        $this->sections[] = ListSection::make($title, $sanitizedItems);
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
     * Set sections from array with auto-sanitization.
     *
     * @param array<int, array{title: string, rows: array}> $sections
     */
    public function sections(array $sections): self
    {
        $this->sections = [];

        foreach ($sections as $section) {
            $title = $section['title'] ?? '';
            $rows = $section['rows'] ?? [];
            $this->addSection($title, $rows);
        }

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
     * Add items with automatic pagination support.
     *
     * If $allItems has more than 10 entries:
     * - Shows 9 items for the requested page
     * - Adds a "More ➡️" item that triggers the next page
     * - Logs pagination info for debugging
     *
     * Your flow controller should handle the "page_next_X" button ID
     * by calling this method again with the next page number.
     *
     * @param array<int, array{id: string, title: string, description?: string}> $allItems Full item list
     * @param int $page Current page (1-based)
     * @param string $sectionTitle Optional section title
     * @return self
     */
    public function addPaginationSupport(array $allItems, int $page = 1, string $sectionTitle = ''): self
    {
        $totalItems = count($allItems);
        $totalPages = (int) ceil($totalItems / self::ITEMS_PER_PAGE);
        $page = max(1, min($page, $totalPages)); // Clamp to valid range

        $offset = ($page - 1) * self::ITEMS_PER_PAGE;
        $pageItems = array_slice($allItems, $offset, self::ITEMS_PER_PAGE);
        $hasMore = ($page < $totalPages);

        Log::info('WhatsApp ListMessage: pagination', [
            'to' => $this->to,
            'total_items' => $totalItems,
            'page' => $page,
            'total_pages' => $totalPages,
            'showing' => count($pageItems),
            'has_more' => $hasMore,
        ]);

        // Add the "More" navigation item if there are more pages
        if ($hasMore) {
            $remaining = $totalItems - ($offset + count($pageItems));
            $nextPage = $page + 1;

            $pageItems[] = [
                'id' => self::MORE_ITEM_ID_PREFIX . $nextPage,
                'title' => "More ➡️ ({$remaining} left)",
                'description' => "Page {$nextPage} of {$totalPages}",
            ];
        }

        // Update footer with page indicator
        if ($totalPages > 1) {
            $this->footer("Page {$page} of {$totalPages}");
        }

        return $this->addSection($sectionTitle, $pageItems);
    }

    /**
     * Check if a list item ID is a pagination "next page" trigger.
     *
     * @return int|null The next page number, or null if not a pagination ID
     */
    public static function parsePaginationId(string $itemId): ?int
    {
        if (str_starts_with($itemId, self::MORE_ITEM_ID_PREFIX)) {
            $page = (int) substr($itemId, strlen(self::MORE_ITEM_ID_PREFIX));
            return $page > 0 ? $page : null;
        }

        return null;
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
     *
     * Validates total item count and auto-trims excess items with warning.
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

        // Count total items and enforce limit
        $totalItems = $this->countTotalItems();

        if ($totalItems > self::MAX_TOTAL_ITEMS) {
            Log::warning('WhatsApp ListMessage: items exceed max, auto-trimming', [
                'to' => $this->to,
                'total_items' => $totalItems,
                'max_allowed' => self::MAX_TOTAL_ITEMS,
                'hint' => 'Use addPaginationSupport() for large lists',
            ]);

            $this->trimItemsToLimit();
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

    /**
     * Get total item count across all sections (for inspection/testing).
     */
    public function getTotalItemCount(): int
    {
        return $this->countTotalItems();
    }

    /*
    |--------------------------------------------------------------------------
    | Internal Guards & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Build a sanitized ListItem from a raw array.
     *
     * Applies title and description truncation with logging.
     */
    private function buildSanitizedListItem(array $item): ListItem
    {
        $id = $item['id'];
        $title = $this->sanitizeItemTitle($item['title'], $id);
        $description = isset($item['description'])
            ? $this->sanitizeItemDescription($item['description'], $id)
            : null;

        return ListItem::make($id, $title, $description);
    }

    /**
     * Sanitize and auto-truncate an item title (max 24 chars).
     */
    private function sanitizeItemTitle(string $title, string $itemId): string
    {
        if (mb_strlen($title) <= self::MAX_ITEM_TITLE_LENGTH) {
            return $title;
        }

        Log::warning('WhatsApp ListMessage: item title auto-truncated', [
            'to' => $this->to,
            'item_id' => $itemId,
            'original_title' => $title,
            'original_length' => mb_strlen($title),
            'max_allowed' => self::MAX_ITEM_TITLE_LENGTH,
        ]);

        return mb_substr($title, 0, self::MAX_ITEM_TITLE_LENGTH - mb_strlen(self::TRUNCATION_SUFFIX))
             . self::TRUNCATION_SUFFIX;
    }

    /**
     * Sanitize and auto-truncate an item description (max 72 chars).
     */
    private function sanitizeItemDescription(string $description, string $itemId): string
    {
        if (mb_strlen($description) <= self::MAX_ITEM_DESCRIPTION_LENGTH) {
            return $description;
        }

        Log::warning('WhatsApp ListMessage: item description auto-truncated', [
            'to' => $this->to,
            'item_id' => $itemId,
            'original_length' => mb_strlen($description),
            'max_allowed' => self::MAX_ITEM_DESCRIPTION_LENGTH,
        ]);

        return mb_substr($description, 0, self::MAX_ITEM_DESCRIPTION_LENGTH - mb_strlen(self::TRUNCATION_SUFFIX))
             . self::TRUNCATION_SUFFIX;
    }

    /**
     * Count total items across all sections.
     */
    private function countTotalItems(): int
    {
        return array_reduce(
            $this->sections,
            fn(int $carry, ListSection $section) => $carry + $section->count(),
            0
        );
    }

    /**
     * Trim items to respect the 10-item WhatsApp limit.
     *
     * Keeps items from earlier sections first. When the limit is
     * reached, remaining sections are dropped entirely.
     *
     * This is a safety net — prefer using addPaginationSupport()
     * so users can actually see all items.
     */
    private function trimItemsToLimit(): void
    {
        $kept = 0;
        $trimmedSections = [];

        foreach ($this->sections as $section) {
            $sectionCount = $section->count();

            if ($kept >= self::MAX_TOTAL_ITEMS) {
                // Skip entire section
                break;
            }

            $available = self::MAX_TOTAL_ITEMS - $kept;

            if ($sectionCount <= $available) {
                // Section fits entirely
                $trimmedSections[] = $section;
                $kept += $sectionCount;
            } else {
                // Partial section — take what fits
                $trimmedSections[] = $section->take($available);
                $kept += $available;
                break;
            }
        }

        $this->sections = $trimmedSections;
    }
}