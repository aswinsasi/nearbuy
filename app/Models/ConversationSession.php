<?php

namespace App\Models;

use App\Enums\FlowStep;
use App\Enums\FlowType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Prunable;
use Carbon\Carbon;

/**
 * Conversation Session Model
 *
 * Maintains conversation state for WhatsApp interactions.
 * Each phone number has exactly one session.
 *
 * @srs-ref Section 7.3 Session State Management
 * @srs-ref NFR-R-03 Session state persists across server restarts
 *
 * @property int $id
 * @property string $phone
 * @property int|null $user_id
 * @property string $current_flow
 * @property string $current_step
 * @property array|null $temp_data
 * @property array|null $context_data
 * @property Carbon $last_activity_at
 * @property string|null $last_message_id
 * @property string|null $last_message_type
 * @property string $language
 * @property Carbon $created_at
 * @property Carbon $updated_at
 *
 * @property-read User|null $user
 *
 * @method static Builder|ConversationSession forPhone(string $phone)
 * @method static Builder|ConversationSession active()
 * @method static Builder|ConversationSession timedOut()
 * @method static Builder|ConversationSession inFlow(FlowType|string $flow)
 * @method static Builder|ConversationSession registered()
 * @method static Builder|ConversationSession anonymous()
 */
class ConversationSession extends Model
{
    use HasFactory, Prunable;

    /*
    |--------------------------------------------------------------------------
    | Constants
    |--------------------------------------------------------------------------
    */

    /**
     * Keywords that trigger menu/navigation.
     *
     * @srs-ref NFR-U-04 Main menu accessible from any flow state
     */
    public const MENU_KEYWORDS = ['menu', 'home', 'start', 'main', 'hi', 'hello', '0'];
    public const CANCEL_KEYWORDS = ['cancel', 'stop', 'exit', 'quit', 'back', 'x'];
    public const HELP_KEYWORDS = ['help', '?', 'support', 'info'];

    /**
     * Supported languages.
     *
     * @srs-ref NFR-U-05 Support English and Malayalam
     */
    public const SUPPORTED_LANGUAGES = [
        'en' => 'English',
        'ml' => 'Malayalam (മലയാളം)',
    ];

    /**
     * Default timeout in minutes.
     */
    public const DEFAULT_TIMEOUT_MINUTES = 30;

    /**
     * Auto-prune sessions older than this many days.
     */
    public const PRUNE_AFTER_DAYS = 1;

    /*
    |--------------------------------------------------------------------------
    | Model Configuration
    |--------------------------------------------------------------------------
    */

    /**
     * The table associated with the model.
     */
    protected $table = 'conversation_sessions';

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [
        'phone',
        'user_id',
        'current_flow',
        'current_step',
        'temp_data',
        'context_data',
        'last_activity_at',
        'last_message_id',
        'last_message_type',
        'language',
    ];

