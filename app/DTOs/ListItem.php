<?php

namespace App\DTOs;

/**
 * Data Transfer Object for WhatsApp list message items.
 *
 * Represents a single row/item in a list message section.
 */
readonly class ListItem
{
    /**
     * Maximum title length allowed by WhatsApp.
     */
    public const MAX_TITLE_LENGTH = 24;

    /**
     * Maximum description length allowed by WhatsApp.
     */
    public const MAX_DESCRIPTION_LENGTH = 72;

    public function __construct(
        public string $id,
        public string $title,
        public ?string $description = null,
    ) {
        // Validate title length
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException(
                "List item title must not exceed " . self::MAX_TITLE_LENGTH . " characters. Got: " . mb_strlen($title)
            );
        }

        // Validate description length
        if ($description !== null && mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new \InvalidArgumentException(
                "List item description must not exceed " . self::MAX_DESCRIPTION_LENGTH . " characters. Got: " . mb_strlen($description)
            );
        }
    }

    /**
     * Create a new ListItem.
     */
    public static function make(string $id, string $title, ?string $description = null): self
    {
        return new self($id, $title, $description);
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? throw new \InvalidArgumentException('List item ID is required'),
            title: $data['title'] ?? throw new \InvalidArgumentException('List item title is required'),
            description: $data['description'] ?? null,
        );
    }

    /**
     * Create multiple items from array.
     *
     * @param array<int, array{id: string, title: string, description?: string}> $items
     * @return array<int, self>
     */
    public static function fromArrayMultiple(array $items): array
    {
        return array_map(fn(array $item) => self::fromArray($item), $items);
    }

    /**
     * Convert to WhatsApp API format.
     */
    public function toApi(): array
    {
        $row = [
            'id' => $this->id,
            'title' => $this->title,
        ];

        if ($this->description !== null) {
            $row['description'] = $this->description;
        }

        return $row;
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
        ];
    }

    /**
     * Truncate title to fit WhatsApp limits.
     */
    public static function truncateTitle(string $title): string
    {
        if (mb_strlen($title) <= self::MAX_TITLE_LENGTH) {
            return $title;
        }

        return mb_substr($title, 0, self::MAX_TITLE_LENGTH - 1) . '…';
    }

    /**
     * Truncate description to fit WhatsApp limits.
     */
    public static function truncateDescription(string $description): string
    {
        if (mb_strlen($description) <= self::MAX_DESCRIPTION_LENGTH) {
            return $description;
        }

        return mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH - 1) . '…';
    }
}