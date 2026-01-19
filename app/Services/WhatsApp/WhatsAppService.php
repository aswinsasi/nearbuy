<?php

namespace App\Services\WhatsApp;

use App\Services\WhatsApp\Builders\TextMessageBuilder;
use App\Services\WhatsApp\Builders\ButtonMessageBuilder;
use App\Services\WhatsApp\Builders\ListMessageBuilder;
use App\Services\WhatsApp\Builders\LocationRequestBuilder;
use App\Services\WhatsApp\Builders\LocationMessageBuilder;
use App\Services\WhatsApp\Builders\ImageMessageBuilder;
use App\Services\WhatsApp\Builders\DocumentMessageBuilder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * WhatsApp Cloud API Service for NearBuy.
 */
class WhatsAppService
{
    private string $apiVersion;
    private string $baseUrl;
    private string $phoneNumberId;
    private string $accessToken;

    public function __construct()
    {
        $this->apiVersion = config('whatsapp.api.version', 'v18.0');
        $this->baseUrl = config('whatsapp.api.base_url', 'https://graph.facebook.com');
        $this->phoneNumberId = config('whatsapp.api.phone_number_id');
        $this->accessToken = config('whatsapp.api.access_token');
    }

    /*
    |--------------------------------------------------------------------------
    | Message Sending Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Send a text message.
     */
    public function sendText(string $to, string $body, bool $previewUrl = false): array
    {
        $payload = TextMessageBuilder::create($to)
            ->body($body)
            ->previewUrl($previewUrl)
            ->build();

        return $this->sendMessage($payload);
    }

    /**
     * Send an interactive button message.
     */
    public function sendButtons(
        string $to,
        string $body,
        array $buttons,
        ?string $header = null,
        ?string $footer = null
    ): array {
        $builder = ButtonMessageBuilder::create($to)
            ->body($body)
            ->buttons($buttons);

        if ($header) {
            $builder->header($header);
        }

        if ($footer) {
            $builder->footer($footer);
        }

        return $this->sendMessage($builder->build());
    }

    /**
     * Send an interactive list message.
     */
    public function sendList(
        string $to,
        string $body,
        string $buttonText,
        array $sections,
        ?string $header = null,
        ?string $footer = null
    ): array {
        $builder = ListMessageBuilder::create($to)
            ->body($body)
            ->buttonText($buttonText)
            ->sections($sections);

        if ($header) {
            $builder->header($header);
        }

        if ($footer) {
            $builder->footer($footer);
        }

        return $this->sendMessage($builder->build());
    }

    /**
     * Request user's location.
     */
    public function requestLocation(string $to, string $body): array
    {
        $payload = LocationRequestBuilder::create($to)
            ->body($body)
            ->build();

        return $this->sendMessage($payload);
    }

    /**
     * Send a location message.
     */
    public function sendLocation(
        string $to,
        float $latitude,
        float $longitude,
        ?string $name = null,
        ?string $address = null
    ): array {
        $builder = LocationMessageBuilder::create($to)
            ->coordinates($latitude, $longitude);

        if ($name) {
            $builder->name($name);
        }

        if ($address) {
            $builder->address($address);
        }

        return $this->sendMessage($builder->build());
    }

    /**
     * Send an image message.
     */
    public function sendImage(
        string $to,
        string $urlOrMediaId,
        ?string $caption = null,
        bool $isMediaId = false
    ): array {
        $builder = ImageMessageBuilder::create($to);

        if ($isMediaId) {
            $builder->mediaId($urlOrMediaId);
        } else {
            $builder->url($urlOrMediaId);
        }

        if ($caption) {
            $builder->caption($caption);
        }

        return $this->sendMessage($builder->build());
    }

