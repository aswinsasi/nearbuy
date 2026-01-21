<?php

namespace App\Services\PDF;

use App\Models\Agreement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * ENHANCED Service for generating agreement PDF documents.
 *
 * Key fixes:
 * 1. Correct field names (from_confirmed_at, amount_in_words)
 * 2. Better error handling with detailed logging
 * 3. Fallback for QR code generation failures
 * 4. Local storage fallback if S3 fails
 */
class AgreementPDFService
{
    /**
     * Generate PDF and upload to storage.
     *
     * @param Agreement $agreement
     * @return string Public URL of the PDF
     * @throws \Exception
     */
    public function generateAndUpload(Agreement $agreement): string
    {
        try {
            Log::info('Starting PDF generation', [
                'agreement_id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number,
            ]);

            // Load relationships
            $agreement->load(['creator', 'counterpartyUser']);

            // Generate PDF
            $pdf = $this->generatePDF($agreement);

            // Generate filename
            $filename = $this->generateFilename($agreement);

            // Upload to storage
            $path = $this->uploadToStorage($pdf, $filename);

            // Get URL
            $disk = config('nearbuy.agreements.storage_disk', 'public');
            $url = Storage::disk($disk)->url($path);

            // Update agreement with PDF URL
            $agreement->update(['pdf_url' => $url]);

            Log::info('Agreement PDF generated successfully', [
                'agreement_id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number,
                'url' => $url,
                'path' => $path,
            ]);

            return $url;

        } catch (\Exception $e) {
            Log::error('Failed to generate agreement PDF', [
                'agreement_id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number ?? 'unknown',
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Generate PDF document.
     *
     * @param Agreement $agreement
     * @return \Barryvdh\DomPDF\PDF
     */
    public function generatePDF(Agreement $agreement): \Barryvdh\DomPDF\PDF
    {
        // Load related models if not already loaded
        if (!$agreement->relationLoaded('creator')) {
            $agreement->load(['creator', 'counterpartyUser']);
        }

        // Generate QR code (with fallback)
        $qrCode = $this->generateQRCode($agreement);

        // Prepare data for view
        $data = $this->prepareViewData($agreement, $qrCode);

        // Check if view exists
        if (!view()->exists('pdf.agreement')) {
            Log::error('PDF view template not found', [
                'view' => 'pdf.agreement',
                'agreement_id' => $agreement->id,
            ]);
            throw new \Exception('PDF template not found. Please ensure resources/views/pdf/agreement.blade.php exists.');
        }

        // Generate PDF from view
        $pdf = Pdf::loadView('pdf.agreement', $data);

        // Configure PDF
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'sans-serif',
            'dpi' => 150,
            'enable_css_float' => true,
            'enable_javascript' => false,
        ]);

        return $pdf;
    }

    /**
     * Generate QR code for agreement verification.
     *
     * @param Agreement $agreement
     * @return string|null Base64 encoded QR code image or null if failed
     */
    public function generateQRCode(Agreement $agreement): ?string
    {
        try {
            $verificationUrl = $this->getVerificationUrl($agreement);

            // Check if QrCode facade is available
            if (!class_exists('SimpleSoftwareIO\QrCode\Facades\QrCode')) {
                Log::warning('QrCode package not installed, skipping QR code generation');
                return null;
            }

            $qrCode = QrCode::format('png')
                ->size(150)
                ->margin(1)
                ->errorCorrection('M')
                ->generate($verificationUrl);

            return 'data:image/png;base64,' . base64_encode($qrCode);

        } catch (\Exception $e) {
            Log::warning('QR code generation failed', [
                'agreement_id' => $agreement->id,
                'error' => $e->getMessage(),
            ]);

            // Return null instead of failing - PDF can still be generated without QR
            return null;
        }
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
     * FIXED: Correct field names for the Agreement model
     *
     * @param Agreement $agreement
     * @param string|null $qrCode
     * @return array
     */
    protected function prepareViewData(Agreement $agreement, ?string $qrCode): array
    {
        $creator = $agreement->creator;

        // Determine creditor - check which field exists
        $creditorId = $agreement->creditor_id ?? null;
        
        // If creditor_id doesn't exist, determine from direction
        if (!$creditorId) {
            $direction = $agreement->direction->value ?? $agreement->direction ?? 'giving';
            $isCreatorCreditor = ($direction === 'giving');
        } else {
            $isCreatorCreditor = $creditorId === $creator->id;
        }

        if ($isCreatorCreditor) {
            $partyA = [
                'label' => 'CREDITOR (Lender)',
                'name' => $creator->name ?? 'Unknown',
                'phone' => $this->formatPhoneForDisplay($creator->phone ?? ''),
                'role' => 'Giving Money',
            ];
            $partyB = [
                'label' => 'DEBTOR (Borrower)',
                'name' => $agreement->to_name ?? 'Unknown',
                'phone' => $this->formatPhoneForDisplay($agreement->to_phone ?? ''),
                'role' => 'Receiving Money',
            ];
        } else {
            $partyA = [
                'label' => 'DEBTOR (Borrower)',
                'name' => $creator->name ?? 'Unknown',
                'phone' => $this->formatPhoneForDisplay($creator->phone ?? ''),
                'role' => 'Receiving Money',
            ];
            $partyB = [
                'label' => 'CREDITOR (Lender)',
                'name' => $agreement->to_name ?? 'Unknown',
                'phone' => $this->formatPhoneForDisplay($agreement->to_phone ?? ''),
                'role' => 'Giving Money',
            ];
        }

        // Get amount in words - check which field exists
        $amountWords = $agreement->amount_in_words 
            ?? $agreement->amount_words 
            ?? $this->convertAmountToWords($agreement->amount ?? 0);

        // Get purpose value - handle both enum and string
        $purposeValue = is_object($agreement->purpose_type) 
            ? $agreement->purpose_type->value 
            : ($agreement->purpose_type ?? $agreement->purpose ?? 'other');

        // Get status value - handle both enum and string
        $statusValue = is_object($agreement->status) 
            ? $agreement->status->value 
            : ($agreement->status ?? 'pending');

        // Get confirmation timestamps - FIXED field names
        $creatorConfirmedAt = $agreement->from_confirmed_at 
            ?? $agreement->creator_confirmed_at 
            ?? $agreement->created_at;
        
        $toConfirmedAt = $agreement->to_confirmed_at 
            ?? $agreement->counterparty_confirmed_at 
            ?? null;

        return [
            'agreement' => $agreement,
            'agreementNumber' => $agreement->agreement_number,
            'partyA' => $partyA,
            'partyB' => $partyB,
            'amount' => number_format($agreement->amount ?? 0, 2),
            'amountWords' => $amountWords,
            'purpose' => $this->formatPurpose($purposeValue),
            'description' => $agreement->description ?? 'N/A',
            'dueDate' => $agreement->due_date 
                ? (is_string($agreement->due_date) 
                    ? \Carbon\Carbon::parse($agreement->due_date)->format('F j, Y') 
                    : $agreement->due_date->format('F j, Y'))
                : 'No fixed date',
            'createdAt' => $agreement->created_at?->format('F j, Y \a\t h:i A') ?? 'Unknown',
            'creatorConfirmedAt' => $creatorConfirmedAt?->format('F j, Y \a\t h:i A'),
            'toConfirmedAt' => $toConfirmedAt?->format('F j, Y \a\t h:i A'),
            'status' => $this->formatStatus($statusValue),
            'qrCode' => $qrCode,
            'verificationUrl' => $this->getVerificationUrl($agreement),
            'generatedAt' => now()->format('F j, Y \a\t h:i A'),
        ];
    }

    /**
     * Upload PDF to storage with fallback.
     *
     * @param \Barryvdh\DomPDF\PDF $pdf
     * @param string $filename
     * @return string Storage path
     */
    protected function uploadToStorage(\Barryvdh\DomPDF\PDF $pdf, string $filename): string
    {
        $primaryDisk = config('nearbuy.agreements.storage_disk', 'public');
        $basePath = config('nearbuy.agreements.storage_path', 'agreements');
        $path = "{$basePath}/{$filename}";

        try {
            // Try primary disk (S3 or configured)
            Storage::disk($primaryDisk)->put($path, $pdf->output(), [
                'visibility' => 'public',
                'ContentType' => 'application/pdf',
            ]);

            Log::info('PDF uploaded to primary storage', [
                'disk' => $primaryDisk,
                'path' => $path,
            ]);

            return $path;

        } catch (\Exception $e) {
            Log::warning('Primary storage failed, trying fallback', [
                'disk' => $primaryDisk,
                'error' => $e->getMessage(),
            ]);

            // Fallback to local public disk
            try {
                Storage::disk('public')->put($path, $pdf->output());

                Log::info('PDF uploaded to fallback storage', [
                    'disk' => 'public',
                    'path' => $path,
                ]);

                return $path;

            } catch (\Exception $fallbackError) {
                Log::error('All storage options failed', [
                    'primary_error' => $e->getMessage(),
                    'fallback_error' => $fallbackError->getMessage(),
                ]);

                throw new \Exception('Failed to upload PDF to any storage: ' . $fallbackError->getMessage());
            }
        }
    }

    /**
     * Generate filename for PDF.
     *
     * @param Agreement $agreement
     * @return string
     */
    protected function generateFilename(Agreement $agreement): string
    {
        $number = str_replace(['/', '-', ' '], '_', $agreement->agreement_number ?? 'UNKNOWN');
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
        if (empty($phone)) {
            return 'N/A';
        }

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
            'LOAN' => 'Loan',
            'ADVANCE' => 'Advance Payment',
            'DEPOSIT' => 'Security Deposit',
            'BUSINESS' => 'Business Transaction',
            'PERSONAL' => 'Personal Transaction',
            'OTHER' => 'Other',
        ];

        return $map[$purpose] ?? ucfirst(strtolower($purpose));
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
            'pending' => 'Pending Confirmation',
            'pending_counterparty' => 'Pending Confirmation',
            'confirmed' => 'Confirmed by Both Parties',
            'active' => 'Active',
            'rejected' => 'Rejected',
            'disputed' => 'Disputed',
            'completed' => 'Completed',
            'expired' => 'Expired',
            'cancelled' => 'Cancelled',
            'PENDING' => 'Pending Confirmation',
            'CONFIRMED' => 'Confirmed by Both Parties',
            'ACTIVE' => 'Active',
            'REJECTED' => 'Rejected',
            'DISPUTED' => 'Disputed',
            'COMPLETED' => 'Completed',
            'EXPIRED' => 'Expired',
            'CANCELLED' => 'Cancelled',
        ];

        return $map[$status] ?? ucfirst(strtolower($status));
    }

    /**
     * Convert amount to words (fallback method).
     *
     * @param float $amount
     * @return string
     */
    protected function convertAmountToWords(float $amount): string
    {
        if ($amount == 0) {
            return 'Rupees Zero Only';
        }

        $amount = round($amount, 2);
        $rupees = floor($amount);

        $ones = [
            '', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine',
            'Ten', 'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen',
            'Seventeen', 'Eighteen', 'Nineteen',
        ];

        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $words = 'Rupees ';

        if ($rupees >= 10000000) {
            $crores = floor($rupees / 10000000);
            $words .= $this->numberToWordsSimple($crores, $ones, $tens) . ' Crore ';
            $rupees %= 10000000;
        }

        if ($rupees >= 100000) {
            $lakhs = floor($rupees / 100000);
            $words .= $this->numberToWordsSimple($lakhs, $ones, $tens) . ' Lakh ';
            $rupees %= 100000;
        }

        if ($rupees >= 1000) {
            $thousands = floor($rupees / 1000);
            $words .= $this->numberToWordsSimple($thousands, $ones, $tens) . ' Thousand ';
            $rupees %= 1000;
        }

        if ($rupees >= 100) {
            $hundreds = floor($rupees / 100);
            $words .= $ones[$hundreds] . ' Hundred ';
            $rupees %= 100;
        }

        if ($rupees > 0) {
            $words .= $this->numberToWordsSimple($rupees, $ones, $tens);
        }

        return trim($words) . ' Only';
    }

    /**
     * Simple number to words helper.
     */
    private function numberToWordsSimple(int $num, array $ones, array $tens): string
    {
        if ($num < 20) {
            return $ones[$num];
        }
        return $tens[floor($num / 10)] . ($num % 10 ? '-' . $ones[$num % 10] : '');
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
            $disk = config('nearbuy.agreements.storage_disk', 'public');
            $path = $this->extractPathFromUrl($agreement->pdf_url);

            if ($path && Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
                $agreement->update(['pdf_url' => null]);
                return true;
            }

            // Try public disk as fallback
            if ($path && Storage::disk('public')->exists($path)) {
                Storage::disk('public')->delete($path);
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

        // Try to extract just the filename
        if (preg_match('#/([^/]+\.pdf)$#', $url, $matches)) {
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
        $this->deletePDF($agreement);
        return $this->generateAndUpload($agreement);
    }

    /**
     * Check if PDF service is properly configured.
     *
     * @return array Status check results
     */
    public function checkConfiguration(): array
    {
        $checks = [];

        // Check DomPDF
        $checks['dompdf'] = class_exists('Barryvdh\DomPDF\Facade\Pdf');

        // Check QR Code (optional)
        $checks['qrcode'] = class_exists('SimpleSoftwareIO\QrCode\Facades\QrCode');

        // Check view exists
        $checks['view_template'] = view()->exists('pdf.agreement');

        // Check storage
        $disk = config('nearbuy.agreements.storage_disk', 'public');
        try {
            Storage::disk($disk)->put('_test.txt', 'test');
            Storage::disk($disk)->delete('_test.txt');
            $checks['storage'] = true;
        } catch (\Exception $e) {
            $checks['storage'] = false;
            $checks['storage_error'] = $e->getMessage();
        }

        $checks['all_ok'] = $checks['dompdf'] && $checks['view_template'] && $checks['storage'];

        return $checks;
    }
}