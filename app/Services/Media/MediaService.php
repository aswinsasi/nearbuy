<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Service for handling media operations.
 *
 * Handles downloading media from WhatsApp and uploading to S3/storage.
 *
 * WhatsApp Media Download Process (2-step):
 * 1. Get media URL from media ID
 * 2. Download actual file content from URL
 *
 * @example
 * $mediaService = app(MediaService::class);
 *
 * // Download from WhatsApp and upload to S3
 * $result = $mediaService->downloadAndStore(
 *     mediaId: 'whatsapp_media_id',
 *     folder: 'offers',
 *     filename: 'offer_123.jpg'
 * );
 *
 * if ($result['success']) {
 *     $publicUrl = $result['url'];
 * }
 */
class MediaService
{
    protected string $apiVersion;
    protected string $baseUrl;
    protected string $accessToken;

    public function __construct()
    {
        $this->apiVersion = config('whatsapp.api.version', 'v18.0');
        $this->baseUrl = config('whatsapp.api.base_url', 'https://graph.facebook.com');
        $this->accessToken = config('whatsapp.api.access_token');
    }

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Media Download
    |--------------------------------------------------------------------------
    */

    /**
     * Download media from WhatsApp and store in configured storage.
     *
     * @param string $mediaId WhatsApp media ID
     * @param string $folder Storage folder (e.g., 'offers', 'requests')
     * @param string|null $filename Custom filename (auto-generated if null)
     * @return array{success: bool, url?: string, path?: string, mime_type?: string, error?: string}
     */
    public function downloadAndStore(string $mediaId, string $folder = 'media', ?string $filename = null): array
    {
        // Step 1: Get media URL from WhatsApp
        $mediaInfo = $this->getMediaUrl($mediaId);

        if (!$mediaInfo['success']) {
            return $mediaInfo;
        }

        // Step 2: Download the file content
        $download = $this->downloadFromUrl($mediaInfo['url']);

        if (!$download['success']) {
            return $download;
        }

        // Step 3: Determine file extension
        $extension = $this->getExtensionFromMimeType($download['mime_type']);

        // Step 4: Generate filename if not provided
        if (!$filename) {
            $filename = $this->generateFilename($extension);
        } elseif (!pathinfo($filename, PATHINFO_EXTENSION)) {
            $filename .= '.' . $extension;
        }

        // Step 5: Upload to storage
        return $this->uploadToStorage($download['content'], $folder, $filename, $download['mime_type']);
    }

