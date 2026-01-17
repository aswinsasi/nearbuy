<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class ConversationSession extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'phone',
        'user_id',
        'current_flow',
        'current_step',
        'temp_data',
        'last_activity_at',
        'last_message_id',
        'last_message_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'temp_data' => 'array',
        'last_activity_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Relationships
    |--------------------------------------------------------------------------
    */

    /**
     * Get the user associated with this session.
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
     * Scope to find session by phone.
     */
    public function scopeByPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone);
    }

    /**
     * Scope to find active sessions (activity within timeout).
     */
    public function scopeActive(Builder $query): Builder
    {
        $timeout = config('nearbuy.session.timeout_minutes', 30);

        return $query->where('last_activity_at', '>=', now()->subMinutes($timeout));
    }

    /**
     * Scope to find inactive/timed out sessions.
     */
    public function scopeTimedOut(Builder $query): Builder
    {
        $timeout = config('nearbuy.session.timeout_minutes', 30);

        return $query->where('last_activity_at', '<', now()->subMinutes($timeout));
    }

    /**
     * Scope to find sessions in a specific flow.
     */
    public function scopeInFlow(Builder $query, string $flow): Builder
    {
        return $query->where('current_flow', $flow);
    }

    /**
     * Scope to find sessions at a specific step.
     */
    public function scopeAtStep(Builder $query, string $step): Builder
    {
        return $query->where('current_step', $step);
    }

    /**
     * Scope to find sessions older than a certain number of days.
     */
    public function scopeOlderThan(Builder $query, int $days): Builder
    {
        return $query->where('last_activity_at', '<', now()->subDays($days));
    }

    /*
    |--------------------------------------------------------------------------
    | Accessors & Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Check if session is active.
     */
    public function isActive(): bool
    {
        $timeout = config('nearbuy.session.timeout_minutes', 30);

        return $this->last_activity_at->diffInMinutes(now()) < $timeout;
    }

    /**
     * Check if session has timed out.
     */
    public function hasTimedOut(): bool
    {
        return !$this->isActive();
    }

    /**
     * Check if session is in idle state.
     */
    public function isIdle(): bool
    {
        return in_array($this->current_step, ['idle', 'main_menu']);
    }

    /**
     * Update the session activity timestamp.
     */
    public function touch($attribute = null)
    {
        $this->last_activity_at = now();
        return $this->save();
    }

    /**
     * Update flow and step.
     */
    public function setFlowStep(string $flow, string $step): void
    {
        $this->update([
            'current_flow' => $flow,
            'current_step' => $step,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Update just the step within current flow.
     */
    public function setStep(string $step): void
    {
        $this->update([
            'current_step' => $step,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Reset session to main menu.
     */
    public function resetToMainMenu(): void
    {
        $this->update([
            'current_flow' => 'main_menu',
            'current_step' => 'idle',
            'temp_data' => null,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Get a value from temp_data.
     */
    public function getTempValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->temp_data, $key, $default);
    }

    /**
     * Set a value in temp_data.
     */
    public function setTempValue(string $key, mixed $value): void
    {
        $data = $this->temp_data ?? [];
        data_set($data, $key, $value);

        $this->update([
            'temp_data' => $data,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Merge values into temp_data.
     */
    public function mergeTempData(array $data): void
    {
        $existing = $this->temp_data ?? [];
        $merged = array_merge($existing, $data);

        $this->update([
            'temp_data' => $merged,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Clear temp_data.
     */
    public function clearTempData(): void
    {
        $this->update([
            'temp_data' => null,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Associate with a user.
     */
    public function associateUser(User $user): void
    {
        $this->update(['user_id' => $user->id]);
    }

    /**
     * Record last message.
     */
    public function recordMessage(string $messageId, string $messageType): void
    {
        $this->update([
            'last_message_id' => $messageId,
            'last_message_type' => $messageType,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Find or create session by phone number.
     */
    public static function findOrCreateByPhone(string $phone): self
    {
        $session = self::firstOrCreate(
            ['phone' => $phone],
            [
                'current_flow' => 'main_menu',
                'current_step' => 'idle',
                'last_activity_at' => now(),
            ]
        );

        // Try to associate with user if exists
        if (!$session->user_id) {
            $user = User::where('phone', $phone)->first();
            if ($user) {
                $session->update(['user_id' => $user->id]);
            }
        }

        return $session;
    }

    /**
     * Get session for a phone, reset if timed out.
     */
    public static function getActiveOrReset(string $phone): self
    {
        $session = self::findOrCreateByPhone($phone);

        if ($session->hasTimedOut() && !$session->isIdle()) {
            $session->resetToMainMenu();
        } else {
            $session->touch();
        }

        return $session;
    }

    /**
     * Clean up old sessions.
     */
    public static function cleanupOldSessions(int $days = 7): int
    {
        return self::olderThan($days)->delete();
    }
}