<?php

namespace App\Jobs;

use App\Services\WhatsApp\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job for sending WhatsApp messages asynchronously.
 *
 * Supports text, buttons, lists, documents, and images.
 *
 * @example
 * // Send text message
 * SendWhatsAppMessage::dispatch('919876543210', 'Hello!');
 *
 * // Send with buttons
 * SendWhatsAppMessage::dispatch(
 *     '919876543210',
 *     'Choose an option:',
 *     'buttons',
 *     [['id' => 'yes', 'title' => 'Yes'], ['id' => 'no', 'title' => 'No']]
 * );
 */
class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 60;

    /**
     * Create a new job instance.
     *
     * @param string $phone Recipient phone number
     * @param string $content Message content
     * @param string $type Message type: text, buttons, list, document, image
     * @param array|null $extra Additional data (buttons, sections, url, etc.)
     */
    public function __construct(
        public string $phone,
        public string $content,
        public string $type = 'text',
        public ?array $extra = null,
    ) {}

    /**
     * Execute the job.
     */
    public function handle(WhatsAppService $whatsApp): void
    {
        try {
            match ($this->type) {
                'text' => $whatsApp->sendText($this->phone, $this->content),
                'buttons' => $whatsApp->sendButtons($this->phone, $this->content, $this->extra ?? []),
                'list' => $this->sendList($whatsApp),
                'document' => $this->sendDocument($whatsApp),
                'image' => $this->sendImage($whatsApp),
                default => $whatsApp->sendText($this->phone, $this->content),
            };

            Log::debug('WhatsApp message sent', [
                'phone' => $this->phone,
                'type' => $this->type,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to send WhatsApp message', [
                'phone' => $this->phone,
                'type' => $this->type,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Send list message.
     */
    protected function sendList(WhatsAppService $whatsApp): void
    {
        $buttonText = $this->extra['button_text'] ?? 'Select';
        $sections = $this->extra['sections'] ?? [];

        $whatsApp->sendList($this->phone, $this->content, $buttonText, $sections);
    }

    /**
     * Send document message.
     */
    protected function sendDocument(WhatsAppService $whatsApp): void
    {
        $url = $this->extra['url'] ?? '';
        $filename = $this->extra['filename'] ?? 'document';
        $caption = $this->extra['caption'] ?? $this->content;

        $whatsApp->sendDocument($this->phone, $url, $filename, $caption);
    }

    /**
     * Send image message.
     */
    protected function sendImage(WhatsAppService $whatsApp): void
    {
        $url = $this->extra['url'] ?? '';
        $caption = $this->extra['caption'] ?? $this->content;

        $whatsApp->sendImage($this->phone, $url, $caption);
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('SendWhatsAppMessage job failed permanently', [
            'phone' => $this->phone,
            'type' => $this->type,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Get the tags for the job.
     */
    public function tags(): array
    {
        return ['whatsapp', 'message', 'phone:' . $this->phone];
    }
}