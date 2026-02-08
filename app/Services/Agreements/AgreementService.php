<?php

declare(strict_types=1);

namespace App\Services\Agreements;

use App\Enums\AgreementPurpose;
use App\Enums\AgreementStatus;
use App\Models\Agreement;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Agreement Service.
 *
 * Handles agreement creation, confirmation, and lifecycle.
 *
 * @srs-ref FR-AGR-01 to FR-AGR-25
 */
class AgreementService
{
    /*
    |--------------------------------------------------------------------------
    | Agreement Creation (FR-AGR-01 to FR-AGR-08)
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new agreement.
     *
     * @srs-ref FR-AGR-10 - Generate unique agreement number
     * @srs-ref FR-AGR-11 - Mark creator as confirmed
     */
    public function createAgreement(User $creator, array $data): Agreement
    {
        $otherPhone = $this->normalizePhone($data['other_party_phone']);

        // Prevent self-agreement
        if ($otherPhone === $creator->phone) {
            throw new \InvalidArgumentException('Cannot create agreement with yourself');
        }

        // Check if counterparty is registered
        $counterparty = User::where('phone', $otherPhone)->first();

        // Parse purpose
        $purpose = AgreementPurpose::tryFrom($data['purpose'] ?? 'other') 
            ?? AgreementPurpose::OTHER;

        // Generate agreement number (FR-AGR-10: NB-AG-YYYY-XXXX)
        $agreementNumber = $this->generateAgreementNumber();

        $agreement = Agreement::create([
            'agreement_number' => $agreementNumber,
            
            // Creator (from)
            'from_user_id' => $creator->id,
            'from_name' => $creator->name ?? 'Unknown',
            'from_phone' => $creator->phone,
            
            // Counterparty (to)
            'to_user_id' => $counterparty?->id,
            'to_name' => trim($data['other_party_name']),
            'to_phone' => $otherPhone,
            
            // Direction
            'direction' => $data['direction'] ?? 'giving',
            
            // Financial details
            'amount' => (float) $data['amount'],
            'amount_in_words' => $this->amountToWords((float) $data['amount']),
            'purpose_type' => $purpose,
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,
            
            // Status (FR-AGR-11: creator confirmed)
            'status' => AgreementStatus::PENDING,
            'from_confirmed_at' => now(),
            'to_confirmed_at' => null,
            
            // Verification
            'verification_token' => Str::random(32),
            'expires_at' => now()->addDays(7),
        ]);

        Log::info('Agreement created', [
            'agreement_id' => $agreement->id,
            'agreement_number' => $agreementNumber,
            'creator_id' => $creator->id,
            'amount' => $data['amount'],
        ]);

        return $agreement;
    }

    /**
     * Generate unique agreement number.
     *
     * @srs-ref FR-AGR-10 - Format: NB-AG-YYYY-XXXX
     */
    public function generateAgreementNumber(): string
    {
        $year = now()->format('Y');

        do {
            $random = strtoupper(Str::random(4));
            $number = "NB-AG-{$year}-{$random}";
        } while (Agreement::where('agreement_number', $number)->exists());

        return $number;
    }

    /*
    |--------------------------------------------------------------------------
    | Confirmation (FR-AGR-10 to FR-AGR-15)
    |--------------------------------------------------------------------------
    */

    /**
     * Confirm by counterparty.
     *
     * @srs-ref FR-AGR-15 - Mark agreement as active
     */
    public function confirmByCounterparty(Agreement $agreement): Agreement
    {
        if ($agreement->status !== AgreementStatus::PENDING) {
            throw new \RuntimeException('Agreement is not pending');
        }

        if ($agreement->isExpired()) {
            throw new \RuntimeException('Agreement has expired');
        }

        $agreement->update([
            'status' => AgreementStatus::CONFIRMED,
            'to_confirmed_at' => now(),
        ]);

        Log::info('Agreement confirmed', [
            'agreement_id' => $agreement->id,
            'agreement_number' => $agreement->agreement_number,
        ]);

        return $agreement->fresh();
    }

