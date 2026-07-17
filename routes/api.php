<?php

/**
 * API Routes
 *
 * Routes that return JSON responses.
 * These routes are prefixed with /api.
 */

use KallioMicro\Routing\Router;

/** @var Router $router */

$router->group(['prefix' => '/api'], function (Router $router) {

    // Public API endpoints
    $router->get('/health', function () {
        return \KallioMicro\Http\ApiResponse::success('OK')
            ->withData(['status' => 'healthy', 'version' => \KallioMicro\Core\Application::version()])
            ->toResponse();
    });

    // Protected API endpoints
    $router->group(['middleware' => [/* ApiAuthMiddleware */]], function (Router $router) {

        // Example: Assessment API
        $router->get('/assessments', [App\Controllers\Api\AssessmentApiController::class, 'index']);
        $router->post('/assessments', [App\Controllers\Api\AssessmentApiController::class, 'store']);
        $router->get('/assessments/{id}', [App\Controllers\Api\AssessmentApiController::class, 'show'])
            ->whereNumber('id');
        $router->put('/assessments/{id}', [App\Controllers\Api\AssessmentApiController::class, 'update'])
            ->whereNumber('id');
        $router->delete('/assessments/{id}', [App\Controllers\Api\AssessmentApiController::class, 'destroy'])
            ->whereNumber('id');

        // Example: User management
        $router->get('/users', [App\Controllers\Api\UserApiController::class, 'index']);
        $router->get('/users/me', [App\Controllers\Api\UserApiController::class, 'me']);

    });
});
