<?php

/**
 * Application Configuration
 */
return [
    'name' => env('APP_NAME', 'KallioMicro'),
    'env' => env('APP_ENV', 'production'),
    'debug' => env('APP_DEBUG', false),
    'url' => env('APP_URL', 'http://localhost'),
    'timezone' => 'Europe/Helsinki',
    'locale' => 'fi',
    'fallback_locale' => 'en',

    // Peers (REMOTE_ADDR, exact match) whose X-Forwarded-For header Request::ip()
    // may trust. Empty = never trust XFF; ip() returns REMOTE_ADDR.
    'trusted_proxies' => [],
];
