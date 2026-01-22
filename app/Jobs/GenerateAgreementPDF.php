<?php

namespace App\Jobs;

use App\Models\Agreement;
use App\Services\PDF\AgreementPDFService;
use App\Services\WhatsApp\WhatsAppService;
use App\Services\WhatsApp\Messages\MessageTemplates;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * ENHANCED Job for generating agreement PDFs asynchronously.
 *
 * Key improvements:
 * 1. Better error handling with notifications
 * 2. Retry with exponential backoff
 * 3. Sends menu button after PDF
 * 4. Checks PDF service configuration first
 */
class GenerateAgreementPDF implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public array $backoff = [60, 180, 300]; // 1min, 3min, 5min

    /**
     * Delete the job if its models no longer exist.
     */
    public bool $deleteWhenMissingModels = true;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Agreement $agreement,
        public bool $notifyParties = true,
        public bool $sendFollowUpButtons = true,
    ) {}

    /**
     * Execute the job.
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

            // Check configuration first
            $configCheck = $pdfService->checkConfiguration();
            if (!$configCheck['all_ok']) {
                Log::error('PDF service configuration issue', $configCheck);
                
                if (!$configCheck['view_template']) {
                    throw new \Exception('PDF template view not found at resources/views/pdf/agreement.blade.php');
                }
                if (!$configCheck['dompdf']) {
                    throw new \Exception('DomPDF package not installed. Run: composer require barryvdh/laravel-dompdf');
                }
                if (!$configCheck['storage']) {
                    throw new \Exception('Storage not configured: ' . ($configCheck['storage_error'] ?? 'unknown error'));
                }
            }

            // Refresh agreement with relationships
            $this->agreement->refresh();
            $this->agreement->load(['fromUser', 'toUser']);

            // Generate PDF
            $pdfUrl = $pdfService->generateAndUpload($this->agreement);

            Log::info('Agreement PDF generated successfully', [
                'agreement_id' => $this->agreement->id,
                'agreement_number' => $this->agreement->agreement_number,
                'pdf_url' => $pdfUrl,
            ]);

            // Send to both parties if requested
            if ($this->notifyParties) {
                $this->sendPDFToParties($whatsApp, $pdfUrl);
            }

        } catch (\Exception $e) {
            Log::error('Failed to generate agreement PDF', [
                'agreement_id' => $this->agreement->id,
                'agreement_number' => $this->agreement->agreement_number ?? 'unknown',
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Notify parties of failure on final attempt
            if ($this->attempts() >= $this->tries) {
                $this->notifyGenerationFailed($whatsApp);
            }

            throw $e;
        }
    }

    /**
     * Send PDF to both parties.
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

        // Send to creator
        $creatorPhone = $agreement->creator?->phone ?? $agreement->from_phone;
        if ($creatorPhone) {
            try {
                $whatsApp->sendDocument(
                    $creatorPhone,
                    $pdfUrl,
                    $filename,
                    $caption
                );

                // Send follow-up with menu
                if ($this->sendFollowUpButtons) {
                    $whatsApp->sendButtons(
                        $creatorPhone,
                        "📋 Agreement #{$agreement->agreement_number} is now complete!\n\nWhat would you like to do next?",
                        [
                            ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                            ['id' => 'create_agreement', 'title' => '➕ New Agreement'],
                            ['id' => 'main_menu', 'title' => '🏠 Menu'],
                        ],
                        null,
                        MessageTemplates::GLOBAL_FOOTER
                    );
                }

                Log::debug('PDF sent to creator', [
                    'agreement_id' => $agreement->id,
                    'phone' => substr($creatorPhone, 0, 5) . '***',
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send PDF to creator', [
                    'agreement_id' => $agreement->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Send to counterparty
        $counterpartyPhone = $agreement->to_phone;
        if ($counterpartyPhone && $counterpartyPhone !== $creatorPhone) {
            try {
                $whatsApp->sendDocument(
                    $counterpartyPhone,
                    $pdfUrl,
                    $filename,
                    $caption
                );

                // Send follow-up with menu
                if ($this->sendFollowUpButtons) {
                    $whatsApp->sendButtons(
                        $counterpartyPhone,
                        "📋 Agreement #{$agreement->agreement_number} is now complete!\n\nWhat would you like to do next?",
                        [
                            ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                            ['id' => 'main_menu', 'title' => '🏠 Menu'],
                        ],
                        null,
                        MessageTemplates::GLOBAL_FOOTER
                    );
                }

                Log::debug('PDF sent to counterparty', [
                    'agreement_id' => $agreement->id,
                    'phone' => substr($counterpartyPhone, 0, 5) . '***',
                ]);
            } catch (\Exception $e) {
                Log::error('Failed to send PDF to counterparty', [
                    'agreement_id' => $agreement->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Notify parties that PDF generation failed.
     */
    protected function notifyGenerationFailed(WhatsAppService $whatsApp): void
    {
        $agreement = $this->agreement;
        
        $message = "⚠️ *PDF Generation Issue*\n\n" .
            "We couldn't generate the PDF for Agreement #{$agreement->agreement_number}.\n\n" .
            "Don't worry - your agreement is still valid and recorded.\n" .
            "We're working on fixing this. You'll receive the PDF soon.";

        // Notify creator
        $creatorPhone = $agreement->creator?->phone ?? $agreement->from_phone;
        if ($creatorPhone) {
            try {
                $whatsApp->sendButtons(
                    $creatorPhone,
                    $message,
                    [
                        ['id' => 'my_agreements', 'title' => '📋 My Agreements'],
                        ['id' => 'main_menu', 'title' => '🏠 Menu'],
                    ],
                    null,
                    MessageTemplates::GLOBAL_FOOTER
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
                    ],
                    null,
                    MessageTemplates::GLOBAL_FOOTER
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

        // Update agreement to mark PDF generation failed
        try {
            $this->agreement->update([
                'pdf_generation_failed' => true,
                'pdf_generation_error' => substr($exception->getMessage(), 0, 255),
            ]);
        } catch (\Exception $e) {
            // Silently fail - don't want to cause more issues
        }
    }

    /**
     * Get the tags for the job.
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
     * Calculate the number of seconds to wait before retrying.
     */
    public function retryAfter(): int
    {
        $attempt = $this->attempts();
        return $this->backoff[$attempt - 1] ?? 300;
    }
}