<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

/**
 * WhatsAppMessage Model - Comprehensive message logging.
 *
 * Logs ALL WhatsApp messages (inbound and outbound) for:
 * - Debugging and troubleshooting
 * - Analytics and insights
 * - Compliance and audit trails
 * - Conversation history
 *
 * @property int $id
 * @property string $wamid WhatsApp message ID
 * @property string $phone Phone number (E.164 format)
 * @property string $direction inbound|outbound
 * @property string $type text|image|document|location|interactive|button|template
 * @property array|null $content Full message content
 * @property string|null $content_summary Human-readable summary
 * @property string $status sent|delivered|read|failed|received
 * @property string|null $error_message Error details if failed
 * @property int|null $user_id Associated user
 * @property int|null $session_id Associated session
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Carbon\Carbon|null $delivered_at
 * @property \Carbon\Carbon|null $read_at
 *
 * @property-read User|null $user
 * @property-read ConversationSession|null $session
 * @property-read bool $is_inbound
 * @property-read bool $is_outbound
 * @property-read bool $is_failed
 * @property-read string|null $text
 */
class WhatsAppMessage extends Model
{
    use HasFactory;

    /**
     * The table associated with the model.
     */
    protected $table = 'whatsapp_messages';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'wamid',
        'phone',
        'direction',
        'type',
        'content',
        'content_summary',
        'status',
        'error_message',
        'user_id',
        'session_id',
        'delivered_at',
        'read_at',
    ];

    /**
     * The attributes that should be cast.
     */
    protected $casts = [
        'content' => 'array',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'status' => 'sent',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the user this message belongs to.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the session this message belongs to.
     */
    public function session(): BelongsTo
    {
        return $this->belongsTo(ConversationSession::class, 'session_id');
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to inbound messages only.
     */
    public function scopeInbound(Builder $query): Builder
    {
        return $query->where('direction', 'inbound');
    }

    /**
     * Scope to outbound messages only.
     */
    public function scopeOutbound(Builder $query): Builder
    {
        return $query->where('direction', 'outbound');
    }

    /**
     * Scope to failed messages.
     */
    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope to successful messages.
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->whereIn('status', ['sent', 'delivered', 'read', 'received']);
    }

    /**
     * Scope by phone number.
     */
    public function scopeForPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone);
    }

    /**
     * Scope by message type.
     */
    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    /**
     * Scope to today's messages.
     */
    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', today());
    }

    /**
     * Scope to recent messages (last N hours).
     */
    public function scopeRecent(Builder $query, int $hours = 24): Builder
    {
        return $query->where('created_at', '>=', now()->subHours($hours));
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Check if message is inbound.
     */
    public function getIsInboundAttribute(): bool
    {
        return $this->direction === 'inbound';
    }

    /**
     * Check if message is outbound.
     */
    public function getIsOutboundAttribute(): bool
    {
        return $this->direction === 'outbound';
    }

    /**
     * Check if message failed.
     */
    public function getIsFailedAttribute(): bool
    {
        return $this->status === 'failed';
    }

    /**
     * Check if message was delivered.
     */
    public function getIsDeliveredAttribute(): bool
    {
        return in_array($this->status, ['delivered', 'read']);
    }

    /**
     * Check if message was read.
     */
    public function getIsReadAttribute(): bool
    {
        return $this->status === 'read';
    }

    /**
     * Get text content if available.
     */
    public function getTextAttribute(): ?string
    {
        if ($this->type === 'text') {
            return $this->content['body'] ?? $this->content['text'] ?? null;
        }

        return null;
    }

    /**
     * Get direction emoji for display.
     */
    public function getDirectionEmojiAttribute(): string
    {
        return $this->is_inbound ? '📥' : '📤';
    }

    /*
    |--------------------------------------------------------------------------
    | Status Updates
    |--------------------------------------------------------------------------
    */

    /**
     * Mark message as delivered.
     */
    public function markDelivered(): self
    {
        $this->update([
            'status' => 'delivered',
            'delivered_at' => now(),
        ]);
        return $this;
    }

    /**
     * Mark message as read.
     */
    public function markRead(): self
    {
        $this->update([
            'status' => 'read',
            'read_at' => now(),
            'delivered_at' => $this->delivered_at ?? now(),
        ]);
        return $this;
    }

    /**
     * Mark message as failed.
     */
    public function markFailed(string $error): self
    {
        $this->update([
            'status' => 'failed',
            'error_message' => mb_substr($error, 0, 500),
        ]);
        return $this;
    }

    /*
    |--------------------------------------------------------------------------
    | Logging Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Log an inbound message.
     *
     * @param string $phone Phone number
     * @param string $wamid WhatsApp message ID
     * @param string $type Message type
     * @param array $content Full message content
     * @param int|null $userId Associated user ID
     * @param int|null $sessionId Associated session ID
     */
    public static function logInbound(
        string $phone,
        string $wamid,
        string $type,
        array $content,
        ?int $userId = null,
        ?int $sessionId = null
    ): self {
        return self::create([
            'phone' => $phone,
            'wamid' => $wamid,
            'direction' => 'inbound',
            'type' => $type,
            'content' => $content,
            'content_summary' => self::generateSummary($type, $content, 'inbound'),
            'status' => 'received',
            'user_id' => $userId,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Log an outbound message.
     *
     * @param string $phone Phone number
     * @param string $wamid WhatsApp message ID (from API response)
     * @param string $type Message type
     * @param array $content Message content sent
     * @param int|null $userId Associated user ID
     * @param int|null $sessionId Associated session ID
     */
    public static function logOutbound(
        string $phone,
        string $wamid,
        string $type,
        array $content,
        ?int $userId = null,
        ?int $sessionId = null
    ): self {
        return self::create([
            'phone' => $phone,
            'wamid' => $wamid,
            'direction' => 'outbound',
            'type' => $type,
            'content' => $content,
            'content_summary' => self::generateSummary($type, $content, 'outbound'),
            'status' => 'sent',
            'user_id' => $userId,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * Log a failed outbound message.
     */
    public static function logFailed(
        string $phone,
        string $type,
        array $content,
        string $error,
        ?int $userId = null
    ): self {
        return self::create([
            'phone' => $phone,
            'wamid' => 'failed_' . now()->timestamp . '_' . mt_rand(1000, 9999),
            'direction' => 'outbound',
            'type' => $type,
            'content' => $content,
            'content_summary' => self::generateSummary($type, $content, 'outbound'),
            'status' => 'failed',
            'error_message' => mb_substr($error, 0, 500),
            'user_id' => $userId,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Summary Generation
    |--------------------------------------------------------------------------
    */

    /**
     * Generate human-readable summary of message content.
     *
     * Examples:
     * - Text: "Hello, I need help" → "Hello, I need help"
     * - Button: Selected "Browse Offers" → "Button: Browse Offers"
     * - List: Selected "Fish" → "List: Fish"
     * - Image: → "Image uploaded"
     * - Location: → "Location: 10.123, 76.456"
     */
    public static function generateSummary(string $type, array $content, string $direction = 'inbound'): string
    {
        $maxLength = 100;

        $summary = match ($type) {
            'text' => self::truncate($content['body'] ?? $content['text'] ?? '[empty]', $maxLength),

            'interactive' => self::summarizeInteractive($content, $direction),

            'button' => 'Quick Reply: ' . ($content['text'] ?? $content['payload'] ?? '[unknown]'),

            'location' => sprintf(
                'Location: %.4f, %.4f%s',
                $content['latitude'] ?? 0,
                $content['longitude'] ?? 0,
                isset($content['name']) ? " ({$content['name']})" : ''
            ),

            'image' => $direction === 'inbound'
                ? 'Image uploaded' . (isset($content['caption']) ? ": {$content['caption']}" : '')
                : 'Image sent' . (isset($content['caption']) ? ": " . self::truncate($content['caption'], 50) : ''),

            'document' => $direction === 'inbound'
                ? 'Document: ' . ($content['filename'] ?? 'uploaded')
                : 'Document sent: ' . ($content['filename'] ?? 'file'),

            'template' => 'Template: ' . ($content['name'] ?? 'message'),

            'audio' => 'Audio message',
            'video' => 'Video message',
            'sticker' => 'Sticker',
            'contacts' => 'Contact shared',
            'reaction' => 'Reaction: ' . ($content['emoji'] ?? '👍'),

            default => "[$type message]",
        };

        return self::truncate($summary, $maxLength);
    }

    /**
     * Summarize interactive message (buttons/lists).
     */
    private static function summarizeInteractive(array $content, string $direction): string
    {
        // Inbound: user selected something
        if ($direction === 'inbound') {
            $interactiveType = $content['type'] ?? 'unknown';

            if ($interactiveType === 'button_reply') {
                $title = $content['button_reply']['title'] ?? $content['title'] ?? 'unknown';
                return "Button: {$title}";
            }

            if ($interactiveType === 'list_reply') {
                $title = $content['list_reply']['title'] ?? $content['title'] ?? 'unknown';
                return "List: {$title}";
            }

            return "Interactive: {$interactiveType}";
        }

        // Outbound: we sent buttons/list
        $interactiveType = $content['type'] ?? 'unknown';

        if ($interactiveType === 'button') {
            $body = $content['body']['text'] ?? '';
            $buttonCount = count($content['action']['buttons'] ?? []);
            return self::truncate($body, 50) . " [{$buttonCount} buttons]";
        }

        if ($interactiveType === 'list') {
            $body = $content['body']['text'] ?? '';
            $sectionCount = count($content['action']['sections'] ?? []);
            return self::truncate($body, 50) . " [{$sectionCount} sections]";
        }

        if ($interactiveType === 'location_request_message') {
            return 'Location request sent';
        }

        return "Interactive: {$interactiveType}";
    }

    /**
     * Truncate string with ellipsis.
     */
    private static function truncate(string $text, int $maxLength): string
    {
        $text = trim($text);

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        return mb_substr($text, 0, $maxLength - 3) . '...';
    }

    /*
    |--------------------------------------------------------------------------
    | Query Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Get conversation history for a phone number.
     */
    public static function getConversation(string $phone, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return self::forPhone($phone)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Find message by WhatsApp message ID.
     */
    public static function findByWamid(string $wamid): ?self
    {
        return self::where('wamid', $wamid)->first();
    }

    /**
     * Update status from webhook (delivered/read).
     */
    public static function updateStatusFromWebhook(string $wamid, string $status): bool
    {
        $message = self::findByWamid($wamid);

        if (!$message) {
            return false;
        }

        if ($status === 'delivered') {
            $message->markDelivered();
        } elseif ($status === 'read') {
            $message->markRead();
        }

        return true;
    }

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get message statistics for a time period.
     */
    public static function getStats(int $hours = 24): array
    {
        $since = now()->subHours($hours);

        return [
            'total' => self::where('created_at', '>=', $since)->count(),
            'inbound' => self::where('created_at', '>=', $since)->inbound()->count(),
            'outbound' => self::where('created_at', '>=', $since)->outbound()->count(),
            'failed' => self::where('created_at', '>=', $since)->failed()->count(),
            'by_type' => self::where('created_at', '>=', $since)
                ->selectRaw('type, COUNT(*) as count')
                ->groupBy('type')
                ->pluck('count', 'type')
                ->toArray(),
        ];
    }
}