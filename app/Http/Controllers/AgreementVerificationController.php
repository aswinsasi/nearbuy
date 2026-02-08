<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\AgreementDirection;
use App\Enums\AgreementPurpose;
use App\Enums\AgreementStatus;
use App\Models\Agreement;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Agreement Verification Controller.
 *
 * Handles QR code verification from agreement PDFs.
 * Displays a clean, mobile-friendly verification page.
 *
 * @srs-ref FR-AGR-23 QR code linking to verification URL
 */
class AgreementVerificationController extends Controller
{
    /**
     * Verify an agreement by token.
     *
     * URL: /verify/{token}
     *
     * Mobile-friendly page when QR scanned:
     * ✅ NearBuy Verified Agreement
     * #NB-AG-2026-0042
     * Between: Name1 ↔ Name2
     * Amount: ₹20,000
     * Purpose: Loan
     * Status: Active
     * Created: Jan 15, 2026
     */
    public function verify(string $token): View
    {
        $agreement = Agreement::where('verification_token', $token)
            ->with(['fromUser', 'toUser'])
            ->first();

        if (!$agreement) {
            return view('verify.agreement', [
                'found' => false,
                'error' => 'Agreement not found or invalid verification link.',
            ]);
        }

        $data = $this->prepareVerificationData($agreement);

        return view('verify.agreement', [
            'found' => true,
            'agreement' => $agreement,
            'data' => $data,
        ]);
    }

    /**
     * Verify by agreement number.
     *
     * URL: /verify?number=NB-AG-2026-0001
     */
    public function verifyByNumber(Request $request): View
    {
        $number = $request->input('number');

        if (!$number) {
            return view('verify.agreement', [
                'found' => false,
                'error' => 'Please provide an agreement number.',
            ]);
        }

        $agreement = Agreement::where('agreement_number', $number)
            ->with(['fromUser', 'toUser'])
            ->first();

        if (!$agreement) {
            return view('verify.agreement', [
                'found' => false,
                'error' => 'Agreement not found.',
            ]);
        }

        $data = $this->prepareVerificationData($agreement);

        return view('verify.agreement', [
            'found' => true,
            'agreement' => $agreement,
            'data' => $data,
        ]);
    }

    /**
     * Prepare verification data for view.
     */
    protected function prepareVerificationData(Agreement $agreement): array
    {
        // Get direction enum
        $directionValue = $agreement->direction ?? 'giving';
        $directionEnum = AgreementDirection::tryFrom($directionValue) ?? AgreementDirection::GIVING;

        // Determine creditor/debtor based on direction
        $isCreatorCreditor = $directionEnum->isCreatorCreditor();

        // Party names - use stored names (works for unregistered users)
        $creatorName = $agreement->from_name ?? $agreement->fromUser?->name ?? 'Unknown';
        $counterpartyName = $agreement->to_name ?? 'Unknown';

        if ($isCreatorCreditor) {
            $partyA = [
                'label' => 'Creditor (Lender)',
                'name' => $creatorName,
                'phone' => $this->maskPhone($agreement->from_phone),
            ];
            $partyB = [
                'label' => 'Debtor (Borrower)',
                'name' => $counterpartyName,
                'phone' => $this->maskPhone($agreement->to_phone),
            ];
        } else {
            $partyA = [
                'label' => 'Debtor (Borrower)',
                'name' => $creatorName,
                'phone' => $this->maskPhone($agreement->from_phone),
            ];
            $partyB = [
                'label' => 'Creditor (Lender)',
                'name' => $counterpartyName,
                'phone' => $this->maskPhone($agreement->to_phone),
            ];
        }

        // Get purpose
        $purposeValue = $agreement->purpose_type instanceof AgreementPurpose
            ? $agreement->purpose_type->value
            : ($agreement->purpose_type ?? 'other');
        $purposeEnum = AgreementPurpose::tryFrom($purposeValue) ?? AgreementPurpose::OTHER;

        // Get status
        $statusEnum = $agreement->status instanceof AgreementStatus
            ? $agreement->status
            : (AgreementStatus::tryFrom($agreement->status ?? 'pending') ?? AgreementStatus::PENDING);

        return [
            // Header
            'agreementNumber' => $agreement->agreement_number,

            // Parties (for "Between: Name1 ↔ Name2" display)
            'party1Name' => $creatorName,
            'party2Name' => $counterpartyName,
            'partyA' => $partyA,
            'partyB' => $partyB,

            // Amount
            'amount' => '₹' . number_format($agreement->amount, 2),
            'amountShort' => '₹' . number_format($agreement->amount, 0),
            'amountWords' => $agreement->amount_in_words ?? '',

            // Purpose
            'purpose' => $purposeEnum->label(),
            'purposeIcon' => $purposeEnum->icon(),
            'description' => $agreement->description ?? '',

            // Status
            'status' => $statusEnum->label(),
            'statusIcon' => $statusEnum->icon(),
            'statusBadge' => $statusEnum->badge(),
            'statusClass' => $statusEnum->badgeClass(),
            'statusColor' => $statusEnum->color(),

            // Dates
            'dueDate' => $agreement->due_date
                ? $agreement->due_date->format('F j, Y')
                : 'No fixed date',
            'createdAt' => $agreement->created_at->format('F j, Y'),
            'createdAtFull' => $agreement->created_at->format('F j, Y \a\t h:i A'),

            // Confirmation timestamps
            'creatorConfirmed' => $agreement->from_confirmed_at
                ? $agreement->from_confirmed_at->format('F j, Y \a\t h:i A')
                : null,
            'counterpartyConfirmed' => $agreement->to_confirmed_at
                ? $agreement->to_confirmed_at->format('F j, Y \a\t h:i A')
                : null,

            // Flags
            'isConfirmed' => $statusEnum === AgreementStatus::CONFIRMED,
            'isCompleted' => $statusEnum === AgreementStatus::COMPLETED,
            'isPending' => $statusEnum === AgreementStatus::PENDING,
            'isDisputed' => $statusEnum === AgreementStatus::DISPUTED,

            // Verification
            'verifiedAt' => now()->format('F j, Y \a\t h:i A'),
        ];
    }

    /**
     * Mask phone number for privacy.
     * 9876543210 → 987****210
     */
    protected function maskPhone(?string $phone): string
    {
        if (!$phone) {
            return '***';
        }

        // Remove country code if present
        if (strlen($phone) === 12 && str_starts_with($phone, '91')) {
            $phone = substr($phone, 2);
        }

        if (strlen($phone) >= 10) {
            return substr($phone, 0, 3) . '****' . substr($phone, -3);
        }

        return '****' . substr($phone, -3);
    }
}