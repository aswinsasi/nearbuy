<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppMessage;
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
 *
 * UX-layer additions:
 * - sendMenuHint()       → universal "type menu" reminder (NFR-U-04)
 * - sendFriendlyError()  → maps error codes to friendly messages (NFR-U-03, NFR-U-05)
 * - Message length logging for body/button/list payloads
 * - All outbound messages logged to WhatsAppMessage model
 */
class WhatsAppService
{
    private string $apiVersion;
    private string $baseUrl;
    private string $phoneNumberId;
    private string $accessToken;

    /**
     * Friendly error messages mapped by error type.
     * Supports English and Malayalam (NFR-U-03, NFR-U-05).
     *
     * Each entry: type => ['en' => ..., 'ml' => ..., 'recovery_en' => ..., 'recovery_ml' => ...]
     */
    private const FRIENDLY_ERRORS = [
        'invalid_input' => [
            'en' => "🤔 That doesn't look right.",
            'ml' => "🤔 ശരിയല്ല എന്ന് തോന്നുന്നു.",
            'recovery_en' => "Please try again with the correct format.",
            'recovery_ml' => "ശരിയായ രൂപത്തിൽ വീണ്ടും ശ്രമിക്കൂ.",
        ],
        'invalid_phone' => [
            'en' => "📱 That phone number doesn't look right.",
            'ml' => "📱 ഫോൺ നമ്പർ ശരിയല്ല.",
            'recovery_en' => "Please enter a 10-digit number (e.g., 9876543210).",
            'recovery_ml' => "10 അക്ക നമ്പർ നൽകുക (ഉദാ: 9876543210).",
        ],
        'invalid_amount' => [
            'en' => "💰 Please enter a valid amount.",
            'ml' => "💰 ശരിയായ തുക നൽകുക.",
            'recovery_en' => "Enter numbers only (e.g., 5000).",
            'recovery_ml' => "അക്കങ്ങൾ മാത്രം നൽകുക (ഉദാ: 5000).",
        ],
        'not_found' => [
            'en' => "🔍 Sorry, we couldn't find that.",
            'ml' => "🔍 കണ്ടെത്താൻ കഴിഞ്ഞില്ല.",
            'recovery_en' => "Try a different search or check the spelling.",
            'recovery_ml' => "മറ്റൊന്ന് തിരയുക അല്ലെങ്കിൽ സ്പെല്ലിംഗ് പരിശോധിക്കുക.",
        ],
        'no_shops_nearby' => [
            'en' => "📍 No shops found nearby.",
            'ml' => "📍 സമീപത്ത് കടകൾ കണ്ടെത്തിയില്ല.",
            'recovery_en' => "Try updating your location or check back later.",
            'recovery_ml' => "ലൊക്കേഷൻ അപ്ഡേറ്റ് ചെയ്യുക അല്ലെങ്കിൽ പിന്നീട് നോക്കുക.",
        ],
        'expired' => [
            'en' => "⏰ This has expired.",
            'ml' => "⏰ ഇതിന്റെ സമയം കഴിഞ്ഞു.",
            'recovery_en' => "Please start a new request.",
            'recovery_ml' => "പുതിയ അഭ്യർത്ഥന തുടങ്ങുക.",
        ],
        'location_required' => [
            'en' => "📍 We need your location to continue.",
            'ml' => "📍 തുടരാൻ നിങ്ങളുടെ ലൊക്കേഷൻ വേണം.",
            'recovery_en' => "Please tap the location button and share your location.",
            'recovery_ml' => "ലൊക്കേഷൻ ബട്ടൺ അമർത്തി ലൊക്കേഷൻ ഷെയർ ചെയ്യുക.",
        ],
        'server_error' => [
            'en' => "😓 Something went wrong on our end.",
            'ml' => "😓 ഞങ്ങളുടെ ഭാഗത്ത് ഒരു പിഴവ്.",
            'recovery_en' => "Please try again in a moment. Type *menu* for Main Menu.",
            'recovery_ml' => "ദയവായി അൽപ്പം കഴിഞ്ഞ് ശ്രമിക്കുക. *menu* ടൈപ്പ് ചെയ്യുക.",
        ],
        'duplicate' => [
            'en' => "⚠️ You've already done this.",
            'ml' => "⚠️ നിങ്ങൾ ഇത് ഇതിനകം ചെയ്തു.",
            'recovery_en' => "No need to do it again. Type *menu* for Main Menu.",
            'recovery_ml' => "വീണ്ടും ചെയ്യേണ്ട. *menu* ടൈപ്പ് ചെയ്യുക.",
        ],
        'permission_denied' => [
            'en' => "🚫 You don't have access to this feature.",
            'ml' => "🚫 ഈ ഫീച്ചർ ഉപയോഗിക്കാൻ അനുമതിയില്ല.",
            'recovery_en' => "This may be available to shop owners only. Type *menu* for Main Menu.",
            'recovery_ml' => "ഇത് കട ഉടമകൾക്ക് മാത്രം ആകാം. *menu* ടൈപ്പ് ചെയ്യുക.",
        ],
        'rate_limited' => [
            'en' => "🐢 Too many requests! Please slow down.",
            'ml' => "🐢 വളരെ വേഗം! ദയവായി പതുക്കെ.",
            'recovery_en' => "Wait a few seconds and try again.",
            'recovery_ml' => "കുറച്ച് സെക്കൻഡ് കാത്തിരുന്ന് വീണ്ടും ശ്രമിക്കുക.",
        ],
    ];

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