    /**
     * Reject by counterparty.
     */
    public function rejectByCounterparty(Agreement $agreement, ?string $reason = null): Agreement
    {
        if ($agreement->status !== AgreementStatus::PENDING) {
            throw new \RuntimeException('Agreement is not pending');
        }

        $agreement->update([
            'status' => AgreementStatus::REJECTED,
            'rejection_reason' => $reason,
        ]);

        Log::info('Agreement rejected', [
            'agreement_id' => $agreement->id,
            'reason' => $reason,
        ]);

        return $agreement->fresh();
    }

    /**
     * Mark as disputed (counterparty doesn't know creator).
     */
    public function markDisputed(Agreement $agreement): Agreement
    {
        $agreement->update([
            'status' => AgreementStatus::DISPUTED,
        ]);

        Log::warning('Agreement disputed', [
            'agreement_id' => $agreement->id,
        ]);

        return $agreement->fresh();
    }

    /**
     * Mark as completed.
     */
    public function markCompleted(Agreement $agreement, User $user): Agreement
    {
        if ($agreement->status !== AgreementStatus::CONFIRMED) {
            throw new \RuntimeException('Agreement must be confirmed first');
        }

        // Only creditor can mark complete
        $isCreditor = $this->isCreditor($agreement, $user);
        if (!$isCreditor) {
            throw new \RuntimeException('Only the creditor can mark as complete');
        }

        $agreement->update([
            'status' => AgreementStatus::COMPLETED,
            'completed_at' => now(),
        ]);

        Log::info('Agreement completed', [
            'agreement_id' => $agreement->id,
        ]);

        return $agreement->fresh();
    }

