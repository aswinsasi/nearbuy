<?php

/**
 * NearBuy WhatsApp Configuration
 *
 * Configuration for WhatsApp Business Platform Cloud API integration.
 * Supports messaging, media handling, webhooks, and interactive elements.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Cloud API Credentials
    |--------------------------------------------------------------------------
    |
    | These credentials are obtained from the Meta Developer Console.
    | The access token should be a System User token for production.
    |
    */

    'api' => [
        'version' => env('WHATSAPP_API_VERSION', 'v18.0'),
        'base_url' => env('WHATSAPP_API_BASE_URL', 'https://graph.facebook.com'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for receiving and validating incoming webhook events.
    | The verify_token is used during initial webhook setup.
    | The app_secret is used for signature verification (X-Hub-Signature-256).
    |
    */

    'webhook' => [
        'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'endpoint' => '/api/webhook/whatsapp',

        // Enable/disable signature verification (disable only for development)
        'verify_signature' => env('WHATSAPP_VERIFY_SIGNATURE', true),

        // Subscribed webhook fields
        'subscribed_fields' => [
            'messages',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Message Limits & Constraints
    |--------------------------------------------------------------------------
    |
    | WhatsApp API has specific limits that must be respected.
    | These are enforced by the MessageBuilder service.
    |
    */

    'limits' => [
        // Maximum characters in message body
        'text_body' => 4096,

        // Maximum characters in button title
        'button_title' => 20,

        // Maximum reply buttons per message
        'reply_buttons' => 3,

        // Maximum items in a list message
        'list_items' => 10,

        // Maximum sections in a list message
        'list_sections' => 10,

        // Maximum characters in list item title
        'list_item_title' => 24,

        // Maximum characters in list item description
        'list_item_description' => 72,

        // Maximum characters in header text
        'header_text' => 60,

        // Maximum characters in footer text
        'footer_text' => 60,

        // Maximum file size for media upload (in bytes) - 16MB for documents
        'media_document_size' => 16 * 1024 * 1024,

        // Maximum file size for images (in bytes) - 5MB
        'media_image_size' => 5 * 1024 * 1024,
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Handling
    |--------------------------------------------------------------------------
    |
    | Configuration for downloading media from WhatsApp and uploading to storage.
    | WhatsApp media URLs expire, so files must be downloaded promptly.
    |
    */

    'media' => [
        // Timeout for media download (seconds)
        'download_timeout' => 30,

        // Supported image MIME types
        'supported_images' => [
            'image/jpeg',
            'image/png',
            'image/webp',
        ],

        // Supported document MIME types
        'supported_documents' => [
            'application/pdf',
            'image/jpeg',
            'image/png',
        ],

        // Storage disk for downloaded media
        'storage_disk' => env('WHATSAPP_MEDIA_DISK', 's3'),

        // Storage path prefix
        'storage_path' => 'whatsapp-media',

        // Media URL expiry buffer (download before this many seconds of expiry)
        'expiry_buffer_seconds' => 300,
    ],

    /*
    |--------------------------------------------------------------------------
    | Message Templates
    |--------------------------------------------------------------------------
    |
    | Pre-approved message templates for initiating conversations.
    | Templates must be approved in the Meta Business Suite.
    |
    */

    'templates' => [
        'namespace' => env('WHATSAPP_TEMPLATE_NAMESPACE'),

        // Welcome message template
        'welcome' => [
            'name' => 'nearbuy_welcome',
            'language' => 'en',
        ],

        // Agreement confirmation template
        'agreement_confirmation' => [
            'name' => 'agreement_confirmation',
            'language' => 'en',
        ],

        // Product request notification for shops
        'product_request_notification' => [
            'name' => 'product_request',
            'language' => 'en',
        ],

        // New offer notification for customers
        'new_offer_notification' => [
            'name' => 'new_offers_available',
            'language' => 'en',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Interactive Message Defaults
    |--------------------------------------------------------------------------
    |
    | Default settings for interactive messages (buttons, lists, etc.)
    |
    */

    'interactive' => [
        // Default button type
        'button_type' => 'reply',

        // Main menu button configuration
        'main_menu' => [
            'header' => '🛒 NearBuy',
            'body' => 'Welcome to NearBuy! How can I help you today?',
            'footer' => 'Powered by NearBuy',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Configure rate limiting to respect WhatsApp API quotas.
    |
    */

    'rate_limits' => [
        // Messages per second (adjust based on your tier)
        'messages_per_second' => env('WHATSAPP_RATE_LIMIT', 80),

        // Enable rate limiting
        'enabled' => env('WHATSAPP_RATE_LIMIT_ENABLED', true),

        // Queue name for rate-limited messages
        'queue' => 'whatsapp-messages',
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for retrying failed API calls.
    |
    */

    'retry' => [
        // Maximum retry attempts
        'max_attempts' => 3,

        // Delay between retries (milliseconds)
        'delay' => 1000,

        // Multiplier for exponential backoff
        'multiplier' => 2,

        // HTTP status codes that should trigger a retry
        'retry_on_status' => [429, 500, 502, 503, 504],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Configure logging for WhatsApp API interactions.
    |
    */

    'logging' => [
        // Enable detailed logging
        'enabled' => env('WHATSAPP_LOGGING_ENABLED', true),

        // Log channel
        'channel' => env('WHATSAPP_LOG_CHANNEL', 'whatsapp'),

        // Log incoming webhooks
        'log_webhooks' => env('WHATSAPP_LOG_WEBHOOKS', true),

        // Log outgoing messages
        'log_outgoing' => env('WHATSAPP_LOG_OUTGOING', true),

        // Log media downloads
        'log_media' => env('WHATSAPP_LOG_MEDIA', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Development & Testing
    |--------------------------------------------------------------------------
    |
    | Settings for development and testing environments.
    |
    */

    'testing' => [
        // Enable test mode (uses mock responses)
        'enabled' => env('WHATSAPP_TEST_MODE', false),

        // Test phone number for development
        'test_phone' => env('WHATSAPP_TEST_PHONE'),

        // Sandbox mode (use test API)
        'sandbox' => env('WHATSAPP_SANDBOX', false),
    ],

];