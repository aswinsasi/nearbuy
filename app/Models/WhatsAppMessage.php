<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WhatsAppMessage Model
 *
 * Logs all WhatsApp messages sent and received.
 *
 * @property int $id
 * @property string $wamid WhatsApp message ID
 * @property string $phone Phone number
 * @property string $direction inbound|outbound
 * @property string $type text|image|document|audio|video|location|interactive|button
 * @property array|null $content Message content
 * @property string $status sent|delivered|read|failed
 * @property string|null $error_message
 * @property int|null $user_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 *
 * @property-read User|null $user
 */
class WhatsAppMessage extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'wamid',
        'phone',
        'direction',
        'type',
        'content',
        'status',
        'error_message',
        'user_id',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'content' => 'array',
    ];

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
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

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    */

    /**
     * Scope to inbound messages.
     */
    public function scopeInbound($query)
    {
        return $query->where('direction', 'inbound');
    }

    /**
     * Scope to outbound messages.
     */
    public function scopeOutbound($query)
    {
        return $query->where('direction', 'outbound');
    }

    /**
     * Scope to failed messages.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope by phone number.
     */
    public function scopeForPhone($query, string $phone)
    {
        return $query->where('phone', $phone);
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
     * Get text content if available.
     */
    public function getTextAttribute(): ?string
    {
        if ($this->type === 'text') {
            return $this->content['body'] ?? $this->content['text'] ?? null;
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Mark message as delivered.
     */
    public function markDelivered(): self
    {
        $this->update(['status' => 'delivered']);
        return $this;
    }

    /**
     * Mark message as read.
     */
    public function markRead(): self
    {
        $this->update(['status' => 'read']);
        return $this;
    }

    /**
     * Mark message as failed.
     */
    public function markFailed(string $error): self
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $error,
        ]);
        return $this;
    }

    /**
     * Create an inbound message log.
     */
    public static function logInbound(string $phone, string $wamid, string $type, array $content): self
    {
        return self::create([
            'phone' => $phone,
            'wamid' => $wamid,
            'direction' => 'inbound',
            'type' => $type,
            'content' => $content,
            'status' => 'received',
        ]);
    }

    /**
     * Create an outbound message log.
     */
    public static function logOutbound(string $phone, string $wamid, string $type, array $content): self
    {
        return self::create([
            'phone' => $phone,
            'wamid' => $wamid,
            'direction' => 'outbound',
            'type' => $type,
            'content' => $content,
            'status' => 'sent',
        ]);
    }
}