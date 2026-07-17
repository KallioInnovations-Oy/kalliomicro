<?php

/**
 * Web Routes
 *
 * Routes that return HTML responses and use session-based authentication.
 */

use KallioMicro\Routing\Router;

/** @var Router $router */

// Public routes
$router->get('/', [App\Controllers\HomeController::class, 'index'])->name('home');
$router->get('/login', [App\Controllers\AuthController::class, 'showLogin'])->name('login');
$router->post('/login', [App\Controllers\AuthController::class, 'login']);
$router->get('/logout', [App\Controllers\AuthController::class, 'logout'])->name('logout');

// OAuth routes
$router->get('/auth/{provider}', [App\Controllers\AuthController::class, 'redirectToProvider'])->name('auth.redirect');
$router->get('/auth/{provider}/callback', [App\Controllers\AuthController::class, 'callback'])->name('auth.callback');

// Protected routes
$router->group(['prefix' => '/app', 'middleware' => [/* AuthMiddleware */]], function (Router $router) {
    $router->get('/dashboard', [App\Controllers\DashboardController::class, 'index'])->name('dashboard');

    // Example CRUD resource
    $router->resource('/assessments', App\Controllers\AssessmentController::class);
});
