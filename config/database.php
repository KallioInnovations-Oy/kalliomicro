<?php

/**
 * Database Configuration
 */
return [
    'default' => env('DB_CONNECTION', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver' => 'mysql',
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', 3306),
            'database' => env('DB_DATABASE', 'meso_production'),
            'username' => env('DB_USERNAME', ''),
            'password' => env('DB_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ],

        'dwh' => [
            'driver' => 'mysql',
            'host' => env('DWH_HOST', 'localhost'),
            'port' => env('DWH_PORT', 3306),
            'database' => env('DWH_DATABASE', 'meso_dwh'),
            'username' => env('DWH_USERNAME', ''),
            'password' => env('DWH_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
        ],
    ],
];
