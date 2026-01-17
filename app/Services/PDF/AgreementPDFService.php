<?php

namespace App\Services\PDF;

use App\Models\Agreement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Service for generating agreement PDF documents.
 *
 * Creates professional PDF agreements with:
 * - Both party details
 * - Amount in numbers and words
 * - QR code for verification
 * - Confirmation timestamps
 *
 * @example
 * $pdfService = app(AgreementPDFService::class);
 *
 * // Generate and upload PDF
 * $url = $pdfService->generateAndUpload($agreement);
 */
class AgreementPDFService
{
    /**
     * Generate PDF and upload to storage.
     *
     * @param Agreement $agreement
     * @return string Public URL of the PDF
     */
    public function generateAndUpload(Agreement $agreement): string
    {
        // Generate PDF
        $pdf = $this->generatePDF($agreement);

        // Generate filename
        $filename = $this->generateFilename($agreement);

        // Upload to storage
        $path = $this->uploadToStorage($pdf, $filename);

        // Update agreement with PDF URL
        $url = Storage::disk(config('nearbuy.agreements.storage_disk', 's3'))->url($path);

        $agreement->update(['pdf_url' => $url]);

        Log::info('Agreement PDF generated', [
            'agreement_id' => $agreement->id,
            'agreement_number' => $agreement->agreement_number,
            'url' => $url,
        ]);

        return $url;
    }