    /**
     * The attributes that should be cast.
     *
     * @srs-ref Section 7.3 temp_data JSON object
     */
    protected $casts = [
        'temp_data' => 'array',
        'context_data' => 'array',
        'last_activity_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     */
    protected $attributes = [
        'current_flow' => 'main_menu',
        'current_step' => 'idle',
        'language' => 'en',
    ];

    /*
    |--------------------------------------------------------------------------
    | Prunable (Auto-cleanup)
    |--------------------------------------------------------------------------
    */

    /**
     * Get the prunable model query.
     *
     * Sessions inactive for more than PRUNE_AFTER_DAYS are auto-deleted.
     * Run: php artisan model:prune
     */
    public function prunable(): Builder
    {
        return static::where('last_activity_at', '<', now()->subDays(self::PRUNE_AFTER_DAYS));
    }

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
     * Scope: Find session by phone.
     */
    public function scopeForPhone(Builder $query, string $phone): Builder
    {
        return $query->where('phone', $phone);
    }

    /**
     * Alias: Find session by phone (for backwards compatibility).
     */
    public function scopeByPhone(Builder $query, string $phone): Builder
    {
        return $this->scopeForPhone($query, $phone);
    }

    /**
     * Scope: Find session for user.
     */
    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Scope: Active sessions (activity within timeout).
     */
    public function scopeActive(Builder $query): Builder
    {
        $timeout = config('nearbuy.session.timeout_minutes', self::DEFAULT_TIMEOUT_MINUTES);
        return $query->where('last_activity_at', '>=', now()->subMinutes($timeout));
    }

    /**
     * Scope: Timed out sessions.
     */
    public function scopeTimedOut(Builder $query): Builder
    {
        $timeout = config('nearbuy.session.timeout_minutes', self::DEFAULT_TIMEOUT_MINUTES);
        return $query->where('last_activity_at', '<', now()->subMinutes($timeout));
    }

    /**
     * Scope: Sessions in a specific flow.
     */
    public function scopeInFlow(Builder $query, FlowType|string $flow): Builder
    {
        $flowValue = $flow instanceof FlowType ? $flow->value : $flow;
        return $query->where('current_flow', $flowValue);
    }

    /**
     * Scope: Sessions at a specific step.
     */
    public function scopeAtStep(Builder $query, FlowStep|string $step): Builder
    {
        $stepValue = $step instanceof FlowStep ? $step->value : $step;
        return $query->where('current_step', $stepValue);
    }

    /**
     * Scope: Sessions older than N days.
     */
    public function scopeOlderThan(Builder $query, int $days): Builder
    {
        return $query->where('last_activity_at', '<', now()->subDays($days));
    }

    /**
     * Scope: Sessions with incomplete flows.
     */
    public function scopeWithIncompleteFlow(Builder $query): Builder
    {
        return $query->whereNotIn('current_step', ['idle', 'main_menu', 'show_menu'])
            ->whereNotIn('current_flow', ['main_menu']);
    }

    /**
     * Scope: Sessions in registration.
     */
    public function scopeInRegistration(Builder $query): Builder
    {
        return $query->where('current_flow', FlowType::REGISTRATION->value);
    }

    /**
     * Scope: Sessions with a linked user (registered).
     */
    public function scopeRegistered(Builder $query): Builder
    {
        return $query->whereNotNull('user_id');
    }

    /**
     * Alias for registered scope.
     */
    public function scopeWithUser(Builder $query): Builder
    {
        return $this->scopeRegistered($query);
    }

    /**
     * Scope: Sessions without a user (anonymous).
     */
    public function scopeAnonymous(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    /**
     * Alias for anonymous scope.
     */
    public function scopeWithoutUser(Builder $query): Builder
    {
        return $this->scopeAnonymous($query);
    }

    /**
     * Scope: Sessions active within last N hours.
     */
    public function scopeRecentlyActive(Builder $query, int $hours = 24): Builder
    {
        return $query->where('last_activity_at', '>=', now()->subHours($hours));
    }

    /**
     * Scope: Sessions with specific language.
     */
    public function scopeWithLanguage(Builder $query, string $language): Builder
    {
        return $query->where('language', $language);
    }

    /*
    |--------------------------------------------------------------------------
    | State Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if session is active (not timed out).
     */
    public function isActive(): bool
    {
        $timeout = $this->getCurrentTimeout();
        return $this->last_activity_at && $this->last_activity_at->diffInMinutes(now()) < $timeout;
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
        return in_array($this->current_step, ['idle', 'main_menu', 'show_menu']);
    }

    /**
     * Check if session is in a specific flow.
     */
    public function isInFlow(FlowType|string $flow): bool
    {
        $flowValue = $flow instanceof FlowType ? $flow->value : $flow;
        return $this->current_flow === $flowValue;
    }

    /**
     * Check if session is at a specific step.
     */
    public function isAtStep(FlowStep|string $step): bool
    {
        $stepValue = $step instanceof FlowStep ? $step->value : $step;
        return $this->current_step === $stepValue;
    }

    /**
     * Check if session is in any active flow (not idle).
     */
    public function isInActiveFlow(): bool
    {
        return !$this->isIdle();
    }

    /**
     * Check if session is in registration flow.
     */
    public function isInRegistration(): bool
    {
        return $this->isInFlow(FlowType::REGISTRATION);
    }

    /**
     * Check if user is registered (has linked user).
     */
    public function isRegistered(): bool
    {
        return $this->user_id !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Flow & Step Accessors
    |--------------------------------------------------------------------------
    */

    /**
     * Get the current flow type enum.
     */
    public function getCurrentFlowType(): ?FlowType
    {
        return FlowType::tryFrom($this->current_flow);
    }

    /**
     * Get the current flow step enum.
     */
    public function getCurrentFlowStep(): ?FlowStep
    {
        return FlowStep::tryFrom($this->current_step);
    }

    /**
     * Get timeout for current flow.
     */
    public function getCurrentTimeout(): int
    {
        $flowType = $this->getCurrentFlowType();
        return $flowType?->timeout() ?? config('nearbuy.session.timeout_minutes', self::DEFAULT_TIMEOUT_MINUTES);
    }

    /**
     * Get remaining time in minutes before timeout.
     */
    public function getRemainingTime(): int
    {
        if (!$this->last_activity_at) {
            return 0;
        }

        $timeout = $this->getCurrentTimeout();
        $elapsed = $this->last_activity_at->diffInMinutes(now());

        return max(0, $timeout - $elapsed);
    }

    /*
    |--------------------------------------------------------------------------
    | Flow Management
    |--------------------------------------------------------------------------
    */

    /**
     * Update last activity timestamp.
     */
    public function touch($attribute = null): bool
    {
        $this->last_activity_at = now();
        return $this->save();
    }

    /**
     * Update flow and step.
     */
    public function setFlowStep(FlowType|string $flow, FlowStep|string $step): void
    {
        $flowValue = $flow instanceof FlowType ? $flow->value : $flow;
        $stepValue = $step instanceof FlowStep ? $step->value : $step;

        $this->update([
            'current_flow' => $flowValue,
            'current_step' => $stepValue,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Update only the step.
     */
    public function setStep(FlowStep|string $step): void
    {
        $stepValue = $step instanceof FlowStep ? $step->value : $step;

        $this->update([
            'current_step' => $stepValue,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Start a new flow.
     */
    public function startFlow(FlowType $flow, ?array $initialData = null): void
    {
        $this->update([
            'current_flow' => $flow->value,
            'current_step' => $flow->initialStep(),
            'temp_data' => $initialData,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Reset session to main menu.
     *
     * @srs-ref NFR-U-04 Main menu accessible from any flow state
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
     * Complete current flow and return to menu.
     */
    public function completeFlow(): void
    {
        $this->resetToMainMenu();
    }

    /*
    |--------------------------------------------------------------------------
    | Temp Data Management
    |--------------------------------------------------------------------------
    */

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
     * Check if temp_data has a key.
     */
    public function hasTempValue(string $key): bool
    {
        return data_get($this->temp_data, $key) !== null;
    }

    /**
     * Remove a value from temp_data.
     */
    public function removeTempValue(string $key): void
    {
        $data = $this->temp_data ?? [];
        unset($data[$key]);

        $this->update([
            'temp_data' => empty($data) ? null : $data,
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
     * Get all temp_data.
     */
    public function getAllTempData(): array
    {
        return $this->temp_data ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | Context Data (Persists across flows)
    |--------------------------------------------------------------------------
    */

    /**
     * Get context value.
     */
    public function getContextValue(string $key, mixed $default = null): mixed
    {
        return data_get($this->context_data, $key, $default);
    }

    /**
     * Set context value.
     */
    public function setContextValue(string $key, mixed $value): void
    {
        $data = $this->context_data ?? [];
        data_set($data, $key, $value);

        $this->update([
            'context_data' => $data,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Clear context data.
     */
    public function clearContextData(): void
    {
        $this->update([
            'context_data' => null,
            'last_activity_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | User & Language
    |--------------------------------------------------------------------------
    */

    /**
     * Associate with a user.
     */
    public function associateUser(User $user): void
    {
        $this->update([
            'user_id' => $user->id,
            'language' => $user->language ?? $this->language,
        ]);
    }

    /**
     * Disassociate user.
     */
    public function disassociateUser(): void
    {
        $this->update(['user_id' => null]);
    }

    /**
     * Set language preference.
     */
    public function setLanguage(string $language): void
    {
        if (array_key_exists($language, self::SUPPORTED_LANGUAGES)) {
            $this->update(['language' => $language]);
        }
    }

    /**
     * Get language name.
     */
    public function getLanguageName(): string
    {
        return self::SUPPORTED_LANGUAGES[$this->language] ?? 'English';
    }

    /*
    |--------------------------------------------------------------------------
    | Message Recording
    |--------------------------------------------------------------------------
    */

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
     * Check if this is a duplicate message.
     */
    public function isDuplicateMessage(string $messageId): bool
    {
        return $this->last_message_id === $messageId;
    }

    /*
    |--------------------------------------------------------------------------
    | Keyword Detection (Static)
    |--------------------------------------------------------------------------
    */

    /**
     * Check if message triggers menu.
     */
    public static function isMenuTrigger(string $message): bool
    {
        $normalized = strtolower(trim($message));
        return in_array($normalized, self::MENU_KEYWORDS);
    }

    /**
     * Check if message triggers cancel.
     */
    public static function isCancelTrigger(string $message): bool
    {
        $normalized = strtolower(trim($message));
        return in_array($normalized, self::CANCEL_KEYWORDS);
    }

    /**
     * Check if message triggers help.
     */
    public static function isHelpTrigger(string $message): bool
    {
        $normalized = strtolower(trim($message));
        return in_array($normalized, self::HELP_KEYWORDS);
    }

    /**
     * Detect message intent.
     *
     * @return string|null 'menu', 'cancel', 'help', or null
     */
    public static function detectIntent(string $message): ?string
    {
        if (self::isMenuTrigger($message)) return 'menu';
        if (self::isCancelTrigger($message)) return 'cancel';
        if (self::isHelpTrigger($message)) return 'help';
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Debug & Summary
    |--------------------------------------------------------------------------
    */

    /**
     * Get session summary.
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->getMaskedPhone(),
            'user_id' => $this->user_id,
            'flow' => $this->current_flow,
            'step' => $this->current_step,
            'language' => $this->language,
            'is_active' => $this->isActive(),
            'is_idle' => $this->isIdle(),
            'remaining_time' => $this->getRemainingTime() . ' minutes',
            'last_activity' => $this->last_activity_at?->diffForHumans(),
            'temp_data_keys' => array_keys($this->temp_data ?? []),
        ];
    }

    /**
     * Get state string for logging.
     */
    public function toStateString(): string
    {
        return "[{$this->getMaskedPhone()}] {$this->current_flow}:{$this->current_step}";
    }

    /**
     * Get masked phone for display.
     */
    public function getMaskedPhone(): string
    {
        if (strlen($this->phone) < 6) {
            return $this->phone;
        }
        return substr($this->phone, 0, 3) . '****' . substr($this->phone, -3);
    }

    /*
    |--------------------------------------------------------------------------
    | Static Helpers
    |--------------------------------------------------------------------------
    */

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

        // Try to associate with user if not linked
        if (!$session->user_id) {
            $user = User::where('phone', $phone)->first();
            if ($user) {
                $session->update(['user_id' => $user->id]);
            }
        }

        return $session;
    }

    /**
     * Get active session or reset if timed out.
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
    public static function cleanupOldSessions(int $days = 1): int
    {
        return self::olderThan($days)->delete();
    }

    /**
     * Get statistics for monitoring.
     */
    public static function getStatistics(): array
    {
        return [
            'total_sessions' => self::count(),
            'active_sessions' => self::active()->count(),
            'timed_out_sessions' => self::timedOut()->count(),
            'registered_users' => self::registered()->count(),
            'anonymous_users' => self::anonymous()->count(),
            'in_registration' => self::inFlow(FlowType::REGISTRATION)->count(),
            'incomplete_flows' => self::withIncompleteFlow()->count(),
            'by_language' => self::selectRaw('language, COUNT(*) as count')
                ->groupBy('language')
                ->pluck('count', 'language')
                ->toArray(),
            'by_flow' => self::selectRaw('current_flow, COUNT(*) as count')
                ->groupBy('current_flow')
                ->pluck('count', 'current_flow')
                ->toArray(),
        ];
    }
}