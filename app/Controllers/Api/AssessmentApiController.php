<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use KallioMicro\Http\Controller;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Http\ApiResponse;

class AssessmentApiController extends Controller
{
    public function index(Request $request): Response
    {
        // Demo: Return empty list (database may not be configured)
        return ApiResponse::success()
            ->withData(['assessments' => []])
            ->toResponse();
    }

    public function store(Request $request): Response
    {
        $this->requireCsrf();

        $validation = $this->validate([
            'name' => 'required|string|max:255',
        ]);

        if (!$validation['valid']) {
            return ApiResponse::validationError('Validation failed', $validation['errors'])->toResponse();
        }

        // Demo response
        return ApiResponse::success('Assessment created')
            ->flash('Assessment created successfully!', ApiResponse::CODE_SUCCESS)
            ->toResponse();
    }

    public function show(Request $request, int $id): Response
    {
        return ApiResponse::success()
            ->withData(['assessment' => ['id' => $id, 'name' => 'Demo Assessment']])
            ->toResponse();
    }

    public function update(Request $request, int $id): Response
    {
        $this->requireCsrf();

        return ApiResponse::success('Assessment updated')
            ->toResponse();
    }

    public function destroy(Request $request, int $id): Response
    {
        $this->requireCsrf();

        return ApiResponse::success('Assessment deleted')
            ->toResponse();
    }
}
