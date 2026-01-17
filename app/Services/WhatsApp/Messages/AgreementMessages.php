<?php

namespace App\Services\WhatsApp\Messages;

/**
 * Message templates for Agreements module.
 *
 * Contains all user-facing messages for agreement creation and confirmation.
 */
class AgreementMessages
{
    /*
    |--------------------------------------------------------------------------
    | Create Flow Messages
    |--------------------------------------------------------------------------
    */

    public const CREATE_START = "📋 *Create Digital Agreement*\n\nRecord a financial agreement with someone for future reference.\n\nThis creates a digitally signed record that both parties can verify.";

    public const ASK_DIRECTION = "💱 *Transaction Direction*\n\nAre you giving or receiving money in this agreement?";

    public const ASK_AMOUNT = "💰 *Enter Amount*\n\nEnter the amount in rupees (numbers only).\n\nExample: _25000_";

    public const ASK_NAME = "👤 *Other Party's Name*\n\nEnter the full name of the other person:";

    public const ASK_PHONE = "📱 *Other Party's WhatsApp Number*\n\nEnter their 10-digit mobile number:\n\nExample: _9876543210_";

    public const ASK_PURPOSE = "📝 *Purpose of Agreement*\n\nSelect the purpose:";

    public const ASK_DESCRIPTION = "📄 *Description (Optional)*\n\nAdd any additional details about this agreement.\n\nOr type 'skip' to continue without a description.";

    public const ASK_DUE_DATE = "📅 *Due Date*\n\nWhen is this amount due?";

    public const REVIEW_AGREEMENT = "📋 *Review Your Agreement*\n\n{direction_emoji} *{direction}*\n\n👤 *Other Party:* {other_party_name}\n📱 *Phone:* {other_party_phone}\n\n💰 *Amount:* ₹{amount}\n📝 *Purpose:* {purpose}\n📅 *Due Date:* {due_date}\n📄 *Description:* {description}\n\nIs this correct?";

    public const CREATE_SUCCESS = "✅ *Agreement Created!*\n\n📋 Agreement #: *{agreement_number}*\n\n{other_party_name} will receive a confirmation request on WhatsApp.\n\nOnce they confirm, you'll both receive a PDF copy of the agreement.";

    public const CREATE_CANCELLED = "❌ Agreement creation cancelled.";

    /*
    |--------------------------------------------------------------------------
    | Confirmation Flow Messages
    |--------------------------------------------------------------------------
    */

    public const CONFIRM_REQUEST = "📋 *Agreement Confirmation Request*\n\n*{creator_name}* wants to record this agreement with you:\n\n{direction_emoji} *{direction}*\n\n💰 *Amount:* ₹{amount}\n📝 *Purpose:* {purpose}\n📅 *Due Date:* {due_date}\n📄 *Description:* {description}\n\n📋 Agreement #: {agreement_number}\n\nPlease confirm if this is correct.";

    public const CONFIRM_SUCCESS = "✅ *Agreement Confirmed!*\n\n📋 Agreement #: *{agreement_number}*\n\nBoth parties have confirmed. Your PDF agreement is ready.";

    public const CONFIRM_REJECTED = "❌ *Agreement Rejected*\n\nYou have rejected this agreement.\n\n{creator_name} will be notified.";

    public const CONFIRM_DISPUTED = "⚠️ *Agreement Disputed*\n\nYou've indicated you don't know the other party.\n\n{creator_name} will be notified.";

    public const CREATOR_NOTIFIED_CONFIRMED = "✅ *Agreement Confirmed!*\n\n{other_party_name} has confirmed your agreement.\n\n📋 Agreement #: *{agreement_number}*\n\nYour PDF agreement is ready.";

    public const CREATOR_NOTIFIED_REJECTED = "❌ *Agreement Rejected*\n\n{other_party_name} has rejected your agreement #{agreement_number}.\n\nReason: {reason}";

    public const CREATOR_NOTIFIED_DISPUTED = "⚠️ *Agreement Disputed*\n\n{other_party_name} claims they don't know you.\n\nAgreement #{agreement_number} has been flagged.";

    /*
    |--------------------------------------------------------------------------
    | Agreement List Messages
    |--------------------------------------------------------------------------
    */

    public const MY_AGREEMENTS_HEADER = "📋 *My Agreements*\n\nYou have {count} agreement(s):";

    public const MY_AGREEMENTS_EMPTY = "📭 *No Agreements*\n\nYou don't have any agreements yet.\n\nWould you like to create one?";

    public const PENDING_AGREEMENTS = "⏳ *Pending Confirmations*\n\nYou have {count} agreement(s) waiting for confirmation:";

    public const AGREEMENT_DETAIL = "📋 *Agreement #{agreement_number}*\n\n{direction_emoji} *{direction}*\n\n👤 *Other Party:* {other_party_name}\n📱 *Phone:* {other_party_phone}\n\n💰 *Amount:* ₹{amount}\n({amount_words})\n\n📝 *Purpose:* {purpose}\n📅 *Due Date:* {due_date}\n📊 *Status:* {status}\n\n📄 {description}";

