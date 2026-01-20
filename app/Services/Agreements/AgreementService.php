<?php

namespace App\Services\Agreements;

use App\Enums\AgreementStatus;
use App\Enums\AgreementPurpose;
use App\Models\Agreement;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Service for managing digital agreements.
 *
 * Handles agreement creation, confirmation, and lifecycle management.
 *
 * @example
 * $service = app(AgreementService::class);
 *
 * // Create agreement
 * $agreement = $service->createAgreement($user, [
 *     'direction' => 'giving',
 *     'amount' => 25000,
 *     'other_party_name' => 'John Doe',
 *     'other_party_phone' => '9876543210',
 *     'purpose' => 'loan',
 *     'due_date' => now()->addMonth(),
 * ]);
 *
 * // Confirm by counterparty
 * $service->confirmByCounterparty($agreement);
 */
class AgreementService
{
    /*
    |--------------------------------------------------------------------------
    | Agreement Creation
    |--------------------------------------------------------------------------
    */

    /**
     * Create a new agreement.
     *
     * @param User $creator
     * @param array{
     *     direction: string,
     *     amount: float,
     *     other_party_name: string,
     *     other_party_phone: string,
     *     purpose: string,
     *     description?: string,
     *     due_date?: \Carbon\Carbon|null
     * } $data
     * @return Agreement
     * @throws \Exception
     */
    public function createAgreement(User $creator, array $data): Agreement
    {
        // Normalize phone
        $otherPartyPhone = $this->normalizePhone($data['other_party_phone']);

        // Prevent self-agreement
        if ($otherPartyPhone === $creator->phone) {
            throw new \Exception('Cannot create an agreement with yourself');
        }

        // Check if other party is a registered user
        $otherPartyUser = User::where('phone', $otherPartyPhone)->first();

        // Parse purpose
        $purpose = AgreementPurpose::tryFrom(strtoupper($data['purpose'])) ?? AgreementPurpose::OTHER;

        // Parse direction
        $direction = \App\Enums\AgreementDirection::tryFrom(strtoupper($data['direction'])) 
            ?? \App\Enums\AgreementDirection::GIVING;

        // Generate verification token
        $verificationToken = $this->generateVerificationToken();

        $agreement = Agreement::create([
            'agreement_number' => $this->generateAgreementNumber(),

            // From (creator) details
            'from_user_id' => $creator->id,
            'from_name' => $creator->name ?? 'Unknown',
            'from_phone' => $creator->phone,

            // To (counterparty) details
            'to_user_id' => $otherPartyUser?->id,
            'to_name' => trim($data['other_party_name']),
            'to_phone' => $otherPartyPhone,

            // Direction
            'direction' => $direction,

            // Financial details
            'amount' => $data['amount'],
            'amount_in_words' => $this->convertAmountToWords($data['amount']),
            'purpose_type' => $purpose,
            'description' => $data['description'] ?? null,
            'due_date' => $data['due_date'] ?? null,

            // Status
            'status' => AgreementStatus::PENDING,
            'from_confirmed_at' => now(),
            'to_confirmed_at' => null,

            // Verification
            'verification_token' => $verificationToken,
            'pdf_url' => null,
        ]);

        Log::info('Agreement created', [
            'agreement_id' => $agreement->id,
            'agreement_number' => $agreement->agreement_number,
            'from_user_id' => $creator->id,
            'amount' => $data['amount'],
        ]);

        return $agreement;
    }

    /**
     * Generate unique agreement number (NB-AG-YYYY-XXXX format).
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

    /**
     * Generate verification token.
     */
    public function generateVerificationToken(): string
    {
        return Str::random(32);
    }

    /*
    |--------------------------------------------------------------------------
    | Confirmation
    |--------------------------------------------------------------------------
    */

    /**
     * Confirm agreement by counterparty.
     *
     * @param Agreement $agreement
     * @return Agreement
     * @throws \Exception
     */
    public function confirmByCounterparty(Agreement $agreement): Agreement
    {
        if ($agreement->status !== AgreementStatus::PENDING) {
            throw new \Exception('Agreement is not pending counterparty confirmation');
        }

        if ($agreement->isExpired()) {
            throw new \Exception('Agreement confirmation has expired');
        }

        $agreement->update([
            'status' => AgreementStatus::CONFIRMED,
            'to_confirmed_at' => now(),
        ]);

        Log::info('Agreement confirmed by counterparty', [
            'agreement_id' => $agreement->id,
            'agreement_number' => $agreement->agreement_number,
        ]);

        return $agreement->fresh();
    }

