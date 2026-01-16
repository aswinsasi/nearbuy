<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware to verify WhatsApp webhook signatures.
 *
 * WhatsApp sends a X-Hub-Signature-256 header with each webhook request.
 * This header contains a HMAC-SHA256 hash of the request payload signed
 * with your app secret. We verify this signature to ensure the request
 * actually came from Meta/WhatsApp and wasn't tampered with.
 *
 * @see https://developers.facebook.com/docs/graph-api/webhooks/getting-started#verification-requests
 */
class VerifyWhatsAppWebhook
{
    /**
     * Handle an incoming request.
     *
     * @param Request $request
     * @param Closure $next
     * @return Response
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip verification in test mode or when explicitly disabled
        if ($this->shouldSkipVerification()) {
            return $next($request);
        }

        // Get the signature from the request header
        $signature = $request->header('X-Hub-Signature-256');

        if (empty($signature)) {
            Log::warning('WhatsApp Webhook: Missing X-Hub-Signature-256 header', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            return response()->json([
                'error' => 'Missing signature header',
            ], Response::HTTP_UNAUTHORIZED);
        }

        // Verify the signature
        if (!$this->verifySignature($request, $signature)) {
            Log::warning('WhatsApp Webhook: Invalid signature', [
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'signature' => substr($signature, 0, 20) . '...',
            ]);

            return response()->json([
                'error' => 'Invalid signature',
            ], Response::HTTP_FORBIDDEN);
        }

        // Log successful verification (optional, can be noisy in production)
        if (config('whatsapp.logging.log_webhooks')) {
            Log::debug('WhatsApp Webhook: Signature verified', [
                'ip' => $request->ip(),
            ]);
        }

        return $next($request);
    }

    /**
     * Check if signature verification should be skipped.
     *
     * @return bool
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
     * Verify the webhook signature.
     *
     * The signature is in the format: sha256=<hex_digest>
     * We compute our own hash using the app secret and compare.
     *
     * @param Request $request
     * @param string $signature
     * @return bool
     */
    private function verifySignature(Request $request, string $signature): bool
    {
        $appSecret = config('whatsapp.webhook.app_secret');

        if (empty($appSecret)) {
            Log::error('WhatsApp Webhook: App secret not configured');
            return false;
        }

        // Extract the hash from the signature header
        // Format: "sha256=<hex_hash>"
        if (!str_starts_with($signature, 'sha256=')) {
            Log::warning('WhatsApp Webhook: Invalid signature format');
            return false;
        }

        $expectedHash = substr($signature, 7); // Remove "sha256=" prefix

        // Get the raw request body
        $payload = $request->getContent();

        // Compute the expected signature
        $computedHash = hash_hmac('sha256', $payload, $appSecret);

        // Use timing-safe comparison to prevent timing attacks
        return hash_equals($expectedHash, $computedHash);
    }
}