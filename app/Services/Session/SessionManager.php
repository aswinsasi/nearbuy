<?php

namespace App\Services\Session;

use App\Enums\FlowType;
use App\Models\ConversationSession;
use App\Models\User;
use Illuminate\Support\Facades\Log;

/**
 * Manages conversation sessions for WhatsApp users.
 *
 * Handles session lifecycle including creation, updates, temp data storage,
 * and cleanup. Each phone number has exactly one session.
 *
 * @example
 * $sessionManager = app(SessionManager::class);
 *
 * // Get or create session
 * $session = $sessionManager->getOrCreate('919876543210');
 *
 * // Update flow and step
 * $sessionManager->setFlowStep($session, FlowType::OFFERS_BROWSE, 'select_category');
 *
 * // Store temp data
 * $sessionManager->setTempData($session, 'selected_category', 'grocery');
 *
 * // Get temp data
 * $category = $sessionManager->getTempData($session, 'selected_category');
 *
 * // Clear session
 * $sessionManager->clearSession($session);
 */
class SessionManager
{
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
            Log::info('Session timed out, resetting', ['phone' => $this->maskPhone($phone)]);
            $this->resetToMainMenu($session);
        } else {
            $this->touch($session);
        }

        return $session;
    }

    /**
     * Update the flow and step.
     */
    public function setFlowStep(ConversationSession $session, FlowType|string $flow, string $step): void
    {
        $flowValue = $flow instanceof FlowType ? $flow->value : $flow;

        $session->update([
            'current_flow' => $flowValue,
            'current_step' => $step,
            'last_activity_at' => now(),
        ]);

        Log::debug('Session flow updated', [
            'phone' => $this->maskPhone($session->phone),
            'flow' => $flowValue,
            'step' => $step,
        ]);
    }

    /**
     * Update only the step (within current flow).
     */
    public function setStep(ConversationSession $session, string $step): void
    {
        $session->update([
            'current_step' => $step,
            'last_activity_at' => now(),
        ]);

        Log::debug('Session step updated', [
            'phone' => $this->maskPhone($session->phone),
            'step' => $step,
        ]);
    }

    /**
     * Store a value in temp data.
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
     * Get a value from temp data.
     */
    public function getTempData(ConversationSession $session, string $key, mixed $default = null): mixed
    {
        return data_get($session->temp_data, $key, $default);
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
     * Reset session to main menu.
     */
    public function resetToMainMenu(ConversationSession $session): void
    {
        $session->update([
            'current_flow' => FlowType::MAIN_MENU->value,
            'current_step' => 'idle',
            'temp_data' => null,
            'last_activity_at' => now(),
        ]);

        Log::debug('Session reset to main menu', [
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Clear session completely (for re-registration, etc.).
     */
    public function clearSession(ConversationSession $session): void
    {
        $session->update([
            'current_flow' => FlowType::MAIN_MENU->value,
            'current_step' => 'idle',
            'temp_data' => null,
            'user_id' => null,
            'last_activity_at' => now(),
        ]);

        Log::info('Session cleared', [
            'phone' => $this->maskPhone($session->phone),
        ]);
    }

    /**
     * Link session to a user.
     */
    public function linkUser(ConversationSession $session, User $user): void
    {
        $session->update(['user_id' => $user->id]);

        Log::info('Session linked to user', [
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
     * Check if session has timed out.
     */
    public function hasTimedOut(ConversationSession $session): bool
    {
        $timeout = config('nearbuy.session.timeout_minutes', 30);

        return $session->last_activity_at->diffInMinutes(now()) >= $timeout;
    }

    /**
     * Check if session is in idle state.
     */
    public function isIdle(ConversationSession $session): bool
    {
        return in_array($session->current_step, ['idle', 'show_menu']);
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
     * Get the current flow type enum.
     */
    public function getCurrentFlowType(ConversationSession $session): ?FlowType
    {
        return FlowType::tryFrom($session->current_flow);
    }

    /**
     * Get the associated user (with fresh data).
     */
    public function getUser(ConversationSession $session): ?User
    {
        if (!$session->user_id) {
            return null;
        }

        return User::find($session->user_id);
    }

    /**
     * Delete sessions older than specified days.
     */
    public function cleanupOldSessions(int $days = 7): int
    {
        $count = ConversationSession::where('last_activity_at', '<', now()->subDays($days))->delete();

        Log::info('Cleaned up old sessions', ['count' => $count, 'days' => $days]);

        return $count;
    }

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