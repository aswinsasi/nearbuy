<?php

namespace App\DTOs;

use Illuminate\Support\Facades\Log;

/**
 * Data Transfer Object for WhatsApp list message sections.
 *
 * WhatsApp Constraints:
 * - Maximum 10 sections per list message
 * - Maximum 10 items per section
 * - Section title: max 24 characters
 *
 * @example
 * // Create section with items
 * $section = ListSection::make('🛒 Grocery', [
 *     ListItem::make('grocery_1', 'Fresh Mart', '500m away'),
 *     ListItem::make('grocery_2', 'Daily Needs', '1.2km away'),
 * ]);
 *
 * // Create with auto-truncation
 * $section = ListSection::makeSafe('Very Long Section Title Here', $items);
 *
 * // Convert to API format
 * $apiPayload = $section->toApi();
 */
readonly class ListSection
{
    /**
     * Maximum title length allowed by WhatsApp.
     */
    public const MAX_TITLE_LENGTH = 24;

    /**
     * Maximum items per section.
     */
    public const MAX_ITEMS_PER_SECTION = 10;

    /**
     * Maximum sections per list message.
     */
    public const MAX_SECTIONS = 10;

    /**
     * @param string $title Section title
     * @param array<int, ListItem> $items Section items
     */
    public function __construct(
        public string $title,
        public array $items,
    ) {
        // Validate title
        if (empty($title)) {
            throw new \InvalidArgumentException('Section title cannot be empty');
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException(
                "Section title must not exceed " . self::MAX_TITLE_LENGTH . " characters. " .
                "Got: " . mb_strlen($title) . " ('{$title}')"
            );
        }

        // Validate items count
        if (count($items) > self::MAX_ITEMS_PER_SECTION) {
            throw new \InvalidArgumentException(
                "Section must not have more than " . self::MAX_ITEMS_PER_SECTION . " items. " .
                "Got: " . count($items)
            );
        }

        // Validate items are ListItem instances
        foreach ($items as $index => $item) {
            if (!$item instanceof ListItem) {
                throw new \InvalidArgumentException(
                    "Item at index {$index} must be a ListItem instance"
                );
            }
        }
    }

    /**
     * Create a new ListSection (strict — throws on invalid).
     *
     * @param string $title Section title
     * @param array<int, ListItem> $items Section items
     */
    public static function make(string $title, array $items): self
    {
        return new self($title, $items);
    }

    /**
     * Create a new ListSection with auto-truncation (safe — never throws on length).
     *
     * @param string $title Section title
     * @param array<int, ListItem> $items Section items
     */
    public static function makeSafe(string $title, array $items): self
    {
        // Truncate title
        $title = self::truncateTitle($title);

        // Truncate items if too many
        if (count($items) > self::MAX_ITEMS_PER_SECTION) {
            Log::warning('ListSection: Truncating to 10 items', [
                'title' => $title,
                'provided' => count($items),
                'kept' => self::MAX_ITEMS_PER_SECTION,
            ]);
            $items = array_slice($items, 0, self::MAX_ITEMS_PER_SECTION);
        }

        return new self($title, $items);
    }

    /**
     * Create from array (strict).
     *
     * Expected format:
     * [
     *     'title' => 'Section Title',
     *     'rows' => [
     *         ['id' => 'item_1', 'title' => 'Item 1', 'description' => 'Desc 1'],
     *         ['id' => 'item_2', 'title' => 'Item 2'],
     *     ]
     * ]
     */
    public static function fromArray(array $data): self
    {
        $title = $data['title'] ?? throw new \InvalidArgumentException('Section title is required');

        $items = [];
        foreach ($data['rows'] ?? $data['items'] ?? [] as $row) {
            $items[] = ListItem::fromArray($row);
        }

        return new self($title, $items);
    }

    /**
     * Create from array with auto-truncation (safe).
     */
    public static function fromArraySafe(array $data): self
    {
        $title = $data['title'] ?? '';

        $items = [];
        foreach ($data['rows'] ?? $data['items'] ?? [] as $row) {
            $items[] = ListItem::fromArraySafe($row);
        }

        return self::makeSafe($title, $items);
    }

    /**
     * Create multiple sections from array (strict).
     *
     * @param array<int, array{title: string, rows: array}> $sections
     * @return array<int, self>
     * @throws \InvalidArgumentException if more than 10 sections
     */
    public static function fromArrayMultiple(array $sections): array
    {
        if (count($sections) > self::MAX_SECTIONS) {
            throw new \InvalidArgumentException(
                'WhatsApp allows maximum ' . self::MAX_SECTIONS . ' sections per list message. ' .
                'Got: ' . count($sections)
            );
        }

        return array_map(fn(array $section) => self::fromArray($section), $sections);
    }

    /**
     * Create multiple sections with auto-truncation (safe).
     *
     * If more than 10 sections, only first 10 are used.
     *
     * @param array<int, array{title: string, rows: array}> $sections
     * @return array<int, self>
     */
    public static function fromArrayMultipleSafe(array $sections): array
    {
        if (count($sections) > self::MAX_SECTIONS) {
            Log::warning('ListSection: Truncating to 10 sections', [
                'provided' => count($sections),
                'kept' => self::MAX_SECTIONS,
            ]);
            $sections = array_slice($sections, 0, self::MAX_SECTIONS);
        }

        return array_map(fn(array $section) => self::fromArraySafe($section), $sections);
    }

    /**
     * Convert to WhatsApp API format.
     */
    public function toApi(): array
    {
        return [
            'title' => $this->title,
            'rows' => array_map(fn(ListItem $item) => $item->toApi(), $this->items),
        ];
    }

    /**
     * Convert multiple sections to API format.
     *
     * @param array<int, self> $sections
     * @return array<int, array>
     */
    public static function toApiMultiple(array $sections): array
    {
        return array_map(fn(self $section) => $section->toApi(), $sections);
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'items' => array_map(fn(ListItem $item) => $item->toArray(), $this->items),
        ];
    }

    /**
     * Get the count of items in this section.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Check if section is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Check if section has items.
     */
    public function hasItems(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Get item by ID.
     */
    public function getItem(string $id): ?ListItem
    {
        foreach ($this->items as $item) {
            if ($item->id === $id) {
                return $item;
            }
        }
        return null;
    }

    /**
     * Check if section has item with ID.
     */
    public function hasItem(string $id): bool
    {
        return $this->getItem($id) !== null;
    }

    /**
     * Get all item IDs.
     *
     * @return array<int, string>
     */
    public function getItemIds(): array
    {
        return array_map(fn(ListItem $item) => $item->id, $this->items);
    }

    /**
     * Truncate title to fit WhatsApp limits.
     */
    public static function truncateTitle(string $title): string
    {
        $title = trim($title);

        if (mb_strlen($title) <= self::MAX_TITLE_LENGTH) {
            return $title;
        }

        return mb_substr($title, 0, self::MAX_TITLE_LENGTH - 1) . '…';
    }

    /**
     * Check if title is valid.
     */
    public static function isValidTitle(string $title): bool
    {
        return mb_strlen($title) <= self::MAX_TITLE_LENGTH && mb_strlen($title) > 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Preset Sections
    |--------------------------------------------------------------------------
    */

    /**
     * Create a shop categories section.
     */
    public static function shopCategories(): self
    {
        return self::make('🏪 Shop Categories', ListItem::shopCategories());
    }

    /**
     * Create a notification frequency section.
     */
    public static function notificationFrequencies(): self
    {
        return self::make('🔔 Notification Settings', ListItem::notificationFrequencies());
    }

    /**
     * Create an offer validity section.
     */
    public static function offerValidity(): self
    {
        return self::make('📅 Validity Period', ListItem::offerValidityOptions());
    }

    /**
     * Create a search radius section.
     */
    public static function searchRadius(): self
    {
        return self::make('📍 Search Radius', ListItem::radiusOptions());
    }

    /**
     * Create an agreement purposes section.
     */
    public static function agreementPurposes(): self
    {
        return self::make('📋 Purpose', ListItem::agreementPurposes());
    }

    /**
     * Create a due date options section.
     */
    public static function dueDateOptions(): self
    {
        return self::make('📅 Due Date', ListItem::dueDateOptions());
    }

    /**
     * Create a fish types section.
     */
    public static function fishTypes(): self
    {
        return self::make('🐟 Fish Types', ListItem::fishTypes());
    }

    /**
     * Create a job types section.
     */
    public static function jobTypes(): self
    {
        return self::make('👷 Job Types', ListItem::jobTypes());
    }

    /*
    |--------------------------------------------------------------------------
    | Builder Pattern
    |--------------------------------------------------------------------------
    */

    /**
     * Create a builder for fluent section creation.
     */
    public static function builder(string $title): ListSectionBuilder
    {
        return new ListSectionBuilder($title);
    }
}

/**
 * Builder for creating ListSection fluently.
 *
 * @example
 * $section = ListSection::builder('🏪 Shops')
 *     ->addItem('shop_1', 'Fresh Mart', '500m away')
 *     ->addItem('shop_2', 'Daily Needs', '1.2km away')
 *     ->build();
 */
class ListSectionBuilder
{
    private string $title;
    private array $items = [];

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    /**
     * Add an item to the section.
     */
    public function addItem(string $id, string $title, ?string $description = null): self
    {
        $this->items[] = ListItem::makeSafe($id, $title, $description);
        return $this;
    }

    /**
     * Add a ListItem instance.
     */
    public function addListItem(ListItem $item): self
    {
        $this->items[] = $item;
        return $this;
    }

    /**
     * Add multiple items from array.
     */
    public function addItems(array $items): self
    {
        foreach ($items as $item) {
            if ($item instanceof ListItem) {
                $this->items[] = $item;
            } elseif (is_array($item)) {
                $this->items[] = ListItem::fromArraySafe($item);
            }
        }
        return $this;
    }

    /**
     * Build the ListSection.
     */
    public function build(): ListSection
    {
        return ListSection::makeSafe($this->title, $this->items);
    }

    /**
     * Get item count.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Check if can add more items.
     */
    public function canAddMore(): bool
    {
        return count($this->items) < ListSection::MAX_ITEMS_PER_SECTION;
    }
}