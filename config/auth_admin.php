<?php

/**
 * Admin Authentication Configuration
 *
 * Add these configurations to your config/auth.php file
 */

return [
    /*
    |--------------------------------------------------------------------------
    | Add to 'guards' array in config/auth.php
    |--------------------------------------------------------------------------
    */

    'guards' => [
        // ... existing guards ...

        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Add to 'providers' array in config/auth.php
    |--------------------------------------------------------------------------
    */

    'providers' => [
        // ... existing providers ...

        'admins' => [
            'driver' => 'eloquent',
            'model' => App\Models\Admin::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Full Example config/auth.php
    |--------------------------------------------------------------------------
    */

    // Here's the complete auth.php for reference:

    /*
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver' => 'session',
            'provider' => 'users',
        ],

        'admin' => [
            'driver' => 'session',
            'provider' => 'admins',
        ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model' => App\Models\User::class,
        ],

        'admins' => [
            'driver' => 'eloquent',
            'model' => App\Models\Admin::class,
        ],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => 10800,
    */
];