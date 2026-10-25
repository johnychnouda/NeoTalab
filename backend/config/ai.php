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

    /*
    | Conversation limits — keep per-turn AI cost bounded.
    | history_turns: messages sent to the model; stored_messages: kept in conversation state;
    | idle_hours: an active conversation older than this is closed and a fresh one started.
    */
    'conversation' => [
        'history_turns' => (int) env('AI_HISTORY_TURNS', 12),
        'stored_messages' => (int) env('AI_STORED_MESSAGES', 100),
        'idle_hours' => (int) env('AI_CONVERSATION_IDLE_HOURS', 24),
    ],

    /*
    | Customer voice notes are transcribed and then handled like typed messages.
    | max_bytes: larger files are not downloaded (about 10 minutes of WhatsApp audio).
    */
    'speech' => [
        'model' => env('AI_SPEECH_MODEL', 'gpt-4o-mini-transcribe'),
        'max_bytes' => (int) env('AI_SPEECH_MAX_BYTES', 5_000_000),
        'fake_transcript' => null,
    ],

    /*
    | Big catalogs: up to full_max active items the AI sees the whole catalog; above that it gets
    | only the items matching the conversation (at most max_items, topped up with best sellers
    | to min_items) — faster, cheaper and more accurate for supermarkets and big shops.
    */
    'catalog' => [
        'full_max' => (int) env('AI_CATALOG_FULL_MAX', 80),
        'max_items' => (int) env('AI_CATALOG_MAX_ITEMS', 40),
        'min_items' => (int) env('AI_CATALOG_MIN_ITEMS', 15),
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('OPENAI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('OPENAI_TIMEOUT', 30),
        // Only for reasoning models (GPT-5/6): none | low | medium… Unset sends nothing.
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT'),
    ],

];
