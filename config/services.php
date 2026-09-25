<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Google OAuth
    |--------------------------------------------------------------------------
    | Used by GoogleAuthService to verify Google ID tokens server-side.
    | Get your Client ID from: https://console.cloud.google.com
    */
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'places_api_key' => env('GOOGLE_PLACES_API_KEY'),
        'places_base_url' => env('GOOGLE_PLACES_BASE_URL', 'https://places.googleapis.com/v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe
    |--------------------------------------------------------------------------
    */
    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'publishable' => env('STRIPE_PUBLISHABLE_KEY'),
        'currency' => strtolower(env('STRIPE_CURRENCY', 'usd')),
    ],

    'telegram' => [
        'bot_token' => env('TELEGRAM_BOT_TOKEN'),
        'admin_chat_id' => env('TELEGRAM_ADMIN_CHAT_ID'),
    ],

    'location_provider' => env('LOCATION_PROVIDER', 'geoapify'),

    'geoapify' => [
        'api_key' => env('GEOAPIFY_API_KEY'),
        'base_url' => env('GEOAPIFY_BASE_URL', 'https://api.geoapify.com'),
    ],

    'easypost' => [
        'driver' => env('EASYPOST_DRIVER', 'easypost'),
        'api_key' => env('EASYPOST_API_KEY'),
        'webhook_secret' => env('EASYPOST_WEBHOOK_SECRET'),
        'quote_ttl_minutes' => (int) env('EASYPOST_QUOTE_TTL_MINUTES', 15),
        'supported_countries' => ['US'],
        // Dimensions converted from the warehouse's actual packaging supplier quote (cm -> in).
        // max_weight is engineering judgment (typical folded-garment load per package size),
        // not a supplier spec -- revisit once real fulfillment data is available.
        'packages' => [
            ['name' => 'bag_small', 'length' => 13.78, 'width' => 9.84, 'height' => 1.97, 'max_weight' => 24.0],
            ['name' => 'box_small', 'length' => 9.84, 'width' => 9.45, 'height' => 3.15, 'max_weight' => 32.0],
            ['name' => 'bag_large', 'length' => 19.69, 'width' => 13.78, 'height' => 1.97, 'max_weight' => 48.0],
            ['name' => 'box_medium', 'length' => 14.17, 'width' => 13.39, 'height' => 3.54, 'max_weight' => 64.0],
            ['name' => 'box_large', 'length' => 19.69, 'width' => 13.78, 'height' => 4.72, 'max_weight' => 112.0],
        ],
        'fake_rates' => [
            ['carrier' => 'MockCarrier', 'service' => 'Ground', 'rate' => 7.95, 'currency' => 'USD', 'delivery_days' => 5],
            ['carrier' => 'MockCarrier', 'service' => 'Express', 'rate' => 14.95, 'currency' => 'USD', 'delivery_days' => 2],
        ],
    ],

    'store_origin' => [
        'name' => env('STORE_ADDRESS_NAME', 'Otantik Queen'),
        'street1' => env('STORE_ADDRESS_STREET1', '123 Main Street'),
        'city' => env('STORE_ADDRESS_CITY', 'New York'),
        'state' => env('STORE_ADDRESS_STATE', 'NY'),
        'zip' => env('STORE_ADDRESS_ZIP', '10001'),
        'country' => env('STORE_ADDRESS_COUNTRY', 'US'),
        'phone' => env('STORE_ADDRESS_PHONE', '+1234567890'),
    ],

];
