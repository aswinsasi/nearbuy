<?php

namespace App\DTOs;

use Illuminate\Support\Facades\Log;

/**
 * Data Transfer Object for WhatsApp list message items.
 *
 * WhatsApp Constraints:
 * - Maximum 10 items per section
 * - Maximum 10 sections per list
 * - Title: max 24 characters
 * - Description: max 72 characters (optional)
 * - ID: max 200 characters
 *
 * @example
 * // Create single item
 * $item = ListItem::make('grocery', '🛒 Grocery', 'Vegetables, fruits');
 *
 * // Auto-truncate long text
 * $item = ListItem::makeSafe('id', 'Very Long Title Here', 'Very long description here');
 *
 * // Create from array
 * $item = ListItem::fromArray(['id' => 'test', 'title' => 'Test', 'description' => 'Desc']);
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

    /**
     * Maximum ID length allowed by WhatsApp.
     */
    public const MAX_ID_LENGTH = 200;

    /**
     * Maximum items per section.
     */
    public const MAX_ITEMS_PER_SECTION = 10;

    /**
     * Maximum sections per list.
     */
    public const MAX_SECTIONS = 10;

    public function __construct(
        public string $id,
        public string $title,
        public ?string $description = null,
    ) {
        // Validate ID
        if (empty($id)) {
            throw new \InvalidArgumentException('List item ID cannot be empty');
        }

        if (mb_strlen($id) > self::MAX_ID_LENGTH) {
            throw new \InvalidArgumentException(
                "List item ID must not exceed " . self::MAX_ID_LENGTH . " characters. Got: " . mb_strlen($id)
            );
        }

        // Validate title
        if (empty($title)) {
            throw new \InvalidArgumentException('List item title cannot be empty');
        }

        if (mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new \InvalidArgumentException(
                "List item title must not exceed " . self::MAX_TITLE_LENGTH . " characters. " .
                "Got: " . mb_strlen($title) . " ('{$title}')"
            );
        }

        // Validate description (if provided)
        if ($description !== null && mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
            throw new \InvalidArgumentException(
                "List item description must not exceed " . self::MAX_DESCRIPTION_LENGTH . " characters. " .
                "Got: " . mb_strlen($description)
            );
        }
    }

    /**
     * Create a new ListItem (strict — throws on invalid).
     */
    public static function make(string $id, string $title, ?string $description = null): self
    {
        return new self($id, $title, $description);
    }

    /**
     * Create a new ListItem with auto-truncation (safe — never throws on length).
     */
    public static function makeSafe(string $id, string $title, ?string $description = null): self
    {
        // Truncate ID if needed
        if (mb_strlen($id) > self::MAX_ID_LENGTH) {
            $id = mb_substr($id, 0, self::MAX_ID_LENGTH);
        }

        // Truncate title
        $title = self::truncateTitle($title);

        // Truncate description
        if ($description !== null) {
            $description = self::truncateDescription($description);
        }

        return new self($id, $title, $description);
    }

    /**
     * Create from array (strict).
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
     * Create from array with auto-truncation (safe).
     */
    public static function fromArraySafe(array $data): self
    {
        $id = $data['id'] ?? throw new \InvalidArgumentException('List item ID is required');
        $title = $data['title'] ?? throw new \InvalidArgumentException('List item title is required');
        $description = $data['description'] ?? null;

        return self::makeSafe($id, $title, $description);
    }

    /**
     * Create multiple items from array (strict).
     *
     * @param array<int, array{id: string, title: string, description?: string}> $items
     * @return array<int, self>
     * @throws \InvalidArgumentException if more than 10 items
     */
    public static function fromArrayMultiple(array $items): array
    {
        if (count($items) > self::MAX_ITEMS_PER_SECTION) {
            throw new \InvalidArgumentException(
                'WhatsApp allows maximum ' . self::MAX_ITEMS_PER_SECTION . ' items per section. Got: ' . count($items)
            );
        }

        return array_map(fn(array $item) => self::fromArray($item), $items);
    }

    /**
     * Create multiple items with auto-truncation (safe).
     *
     * If more than 10 items, only first 10 are used (logged).
     *
     * @param array<int, array{id: string, title: string, description?: string}> $items
     * @return array<int, self>
     */
    public static function fromArrayMultipleSafe(array $items): array
    {
        if (count($items) > self::MAX_ITEMS_PER_SECTION) {
            Log::warning('ListItem: Truncating to 10 items', [
                'provided' => count($items),
                'kept' => self::MAX_ITEMS_PER_SECTION,
            ]);
            $items = array_slice($items, 0, self::MAX_ITEMS_PER_SECTION);
        }

        return array_map(fn(array $item) => self::fromArraySafe($item), $items);
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
     * Convert multiple items to API format.
     *
     * @param array<int, self> $items
     * @return array<int, array>
     */
    public static function toApiMultiple(array $items): array
    {
        return array_map(fn(self $item) => $item->toApi(), $items);
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
        $title = trim($title);

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
        $description = trim($description);

        if (mb_strlen($description) <= self::MAX_DESCRIPTION_LENGTH) {
            return $description;
        }

        return mb_substr($description, 0, self::MAX_DESCRIPTION_LENGTH - 1) . '…';
    }

    /**
     * Check if a title is valid.
     */
    public static function isValidTitle(string $title): bool
    {
        return mb_strlen($title) <= self::MAX_TITLE_LENGTH && mb_strlen($title) > 0;
    }

    /**
     * Check if a description is valid.
     */
    public static function isValidDescription(?string $description): bool
    {
        if ($description === null) {
            return true;
        }
        return mb_strlen($description) <= self::MAX_DESCRIPTION_LENGTH;
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
    | Category List Item Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Create list items for shop categories.
     *
     * @return array<int, self>
     */
    public static function shopCategories(): array
    {
        return [
            self::make('grocery', '🛒 Grocery', 'Vegetables, fruits, daily needs'),
            self::make('electronics', '📱 Electronics', 'TV, laptop, gadgets'),
            self::make('clothes', '👕 Clothes', 'Fashion, textiles'),
            self::make('medical', '💊 Medical', 'Pharmacy, health products'),
            self::make('furniture', '🪑 Furniture', 'Home & office furniture'),
            self::make('mobile', '📲 Mobile', 'Phones & accessories'),
            self::make('appliances', '🔌 Appliances', 'AC, fridge, washing machine'),
            self::make('hardware', '🔧 Hardware', 'Tools, construction'),
        ];
    }

    /**
     * Create list items for notification frequencies.
     *
     * @return array<int, self>
     */
    public static function notificationFrequencies(): array
    {
        return [
            self::make('immediate', '🔔 Immediately', 'Get notified instantly'),
            self::make('2hours', '⏰ Every 2 Hours', 'Batched (Recommended)'),
            self::make('twice_daily', '📅 Twice Daily', '9 AM & 5 PM'),
            self::make('daily', '🌅 Once Daily', 'Morning 9 AM only'),
        ];
    }

    /**
     * Create list items for offer validity periods.
     *
     * @return array<int, self>
     */
    public static function offerValidityOptions(): array
    {
        return [
            self::make('today', '📅 Today Only', 'Expires tonight'),
            self::make('3days', '📅 3 Days', 'Short promotion'),
            self::make('week', '📅 This Week', 'Week-long offer'),
            self::make('month', '📅 This Month', 'Monthly deal'),
        ];
    }

    /**
     * Create list items for search radius options.
     *
     * @return array<int, self>
     */
    public static function radiusOptions(): array
    {
        return [
            self::make('radius_2', '📍 2 km', 'Walking distance'),
            self::make('radius_5', '📍 5 km', 'Nearby (Recommended)'),
            self::make('radius_10', '📍 10 km', 'Extended area'),
            self::make('radius_20', '📍 20 km', 'Wide search'),
        ];
    }

    /**
     * Create list items for agreement purposes.
     *
     * @return array<int, self>
     */
    public static function agreementPurposes(): array
    {
        return [
            self::make('loan', '🤝 Loan', 'Lending to friend/family'),
            self::make('advance', '🔧 Work Advance', 'Advance for work/service'),
            self::make('deposit', '🏠 Deposit', 'Rent, booking, purchase'),
            self::make('business', '💼 Business', 'Vendor/supplier payment'),
            self::make('other', '📝 Other', 'Other purpose'),
        ];
    }

    /**
     * Create list items for due date options.
     *
     * @return array<int, self>
     */
    public static function dueDateOptions(): array
    {
        return [
            self::make('due_1week', '📅 1 Week', date('d M Y', strtotime('+1 week'))),
            self::make('due_2weeks', '📅 2 Weeks', date('d M Y', strtotime('+2 weeks'))),
            self::make('due_1month', '📅 1 Month', date('d M Y', strtotime('+1 month'))),
            self::make('due_3months', '📅 3 Months', date('d M Y', strtotime('+3 months'))),
            self::make('due_none', '⏳ No Fixed Date', 'Open-ended'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Fish Types (Pacha Meen Module)
    |--------------------------------------------------------------------------
    */

    /**
     * Create list items for fish types.
     *
     * @return array<int, self>
     */
    public static function fishTypes(): array
    {
        return [
            self::make('mathi', '🐟 Mathi (Sardine)', 'Sea Fish'),
            self::make('ayala', '🐟 Ayala (Mackerel)', 'Sea Fish'),
            self::make('karimeen', '🐟 Karimeen', 'Fresh Water'),
            self::make('choora', '🐟 Choora (Tuna)', 'Sea Fish'),
            self::make('nenmeen', '🐟 Nenmeen (Red Snapper)', 'Sea Fish'),
            self::make('konju', '🦐 Konju (Prawns)', 'Shellfish'),
            self::make('njandu', '🦀 Njandu (Crab)', 'Shellfish'),
            self::make('avoli', '🐟 Avoli (Pomfret)', 'Sea Fish'),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Job Types (Njaanum Panikkar Module)
    |--------------------------------------------------------------------------
    */

    /**
     * Create list items for job types.
     *
     * @return array<int, self>
     */
    public static function jobTypes(): array
    {
        return [
            self::make('queue', '🕐 Queue Standing', '₹100-200'),
            self::make('delivery', '📦 Pickup/Delivery', '₹50-150'),
            self::make('shopping', '🛒 Grocery Shopping', '₹80-150'),
            self::make('bill_payment', '💳 Bill Payment', '₹50-100'),
            self::make('moving', '📦 Moving Help', '₹200-500'),
            self::make('event', '🎉 Event Helper', '₹300-500'),
            self::make('garden', '🌳 Garden Cleaning', '₹200-400'),
            self::make('typing', '💻 Computer Typing', '₹100-300'),
        ];
    }
}