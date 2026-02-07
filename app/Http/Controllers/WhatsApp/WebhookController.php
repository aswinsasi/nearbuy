<?php

namespace App\Http\Controllers\WhatsApp;

use App\DTOs\IncomingMessage;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWhatsAppMessage;
use App\Models\ConversationSession;
use App\Models\ProcessedWebhook;
use App\Enums\FlowType;
use App\Services\Flow\FlowRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Controller for handling WhatsApp Cloud API webhooks.
 *
 * Key Principles (NFR-P-01, NFR-S-02):
 * - Return 200 OK IMMEDIATELY (don't block webhook)
 * - Process messages asynchronously via job queue
 * - Deduplicate webhooks (WhatsApp sends twice sometimes)
 * - NEVER return error to WhatsApp (always 200)
 * - Catch ALL exceptions, log them, continue
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks
 */
class WebhookController extends Controller
{
    /**
     * Cache TTL for deduplication (5 minutes).
     */
    private const DEDUP_CACHE_TTL = 300;

    /**
     * Maximum time to spend processing before returning (ms).
     * Beyond this, we queue for async processing.
     */
    private const MAX_SYNC_PROCESSING_MS = 2000;

    public function __construct(
        protected FlowRouter $flowRouter,
    ) {}

    /**
     * Handle webhook verification (GET request).
     *
     * When you configure a webhook URL in Meta Developer Console,
     * Meta sends a GET request with challenge parameters.
     */
    public function verify(Request $request): Response
    {
        try {
            $mode = $request->query('hub_mode');
            $token = $request->query('hub_verify_token');
            $challenge = $request->query('hub_challenge');

            Log::info('WhatsApp Webhook: Verification request', [
                'mode' => $mode,
                'has_token' => !empty($token),
                'ip' => $request->ip(),
            ]);

            // Validate mode
            if ($mode !== 'subscribe') {
                Log::warning('WhatsApp Webhook: Invalid mode', ['mode' => $mode]);
                return response('Invalid mode', 403);
            }

            // Validate token
            $expectedToken = config('whatsapp.webhook.verify_token');

            if (empty($expectedToken)) {
                Log::error('WhatsApp Webhook: Verify token not configured');
                return response('Server configuration error', 500);
            }

            if ($token !== $expectedToken) {
                Log::warning('WhatsApp Webhook: Token mismatch');
                return response('Invalid verify token', 403);
            }

            Log::info('WhatsApp Webhook: Verification successful');

            return response($challenge, 200)
                ->header('Content-Type', 'text/plain');

        } catch (\Throwable $e) {
            Log::error('WhatsApp Webhook: Verification error', [
                'error' => $e->getMessage(),
            ]);
            return response('Error', 500);
        }
    }

    /**
     * Handle incoming webhook events (POST request).
     *
     * CRITICAL: Always return 200 OK quickly!
     * WhatsApp will retry if we don't respond in time,
     * causing duplicate processing.
     */
    public function handle(Request $request): JsonResponse
    {
        // ALWAYS return 200, even if processing fails
        // This prevents WhatsApp from retrying and causing duplicates
        try {
            $this->processWebhook($request);
        } catch (\Throwable $e) {
            // Log but don't fail — always return 200
            Log::error('WhatsApp Webhook: Processing error (returning 200 anyway)', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json(['status' => 'received']);
    }

    /**
     * Process the webhook payload.
     */
    private function processWebhook(Request $request): void
    {
        $payload = $request->all();

        // Log incoming webhook (minimal info for performance)
        Log::debug('WhatsApp Webhook: Received', [
            'object' => $payload['object'] ?? 'unknown',
        ]);

        // Validate payload structure
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            Log::debug('WhatsApp Webhook: Ignoring non-WhatsApp event');
            return;
        }

        // Process each entry
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $this->processEntry($entry);
        }
    }

    /**
     * Process a single webhook entry.
     */
    private function processEntry(array $entry): void
    {
        $changes = $entry['changes'] ?? [];

        foreach ($changes as $change) {
            try {
                $field = $change['field'] ?? '';

                if ($field !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $this->processValue($value);

            } catch (\Throwable $e) {
                Log::error('WhatsApp Webhook: Entry processing error', [
                    'error' => $e->getMessage(),
                    'field' => $change['field'] ?? 'unknown',
                ]);
                // Continue processing other entries
            }
        }
    }

    /**
     * Process the value object from a webhook change.
     */
    private function processValue(array $value): void
    {
        $metadata = $value['metadata'] ?? [];
        $contacts = $value['contacts'] ?? [];
        $messages = $value['messages'] ?? [];
        $statuses = $value['statuses'] ?? [];

        // Process incoming messages
        foreach ($messages as $message) {
            try {
                $contact = $contacts[0] ?? [];
                $this->processMessage($message, $contact, $metadata);
            } catch (\Throwable $e) {
                Log::error('WhatsApp Webhook: Message processing error', [
                    'error' => $e->getMessage(),
                    'message_id' => $message['id'] ?? 'unknown',
                ]);
                // Continue processing other messages
            }
        }

        // Process status updates (non-blocking)
        foreach ($statuses as $status) {
            try {
                $this->processStatus($status, $metadata);
            } catch (\Throwable $e) {
                Log::warning('WhatsApp Webhook: Status processing error', [
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Process an incoming message.
     *
     * Includes deduplication to handle WhatsApp's duplicate webhooks.
     */
    private function processMessage(array $message, array $contact, array $metadata): void
    {
        $messageId = $message['id'] ?? null;

        if (!$messageId) {
            Log::warning('WhatsApp Webhook: Message without ID');
            return;
        }

        // =====================================================
        // DEDUPLICATION: Skip if we've seen this message before
        // WhatsApp sometimes sends the same webhook twice
        // =====================================================
        if ($this->isDuplicateMessage($messageId)) {
            Log::debug('WhatsApp Webhook: Duplicate message skipped', [
                'message_id' => $messageId,
            ]);
            return;
        }

        // Mark as processed
        $this->markMessageProcessed($messageId);

        // Parse into DTO
        $incomingMessage = IncomingMessage::fromWebhook($message, $contact);

        Log::info('WhatsApp Webhook: Message received', [
            'from' => $this->maskPhone($incomingMessage->from),
            'type' => $incomingMessage->type,
            'message_id' => $this->truncateId($messageId),
        ]);

        // Dispatch for processing (pass raw data for async job)
        $this->dispatchMessage($incomingMessage, $metadata, $message, $contact);
    }

    /**
     * Check if this message has already been processed.
     */
    private function isDuplicateMessage(string $messageId): bool
    {
        $cacheKey = 'wa_msg_' . $messageId;

        // Check cache first (fast path)
        if (Cache::has($cacheKey)) {
            return true;
        }

        // Check database for persistence across restarts
        // (Only if ProcessedWebhook model exists)
        if (class_exists(ProcessedWebhook::class)) {
            return ProcessedWebhook::where('message_id', $messageId)->exists();
        }

        return false;
    }

    /**
     * Mark a message as processed.
     */
    private function markMessageProcessed(string $messageId): void
    {
        $cacheKey = 'wa_msg_' . $messageId;

        // Set in cache
        Cache::put($cacheKey, true, self::DEDUP_CACHE_TTL);

        // Persist to database (if model exists)
        if (class_exists(ProcessedWebhook::class)) {
            try {
                ProcessedWebhook::create([
                    'message_id' => $messageId,
                    'processed_at' => now(),
                ]);
            } catch (\Throwable $e) {
                // Ignore duplicate key errors
                Log::debug('WhatsApp Webhook: Could not persist message ID', [
                    'message_id' => $messageId,
                ]);
            }
        }
    }

    /**
     * Dispatch message to appropriate handler.
     *
     * For fast processing, handle synchronously.
     * For complex flows, dispatch to job queue.
     */
    private function dispatchMessage(IncomingMessage $message, array $metadata, array $rawMessage = [], array $contact = []): void
    {
        try {
            // Get or create session
            $session = $this->getOrCreateSession($message->from);

            // Update last activity
            $session->update(['last_activity_at' => now()]);

            // Check if we should process async
            if ($this->shouldProcessAsync($message, $session)) {
                // Dispatch to queue for async processing
                // Pass raw data since IncomingMessage is readonly and can't serialize well
                ProcessWhatsAppMessage::dispatch(
                    $rawMessage,
                    $session->id,
                    array_merge($metadata, ['contact' => $contact])
                )->onQueue('whatsapp');

                Log::debug('WhatsApp Webhook: Message queued for async processing', [
                    'from' => $this->maskPhone($message->from),
                ]);

                return;
            }

            // Process synchronously (fast path)
            $this->flowRouter->route($message, $session);

        } catch (\Throwable $e) {
            Log::error('WhatsApp Webhook: Dispatch error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'from' => $this->maskPhone($message->from),
            ]);

            // Don't rethrow — we already returned 200
        }
    }

    /**
     * Determine if message should be processed asynchronously.
     */
    private function shouldProcessAsync(IncomingMessage $message, ConversationSession $session): bool
    {
        // Media messages take longer (download required)
        if ($message->isMedia()) {
            return true;
        }

        // Complex flows (agreements, etc.) process async
        $complexFlows = [
            FlowType::AGREEMENT_CREATE->value,
            FlowType::PRODUCT_SEARCH->value,
            FlowType::FISH_POST_CATCH->value,
            FlowType::JOB_POST->value,
        ];

        if (in_array($session->current_flow, $complexFlows)) {
            return true;
        }

        // Default: sync for fast response
        return false;
    }

    /**
     * Get or create a conversation session for the phone number.
     */
    private function getOrCreateSession(string $phone): ConversationSession
    {
        return ConversationSession::firstOrCreate(
            ['phone' => $phone],
            [
                'current_flow' => FlowType::MAIN_MENU->value,
                'current_step' => 'idle',
                'temp_data' => [],
                'last_activity_at' => now(),
            ]
        );
    }

    /**
     * Process a status update.
     */
    private function processStatus(array $status, array $metadata): void
    {
        $messageId = $status['id'] ?? null;
        $statusType = $status['status'] ?? 'unknown';
        $recipientId = $status['recipient_id'] ?? null;

        Log::debug('WhatsApp Webhook: Status update', [
            'message_id' => $this->truncateId($messageId),
            'status' => $statusType,
            'recipient' => $this->maskPhone($recipientId),
        ]);

        // Handle failed messages
        if ($statusType === 'failed') {
            $this->handleFailedMessage($status);
        }

        // Dispatch status update event (non-blocking)
        // event(new MessageStatusUpdated($messageId, $statusType));
    }

    /**
     * Handle a failed message status.
     */
    private function handleFailedMessage(array $status): void
    {
        $messageId = $status['id'] ?? 'unknown';
        $errors = $status['errors'] ?? [];
        $recipientId = $status['recipient_id'] ?? null;

        foreach ($errors as $error) {
            Log::error('WhatsApp Webhook: Message delivery failed', [
                'message_id' => $this->truncateId($messageId),
                'recipient' => $this->maskPhone($recipientId),
                'error_code' => $error['code'] ?? null,
                'error_title' => $error['title'] ?? null,
                'error_message' => $error['message'] ?? null,
            ]);
        }

        // TODO: Implement retry with exponential backoff (NFR-R-02)
        // RetryFailedMessage::dispatch($messageId, $recipientId)
        //     ->delay(now()->addMinutes(1));
    }

    /**
     * Mask phone number for logging (privacy).
     */
    private function maskPhone(?string $phone): string
    {
        if (empty($phone) || strlen($phone) < 6) {
            return $phone ?? 'unknown';
        }

        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }

    /**
     * Truncate ID for logging (readability).
     */
    private function truncateId(?string $id): string
    {
        if (empty($id)) {
            return 'unknown';
        }

        if (strlen($id) <= 16) {
            return $id;
        }

        return substr($id, 0, 8) . '...' . substr($id, -4);
    }
}