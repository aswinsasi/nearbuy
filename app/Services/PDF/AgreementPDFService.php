<?php

declare(strict_types=1);

namespace App\Services\PDF;

use App\Enums\AgreementPurpose;
use App\Models\Agreement;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use SimpleSoftwareIO\QrCode\Facades\QrCode;

/**
 * Agreement PDF Service.
 *
 * Generates professional PDF documents for confirmed agreements.
 *
 * @srs-ref FR-AGR-20 Generate PDF on mutual confirmation
 * @srs-ref FR-AGR-21 Include: agreement number, both party details, amount, purpose, timestamps
 * @srs-ref FR-AGR-22 Include amount IN WORDS
 * @srs-ref FR-AGR-23 Include QR code linking to verification URL
 * @srs-ref FR-AGR-24 Store PDF in cloud storage
 */
class AgreementPDFService
{
    /**
     * Generate PDF and upload to storage.
     *
     * @srs-ref FR-AGR-20, FR-AGR-24
     *
     * @return string Public URL of the PDF
     */
    public function generateAndUpload(Agreement $agreement): string
    {
        try {
            Log::info('Starting PDF generation', [
                'agreement_id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number,
            ]);

            // Load relationships
            $agreement->load(['fromUser', 'toUser']);

            // Generate PDF
            $pdf = $this->generatePDF($agreement);

            // Generate filename
            $filename = $this->generateFilename($agreement);

            // Upload to storage
            $path = $this->uploadToStorage($pdf, $filename);

            // Get URL
            $disk = config('nearbuy.agreements.storage_disk', 'public');
            $url = Storage::disk($disk)->url($path);

            // Update agreement with PDF URL (FR-AGR-24)
            $agreement->update(['pdf_url' => $url]);

            Log::info('Agreement PDF generated successfully', [
                'agreement_id' => $agreement->id,
                'agreement_number' => $agreement->agreement_number,
                'url' => $url,
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
     * @srs-ref FR-AGR-21, FR-AGR-22, FR-AGR-23
     */
    public function generatePDF(Agreement $agreement): \Barryvdh\DomPDF\PDF
    {
        // Load relationships
        if (!$agreement->relationLoaded('fromUser')) {
            $agreement->load(['fromUser', 'toUser']);
        }

        // Generate QR code (FR-AGR-23)
        $qrCode = $this->generateQRCode($agreement);

        // Prepare data for view (FR-AGR-21, FR-AGR-22)
        $data = $this->prepareViewData($agreement, $qrCode);

        // Check view exists
        if (!view()->exists('pdf.agreement')) {
            Log::error('PDF view template not found');
            throw new \Exception('PDF template not found at resources/views/pdf/agreement.blade.php');
        }

        // Generate PDF
        $pdf = Pdf::loadView('pdf.agreement', $data);

        // Configure
        $pdf->setPaper('a4', 'portrait');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'sans-serif',
            'dpi' => 150,
        ]);

        return $pdf;
    }

    /**
     * Generate QR code for verification.
     *
     * @srs-ref FR-AGR-23 QR code linking to verification URL
     */
    public function generateQRCode(Agreement $agreement): ?string
    {
        try {
            $verificationUrl = $agreement->getVerificationUrl();

            // Check if QrCode facade is available
            if (!class_exists('SimpleSoftwareIO\QrCode\Facades\QrCode')) {
                Log::warning('QrCode package not installed');
                return null;
            }

            // Use SVG format (no imagick required)
            $qrCode = QrCode::format('svg')
                ->size(150)
                ->margin(1)
                ->errorCorrection('M')
                ->generate($verificationUrl);

            return 'data:image/svg+xml;base64,' . base64_encode($qrCode);

        } catch (\Exception $e) {
            Log::warning('QR code generation failed', [
                'agreement_id' => $agreement->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Prepare data for PDF view.
     *
     * @srs-ref FR-AGR-21 Include: agreement number, both party details, amount, purpose, timestamps
     * @srs-ref FR-AGR-22 Include amount IN WORDS
     */
    protected function prepareViewData(Agreement $agreement, ?string $qrCode): array
    {
        $creator = $agreement->fromUser;

        // Determine creditor/debtor from direction
        $direction = $agreement->direction ?? 'giving';
        $isCreatorCreditor = ($direction === 'giving');

        if ($isCreatorCreditor) {
            $partyA = [
                'label' => 'CREDITOR (Lender)',
                'name' => $creator?->name ?? $agreement->from_name ?? 'Unknown',
                'phone' => $this->formatPhone($agreement->from_phone),
                'role' => 'Giving Money',
            ];
            $partyB = [
                'label' => 'DEBTOR (Borrower)',
                'name' => $agreement->to_name ?? 'Unknown',
                'phone' => $this->formatPhone($agreement->to_phone),
                'role' => 'Receiving Money',
            ];
        } else {
            $partyA = [
                'label' => 'DEBTOR (Borrower)',
                'name' => $creator?->name ?? $agreement->from_name ?? 'Unknown',
                'phone' => $this->formatPhone($agreement->from_phone),
                'role' => 'Receiving Money',
            ];
            $partyB = [
                'label' => 'CREDITOR (Lender)',
                'name' => $agreement->to_name ?? 'Unknown',
                'phone' => $this->formatPhone($agreement->to_phone),
                'role' => 'Giving Money',
            ];
        }

        // Amount in words (FR-AGR-22)
        $amountWords = $agreement->amount_in_words
            ?? Agreement::amountToWords($agreement->amount ?? 0);

        // Purpose
        // Purpose - handle both enum and string
        $purposeValue = $agreement->purpose_type instanceof AgreementPurpose
            ? $agreement->purpose_type->value
            : ($agreement->purpose_type ?? 'other');
        $purposeLabel = $this->formatPurpose($purposeValue);

        // Status
        $statusLabel = is_object($agreement->status)
            ? $agreement->status->label()
            : ucfirst($agreement->status ?? 'pending');

        return [
            'agreement' => $agreement,
            'agreementNumber' => $agreement->agreement_number,
            'partyA' => $partyA,
            'partyB' => $partyB,
            'amount' => number_format($agreement->amount ?? 0, 2),
            'amountWords' => $amountWords,
            'purpose' => $purposeLabel,
            'description' => $agreement->description ?? 'N/A',
            'dueDate' => $agreement->due_date
                ? $agreement->due_date->format('F j, Y')
                : 'No fixed date',
            'createdAt' => $agreement->created_at?->format('F j, Y \a\t h:i A') ?? 'Unknown',
            'creatorConfirmedAt' => $agreement->from_confirmed_at?->format('F j, Y \a\t h:i A'),
            'counterpartyConfirmedAt' => $agreement->to_confirmed_at?->format('F j, Y \a\t h:i A'),
            'status' => $statusLabel,
            'qrCode' => $qrCode,
            'verificationUrl' => $agreement->getVerificationUrl(),
            'generatedAt' => now()->format('F j, Y \a\t h:i A'),
        ];
    }

    /**
     * Upload PDF to storage.
     *
     * @srs-ref FR-AGR-24 Store PDF in cloud storage
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

                throw new \Exception('Failed to upload PDF: ' . $fallbackError->getMessage());
            }
        }
    }

    /**
     * Generate filename.
     */
    protected function generateFilename(Agreement $agreement): string
    {
        $number = str_replace(['/', '-', ' '], '_', $agreement->agreement_number ?? 'UNKNOWN');
        $timestamp = now()->format('Ymd_His');
        return "{$number}_{$timestamp}.pdf";
    }

    /**
     * Format phone for display.
     */
    protected function formatPhone(string $phone): string
    {
        if (empty($phone)) return 'N/A';

        // Remove 91 prefix
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
     * Format purpose.
     */
    protected function formatPurpose(string $purpose): string
    {
        $map = [
            'loan' => 'Loan',
            'advance' => 'Advance Payment',
            'deposit' => 'Security Deposit',
            'business' => 'Business Transaction',
            'other' => 'Other',
        ];

        return $map[strtolower($purpose)] ?? ucfirst($purpose);
    }

    /**
     * Delete PDF from storage.
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
            }

            $agreement->update(['pdf_url' => null]);
            return true;

        } catch (\Exception $e) {
            Log::error('Failed to delete PDF', [
                'agreement_id' => $agreement->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Extract path from URL.
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
     * Regenerate PDF.
     */
    public function regeneratePDF(Agreement $agreement): string
    {
        $this->deletePDF($agreement);
        return $this->generateAndUpload($agreement);
    }

    /**
     * Check if PDF service is configured.
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