    /*
    |--------------------------------------------------------------------------
    | UX Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Send a menu hint message (NFR-U-04).
     *
     * Short reminder that the user can type "menu" at any time
     * to return to the main menu.
     *
     * @param string $lang 'en' or 'ml'
     */
    public function sendMenuHint(string $to, string $lang = 'en'): array
    {
        $body = ($lang === 'ml')
            ? "💡 ഏത് സമയത്തും *menu* ടൈപ്പ് ചെയ്ത് മെയിൻ മെനുവിലേക്ക് പോകാം."
            : "💡 Type *menu* anytime to go back to the Main Menu.";

        return $this->sendText($to, $body);
    }

    /**
     * Send a user-friendly error message (NFR-U-03, NFR-U-05).
     *
     * Maps an error type to a localized, friendly message with
     * clear recovery instructions. Always includes a menu hint
     * so the user is never stuck.
     *
     * @param string $to         Recipient phone number
     * @param string $errorType  One of the keys in FRIENDLY_ERRORS
     * @param string $lang       'en' or 'ml'
     * @param string|null $extra Optional extra context to append
     *
     * @example
     * $whatsapp->sendFriendlyError($phone, 'invalid_phone');
     * $whatsapp->sendFriendlyError($phone, 'no_shops_nearby', 'ml');
     * $whatsapp->sendFriendlyError($phone, 'server_error', 'en', 'Error code: WH-042');
     */
    public function sendFriendlyError(
        string $to,
        string $errorType,
        string $lang = 'en',
        ?string $extra = null
    ): array {
        $error = self::FRIENDLY_ERRORS[$errorType] ?? self::FRIENDLY_ERRORS['server_error'];

        $messageSuffix = ($lang === 'ml') ? 'ml' : 'en';
        $recoverySuffix = ($lang === 'ml') ? 'recovery_ml' : 'recovery_en';

        $body = $error[$messageSuffix] . "\n" . $error[$recoverySuffix];

        if ($extra) {
            $body .= "\n\n_{$extra}_";
        }

        Log::info('WhatsApp: sending friendly error', [
            'to' => $to,
            'error_type' => $errorType,
            'lang' => $lang,
        ]);

        return $this->sendText($to, $body);
    }

    /**
     * Get all available friendly error types.
     *
     * Useful for validation or documentation.
     *
     * @return array<string>
     */
    public static function friendlyErrorTypes(): array
    {
        return array_keys(self::FRIENDLY_ERRORS);
    }

