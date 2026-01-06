<?php

/**
 * Authentication Configuration
 */
return [
    'default' => env('AUTH_PROVIDER', 'local'),

    'providers' => [
        'local' => [
            'table' => 'core_users',
            'username_column' => 'username',
            'password_column' => 'password',
            'active_column' => 'active',
        ],

        'entra' => [
            'tenant_id' => env('ENTRA_TENANT_ID', ''),
            'client_id' => env('ENTRA_CLIENT_ID', ''),
            'client_secret' => env('ENTRA_CLIENT_SECRET', ''),
            'redirect_uri' => env('ENTRA_REDIRECT_URI', ''),
            'scopes' => ['openid', 'profile', 'email', 'User.Read'],
        ],

        'ldap' => [
            'host' => env('LDAP_HOST', ''),
            'port' => env('LDAP_PORT', 389),
            'base_dn' => env('LDAP_BASE_DN', ''),
            'bind_dn' => env('LDAP_BIND_DN', ''),
            'bind_password' => env('LDAP_BIND_PASSWORD', ''),
            'use_tls' => env('LDAP_USE_TLS', false),
        ],

        'google' => [
            'client_id' => env('GOOGLE_CLIENT_ID', ''),
            'client_secret' => env('GOOGLE_CLIENT_SECRET', ''),
            'redirect_uri' => env('GOOGLE_REDIRECT_URI', ''),
            'hosted_domain' => env('GOOGLE_HOSTED_DOMAIN', ''),
        ],
    ],
];
