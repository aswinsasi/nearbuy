<?php

declare(strict_types=1);

namespace App\Services\WhatsApp\Messages;

use App\Enums\AgreementPurpose;
use Carbon\Carbon;

/**
 * Agreement Message Templates.
 *
 * Friendly, bilingual (English + Malayalam) messages for trust-building.
 * This involves money - be precise in review but warm in conversation.
 *
 * @srs-ref FR-AGR-01 to FR-AGR-25
 */
class AgreementMessages
{
    /*
    |--------------------------------------------------------------------------
    | Create Flow Messages (FR-AGR-01 to FR-AGR-08)
    |--------------------------------------------------------------------------
    */

    /** FR-AGR-01: Direction selection */
    public const ASK_DIRECTION = "📝 *Puthiya Agreement!*\n\n" .
        "Paisa kodukkunnathaano vaangunnathaano?\n" .
        "_Are you giving or receiving?_";

    /** FR-AGR-02: Amount */
    public const ASK_AMOUNT = "💰 *Ethra amount?*\n\n" .
        "Amount type cheyyuka (numbers only):\n" .
        "_Eg: 20000_";

    /** FR-AGR-03: Counterparty name */
    public const ASK_NAME = "👤 *Aarude koode?*\n\n" .
        "Other person-nte full name type cheyyuka:";

    /** FR-AGR-04: Counterparty phone */
    public const ASK_PHONE = "📱 *{name}-nte WhatsApp number?*\n\n" .
        "10-digit mobile number type cheyyuka:\n" .
        "_Eg: 9876543210_\n\n" .
        "⚠️ Avar-kku confirmation request povum.";

    /** FR-AGR-05: Purpose selection */
    public const ASK_PURPOSE = "📋 *Entha karyam?*\n\n" .
        "Purpose select cheyyuka:";

    /** FR-AGR-06: Description */
    public const ASK_DESCRIPTION = "📝 *Kurachu details?* (optional)\n\n" .
        "{hint}\n\n" .
        "Type cheyyuka or *skip* cheyyuka.";

    /** FR-AGR-07: Due date */
    public const ASK_DUE_DATE = "📅 *Eppozhekku thirichu thararanam?*\n\n" .
        "Due date select cheyyuka:";

    /** FR-AGR-08: Review summary */
    public const REVIEW = "📋 *Agreement Review*\n\n" .
        "{direction_icon} *{direction_text}*\n" .
        "💰 *₹{amount}*\n\n" .
        "👤 {arrow} *{name}*\n" .
        "📱 {phone}\n\n" .
        "📋 Purpose: {purpose}\n" .
        "📝 Details: {description}\n" .
        "📅 Due: {due_date}\n\n" .
        "✅ *Sheri aano?*";

    /** Creation success */
    public const CREATED = "🎉 *Agreement Created!*\n\n" .
        "📋 #{agreement_number}\n" .
        "💰 ₹{amount} {direction} {name}\n\n" .
        "📤 *{name}*-nu confirmation request ayachittund.\n\n" .
        "Avar confirm cheythaal PDF kittum 👍";

    /*
    |--------------------------------------------------------------------------
    | Confirmation Flow Messages (FR-AGR-10 to FR-AGR-15)
    |--------------------------------------------------------------------------
    */

    /** FR-AGR-12: Confirmation request to counterparty */
    public const CONFIRM_REQUEST = "📋 *Agreement Confirmation!*\n\n" .
        "*{creator_name}* ninakkum aayi oru agreement record cheyyaan aagrahikkunnu:\n\n" .
        "{direction_icon} *{direction_text}*\n" .
        "💰 *₹{amount}*\n\n" .
        "📋 Purpose: {purpose}\n" .
        "📝 Details: {description}\n" .
        "📅 Due: {due_date}\n\n" .
        "📋 #{agreement_number}\n\n" .
        "⚠️ *Ith sheri aano?*";

    /** FR-AGR-15: Both confirmed */
    public const BOTH_CONFIRMED = "✅ *Agreement Confirmed!*\n\n" .
        "📋 #{agreement_number}\n\n" .
        "Randum perrum confirm cheythu! 🎉\n" .
        "PDF document generate cheythittund.";

    /** Counterparty rejected */
    public const REJECTED = "❌ *Agreement Rejected*\n\n" .
        "*{name}* ith reject cheythu.\n\n" .
        "📋 #{agreement_number}";

    /** Counterparty doesn't know creator */
    public const DISPUTED = "⚠️ *Agreement Disputed*\n\n" .
        "*{name}* says they don't know you.\n\n" .
        "📋 #{agreement_number}";

    /*
    |--------------------------------------------------------------------------
    | My Agreements Messages
    |--------------------------------------------------------------------------
    */

    public const MY_LIST_HEADER = "📋 *Ente Agreements*\n\n" .
        "{count} agreement(s):";

    public const MY_LIST_EMPTY = "📭 *Agreements illa*\n\n" .
        "Puthiyathu undaakkatte?";

    public const PENDING_HEADER = "⏳ *Pending Confirmations*\n\n" .
        "{count} agreement(s) confirmation kaaththirikkunnu:";

