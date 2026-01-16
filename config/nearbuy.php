<?php

/**
 * NearBuy Application Configuration
 *
 * Core settings for the NearBuy hyperlocal commerce platform.
 * Includes business rules, categories, geolocation, and feature flags.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Application Settings
    |--------------------------------------------------------------------------
    |
    | General application configuration.
    |
    */

    'app' => [
        'name' => env('NEARBUY_APP_NAME', 'NearBuy'),
        'tagline' => 'Your Local Marketplace on WhatsApp',
        'support_phone' => env('NEARBUY_SUPPORT_PHONE'),
        'support_email' => env('NEARBUY_SUPPORT_EMAIL'),
        'default_language' => env('NEARBUY_DEFAULT_LANGUAGE', 'en'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geolocation Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for proximity-based features.
    | Uses MySQL ST_Distance_Sphere for calculations.
    |
    */

    'geolocation' => [
        // Default search radius in kilometers
        'default_radius_km' => env('NEARBUY_DEFAULT_RADIUS', 5),

        // Maximum allowed search radius in kilometers
        'max_radius_km' => env('NEARBUY_MAX_RADIUS', 25),

        // Minimum radius in kilometers
        'min_radius_km' => 1,

        // Available radius options for user selection (in km)
        'radius_options' => [1, 2, 3, 5, 10, 15, 20, 25],

        // Earth radius in kilometers (for distance calculations)
        'earth_radius_km' => 6371,

        // Coordinate precision for storage
        'coordinate_precision' => 8,
    ],

    /*
    |--------------------------------------------------------------------------
    | Shop Categories
    |--------------------------------------------------------------------------
    |
    | Available shop categories with display names and icons.
    | Used for filtering offers and targeting product requests.
    |
    */

    'shop_categories' => [
        'grocery' => [
            'name' => 'Grocery Store',
            'name_ml' => 'പലചരക്ക് കട',
            'icon' => '🛒',
            'description' => 'Daily essentials, provisions, and groceries',
        ],
        'electronics' => [
            'name' => 'Electronics',
            'name_ml' => 'ഇലക്ട്രോണിക്സ്',
            'icon' => '📱',
            'description' => 'Phones, appliances, and electronic gadgets',
        ],
        'clothing' => [
            'name' => 'Clothing & Fashion',
            'name_ml' => 'വസ്ത്രങ്ങൾ',
            'icon' => '👕',
            'description' => 'Apparel, fashion accessories, and textiles',
        ],
        'pharmacy' => [
            'name' => 'Pharmacy',
            'name_ml' => 'മെഡിക്കൽ ഷോപ്പ്',
            'icon' => '💊',
            'description' => 'Medicines and healthcare products',
        ],
        'hardware' => [
            'name' => 'Hardware Store',
            'name_ml' => 'ഹാർഡ്‌വെയർ',
            'icon' => '🔧',
            'description' => 'Tools, building materials, and hardware',
        ],
        'restaurant' => [
            'name' => 'Restaurant & Food',
            'name_ml' => 'ഭക്ഷണശാല',
            'icon' => '🍽️',
            'description' => 'Restaurants, cafes, and food outlets',
        ],
        'bakery' => [
            'name' => 'Bakery',
            'name_ml' => 'ബേക്കറി',
            'icon' => '🥐',
            'description' => 'Fresh baked goods and confectionery',
        ],
        'stationery' => [
            'name' => 'Stationery & Books',
            'name_ml' => 'സ്റ്റേഷനറി',
            'icon' => '📚',
            'description' => 'Office supplies, books, and stationery',
        ],
        'beauty' => [
            'name' => 'Beauty & Salon',
            'name_ml' => 'ബ്യൂട്ടി പാർലർ',
            'icon' => '💅',
            'description' => 'Beauty products and salon services',
        ],
        'automotive' => [
            'name' => 'Automotive',
            'name_ml' => 'ഓട്ടോമൊബൈൽ',
            'icon' => '🚗',
            'description' => 'Auto parts, accessories, and services',
        ],
        'jewelry' => [
            'name' => 'Jewelry',
            'name_ml' => 'ജ്വല്ലറി',
            'icon' => '💎',
            'description' => 'Gold, silver, and fashion jewelry',
        ],
        'furniture' => [
            'name' => 'Furniture',
            'name_ml' => 'ഫർണിച്ചർ',
            'icon' => '🪑',
            'description' => 'Home and office furniture',
        ],
        'sports' => [
            'name' => 'Sports & Fitness',
            'name_ml' => 'സ്പോർട്സ്',
            'icon' => '⚽',
            'description' => 'Sports equipment and fitness gear',
        ],
        'pet_store' => [
            'name' => 'Pet Store',
            'name_ml' => 'പെറ്റ് ഷോപ്പ്',
            'icon' => '🐕',
            'description' => 'Pet supplies and accessories',
        ],
        'flowers' => [
            'name' => 'Flowers & Gifts',
            'name_ml' => 'പൂക്കട',
            'icon' => '💐',
            'description' => 'Fresh flowers and gift items',
        ],
        'other' => [
            'name' => 'Other',
            'name_ml' => 'മറ്റുള്ളവ',
            'icon' => '🏪',
            'description' => 'Other retail categories',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Agreement Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for the digital agreements feature.
    |
    */

    'agreements' => [
        // Available agreement purpose types
        'purposes' => [
            'loan' => [
                'name' => 'Personal Loan',
                'name_ml' => 'വ്യക്തിഗത വായ്പ',
                'icon' => '💰',
            ],
            'work_advance' => [
                'name' => 'Work Advance',
                'name_ml' => 'ജോലി അഡ്വാൻസ്',
                'icon' => '💼',
            ],
            'deposit' => [
                'name' => 'Security Deposit',
                'name_ml' => 'സെക്യൂരിറ്റി ഡെപ്പോസിറ്റ്',
                'icon' => '🏠',
            ],
            'business_payment' => [
                'name' => 'Business Payment',
                'name_ml' => 'ബിസിനസ് പേയ്മെന്റ്',
                'icon' => '🏢',
            ],
            'other' => [
                'name' => 'Other',
                'name_ml' => 'മറ്റുള്ളവ',
                'icon' => '📝',
            ],
        ],

        // Due date options
        'due_date_options' => [
            '7_days' => ['label' => '1 Week', 'days' => 7],
            '15_days' => ['label' => '15 Days', 'days' => 15],
            '1_month' => ['label' => '1 Month', 'days' => 30],
            '2_months' => ['label' => '2 Months', 'days' => 60],
            '3_months' => ['label' => '3 Months', 'days' => 90],
            '6_months' => ['label' => '6 Months', 'days' => 180],
            '1_year' => ['label' => '1 Year', 'days' => 365],
            'custom' => ['label' => 'Custom Date', 'days' => null],
        ],

        // Agreement confirmation expiry (hours)
        'confirmation_expiry_hours' => 48,

        // Minimum agreement amount
        'min_amount' => 100,

        // Maximum agreement amount
        'max_amount' => 10000000,

        // Currency settings
        'currency' => [
            'code' => 'INR',
            'symbol' => '₹',
            'decimal_places' => 2,
        ],

        // PDF settings
        'pdf' => [
            'paper_size' => 'a4',
            'orientation' => 'portrait',
            'include_qr_code' => true,
            'watermark' => 'NearBuy Digital Agreement',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Offers Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for shop offers feature.
    |
    */

    'offers' => [
        // Maximum active offers per shop
        'max_active_per_shop' => env('NEARBUY_MAX_OFFERS_PER_SHOP', 5),

        // Default offer validity (days)
        'default_validity_days' => 7,

        // Maximum offer validity (days)
        'max_validity_days' => 30,

        // Allowed file types for offer images
        'allowed_file_types' => ['jpg', 'jpeg', 'png', 'pdf'],

        // Maximum file size (in KB)
        'max_file_size_kb' => 5120,

        // Image compression quality (1-100)
        'image_quality' => 85,

        // Thumbnail dimensions
        'thumbnail' => [
            'width' => 300,
            'height' => 300,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Product Search Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for product request and search feature.
    |
    */

    'product_search' => [
        // Maximum shops to notify per request
        'max_shops_to_notify' => env('NEARBUY_MAX_SHOP_NOTIFY', 20),

        // Time window for shop responses (hours)
        'response_window_hours' => 24,

        // Maximum responses to show customer
        'max_responses_to_show' => 10,

        // Request expiry (hours)
        'request_expiry_hours' => 48,

        // Minimum description length
        'min_description_length' => 10,

        // Maximum description length
        'max_description_length' => 500,

        // Allow image with request
        'allow_images' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Settings
    |--------------------------------------------------------------------------
    |
    | Configuration for user notifications and batching.
    |
    */

    'notifications' => [
        // Frequency options for offer notifications
        'frequency_options' => [
            'realtime' => [
                'label' => 'Real-time',
                'description' => 'Get notified immediately',
            ],
            'daily' => [
                'label' => 'Daily Digest',
                'description' => 'Once a day at 9 AM',
                'schedule' => '09:00',
            ],
            'weekly' => [
                'label' => 'Weekly Summary',
                'description' => 'Every Monday at 9 AM',
                'schedule' => 'monday 09:00',
            ],
            'none' => [
                'label' => 'No Notifications',
                'description' => 'Browse offers manually',
            ],
        ],

        // Default frequency for new users
        'default_frequency' => 'daily',

        // Quiet hours (no notifications)
        'quiet_hours' => [
            'enabled' => true,
            'start' => '22:00',
            'end' => '07:00',
        ],

        // Batch size for sending notifications
        'batch_size' => 100,
    ],

    /*
    |--------------------------------------------------------------------------
    | Session Management
    |--------------------------------------------------------------------------
    |
    | Configuration for conversation session handling.
    |
    */

    'session' => [
        // Session timeout (minutes of inactivity)
        'timeout_minutes' => env('NEARBUY_SESSION_TIMEOUT', 30),

        // Maximum temp_data size (KB)
        'max_temp_data_size_kb' => 64,

        // Auto-cleanup sessions older than (days)
        'cleanup_after_days' => 7,

        // Available flows
        'flows' => [
            'registration',
            'main_menu',
            'offers',
            'products',
            'agreements',
            'settings',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    |
    | Enable/disable specific features.
    |
    */

    'features' => [
        // Enable offers browsing
        'offers_enabled' => env('NEARBUY_OFFERS_ENABLED', true),

        // Enable product search
        'product_search_enabled' => env('NEARBUY_PRODUCT_SEARCH_ENABLED', true),

        // Enable digital agreements
        'agreements_enabled' => env('NEARBUY_AGREEMENTS_ENABLED', true),

        // Enable shop registration
        'shop_registration_enabled' => env('NEARBUY_SHOP_REGISTRATION_ENABLED', true),

        // Enable Malayalam language support
        'malayalam_support' => env('NEARBUY_MALAYALAM_SUPPORT', true),

        // Enable location requests
        'location_enabled' => env('NEARBUY_LOCATION_ENABLED', true),

        // Enable PDF generation for agreements
        'pdf_generation_enabled' => env('NEARBUY_PDF_ENABLED', true),

        // Enable QR code verification
        'qr_verification_enabled' => env('NEARBUY_QR_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Main Menu Configuration
    |--------------------------------------------------------------------------
    |
    | Structure for the main menu shown to users.
    |
    */

    'main_menu' => [
        'customer' => [
            ['id' => 'browse_offers', 'title' => '🛍️ Browse Offers', 'description' => 'See offers from nearby shops'],
            ['id' => 'search_product', 'title' => '🔍 Search Product', 'description' => 'Find a product from local shops'],
            ['id' => 'create_agreement', 'title' => '📝 Create Agreement', 'description' => 'Create a digital agreement'],
            ['id' => 'my_agreements', 'title' => '📋 My Agreements', 'description' => 'View your agreements'],
            ['id' => 'settings', 'title' => '⚙️ Settings', 'description' => 'Update your preferences'],
        ],
        'shop_owner' => [
            ['id' => 'upload_offer', 'title' => '📤 Upload Offer', 'description' => 'Share a new offer'],
            ['id' => 'my_offers', 'title' => '🏷️ My Offers', 'description' => 'Manage your offers'],
            ['id' => 'product_requests', 'title' => '📬 Product Requests', 'description' => 'View customer requests'],
            ['id' => 'shop_profile', 'title' => '🏪 Shop Profile', 'description' => 'Update shop details'],
            ['id' => 'browse_offers', 'title' => '🛍️ Browse Offers', 'description' => 'See offers from other shops'],
            ['id' => 'create_agreement', 'title' => '📝 Create Agreement', 'description' => 'Create a digital agreement'],
            ['id' => 'settings', 'title' => '⚙️ Settings', 'description' => 'Update your preferences'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Messages & Responses
    |--------------------------------------------------------------------------
    |
    | Default message templates used in the application.
    |
    */

    'messages' => [
        'welcome' => "👋 Welcome to *NearBuy*!\n\nYour local marketplace on WhatsApp.\n\nI can help you:\n• Browse offers from nearby shops\n• Search for products locally\n• Create digital agreements",

        'registration_complete' => "✅ Registration complete!\n\nYou're all set to explore NearBuy.",

        'location_request' => "📍 Please share your location so we can show you nearby shops and offers.",

        'error_generic' => "❌ Oops! Something went wrong. Please try again.",

        'session_timeout' => "⏰ Your session has timed out. Type 'hi' to start again.",

        'feature_disabled' => "🚫 This feature is currently unavailable. Please try again later.",
    ],

];