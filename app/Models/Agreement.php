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
     * Scope to filter active agreements.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', AgreementStatus::ACTIVE);
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
            ->whereIn('status', [AgreementStatus::PENDING, AgreementStatus::ACTIVE]);
    }

    /**
     * Scope to find agreements awaiting confirmation from a phone.
     */
    public function scopeAwaitingConfirmationFrom(Builder $query, string $phone): Builder
    {
        return $query
            ->where('status', AgreementStatus::PENDING)
            ->where('to_phone', $phone)
            ->whereNull('to_confirmed_at');
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get formatted amount.
     */
    public function getFormattedAmountAttribute(): string
    {
        $currency = config('nearbuy.agreements.currency.symbol', '₹');
        return $currency . number_format($this->amount, 2);
    }

    /**
     * Check if agreement is pending.
     */
    public function isPending(): bool
    {
        return $this->status === AgreementStatus::PENDING;
    }

    /**
     * Check if agreement is active.
     */
    public function isActive(): bool
    {
        return $this->status === AgreementStatus::ACTIVE;
    }

    /**
     * Check if agreement is fully confirmed.
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
            in_array($this->status, [AgreementStatus::PENDING, AgreementStatus::ACTIVE]);
    }

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
            ];
        }

        return [
            'name' => $this->from_name,
            'phone' => $this->from_phone,
            'user_id' => $this->from_user_id,
        ];
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
     * Confirm the agreement by the from party.
     */
    public function confirmByCreator(): void
    {
        $this->update(['from_confirmed_at' => now()]);
        $this->checkAndActivate();
    }

    /**
     * Confirm the agreement by the to party.
     */
    public function confirmByRecipient(): void
    {
        $this->update(['to_confirmed_at' => now()]);
        $this->checkAndActivate();
    }

    /**
     * Check if both parties have confirmed and activate if so.
     */
    public function checkAndActivate(): void
    {
        if ($this->isFullyConfirmed() && $this->status === AgreementStatus::PENDING) {
            $this->update(['status' => AgreementStatus::ACTIVE]);
        }
    }

    /**
     * Mark agreement as completed.
     */
    public function markAsCompleted(?string $notes = null): void
    {
        if ($this->status->canBeCompleted()) {
            $this->update([
                'status' => AgreementStatus::COMPLETED,
                'completed_at' => now(),
                'completion_notes' => $notes,
            ]);
        }
    }

    /**
     * Mark agreement as disputed.
     */
    public function markAsDisputed(): void
    {
        if ($this->status->canBeDisputed()) {
            $this->update(['status' => AgreementStatus::DISPUTED]);
        }
    }

    /**
     * Cancel the agreement.
     */
    public function cancel(): void
    {
        if ($this->status->canBeCancelled()) {
            $this->update(['status' => AgreementStatus::CANCELLED]);
        }
    }

    /**
     * Set the PDF URL.
     */
    public function setPdfUrl(string $url): void
    {
        $this->update(['pdf_url' => $url]);
    }

    /**
     * Get verification URL for QR code.
     */
    public function getVerificationUrl(): string
    {
        return route('agreements.verify', ['uuid' => $this->verification_token]);
    }

    /**
     * Generate a unique agreement number.
     */
    public static function generateAgreementNumber(): string
    {
        $year = now()->format('Y');
        $prefix = "NB-AG-{$year}-";

        $lastAgreement = self::where('agreement_number', 'like', "{$prefix}%")
            ->orderBy('agreement_number', 'desc')
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
     * Convert amount to words (basic implementation).
     */
    public static function amountToWords(float $amount): string
    {
        $formatter = new \NumberFormatter('en_IN', \NumberFormatter::SPELLOUT);
        $words = $formatter->format((int) $amount);

        $decimal = round(($amount - floor($amount)) * 100);
        if ($decimal > 0) {
            $words .= ' and ' . $formatter->format($decimal) . ' paise';
        }

        return ucfirst($words) . ' only';
    }

    /**
     * Create a new agreement.
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
        // Check if recipient is a registered user
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
            'from_confirmed_at' => now(), // Creator confirms by creating
        ]);
    }
}