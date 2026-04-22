<?php

return [

    /*
    |--------------------------------------------------------------------------
    | AI Service Configuration
    |--------------------------------------------------------------------------
    |
    | Konfigurasi terpusat untuk layanan AI. Ubah nilai di sini untuk
    | pergantian operasional tanpa mengubah logika inti.
    |
    */

    'global' => [
        'timeout' => env('AI_TIMEOUT', 30),
        'connect_timeout' => env('AI_CONNECT_TIMEOUT', 10),
        'read_timeout' => env('AI_READ_TIMEOUT', 120),
        'retry_attempts' => env('AI_RETRY_ATTEMPTS', 2),
        'retry_delay_ms' => env('AI_RETRY_DELAY_MS', 400),
    ],

    'lanes' => [
        'chat' => [
            'url' => env('AI_SERVICE_URL', 'http://localhost:8001'),
            'token' => env('AI_SERVICE_TOKEN'),
        ],
    ],

    'laravel_ai' => [
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'api_key' => env('OPENAI_API_KEY'),
        'web_search' => [
            'enabled' => env('AI_WEB_SEARCH_ENABLED', true),
            'provider' => env('AI_WEB_SEARCH_PROVIDER', 'ddg'),
        ],
    ],

];
