<?php

/**
 * Session Configuration
 */
return [
    'cookie' => env('SESSION_COOKIE', 'kalliomicro_session'),
    'lifetime' => env('SESSION_LIFETIME', 120), // minutes
    'secure' => env('SESSION_SECURE', true),
    'http_only' => true,
    'same_site' => 'Lax',
    'domain' => env('SESSION_DOMAIN', ''),
    'regenerate_interval' => 300, // seconds
];
