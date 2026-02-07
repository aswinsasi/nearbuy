<?php

namespace App\Services\Session;

use App\Enums\FlowType;
use App\Enums\FlowStep;
use App\Models\ConversationSession;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * SessionManager - Manages conversation sessions for WhatsApp users.
 *
 * Handles session lifecycle, state management, and temp data storage.
 * Each phone number has exactly one session.
 *
 * @srs-ref Section 7.3 Session State Management
 * @srs-ref NFR-R-03 Session state persists across server restarts
 *
 * @example
 * $session = $sessionManager->getOrCreate('919876543210');
 * $sessionManager->setFlowStep($session, FlowType::OFFERS_BROWSE, 'select_category');
 * $sessionManager->setTempData($session, 'selected_category', 'grocery');
 * $category = $sessionManager->getTempData($session, 'selected_category');
 */
class SessionManager
{
    /*
    |--------------------------------------------------------------------------
    | Session Retrieval
    |--------------------------------------------------------------------------
    */

    /**
     * Get or create a session for a phone number.
     */
    public function getOrCreate(string $phone): ConversationSession
    {
        $session = ConversationSession::firstOrCreate(
            ['phone' => $phone],
            [
                'current_flow' => FlowType::MAIN_MENU->value,
                'current_step' => 'idle',
                'last_activity_at' => now(),
            ]
        );

        // Try to link to user if not already linked
        if (!$session->user_id) {
            $this->tryLinkUser($session);
        }

        return $session;
    }

    /**
     * Get session and reset if timed out.
     */
    public function getActiveOrReset(string $phone): ConversationSession
    {
        $session = $this->getOrCreate($phone);

        if ($this->hasTimedOut($session) && !$this->isIdle($session)) {
            Log::info('SessionManager: Session timed out, resetting', [
                'phone' => $this->maskPhone($phone),
            ]);
            $this->resetToMainMenu($session);
        } else {
            $this->touch($session);
        }

        return $session;
    }

    /**
     * Find session by phone (returns null if not exists).
     */
    public function findByPhone(string $phone): ?ConversationSession
    {
        return ConversationSession::where('phone', $phone)->first();
    }

    /*
    |--------------------------------------------------------------------------
    | Flow & Step Management
    |--------------------------------------------------------------------------
    */

    /**
     * Set flow and step together.
     *
     * @param ConversationSession $session
     * @param FlowType|string $flow
     * @param FlowStep|string $step
     */
    public function setFlowStep(ConversationSession $session, FlowType|string $flow, FlowStep|string $step): void
    {
        $flowValue = $flow instanceof FlowType ? $flow->value : $flow;
        $stepValue = $step instanceof FlowStep ? $step->value : $step;

        $session->update([
            'current_flow' => $flowValue,
            'current_step' => $stepValue,
            'last_activity_at' => now(),
        ]);

        Log::debug('SessionManager: Flow/step updated', [
            'phone' => $this->maskPhone($session->phone),
            'flow' => $flowValue,
            'step' => $stepValue,
        ]);
    }

    /**
     * Set only the step (within current flow).
     *
     * @param ConversationSession $session
     * @param FlowStep|string $step
     */
    public function setStep(ConversationSession $session, FlowStep|string $step): void
    {
        $stepValue = $step instanceof FlowStep ? $step->value : $step;

        $session->update([
            'current_step' => $stepValue,
            'last_activity_at' => now(),
        ]);

        Log::debug('SessionManager: Step updated', [
            'phone' => $this->maskPhone($session->phone),
            'step' => $stepValue,
        ]);
    }

    /**
     * Get current step.
     */
    public function getStep(ConversationSession $session): string
    {
        return $session->current_step;
    }

    /**
     * Get current flow.
     */
    public function getFlow(ConversationSession $session): string
    {
        return $session->current_flow;
    }

    /**
     * Get current flow as FlowType enum.
     */
    public function getCurrentFlowType(ConversationSession $session): ?FlowType
    {
        return FlowType::tryFrom($session->current_flow);
    }

    /**
     * Get current step as FlowStep enum.
     */
    public function getCurrentFlowStep(ConversationSession $session): ?FlowStep
    {
        return FlowStep::tryFrom($session->current_step);
    }