    /**
     * Send a document message.
     */
    public function sendDocument(
        string $to,
        string $urlOrMediaId,
        ?string $filename = null,
        ?string $caption = null,
        bool $isMediaId = false
    ): array {
        $builder = DocumentMessageBuilder::create($to);

        if ($isMediaId) {
            $builder->mediaId($urlOrMediaId);
        } else {
            $builder->url($urlOrMediaId);
        }

        if ($filename) {
            $builder->filename($filename);
        }

        if ($caption) {
            $builder->caption($caption);
        }

        return $this->sendMessage($builder->build());
    }

    /**
     * Send a raw message payload (for custom builders).
     * 
     * UPDATED: Now includes API response logging for debugging
     */
    public function sendMessage(array $payload): array
    {
        // Validate configuration
        if (empty($this->phoneNumberId)) {
            Log::error('WhatsApp API: Phone number ID not configured');
            return [
                'success' => false,
                'error' => 'Phone number ID not configured',
            ];
        }

        if (empty($this->accessToken)) {
            Log::error('WhatsApp API: Access token not configured');
            return [
                'success' => false,
                'error' => 'Access token not configured',
            ];
        }

        $url = "{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $this->logOutgoing($payload);

        try {
            $response = $this->httpClient()
                ->timeout(30)
                ->post($url, $payload);

            $result = $this->handleResponse($response, 'sendMessage');

            // ============ API RESPONSE LOGGING FOR DEBUGGING ============
            Log::info('WhatsApp API Response', [
                'to' => $payload['to'] ?? 'unknown',
                'type' => $payload['type'] ?? 'unknown',
                'success' => $result['success'],
                'message_id' => $result['message_id'] ?? null,
                'error' => $result['error'] ?? null,
                'error_code' => $result['error_code'] ?? null,
                'http_status' => $response->status(),
            ]);

            return $result;

        } catch (\Exception $e) {
            Log::error('WhatsApp API Exception', [
                'to' => $payload['to'] ?? 'unknown',
                'type' => $payload['type'] ?? 'unknown',
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            return $this->handleException($e, 'sendMessage');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Media Handling Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Download media from WhatsApp (2-step process).
     */
    public function downloadMedia(string $mediaId): array
    {
        $mediaUrl = $this->getMediaUrl($mediaId);

        if (!$mediaUrl['success']) {
            return $mediaUrl;
        }

        return $this->downloadFromUrl($mediaUrl['url']);
    }

    /**
     * Get the download URL for a media ID.
     */
    public function getMediaUrl(string $mediaId): array
    {
        $url = "{$this->baseUrl}/{$this->apiVersion}/{$mediaId}";

        try {
            $response = $this->httpClient()->get($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'Failed to get media URL: ' . $response->body(),
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'url' => $data['url'] ?? null,
                'mime_type' => $data['mime_type'] ?? null,
                'sha256' => $data['sha256'] ?? null,
                'file_size' => $data['file_size'] ?? null,
            ];
        } catch (\Exception $e) {
            return $this->handleException($e, 'getMediaUrl');
        }
    }

    /**
     * Download file content from a WhatsApp media URL.
     */
    public function downloadFromUrl(string $url): array
    {
        try {
            $response = $this->httpClient()
                ->timeout(config('whatsapp.media.download_timeout', 30))
                ->get($url);

            if (!$response->successful()) {
                return [
                    'success' => false,
                    'error' => 'Failed to download media: HTTP ' . $response->status(),
                ];
            }

            return [
                'success' => true,
                'content' => $response->body(),
                'mime_type' => $response->header('Content-Type'),
            ];
        } catch (\Exception $e) {
            return $this->handleException($e, 'downloadFromUrl');
        }
    }

    /**
     * Download media and upload to S3.
     */
    public function downloadAndStoreMedia(string $mediaId, string $path): array
    {
        $download = $this->downloadMedia($mediaId);

        if (!$download['success']) {
            return $download;
        }

        try {
            $disk = config('whatsapp.media.storage_disk', 's3');
            $fullPath = config('whatsapp.media.storage_path', 'whatsapp-media') . '/' . $path;

            Storage::disk($disk)->put($fullPath, $download['content']);

            $url = Storage::disk($disk)->url($fullPath);

            Log::info('WhatsApp media stored', [
                'media_id' => $mediaId,
                'path' => $fullPath,
                'url' => $url,
            ]);

            return [
                'success' => true,
                'url' => $url,
                'path' => $fullPath,
                'mime_type' => $download['mime_type'],
            ];
        } catch (\Exception $e) {
            return $this->handleException($e, 'downloadAndStoreMedia');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Message Status Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Mark a message as read.
     */
    public function markAsRead(string $messageId): array
    {
        $url = "{$this->baseUrl}/{$this->apiVersion}/{$this->phoneNumberId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'status' => 'read',
            'message_id' => $messageId,
        ];

        try {
            $response = $this->httpClient()->post($url, $payload);

            return $this->handleResponse($response, 'markAsRead');
        } catch (\Exception $e) {
            return $this->handleException($e, 'markAsRead');
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Builder Factory Methods
    |--------------------------------------------------------------------------
    */

    public function text(string $to): TextMessageBuilder
    {
        return TextMessageBuilder::create($to);
    }

    public function buttons(string $to): ButtonMessageBuilder
    {
        return ButtonMessageBuilder::create($to);
    }

    public function list(string $to): ListMessageBuilder
    {
        return ListMessageBuilder::create($to);
    }

    public function locationRequest(string $to): LocationRequestBuilder
    {
        return LocationRequestBuilder::create($to);
    }

    public function location(string $to): LocationMessageBuilder
    {
        return LocationMessageBuilder::create($to);
    }

    public function image(string $to): ImageMessageBuilder
    {
        return ImageMessageBuilder::create($to);
    }

    public function document(string $to): DocumentMessageBuilder
    {
        return DocumentMessageBuilder::create($to);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Get configured HTTP client.
     */
    private function httpClient(): PendingRequest
    {
        return Http::withToken($this->accessToken)
            ->acceptJson()
            ->contentType('application/json')
            ->retry(
                config('whatsapp.retry.max_attempts', 3),
                config('whatsapp.retry.delay', 1000),
                function (\Exception $exception, PendingRequest $request) {
                    if ($exception instanceof \Illuminate\Http\Client\RequestException) {
                        $status = $exception->response->status();
                        return in_array($status, config('whatsapp.retry.retry_on_status', [429, 500, 502, 503, 504]));
                    }
                    return false;
                }
            );
    }

    /**
     * Handle API response.
     */
    private function handleResponse(Response $response, string $operation): array
    {
        $data = $response->json();

        if (!$response->successful()) {
            $error = $data['error']['message'] ?? 'Unknown error';
            $errorCode = $data['error']['code'] ?? 'unknown';

            Log::error("WhatsApp API error in {$operation}", [
                'status' => $response->status(),
                'error' => $error,
                'error_code' => $errorCode,
                'response' => $data,
            ]);

            return [
                'success' => false,
                'error' => $error,
                'error_code' => $errorCode,
                'status' => $response->status(),
            ];
        }

        return [
            'success' => true,
            'message_id' => $data['messages'][0]['id'] ?? null,
            'data' => $data,
        ];
    }

    /**
     * Handle exception.
     */
    private function handleException(\Exception $e, string $operation): array
    {
        Log::error("WhatsApp API exception in {$operation}", [
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        return [
            'success' => false,
            'error' => $e->getMessage(),
            'exception' => get_class($e),
        ];
    }

    /**
     * Log outgoing message.
     */
    private function logOutgoing(array $payload): void
    {
        if (!config('whatsapp.logging.log_outgoing', true)) {
            return;
        }

        Log::info('WhatsApp outgoing message', [
            'to' => $payload['to'] ?? 'unknown',
            'type' => $payload['type'] ?? 'unknown',
        ]);
    }
}