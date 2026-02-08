<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Agreement;
use App\Services\PDF\AgreementPDFService;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job: Generate Agreement PDF.
 *
 * Generates PDF asynchronously and sends to both parties.
 *
 * @srs-ref FR-AGR-20 Generate PDF on mutual confirmation
 * @srs-ref FR-AGR-25 Send PDF to BOTH parties via WhatsApp
 */
class GenerateAgreementPDF implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max attempts.
     */
    public int $tries = 3;

    /**
     * Backoff between retries (seconds).
     */
    public array $backoff = [60, 180, 300]; // 1min, 3min, 5min

    /**
     * Delete if model missing.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Agreement $agreement,
        public bool $notifyParties = true,
        public bool $sendFollowUpButtons = true,
    ) {}

    /**
     * Execute the job.
     *
     * @srs-ref FR-AGR-20, FR-AGR-25
     */
    public function handle(
        AgreementPDFService $pdfService,
        WhatsAppService $whatsApp,
    ): void {
        try {
            Log::info('Starting PDF generation job', [
                'agreement_id' => $this->agreement->id,
                'agreement_number' => $this->agreement->agreement_number,
                'attempt' => $this->attempts(),
            ]);

            // Check configuration
            $configCheck = $pdfService->checkConfiguration();
            if (!$configCheck['all_ok']) {
                Log::error('PDF service configuration issue', $configCheck);

                if (!$configCheck['view_template']) {
                    throw new \Exception('PDF template view not found');
                }
                if (!$configCheck['dompdf']) {
                    throw new \Exception('DomPDF package not installed');
                }
                if (!$configCheck['storage']) {
                    throw new \Exception('Storage not configured: ' . ($configCheck['storage_error'] ?? 'unknown'));
                }
            }

            // Refresh agreement with relationships
            $this->agreement->refresh();
            $this->agreement->load(['fromUser', 'toUser']);

            // Generate PDF (FR-AGR-20)
            $pdfUrl = $pdfService->generateAndUpload($this->agreement);

            Log::info('Agreement PDF generated', [
                'agreement_id' => $this->agreement->id,
                'pdf_url' => $pdfUrl,
            ]);

            // Send to both parties (FR-AGR-25)
            if ($this->notifyParties) {
                $this->sendPDFToParties($whatsApp, $pdfUrl);
            }

        } catch (\Exception $e) {
            Log::error('PDF generation failed', [
                'agreement_id' => $this->agreement->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Notify on final failure
            if ($this->attempts() >= $this->tries) {
                $this->notifyGenerationFailed($whatsApp);
            }

            throw $e;
        }
    }

    /**
     * Send PDF to BOTH parties.
     *
     * @srs-ref FR-AGR-25 Send PDF to BOTH parties via WhatsApp
     */
    protected function sendPDFToParties(WhatsAppService $whatsApp, string $pdfUrl): void
    {
        $agreement = $this->agreement;
        $filename = "Agreement_{$agreement->agreement_number}.pdf";

        $caption = "📄 *Your Agreement Document*\n\n" .
            "Agreement #: *{$agreement->agreement_number}*\n" .
            "Amount: ₹" . number_format($agreement->amount) . "\n\n" .
            "✅ Confirmed by both parties.\n" .
            "🔒 This is your official record.";

        // Send to CREATOR (fromUser)
        $creatorPhone = $agreement->fromUser?->phone ?? $agreement->from_phone;
        if ($creatorPhone) {
            $this->sendPdfToPhone($whatsApp, $creatorPhone, $pdfUrl, $filename, $caption, true);
        }

        // Send to COUNTERPARTY (to_phone)
        // Note: They may not be registered (FR-AGR-13), so we use to_phone directly
        $counterpartyPhone = $agreement->to_phone;
        if ($counterpartyPhone && $counterpartyPhone !== $creatorPhone) {
            $this->sendPdfToPhone($whatsApp, $counterpartyPhone, $pdfUrl, $filename, $caption, false);
        }
    }

    /**
     * Send PDF to a single phone.
     */
    protected function sendPdfToPhone(
        WhatsAppService $whatsApp,
        string $phone,
        string $pdfUrl,
        string $filename,
        string $caption,
        bool $isCreator,
    ): void {
        try {
            // Send PDF document
            $whatsApp->sendDocument($phone, $pdfUrl, $filename, $caption);

            // Send follow-up buttons
            if ($this->sendFollowUpButtons) {
                $buttons = $isCreator
                    ? [
                        ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                        ['id' => 'create_agreement', 'title' => '➕ New Agreement'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ]
                    : [
                        ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ];

                $whatsApp->sendButtons(
                    $phone,
                    "📋 Agreement #{$this->agreement->agreement_number} complete!\n\nEntha cheyyaan?",
                    $buttons
                );
            }

            Log::info('PDF sent to party', [
                'agreement_id' => $this->agreement->id,
                'phone' => substr($phone, 0, 5) . '***',
                'is_creator' => $isCreator,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send PDF to party', [
                'agreement_id' => $this->agreement->id,
                'phone' => substr($phone, 0, 5) . '***',
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify parties of generation failure.
     */
    protected function notifyGenerationFailed(WhatsAppService $whatsApp): void
    {
        $agreement = $this->agreement;

        $message = "⚠️ *PDF Generation Issue*\n\n" .
            "Agreement #{$agreement->agreement_number} PDF generate cheyyaan pattiyilla.\n\n" .
            "Don't worry - your agreement is still valid and recorded.\n\n" .
            "PDF udane ayakkaam. 🙏";

        // Notify creator
        $creatorPhone = $agreement->fromUser?->phone ?? $agreement->from_phone;
        if ($creatorPhone) {
            try {
                $whatsApp->sendButtons(
                    $creatorPhone,
                    $message,
                    [
                        ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ]
                );
            } catch (\Exception $e) {
                Log::error('Failed to notify creator of PDF failure', ['error' => $e->getMessage()]);
            }
        }

        // Notify counterparty
        $counterpartyPhone = $agreement->to_phone;
        if ($counterpartyPhone && $counterpartyPhone !== $creatorPhone) {
            try {
                $whatsApp->sendButtons(
                    $counterpartyPhone,
                    $message,
                    [
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ]
                );
            } catch (\Exception $e) {
                Log::error('Failed to notify counterparty of PDF failure', ['error' => $e->getMessage()]);
            }
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateAgreementPDF job failed permanently', [
            'agreement_id' => $this->agreement->id,
            'agreement_number' => $this->agreement->agreement_number ?? 'unknown',
            'error' => $exception->getMessage(),
        ]);

        // Mark PDF generation as failed
        try {
            $this->agreement->update([
                'pdf_generation_failed' => true,
            ]);
        } catch (\Exception $e) {
            // Silently fail
        }
    }

    /**
     * Get job tags.
     */
    public function tags(): array
    {
        return [
            'pdf',
            'agreement',
            'agreement:' . $this->agreement->id,
        ];
    }

    /**
     * Calculate retry delay.
     */
    public function retryAfter(): int
    {
        $attempt = $this->attempts();
        return $this->backoff[$attempt - 1] ?? 300;
    }
}