    /**
     * Send a raw message payload (for custom builders).
     *
     * All outbound messages are:
     * 1. Length-logged for debugging
     * 2. Logged to WhatsAppMessage model for audit trail
     * 3. API response logged with status
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
        $this->logMessageLengths($payload);

        try {
            $response = $this->httpClient()
                ->timeout(30)
                ->post($url, $payload);

            $result = $this->handleResponse($response, 'sendMessage');

            // API response logging for debugging
            Log::info('WhatsApp API Response', [
                'to' => $payload['to'] ?? 'unknown',
                'type' => $payload['type'] ?? 'unknown',
                'success' => $result['success'],
                'message_id' => $result['message_id'] ?? null,
                'error' => $result['error'] ?? null,
                'error_code' => $result['error_code'] ?? null,
                'http_status' => $response->status(),
            ]);

            // Log to WhatsAppMessage model for audit trail
            $this->logToModel($payload, $result);

            return $result;

        } catch (\Exception $e) {
            Log::error('WhatsApp API Exception', [
                'to' => $payload['to'] ?? 'unknown',
                'type' => $payload['type'] ?? 'unknown',
                'error' => $e->getMessage(),
                'exception_class' => get_class($e),
            ]);

            // Log failed attempt to model
            $this->logToModel($payload, [
                'success' => false,
                'error' => $e->getMessage(),
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

    /**
     * Log message body/payload lengths for debugging.
     *
     * Helps identify messages that are too long for good UX
     * or approaching WhatsApp API limits.
     */
    private function logMessageLengths(array $payload): void
    {
        $type = $payload['type'] ?? 'unknown';
        $lengths = ['type' => $type, 'to' => $payload['to'] ?? 'unknown'];

        switch ($type) {
            case 'text':
                $body = $payload['text']['body'] ?? '';
                $lengths['body_length'] = mb_strlen($body);
                $lengths['exceeds_soft_limit'] = mb_strlen($body) > TextMessageBuilder::SOFT_BODY_LENGTH;
                break;

            case 'interactive':
                $interactive = $payload['interactive'] ?? [];
                $subType = $interactive['type'] ?? 'unknown';
                $lengths['interactive_type'] = $subType;

                // Body length
                $body = $interactive['body']['text'] ?? '';
                $lengths['body_length'] = mb_strlen($body);

                // Header length
                if (isset($interactive['header']['text'])) {
                    $lengths['header_length'] = mb_strlen($interactive['header']['text']);
                }

                // Button/item counts
                if ($subType === 'button') {
                    $buttons = $interactive['action']['buttons'] ?? [];
                    $lengths['button_count'] = count($buttons);
                    $lengths['button_titles'] = array_map(
                        fn($btn) => $btn['reply']['title'] ?? '',
                        $buttons
                    );
                } elseif ($subType === 'list') {
                    $sections = $interactive['action']['sections'] ?? [];
                    $itemCount = 0;
                    foreach ($sections as $section) {
                        $itemCount += count($section['rows'] ?? []);
                    }
                    $lengths['section_count'] = count($sections);
                    $lengths['total_items'] = $itemCount;
                }
                break;
        }

        Log::debug('WhatsApp message lengths', $lengths);
    }

    /**
     * Log outbound message to WhatsAppMessage model.
     *
     * Creates an audit trail of all messages sent through the platform.
     * Gracefully handles failures to avoid blocking message delivery.
     */
    private function logToModel(array $payload, array $result): void
    {
        try {
            $phone = $payload['to'] ?? 'unknown';
            $type = $payload['type'] ?? 'unknown';
            $wamid = $result['message_id'] ?? ('local_' . uniqid());

            // Extract meaningful content summary for the log
            $content = $this->extractContentSummary($payload);

            $status = $result['success'] ? 'sent' : 'failed';
            $errorMessage = $result['success'] ? null : ($result['error'] ?? 'Unknown error');

            WhatsAppMessage::logOutbound($phone, $wamid, $type, $content);

            // If failed, update the status
            if (!$result['success']) {
                WhatsAppMessage::where('wamid', $wamid)
                    ->update([
                        'status' => 'failed',
                        'error_message' => $errorMessage,
                    ]);
            }
        } catch (\Exception $e) {
            // Never let logging failures block message delivery
            Log::warning('WhatsApp: failed to log outbound message to model', [
                'error' => $e->getMessage(),
                'to' => $payload['to'] ?? 'unknown',
            ]);
        }
    }

    /**
     * Extract a content summary from the payload for logging.
     *
     * Stores enough context to understand the message without
     * duplicating the full payload.
     */
    private function extractContentSummary(array $payload): array
    {
        $type = $payload['type'] ?? 'unknown';

        switch ($type) {
            case 'text':
                return [
                    'body' => mb_substr($payload['text']['body'] ?? '', 0, 500),
                ];

            case 'interactive':
                $interactive = $payload['interactive'] ?? [];
                $summary = [
                    'interactive_type' => $interactive['type'] ?? 'unknown',
                    'body' => mb_substr($interactive['body']['text'] ?? '', 0, 300),
                ];

                if (isset($interactive['header']['text'])) {
                    $summary['header'] = $interactive['header']['text'];
                }

                if (($interactive['type'] ?? '') === 'button') {
                    $summary['buttons'] = array_map(
                        fn($btn) => $btn['reply']['id'] ?? '',
                        $interactive['action']['buttons'] ?? []
                    );
                }

                return $summary;

            case 'image':
                return [
                    'url' => $payload['image']['link'] ?? null,
                    'caption' => mb_substr($payload['image']['caption'] ?? '', 0, 200),
                ];

            case 'document':
                return [
                    'url' => $payload['document']['link'] ?? null,
                    'filename' => $payload['document']['filename'] ?? null,
                ];

            case 'location':
                return [
                    'latitude' => $payload['location']['latitude'] ?? null,
                    'longitude' => $payload['location']['longitude'] ?? null,
                    'name' => $payload['location']['name'] ?? null,
                ];

            default:
                return ['raw_type' => $type];
        }
    }
}