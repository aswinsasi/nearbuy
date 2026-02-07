<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Media Service - Handles media download from WhatsApp and storage.
 *
 * TWO-STEP MEDIA DOWNLOAD (SRS Section 4.1.3):
 * 1. GET /v18.0/{MEDIA_ID} with Authorization → get media URL
 * 2. GET {media_url} with Authorization → download file content
 *
 * IMPORTANT: WhatsApp media URLs expire quickly - must download promptly!
 *
 * @srs-ref Section 4.1.3 - Media Download API
 * @srs-ref Section 2.5 - Design Constraints (Media Download expiry)
 *
 * @example
 * $result = $mediaService->downloadAndStore('media_id_123', 'offers');
 * if ($result['success']) {
 *     $publicUrl = $result['url'];
 * }
 */
class MediaService
{
    protected string $apiVersion;
    protected string $baseUrl;
    protected string $accessToken;
    protected int $downloadTimeout;
    protected int $urlFetchTimeout;

    public function __construct()
    {
        $this->apiVersion = config('whatsapp.api.version', 'v18.0');
        $this->baseUrl = config('whatsapp.api.base_url', 'https://graph.facebook.com');
        $this->accessToken = config('whatsapp.api.access_token', '');
        $this->downloadTimeout = config('whatsapp.media.download_timeout', 60);
        $this->urlFetchTimeout = config('whatsapp.media.url_fetch_timeout', 30);
    }

    /*
    |--------------------------------------------------------------------------
    | Main Download Method
    |--------------------------------------------------------------------------
    */

    /**
     * Download media from WhatsApp and store in configured storage.
     *
     * This is the main method to use. It:
     * 1. Fetches media URL from WhatsApp (Step 1)
     * 2. Downloads actual content (Step 2)
     * 3. Uploads to cloud storage
     * 4. Returns public URL
     *
     * @param string $mediaId WhatsApp media ID from webhook
     * @param string $folder Storage folder (e.g., 'offers', 'catches', 'agreements')
     * @param string|null $filename Custom filename (auto-generated if null)
     * @return array{success: bool, url?: string, path?: string, mime_type?: string, size?: int, error?: string}
     *
     * @srs-ref Section 4.1.3 - Two-step media download process
     */
    public function downloadAndStore(string $mediaId, string $folder = 'media', ?string $filename = null): array
    {
        $startTime = microtime(true);

        Log::info('MediaService: Starting download', [
            'media_id' => $mediaId,
            'folder' => $folder,
        ]);

        // STEP 1: Get media URL from WhatsApp
        $mediaInfo = $this->getMediaUrl($mediaId);

        if (!$mediaInfo['success']) {
            return $mediaInfo;
        }

        if (empty($mediaInfo['url'])) {
            return [
                'success' => false,
                'error' => 'No media URL returned from WhatsApp',
            ];
        }

        // STEP 2: Download actual file content
        $download = $this->downloadFromUrl($mediaInfo['url']);

        if (!$download['success']) {
            return $download;
        }

        // Determine file extension from MIME type
        $mimeType = $download['mime_type'] ?? $mediaInfo['mime_type'] ?? 'application/octet-stream';
        $extension = $this->getExtensionFromMimeType($mimeType);

        // Generate filename if not provided
        if (!$filename) {
            $filename = $this->generateFilename($extension);
        } elseif (!pathinfo($filename, PATHINFO_EXTENSION)) {
            $filename .= '.' . $extension;
        }

        // STEP 3: Upload to storage
        $result = $this->uploadToStorage(
            $download['content'],
            $folder,
            $filename,
            $mimeType
        );

        if ($result['success']) {
            $result['mime_type'] = $mimeType;
            $result['size'] = strlen($download['content']);
            $result['download_time_ms'] = round((microtime(true) - $startTime) * 1000);

            Log::info('MediaService: Download complete', [
                'media_id' => $mediaId,
                'path' => $result['path'],
                'size' => $result['size'],
                'time_ms' => $result['download_time_ms'],
            ]);
        }

        return $result;
    }

    /*
    |--------------------------------------------------------------------------
    | Step 1: Get Media URL
    |--------------------------------------------------------------------------
    */

