<?php

namespace App\DTOs;

/**
 * Data Transfer Object for WhatsApp list message sections.
 *
 * Represents a section in a list message containing multiple items.
 * WhatsApp allows maximum 10 sections per list message.
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
    public const MAX_ITEMS = 10;

    /**
     * @param string $title Section title
     * @param array<int, ListItem> $items Section items
     */
    public function __construct(
        public string $title,
        public array $items,
    ) {
        // Validate title length
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException(
                "Section title must not exceed " . self::MAX_TITLE_LENGTH . " characters. Got: " . mb_strlen($title)
            );
        }

        // Validate items count
        if (count($items) > self::MAX_ITEMS) {
            throw new \InvalidArgumentException(
                "Section must not have more than " . self::MAX_ITEMS . " items. Got: " . count($items)
            );
        }

        // Validate items are ListItem instances
        foreach ($items as $item) {
            if (!$item instanceof ListItem) {
                throw new \InvalidArgumentException('All items must be ListItem instances');
            }
        }
    }

    /**
     * Create a new ListSection.
     *
     * @param string $title
     * @param array<int, ListItem> $items
     */
    public static function make(string $title, array $items): self
    {
        return new self($title, $items);
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        $items = [];
        foreach ($data['rows'] ?? $data['items'] ?? [] as $row) {
            $items[] = ListItem::fromArray($row);
        }

        return new self(
            title: $data['title'] ?? '',
            items: $items,
        );
    }

    /**
     * Create multiple sections from array.
     *
     * @param array<int, array{title: string, rows: array}> $sections
     * @return array<int, self>
     */
    public static function fromArrayMultiple(array $sections): array
    {
        if (count($sections) > 10) {
            throw new \InvalidArgumentException('WhatsApp allows maximum 10 sections per list message');
        }

        return array_map(fn(array $section) => self::fromArray($section), $sections);
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
}