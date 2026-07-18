<?php

/**
 * Notification Configuration
 *
 * Consumed by KallioMicro\Support\Communicator. Lives here because the security
 * checklist forbids env() outside config/*.php; Communicator reads these through
 * config() and merges anything its caller passes on top.
 */
return [
    'email' => [
        'host' => env('MAIL_HOST', 'localhost'),
        'port' => (int) env('MAIL_PORT', 587),
        'username' => env('MAIL_USERNAME', ''),
        'password' => env('MAIL_PASSWORD', ''),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
        'from_address' => env('MAIL_FROM_ADDRESS', 'noreply@example.com'),
        'from_name' => env('MAIL_FROM_NAME', 'KallioMicro'),
    ],

    'webhooks' => [
        'teams' => env('WEBHOOK_TEAMS', ''),
        'slack' => env('WEBHOOK_SLACK', ''),
    ],
];