    public const AGREEMENT_DETAIL = "📋 *#{agreement_number}*\n\n" .
        "{direction_icon} *{direction_text}*\n\n" .
        "👤 {name}\n" .
        "📱 {phone}\n\n" .
        "💰 *₹{amount}*\n" .
        "_{amount_words}_\n\n" .
        "📋 {purpose}\n" .
        "📝 {description}\n" .
        "📅 Due: {due_date}\n" .
        "📊 Status: {status}";

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    */

    public const ERROR_INVALID_AMOUNT = "⚠️ *Invalid amount*\n\n" .
        "Numbers maathram type cheyyuka.\n" .
        "_Eg: 25000_";

    public const ERROR_INVALID_PHONE = "⚠️ *Invalid phone number*\n\n" .
        "10-digit mobile number venam.\n" .
        "_Eg: 9876543210_";

    public const ERROR_INVALID_NAME = "⚠️ *Invalid name*\n\n" .
        "Valid name type cheyyuka (min 2 characters).";

    public const ERROR_SELF_AGREEMENT = "⚠️ *Swantham koode agreement undaakkaan pattoola!*\n\n" .
        "Other person-nte number type cheyyuka.";

    public const ERROR_NOT_FOUND = "❌ Agreement kandilla.";

    public const ERROR_ALREADY_CONFIRMED = "⚠️ Ith already confirm aayi.";

    public const ERROR_EXPIRED = "⏰ Ith expire aayi.";

    /*
    |--------------------------------------------------------------------------
    | Button Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * FR-AGR-01: Direction buttons.
     */
    public static function getDirectionButtons(): array
    {
        return [
            ['id' => 'giving', 'title' => '💸 Kodukkunnu'],
            ['id' => 'receiving', 'title' => '📥 Vaangunnu'],
        ];
    }

    /**
     * FR-AGR-05: Purpose list (5 types from Appendix 8.2).
     */
    public static function getPurposeSections(): array
    {
        return [
            [
                'title' => 'Purpose',
                'rows' => AgreementPurpose::toListRows(),
            ],
        ];
    }

    /**
     * FR-AGR-07: Due date list (5 options from Appendix 8.4).
     */
    public static function getDueDateSections(): array
    {
        return [
            [
                'title' => 'Due Date',
                'rows' => [
                    ['id' => '1week', 'title' => '📅 1 Week', 'description' => self::formatFutureDate(7)],
                    ['id' => '2weeks', 'title' => '📅 2 Weeks', 'description' => self::formatFutureDate(14)],
                    ['id' => '1month', 'title' => '📅 1 Month', 'description' => self::formatFutureDate(30)],
                    ['id' => '3months', 'title' => '📅 3 Months', 'description' => self::formatFutureDate(90)],
                    ['id' => 'none', 'title' => '⏰ No Fixed Date', 'description' => 'Open-ended'],
                ],
            ],
        ];
    }

