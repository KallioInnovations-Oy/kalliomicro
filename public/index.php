<?php

/**
 * KallioMicro Framework - Application Entry Point
 *
 * All requests are routed through this file.
 */

declare(strict_types=1);

// Load Composer autoloader
require_once __DIR__ . '/../vendor/autoload.php';

// Load environment variables using native DotEnv
use KallioMicro\Support\DotEnv;
DotEnv::create(__DIR__ . '/..')->safeLoad();

// Load helper functions
require_once __DIR__ . '/../src/Support/helpers.php';

// Create application instance
$app = new KallioMicro\Core\Application(dirname(__DIR__));

// Register database connections
$dbConfig = $app->make(KallioMicro\Core\Config::class)->get('database.connections', []);
foreach ($dbConfig as $name => $config) {
    $app->registerDatabase($name, $config);
}

// Register auth manager
$app->singleton(KallioMicro\Auth\AuthManager::class, function ($app) {
    return new KallioMicro\Auth\AuthManager(
        $app->make(KallioMicro\Core\Config::class),
        $app->make(KallioMicro\Auth\Session::class),
        $app->has('db') ? $app->make(KallioMicro\Database\Connection::class) : null
    );
});

// Global middleware
$app->middleware(function ($request, $next) {
    // Start session
    $session = app(KallioMicro\Auth\Session::class);
    $session->start();

    return $next($request);
});

// Load routes
$router = $app->make(KallioMicro\Routing\Router::class);

// Define routes (in production, load from routes file)
require_once __DIR__ . '/../routes/web.php';
require_once __DIR__ . '/../routes/api.php';

// Run the application
$app->run();
