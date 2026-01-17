<?php

namespace App\Enums;

/**
 * Agreement direction (from creator's perspective).
 */
enum AgreementDirection: string
{
    case GIVING = 'giving';
    case RECEIVING = 'receiving';

    /**
     * Get the display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::GIVING => 'I am giving money',
            self::RECEIVING => 'I am receiving money',
        };
    }

    /**
     * Get short label.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::GIVING => 'Giving',
            self::RECEIVING => 'Receiving',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::GIVING => 'ഞാൻ പണം നൽകുന്നു',
            self::RECEIVING => 'ഞാൻ പണം വാങ്ങുന്നു',
        };
    }

    /**
     * Get icon.
     */
    public function icon(): string
    {
        return match ($this) {
            self::GIVING => '💸',
            self::RECEIVING => '💵',
        };
    }

    /**
     * Get the opposite direction.
     */
    public function opposite(): self
    {
        return match ($this) {
            self::GIVING => self::RECEIVING,
            self::RECEIVING => self::GIVING,
        };
    }

    /**
     * Get verb for display (past tense).
     */
    public function verbPast(): string
    {
        return match ($this) {
            self::GIVING => 'gave',
            self::RECEIVING => 'received',
        };
    }

    /**
     * Get verb for display (present tense).
     */
    public function verbPresent(): string
    {
        return match ($this) {
            self::GIVING => 'give',
            self::RECEIVING => 'receive',
        };
    }

    /**
     * Get all values as array.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get options for WhatsApp buttons.
     */
    public static function toButtons(): array
    {
        return array_map(fn(self $dir) => [
            'id' => $dir->value,
            'title' => substr("{$dir->icon()} {$dir->shortLabel()}", 0, 20),
        ], self::cases());
    }
}