<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AgreementDirection;
use App\Enums\AgreementPurpose;
use App\Enums\AgreementStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Agreement Model.
 *
 * Digital records of informal financial transactions.
 *
 * @srs-ref Section 3.4 Digital Agreements
 * @srs-ref Section 6.2.3 agreements Table
 *
 * @property int $id
 * @property string $agreement_number
 * @property int $from_user_id
 * @property string $from_name
 * @property string $from_phone
 * @property int|null $to_user_id
 * @property string $to_name
 * @property string $to_phone
 * @property string $direction
 * @property float $amount
 * @property string $amount_in_words
 * @property AgreementPurpose $purpose_type
 * @property string|null $description
 * @property Carbon|null $due_date
 * @property AgreementStatus $status
 * @property Carbon|null $from_confirmed_at
 * @property Carbon|null $to_confirmed_at
 * @property string|null $pdf_url
 * @property string $verification_token
 * @property Carbon|null $completed_at
 * @property string|null $rejection_reason
 * @property Carbon|null $reminder_sent_at
 * @property Carbon|null $expires_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Agreement extends Model
{
    use HasFactory;

    protected $fillable = [
        'agreement_number',
        'from_user_id',
        'from_name',
        'from_phone',
        'to_user_id',
        'to_name',
        'to_phone',
        'direction',
        'amount',
        'amount_in_words',
        'purpose_type',
        'description',
        'due_date',
        'status',
        'from_confirmed_at',
        'to_confirmed_at',
        'pdf_url',
        'verification_token',
        'completed_at',
        'rejection_reason',
        'reminder_sent_at',
        'expires_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'status' => AgreementStatus::class,
        'purpose_type' => AgreementPurpose::class,
        'due_date' => 'date',
        'from_confirmed_at' => 'datetime',
        'to_confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
        'reminder_sent_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    protected $hidden = [
        'verification_token',
    ];

    /*
    |--------------------------------------------------------------------------
    | Boot
    |--------------------------------------------------------------------------
    */

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (self $agreement) {
            // Generate agreement number (FR-AGR-10)
            if (empty($agreement->agreement_number)) {
                $agreement->agreement_number = self::generateAgreementNumber();
            }

            // Generate verification token
            if (empty($agreement->verification_token)) {
                $agreement->verification_token = Str::random(64);
            }

            // Calculate amount in words (FR-AGR-22)
            if (empty($agreement->amount_in_words) && $agreement->amount) {
                $agreement->amount_in_words = self::amountToWords($agreement->amount);
            }

            // Set expiry (7 days for confirmation)
            if (empty($agreement->expires_at)) {
                $agreement->expires_at = now()->addDays(7);
            }
        });

        static::updating(function (self $agreement) {
            // Recalculate amount in words if changed
            if ($agreement->isDirty('amount')) {
                $agreement->amount_in_words = self::amountToWords($agreement->amount);
            }
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Creator (from party).
     */
    public function fromUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'from_user_id');
    }

    /**
     * Alias for fromUser.
     */
    public function creator(): BelongsTo
    {
        return $this->fromUser();
    }

    /**
     * Recipient (to party) - may be null for unregistered users.
     *
     * @srs-ref FR-AGR-13 Works for unregistered counterparties
     */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AgreementStatus::PENDING);
    }

    public function scopeConfirmed(Builder $query): Builder
    {
        return $query->where('status', AgreementStatus::CONFIRMED);
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', AgreementStatus::COMPLETED);
    }

    /**
     * Agreements involving a user (as creator or recipient).
     */
    public function scopeInvolvingUser(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;
        return $query->where(function (Builder $q) use ($userId) {
            $q->where('from_user_id', $userId)
                ->orWhere('to_user_id', $userId);
        });
    }

    /**
     * Agreements involving a phone number.
     *
     * @srs-ref FR-AGR-13 Works for unregistered users via phone
     */
    public function scopeInvolvingPhone(Builder $query, string $phone): Builder
    {
        return $query->where(function (Builder $q) use ($phone) {
            $q->where('from_phone', $phone)
                ->orWhere('to_phone', $phone);
        });
    }

    /**
     * Pending confirmations for a phone number.
     *
     * @srs-ref FR-AGR-12, FR-AGR-13
     */
    public function scopeAwaitingConfirmationFrom(Builder $query, string $phone): Builder
    {
        return $query
            ->where('status', AgreementStatus::PENDING)
            ->where('to_phone', $phone)
            ->whereNull('to_confirmed_at');
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereIn('status', [AgreementStatus::PENDING, AgreementStatus::CONFIRMED]);
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get direction as enum.
     */
    public function getDirectionEnumAttribute(): AgreementDirection
    {
        return AgreementDirection::tryFrom($this->direction) ?? AgreementDirection::GIVING;
    }

    /**
     * Get purpose as enum (alias for purpose_type when cast).
     */
    public function getPurposeEnumAttribute(): AgreementPurpose
    {
        // Already cast to enum via $casts
        if ($this->purpose_type instanceof AgreementPurpose) {
            return $this->purpose_type;
        }
        // Fallback for raw string access
        return AgreementPurpose::tryFrom($this->purpose_type) ?? AgreementPurpose::OTHER;
    }

    /**
     * Formatted amount.
     */
    public function getFormattedAmountAttribute(): string
    {
        return '₹' . number_format($this->amount, 2);
    }

    /**
     * Short amount (no decimals for whole numbers).
     */
    public function getShortAmountAttribute(): string
    {
        $amount = $this->amount == floor($this->amount)
            ? number_format($this->amount, 0)
            : number_format($this->amount, 2);
        return '₹' . $amount;
    }

    /**
     * Days until due.
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        if (!$this->due_date) return null;
        return (int) now()->startOfDay()->diffInDays($this->due_date->startOfDay(), false);
    }

    /**
     * Due status text.
     */
    public function getDueStatusAttribute(): string
    {
        if (!$this->due_date) return 'No due date';

        $days = $this->days_until_due;
        if ($days < 0) {
            return abs($days) . ' day(s) overdue';
        } elseif ($days === 0) {
            return 'Due today';
        } elseif ($days === 1) {
            return 'Due tomorrow';
        }
        return "Due in {$days} days";
    }

    /*
    |--------------------------------------------------------------------------
    | Status Checks
    |--------------------------------------------------------------------------
    */

    public function isPending(): bool
    {
        return $this->status === AgreementStatus::PENDING;
    }

    public function isConfirmed(): bool
    {
        return $this->status === AgreementStatus::CONFIRMED;
    }

    public function isCompleted(): bool
    {
        return $this->status === AgreementStatus::COMPLETED;
    }

    /**
     * Check if BOTH parties have confirmed.
     *
     * @srs-ref FR-AGR-15 Active upon both confirmations
     */
    public function isFullyConfirmed(): bool
    {
        return $this->from_confirmed_at !== null && $this->to_confirmed_at !== null;
    }

    /**
     * Check if expired (confirmation period passed).
     */
    public function isExpired(): bool
    {
        if ($this->status !== AgreementStatus::PENDING) {
            return false;
        }
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function isOverdue(): bool
    {
        return $this->due_date?->isPast() ?? false;
    }

    public function hasPdf(): bool
    {
        return !empty($this->pdf_url);
    }

    /*
    |--------------------------------------------------------------------------
    | Party Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check if phone is the creator.
     */
    public function isCreator(string $phone): bool
    {
        return $this->from_phone === $phone;
    }

    /**
     * Check if phone is the recipient.
     */
    public function isRecipient(string $phone): bool
    {
        return $this->to_phone === $phone;
    }

    /**
     * Check if phone is involved.
     */
    public function involvesPhone(string $phone): bool
    {
        return $this->from_phone === $phone || $this->to_phone === $phone;
    }

    /**
     * Get role for a phone number.
     *
     * @srs-ref FR-AGR-01 Direction (Giving/Receiving)
     */
    public function getRoleForPhone(string $phone): string
    {
        $isCreator = $this->isCreator($phone);
        $isGiving = $this->direction === 'giving';

        if ($isCreator) {
            return $isGiving ? 'Giving' : 'Receiving';
        }
        return $isGiving ? 'Receiving' : 'Giving';
    }

    /**
     * Get other party info for a phone.
     */
    public function getOtherParty(string $phone): array
    {
        if ($this->isCreator($phone)) {
            return [
                'name' => $this->to_name,
                'phone' => $this->to_phone,
                'is_creator' => false,
            ];
        }
        return [
            'name' => $this->from_name,
            'phone' => $this->from_phone,
            'is_creator' => true,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    /**
     * Confirm by creator.
     *
     * @srs-ref FR-AGR-11 Mark creator as confirmed with timestamp
     */
    public function confirmByCreator(): void
    {
        $this->update(['from_confirmed_at' => now()]);
        $this->checkAndActivate();
    }

    /**
     * Confirm by recipient.
     *
     * @srs-ref FR-AGR-15 Mark active upon both confirmations
     */
    public function confirmByRecipient(): void
    {
        $this->update(['to_confirmed_at' => now()]);
        $this->checkAndActivate();
    }

    /**
     * Confirm by phone number.
     */
    public function confirmByPhone(string $phone): bool
    {
        if ($this->isCreator($phone)) {
            $this->confirmByCreator();
            return true;
        }
        if ($this->isRecipient($phone)) {
            $this->confirmByRecipient();
            return true;
        }
        return false;
    }

    /**
     * Check if both confirmed and activate.
     *
     * @srs-ref FR-AGR-15 Mark active upon both confirmations
     */
    public function checkAndActivate(): void
    {
        if ($this->isFullyConfirmed() && $this->status === AgreementStatus::PENDING) {
            $this->update(['status' => AgreementStatus::CONFIRMED]);
        }
    }

    /**
     * Mark as completed.
     */
    public function markAsCompleted(?string $notes = null): bool
    {
        if (!$this->status->canBeCompleted()) {
            return false;
        }

        $this->update([
            'status' => AgreementStatus::COMPLETED,
            'completed_at' => now(),
        ]);
        return true;
    }

    /**
     * Mark as rejected.
     */
    public function markAsRejected(?string $reason = null): bool
    {
        if (!$this->status->canBeRejected()) {
            return false;
        }

        $this->update([
            'status' => AgreementStatus::REJECTED,
            'rejection_reason' => $reason,
        ]);
        return true;
    }

    /**
     * Mark as disputed.
     */
    public function markAsDisputed(?string $reason = null): bool
    {
        if (!$this->status->canBeDisputed()) {
            return false;
        }

        $this->update([
            'status' => AgreementStatus::DISPUTED,
            'rejection_reason' => $reason,
        ]);
        return true;
    }

    /**
     * Cancel the agreement.
     */
    public function cancel(): bool
    {
        if (!$this->status->canBeCancelled()) {
            return false;
        }

        $this->update(['status' => AgreementStatus::CANCELLED]);
        return true;
    }

    /**
     * Set PDF URL.
     *
     * @srs-ref FR-AGR-24 Store PDF in cloud storage
     */
    public function setPdfUrl(string $url): void
    {
        $this->update(['pdf_url' => $url]);
    }

    /*
    |--------------------------------------------------------------------------
    | URL Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get verification URL for QR code.
     *
     * @srs-ref FR-AGR-23 QR code linking to verification URL
     */
    public function getVerificationUrl(): string
    {
        $baseUrl = config('app.url');
        return "{$baseUrl}/verify/{$this->verification_token}";
    }

    /*
    |--------------------------------------------------------------------------
    | Static Generators
    |--------------------------------------------------------------------------
    */

    /**
     * Generate unique agreement number.
     *
     * @srs-ref FR-AGR-10 Format: NB-AG-YYYY-XXXX
     */
    public static function generateAgreementNumber(): string
    {
        $year = now()->format('Y');
        $prefix = "NB-AG-{$year}-";

        // Get last number this year
        $last = self::where('agreement_number', 'like', "{$prefix}%")
            ->orderByRaw('CAST(SUBSTRING(agreement_number, -4) AS UNSIGNED) DESC')
            ->value('agreement_number');

        if ($last) {
            $lastNum = (int) substr($last, -4);
            $newNum = str_pad((string) ($lastNum + 1), 4, '0', STR_PAD_LEFT);
        } else {
            $newNum = '0001';
        }

        return $prefix . $newNum;
    }

    /**
     * Convert amount to words (Indian format).
     *
     * @srs-ref FR-AGR-22 Amount in words (e.g., Rupees Twenty Thousand Only)
     */
    public static function amountToWords(float $amount): string
    {
        if ($amount == 0) {
            return 'Rupees Zero Only';
        }

        $amount = round($amount, 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $ones = [
            '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
            'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
            'Seventeen', 'Eighteen', 'Nineteen',
        ];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $words = 'Rupees ';

        // Crores
        if ($rupees >= 10000000) {
            $words .= self::twoDigitWords((int) floor($rupees / 10000000), $ones, $tens) . ' Crore ';
            $rupees %= 10000000;
        }

        // Lakhs
        if ($rupees >= 100000) {
            $words .= self::twoDigitWords((int) floor($rupees / 100000), $ones, $tens) . ' Lakh ';
            $rupees %= 100000;
        }

        // Thousands
        if ($rupees >= 1000) {
            $words .= self::twoDigitWords((int) floor($rupees / 1000), $ones, $tens) . ' Thousand ';
            $rupees %= 1000;
        }

        // Hundreds
        if ($rupees >= 100) {
            $words .= $ones[(int) floor($rupees / 100)] . ' Hundred ';
            $rupees %= 100;
        }

        // Tens and ones
        if ($rupees > 0) {
            $words .= self::twoDigitWords($rupees, $ones, $tens);
        }

        // Paise
        if ($paise > 0) {
            $words .= ' and ' . self::twoDigitWords($paise, $ones, $tens) . ' Paise';
        }

        return trim($words) . ' Only';
    }

    /**
     * Convert two-digit number to words.
     */
    private static function twoDigitWords(int $num, array $ones, array $tens): string
    {
        if ($num < 20) {
            return $ones[$num];
        }
        $result = $tens[(int) floor($num / 10)];
        if ($num % 10 > 0) {
            $result .= '-' . $ones[$num % 10];
        }
        return $result;
    }

    /**
     * Get data for PDF generation.
     *
     * @srs-ref FR-AGR-21, FR-AGR-22
     */
    public function toPdfData(): array
    {
        return [
            'agreement_number' => $this->agreement_number,
            'from_name' => $this->from_name,
            'from_phone' => $this->from_phone,
            'to_name' => $this->to_name,
            'to_phone' => $this->to_phone,
            'direction' => $this->direction,
            'amount' => $this->formatted_amount,
            'amount_numeric' => $this->amount,
            'amount_in_words' => $this->amount_in_words,
            'purpose' => $this->purpose_enum->label(),
            'purpose_icon' => $this->purpose_enum->icon(),
            'description' => $this->description,
            'due_date' => $this->due_date?->format('d F Y'),
            'created_at' => $this->created_at->format('d F Y \a\t H:i'),
            'from_confirmed_at' => $this->from_confirmed_at?->format('d F Y \a\t H:i'),
            'to_confirmed_at' => $this->to_confirmed_at?->format('d F Y \a\t H:i'),
            'status' => $this->status->label(),
            'verification_url' => $this->getVerificationUrl(),
        ];
    }
}