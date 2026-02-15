<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\NotificationLog;
use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Job for sending WhatsApp messages with rate limiting and quiet hours.
 *
 * FEATURES:
 * - Retry 3x with exponential backoff (60s, 120s, 240s)
 * - WhatsApp rate limit: ~80 messages/second
 * - Quiet hours: 10PM-7AM (messages queued for 7AM)
 * - Priority queues: flash-deals > fish-alerts > jobs > products > offers
 * - Comprehensive logging of all send attempts
 *
 * @srs-ref NFR-P-01 - Webhook processing < 5 seconds
 * @srs-ref NFR-R-02 - Failed deliveries retried with exponential backoff
 * @module Notifications
 *
 * @example
 * // Send with default priority
 * SendWhatsAppMessage::dispatch('919876543210', 'Hello!');
 *
 * // Send Flash Deal alert (high priority)
 * SendWhatsAppMessage::flashDeal('919876543210', $message, 'buttons', $buttons)->dispatch();
 *
 * // Send with context for logging
 * SendWhatsAppMessage::dispatch('919876543210', $message)
 *     ->withContext(['type' => 'product_request', 'request_id' => 123]);
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // =========================================================================
    // RETRY CONFIGURATION
    // =========================================================================

    /**
     * Number of retry attempts.
     * @srs-ref NFR-R-02 - Retry 3 times
     */
    public int $tries = 3;

    /**
     * Maximum exceptions before failing.
     */
    public int $maxExceptions = 3;

    /**
     * Job timeout in seconds.
     * @srs-ref NFR-P-04 - Media upload/download < 30 seconds
     */
    public int $timeout = 30;

    /**
     * Mark as failed on timeout.
     */
    public bool $failOnTimeout = true;

    // =========================================================================
    // QUIET HOURS
    // =========================================================================

    protected const QUIET_START = 22;  // 10 PM
    protected const QUIET_END = 7;     // 7 AM

    /**
     * Message types exempt from quiet hours.
     */
    protected const QUIET_EXEMPT = [
        'flash_deal_activation',
        'flash_deal_coupon',
        'fish_arrival_imminent',
        'job_accepted',
        'otp',
        'urgent',
    ];

    // =========================================================================
    // PROPERTIES
    // =========================================================================

    public array $context = [];
    public bool $bypassQuietHours = false;
    public ?string $uniqueId = null;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public string $phone,
        public string $content,
        public string $type = 'text',
        public ?array $extra = null,
        public ?string $notificationType = null,
    ) {
        $this->uniqueId = md5($phone . $content . $type . microtime());
    }

    // =========================================================================
    // MIDDLEWARE & BACKOFF
    // =========================================================================

    /**
     * Get middleware for rate limiting.
     */
    public function middleware(): array
    {
        return [
            new RateLimited('whatsapp-api'),
            (new WithoutOverlapping($this->phone))
                ->releaseAfter(5)
                ->expireAfter(60),
        ];
    }

    /**
     * Exponential backoff: 1 min, 2 min, 4 min.
     * @srs-ref NFR-R-02 - Exponential backoff
     */
    public function backoff(): array
    {
        return [60, 120, 240];
    }

    /**
     * Maximum retry time.
     */
    public function retryUntil(): Carbon
    {
        return now()->addHours(2);
    }

    // =========================================================================
    // EXECUTION
    // =========================================================================

    /**
     * Execute the job.
     */
    public function handle(WhatsAppService $whatsApp): void
    {
        $startTime = microtime(true);
        $logData = $this->buildLogData();

        try {
            // Check quiet hours
            if ($this->shouldDelayForQuietHours()) {
                $this->delayUntilMorning();
                return;
            }

            // Send the message
            $messageId = $this->sendMessage($whatsApp);

            // Log success
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logSuccess($logData, $messageId, $duration);

        } catch (\Exception $e) {
            $duration = round((microtime(true) - $startTime) * 1000, 2);
            $this->logFailure($logData, $e, $duration);
            throw $e;
        }
    }

    /**
     * Send message based on type.
     */
    protected function sendMessage(WhatsAppService $whatsApp): string|array|null
    {
        return match ($this->type) {
            'text' => $whatsApp->sendText($this->phone, $this->content),

            'buttons' => $whatsApp->sendButtons(
                $this->phone,
                $this->content,
                $this->extra['buttons'] ?? $this->extra ?? [],
                $this->extra['header'] ?? null
            ),

            'list' => $whatsApp->sendList(
                $this->phone,
                $this->content,
                $this->extra['button_text'] ?? 'Select',
                $this->extra['sections'] ?? []
            ),

            'document' => $whatsApp->sendDocument(
                $this->phone,
                $this->extra['url'] ?? '',
                $this->extra['filename'] ?? 'document',
                $this->extra['caption'] ?? $this->content
            ),

            'image' => $whatsApp->sendImage(
                $this->phone,
                $this->extra['url'] ?? '',
                $this->extra['caption'] ?? $this->content
            ),

            'location' => $whatsApp->sendLocation(
                $this->phone,
                $this->extra['latitude'] ?? 0,
                $this->extra['longitude'] ?? 0,
                $this->extra['name'] ?? null,
                $this->extra['address'] ?? null
            ),

            'location_request' => $whatsApp->requestLocation($this->phone, $this->content),

            default => $whatsApp->sendText($this->phone, $this->content),
        };
    }

    // =========================================================================
    // QUIET HOURS
    // =========================================================================

    protected function shouldDelayForQuietHours(): bool
    {
        if ($this->bypassQuietHours) {
            return false;
        }

        if ($this->notificationType && in_array($this->notificationType, self::QUIET_EXEMPT)) {
            return false;
        }

        return $this->isQuietHours();
    }

    protected function isQuietHours(): bool
    {
        $hour = (int) now()->format('H');
        return $hour >= self::QUIET_START || $hour < self::QUIET_END;
    }

    protected function delayUntilMorning(): void
    {
        $nextMorning = now()->copy();

        if ($nextMorning->hour >= self::QUIET_START) {
            $nextMorning->addDay()->setTime(self::QUIET_END, 0);
        } else {
            $nextMorning->setTime(self::QUIET_END, 0);
        }

        $this->release($nextMorning->diffInSeconds(now()));

        Log::info('WhatsApp message delayed for quiet hours', [
            'phone' => $this->maskPhone($this->phone),
            'type' => $this->type,
            'delayed_until' => $nextMorning->toDateTimeString(),
        ]);
    }

    // =========================================================================
    // LOGGING
    // =========================================================================

    protected function buildLogData(): array
    {
        return array_merge([
            'phone' => $this->maskPhone($this->phone),
            'phone_raw' => $this->phone,
            'type' => $this->type,
            'notification_type' => $this->notificationType,
            'queue' => $this->queue,
            'attempt' => $this->attempts(),
            'content_length' => strlen($this->content),
        ], $this->context);
    }

    protected function logSuccess(array $data, ?string $messageId, float $duration): void
    {
        $data['message_id'] = $messageId;
        $data['duration_ms'] = $duration;
        $data['status'] = 'sent';

        Log::channel('whatsapp')->info('WhatsApp message sent', $data);
        $this->storeLog($data, 'sent');
    }

    protected function logFailure(array $data, \Exception $e, float $duration): void
    {
        $data['error'] = $e->getMessage();
        $data['error_class'] = get_class($e);
        $data['duration_ms'] = $duration;
        $data['status'] = 'failed';

        Log::channel('whatsapp')->error('WhatsApp message failed', $data);
        $this->storeLog($data, 'failed', $e->getMessage());
    }

    protected function storeLog(array $data, string $status, ?string $error = null): void
    {
        try {
            if (class_exists(NotificationLog::class)) {
                NotificationLog::create([
                    'phone' => $data['phone_raw'],
                    'type' => $this->type,
                    'notification_type' => $this->notificationType,
                    'status' => $status,
                    'message_id' => $data['message_id'] ?? null,
                    'error' => $error,
                    'duration_ms' => $data['duration_ms'] ?? null,
                    'attempt' => $data['attempt'],
                    'queue' => $this->queue,
                    'context' => $this->context,
                ]);
            }
        } catch (\Exception $e) {
            Log::warning('Failed to store notification log', ['error' => $e->getMessage()]);
        }
    }

    // =========================================================================
    // FAILURE HANDLING
    // =========================================================================

    public function failed(\Throwable $exception): void
    {
        Log::channel('whatsapp')->error('SendWhatsAppMessage job failed permanently', [
            'phone' => $this->maskPhone($this->phone),
            'type' => $this->type,
            'notification_type' => $this->notificationType,
            'attempts' => $this->attempts(),
            'error' => $exception->getMessage(),
        ]);

        $this->storeLog([
            'phone_raw' => $this->phone,
            'attempt' => $this->attempts(),
        ], 'failed_permanently', $exception->getMessage());
    }

    // =========================================================================
    // FLUENT METHODS
    // =========================================================================

    public function withContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    public function ofType(string $type): self
    {
        $this->notificationType = $type;
        return $this;
    }

    public function urgent(): self
    {
        $this->bypassQuietHours = true;
        $this->notificationType = 'urgent';
        return $this;
    }

    public function tags(): array
    {
        $tags = ['whatsapp', 'message', 'type:' . $this->type];

        if ($this->notificationType) {
            $tags[] = 'notification:' . $this->notificationType;
        }

        return $tags;
    }

    public function uniqueId(): string
    {
        return $this->uniqueId ?? md5($this->phone . microtime());
    }

    protected function maskPhone(string $phone): string
    {
        return strlen($phone) < 6 ? $phone : substr($phone, 0, 3) . '****' . substr($phone, -3);
    }

    // =========================================================================
    // FACTORY METHODS (Priority Queues)
    // =========================================================================

    /**
     * Flash Deal notification (Priority 1 - Highest).
     */
    public static function flashDeal(string $phone, string $content, string $type = 'text', ?array $extra = null): self
    {
        return (new self($phone, $content, $type, $extra, 'flash_deal'))
            ->onQueue('flash-deals');
    }

    /**
     * Fish Alert notification (Priority 2).
     */
    public static function fishAlert(string $phone, string $content, string $type = 'text', ?array $extra = null): self
    {
        return (new self($phone, $content, $type, $extra, 'fish_alert'))
            ->onQueue('fish-alerts');
    }

    /**
     * Job notification (Priority 3).
     */
    public static function jobNotification(string $phone, string $content, string $type = 'text', ?array $extra = null): self
    {
        return (new self($phone, $content, $type, $extra, 'job_notification'))
            ->onQueue('job-notifications');
    }

    /**
     * Product Request notification (Priority 4).
     */
    public static function productRequest(string $phone, string $content, string $type = 'text', ?array $extra = null): self
    {
        return (new self($phone, $content, $type, $extra, 'product_request'))
            ->onQueue('product-requests');
    }

    /**
     * Offer notification (Priority 5 - Lowest).
     */
    public static function offer(string $phone, string $content, string $type = 'text', ?array $extra = null): self
    {
        return (new self($phone, $content, $type, $extra, 'offer'))
            ->onQueue('offers');
    }
}