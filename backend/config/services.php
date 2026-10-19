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

    // Live driving times (Routes API). Optional: without it, each delivery area's own time is used.
    'google_maps' => [
        'key' => env('GOOGLE_MAPS_API_KEY'),
    ],

    // Governorate and city from a pin (Geocoding API). Falls back to the Maps key when not set.
    'google_geocoding' => [
        'key' => env('GOOGLE_GEOCODING_KEY', env('GOOGLE_MAPS_API_KEY')),
    ],

    // Whish Pay sandbox (fake money), off in production unless explicitly enabled for a test.
    'whish' => [
        'sandbox_allowed' => env('WHISH_SANDBOX_ALLOWED', env('APP_ENV') !== 'production'),
    ],

];
