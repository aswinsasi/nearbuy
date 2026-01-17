<?php

namespace App\DTOs;

/**
 * Data Transfer Object for WhatsApp interactive button options.
 *
 * Represents a single button in an interactive button message.
 * WhatsApp allows maximum 3 buttons per message.
 */
readonly class ButtonOption
{
    /**
     * Maximum title length allowed by WhatsApp.
     */
    public const MAX_TITLE_LENGTH = 20;

    public function __construct(
        public string $id,
        public string $title,
    ) {
        // Validate title length
        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException(
                "Button title must not exceed " . self::MAX_TITLE_LENGTH . " characters. Got: " . mb_strlen($title)
            );
        }
    }

    /**
     * Create a new ButtonOption.
     */
    public static function make(string $id, string $title): self
    {
        return new self($id, $title);
    }

    /**
     * Create from array.
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? throw new \InvalidArgumentException('Button ID is required'),
            title: $data['title'] ?? throw new \InvalidArgumentException('Button title is required'),
        );
    }

    /**
     * Create multiple buttons from array.
     *
     * @param array<int, array{id: string, title: string}> $buttons
     * @return array<int, self>
     */
    public static function fromArrayMultiple(array $buttons): array
    {
        if (count($buttons) > 3) {
            throw new \InvalidArgumentException('WhatsApp allows maximum 3 buttons per message');
        }

        return array_map(fn(array $button) => self::fromArray($button), $buttons);
    }

    /**
     * Convert to WhatsApp API format.
     */
    public function toApi(): array
    {
        return [
            'type' => 'reply',
            'reply' => [
                'id' => $this->id,
                'title' => $this->title,
            ],
        ];
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
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
}