    /**
     * Cancel by creator.
     */
    public function cancelAgreement(Agreement $agreement, User $user): Agreement
    {
        if ($agreement->from_user_id !== $user->id) {
            throw new \RuntimeException('Only creator can cancel');
        }

        if ($agreement->status !== AgreementStatus::PENDING) {
            throw new \RuntimeException('Can only cancel pending agreements');
        }

        $agreement->update([
            'status' => AgreementStatus::CANCELLED,
        ]);

        return $agreement->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Get agreements for user.
     */
    public function getAgreementsForUser(User $user, int $limit = 20): Collection
    {
        return Agreement::query()
            ->where(function ($q) use ($user) {
                $q->where('from_user_id', $user->id)
                    ->orWhere('to_user_id', $user->id)
                    ->orWhere('to_phone', $user->phone);
            })
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Get active agreements.
     */
    public function getActiveAgreements(User $user): Collection
    {
        return Agreement::query()
            ->where(function ($q) use ($user) {
                $q->where('from_user_id', $user->id)
                    ->orWhere('to_user_id', $user->id)
                    ->orWhere('to_phone', $user->phone);
            })
            ->whereIn('status', [
                AgreementStatus::PENDING,
                AgreementStatus::CONFIRMED,
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get pending confirmations for user (as counterparty).
     */
    public function getPendingConfirmations(User $user): Collection
    {
        return Agreement::query()
            ->where(function ($q) use ($user) {
                $q->where('to_user_id', $user->id)
                    ->orWhere('to_phone', $user->phone);
            })
            ->where('status', AgreementStatus::PENDING)
            ->whereNull('to_confirmed_at')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Count pending confirmations.
     */
    public function countPendingConfirmations(User $user): int
    {
        return $this->getPendingConfirmations($user)->count();
    }

    /**
     * Get by agreement number.
     */
    public function getByAgreementNumber(string $number): ?Agreement
    {
        return Agreement::where('agreement_number', $number)->first();
    }

    /**
     * Get by verification token.
     */
    public function getByVerificationToken(string $token): ?Agreement
    {
        return Agreement::where('verification_token', $token)->first();
    }

    /**
     * Check if user is party to agreement.
     */
    public function isPartyToAgreement(Agreement $agreement, User $user): bool
    {
        return $agreement->from_user_id === $user->id
            || $agreement->to_user_id === $user->id
            || $agreement->to_phone === $user->phone;
    }

    /**
     * Check if user is the creditor (one who should receive money).
     */
    public function isCreditor(Agreement $agreement, User $user): bool
    {
        $direction = $agreement->direction ?? 'giving';
        
        // If creator is giving, creator is creditor
        // If creator is receiving, counterparty is creditor
        if ($direction === 'giving') {
            return $agreement->from_user_id === $user->id;
        }
        
        return $agreement->to_user_id === $user->id 
            || $agreement->to_phone === $user->phone;
    }

    /**
     * Get user's role in agreement.
     */
    public function getUserRole(Agreement $agreement, User $user): ?string
    {
        if ($agreement->from_user_id === $user->id) {
            return 'creator';
        }

        if ($agreement->to_user_id === $user->id || $agreement->to_phone === $user->phone) {
            return 'counterparty';
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Amount to Words (FR-AGR-22)
    |--------------------------------------------------------------------------
    */

    /**
     * Convert amount to words (Indian format).
     *
     * @srs-ref FR-AGR-22 - Amount in words
     */
    public function amountToWords(float $amount): string
    {
        if ($amount == 0) {
            return 'Rupees Zero Only';
        }

        $amount = round($amount, 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);

        $words = 'Rupees ' . $this->numberToWords($rupees);

        if ($paise > 0) {
            $words .= ' and ' . $this->numberToWords($paise) . ' Paise';
        }

        return $words . ' Only';
    }

    /**
     * Convert number to words.
     */
    protected function numberToWords(int $num): string
    {
        if ($num == 0) return 'Zero';

        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
            'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
            'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $words = '';

        // Crores
        if ($num >= 10000000) {
            $words .= $this->numberToWords((int) floor($num / 10000000)) . ' Crore ';
            $num %= 10000000;
        }

        // Lakhs
        if ($num >= 100000) {
            $words .= $this->numberToWords((int) floor($num / 100000)) . ' Lakh ';
            $num %= 100000;
        }

        // Thousands
        if ($num >= 1000) {
            $words .= $this->numberToWords((int) floor($num / 1000)) . ' Thousand ';
            $num %= 1000;
        }

        // Hundreds
        if ($num >= 100) {
            $words .= $ones[(int) floor($num / 100)] . ' Hundred ';
            $num %= 100;
        }

        // Tens and ones
        if ($num >= 20) {
            $words .= $tens[(int) floor($num / 10)];
            if ($num % 10 > 0) {
                $words .= '-' . $ones[$num % 10];
            }
            $words .= ' ';
        } elseif ($num > 0) {
            $words .= $ones[$num] . ' ';
        }

        return trim($words);
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate phone number.
     */
    public function isValidPhone(string $phone): bool
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);
        return strlen($clean) === 10 
            || (strlen($clean) === 12 && str_starts_with($clean, '91'));
    }

    /**
     * Validate amount.
     */
    public function isValidAmount($amount): bool
    {
        if (!is_numeric($amount)) return false;
        $val = (float) $amount;
        return $val > 0 && $val <= 100000000; // Max 10 crore
    }

    /**
     * Normalize phone number.
     */
    public function normalizePhone(string $phone): string
    {
        $clean = preg_replace('/[^0-9]/', '', $phone);

        // Remove 91 prefix if 12 digits
        if (strlen($clean) === 12 && str_starts_with($clean, '91')) {
            $clean = substr($clean, 2);
        }

        // Add 91 prefix for consistency
        if (strlen($clean) === 10) {
            $clean = '91' . $clean;
        }

        return $clean;
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance
    |--------------------------------------------------------------------------
    */

    /**
     * Expire old pending agreements.
     */
    public function expirePendingAgreements(): int
    {
        $count = Agreement::query()
            ->where('status', AgreementStatus::PENDING)
            ->where('expires_at', '<', now())
            ->update(['status' => AgreementStatus::EXPIRED]);

        if ($count > 0) {
            Log::info('Agreements expired', ['count' => $count]);
        }

        return $count;
    }

    /**
     * Get agreements needing reminder.
     */
    public function getAgreementsNeedingReminder(): Collection
    {
        return Agreement::query()
            ->where('status', AgreementStatus::PENDING)
            ->where('expires_at', '>', now())
            ->where('created_at', '<', now()->subDays(2))
            ->whereNull('reminder_sent_at')
            ->get();
    }

    /**
     * Mark reminder sent.
     */
    public function markReminderSent(Agreement $agreement): void
    {
        $agreement->update(['reminder_sent_at' => now()]);
    }
}