    /**
     * Generate PDF document.
     *
     * @param Agreement $agreement
     * @return \Barryvdh\DomPDF\PDF
     */
    public function generatePDF(Agreement $agreement): \Barryvdh\DomPDF\PDF
    {
        // Load related models
        $agreement->load(['creator', 'counterpartyUser']);

        // Generate QR code
        $qrCode = $this->generateQRCode($agreement);

        // Prepare data for view
        $data = $this->prepareViewData($agreement, $qrCode);

        // Generate PDF from view
        $pdf = Pdf::loadView('pdf.agreement', $data);

        // Configure PDF
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'sans-serif',
        ]);

        return $pdf;
    }

    /**
     * Generate QR code for agreement verification.
     *
     * @param Agreement $agreement
     * @return string Base64 encoded QR code image
     */
    public function generateQRCode(Agreement $agreement): string
    {
        $verificationUrl = $this->getVerificationUrl($agreement);

        $qrCode = QrCode::format('png')
            ->size(150)
            ->margin(1)
            ->generate($verificationUrl);

        return 'data:image/png;base64,' . base64_encode($qrCode);
    }

    /**
     * Get verification URL for agreement.
     *
     * @param Agreement $agreement
     * @return string
     */
    public function getVerificationUrl(Agreement $agreement): string
    {
        $baseUrl = config('app.url');
        $token = $agreement->verification_token;

        return "{$baseUrl}/verify/{$token}";
    }

    /**
     * Prepare data for PDF view.
     *
     * @param Agreement $agreement
     * @param string $qrCode
     * @return array
     */
    protected function prepareViewData(Agreement $agreement, string $qrCode): array
    {
        $creator = $agreement->creator;
        $counterparty = $agreement->counterpartyUser;

        // Determine Party A and Party B based on who is creditor
        $isCreatorCreditor = $agreement->creditor_id === $creator->id;

        if ($isCreatorCreditor) {
            $partyA = [
                'label' => 'CREDITOR (Lender)',
                'name' => $creator->name,
                'phone' => $this->formatPhoneForDisplay($creator->phone),
                'role' => 'Giving Money',
            ];
            $partyB = [
                'label' => 'DEBTOR (Borrower)',
                'name' => $agreement->counterparty_name,
                'phone' => $this->formatPhoneForDisplay($agreement->counterparty_phone),
                'role' => 'Receiving Money',
            ];
        } else {
            $partyA = [
                'label' => 'DEBTOR (Borrower)',
                'name' => $creator->name,
                'phone' => $this->formatPhoneForDisplay($creator->phone),
                'role' => 'Receiving Money',
            ];
            $partyB = [
                'label' => 'CREDITOR (Lender)',
                'name' => $agreement->counterparty_name,
                'phone' => $this->formatPhoneForDisplay($agreement->counterparty_phone),
                'role' => 'Giving Money',
            ];
        }

        return [
            'agreement' => $agreement,
            'agreementNumber' => $agreement->agreement_number,
            'partyA' => $partyA,
            'partyB' => $partyB,
            'amount' => number_format($agreement->amount, 2),
            'amountWords' => $agreement->amount_words,
            'purpose' => $this->formatPurpose($agreement->purpose->value ?? 'other'),
            'description' => $agreement->description ?? 'N/A',
            'dueDate' => $agreement->due_date ? $agreement->due_date->format('F j, Y') : 'No fixed date',
            'createdAt' => $agreement->created_at->format('F j, Y \a\t h:i A'),
            'creatorConfirmedAt' => $agreement->creator_confirmed_at?->format('F j, Y \a\t h:i A'),
            'counterpartyConfirmedAt' => $agreement->counterparty_confirmed_at?->format('F j, Y \a\t h:i A'),
            'status' => $this->formatStatus($agreement->status->value ?? 'pending'),
            'qrCode' => $qrCode,
            'verificationUrl' => $this->getVerificationUrl($agreement),
            'generatedAt' => now()->format('F j, Y \a\t h:i A'),
        ];
    }

    /**
     * Upload PDF to storage.
     *
     * @param \Barryvdh\DomPDF\PDF $pdf
     * @param string $filename
     * @return string Storage path
     */
    protected function uploadToStorage(\Barryvdh\DomPDF\PDF $pdf, string $filename): string
    {
        $disk = config('nearbuy.agreements.storage_disk', 's3');
        $basePath = config('nearbuy.agreements.storage_path', 'agreements');

        $path = "{$basePath}/{$filename}";

        Storage::disk($disk)->put($path, $pdf->output(), [
            'visibility' => 'public',
            'ContentType' => 'application/pdf',
        ]);

        return $path;
    }

    /**
     * Generate filename for PDF.
     *
     * @param Agreement $agreement
     * @return string
     */
    protected function generateFilename(Agreement $agreement): string
    {
        $number = str_replace(['/', '-'], '_', $agreement->agreement_number);
        $timestamp = now()->format('Ymd_His');

        return "{$number}_{$timestamp}.pdf";
    }

    /**
     * Format phone for display.
     *
     * @param string $phone
     * @return string
     */
    protected function formatPhoneForDisplay(string $phone): string
    {
        // Remove 91 prefix if present
        if (strlen($phone) === 12 && str_starts_with($phone, '91')) {
            $phone = substr($phone, 2);
        }

        // Format as XXX-XXX-XXXX
        if (strlen($phone) === 10) {
            return substr($phone, 0, 3) . '-' . substr($phone, 3, 3) . '-' . substr($phone, 6, 4);
        }

        return $phone;
    }

    /**
     * Format purpose for display.
     *
     * @param string $purpose
     * @return string
     */
    protected function formatPurpose(string $purpose): string
    {
        $map = [
            'loan' => 'Loan',
            'advance' => 'Advance Payment',
            'deposit' => 'Security Deposit',
            'business' => 'Business Transaction',
            'personal' => 'Personal Transaction',
            'other' => 'Other',
        ];

        return $map[strtolower($purpose)] ?? ucfirst($purpose);
    }

    /**
     * Format status for display.
     *
     * @param string $status
     * @return string
     */
    protected function formatStatus(string $status): string
    {
        $map = [
            'pending_counterparty' => 'Pending Confirmation',
            'confirmed' => 'Confirmed by Both Parties',
            'rejected' => 'Rejected',
            'disputed' => 'Disputed',
            'completed' => 'Completed',
            'expired' => 'Expired',
            'cancelled' => 'Cancelled',
        ];

        return $map[strtolower($status)] ?? ucfirst($status);
    }

    /**
     * Delete PDF from storage.
     *
     * @param Agreement $agreement
     * @return bool
     */
    public function deletePDF(Agreement $agreement): bool
    {
        if (!$agreement->pdf_url) {
            return true;
        }

        try {
            $disk = config('nearbuy.agreements.storage_disk', 's3');

            // Extract path from URL
            $path = $this->extractPathFromUrl($agreement->pdf_url);

            if ($path && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);

                $agreement->update(['pdf_url' => null]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Failed to delete agreement PDF', [
                'agreement_id' => $agreement->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Extract storage path from URL.
     *
     * @param string $url
     * @return string|null
     */
    protected function extractPathFromUrl(string $url): ?string
    {
        $basePath = config('nearbuy.agreements.storage_path', 'agreements');

        if (preg_match("#/{$basePath}/(.+)$#", $url, $matches)) {
            return "{$basePath}/{$matches[1]}";
        }

        return null;
    }

    /**
     * Regenerate PDF for an agreement.
     *
     * @param Agreement $agreement
     * @return string New URL
     */
    public function regeneratePDF(Agreement $agreement): string
    {
        // Delete old PDF if exists
        $this->deletePDF($agreement);

        // Generate new PDF
        return $this->generateAndUpload($agreement);
    }
}