    /**
     * Get media URL from WhatsApp Media API.
     *
     * STEP 1 of two-step process.
     * Makes authenticated request to: GET /v18.0/{MEDIA_ID}
     *
     * @param string $mediaId WhatsApp media ID
     * @return array{success: bool, url?: string, mime_type?: string, sha256?: string, file_size?: int, error?: string}
     *
     * @srs-ref Section 4.1.3 - Step 1: GET /v18.0/{MEDIA_ID} with Authorization header
     */
    public function getMediaUrl(string $mediaId): array
    {
        $url = "{$this->baseUrl}/{$this->apiVersion}/{$mediaId}";

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout($this->urlFetchTimeout)
                ->get($url);

            if (!$response->successful()) {
                $error = $response->json('error.message', 'Failed to get media URL');
                $errorCode = $response->json('error.code', 'unknown');

                Log::error('MediaService: URL fetch failed', [
                    'media_id' => $mediaId,
                    'status' => $response->status(),
                    'error' => $error,
                    'error_code' => $errorCode,
                ]);

                return [
                    'success' => false,
                    'error' => "WhatsApp API error: {$error}",
                    'error_code' => $errorCode,
                ];
            }

            $data = $response->json();

            return [
                'success' => true,
                'url' => $data['url'] ?? null,
                'mime_type' => $data['mime_type'] ?? null,
                'sha256' => $data['sha256'] ?? null,
                'file_size' => $data['file_size'] ?? null,
                'messaging_product' => $data['messaging_product'] ?? null,
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('MediaService: Connection timeout on URL fetch', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Connection timeout while fetching media URL',
            ];

        } catch (\Exception $e) {
            Log::error('MediaService: URL fetch exception', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Step 2: Download Content
    |--------------------------------------------------------------------------
    */

    /**
     * Download actual file content from WhatsApp media URL.
     *
     * STEP 2 of two-step process.
     * The URL requires Authorization header (same token as Step 1).
     *
     * @param string $url WhatsApp media URL (from Step 1)
     * @return array{success: bool, content?: string, mime_type?: string, error?: string}
     *
     * @srs-ref Section 4.1.3 - Step 2: GET {media_url} with Authorization header
     * @srs-ref NFR-P-04 - Media download shall complete within 30 seconds
     */
    public function downloadFromUrl(string $url): array
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->timeout($this->downloadTimeout)
                ->withOptions([
                    'stream' => false, // Get full content
                ])
                ->get($url);

            if (!$response->successful()) {
                Log::error('MediaService: Content download failed', [
                    'status' => $response->status(),
                    'url_prefix' => substr($url, 0, 50) . '...',
                ]);

                return [
                    'success' => false,
                    'error' => 'Failed to download media: HTTP ' . $response->status(),
                ];
            }

            $content = $response->body();
            $mimeType = $response->header('Content-Type');

            // Validate we got actual content
            if (empty($content)) {
                return [
                    'success' => false,
                    'error' => 'Downloaded content is empty',
                ];
            }

            return [
                'success' => true,
                'content' => $content,
                'mime_type' => $mimeType,
                'size' => strlen($content),
            ];

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            Log::error('MediaService: Download timeout', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Download timeout - file may be too large',
            ];

        } catch (\Exception $e) {
            Log::error('MediaService: Download exception', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Storage Operations
    |--------------------------------------------------------------------------
    */

    /**
     * Upload content to configured storage (S3, local, etc.).
     *
     * @param string $content File content
     * @param string $folder Storage folder
     * @param string $filename Filename with extension
     * @param string|null $mimeType MIME type for Content-Type header
     * @return array{success: bool, url?: string, path?: string, error?: string}
     */
    public function uploadToStorage(string $content, string $folder, string $filename, ?string $mimeType = null): array
    {
        try {
            $disk = config('whatsapp.media.storage_disk', 's3');
            $basePath = config('whatsapp.media.storage_path', 'nearbuy');

            // Build full path: nearbuy/offers/20250207_abc123.jpg
            $fullPath = trim("{$basePath}/{$folder}/{$filename}", '/');

            // Upload with public visibility
            $uploaded = Storage::disk($disk)->put($fullPath, $content, [
                'visibility' => 'public',
                'ContentType' => $mimeType,
            ]);

            if (!$uploaded) {
                return [
                    'success' => false,
                    'error' => 'Storage upload failed',
                ];
            }

            // Get public URL
            $url = Storage::disk($disk)->url($fullPath);

            return [
                'success' => true,
                'url' => $url,
                'path' => $fullPath,
            ];

        } catch (\Exception $e) {
            Log::error('MediaService: Storage upload failed', [
                'folder' => $folder,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => 'Storage error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Upload a local file to storage.
     */
    public function uploadFile(string $localPath, string $folder, ?string $filename = null): array
    {
        if (!file_exists($localPath)) {
            return [
                'success' => false,
                'error' => 'Local file not found',
            ];
        }

        $content = file_get_contents($localPath);
        $mimeType = mime_content_type($localPath) ?: 'application/octet-stream';

        if (!$filename) {
            $extension = pathinfo($localPath, PATHINFO_EXTENSION) ?: 'bin';
            $filename = $this->generateFilename($extension);
        }

        return $this->uploadToStorage($content, $folder, $filename, $mimeType);
    }

    /**
     * Delete file from storage.
     */
    public function deleteFromStorage(string $urlOrPath): bool
    {
        try {
            $disk = config('whatsapp.media.storage_disk', 's3');
            $path = $this->extractPathFromUrl($urlOrPath);

            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
                Log::info('MediaService: File deleted', ['path' => $path]);
                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('MediaService: Delete failed', [
                'path' => $urlOrPath,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Check if file exists in storage.
     */
    public function exists(string $urlOrPath): bool
    {
        $disk = config('whatsapp.media.storage_disk', 's3');
        $path = $this->extractPathFromUrl($urlOrPath);
        return Storage::disk($disk)->exists($path);
    }

    /**
     * Get temporary URL for private files (S3 pre-signed URL).
     */
    public function getTemporaryUrl(string $path, int $minutes = 60): ?string
    {
        try {
            $disk = config('whatsapp.media.storage_disk', 's3');
            return Storage::disk($disk)->temporaryUrl($path, now()->addMinutes($minutes));
        } catch (\Exception $e) {
            Log::error('MediaService: Temporary URL failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    /**
     * Validate media type for offers (images + PDF only).
     *
     * @srs-ref FR-OFR-01 - Accept image (JPEG, PNG) and PDF
     */
    public function isValidOfferMedia(string $mimeType): bool
    {
        $allowed = [
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
            'application/pdf',
        ];

        return in_array(strtolower($mimeType), $allowed);
    }

    /**
     * Validate media type for fish catches (images only).
     *
     * @srs-ref PM-008 - Require fresh photo upload for each catch
     */
    public function isValidFishMedia(string $mimeType): bool
    {
        return str_starts_with(strtolower($mimeType), 'image/');
    }

    /**
     * Validate file size.
     */
    public function isValidFileSize(int $sizeBytes, int $maxMb = 5): bool
    {
        return $sizeBytes <= ($maxMb * 1024 * 1024);
    }

    /**
     * Get media category (image, pdf, video, etc.).
     */
    public function getMediaType(string $mimeType): string
    {
        $mime = strtolower($mimeType);

        if (str_starts_with($mime, 'image/')) {
            return 'image';
        }
        if ($mime === 'application/pdf') {
            return 'pdf';
        }
        if (str_starts_with($mime, 'video/')) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio/')) {
            return 'audio';
        }

        return 'unknown';
    }

    /*
    |--------------------------------------------------------------------------
    | Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Generate unique filename.
     */
    protected function generateFilename(string $extension): string
    {
        $timestamp = now()->format('Ymd_His');
        $random = Str::random(8);
        return "{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Get file extension from MIME type.
     */
    protected function getExtensionFromMimeType(?string $mimeType): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            'video/mp4' => 'mp4',
            'video/3gpp' => '3gp',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/aac' => 'aac',
        ];

        return $map[$mimeType] ?? 'bin';
    }

    /**
     * Extract storage path from URL.
     */
    protected function extractPathFromUrl(string $urlOrPath): string
    {
        // If already a path, return as-is
        if (!str_starts_with($urlOrPath, 'http')) {
            return $urlOrPath;
        }

        // Extract path from S3-style URL
        $basePath = config('whatsapp.media.storage_path', 'nearbuy');

        if (preg_match("#/{$basePath}/(.+)$#", $urlOrPath, $matches)) {
            return "{$basePath}/{$matches[1]}";
        }

        // Fallback: parse URL path
        $parsed = parse_url($urlOrPath);
        return ltrim($parsed['path'] ?? $urlOrPath, '/');
    }
}