    /*
    |--------------------------------------------------------------------------
    | PDF Messages
    |--------------------------------------------------------------------------
    */

    public const PDF_READY = "📄 *Your Agreement PDF is Ready*\n\n📋 Agreement #: {agreement_number}\n\nTap below to download your signed agreement document.";

    public const PDF_GENERATING = "⏳ Generating your agreement PDF...";

    /*
    |--------------------------------------------------------------------------
    | Error Messages
    |--------------------------------------------------------------------------
    */

    public const ERROR_INVALID_AMOUNT = "⚠️ Invalid amount. Please enter numbers only.\n\nExample: _25000_";

    public const ERROR_INVALID_PHONE = "⚠️ Invalid phone number. Please enter a 10-digit mobile number.\n\nExample: _9876543210_";

    public const ERROR_INVALID_NAME = "⚠️ Please enter a valid name (at least 2 characters).";

    public const ERROR_SELF_AGREEMENT = "⚠️ You cannot create an agreement with yourself.";

    public const ERROR_AGREEMENT_NOT_FOUND = "❌ Agreement not found.";

    public const ERROR_ALREADY_CONFIRMED = "⚠️ This agreement has already been confirmed.";

    public const ERROR_ALREADY_REJECTED = "⚠️ This agreement has already been rejected.";

    public const ERROR_EXPIRED = "⚠️ This agreement confirmation request has expired.";

    /*
    |--------------------------------------------------------------------------
    | Button Configurations
    |--------------------------------------------------------------------------
    */

    /**
     * Get direction selection buttons.
     */
    public static function getDirectionButtons(): array
    {
        return [
            ['id' => 'giving', 'title' => '💸 Giving Money'],
            ['id' => 'receiving', 'title' => '💰 Receiving Money'],
        ];
    }

    /**
     * Get purpose selection list sections.
     */
    public static function getPurposeSections(): array
    {
        return [
            [
                'title' => 'Select Purpose',
                'rows' => [
                    ['id' => 'loan', 'title' => '🏦 Loan', 'description' => 'Personal or business loan'],
                    ['id' => 'advance', 'title' => '💵 Advance', 'description' => 'Salary or payment advance'],
                    ['id' => 'deposit', 'title' => '🏠 Deposit', 'description' => 'Security or rental deposit'],
                    ['id' => 'business', 'title' => '💼 Business', 'description' => 'Business transaction'],
                    ['id' => 'personal', 'title' => '👤 Personal', 'description' => 'Personal transaction'],
                    ['id' => 'other', 'title' => '📋 Other', 'description' => 'Other purpose'],
                ],
            ],
        ];
    }

    /**
     * Get due date selection list sections.
     */
    public static function getDueDateSections(): array
    {
        return [
            [
                'title' => 'Select Due Date',
                'rows' => [
                    ['id' => '1week', 'title' => '📅 1 Week', 'description' => self::formatFutureDate(7)],
                    ['id' => '2weeks', 'title' => '📅 2 Weeks', 'description' => self::formatFutureDate(14)],
                    ['id' => '1month', 'title' => '📅 1 Month', 'description' => self::formatFutureDate(30)],
                    ['id' => '3months', 'title' => '📅 3 Months', 'description' => self::formatFutureDate(90)],
                    ['id' => '6months', 'title' => '📅 6 Months', 'description' => self::formatFutureDate(180)],
                    ['id' => 'none', 'title' => '⏰ No Fixed Date', 'description' => 'Open-ended agreement'],
                ],
            ],
        ];
    }

    /**
     * Get review confirmation buttons.
     */
    public static function getReviewButtons(): array
    {
        return [
            ['id' => 'confirm', 'title' => '✅ Create Agreement'],
            ['id' => 'edit', 'title' => '✏️ Edit'],
            ['id' => 'cancel', 'title' => '❌ Cancel'],
        ];
    }

    /**
     * Get counterparty confirmation buttons.
     */
    public static function getConfirmButtons(): array
    {
        return [
            ['id' => 'confirm', 'title' => '✅ Yes, Confirm'],
            ['id' => 'reject', 'title' => '❌ No, Incorrect'],
            ['id' => 'unknown', 'title' => '❓ Don\'t Know'],
        ];
    }

    /**
     * Get agreement action buttons.
     */
    public static function getAgreementActionButtons(): array
    {
        return [
            ['id' => 'download_pdf', 'title' => '📄 Download PDF'],
            ['id' => 'mark_complete', 'title' => '✅ Mark Complete'],
            ['id' => 'back', 'title' => '⬅️ Back'],
        ];
    }

    /**
     * Get pending agreement action buttons.
     */
    public static function getPendingActionButtons(): array
    {
        return [
            ['id' => 'remind', 'title' => '🔔 Send Reminder'],
            ['id' => 'cancel', 'title' => '❌ Cancel Agreement'],
            ['id' => 'back', 'title' => '⬅️ Back'],
        ];
    }

