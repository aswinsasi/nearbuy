<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to verify WhatsApp webhook signatures (NFR-S-02).
 *
 * Security Measures:
 * - HMAC-SHA256 signature verification
 * - Timing-safe comparison to prevent timing attacks
 * - Request body integrity verification
 * - Detailed logging for security auditing
 *
 * WhatsApp sends X-Hub-Signature-256 header with each webhook request.
 * We verify this to ensure the request came from Meta and wasn't tampered with.
 *
 * @see https://developers.facebook.com/docs/graph-api/webhooks/getting-started#verification-requests
 */
class VerifyWhatsAppWebhook
{
    /**
     * Signature header name.
     */
    private const SIGNATURE_HEADER = 'X-Hub-Signature-256';

    /**
     * Signature prefix.
     */
    private const SIGNATURE_PREFIX = 'sha256=';

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip verification for GET requests (webhook verification)
        if ($request->isMethod('GET')) {
            return $next($request);
        }

        // Skip verification in test mode or when explicitly disabled
        if ($this->shouldSkipVerification()) {
            return $next($request);
        }

        // Verify signature
        $verificationResult = $this->verifyRequest($request);

        if (!$verificationResult['valid']) {
            Log::warning('WhatsApp Webhook: Signature verification failed', [
                'reason' => $verificationResult['reason'],
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'error' => 'Signature verification failed',
            ], Response::HTTP_FORBIDDEN);
        }

        // Log successful verification (debug level)
        if (config('whatsapp.logging.log_webhooks', false)) {
            Log::debug('WhatsApp Webhook: Signature verified', [
                'ip' => $request->ip(),
            ]);
        }

        return $next($request);
    }

    /**
     * Check if signature verification should be skipped.
     */
    private function shouldSkipVerification(): bool
    {
        // Always verify in production
        if (app()->environment('production')) {
            return false;
        }

        // Check config flag
        if (!config('whatsapp.webhook.verify_signature', true)) {
            Log::debug('WhatsApp Webhook: Signature verification disabled by config');
            return true;
        }

        // Check test mode
        if (config('whatsapp.testing.enabled', false)) {
            Log::debug('WhatsApp Webhook: Test mode enabled, skipping verification');
            return true;
        }

        return false;
    }

    /**
     * Verify the webhook request.
     *
     * @return array{valid: bool, reason: string|null}
     */
    private function verifyRequest(Request $request): array
    {
        // Get signature from header
        $signature = $request->header(self::SIGNATURE_HEADER);

        if (empty($signature)) {
            return [
                'valid' => false,
                'reason' => 'missing_signature_header',
            ];
        }

        // Validate signature format
        if (!str_starts_with($signature, self::SIGNATURE_PREFIX)) {
            return [
                'valid' => false,
                'reason' => 'invalid_signature_format',
            ];
        }

        // Get app secret
        $appSecret = config('whatsapp.webhook.app_secret');

        if (empty($appSecret)) {
            Log::error('WhatsApp Webhook: App secret not configured');
            return [
                'valid' => false,
                'reason' => 'missing_app_secret',
            ];
        }

        // Extract hash from signature
        $expectedHash = substr($signature, strlen(self::SIGNATURE_PREFIX));

        // Validate hash format (should be hex)
        if (!ctype_xdigit($expectedHash) || strlen($expectedHash) !== 64) {
            return [
                'valid' => false,
                'reason' => 'invalid_hash_format',
            ];
        }

        // Get raw request body
        $payload = $request->getContent();

        if (empty($payload)) {
            return [
                'valid' => false,
                'reason' => 'empty_payload',
            ];
        }

        // Compute expected signature
        $computedHash = hash_hmac('sha256', $payload, $appSecret);

        // Use timing-safe comparison
        if (!hash_equals($expectedHash, $computedHash)) {
            return [
                'valid' => false,
                'reason' => 'signature_mismatch',
            ];
        }

        return [
            'valid' => true,
            'reason' => null,
        ];
    }

    /**
     * Verify a signature manually (useful for testing).
     *
     * @param string $payload  Raw request body
     * @param string $signature  Signature from header
     * @param string $appSecret  App secret
     * @return bool
     */
    public static function verifySignature(string $payload, string $signature, string $appSecret): bool
    {
        if (!str_starts_with($signature, self::SIGNATURE_PREFIX)) {
            return false;
        }

        $expectedHash = substr($signature, strlen(self::SIGNATURE_PREFIX));
        $computedHash = hash_hmac('sha256', $payload, $appSecret);

        return hash_equals($expectedHash, $computedHash);
    }

    /**
     * Generate a signature for testing purposes.
     *
     * @param string $payload  Raw request body
     * @param string $appSecret  App secret
     * @return string  Signature in format "sha256=<hex>"
     */
    public static function generateSignature(string $payload, string $appSecret): string
    {
        $hash = hash_hmac('sha256', $payload, $appSecret);
        return self::SIGNATURE_PREFIX . $hash;
    }
}