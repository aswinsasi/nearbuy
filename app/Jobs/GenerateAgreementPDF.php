<?php

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
 * Job for generating agreement PDFs asynchronously.
 *
 * Generates the PDF and sends it to both parties.
 *
 * @example
 * GenerateAgreementPDF::dispatch($agreement);
 *
 * // With notification to both parties
 * GenerateAgreementPDF::dispatch($agreement, notifyParties: true);
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
    public int $backoff = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Agreement $agreement,
        public bool $notifyParties = true,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(
        AgreementPDFService $pdfService,
        WhatsAppService $whatsApp,
    ): void {
        try {
            // Load relationships
            $this->agreement->load(['creator', 'counterpartyUser']);

            // Generate PDF
            $pdfUrl = $pdfService->generateAndUpload($this->agreement);

            Log::info('Agreement PDF generated', [
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
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Send PDF to both parties.
     */
    protected function sendPDFToParties(WhatsAppService $whatsApp, string $pdfUrl): void
    {
        $filename = "Agreement_{$this->agreement->agreement_number}.pdf";
        $caption = "📄 Your signed agreement document\n\nAgreement #: {$this->agreement->agreement_number}";

        // Send to creator
        if ($this->agreement->creator?->phone) {
            $whatsApp->sendDocument(
                $this->agreement->creator->phone,
                $pdfUrl,
                $filename,
                $caption
            );

            Log::debug('PDF sent to creator', [
                'agreement_id' => $this->agreement->id,
                'phone' => $this->agreement->creator->phone,
            ]);
        }

        // Send to counterparty
        if ($this->agreement->counterparty_phone) {
            $whatsApp->sendDocument(
                $this->agreement->counterparty_phone,
                $pdfUrl,
                $filename,
                $caption
            );

            Log::debug('PDF sent to counterparty', [
                'agreement_id' => $this->agreement->id,
                'phone' => $this->agreement->counterparty_phone,
            ]);
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('GenerateAgreementPDF job failed', [
            'agreement_id' => $this->agreement->id,
            'error' => $exception->getMessage(),
        ]);
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
}