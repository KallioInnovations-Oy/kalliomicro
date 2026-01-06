<?php

declare(strict_types=1);

namespace App\Controllers;

use KallioMicro\Http\Controller;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Http\ApiResponse;

/**
 * AssessmentController - Example controller demonstrating framework patterns
 *
 * This controller shows how to:
 * - Handle CRUD operations
 * - Validate input
 * - Return unified responses with actions
 * - Work with the database query builder
 * - Use templates
 */
class AssessmentController extends Controller
{
    /**
     * Display a listing of assessments
     */
    public function index(Request $request): Response
    {
        $assessments = $this->table('content_smallforms')
            ->select(['id', 'rowtype', 'data1', 'data2', 'origdate', 'moddate'])
            ->where('rowtype', 'LIKE', 'assessment%')
            ->orderByDesc('moddate')
            ->forPage($request->integer('page', 1), 25)
            ->get();

        if ($this->wantsJson()) {
            return ApiResponse::success()
                ->withData(['assessments' => $assessments])
                ->toResponse();
        }

        return $this->render('assessments.index', [
            'assessments' => $assessments,
        ]);
    }

    /**
     * Show the form for creating a new assessment
     */
    public function create(Request $request): Response
    {
        if ($this->isAjax()) {
            $content = $this->renderPartial('assessments.form', [
                'assessment' => null,
            ]);

            return ApiResponse::success()
                ->modal($content, 'lg')
                ->toResponse();
        }

        return $this->render('assessments.create');
    }

    /**
     * Store a newly created assessment
     */
    public function store(Request $request): Response
    {
        $this->requireCsrf();

        // Validate input
        $validation = $this->validate([
            'rowtype' => 'required|string',
            'order_name' => 'required|string|max:255',
            'order_address' => 'required|string|max:255',
            'reportername' => 'required|string|max:255',
            'reporterphone' => 'required|string',
            'reporteremail' => 'required|email',
            'description' => 'string',
        ]);

        if (!$validation['valid']) {
            return ApiResponse::validationError('Please correct the errors', $validation['errors'])
                ->toResponse();
        }

        // Insert into database
        $id = $this->table('content_smallforms')->insert([
            'origdate' => date('Y-m-d H:i:s'),
            'moddate' => date('Y-m-d H:i:s'),
            'user_id' => $this->userId(),
            'user_id_mod' => $this->userId(),
            'rowtype' => $this->input('rowtype'),
            'data1' => $this->input('order_name'),
            'data2' => $this->input('order_address'),
            'data3' => $this->input('reportername'),
            'data4' => $this->input('reporterphone'),
            'data5' => $this->input('reporteremail'),
            'data6' => $this->input('description'),
        ]);

        return ApiResponse::success('Assessment saved successfully')
            ->flash('Assessment created!', ApiResponse::CODE_SUCCESS)
            ->updateField('#form_id', (string) $id)
            ->closeModal()
            ->refreshTable('#assessments-table')
            ->toResponse();
    }

    /**
     * Display the specified assessment
     */
    public function show(Request $request, string $id): Response
    {
        $assessment = $this->table('content_smallforms')
            ->where('id', $id)
            ->first();

        if (!$assessment) {
            return ApiResponse::notFound('Assessment not found')->toResponse();
        }

        if ($this->wantsJson()) {
            return ApiResponse::success()
                ->withData(['assessment' => $assessment])
                ->toResponse();
        }

        return $this->render('assessments.show', [
            'assessment' => $assessment,
        ]);
    }

    /**
     * Show the form for editing the specified assessment
     */
    public function edit(Request $request, string $id): Response
    {
        $assessment = $this->table('content_smallforms')
            ->where('id', $id)
            ->first();

        if (!$assessment) {
            return ApiResponse::notFound('Assessment not found')->toResponse();
        }

        if ($this->isAjax()) {
            $content = $this->renderPartial('assessments.form', [
                'assessment' => $assessment,
            ]);

            return ApiResponse::success()
                ->modal($content, 'lg')
                ->toResponse();
        }

        return $this->render('assessments.edit', [
            'assessment' => $assessment,
        ]);
    }

    /**
     * Update the specified assessment
     */
    public function update(Request $request, string $id): Response
    {
        $this->requireCsrf();

        $assessment = $this->table('content_smallforms')
            ->where('id', $id)
            ->first();

        if (!$assessment) {
            return ApiResponse::notFound('Assessment not found')->toResponse();
        }

        // Check permission (user owns it or is admin)
        if ($assessment['user_id'] != $this->userId() && $this->session->getProfileId() != 1) {
            return ApiResponse::forbidden('You cannot edit this assessment')->toResponse();
        }

        // Validate input
        $validation = $this->validate([
            'order_name' => 'required|string|max:255',
            'order_address' => 'required|string|max:255',
            'description' => 'string',
        ]);

        if (!$validation['valid']) {
            return ApiResponse::validationError('Please correct the errors', $validation['errors'])
                ->toResponse();
        }

        // Update database
        $this->table('content_smallforms')
            ->where('id', $id)
            ->update([
                'moddate' => date('Y-m-d H:i:s'),
                'user_id_mod' => $this->userId(),
                'data1' => $this->input('order_name'),
                'data2' => $this->input('order_address'),
                'data6' => $this->input('description'),
            ]);

        return ApiResponse::success('Assessment updated successfully')
            ->flash('Changes saved!', ApiResponse::CODE_SUCCESS)
            ->closeModal()
            ->refreshTable('#assessments-table')
            ->toResponse();
    }

    /**
     * Remove the specified assessment
     */
    public function destroy(Request $request, string $id): Response
    {
        $this->requireCsrf();

        $assessment = $this->table('content_smallforms')
            ->where('id', $id)
            ->first();

        if (!$assessment) {
            return ApiResponse::notFound('Assessment not found')->toResponse();
        }

        // Check permission
        if ($assessment['user_id'] != $this->userId() && $this->session->getProfileId() != 1) {
            return ApiResponse::forbidden('You cannot delete this assessment')->toResponse();
        }

        // Delete
        $this->table('content_smallforms')
            ->where('id', $id)
            ->delete();

        return ApiResponse::success('Assessment deleted')
            ->flash('Assessment deleted', ApiResponse::CODE_SUCCESS)
            ->remove("#assessment-row-{$id}")
            ->toResponse();
    }

    /**
     * Search assessments (AJAX)
     */
    public function search(Request $request): Response
    {
        $query = $this->table('content_smallforms')
            ->where('rowtype', 'LIKE', 'assessment%');

        // Apply search filters
        if ($term = $request->input('search')) {
            $query->where('data1', 'LIKE', "%{$term}%");
        }

        if ($type = $request->input('type')) {
            $query->where('rowtype', $type);
        }

        $assessments = $query
            ->orderByDesc('moddate')
            ->forPage($request->integer('page', 1), 25)
            ->get();

        // Render the table rows partial
        $tableContent = $this->renderPartial('assessments.partials.table-rows', [
            'assessments' => $assessments,
        ]);

        return ApiResponse::success()
            ->replace('#assessments-table tbody', $tableContent)
            ->toResponse();
    }
}