    /**
     * FR-AGR-08: Review confirmation buttons.
     */
    public static function getReviewButtons(): array
    {
        return [
            ['id' => 'confirm', 'title' => '✅ Confirm'],
            ['id' => 'edit', 'title' => '✏️ Edit'],
            ['id' => 'cancel', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * FR-AGR-14: Counterparty confirmation buttons.
     */
    public static function getConfirmButtons(): array
    {
        return [
            ['id' => 'yes', 'title' => '✅ Sheri, Confirm'],
            ['id' => 'no', 'title' => '❌ Alla, Incorrect'],
            ['id' => 'unknown', 'title' => "❓ Ariyilla"],
        ];
    }

    /**
     * Skip/back buttons.
     */
    public static function getSkipButtons(): array
    {
        return [
            ['id' => 'skip', 'title' => '⏭️ Skip'],
            ['id' => 'back', 'title' => '⬅️ Back'],
        ];
    }

    /**
     * Post-creation buttons.
     */
    public static function getPostCreateButtons(): array
    {
        return [
            ['id' => 'view', 'title' => '📋 View'],
            ['id' => 'another', 'title' => '➕ Another'],
            ['id' => 'menu', 'title' => '🏠 Menu'],
        ];
    }

    /**
     * Agreement actions.
     */
    public static function getAgreementActionButtons(): array
    {
        return [
            ['id' => 'pdf', 'title' => '📄 Download PDF'],
            ['id' => 'complete', 'title' => '✅ Mark Done'],
            ['id' => 'back', 'title' => '⬅️ Back'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | List Builders
    |--------------------------------------------------------------------------
    */

    /**
     * Build agreements list.
     */
    public static function buildAgreementsList(array $agreements, int $userId): array
    {
        $rows = [];

        foreach ($agreements as $agr) {
            $isCreator = ($agr['from_user_id'] ?? null) === $userId;
            $otherName = $isCreator 
                ? ($agr['to_name'] ?? 'Unknown') 
                : ($agr['from_name'] ?? 'Unknown');
            
            $direction = $agr['direction'] ?? 'giving';
            $icon = self::getDirectionIcon($direction, $isCreator);
            $statusIcon = self::getStatusIcon($agr['status'] ?? 'pending');

            $rows[] = [
                'id' => 'agr_' . $agr['id'],
                'title' => self::truncate("{$icon} ₹" . number_format($agr['amount'] ?? 0), 24),
                'description' => "{$statusIcon} {$otherName} • #{$agr['agreement_number']}",
            ];
        }

        return [['title' => 'Agreements', 'rows' => array_slice($rows, 0, 10)]];
    }

    /**
     * Build pending confirmations list.
     */
    public static function buildPendingList(array $agreements): array
    {
        $rows = [];

        foreach ($agreements as $agr) {
            $creator = $agr['from_name'] ?? 'Unknown';

            $rows[] = [
                'id' => 'pending_' . $agr['id'],
                'title' => self::truncate("₹" . number_format($agr['amount'] ?? 0) . " - {$creator}", 24),
                'description' => "⏳ Confirmation pending",
            ];
        }

        return [['title' => 'Pending', 'rows' => array_slice($rows, 0, 10)]];
    }

    /*
    |--------------------------------------------------------------------------
    | Formatting Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Format message with placeholders.
     */
    public static function format(string $template, array $data): string
    {
        foreach ($data as $key => $value) {
            $template = str_replace("{{$key}}", (string) $value, $template);
        }
        return $template;
    }

    /**
     * Get direction icon.
     */
    public static function getDirectionIcon(string $direction, bool $isCreator = true): string
    {
        if ($isCreator) {
            return $direction === 'giving' ? '💸' : '📥';
        }
        // Invert for counterparty
        return $direction === 'giving' ? '📥' : '💸';
    }

    /**
     * Get direction text.
     */
    public static function getDirectionText(string $direction, bool $isCreator = true): string
    {
        if ($isCreator) {
            return $direction === 'giving' 
                ? 'Nee kodukkunnu' 
                : 'Nee vaangunnu';
        }
        return $direction === 'giving' 
            ? 'Neekku kittunnu' 
            : 'Nee kodukkanam';
    }

    /**
     * Get direction arrow for review.
     */
    public static function getDirectionArrow(string $direction): string
    {
        return $direction === 'giving' ? '→' : '←';
    }

    /**
     * Get purpose label.
     */
    public static function getPurposeLabel(?string $purpose): string
    {
        if (!$purpose) return '📝 Other';
        
        $enum = AgreementPurpose::tryFrom($purpose);
        return $enum ? $enum->displayWithIcon() : '📝 ' . ucfirst($purpose);
    }

    /**
     * Get description hint based on purpose.
     */
    public static function getDescriptionHint(?string $purpose): string
    {
        if (!$purpose) return 'Eg: Describe the agreement';
        
        $enum = AgreementPurpose::tryFrom($purpose);
        return $enum ? $enum->descriptionHint() : 'Eg: Describe the agreement';
    }

    /**
     * Get due date from selection (Appendix 8.4).
     */
    public static function getDueDateFromSelection(string $selection): ?Carbon
    {
        return match ($selection) {
            '1week' => now()->addWeek(),
            '2weeks' => now()->addWeeks(2),
            '1month' => now()->addMonth(),
            '3months' => now()->addMonths(3),
            'none' => null,
            default => null,
        };
    }

    /**
     * Get due date label from selection.
     */
    public static function getDueDateLabel(string $selection): string
    {
        return match ($selection) {
            '1week' => '1 Week',
            '2weeks' => '2 Weeks',
            '1month' => '1 Month',
            '3months' => '3 Months',
            'none' => 'No Fixed Date',
            default => $selection,
        };
    }

    /**
     * Format due date.
     */
    public static function formatDueDate(?Carbon $date): string
    {
        if (!$date) return 'No fixed date';
        return $date->format('M j, Y');
    }

    /**
     * Format future date for list description.
     */
    public static function formatFutureDate(int $days): string
    {
        return now()->addDays($days)->format('M j, Y');
    }

    /**
     * Get status icon.
     */
    public static function getStatusIcon(string $status): string
    {
        return match ($status) {
            'pending' => '⏳',
            'confirmed' => '✅',
            'completed' => '✔️',
            'rejected' => '❌',
            'disputed' => '⚠️',
            'expired' => '⏰',
            'cancelled' => '🚫',
            default => '📋',
        };
    }

    /**
     * Get status label.
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'pending' => '⏳ Waiting for confirmation',
            'confirmed' => '✅ Confirmed',
            'completed' => '✔️ Completed',
            'rejected' => '❌ Rejected',
            'disputed' => '⚠️ Disputed',
            'expired' => '⏰ Expired',
            'cancelled' => '🚫 Cancelled',
            default => ucfirst($status),
        };
    }

    /**
     * Truncate text.
     */
    public static function truncate(string $text, int $max): string
    {
        if (mb_strlen($text) <= $max) return $text;
        return mb_substr($text, 0, $max - 1) . '…';
    }

    /**
     * Format amount.
     */
    public static function formatAmount(float $amount): string
    {
        return '₹' . number_format($amount);
    }
}