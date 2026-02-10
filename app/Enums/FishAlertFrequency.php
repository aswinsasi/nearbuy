<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Alert frequency/time preferences for fish subscriptions.
 *
 * @srs-ref PM-014: Alert time preference: Early Morning (5-7 AM), Morning (7-9 AM), Anytime
 * @srs-ref PM-020: Respect alert time preferences
 */
enum FishAlertFrequency: string
{
    case ANYTIME = 'anytime';           // Immediate alerts
    case EARLY_MORNING = 'early_morning'; // 5-7 AM only
    case MORNING = 'morning';           // 7-9 AM only
    case TWICE_DAILY = 'twice_daily';   // 6 AM & 4 PM batch

    /**
     * Get display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::ANYTIME => 'Anytime',
            self::EARLY_MORNING => 'Early Morning (5-7 AM)',
            self::MORNING => 'Morning (7-9 AM)',
            self::TWICE_DAILY => 'Twice Daily',
        };
    }

    /**
     * Get Malayalam label.
     */
    public function labelMl(): string
    {
        return match ($this) {
            self::ANYTIME => 'എപ്പോൾ വേണമെങ്കിലും',
            self::EARLY_MORNING => 'അതിരാവിലെ (5-7)',
            self::MORNING => 'രാവിലെ (7-9)',
            self::TWICE_DAILY => 'ദിവസം രണ്ട് തവണ',
        };
    }

    /**
     * Get emoji.
     */
    public function emoji(): string
    {
        return match ($this) {
            self::ANYTIME => '🔔',
            self::EARLY_MORNING => '🌅',
            self::MORNING => '☀️',
            self::TWICE_DAILY => '📅',
        };
    }

    /**
     * Get short description.
     */
    public function description(): string
    {
        return match ($this) {
            self::ANYTIME => 'Instant alerts when fish arrives',
            self::EARLY_MORNING => 'Best for early market buyers',
            self::MORNING => 'Morning batch alerts',
            self::TWICE_DAILY => '6 AM & 4 PM digest',
        };
    }

    /**
     * Check if should send immediately.
     */
    public function isImmediate(): bool
    {
        return $this === self::ANYTIME;
    }

    /**
     * Check if should batch alerts.
     */
    public function shouldBatch(): bool
    {
        return $this !== self::ANYTIME;
    }

    /**
     * Get time window start hour (24h).
     */
    public function windowStartHour(): ?int
    {
        return match ($this) {
            self::ANYTIME => null,
            self::EARLY_MORNING => 5,
            self::MORNING => 7,
            self::TWICE_DAILY => 6,
        };
    }

    /**
     * Get time window end hour (24h).
     */
    public function windowEndHour(): ?int
    {
        return match ($this) {
            self::ANYTIME => null,
            self::EARLY_MORNING => 7,
            self::MORNING => 9,
            self::TWICE_DAILY => 16,
        };
    }

    /**
     * Check if current time is within alert window.
     */
    public function isWithinWindow(): bool
    {
        if ($this === self::ANYTIME) {
            return true;
        }

        $hour = (int) now()->format('G');

        return match ($this) {
            self::EARLY_MORNING => $hour >= 5 && $hour < 7,
            self::MORNING => $hour >= 7 && $hour < 9,
            self::TWICE_DAILY => ($hour >= 6 && $hour < 7) || ($hour >= 16 && $hour < 17),
            default => true,
        };
    }

    /**
     * Get next scheduled time for this frequency.
     */
    public function nextScheduledTime(): \Carbon\Carbon
    {
        $now = now();
        $hour = $now->hour;

        return match ($this) {
            self::ANYTIME => $now,
            
            self::EARLY_MORNING => $hour < 5 
                ? $now->copy()->setTime(5, 0)
                : $now->copy()->addDay()->setTime(5, 0),
            
            self::MORNING => $hour < 7
                ? $now->copy()->setTime(7, 0)
                : $now->copy()->addDay()->setTime(7, 0),
            
            self::TWICE_DAILY => match (true) {
                $hour < 6 => $now->copy()->setTime(6, 0),
                $hour < 16 => $now->copy()->setTime(16, 0),
                default => $now->copy()->addDay()->setTime(6, 0),
            },
        };
    }

    /**
     * Convert to WhatsApp list item.
     */
    public function toListItem(): array
    {
        return [
            'id' => 'freq_' . $this->value,
            'title' => $this->emoji() . ' ' . substr($this->label(), 0, 20),
            'description' => substr($this->description(), 0, 72),
        ];
    }

    /**
     * Get all as WhatsApp list items.
     */
    public static function toListItems(): array
    {
        return array_map(fn(self $f) => $f->toListItem(), self::cases());
    }

    /**
     * Get all values.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Create from list item ID.
     */
    public static function fromListId(string $listId): ?self
    {
        $value = str_replace(['freq_', 'fish_freq_'], '', $listId);
        return self::tryFrom($value);
    }

    /**
     * Alias for backward compatibility.
     */
    public const IMMEDIATE = self::ANYTIME;
    public const MORNING_ONLY = self::MORNING;
}