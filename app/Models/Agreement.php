<?php

namespace App\Models;

use App\Enums\AgreementStatus;
use App\Enums\AgreementPurpose;
use App\Enums\AgreementDirection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Agreement Model
 *
 * Represents digital records of informal financial transactions.
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
 * @property AgreementDirection $direction
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
 * @property string|null $completion_notes
 * @property Carbon|null $disputed_at
 * @property string|null $disputed_by
 * @property string|null $dispute_reason
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read User $fromUser
 * @property-read User|null $toUser
 * @property-read string $formatted_amount
 * @property-read string $short_amount
 * @property-read int|null $days_until_due
 * @property-read string $due_status
 */
class Agreement extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
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
        'completion_notes',
        'disputed_at',
        'disputed_by',
        'dispute_reason',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'direction' => AgreementDirection::class,
        'amount' => 'decimal:2',
        'purpose_type' => AgreementPurpose::class,
        'status' => AgreementStatus::class,
        'due_date' => 'date',
        'from_confirmed_at' => 'datetime',
        'to_confirmed_at' => 'datetime',
        'completed_at' => 'datetime',
        'disputed_at' => 'datetime',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'verification_token',
    ];

    /**
     * The accessors to append to the model's array form.
     *
     * @var array<int, string>
     */
    protected $appends = [
        'formatted_amount',
        'short_amount',
        'due_status',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($agreement) {
            if (empty($agreement->agreement_number)) {
                $agreement->agreement_number = self::generateAgreementNumber();
            }

            if (empty($agreement->verification_token)) {
                $agreement->verification_token = Str::random(64);
            }

            // Auto-calculate amount in words if not set
            if (empty($agreement->amount_in_words) && $agreement->amount) {
                $agreement->amount_in_words = self::amountToWords($agreement->amount);
            }
        });

        static::updating(function ($agreement) {
            // Recalculate amount in words if amount changed
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
     * Get the user who created this agreement (from party).
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
     * Get the recipient user (to party).
     */
    public function toUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'to_user_id');
    }

    /**
     * Alias for toUser.
     */
    public function recipient(): BelongsTo
    {
        return $this->toUser();
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to filter by status.
     */
    public function scopeWithStatus(Builder $query, AgreementStatus $status): Builder
    {
        return $query->where('status', $status);
    }

    /**
     * Scope to filter pending agreements.
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', AgreementStatus::PENDING);
    }

    /**
     * Scope to filter active/confirmed agreements.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AgreementStatus::CONFIRMED);
    }

    /**
     * Scope to filter completed agreements.
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', AgreementStatus::COMPLETED);
    }

    /**
     * Scope to filter disputed agreements.
     */
    public function scopeDisputed(Builder $query): Builder
    {
        return $query->where('status', AgreementStatus::DISPUTED);
    }

    /**
     * Scope to filter cancelled agreements.
     */
    public function scopeCancelled(Builder $query): Builder
    {
        return $query->where('status', AgreementStatus::CANCELLED);
    }

    /**
     * Scope to find agreements involving a user (as from or to).
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
     * Scope to find agreements involving a phone number.
     */
    public function scopeInvolvingPhone(Builder $query, string $phone): Builder
    {
        return $query->where(function (Builder $q) use ($phone) {
            $q->where('from_phone', $phone)
                ->orWhere('to_phone', $phone);
        });
    }

    /**
     * Scope to find agreements created by a user.
     */
    public function scopeCreatedBy(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;
        return $query->where('from_user_id', $userId);
    }

    /**
     * Scope to find agreements received by a user.
     */
    public function scopeReceivedBy(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;
        return $query->where('to_user_id', $userId);
    }

    /**
     * Scope to filter by purpose type.
     */
    public function scopeOfPurpose(Builder $query, AgreementPurpose $purpose): Builder
    {
        return $query->where('purpose_type', $purpose);
    }

    /**
     * Scope to filter by direction.
     */
    public function scopeWithDirection(Builder $query, AgreementDirection $direction): Builder
    {
        return $query->where('direction', $direction);
    }

    /**
     * Scope to find agreements due within a period.
     */
    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query
            ->whereNotNull('due_date')
            ->where('due_date', '<=', now()->addDays($days))
            ->where('due_date', '>=', now());
    }

    /**
     * Scope to find overdue agreements.
     */
    public function scopeOverdue(Builder $query): Builder
    {
        return $query
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->whereIn('status', [AgreementStatus::PENDING, AgreementStatus::CONFIRMED]);
    }

    /**
     * Scope to find agreements awaiting confirmation from a phone.
     *
     * @srs-ref FR-AGR-12 Confirmation request to counterparty
     */
    public function scopeAwaitingConfirmationFrom(Builder $query, string $phone): Builder
    {
        return $query
            ->where('status', AgreementStatus::PENDING)
            ->where('to_phone', $phone)
            ->whereNull('to_confirmed_at');
    }

    /**
     * Scope to find agreements needing reminder.
     *
     * @srs-ref Notification scheduling
     */
    public function scopeNeedingReminder(Builder $query, int $daysBefore = 3): Builder
    {
        return $query
            ->where('status', AgreementStatus::CONFIRMED)
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now(), now()->addDays($daysBefore)]);
    }

    /**
     * Scope to find recently created agreements.
     */
    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * Scope to exclude cancelled agreements.
     */
    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->where('status', '!=', AgreementStatus::CANCELLED);
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get formatted amount with currency symbol.
     */
    public function getFormattedAmountAttribute(): string
    {
        $currency = config('nearbuy.agreements.currency.symbol', '₹');
        return $currency . number_format($this->amount, 2);
    }

    /**
     * Get short formatted amount (no decimals for whole numbers).
     */
    public function getShortAmountAttribute(): string
    {
        $currency = config('nearbuy.agreements.currency.symbol', '₹');
        $amount = $this->amount == floor($this->amount)
            ? number_format($this->amount, 0)
            : number_format($this->amount, 2);
        return $currency . $amount;
    }

    /**
     * Get days until due date.
     */
    public function getDaysUntilDueAttribute(): ?int
    {
        if (!$this->due_date) {
            return null;
        }
        return now()->startOfDay()->diffInDays($this->due_date->startOfDay(), false);
    }

    /**
     * Get human-readable due status.
     */
    public function getDueStatusAttribute(): string
    {
        if (!$this->due_date) {
            return 'No due date';
        }

        $days = $this->days_until_due;

        if ($days < 0) {
            $absDays = abs($days);
            return $absDays === 1 ? '1 day overdue' : "{$absDays} days overdue";
        } elseif ($days === 0) {
            return 'Due today';
        } elseif ($days === 1) {
            return 'Due tomorrow';
        } else {
            return "Due in {$days} days";
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Status Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if agreement is pending.
     */
    public function isPending(): bool
    {
        return $this->status === AgreementStatus::PENDING;
    }

    /**
     * Check if agreement is active/confirmed.
     */
    public function isActive(): bool
    {
        return $this->status === AgreementStatus::CONFIRMED;
    }

    /**
     * Check if agreement is completed.
     */
    public function isCompleted(): bool
    {
        return $this->status === AgreementStatus::COMPLETED;
    }

    /**
     * Check if agreement is disputed.
     */
    public function isDisputed(): bool
    {
        return $this->status === AgreementStatus::DISPUTED;
    }

    /**
     * Check if agreement is cancelled.
     */
    public function isCancelled(): bool
    {
        return $this->status === AgreementStatus::CANCELLED;
    }

    /**
     * Check if agreement is fully confirmed.
     *
     * @srs-ref FR-AGR-15 Active upon both confirmations
     */
    public function isFullyConfirmed(): bool
    {
        return $this->from_confirmed_at !== null && $this->to_confirmed_at !== null;
    }

    /**
     * Check if agreement is overdue.
     */
    public function isOverdue(): bool
    {
        return $this->due_date !== null &&
            $this->due_date->isPast() &&
            in_array($this->status, [AgreementStatus::PENDING, AgreementStatus::CONFIRMED]);
    }

    /**
     * Check if agreement can be modified.
     */
    public function canBeModified(): bool
    {
        return $this->isPending() && $this->to_confirmed_at === null;
    }

    /**
     * Check if agreement can be completed.
     */
    public function canBeCompleted(): bool
    {
        return $this->status->canBeCompleted();
    }

    /**
     * Check if agreement can be disputed.
     */
    public function canBeDisputed(): bool
    {
        return $this->status->canBeDisputed();
    }

    /**
     * Check if agreement can be cancelled.
     */
    public function canBeCancelled(): bool
    {
        return $this->status->canBeCancelled();
    }

    /**
     * Check if PDF has been generated.
     */
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
     * Get the other party for a given user.
     */
    public function getOtherParty(User|string $userOrPhone): array
    {
        $identifier = $userOrPhone instanceof User ? $userOrPhone->phone : $userOrPhone;

        if ($this->from_phone === $identifier) {
            return [
                'name' => $this->to_name,
                'phone' => $this->to_phone,
                'user_id' => $this->to_user_id,
                'is_creator' => false,
            ];
        }

        return [
            'name' => $this->from_name,
            'phone' => $this->from_phone,
            'user_id' => $this->from_user_id,
            'is_creator' => true,
        ];
    }

    /**
     * Get role label for a phone number.
     *
     * @srs-ref FR-AGR-01 Direction (Giving/Receiving)
     */
    public function getRoleForPhone(string $phone): string
    {
        $isCreator = $this->from_phone === $phone;
        $isGiving = $this->direction === AgreementDirection::GIVING;

        if ($isCreator) {
            return $isGiving ? 'Giving' : 'Receiving';
        } else {
            return $isGiving ? 'Receiving' : 'Giving';
        }
    }

    /**
     * Get role icon for a phone number.
     */
    public function getRoleIconForPhone(string $phone): string
    {
        $role = $this->getRoleForPhone($phone);
        return $role === 'Giving' ? '💸' : '💰';
    }

    /**
     * Check if a phone is the creator.
     */
    public function isCreator(string $phone): bool
    {
        return $this->from_phone === $phone;
    }

    /**
     * Check if a phone is the recipient.
     */
    public function isRecipient(string $phone): bool
    {
        return $this->to_phone === $phone;
    }

    /**
     * Check if phone is involved in this agreement.
     */
    public function involvesPhone(string $phone): bool
    {
        return $this->from_phone === $phone || $this->to_phone === $phone;
    }

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    */

    /**
     * Confirm the agreement by the from party.
     *
     * @srs-ref FR-AGR-11 Creator confirmed with timestamp
     */
    public function confirmByCreator(): void
    {
        $this->update(['from_confirmed_at' => now()]);
        $this->checkAndActivate();
    }

    /**
     * Confirm the agreement by the to party.
     *
     * @srs-ref FR-AGR-15 Active upon both confirmations
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
     * Check if both parties have confirmed and activate if so.
     */
    public function checkAndActivate(): void
    {
        if ($this->isFullyConfirmed() && $this->status === AgreementStatus::PENDING) {
            $this->update(['status' => AgreementStatus::CONFIRMED]);
        }
    }

    /**
     * Mark agreement as completed.
     */
    public function markAsCompleted(?string $notes = null): bool
    {
        if (!$this->status->canBeCompleted()) {
            return false;
        }

        $this->update([
            'status' => AgreementStatus::COMPLETED,
            'completed_at' => now(),
            'completion_notes' => $notes,
        ]);

        return true;
    }

    /**
     * Mark agreement as disputed.
     *
     * @param string $phone Phone of user raising dispute
     * @param string|null $reason Reason for dispute
     */
    public function markAsDisputed(string $phone, ?string $reason = null): bool
    {
        if (!$this->status->canBeDisputed()) {
            return false;
        }

        $this->update([
            'status' => AgreementStatus::DISPUTED,
            'disputed_at' => now(),
            'disputed_by' => $phone,
            'dispute_reason' => $reason,
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
     * Set the PDF URL.
     *
     * @srs-ref FR-AGR-24 Store PDF in cloud storage
     */
    public function setPdfUrl(string $url): void
    {
        $this->update(['pdf_url' => $url]);
    }

    /*
    |--------------------------------------------------------------------------
    | Message & Display Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get verification URL for QR code.
     *
     * @srs-ref FR-AGR-23 QR code linking to verification URL
     */
    public function getVerificationUrl(): string
    {
        return route('agreements.verify', ['token' => $this->verification_token]);
    }

    /**
     * Get summary for WhatsApp message.
     */
    public function getSummaryForMessage(string $forPhone): string
    {
        $role = $this->getRoleForPhone($forPhone);
        $roleIcon = $this->getRoleIconForPhone($forPhone);
        $otherParty = $this->getOtherParty($forPhone);

        $lines = [
            "📋 *Agreement #{$this->agreement_number}*",
            "",
            "💰 Amount: *{$this->formatted_amount}*",
            "📝 Purpose: {$this->purpose_type->label()}",
            "{$roleIcon} {$role} " . ($role === 'Giving' ? 'to' : 'from') . ": {$otherParty['name']}",
        ];

        if ($this->description) {
            $lines[] = "📄 Notes: {$this->description}";
        }

        if ($this->due_date) {
            $lines[] = "📅 Due: {$this->due_date->format('d M Y')} ({$this->due_status})";
        }

        $lines[] = "";
        $lines[] = "Status: {$this->status->icon()} {$this->status->label()}";

        return implode("\n", $lines);
    }

    /**
     * Get short summary for list display.
     */
    public function getShortSummary(string $forPhone): string
    {
        $role = $this->getRoleForPhone($forPhone);
        $otherParty = $this->getOtherParty($forPhone);

        return "{$this->short_amount} - {$role} " .
            ($role === 'Giving' ? 'to' : 'from') .
            " {$otherParty['name']}";
    }

    /**
     * Get list item for WhatsApp list message.
     */
    public function toListItem(string $forPhone): array
    {
        return [
            'id' => 'agreement_' . $this->id,
            'title' => $this->short_amount . ' - ' . $this->purpose_type->label(),
            'description' => $this->getShortSummary($forPhone),
        ];
    }

    /**
     * Get data array for PDF generation.
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
            'direction' => $this->direction->label(),
            'amount' => $this->formatted_amount,
            'amount_numeric' => $this->amount,
            'amount_in_words' => $this->amount_in_words,
            'purpose' => $this->purpose_type->label(),
            'purpose_icon' => $this->purpose_type->icon(),
            'description' => $this->description,
            'due_date' => $this->due_date?->format('d F Y'),
            'due_date_formatted' => $this->due_date?->format('d/m/Y'),
            'created_at' => $this->created_at->format('d F Y \a\t H:i'),
            'from_confirmed_at' => $this->from_confirmed_at?->format('d F Y \a\t H:i'),
            'to_confirmed_at' => $this->to_confirmed_at?->format('d F Y \a\t H:i'),
            'status' => $this->status->label(),
            'verification_url' => $this->getVerificationUrl(),
            'verification_token' => $this->verification_token,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Static Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Generate a unique agreement number.
     *
     * @srs-ref FR-AGR-10 Format: NB-AG-YYYY-XXXX
     */
    public static function generateAgreementNumber(): string
    {
        $year = now()->format('Y');
        $prefix = "NB-AG-{$year}-";

        $lastAgreement = self::where('agreement_number', 'like', "{$prefix}%")
            ->orderByRaw('CAST(SUBSTRING(agreement_number, -4) AS UNSIGNED) DESC')
            ->first();

        if ($lastAgreement) {
            $lastNumber = (int) substr($lastAgreement->agreement_number, -4);
            $newNumber = str_pad($lastNumber + 1, 4, '0', STR_PAD_LEFT);
        } else {
            $newNumber = '0001';
        }

        return $prefix . $newNumber;
    }

    /**
     * Convert amount to words.
     *
     * @srs-ref FR-AGR-22 Amount in words (e.g., Rupees Twenty Thousand Only)
     */
    public static function amountToWords(float $amount): string
    {
        $formatter = new \NumberFormatter('en_IN', \NumberFormatter::SPELLOUT);
        $words = $formatter->format((int) $amount);

        $decimal = round(($amount - floor($amount)) * 100);
        if ($decimal > 0) {
            $words .= ' and ' . $formatter->format($decimal) . ' paise';
        }

        $currency = config('nearbuy.agreements.currency.name', 'Rupees');
        return $currency . ' ' . ucfirst($words) . ' only';
    }

    /**
     * Calculate due date from option ID.
     *
     * @srs-ref SRS 8.4 Due Date Options
     */
    public static function calculateDueDate(string $optionId): ?Carbon
    {
        return match ($optionId) {
            'due_1week' => now()->addDays(7),
            'due_2weeks' => now()->addDays(14),
            'due_1month' => now()->addDays(30),
            'due_3months' => now()->addDays(90),
            'due_none' => null,
            default => null,
        };
    }

    /**
     * Create a new agreement.
     *
     * @srs-ref FR-AGR-01 to FR-AGR-11
     */
    public static function createNew(
        User $fromUser,
        string $toName,
        string $toPhone,
        AgreementDirection $direction,
        float $amount,
        AgreementPurpose $purpose,
        ?string $description = null,
        ?Carbon $dueDate = null
    ): self {
        // Check if recipient is a registered user (FR-AGR-13)
        $toUser = User::where('phone', $toPhone)->first();

        return self::create([
            'from_user_id' => $fromUser->id,
            'from_name' => $fromUser->name ?? 'Unknown',
            'from_phone' => $fromUser->phone,
            'to_user_id' => $toUser?->id,
            'to_name' => $toName,
            'to_phone' => $toPhone,
            'direction' => $direction,
            'amount' => $amount,
            'amount_in_words' => self::amountToWords($amount),
            'purpose_type' => $purpose,
            'description' => $description,
            'due_date' => $dueDate,
            'status' => AgreementStatus::PENDING,
            'from_confirmed_at' => now(), // Creator confirms by creating (FR-AGR-11)
        ]);
    }

    /**
     * Get statistics for a user.
     */
    public static function getStatsForPhone(string $phone): array
    {
        $query = self::involvingPhone($phone);

        return [
            'total' => $query->count(),
            'pending' => (clone $query)->pending()->count(),
            'active' => (clone $query)->active()->count(),
            'completed' => (clone $query)->completed()->count(),
            'disputed' => (clone $query)->disputed()->count(),
            'overdue' => (clone $query)->overdue()->count(),
            'total_giving' => (clone $query)
                ->where(function ($q) use ($phone) {
                    $q->where('from_phone', $phone)->where('direction', AgreementDirection::GIVING)
                        ->orWhere('to_phone', $phone)->where('direction', AgreementDirection::RECEIVING);
                })
                ->sum('amount'),
            'total_receiving' => (clone $query)
                ->where(function ($q) use ($phone) {
                    $q->where('from_phone', $phone)->where('direction', AgreementDirection::RECEIVING)
                        ->orWhere('to_phone', $phone)->where('direction', AgreementDirection::GIVING);
                })
                ->sum('amount'),
        ];
    }

    /**
     * Check if agreement confirmation has expired.
     * Agreements expire after 7 days if not confirmed.
     */
    public function isExpired(): bool
    {
        // Only pending agreements can expire
        if ($this->status !== AgreementStatus::PENDING) {
            return false;
        }

        // Check if agreement is older than expiry period (default: 7 days)
        $expiryDays = config('nearbuy.agreements.expiry_days', 7);
        
        return $this->created_at->addDays($expiryDays)->isPast();
    }
}