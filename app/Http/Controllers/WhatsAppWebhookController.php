<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for handling WhatsApp Cloud API webhooks.
 *
 * This controller handles two types of requests:
 * 1. GET - Webhook verification during initial setup
 * 2. POST - Incoming messages and status updates
 *
 * @see https://developers.facebook.com/docs/whatsapp/cloud-api/webhooks/components
 */
class WhatsAppWebhookController extends Controller
{
    /**
     * Handle webhook verification request from Meta.
     *
     * When you configure a webhook URL in Meta Developer Console,
     * Meta sends a GET request with these query parameters:
     * - hub.mode: Should be "subscribe"
     * - hub.verify_token: The token you configured (must match your env)
     * - hub.challenge: A random string to echo back
     *
     * @param Request $request
     * @return Response
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        Log::info('WhatsApp Webhook: Verification attempt', [
            'mode' => $mode,
            'token_provided' => !empty($token),
            'challenge_provided' => !empty($challenge),
        ]);

        // Validate the verification request
        if ($mode !== 'subscribe') {
            Log::warning('WhatsApp Webhook: Invalid mode', ['mode' => $mode]);
            return response('Invalid mode', Response::HTTP_FORBIDDEN);
        }

        $expectedToken = config('whatsapp.webhook.verify_token');

        if (empty($expectedToken)) {
            Log::error('WhatsApp Webhook: Verify token not configured');
            return response('Server configuration error', Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        if ($token !== $expectedToken) {
            Log::warning('WhatsApp Webhook: Token mismatch');
            return response('Invalid verify token', Response::HTTP_FORBIDDEN);
        }

        Log::info('WhatsApp Webhook: Verification successful');

        // Return the challenge to complete verification
        return response($challenge, Response::HTTP_OK)
            ->header('Content-Type', 'text/plain');
    }

    /**
     * Handle incoming webhook events from WhatsApp.
     *
     * This receives various event types:
     * - messages: Incoming messages from users
     * - statuses: Message delivery/read status updates
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        // Log the incoming webhook (be careful with PII in production)
        if (config('whatsapp.logging.log_webhooks')) {
            Log::info('WhatsApp Webhook: Received', [
                'object' => $payload['object'] ?? 'unknown',
                'has_entry' => isset($payload['entry']),
            ]);
        }

        // Validate basic payload structure
        if (!isset($payload['object']) || $payload['object'] !== 'whatsapp_business_account') {
            Log::warning('WhatsApp Webhook: Invalid object type', [
                'object' => $payload['object'] ?? 'missing',
            ]);

            return response()->json(['status' => 'ignored']);
        }

        // Process each entry in the webhook
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $this->processEntry($entry);
        }

        // Always return 200 quickly to acknowledge receipt
        // Actual processing should be done asynchronously via queues
        return response()->json(['status' => 'received']);
    }

    /**
     * Process a single webhook entry.
     *
     * @param array $entry
     * @return void
     */
    private function processEntry(array $entry): void
    {
        $changes = $entry['changes'] ?? [];

        foreach ($changes as $change) {
            if (($change['field'] ?? '') !== 'messages') {
                continue;
            }

            $value = $change['value'] ?? [];
            $this->processValue($value);
        }
    }

    /**
     * Process the value object from a webhook change.
     *
     * @param array $value
     * @return void
     */
    private function processValue(array $value): void
    {
        $metadata = $value['metadata'] ?? [];
        $contacts = $value['contacts'] ?? [];
        $messages = $value['messages'] ?? [];
        $statuses = $value['statuses'] ?? [];

        // Process incoming messages
        foreach ($messages as $message) {
            $this->processMessage($message, $contacts, $metadata);
        }

        // Process status updates
        foreach ($statuses as $status) {
            $this->processStatus($status, $metadata);
        }
    }