    /**
     * Reject agreement by counterparty.
     *
     * @param Agreement $agreement
     * @param string|null $reason
     * @return Agreement
     */
    public function rejectByCounterparty(Agreement $agreement, ?string $reason = null): Agreement
    {
        if (!in_array($agreement->status, [AgreementStatus::PENDING])) {
            throw new \Exception('Agreement cannot be rejected');
        }

        $agreement->update([
            'status' => AgreementStatus::REJECTED,
            'rejection_reason' => $reason,
        ]);

        Log::info('Agreement rejected by counterparty', [
            'agreement_id' => $agreement->id,
            'agreement_number' => $agreement->agreement_number,
            'reason' => $reason,
        ]);

        return $agreement->fresh();
    }

    /**
     * Mark agreement as disputed (counterparty doesn't know creator).
     *
     * @param Agreement $agreement
     * @return Agreement
     */
    public function markDisputed(Agreement $agreement): Agreement
    {
        $agreement->update([
            'status' => AgreementStatus::DISPUTED,
        ]);

        Log::warning('Agreement disputed', [
            'agreement_id' => $agreement->id,
            'agreement_number' => $agreement->agreement_number,
        ]);

        return $agreement->fresh();
    }

    /**
     * Mark agreement as completed (debt settled).
     *
     * @param Agreement $agreement
     * @param User $user
     * @return Agreement
     */
    public function markCompleted(Agreement $agreement, User $user): Agreement
    {
        // Only creditor can mark as completed
        if ($agreement->creditor_id !== $user->id) {
            throw new \Exception('Only the creditor can mark this agreement as completed');
        }

        if ($agreement->status !== AgreementStatus::CONFIRMED) {
            throw new \Exception('Agreement must be confirmed before marking complete');
        }

        $agreement->update([
            'status' => AgreementStatus::COMPLETED,
            'completed_at' => now(),
        ]);

        Log::info('Agreement marked completed', [
            'agreement_id' => $agreement->id,
            'agreement_number' => $agreement->agreement_number,
        ]);

        return $agreement->fresh();
    }

