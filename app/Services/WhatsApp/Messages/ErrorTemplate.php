<?php

namespace App\Services\WhatsApp\Messages;


use App\Services\WhatsApp\Messages\MessageTemplates;
/**
 * Template builder for error messages.
 *
 * Generates user-friendly error messages with contextual help.
 */
class ErrorTemplate
{
    /**
     * Build an invalid input error message.
     */
    public static function invalidInput(string $expectedType, ?string $customMessage = null): string
    {
        $help = MessageTemplates::getExpectedInputHelp($expectedType);

        if ($customMessage) {
            return MessageTemplates::format(
                MessageTemplates::ERROR_INVALID_INPUT,
                ['expected' => $customMessage]
            );
        }

        return MessageTemplates::format(
            MessageTemplates::ERROR_INVALID_INPUT,
            ['expected' => $help]
        );
    }

    /**
     * Build a phone validation error.
     */
    public static function invalidPhone(): string
    {
        return MessageTemplates::ERROR_INVALID_PHONE;
    }

    /**
     * Build an amount validation error.
     */
    public static function invalidAmount(): string
    {
        return MessageTemplates::ERROR_INVALID_AMOUNT;
    }

    /**
     * Build a date validation error.
     */
    public static function invalidDate(): string
    {
        return MessageTemplates::ERROR_INVALID_DATE;
    }

    /**
     * Build a session timeout error with retry option.
     */
    public static function sessionTimeout(): string
    {
        return MessageTemplates::ERROR_SESSION_TIMEOUT;
    }

    /**
     * Build a not registered error with registration prompt.
     */
    public static function notRegistered(): array
    {
        return [
            'message' => MessageTemplates::ERROR_NOT_REGISTERED,
            'buttons' => [
                ['id' => 'register', 'title' => '📝 Register Now'],
                ['id' => 'browse', 'title' => '🛍️ Just Browse'],
            ],
        ];
    }

    /**
     * Build a shop-only feature error.
     */
    public static function shopOnly(): array
    {
        return [
            'message' => MessageTemplates::ERROR_SHOP_ONLY,
            'buttons' => [
                ['id' => 'register_shop', 'title' => '🏪 Register Shop'],
                ['id' => 'menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Build a feature disabled error.
     */
    public static function featureDisabled(): string
    {
        return MessageTemplates::ERROR_FEATURE_DISABLED;
    }

    /**
     * Build a generic error message.
     */
    public static function generic(?string $context = null): string
    {
        $message = MessageTemplates::ERROR_GENERIC;

        if ($context) {
            $message .= "\n\n_Error: {$context}_";
        }

        return $message;
    }

    /**
     * Build error with retry buttons.
     */
    public static function withRetry(string $message): array
    {
        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Build error with menu button only.
     */
    public static function withMenuButton(string $message): array
    {
        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Build a "no results" message for various contexts.
     */
    public static function noResults(string $context): string
    {
        return match ($context) {
            'offers' => "😕 No offers found in this area.\n\nTry expanding your search radius or selecting a different category.",
            'shops' => "😕 No shops found in this area.\n\nTry expanding your search radius.",
            'requests' => "📭 No product requests at the moment.\n\nCheck back later for new requests.",
            'responses' => "⏳ No responses yet for this request.\n\nShops have been notified. Please check back later.",
            'agreements' => "📋 You don't have any agreements yet.\n\nWould you like to create one?",
            default => "😕 No results found.\n\nPlease try again with different criteria.",
        };
    }

    /**
     * Build validation error for specific fields.
     */
    public static function validationError(string $field): string
    {
        return match ($field) {
            'phone' => self::invalidPhone(),
            'amount' => self::invalidAmount(),
            'date' => self::invalidDate(),
            'name' => "⚠️ Please enter a valid name (2-100 characters).",
            'description' => "⚠️ Description is too short. Please provide more details (minimum 10 characters).",
            'caption' => "⚠️ Caption is too long. Maximum 500 characters allowed.",
            default => "⚠️ Invalid input. Please check and try again.",
        };
    }
}