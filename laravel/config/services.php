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
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'ai_service' => [
        'url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),
        'token' => env('AI_SERVICE_TOKEN'),
        'connect_timeout' => env('AI_SERVICE_CONNECT_TIMEOUT', 10),
        'timeout' => env('AI_SERVICE_TIMEOUT', 120),
        'read_timeout' => env('AI_SERVICE_READ_TIMEOUT', 120),
        'retries' => env('AI_SERVICE_RETRIES', 2),
        'retry_delay_ms' => env('AI_SERVICE_RETRY_DELAY_MS', 400),
        'max_history_messages' => env('AI_SERVICE_MAX_HISTORY_MESSAGES', 20),
    ],

    'ai_document_service' => [
        'url' => env('AI_DOCUMENT_SERVICE_URL', env('AI_SERVICE_URL', 'http://127.0.0.1:8001')),
        'token' => env('AI_DOCUMENT_SERVICE_TOKEN', env('AI_SERVICE_TOKEN')),
        'connect_timeout' => env('AI_DOCUMENT_SERVICE_CONNECT_TIMEOUT', env('AI_SERVICE_CONNECT_TIMEOUT', 10)),
        'timeout' => env('AI_DOCUMENT_SERVICE_TIMEOUT', env('AI_SERVICE_TIMEOUT', 120)),
        'read_timeout' => env('AI_DOCUMENT_SERVICE_READ_TIMEOUT', env('AI_SERVICE_READ_TIMEOUT', 120)),
    ],

    'onlyoffice' => [
        'public_url' => env('ONLYOFFICE_PUBLIC_URL', 'http://127.0.0.1:8080'),
        'internal_url' => env('ONLYOFFICE_INTERNAL_URL', 'http://onlyoffice'),
        'laravel_internal_url' => env('ONLYOFFICE_LARAVEL_INTERNAL_URL', env('APP_URL', 'http://localhost')),
        'jwt_secret' => env('ONLYOFFICE_JWT_SECRET'),
        'signed_url_secret' => env('ONLYOFFICE_SIGNED_URL_SECRET'),
        'signed_url_ttl_minutes' => env('ONLYOFFICE_SIGNED_URL_TTL_MINUTES', 30),
        'conversion_timeout' => env('ONLYOFFICE_CONVERSION_TIMEOUT', 120),
        'force_save_wait_seconds' => env('ONLYOFFICE_FORCE_SAVE_WAIT_SECONDS', 12),
        'force_save_command_timeout' => env('ONLYOFFICE_FORCE_SAVE_COMMAND_TIMEOUT', 10),
        'force_save_poll_microseconds' => env('ONLYOFFICE_FORCE_SAVE_POLL_MICROSECONDS', 250000),
    ],

];