    /**
     * Cancel agreement by creator.
     *
     * @param Agreement $agreement
     * @param User $user
     * @return Agreement
     */
    public function cancelAgreement(Agreement $agreement, User $user): Agreement
    {
        if ($agreement->creator_id !== $user->id) {
            throw new \Exception('Only the creator can cancel this agreement');
        }

        if (!in_array($agreement->status, [AgreementStatus::PENDING])) {
            throw new \Exception('Agreement cannot be cancelled');
        }

        $agreement->update([
            'status' => AgreementStatus::CANCELLED,
        ]);

        Log::info('Agreement cancelled', [
            'agreement_id' => $agreement->id,
            'agreement_number' => $agreement->agreement_number,
        ]);

        return $agreement->fresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Queries
    |--------------------------------------------------------------------------
    */

    /**
     * Get all agreements for a user (as creator or counterparty).
     *
     * @param User $user
     * @param int $limit
     * @return Collection
     */
    public function getAgreementsForUser(User $user, int $limit = 20): Collection
    {
        return Agreement::query()
            ->where(function ($query) use ($user) {
                $query->where('from_user_id', $user->id)
                    ->orWhere('to_user_id', $user->id)
                    ->orWhere('to_phone', $user->phone); // ← Changed
            })
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get active agreements for a user.
     *
     * @param User $user
     * @return Collection
     */
    public function getActiveAgreements(User $user): Collection
    {
        return Agreement::query()
            ->where(function ($query) use ($user) {
                $query->where('from_user_id', $user->id) // ← Changed
                    ->orWhere('to_user_id', $user->id) // ← Changed
                    ->orWhere('to_phone', $user->phone); // ← Changed
            })
            ->whereIn('status', [
                AgreementStatus::PENDING,
                AgreementStatus::CONFIRMED,
            ])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get pending confirmations for a user.
     *
     * @param User $user
     * @return Collection
     */
    public function getPendingConfirmations(User $user): Collection
    {
        return Agreement::query()
            ->where(function ($query) use ($user) {
                $query->where('to_user_id', $user->id) // ← Changed
                    ->orWhere('to_phone', $user->phone); // ← Changed
            })
            ->where('status', AgreementStatus::PENDING)
            ->whereNull('to_confirmed_at') // ← Changed
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Count pending confirmations for a user.
     *
     * @param User $user
     * @return int
     */
    public function countPendingConfirmations(User $user): int
    {
        return $this->getPendingConfirmations($user)->count();
    }

    /**
     * Get agreement by verification token.
     *
     * @param string $token
     * @return Agreement|null
     */
    public function getByVerificationToken(string $token): ?Agreement
    {
        return Agreement::where('verification_token', $token)->first();
    }

    /**
     * Get agreement by number.
     *
     * @param string $agreementNumber
     * @return Agreement|null
     */
    public function getByAgreementNumber(string $agreementNumber): ?Agreement
    {
        return Agreement::where('agreement_number', $agreementNumber)->first();
    }

    /**
     * Check if user is party to agreement.
     *
     * @param Agreement $agreement
     * @param User $user
     * @return bool
     */
    public function isPartyToAgreement(Agreement $agreement, User $user): bool
    {
        return $agreement->from_user_id === $user->id // ← Changed
            || $agreement->to_user_id === $user->id // ← Changed
            || $agreement->to_phone === $user->phone; // ← Changed
    }

    /**
     * Get user's role in agreement.
     *
     * @param Agreement $agreement
     * @param User $user
     * @return string|null
     */
    public function getUserRole(Agreement $agreement, User $user): ?string
    {
        if ($agreement->from_user_id === $user->id) { // ← Changed
            return 'creator';
        }

        if ($agreement->to_user_id === $user->id || $agreement->to_phone === $user->phone) { // ← Changed
            return 'counterparty';
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Amount Conversion
    |--------------------------------------------------------------------------
    */

    /**
     * Convert amount to words (Indian numbering system).
     *
     * @param float $amount
     * @return string
     */
    public function convertAmountToWords(float $amount): string
    {
        if ($amount == 0) {
            return 'Rupees Zero Only';
        }

        $amount = round($amount, 2);
        $rupees = floor($amount);
        $paise = round(($amount - $rupees) * 100);

        $words = 'Rupees ' . $this->numberToWords($rupees);

        if ($paise > 0) {
            $words .= ' and ' . $this->numberToWords($paise) . ' Paise';
        }

        return $words . ' Only';
    }

    /**
     * Convert number to words.
     *
     * @param int $number
     * @return string
     */
    protected function numberToWords(int $number): string
    {
        if ($number == 0) {
            return 'Zero';
        }

        $ones = [
            '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
            'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
            'Seventeen', 'Eighteen', 'Nineteen',
        ];

        $tens = [
            '', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety',
        ];

        $words = '';

        // Crores (10,000,000)
        if ($number >= 10000000) {
            $words .= $this->numberToWords(floor($number / 10000000)) . ' Crore ';
            $number %= 10000000;
        }

        // Lakhs (100,000)
        if ($number >= 100000) {
            $words .= $this->numberToWords(floor($number / 100000)) . ' Lakh ';
            $number %= 100000;
        }

        // Thousands
        if ($number >= 1000) {
            $words .= $this->numberToWords(floor($number / 1000)) . ' Thousand ';
            $number %= 1000;
        }

        // Hundreds
        if ($number >= 100) {
            $words .= $ones[floor($number / 100)] . ' Hundred ';
            $number %= 100;
        }

        // Tens and ones
        if ($number >= 20) {
            $words .= $tens[floor($number / 10)];
            if ($number % 10 > 0) {
                $words .= '-' . $ones[$number % 10];
            }
            $words .= ' ';
        } elseif ($number > 0) {
            $words .= $ones[$number] . ' ';
        }

        return trim($words);
    }

    /*
    |--------------------------------------------------------------------------
    | Maintenance
    |--------------------------------------------------------------------------
    */

    /**
     * Expire old pending agreements.
     *
     * @return int
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
     * Send reminders for pending agreements.
     *
     * @return Collection
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
     * Mark reminder as sent.
     *
     * @param Agreement $agreement
     * @return void
     */
    public function markReminderSent(Agreement $agreement): void
    {
        $agreement->update(['reminder_sent_at' => now()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Normalize phone number.
     *
     * @param string $phone
     * @return string
     */
    protected function normalizePhone(string $phone): string
    {
        // Remove all non-numeric characters
        $phone = preg_replace('/[^0-9]/', '', $phone);

        // Remove leading 91 if present
        if (strlen($phone) === 12 && str_starts_with($phone, '91')) {
            $phone = substr($phone, 2);
        }

        // Add 91 prefix for consistency
        if (strlen($phone) === 10) {
            $phone = '91' . $phone;
        }

        return $phone;
    }

    /**
     * Validate phone number.
     *
     * @param string $phone
     * @return bool
     */
    public function isValidPhone(string $phone): bool
    {
        $normalized = preg_replace('/[^0-9]/', '', $phone);

        // Accept 10 digits or 12 digits (with 91)
        return strlen($normalized) === 10 || (strlen($normalized) === 12 && str_starts_with($normalized, '91'));
    }

    /**
     * Validate amount.
     *
     * @param mixed $amount
     * @return bool
     */
    public function isValidAmount($amount): bool
    {
        if (!is_numeric($amount)) {
            return false;
        }

        $amount = (float) $amount;

        return $amount > 0 && $amount <= 100000000; // Max 10 crore
    }
}