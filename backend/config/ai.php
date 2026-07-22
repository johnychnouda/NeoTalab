<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Chat Provider
    |--------------------------------------------------------------------------
    |
    | Driver: "fake" (deterministic, no API key — default for tests/local) or
    | "openai" (OpenAI-compatible structured JSON output API).
    |
    */

    'driver' => env('AI_DRIVER', 'fake'),

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
    ],

];