    /**
     * Get media URL from WhatsApp media ID.
     *
     * @param string $mediaId
     * @return array{success: bool, url?: string, mime_type?: string, file_size?: int, error?: string}
     */
    public function getMediaUrl(string $mediaId): array
    {
        $url = "{$this->baseUrl}/{$this->apiVersion}/{$mediaId}";

        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(30)
                ->get($url);

            if (!$response->successful()) {
                $error = $response->json('error.message', 'Failed to get media URL');
                Log::error('WhatsApp media URL fetch failed', [
                    'media_id' => $mediaId,
                    'status' => $response->status(),
                    'error' => $error,
                ]);

                return [
                    'success' => false,
                    'error' => $error,
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
            Log::error('WhatsApp media URL fetch exception', [
                'media_id' => $mediaId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Download file content from WhatsApp media URL.
     *
     * @param string $url WhatsApp media URL (requires auth token)
     * @return array{success: bool, content?: string, mime_type?: string, error?: string}
     */
    public function downloadFromUrl(string $url): array
    {
        try {
            $response = Http::withToken($this->accessToken)
                ->timeout(config('whatsapp.media.download_timeout', 60))
                ->get($url);

            if (!$response->successful()) {
                Log::error('WhatsApp media download failed', [
                    'status' => $response->status(),
                ]);

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
            Log::error('WhatsApp media download exception', [
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
     * Upload content to storage.
     *
     * @param string $content File content
     * @param string $folder Storage folder
     * @param string $filename Filename with extension
     * @param string|null $mimeType MIME type
     * @return array{success: bool, url?: string, path?: string, error?: string}
     */
    public function uploadToStorage(string $content, string $folder, string $filename, ?string $mimeType = null): array
    {
        try {
            $disk = config('whatsapp.media.storage_disk', 's3');
            $basePath = config('whatsapp.media.storage_path', 'nearbuy');

            $fullPath = trim("{$basePath}/{$folder}/{$filename}", '/');

            // Upload to storage
            $uploaded = Storage::disk($disk)->put($fullPath, $content, [
                'visibility' => 'public',
                'ContentType' => $mimeType,
            ]);

            if (!$uploaded) {
                return [
                    'success' => false,
                    'error' => 'Failed to upload to storage',
                ];
            }

            // Get public URL
            $url = Storage::disk($disk)->url($fullPath);

            Log::info('Media uploaded to storage', [
                'path' => $fullPath,
                'disk' => $disk,
            ]);

            return [
                'success' => true,
                'url' => $url,
                'path' => $fullPath,
                'mime_type' => $mimeType,
            ];

        } catch (\Exception $e) {
            Log::error('Storage upload failed', [
                'folder' => $folder,
                'filename' => $filename,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Upload a file from local path to storage.
     *
     * @param string $localPath Local file path
     * @param string $folder Storage folder
     * @param string|null $filename Custom filename
     * @return array{success: bool, url?: string, path?: string, error?: string}
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
        $mimeType = mime_content_type($localPath);

        if (!$filename) {
            $extension = pathinfo($localPath, PATHINFO_EXTENSION);
            $filename = $this->generateFilename($extension);
        }

        return $this->uploadToStorage($content, $folder, $filename, $mimeType);
    }

    /**
     * Delete a file from storage.
     *
     * @param string $urlOrPath Full URL or storage path
     * @return bool
     */
    public function deleteFromStorage(string $urlOrPath): bool
    {
        try {
            $disk = config('whatsapp.media.storage_disk', 's3');

            // Extract path from URL if needed
            $path = $this->extractPathFromUrl($urlOrPath);

            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);

                Log::info('Media deleted from storage', ['path' => $path]);

                return true;
            }

            return false;

        } catch (\Exception $e) {
            Log::error('Storage delete failed', [
                'path' => $urlOrPath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Check if a file exists in storage.
     *
     * @param string $urlOrPath Full URL or storage path
     * @return bool
     */
    public function exists(string $urlOrPath): bool
    {
        $disk = config('whatsapp.media.storage_disk', 's3');
        $path = $this->extractPathFromUrl($urlOrPath);

        return Storage::disk($disk)->exists($path);
    }

    /**
     * Get temporary URL for private files.
     *
     * @param string $path Storage path
     * @param int $minutes Expiry in minutes
     * @return string|null
     */
    public function getTemporaryUrl(string $path, int $minutes = 60): ?string
    {
        try {
            $disk = config('whatsapp.media.storage_disk', 's3');

            return Storage::disk($disk)->temporaryUrl(
                $path,
                now()->addMinutes($minutes)
            );

        } catch (\Exception $e) {
            Log::error('Failed to generate temporary URL', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Validation Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Validate if the media type is allowed for offers.
     *
     * @param string $mimeType
     * @return bool
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
     * Validate file size.
     *
     * @param int $sizeBytes
     * @param int $maxMb Maximum size in MB
     * @return bool
     */
    public function isValidFileSize(int $sizeBytes, int $maxMb = 5): bool
    {
        return $sizeBytes <= ($maxMb * 1024 * 1024);
    }

    /**
     * Get media type (image or pdf).
     *
     * @param string $mimeType
     * @return string
     */
    public function getMediaType(string $mimeType): string
    {
        if (str_starts_with($mimeType, 'image/')) {
            return 'image';
        }

        if ($mimeType === 'application/pdf') {
            return 'pdf';
        }

        return 'unknown';
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Generate a unique filename.
     *
     * @param string $extension
     * @return string
     */
    protected function generateFilename(string $extension): string
    {
        $timestamp = now()->format('Ymd_His');
        $random = Str::random(8);

        return "{$timestamp}_{$random}.{$extension}";
    }

    /**
     * Get file extension from MIME type.
     *
     * @param string|null $mimeType
     * @return string
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
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
        ];

        return $map[$mimeType] ?? 'bin';
    }

    /**
     * Extract storage path from URL.
     *
     * @param string $urlOrPath
     * @return string
     */
    protected function extractPathFromUrl(string $urlOrPath): string
    {
        // If it's already a path (no http), return as is
        if (!str_starts_with($urlOrPath, 'http')) {
            return $urlOrPath;
        }

        // Try to extract path from S3-style URL
        $basePath = config('whatsapp.media.storage_path', 'nearbuy');

        if (preg_match("#/{$basePath}/(.+)$#", $urlOrPath, $matches)) {
            return "{$basePath}/{$matches[1]}";
        }

        // Try to get just the path portion
        $parsed = parse_url($urlOrPath);
        return ltrim($parsed['path'] ?? $urlOrPath, '/');
    }

    /**
     * Get MIME type from file extension.
     *
     * @param string $extension
     * @return string
     */
    protected function getMimeTypeFromExtension(string $extension): string
    {
        $map = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
        ];

        return $map[strtolower($extension)] ?? 'application/octet-stream';
    }
}