    /**
     * Check if session is in a specific flow.
     */
    public function isInFlow(ConversationSession $session, FlowType|string $flow): bool
    {
        $flowValue = $flow instanceof FlowType ? $flow->value : $flow;
        return $session->current_flow === $flowValue;
    }

    /**
     * Check if session is at a specific step.
     */
    public function isAtStep(ConversationSession $session, FlowStep|string $step): bool
    {
        $stepValue = $step instanceof FlowStep ? $step->value : $step;
        return $session->current_step === $stepValue;
    }

    /**
     * Start a new flow (clears temp data).
     */
    public function startFlow(ConversationSession $session, FlowType $flow, ?array $initialData = null): void
    {
        $session->update([
            'current_flow' => $flow->value,
            'current_step' => $flow->initialStep(),
            'temp_data' => $initialData,
            'last_activity_at' => now(),
        ]);

        Log::debug('SessionManager: Flow started', [
            'phone' => $this->maskPhone($session->phone),
            'flow' => $flow->value,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Navigation
    |--------------------------------------------------------------------------
    */

    /**
     * Reset session to main menu.
     *
     * @srs-ref NFR-U-04 Main menu accessible from any flow state
     */
    public function resetToMainMenu(ConversationSession $session): void
    {
        $session->update([
            'current_flow' => FlowType::MAIN_MENU->value,
            'current_step' => 'idle',
            'temp_data' => null,
            'last_activity_at' => now(),
        ]);

        Log::debug('SessionManager: Reset to main menu', [
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Alias for resetToMainMenu.
     */
    public function resetToMenu(ConversationSession $session): void
    {
        $this->resetToMainMenu($session);
    }

    /**
     * Go back to previous step.
     *
     * Requires 'previous_step' to be stored in temp_data.
     * Falls back to main menu if no previous step.
     */
    public function goBack(ConversationSession $session): void
    {
        $previousStep = $this->getTempData($session, 'previous_step');
        $previousFlow = $this->getTempData($session, 'previous_flow');

        if ($previousStep) {
            $session->update([
                'current_step' => $previousStep,
                'current_flow' => $previousFlow ?? $session->current_flow,
                'last_activity_at' => now(),
            ]);

            // Clear the previous step from temp data
            $this->removeTempData($session, 'previous_step');
            $this->removeTempData($session, 'previous_flow');

            Log::debug('SessionManager: Went back', [
                'phone' => $this->maskPhone($session->phone),
                'to_step' => $previousStep,
            ]);
        } else {
            // No previous step, go to main menu
            $this->resetToMainMenu($session);
        }
    }

    /**
     * Store current step as previous before moving to next.
     * Call this before setStep() to enable goBack().
     */
    public function savePreviousStep(ConversationSession $session): void
    {
        $this->setTempData($session, 'previous_step', $session->current_step);
        $this->setTempData($session, 'previous_flow', $session->current_flow);
    }

    /**
     * Complete current flow and return to menu.
     */
    public function completeFlow(ConversationSession $session): void
    {
        $this->resetToMainMenu($session);
    }

    /*
    |--------------------------------------------------------------------------
    | Temp Data Management
    |--------------------------------------------------------------------------
    */

    /**
     * Set a single temp data value.
     *
     * @param ConversationSession $session
     * @param string $key
     * @param mixed $value
     */
    public function setTempData(ConversationSession $session, string $key, mixed $value): void
    {
        $data = $session->temp_data ?? [];
        $data[$key] = $value;

        $session->update([
            'temp_data' => $data,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Get a temp data value.
     *
     * @param ConversationSession $session
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public function getTempData(ConversationSession $session, string $key, mixed $default = null): mixed
    {
        return data_get($session->temp_data, $key, $default);
    }

    /**
     * Check if temp data has a key.
     */
    public function hasTempData(ConversationSession $session, string $key): bool
    {
        return $this->getTempData($session, $key) !== null;
    }

    /**
     * Get all temp data.
     */
    public function getAllTempData(ConversationSession $session): array
    {
        return $session->temp_data ?? [];
    }

    /**
     * Merge multiple values into temp data.
     */
    public function mergeTempData(ConversationSession $session, array $data): void
    {
        $existing = $session->temp_data ?? [];
        $merged = array_merge($existing, $data);

        $session->update([
            'temp_data' => $merged,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Remove a key from temp data.
     */
    public function removeTempData(ConversationSession $session, string $key): void
    {
        $data = $session->temp_data ?? [];
        unset($data[$key]);

        $session->update([
            'temp_data' => empty($data) ? null : $data,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Clear all temp data.
     */
    public function clearTempData(ConversationSession $session): void
    {
        $session->update([
            'temp_data' => null,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Increment a numeric temp data value.
     */
    public function incrementTempData(ConversationSession $session, string $key, int $amount = 1): int
    {
        $current = (int) $this->getTempData($session, $key, 0);
        $new = $current + $amount;
        $this->setTempData($session, $key, $new);
        return $new;
    }

    /**
     * Append to an array in temp data.
     */
    public function appendTempData(ConversationSession $session, string $key, mixed $value): void
    {
        $current = $this->getTempData($session, $key, []);

        if (!is_array($current)) {
            $current = [$current];
        }

        $current[] = $value;
        $this->setTempData($session, $key, $current);
    }

    /*
    |--------------------------------------------------------------------------
    | Context Data (Persists across flows)
    |--------------------------------------------------------------------------
    */

    /**
     * Set context data (persists across flows).
     */
    public function setContextData(ConversationSession $session, string $key, mixed $value): void
    {
        $data = $session->context_data ?? [];
        $data[$key] = $value;

        $session->update([
            'context_data' => $data,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Get context data.
     */
    public function getContextData(ConversationSession $session, string $key, mixed $default = null): mixed
    {
        return data_get($session->context_data, $key, $default);
    }

    /**
     * Clear context data.
     */
    public function clearContextData(ConversationSession $session): void
    {
        $session->update([
            'context_data' => null,
            'last_activity_at' => now(),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | State Checks
    |--------------------------------------------------------------------------
    */

    /**
     * Check if session has timed out.
     */
    public function hasTimedOut(ConversationSession $session): bool
    {
        $flowType = $this->getCurrentFlowType($session);
        $timeout = $flowType?->timeout() ?? config('nearbuy.session.timeout_minutes', 30);

        return $session->last_activity_at->diffInMinutes(now()) >= $timeout;
    }

    /**
     * Check if session is in idle state.
     */
    public function isIdle(ConversationSession $session): bool
    {
        return in_array($session->current_step, ['idle', 'show_menu', 'main_menu']);
    }

    /**
     * Check if session is in any active flow.
     */
    public function isInActiveFlow(ConversationSession $session): bool
    {
        return !$this->isIdle($session);
    }

    /**
     * Check if user is registered.
     */
    public function isRegistered(ConversationSession $session): bool
    {
        if (!$session->user_id) {
            return false;
        }

        $user = $session->user;
        return $user && $user->registered_at !== null;
    }

    /**
     * Get remaining time before timeout (in minutes).
     */
    public function getRemainingTime(ConversationSession $session): int
    {
        $flowType = $this->getCurrentFlowType($session);
        $timeout = $flowType?->timeout() ?? config('nearbuy.session.timeout_minutes', 30);
        $elapsed = $session->last_activity_at->diffInMinutes(now());

        return max(0, $timeout - $elapsed);
    }

    /*
    |--------------------------------------------------------------------------
    | User Management
    |--------------------------------------------------------------------------
    */

    /**
     * Get the associated user.
     */
    public function getUser(ConversationSession $session): ?User
    {
        if (!$session->user_id) {
            return null;
        }

        return User::find($session->user_id);
    }

    /**
     * Link session to a user.
     */
    public function linkUser(ConversationSession $session, User $user): void
    {
        $session->update(['user_id' => $user->id]);

        Log::info('SessionManager: Session linked to user', [
            'phone' => $this->maskPhone($session->phone),
            'user_id' => $user->id,
        ]);
    }

    /**
     * Try to link session to existing user by phone.
     */
    public function tryLinkUser(ConversationSession $session): bool
    {
        $user = User::where('phone', $session->phone)->first();

        if ($user) {
            $this->linkUser($session, $user);
            return true;
        }

        return false;
    }

    /**
     * Unlink user from session.
     */
    public function unlinkUser(ConversationSession $session): void
    {
        $session->update(['user_id' => null]);
    }

    /*
    |--------------------------------------------------------------------------
    | Activity & Message Recording
    |--------------------------------------------------------------------------
    */

    /**
     * Update last activity timestamp.
     */
    public function touch(ConversationSession $session): void
    {
        $session->update(['last_activity_at' => now()]);
    }

    /**
     * Record last message info.
     */
    public function recordMessage(ConversationSession $session, string $messageId, string $messageType): void
    {
        $session->update([
            'last_message_id' => $messageId,
            'last_message_type' => $messageType,
            'last_activity_at' => now(),
        ]);
    }

    /**
     * Check if message is a duplicate.
     */
    public function isDuplicateMessage(ConversationSession $session, string $messageId): bool
    {
        return $session->last_message_id === $messageId;
    }

    /*
    |--------------------------------------------------------------------------
    | Language
    |--------------------------------------------------------------------------
    */

    /**
     * Set language preference.
     *
     * @srs-ref NFR-U-05 Support English and Malayalam
     */
    public function setLanguage(ConversationSession $session, string $language): void
    {
        if (in_array($language, ['en', 'ml'])) {
            $session->update(['language' => $language]);
        }
    }

    /**
     * Get language.
     */
    public function getLanguage(ConversationSession $session): string
    {
        return $session->language ?? 'en';
    }

    /*
    |--------------------------------------------------------------------------
    | Session Management
    |--------------------------------------------------------------------------
    */

    /**
     * Clear session completely (for re-registration, etc.).
     */
    public function clearSession(ConversationSession $session): void
    {
        $session->update([
            'current_flow' => FlowType::MAIN_MENU->value,
            'current_step' => 'idle',
            'temp_data' => null,
            'context_data' => null,
            'user_id' => null,
            'last_activity_at' => now(),
        ]);

        Log::info('SessionManager: Session cleared', [
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Delete session completely.
     */
    public function deleteSession(ConversationSession $session): void
    {
        $phone = $session->phone;
        $session->delete();

        Log::info('SessionManager: Session deleted', [
            'phone' => $this->maskPhone($phone),
        ]);
    }

    /**
     * Delete sessions older than specified days.
     */
    public function cleanupOldSessions(int $days = 1): int
    {
        $count = ConversationSession::where('last_activity_at', '<', now()->subDays($days))->delete();

        Log::info('SessionManager: Cleaned up old sessions', [
            'count' => $count,
            'days' => $days,
        ]);

        return $count;
    }

    /*
    |--------------------------------------------------------------------------
    | Debug & Statistics
    |--------------------------------------------------------------------------
    */

    /**
     * Get session summary for debugging.
     */
    public function getSummary(ConversationSession $session): array
    {
        return [
            'id' => $session->id,
            'phone' => $this->maskPhone($session->phone),
            'user_id' => $session->user_id,
            'flow' => $session->current_flow,
            'step' => $session->current_step,
            'language' => $session->language,
            'is_idle' => $this->isIdle($session),
            'is_registered' => $this->isRegistered($session),
            'has_timed_out' => $this->hasTimedOut($session),
            'remaining_time' => $this->getRemainingTime($session) . ' min',
            'last_activity' => $session->last_activity_at?->diffForHumans(),
            'temp_data_keys' => array_keys($session->temp_data ?? []),
        ];
    }

    /**
     * Get state string for logging.
     */
    public function toStateString(ConversationSession $session): string
    {
        return "[{$this->maskPhone($session->phone)}] {$session->current_flow}:{$session->current_step}";
    }

    /**
     * Get statistics for monitoring.
     */
    public function getStatistics(): array
    {
        return [
            'total_sessions' => ConversationSession::count(),
            'active_sessions' => ConversationSession::where('last_activity_at', '>=', now()->subMinutes(30))->count(),
            'registered_users' => ConversationSession::whereNotNull('user_id')->count(),
            'in_registration' => ConversationSession::where('current_flow', FlowType::REGISTRATION->value)->count(),
            'by_flow' => ConversationSession::selectRaw('current_flow, COUNT(*) as count')
                ->groupBy('current_flow')
                ->pluck('count', 'current_flow')
                ->toArray(),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Private Helpers
    |--------------------------------------------------------------------------
    */

    /**
     * Mask phone number for logging.
     */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return $phone;
        }
        return substr($phone, 0, 3) . '****' . substr($phone, -3);
    }
}