    /**
     * Get post-creation buttons.
     */
    public static function getPostCreateButtons(): array
    {
        return [
            ['id' => 'view_agreement', 'title' => '📋 View Agreement'],
            ['id' => 'create_another', 'title' => '➕ Create Another'],
            ['id' => 'menu', 'title' => '🏠 Main Menu'],
        ];
    }

    /**
     * Get my agreements empty buttons.
     */
    public static function getEmptyAgreementsButtons(): array
    {
        return [
            ['id' => 'create', 'title' => '➕ Create Agreement'],
            ['id' => 'menu', 'title' => '🏠 Main Menu'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | List Builders
    |--------------------------------------------------------------------------
    */

    /**
     * Build agreements list for user.
     */
    public static function buildAgreementsList(array $agreements): array
    {
        $rows = [];

        foreach ($agreements as $agreement) {
            $statusIcon = self::getStatusIcon($agreement['status'] ?? 'pending');
            $amount = number_format($agreement['amount'] ?? 0);
            $otherParty = $agreement['other_party_name'] ?? 'Unknown';

            $rows[] = [
                'id' => 'agreement_' . $agreement['id'],
                'title' => self::truncate("₹{$amount} - {$otherParty}", 24),
                'description' => self::truncate("{$statusIcon} #{$agreement['agreement_number']}", 72),
            ];
        }

        return [
            [
                'title' => 'Your Agreements',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];
    }

    /**
     * Build pending confirmations list.
     */
    public static function buildPendingList(array $agreements): array
    {
        $rows = [];

        foreach ($agreements as $agreement) {
            $amount = number_format($agreement['amount'] ?? 0);
            $creator = $agreement['creator_name'] ?? 'Unknown';

            $rows[] = [
                'id' => 'pending_' . $agreement['id'],
                'title' => self::truncate("₹{$amount} from {$creator}", 24),
                'description' => self::truncate("⏳ Awaiting your confirmation", 72),
            ];
        }

        return [
            [
                'title' => 'Pending Confirmations',
                'rows' => array_slice($rows, 0, 10),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Format message with placeholders.
     */
    public static function format(string $template, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $template = str_replace("{{$key}}", (string) $value, $template);
        }

        return $template;
    }

    /**
     * Format amount for display.
     */
    public static function formatAmount(float $amount): string
    {
        return '₹' . number_format($amount, 2);
    }

    /**
     * Get direction emoji.
     */
    public static function getDirectionEmoji(string $direction): string
    {
        return $direction === 'giving' ? '💸' : '💰';
    }

    /**
     * Get direction label.
     */
    public static function getDirectionLabel(string $direction, bool $isCreator = true): string
    {
        if ($isCreator) {
            return $direction === 'giving' ? 'You are giving' : 'You are receiving';
        }

        return $direction === 'giving' ? 'They are giving you' : 'They are receiving from you';
    }

    /**
     * Get purpose label.
     */
    public static function getPurposeLabel(string $purposeId): string
    {
        $map = [
            'loan' => '🏦 Loan',
            'advance' => '💵 Advance',
            'deposit' => '🏠 Deposit',
            'business' => '💼 Business',
            'personal' => '👤 Personal',
            'other' => '📋 Other',
        ];

        return $map[$purposeId] ?? ucfirst($purposeId);
    }

    /**
     * Get due date from selection.
     */
    public static function getDueDateFromSelection(string $selection): ?\Carbon\Carbon
    {
        return match ($selection) {
            '1week' => now()->addWeek(),
            '2weeks' => now()->addWeeks(2),
            '1month' => now()->addMonth(),
            '3months' => now()->addMonths(3),
            '6months' => now()->addMonths(6),
            'none' => null,
            default => null,
        };
    }

    /**
     * Format due date for display.
     */
    public static function formatDueDate(?\Carbon\Carbon $dueDate): string
    {
        if (!$dueDate) {
            return 'No fixed date';
        }

        return $dueDate->format('M j, Y');
    }

    /**
     * Get status icon.
     */
    public static function getStatusIcon(string $status): string
    {
        return match ($status) {
            'pending_counterparty' => '⏳',
            'pending_creator' => '⏳',
            'confirmed' => '✅',
            'rejected' => '❌',
            'disputed' => '⚠️',
            'completed' => '✔️',
            'expired' => '⏰',
            default => '📋',
        };
    }

    /**
     * Get status label.
     */
    public static function getStatusLabel(string $status): string
    {
        return match ($status) {
            'pending_counterparty' => '⏳ Awaiting confirmation',
            'pending_creator' => '⏳ Awaiting your confirmation',
            'confirmed' => '✅ Confirmed by both parties',
            'rejected' => '❌ Rejected',
            'disputed' => '⚠️ Disputed',
            'completed' => '✔️ Completed',
            'expired' => '⏰ Expired',
            default => ucfirst($status),
        };
    }

    /**
     * Format a future date for list description.
     */
    private static function formatFutureDate(int $days): string
    {
        return now()->addDays($days)->format('M j, Y');
    }

    /**
     * Truncate string.
     */
    private static function truncate(string $text, int $maxLength): string
    {
        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 1) . '…';
    }
}