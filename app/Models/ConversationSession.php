<?php

namespace App\Models;

use App\Enums\FlowStep;
use App\Enums\FlowType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

/**
 * Conversation Session Model
 *
 * Maintains conversation state for WhatsApp interactions.
 *
 * @srs-ref Section 7.3 Session State Management
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
 */
class ConversationSession extends Model
{
    use HasFactory;

    /**
     * Keywords that trigger menu/navigation actions.
     *
     * @srs-ref NFR-U-04 Main menu accessible from any flow state
     */
    public const MENU_KEYWORDS = ['menu', 'home', 'start', 'main', 'hi', 'hello', '0'];
    public const CANCEL_KEYWORDS = ['cancel', 'stop', 'exit', 'quit', 'back', 'x'];
    public const HELP_KEYWORDS = ['help', '?', 'support', 'info'];
    public const RESTART_KEYWORDS = ['restart', 'reset', 'new'];

    /**
     * Supported languages.
     *
     * @srs-ref NFR-U-05 Support English and Malayalam
     */
    public const SUPPORTED_LANGUAGES = [
        'en' => 'English',
        'ml' => 'Malayalam',
    ];

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
        'context_data',
        'last_activity_at',
        'last_message_id',
        'last_message_type',
        'language',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'temp_data' => 'array',
        'context_data' => 'array',
        'last_activity_at' => 'datetime',
    ];

    /**
     * Default attribute values.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'current_flow' => 'main_menu',
        'current_step' => 'idle',
        'language' => 'en',
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
    public function scopeInFlow(Builder $query, string|FlowType $flow): Builder
    {
        $flowValue = $flow instanceof FlowType ? $flow->value : $flow;
        return $query->where('current_flow', $flowValue);
    }

    /**
     * Scope to find sessions at a specific step.
     */
    public function scopeAtStep(Builder $query, string|FlowStep $step): Builder
    {
        $stepValue = $step instanceof FlowStep ? $step->value : $step;
        return $query->where('current_step', $stepValue);
    }

    /**
     * Scope to find sessions older than a certain number of days.
     */
    public function scopeOlderThan(Builder $query, int $days): Builder
    {
        return $query->where('last_activity_at', '<', now()->subDays($days));
    }

    /**
     * Scope to find sessions with incomplete flows.
     */
    public function scopeWithIncompleteFlow(Builder $query): Builder
    {
        return $query->whereNotIn('current_step', ['idle', 'main_menu'])
            ->whereNotIn('current_flow', ['main_menu']);
    }

    /**
     * Scope to find sessions in registration.
     */
    public function scopeInRegistration(Builder $query): Builder
    {
        return $query->where('current_flow', FlowType::REGISTRATION->value);
    }

    /**
     * Scope to find sessions with a user.
     */
    public function scopeWithUser(Builder $query): Builder
    {
        return $query->whereNotNull('user_id');
    }

    /**
     * Scope to find sessions without a user (anonymous).
     */
    public function scopeWithoutUser(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    /*
    |--------------------------------------------------------------------------
    | State Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if session is active.
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
        return in_array($this->current_step, ['idle', 'main_menu']);
    }

    /**
     * Check if session is in a specific flow.
     */
    public function isInFlow(string|FlowType $flow): bool
    {
        $flowValue = $flow instanceof FlowType ? $flow->value : $flow;
        return $this->current_flow === $flowValue;
    }

    /**
     * Check if session is at a specific step.
     */
    public function isAtStep(string|FlowStep $step): bool
    {
        $stepValue = $step instanceof FlowStep ? $step->value : $step;
        return $this->current_step === $stepValue;
    }

    /**
     * Check if session is in any active flow (not idle/menu).
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
     * Check if user is registered.
     */
    public function isRegistered(): bool
    {
        return $this->user_id !== null;
    }

    /**
     * Check if the session expects a specific input type.
     */
    public function expectsInputType(string $type): bool
    {
        $step = $this->getCurrentFlowStep();
        if (!$step) {
            return false;
        }

        return match ($type) {
            'location' => $step->expectsLocation(),
            'image' => $step->expectsImage(),
            'interactive', 'button' => $step->expectsInteractive(),
            'list' => $step->expectsList(),
            'text' => $step->expectsText(),
            default => false,
        };
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
     * Get the timeout for current flow.
     */
    public function getCurrentTimeout(): int
    {
        $flowType = $this->getCurrentFlowType();
        return $flowType?->timeout() ?? config('nearbuy.session.timeout_minutes', 30);
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
     * Update the session activity timestamp.
     */
    public function touch($attribute = null)
    {
        $this->last_activity_at = now();
        return $this->save();
    }

    /**
     * Update flow and step.
     *
     * @srs-ref Section 7.3 current_flow, current_step
     */
    public function setFlowStep(string|FlowType $flow, string|FlowStep $step): void
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
     * Update just the step within current flow.
     */
    public function setStep(string|FlowStep $step): void
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
    | Message Trigger Detection
    |--------------------------------------------------------------------------
    */

    /**
     * Check if message triggers menu navigation.
     *
     * @srs-ref NFR-U-04 Main menu accessible from any flow state
     */
    public static function isMenuTrigger(string $message): bool
    {
        $normalized = strtolower(trim($message));
        return in_array($normalized, self::MENU_KEYWORDS);
    }

    /**
     * Check if message triggers cancel/exit.
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
     * Check if message triggers restart.
     */
    public static function isRestartTrigger(string $message): bool
    {
        $normalized = strtolower(trim($message));
        return in_array($normalized, self::RESTART_KEYWORDS);
    }

    /**
     * Detect message intent.
     *
     * @return string|null 'menu', 'cancel', 'help', 'restart', or null
     */
    public static function detectIntent(string $message): ?string
    {
        if (self::isMenuTrigger($message)) {
            return 'menu';
        }
        if (self::isCancelTrigger($message)) {
            return 'cancel';
        }
        if (self::isHelpTrigger($message)) {
            return 'help';
        }
        if (self::isRestartTrigger($message)) {
            return 'restart';
        }
        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Temp Data Management
    |--------------------------------------------------------------------------
    */

    /**
     * Get a value from temp_data.
     *
     * @srs-ref Section 7.3 temp_data JSON object
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
     * Get all temp_data.
     */
    public function getAllTempData(): array
    {
        return $this->temp_data ?? [];
    }

    /*
    |--------------------------------------------------------------------------
    | Context Data Management (Persists across flows)
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
     * Set context value (persists across flows).
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
     * Check if context has a key.
     */
    public function hasContextValue(string $key): bool
    {
        return data_get($this->context_data, $key) !== null;
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
     * Disassociate user (for logout/reset).
     */
    public function disassociateUser(): void
    {
        $this->update(['user_id' => null]);
    }

    /**
     * Set language preference.
     *
     * @srs-ref NFR-U-05 Support English and Malayalam
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
    | Debug & Summary
    |--------------------------------------------------------------------------
    */

    /**
     * Get session summary for debugging.
     */
    public function getSummary(): array
    {
        return [
            'id' => $this->id,
            'phone' => $this->phone,
            'user_id' => $this->user_id,
            'flow' => $this->current_flow,
            'step' => $this->current_step,
            'language' => $this->language,
            'is_active' => $this->isActive(),
            'is_idle' => $this->isIdle(),
            'remaining_time' => $this->getRemainingTime() . ' minutes',
            'last_activity' => $this->last_activity_at?->diffForHumans(),
            'temp_data_keys' => array_keys($this->temp_data ?? []),
            'context_data_keys' => array_keys($this->context_data ?? []),
        ];
    }

    /**
     * Get session state as string for logging.
     */
    public function toStateString(): string
    {
        return "[{$this->phone}] {$this->current_flow}:{$this->current_step}";
    }

    /*
    |--------------------------------------------------------------------------
    | Static Methods
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

    /**
     * Get statistics for monitoring.
     */
    public static function getStatistics(): array
    {
        return [
            'total_sessions' => self::count(),
            'active_sessions' => self::active()->count(),
            'timed_out_sessions' => self::timedOut()->count(),
            'registered_users' => self::withUser()->count(),
            'anonymous_users' => self::withoutUser()->count(),
            'in_registration' => self::inFlow(FlowType::REGISTRATION)->count(),
            'in_product_search' => self::inFlow(FlowType::PRODUCT_SEARCH)->count(),
            'in_offers' => self::inFlow(FlowType::OFFERS_BROWSE)->count() +
                self::inFlow(FlowType::OFFERS_UPLOAD)->count(),
            'in_agreements' => self::inFlow(FlowType::AGREEMENT_CREATE)->count() +
                self::inFlow(FlowType::AGREEMENT_CONFIRM)->count() +
                self::inFlow(FlowType::AGREEMENT_LIST)->count(),
            'incomplete_flows' => self::withIncompleteFlow()->count(),
            'by_language' => self::selectRaw('language, COUNT(*) as count')
                ->groupBy('language')
                ->pluck('count', 'language')
                ->toArray(),
        ];
    }

    /**
     * Get sessions by flow for monitoring.
     */
    public static function getSessionsByFlow(): array
    {
        return self::selectRaw('current_flow, COUNT(*) as count')
            ->groupBy('current_flow')
            ->pluck('count', 'current_flow')
            ->toArray();
    }
}