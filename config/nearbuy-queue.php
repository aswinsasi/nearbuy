<?php

/**
 * NearBuy Queue Configuration.
 *
 * PRIORITY QUEUES (highest to lowest):
 * 1. flash-deals     - Time-critical flash deal notifications
 * 2. fish-alerts     - Fish seller arrival alerts
 * 3. job-notifications - Worker job notifications
 * 4. product-requests - Product request alerts to shops
 * 5. offers          - Offer notifications (lowest priority)
 * 6. notifications   - General batched notifications
 * 7. default         - Everything else
 *
 * RUN WORKERS WITH PRIORITY ORDER:
 * php artisan queue:work --queue=flash-deals,fish-alerts,job-notifications,product-requests,offers,notifications,default
 *
 * @srs-ref NFR-P-01 - Webhook processing < 5 seconds
 * @srs-ref NFR-R-02 - Failed deliveries retried with exponential backoff
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Queue Priorities
    |--------------------------------------------------------------------------
    |
    | Define queue names in priority order. Workers should process
    | queues in this exact order for proper priority handling.
    |
    */

    'priorities' => [
        'flash-deals',       // Priority 1 - Highest
        'fish-alerts',       // Priority 2
        'job-notifications', // Priority 3
        'product-requests',  // Priority 4
        'offers',            // Priority 5
        'notifications',     // Priority 6 - Batched
        'default',           // Priority 7 - Lowest
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Command
    |--------------------------------------------------------------------------
    |
    | Recommended command for running queue workers with priorities.
    |
    */

    'worker_command' => 'php artisan queue:work --queue=flash-deals,fish-alerts,job-notifications,product-requests,offers,notifications,default --tries=3 --backoff=60,120,240',

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | WhatsApp Business API allows ~80 messages/second.
    | We set a conservative limit to avoid hitting rate limits.
    |
    */

    'rate_limits' => [
        'whatsapp-api' => [
            'per_second' => 70,        // 70/sec (conservative, API allows ~80)
            'per_minute' => 4000,      // 4000/min
            'decay_seconds' => 60,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Quiet Hours
    |--------------------------------------------------------------------------
    |
    | Messages are delayed during quiet hours unless marked urgent
    | or exempt (e.g., Flash Deal coupons, OTPs).
    |
    */

    'quiet_hours' => [
        'enabled' => true,
        'start' => 22,  // 10 PM
        'end' => 7,     // 7 AM

        // These notification types bypass quiet hours
        'exempt_types' => [
            'flash_deal_activation',
            'flash_deal_coupon',
            'fish_arrival_imminent',
            'job_accepted',
            'otp',
            'urgent',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retry Configuration
    |--------------------------------------------------------------------------
    |
    | Exponential backoff for failed jobs.
    |
    */

    'retry' => [
        'max_tries' => 3,
        'backoff' => [60, 120, 240],  // 1 min, 2 min, 4 min
        'retry_until_hours' => 2,     // Stop retrying after 2 hours
    ],

    /*
    |--------------------------------------------------------------------------
    | Batch Configuration
    |--------------------------------------------------------------------------
    |
    | Settings for batched notifications.
    |
    */

    'batching' => [
        'max_items_per_batch' => 50,
        'max_batch_age_hours' => 24,

        // Schedule times for each frequency
        'schedules' => [
            'daily' => ['09:00'],
            'twice_daily' => ['09:00', '17:00'],
            '2hours' => 'even_hours',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Notification logging configuration.
    |
    */

    'logging' => [
        'enabled' => true,
        'channel' => 'whatsapp',
        'log_content' => false,      // Don't log message content (privacy)
        'retention_days' => 30,      // Keep logs for 30 days
    ],

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Job timeout configuration.
    |
    */

    'timeouts' => [
        'text' => 10,       // 10 seconds for text messages
        'buttons' => 15,    // 15 seconds for interactive
        'list' => 15,       // 15 seconds for list
        'image' => 30,      // 30 seconds for media
        'document' => 30,   // 30 seconds for documents
        'batch' => 60,      // 60 seconds for batch processing
    ],

];