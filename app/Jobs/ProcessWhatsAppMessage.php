<?php

namespace App\Jobs;

use App\DTOs\IncomingMessage;
use App\Models\ConversationSession;
use App\Services\Flow\FlowRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for processing WhatsApp messages asynchronously.
 *
 * Used for:
 * - Media messages (require download)
 * - Complex flows (agreements, product search)
 * - Any processing that might exceed webhook timeout
 *
 * @srs-ref NFR-P-01: Webhook processing within 5 seconds
 */
class ProcessWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 10;

    /**
     * The maximum number of seconds the job can run.
     */
    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public readonly array $messageData,
        public readonly int $sessionId,
        public readonly array $metadata = [],
    ) {}

    /**
     * Execute the job.
     */
    public function handle(FlowRouter $flowRouter): void
    {
        try {
            // Reconstruct the IncomingMessage from serialized data
            $message = $this->reconstructMessage();

            if (!$message) {
                Log::error('ProcessWhatsAppMessage: Could not reconstruct message', [
                    'session_id' => $this->sessionId,
                ]);
                return;
            }

            // Get the session
            $session = ConversationSession::find($this->sessionId);

            if (!$session) {
                Log::error('ProcessWhatsAppMessage: Session not found', [
                    'session_id' => $this->sessionId,
                ]);
                return;
            }

            Log::debug('ProcessWhatsAppMessage: Processing', [
                'session_id' => $this->sessionId,
                'message_type' => $message->type,
                'from' => $this->maskPhone($message->from),
            ]);

            // Route through FlowRouter
            $flowRouter->route($message, $session);

            Log::debug('ProcessWhatsAppMessage: Completed', [
                'session_id' => $this->sessionId,
            ]);

        } catch (\Throwable $e) {
            Log::error('ProcessWhatsAppMessage: Failed', [
                'session_id' => $this->sessionId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Rethrow to trigger retry
            throw $e;
        }
    }

    /**
     * Reconstruct IncomingMessage from serialized data.
     */
    private function reconstructMessage(): ?IncomingMessage
    {
        try {
            return IncomingMessage::fromWebhook(
                $this->messageData,
                $this->metadata['contact'] ?? []
            );
        } catch (\Throwable $e) {
            Log::error('ProcessWhatsAppMessage: Message reconstruction failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('ProcessWhatsAppMessage: Job failed permanently', [
            'session_id' => $this->sessionId,
            'error' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ]);

        // TODO: Send error notification to user
        // TODO: Alert admin of repeated failures
    }

    /**
     * Mask phone number for logging.
     */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return $phone;
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }

    /**
     * Get the tags that should be assigned to the job.
     */
    public function tags(): array
    {
        return [
            'whatsapp',
            'session:' . $this->sessionId,
        ];
    }
}