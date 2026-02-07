<?php

namespace App\Contracts;

use App\DTOs\IncomingMessage;
use App\Models\ConversationSession;

/**
 * Interface for conversation flow handlers.
 *
 * Each flow in NearBuy (registration, offers, products, agreements, fish, jobs, flash deals)
 * implements this interface to handle incoming messages within their respective flows.
 *
 * @srs-ref Section 7.1 Flow Controllers
 */
interface FlowHandlerInterface
{
    /**
     * Handle an incoming message within this flow.
     *
     * Called by FlowRouter when a message is received for a session in this flow.
     * The handler should:
     * 1. Check the current step
     * 2. Validate the input
     * 3. Process the input and update state
     * 4. Send appropriate response
     * 5. Move to next step or complete flow
     *
     * @param IncomingMessage $message The incoming WhatsApp message
     * @param ConversationSession $session The current conversation session
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void;

    /**
     * Start the flow from the beginning.
     *
     * Called when a user enters this flow for the first time or restarts it.
     * Should:
     * 1. Clear any previous temp data
     * 2. Set initial step
     * 3. Send the first prompt
     *
     * @param ConversationSession $session The current conversation session
     */
    public function start(ConversationSession $session): void;

    /**
     * Get the name/identifier of this flow.
     *
     * @return string The flow name (e.g., 'registration', 'offers_browse')
     */
    public function getName(): string;

    /**
     * Check if this flow can handle the given step.
     *
     * @param string $step The step identifier
     * @return bool True if this handler manages the step
     */
    public function canHandleStep(string $step): bool;

    /**
     * Handle invalid input for the current step.
     *
     * Called when the user provides input that doesn't match what's expected.
     * Should send a friendly error message and re-prompt.
     *
     * @param IncomingMessage $message The invalid message
     * @param ConversationSession $session The current conversation session
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void;

    /**
     * Handle timeout recovery for this flow.
     *
     * Called when a session times out mid-flow and the user returns.
     * Should either resume from where they left off or restart cleanly.
     *
     * @param ConversationSession $session The timed-out session
     */
    public function handleTimeout(ConversationSession $session): void;

    /**
     * Get the expected input type for the current step.
     *
     * @param string $step The current step
     * @return string Expected type: 'text', 'button', 'list', 'location', 'image', 'document'
     */
    public function getExpectedInputType(string $step): string;
}