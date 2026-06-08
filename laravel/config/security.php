<?php

return [
    'headers' => [
        'content_security_policy' => [
            'enabled' => env('SECURITY_CSP_ENABLED', true),
            'allow_dev_server' => env('SECURITY_CSP_ALLOW_DEV_SERVER', env('APP_ENV') !== 'production'),
            'allow_unsafe_eval' => env('SECURITY_CSP_ALLOW_UNSAFE_EVAL', env('APP_ENV') !== 'production'),
            'upgrade_insecure_requests' => env('SECURITY_CSP_UPGRADE_INSECURE_REQUESTS', env('APP_ENV') === 'production'),
        ],
    ],
];