    /**
     * Process an incoming message.
     *
     * This extracts the message details and dispatches it
     * to the appropriate flow handler.
     *
     * @param array $message
     * @param array $contacts
     * @param array $metadata
     * @return void
     */
    private function processMessage(array $message, array $contacts, array $metadata): void
    {
        $from = $message['from'] ?? null;
        $messageId = $message['id'] ?? null;
        $timestamp = $message['timestamp'] ?? null;
        $type = $message['type'] ?? 'unknown';

        // Get contact info
        $contact = $contacts[0] ?? [];
        $profileName = $contact['profile']['name'] ?? null;

        Log::info('WhatsApp Webhook: Processing message', [
            'from' => $this->maskPhoneNumber($from),
            'type' => $type,
            'message_id' => $messageId,
        ]);

        // Extract message content based on type
        $content = $this->extractMessageContent($message, $type);

        // TODO: Dispatch to message processing service/job
        // Example:
        // ProcessWhatsAppMessage::dispatch([
        //     'from' => $from,
        //     'message_id' => $messageId,
        //     'timestamp' => $timestamp,
        //     'type' => $type,
        //     'content' => $content,
        //     'profile_name' => $profileName,
        //     'metadata' => $metadata,
        // ]);

        // For now, log the extracted content
        Log::debug('WhatsApp Webhook: Message content extracted', [
            'type' => $type,
            'content_keys' => array_keys($content),
        ]);
    }

    /**
     * Extract content from a message based on its type.
     *
     * @param array $message
     * @param string $type
     * @return array
     */
    private function extractMessageContent(array $message, string $type): array
    {
        return match ($type) {
            'text' => [
                'body' => $message['text']['body'] ?? '',
            ],

            'interactive' => $this->extractInteractiveContent($message),

            'location' => [
                'latitude' => $message['location']['latitude'] ?? null,
                'longitude' => $message['location']['longitude'] ?? null,
                'name' => $message['location']['name'] ?? null,
                'address' => $message['location']['address'] ?? null,
            ],

            'image' => [
                'id' => $message['image']['id'] ?? null,
                'mime_type' => $message['image']['mime_type'] ?? null,
                'sha256' => $message['image']['sha256'] ?? null,
                'caption' => $message['image']['caption'] ?? null,
            ],

            'document' => [
                'id' => $message['document']['id'] ?? null,
                'mime_type' => $message['document']['mime_type'] ?? null,
                'sha256' => $message['document']['sha256'] ?? null,
                'filename' => $message['document']['filename'] ?? null,
                'caption' => $message['document']['caption'] ?? null,
            ],

            'button' => [
                'text' => $message['button']['text'] ?? '',
                'payload' => $message['button']['payload'] ?? '',
            ],

            default => [
                'raw' => $message[$type] ?? [],
            ],
        };
    }

    /**
     * Extract content from an interactive message response.
     *
     * @param array $message
     * @return array
     */
    private function extractInteractiveContent(array $message): array
    {
        $interactive = $message['interactive'] ?? [];
        $interactiveType = $interactive['type'] ?? 'unknown';

        return match ($interactiveType) {
            'button_reply' => [
                'type' => 'button_reply',
                'id' => $interactive['button_reply']['id'] ?? null,
                'title' => $interactive['button_reply']['title'] ?? null,
            ],

            'list_reply' => [
                'type' => 'list_reply',
                'id' => $interactive['list_reply']['id'] ?? null,
                'title' => $interactive['list_reply']['title'] ?? null,
                'description' => $interactive['list_reply']['description'] ?? null,
            ],

            'nfm_reply' => [
                'type' => 'flow_reply',
                'response_json' => $interactive['nfm_reply']['response_json'] ?? null,
                'body' => $interactive['nfm_reply']['body'] ?? null,
                'name' => $interactive['nfm_reply']['name'] ?? null,
            ],

            default => [
                'type' => $interactiveType,
                'raw' => $interactive,
            ],
        };
    }

    /**
     * Process a message status update.
     *
     * Status types: sent, delivered, read, failed
     *
     * @param array $status
     * @param array $metadata
     * @return void
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
            'recipient' => $this->maskPhoneNumber($recipientId),
        ]);

        // Handle errors
        if ($statusType === 'failed') {
            $errors = $status['errors'] ?? [];
            foreach ($errors as $error) {
                Log::error('WhatsApp Webhook: Message failed', [
                    'message_id' => $messageId,
                    'error_code' => $error['code'] ?? null,
                    'error_title' => $error['title'] ?? null,
                    'error_message' => $error['message'] ?? null,
                ]);
            }
        }

        // TODO: Update message status in database
        // Example:
        // UpdateMessageStatus::dispatch($messageId, $statusType, $timestamp);
    }

    /**
     * Mask a phone number for logging (privacy).
     *
     * @param string|null $phone
     * @return string|null
     */
    private function maskPhoneNumber(?string $phone): ?string
    {
        if (empty($phone) || strlen($phone) < 6) {
            return $phone;
        }

        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }
}