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

    'ai_config' => [
        'db_enabled' => env('AI_CONFIG_DB_ENABLED', false),
        'model_catalog' => [
            [
                'label' => 'GPT-4.1',
                'provider' => 'litellm',
                'model_name' => 'openai/gpt-4.1',
                'api_key_env' => 'GITHUB_TOKEN',
                'base_url' => 'https://models.github.ai/inference',
            ],
            [
                'label' => 'GPT-4.1 Mini',
                'provider' => 'litellm',
                'model_name' => 'openai/gpt-4.1-mini',
                'api_key_env' => 'GITHUB_TOKEN',
                'base_url' => 'https://models.github.ai/inference',
            ],
            [
                'label' => 'GPT-4o',
                'provider' => 'litellm',
                'model_name' => 'openai/gpt-4o',
                'api_key_env' => 'GITHUB_TOKEN',
                'base_url' => 'https://models.github.ai/inference',
            ],
            [
                'label' => 'Llama 3.3 70B (Groq)',
                'provider' => 'litellm',
                'model_name' => 'groq/llama-3.3-70b-versatile',
                'api_key_env' => 'GROQ_API_KEY',
            ],
            [
                'label' => 'Mistral Medium 3',
                'provider' => 'litellm',
                'model_name' => 'mistral-ai/mistral-medium-2505',
                'api_key_env' => 'GITHUB_TOKEN',
                'base_url' => 'https://models.github.ai/inference',
            ],
            [
                'label' => 'GPT-OSS 120B (Bedrock)',
                'provider' => 'bedrock_converse',
                'model_name' => 'openai.gpt-oss-120b-1:0',
                'api_key_env' => 'AWS_BEARER_TOKEN_BEDROCK',
                'region' => 'us-east-1',
            ],
            [
                'label' => 'GLM 4.7 Flash (Bedrock)',
                'provider' => 'bedrock_converse',
                'model_name' => 'zai.glm-4.7-flash',
                'api_key_env' => 'AWS_BEARER_TOKEN_BEDROCK',
                'region' => 'us-east-1',
            ],
        ],
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

    'google_drive' => [
        'service_account_json' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT_JSON'),
        'service_account_path' => env('GOOGLE_DRIVE_SERVICE_ACCOUNT_PATH'),
        'root_folder_id' => env('GOOGLE_DRIVE_ROOT_FOLDER_ID'),
        'default_upload_folder_name' => env('GOOGLE_DRIVE_UPLOAD_FOLDER_NAME', 'ISTA AI'),
        'shared_drive_id' => env('GOOGLE_DRIVE_SHARED_DRIVE_ID'),
        'impersonated_user_email' => env('GOOGLE_DRIVE_IMPERSONATED_USER_EMAIL'),
        'oauth_client_id' => env('GOOGLE_DRIVE_OAUTH_CLIENT_ID'),
        'oauth_client_secret' => env('GOOGLE_DRIVE_OAUTH_CLIENT_SECRET'),
        'oauth_redirect_uri' => env('GOOGLE_DRIVE_OAUTH_REDIRECT_URI'),
        'oauth_setup_key' => env('GOOGLE_DRIVE_OAUTH_SETUP_KEY'),
        'oauth_admin_emails' => env('GOOGLE_DRIVE_OAUTH_ADMIN_EMAILS'),
    ],

];
