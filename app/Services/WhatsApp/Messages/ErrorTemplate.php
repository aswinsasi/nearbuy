<?php

namespace App\Services\WhatsApp\Messages;

use App\Services\WhatsApp\Messages\MessageTemplates;

/**
 * ENHANCED Template builder for error messages.
 *
 * Key improvements:
 * 1. ALL errors now return arrays with buttons
 * 2. Consistent "Main Menu" button on every error
 * 3. Context-aware retry options
 * 4. User-friendly messaging with emojis
 */
class ErrorTemplate
{
    /**
     * Standard error buttons.
     */
    public const ERROR_BUTTONS = [
        ['id' => 'retry', 'title' => '🔄 Try Again'],
        ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
    ];

    /**
     * Build an invalid input error message.
     * 
     * ENHANCED: Now returns array with buttons.
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
     * Build invalid input error WITH buttons.
     */
    public static function invalidInputWithButtons(string $expectedType, ?string $customMessage = null): array
    {
        $message = self::invalidInput($expectedType, $customMessage);

        // Context-aware buttons based on expected type
        $buttons = match ($expectedType) {
            'button' => [
                ['id' => 'retry', 'title' => '🔄 Show Options'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'list' => [
                ['id' => 'retry', 'title' => '🔄 Show List'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'location' => [
                ['id' => 'retry', 'title' => '📍 Share Location'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'image' => [
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            default => self::ERROR_BUTTONS,
        };

        return [
            'message' => $message,
            'buttons' => $buttons,
        ];
    }

    /**
     * Build a phone validation error with buttons.
     */
    public static function invalidPhone(): array
    {
        return [
            'message' => MessageTemplates::ERROR_INVALID_PHONE,
            'buttons' => self::ERROR_BUTTONS,
        ];
    }

    /**
     * Build an amount validation error with buttons.
     */
    public static function invalidAmount(): array
    {
        return [
            'message' => MessageTemplates::ERROR_INVALID_AMOUNT,
            'buttons' => self::ERROR_BUTTONS,
        ];
    }

    /**
     * Build a date validation error with buttons.
     */
    public static function invalidDate(): array
    {
        return [
            'message' => MessageTemplates::ERROR_INVALID_DATE,
            'buttons' => self::ERROR_BUTTONS,
        ];
    }

    /**
     * Build a session timeout error with restart options.
     */
    public static function sessionTimeout(): array
    {
        return [
            'message' => MessageTemplates::ERROR_SESSION_TIMEOUT,
            'buttons' => [
                ['id' => 'restart', 'title' => '🔄 Start Fresh'],
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
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
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Build a feature disabled error.
     */
    public static function featureDisabled(): array
    {
        return [
            'message' => MessageTemplates::ERROR_FEATURE_DISABLED,
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Build a generic error message with buttons.
     */
    public static function generic(?string $context = null): array
    {
        $message = MessageTemplates::ERROR_GENERIC;

        if ($context) {
            $message .= "\n\n_Error: {$context}_";
        }

        return [
            'message' => $message,
            'buttons' => self::ERROR_BUTTONS,
        ];
    }

    /**
     * Build error with retry buttons.
     */
    public static function withRetry(string $message): array
    {
        return [
            'message' => $message,
            'buttons' => self::ERROR_BUTTONS,
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
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Build error with custom buttons.
     */
    public static function withCustomButtons(string $message, array $buttons): array
    {
        // Ensure menu button is present
        $hasMenu = false;
        foreach ($buttons as $btn) {
            if ($btn['id'] === 'main_menu' || $btn['id'] === 'menu') {
                $hasMenu = true;
                break;
            }
        }

        if (!$hasMenu && count($buttons) < 3) {
            $buttons[] = ['id' => 'main_menu', 'title' => '🏠 Menu'];
        }

        return [
            'message' => $message,
            'buttons' => $buttons,
        ];
    }

    /**
     * Build a "no results" message for various contexts with action buttons.
     */
    public static function noResults(string $context): array
    {
        $message = match ($context) {
            'offers' => "😕 *No Offers Found*\n\n" .
                "No offers in this area yet.\n\n" .
                "Try:\n" .
                "• Different category\n" .
                "• Larger search radius",
            'shops' => "😕 *No Shops Found*\n\n" .
                "No shops found nearby.\n\n" .
                "Try expanding your search radius.",
            'requests' => "📭 *No Requests*\n\n" .
                "No product requests at the moment.\n\n" .
                "Check back later for new requests.",
            'responses' => "⏳ *No Responses Yet*\n\n" .
                "Shops have been notified.\n" .
                "Please check back in a few hours.",
            'agreements' => "📋 *No Agreements*\n\n" .
                "You don't have any agreements yet.",
            'my_offers' => "📤 *No Active Offers*\n\n" .
                "You haven't uploaded any offers yet.",
            'my_requests' => "🔍 *No Active Requests*\n\n" .
                "You don't have any pending requests.",
            default => "😕 *No Results*\n\n" .
                "Nothing found. Please try again with different criteria.",
        };

        $buttons = match ($context) {
            'offers' => [
                ['id' => 'change_category', 'title' => '📂 Change Category'],
                ['id' => 'change_radius', 'title' => '📍 Expand Radius'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'shops' => [
                ['id' => 'change_radius', 'title' => '📍 Expand Radius'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'agreements' => [
                ['id' => 'create_agreement', 'title' => '📝 Create One'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'my_offers' => [
                ['id' => 'upload_offer', 'title' => '📤 Upload Offer'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'my_requests' => [
                ['id' => 'search_product', 'title' => '🔍 Search Product'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            default => [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        };

        return [
            'message' => $message,
            'buttons' => $buttons,
        ];
    }

    /**
     * Build validation error for specific fields with buttons.
     */
    public static function validationError(string $field): array
    {
        $message = match ($field) {
            'phone' => MessageTemplates::ERROR_INVALID_PHONE,
            'amount' => MessageTemplates::ERROR_INVALID_AMOUNT,
            'date' => MessageTemplates::ERROR_INVALID_DATE,
            'name' => "⚠️ *Invalid Name*\n\nPlease enter a valid name (2-100 characters).",
            'description' => "⚠️ *Too Short*\n\nPlease provide more details (minimum 10 characters).",
            'caption' => "⚠️ *Too Long*\n\nCaption must be under 500 characters.",
            'image' => "⚠️ *Invalid File*\n\nPlease send an image (JPG, PNG) or PDF.\nMax size: 5MB",
            'location' => "⚠️ *Location Required*\n\nPlease share your location using the button.",
            default => "⚠️ *Invalid Input*\n\nPlease check and try again.",
        };

        return [
            'message' => $message,
            'buttons' => self::ERROR_BUTTONS,
        ];
    }

    /**
     * Build media upload error with retry options.
     */
    public static function mediaUploadFailed(?string $reason = null): array
    {
        $message = MessageTemplates::ERROR_MEDIA_UPLOAD_FAILED;

        if ($reason) {
            $message .= "\n\n_Reason: {$reason}_";
        }

        return [
            'message' => $message,
            'buttons' => [
                ['id' => 'retry', 'title' => '🔄 Try Again'],
                ['id' => 'skip', 'title' => '⏭️ Skip'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
        ];
    }

    /**
     * Build location required error with location button.
     */
    public static function locationRequired(): array
    {
        return [
            'message' => MessageTemplates::ERROR_LOCATION_REQUIRED,
            'buttons' => [
                ['id' => 'share_location', 'title' => '📍 Share Location'],
                ['id' => 'main_menu', 'title' => '🏠 Menu'],
            ],
            'request_location' => true, // Flag to indicate location request needed
        ];
    }

    /**
     * Build network/API error with retry.
     */
    public static function networkError(): array
    {
        return [
            'message' => "🌐 *Connection Issue*\n\n" .
                "Having trouble connecting. Please try again in a moment.",
            'buttons' => self::ERROR_BUTTONS,
        ];
    }

    /**
     * Build rate limit error with wait time.
     */
    public static function rateLimited(int $waitMinutes = 5): array
    {
        return [
            'message' => "⏳ *Please Wait*\n\n" .
                "You're doing that too fast.\n" .
                "Please wait {$waitMinutes} minutes before trying again.",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Build permission denied error.
     */
    public static function permissionDenied(string $action = 'this action'): array
    {
        return [
            'message' => "🚫 *Access Denied*\n\n" .
                "You don't have permission for {$action}.",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Build expired item error (offer, request, etc).
     */
    public static function expired(string $itemType): array
    {
        $itemDisplay = match ($itemType) {
            'offer' => 'offer',
            'request' => 'product request',
            'agreement' => 'agreement',
            default => 'item',
        };

        return [
            'message' => "⏰ *Expired*\n\n" .
                "This {$itemDisplay} has expired and is no longer available.",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Build already exists error.
     */
    public static function alreadyExists(string $itemType): array
    {
        $itemDisplay = match ($itemType) {
            'response' => 'You have already responded to this request.',
            'agreement' => 'An agreement with these details already exists.',
            default => 'This item already exists.',
        };

        return [
            'message' => "⚠️ *Already Exists*\n\n{$itemDisplay}",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }

    /**
     * Build not found error.
     */
    public static function notFound(string $itemType): array
    {
        $itemDisplay = match ($itemType) {
            'offer' => 'offer',
            'request' => 'product request',
            'agreement' => 'agreement',
            'shop' => 'shop',
            'user' => 'user',
            default => 'item',
        };

        return [
            'message' => "🔍 *Not Found*\n\n" .
                "The {$itemDisplay} you're looking for couldn't be found.\n" .
                "It may have been deleted or expired.",
            'buttons' => [
                ['id' => 'main_menu', 'title' => '🏠 Main Menu'],
            ],
        ];
    }
}