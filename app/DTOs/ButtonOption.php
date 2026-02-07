<?php

namespace App\DTOs;

use Illuminate\Support\Facades\Log;

/**
 * Data Transfer Object for WhatsApp interactive button options.
 *
 * WhatsApp Constraints:
 * - Maximum 3 buttons per message
 * - Title: max 20 characters
 * - ID: max 256 characters (alphanumeric, underscore, hyphen)
 *
 * @example
 * // Create single button
 * $button = ButtonOption::make('confirm', '✅ Confirm');
 *
 * // Create from array
 * $button = ButtonOption::fromArray(['id' => 'cancel', 'title' => '❌ Cancel']);
 *
 * // Auto-truncate long title
 * $button = ButtonOption::makeSafe('long_id', 'This title is way too long');
 */
readonly class ButtonOption
{
    /**
     * Maximum title length allowed by WhatsApp.
     */
    public const MAX_TITLE_LENGTH = 20;

    /**
     * Maximum ID length allowed by WhatsApp.
     */
    public const MAX_ID_LENGTH = 256;

    /**
     * Maximum buttons per message.
     */
    public const MAX_BUTTONS = 3;

    public function __construct(
        public string $id,
        public string $title,
    ) {
        // Validate ID
        if (empty($id)) {
            throw new \InvalidArgumentException('Button ID cannot be empty');
        }

        if (mb_strlen($id) > self::MAX_ID_LENGTH) {
            throw new \InvalidArgumentException(
                "Button ID must not exceed " . self::MAX_ID_LENGTH . " characters. Got: " . mb_strlen($id)
            );
        }

        // Validate title
        if (empty($title)) {
            throw new \InvalidArgumentException('Button title cannot be empty');
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException(
                "Button title must not exceed " . self::MAX_TITLE_LENGTH . " characters. " .
                "Got: " . mb_strlen($title) . " ('{$title}')"
            );
        }
    }

    /**
     * Create a new ButtonOption (strict — throws on invalid).
     */
    public static function make(string $id, string $title): self
    {
        return new self($id, $title);
    }

    /**
     * Create a new ButtonOption with auto-truncation (safe — never throws).
     */
    public static function makeSafe(string $id, string $title): self
    {
        // Truncate ID if needed
        if (mb_strlen($id) > self::MAX_ID_LENGTH) {
            $id = mb_substr($id, 0, self::MAX_ID_LENGTH);
        }

        // Truncate title if needed
        $title = self::truncateTitle($title);

        return new self($id, $title);
    }

    /**
     * Create from array (strict).
     */
    public static function fromArray(array $data): self
    {
        return new self(
            id: $data['id'] ?? throw new \InvalidArgumentException('Button ID is required'),
            title: $data['title'] ?? throw new \InvalidArgumentException('Button title is required'),
        );
    }

    /**
     * Create from array with auto-truncation (safe).
     */
    public static function fromArraySafe(array $data): self
    {
        $id = $data['id'] ?? throw new \InvalidArgumentException('Button ID is required');
        $title = $data['title'] ?? throw new \InvalidArgumentException('Button title is required');

        return self::makeSafe($id, $title);
    }

    /**
     * Create multiple buttons from array (strict).
     *
     * @param array<int, array{id: string, title: string}> $buttons
     * @return array<int, self>
     * @throws \InvalidArgumentException if more than 3 buttons
     */
    public static function fromArrayMultiple(array $buttons): array
    {
        if (count($buttons) > self::MAX_BUTTONS) {
            throw new \InvalidArgumentException(
                'WhatsApp allows maximum ' . self::MAX_BUTTONS . ' buttons per message. Got: ' . count($buttons)
            );
        }

        return array_map(fn(array $button) => self::fromArray($button), $buttons);
    }

    /**
     * Create multiple buttons from array with auto-truncation (safe).
     *
     * If more than 3 buttons provided, only first 3 are used (logged).
     *
     * @param array<int, array{id: string, title: string}> $buttons
     * @return array<int, self>
     */
    public static function fromArrayMultipleSafe(array $buttons): array
    {
        if (count($buttons) > self::MAX_BUTTONS) {
            Log::warning('ButtonOption: Truncating to 3 buttons', [
                'provided' => count($buttons),
                'kept' => self::MAX_BUTTONS,
            ]);
            $buttons = array_slice($buttons, 0, self::MAX_BUTTONS);
        }

        return array_map(fn(array $button) => self::fromArraySafe($button), $buttons);
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
     * Convert multiple buttons to API format.
     *
     * @param array<int, self> $buttons
     * @return array<int, array>
     */
    public static function toApiMultiple(array $buttons): array
    {
        return array_map(fn(self $button) => $button->toApi(), $buttons);
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
     *
     * Uses "…" suffix when truncated.
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
     * Check if a title is valid (within limits).
     */
    public static function isValidTitle(string $title): bool
    {
        return mb_strlen($title) <= self::MAX_TITLE_LENGTH && mb_strlen($title) > 0;
    }

    /**
     * Check if an ID is valid.
     */
    public static function isValidId(string $id): bool
    {
        return mb_strlen($id) <= self::MAX_ID_LENGTH && mb_strlen($id) > 0;
    }

    /*
    |--------------------------------------------------------------------------
    | Common Button Presets
    |--------------------------------------------------------------------------
    */

    /**
     * Create a "Main Menu" button.
     */
    public static function mainMenu(): self
    {
        return new self('main_menu', '🏠 Menu');
    }

    /**
     * Create a "Cancel" button.
     */
    public static function cancel(): self
    {
        return new self('cancel', '❌ Cancel');
    }

    /**
     * Create a "Back" button.
     */
    public static function back(): self
    {
        return new self('back', '⬅️ Back');
    }

    /**
     * Create a "Retry" button.
     */
    public static function retry(): self
    {
        return new self('retry', '🔄 Try Again');
    }

    /**
     * Create a "Skip" button.
     */
    public static function skip(): self
    {
        return new self('skip', '⏭️ Skip');
    }

    /**
     * Create a "Confirm" button.
     */
    public static function confirm(): self
    {
        return new self('confirm', '✅ Confirm');
    }

    /**
     * Create a "Yes" button.
     */
    public static function yes(string $label = '✅ Yes'): self
    {
        return new self('yes', self::truncateTitle($label));
    }

    /**
     * Create a "No" button.
     */
    public static function no(string $label = '❌ No'): self
    {
        return new self('no', self::truncateTitle($label));
    }

    /**
     * Create a "Done" button.
     */
    public static function done(): self
    {
        return new self('done', '✅ Done');
    }

    /**
     * Create standard error recovery buttons [Retry, Menu].
     *
     * @return array<int, self>
     */
    public static function errorButtons(): array
    {
        return [
            self::retry(),
            self::mainMenu(),
        ];
    }

    /**
     * Create standard confirm/cancel buttons.
     *
     * @return array<int, self>
     */
    public static function confirmCancelButtons(): array
    {
        return [
            self::confirm(),
            self::cancel(),
        ];
    }

    /**
     * Create standard yes/no buttons.
     *
     * @return array<int, self>
     */
    public static function yesNoButtons(): array
    {
        return [
            self::yes(),
            self::no(),
        ];
    }
}