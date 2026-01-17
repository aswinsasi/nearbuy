<?php

namespace App\Contracts;

use App\DTOs\IncomingMessage;
use App\Models\ConversationSession;

/**
 * Interface for conversation flow handlers.
 *
 * Each flow in NearBuy (registration, offers, products, agreements)
 * should implement this interface to handle incoming messages
 * within their respective conversation flows.
 */
interface FlowHandlerInterface
{
    /**
     * Handle an incoming message within this flow.
     *
     * This method is called by the FlowRouter when a message
     * is received for a session currently in this flow.
     *
     * @param IncomingMessage $message The incoming WhatsApp message
     * @param ConversationSession $session The current conversation session
     * @return void
     */
    public function handle(IncomingMessage $message, ConversationSession $session): void;

    /**
     * Start the flow from the beginning.
     *
     * Called when a user enters this flow for the first time
     * or restarts the flow.
     *
     * @param ConversationSession $session The current conversation session
     * @return void
     */
    public function start(ConversationSession $session): void;

    /**
     * Get the name/identifier of this flow.
     *
     * @return string
     */
    public function getName(): string;

    /**
     * Check if this flow can handle the given step.
     *
     * @param string $step The step identifier
     * @return bool
     */
    public function canHandleStep(string $step): bool;

    /**
     * Handle invalid input for the current step.
     *
     * Called when the user provides input that doesn't match
     * what's expected for the current step.
     *
     * @param IncomingMessage $message The invalid message
     * @param ConversationSession $session The current conversation session
     * @return void
     */
    public function handleInvalidInput(IncomingMessage $message, ConversationSession $session): void;
}