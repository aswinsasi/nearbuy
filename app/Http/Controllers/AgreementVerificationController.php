<?php

namespace App\Http\Controllers;

use App\Models\Agreement;
use App\Services\Agreements\AgreementService;
use Illuminate\Http\Request;

/**
 * Controller for agreement verification via QR code.
 *
 * Handles verification requests from QR codes on agreement PDFs.
 */
class AgreementVerificationController extends Controller
{
    public function __construct(
        protected AgreementService $agreementService,
    ) {}

    /**
     * Verify an agreement by token.
     *
     * @param string $token
     * @return \Illuminate\View\View
     */
    public function verify(string $token)
    {
        $agreement = $this->agreementService->getByVerificationToken($token);

        if (!$agreement) {
            return view('verify.agreement', [
                'found' => false,
                'error' => 'Agreement not found or invalid verification token.',
            ]);
        }

        // Load relationships
        $agreement->load(['creator', 'counterpartyUser']);

        // Prepare data for view
        $data = $this->prepareVerificationData($agreement);

        return view('verify.agreement', [
            'found' => true,
            'agreement' => $agreement,
            'data' => $data,
        ]);
    }

    /**
     * Verify by agreement number (alternative).
     *
     * @param Request $request
     * @return \Illuminate\View\View
     */
    public function verifyByNumber(Request $request)
    {
        $agreementNumber = $request->input('number');

        if (!$agreementNumber) {
            return view('verify.agreement', [
                'found' => false,
                'error' => 'Please provide an agreement number.',
            ]);
        }

        $agreement = $this->agreementService->getByAgreementNumber($agreementNumber);

        if (!$agreement) {
            return view('verify.agreement', [
                'found' => false,
                'error' => 'Agreement not found.',
            ]);
        }

        $agreement->load(['creator', 'counterpartyUser']);
        $data = $this->prepareVerificationData($agreement);

        return view('verify.agreement', [
            'found' => true,
            'agreement' => $agreement,
            'data' => $data,
        ]);
    }

    /**
     * Prepare verification data for view.
     *
     * @param Agreement $agreement
     * @return array
     */
    protected function prepareVerificationData(Agreement $agreement): array
    {
        $creator = $agreement->creator;
        $isCreatorCreditor = $agreement->creditor_id === $creator->id;

        return [
            'agreementNumber' => $agreement->agreement_number,
            'status' => $this->formatStatus($agreement->status->value ?? 'unknown'),
            'statusClass' => $this->getStatusClass($agreement->status->value ?? 'unknown'),

            'partyA' => [
                'label' => $isCreatorCreditor ? 'Creditor (Lender)' : 'Debtor (Borrower)',
                'name' => $creator->name,
                'phone' => $this->maskPhone($creator->phone),
            ],

            'partyB' => [
                'label' => $isCreatorCreditor ? 'Debtor (Borrower)' : 'Creditor (Lender)',
                'name' => $agreement->to_name,
                'phone' => $this->maskPhone($agreement->to_phone),
            ],

            'amount' => '₹' . number_format($agreement->amount, 2),
            'amountWords' => $agreement->amount_words,
            'purpose' => $this->formatPurpose($agreement->purpose->value ?? 'other'),
            'description' => $agreement->description ?? 'N/A',
            'dueDate' => $agreement->due_date ? $agreement->due_date->format('F j, Y') : 'No fixed date',

            'createdAt' => $agreement->created_at->format('F j, Y \a\t h:i A'),
            'creatorConfirmed' => $agreement->creator_confirmed_at
                ? $agreement->creator_confirmed_at->format('F j, Y \a\t h:i A')
                : 'Pending',
            'counterpartyConfirmed' => $agreement->to_confirmed_at
                ? $agreement->to_confirmed_at->format('F j, Y \a\t h:i A')
                : 'Pending',

            'isConfirmed' => $agreement->status->value === 'confirmed',
            'isCompleted' => $agreement->status->value === 'completed',
        ];
    }

    /**
     * Format status for display.
     *
     * @param string $status
     * @return string
     */
    protected function formatStatus(string $status): string
    {
        return match ($status) {
            'pending_counterparty' => 'Pending Confirmation',
            'confirmed' => 'Confirmed by Both Parties',
            'rejected' => 'Rejected',
            'disputed' => 'Disputed',
            'completed' => 'Completed',
            'expired' => 'Expired',
            'cancelled' => 'Cancelled',
            default => 'Unknown',
        };
    }

    /**
     * Get CSS class for status.
     *
     * @param string $status
     * @return string
     */
    protected function getStatusClass(string $status): string
    {
        return match ($status) {
            'confirmed', 'completed' => 'bg-green-100 text-green-800',
            'pending_counterparty' => 'bg-yellow-100 text-yellow-800',
            'rejected', 'cancelled' => 'bg-red-100 text-red-800',
            'disputed' => 'bg-orange-100 text-orange-800',
            'expired' => 'bg-gray-100 text-gray-800',
            default => 'bg-gray-100 text-gray-800',
        };
    }

    /**
     * Format purpose for display.
     *
     * @param string $purpose
     * @return string
     */
    protected function formatPurpose(string $purpose): string
    {
        return match (strtolower($purpose)) {
            'loan' => 'Loan',
            'advance' => 'Advance Payment',
            'deposit' => 'Security Deposit',
            'business' => 'Business Transaction',
            'personal' => 'Personal Transaction',
            'other' => 'Other',
            default => ucfirst($purpose),
        };
    }

    /**
     * Mask phone number for privacy.
     *
     * @param string $phone
     * @return string
     */
    protected function maskPhone(string $phone): string
    {
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