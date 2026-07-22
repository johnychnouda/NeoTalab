<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Meta Graph API
    |--------------------------------------------------------------------------
    */
    'graph_api_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
    'graph_api_base' => env('WHATSAPP_GRAPH_BASE', 'https://graph.facebook.com'),

    /*
    |--------------------------------------------------------------------------
    | Platform-level Meta app credentials (release: set in production .env)
    |--------------------------------------------------------------------------
    | One NeoTalab Meta app serves all merchants. Webhook verify + signature
    | use these values. Per-merchant Phone Number IDs live on each merchant row.
    */
    'app_secret' => env('WHATSAPP_APP_SECRET'),
    'meta_app_id' => env('WHATSAPP_META_APP_ID'),
    'embedded_signup_config_id' => env('WHATSAPP_EMBEDDED_SIGNUP_CONFIG_ID'),
    'verify_token' => env('WHATSAPP_VERIFY_TOKEN', 'neotalab-verify'),
    'platform_access_token' => env('WHATSAPP_PLATFORM_ACCESS_TOKEN'),

    /*
    |--------------------------------------------------------------------------
    | Webhook behaviour
    |--------------------------------------------------------------------------
    */
    'verify_signature' => env('WHATSAPP_VERIFY_SIGNATURE', true),

];
