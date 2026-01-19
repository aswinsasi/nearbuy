<?php

namespace App\Http\Controllers\WhatsApp;

use App\DTOs\IncomingMessage;
use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Enums\FlowType;
use App\Services\Flow\FlowRouter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Controller for handling WhatsApp Cloud API webhooks.
 *
 * Handles:
 * - GET: Webhook verification during initial setup
 * - POST: Incoming messages and status updates
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks
 */
class WebhookController extends Controller
{
    public function __construct(
        protected FlowRouter $flowRouter,
    ) {}

    /**
     * Handle webhook verification (GET request).
     *
     * When you configure a webhook URL in Meta Developer Console,
     * Meta sends a GET request with challenge parameters.
     *
     * Query parameters:
     * - hub.mode: Should be "subscribe"
     * - hub.verify_token: Your configured verify token
     * - hub.challenge: Random string to echo back
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        Log::info('WhatsApp Webhook: Verification request', [
            'mode' => $mode,
            'token_provided' => !empty($token),
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

        // Echo back the challenge to complete verification
        return response($challenge, 200)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Handle incoming webhook events (POST request).
     *
     * Receives various event types:
     * - messages: Incoming messages from users
     * - statuses: Message delivery/read status updates
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        // Log incoming webhook
        if (config('whatsapp.logging.log_webhooks', true)) {
            Log::channel(config('whatsapp.logging.channel', 'whatsapp'))
                ->info('WhatsApp Webhook: Received', [
                    'object' => $payload['object'] ?? 'unknown',
                ]);
        }

        // Validate payload structure
        if (($payload['object'] ?? '') !== 'whatsapp_business_account') {
            Log::debug('WhatsApp Webhook: Ignoring non-WhatsApp event');
            return response()->json(['status' => 'ignored']);
        }

        // Process each entry
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $this->processEntry($entry);
        }

        // Always return 200 quickly to acknowledge receipt
        return response()->json(['status' => 'received']);
    }

    /**
     * Process a single webhook entry.
     */
    private function processEntry(array $entry): void
    {
        $changes = $entry['changes'] ?? [];

        foreach ($changes as $change) {
            $field = $change['field'] ?? '';

            if ($field !== 'messages') {
                continue;
            }

            $value = $change['value'] ?? [];
            $this->processValue($value);
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
            $contact = $contacts[0] ?? [];
            $this->processMessage($message, $contact, $metadata);
        }

        // Process status updates
        foreach ($statuses as $status) {
            $this->processStatus($status, $metadata);
        }
    }

    /**
     * Process an incoming message.
     */
    private function processMessage(array $message, array $contact, array $metadata): void
    {
        try {
            // Parse into DTO
            $incomingMessage = IncomingMessage::fromWebhook($message, $contact);

            Log::info('WhatsApp Webhook: Message received', [
                'from' => $this->maskPhone($incomingMessage->from),
                'type' => $incomingMessage->type,
                'message_id' => $incomingMessage->messageId,
            ]);

            // Dispatch to message handler
            $this->dispatchMessage($incomingMessage, $metadata);

        } catch (\Exception $e) {
            Log::error('WhatsApp Webhook: Failed to process message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'message_id' => $message['id'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Dispatch message to appropriate handler.
     */
    private function dispatchMessage(IncomingMessage $message, array $metadata): void
    {
        Log::debug('WhatsApp Webhook: Message dispatched', [
            'from' => $message->from,
            'type' => $message->type,
            'content' => $this->getMessageSummary($message),
        ]);

        try {
            // Get or create session for this user
            $session = $this->getOrCreateSession($message->from);

            // Update last activity
            $session->update(['last_activity_at' => now()]);

            // Route message through FlowRouter
            $this->flowRouter->route($message, $session);

        } catch (\Exception $e) {
            Log::error('WhatsApp Webhook: Failed to dispatch message', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'from' => $this->maskPhone($message->from),
            ]);
        }
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
     * Get a summary of message content for logging.
     */
    private function getMessageSummary(IncomingMessage $message): string
    {
        if ($message->isText()) {
            return 'Text: ' . mb_substr($message->text ?? '', 0, 50);
        }

        if ($message->isButtonReply()) {
            return 'Button: ' . ($message->interactive['id'] ?? 'unknown');
        }

        if ($message->isListReply()) {
            return 'List: ' . ($message->interactive['id'] ?? 'unknown');
        }

        if ($message->isLocation()) {
            return 'Location: ' . ($message->location['latitude'] ?? 0) . ', ' . ($message->location['longitude'] ?? 0);
        }

        if ($message->isImage()) {
            return 'Image: ' . ($message->image['id'] ?? 'unknown');
        }

        if ($message->isDocument()) {
            return 'Document: ' . ($message->document['filename'] ?? 'unknown');
        }

        return 'Type: ' . $message->type;
    }

    /**
     * Process a status update.
     */
    private function processStatus(array $status, array $metadata): void
    {
        $messageId = $status['id'] ?? null;
        $statusType = $status['status'] ?? 'unknown';
        $timestamp = $status['timestamp'] ?? null;
        $recipientId = $status['recipient_id'] ?? null;

        Log::debug('WhatsApp Webhook: Status update', [
            'message_id' => $messageId,
            'status' => $statusType,
            'recipient' => $this->maskPhone($recipientId),
        ]);

        // Handle errors
        if ($statusType === 'failed') {
            $this->handleFailedMessage($status);
        }

        // TODO: Update message status in database
        // MessageStatus::updateStatus($messageId, $statusType, $timestamp);
    }

    /**
     * Handle a failed message status.
     */
    private function handleFailedMessage(array $status): void
    {
        $messageId = $status['id'] ?? 'unknown';
        $errors = $status['errors'] ?? [];

        foreach ($errors as $error) {
            Log::error('WhatsApp Webhook: Message delivery failed', [
                'message_id' => $messageId,
                'error_code' => $error['code'] ?? null,
                'error_title' => $error['title'] ?? null,
                'error_message' => $error['message'] ?? null,
                'error_details' => $error['error_data']['details'] ?? null,
            ]);
        }

        // TODO: Implement retry logic or notification
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
}