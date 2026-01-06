<?php $view->extends('layouts.app'); ?>

<?php $view->section('content'); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Assessments</h1>
    <button type="button" class="btn btn-primary"
            data-action="modal"
            data-url="/app/assessments/create"
            data-size="lg">
        + New Assessment
    </button>
</div>

<!-- Search/Filter Form -->
<div class="card mb-4">
    <div class="card-body">
        <form id="search-form" data-ajax="true" action="/app/assessments/search" method="POST">
            <?= $view->csrf() ?>
            <div class="row g-3">
                <div class="col-md-4">
                    <input type="text"
                           class="form-control"
                           name="search"
                           placeholder="Search..."
                           data-auto-submit="true">
                </div>
                <div class="col-md-3">
                    <select class="form-select" name="type" data-auto-submit="true">
                        <option value="">All Types</option>
                        <option value="assessmentaccident">Accidents</option>
                        <option value="assessmentinstallation">Installations</option>
                        <option value="assessmentsiterisk">Site Risk</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-outline-secondary">
                        Search
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Assessments Table -->
<div class="card">
    <div class="table-responsive">
        <table class="table table-hover mb-0" id="assessments-table">
            <thead class="table-light">
                <tr>
                    <th>ID</th>
                    <th>Type</th>
                    <th>Location</th>
                    <th>Created</th>
                    <th>Modified</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($assessments as $assessment): ?>
                <tr id="assessment-row-<?= $view->e($assessment['id']) ?>">
                    <td><?= $view->e($assessment['id']) ?></td>
                    <td>
                        <span class="badge bg-secondary">
                            <?= $view->e(str_replace('assessment', '', $assessment['rowtype'])) ?>
                        </span>
                    </td>
                    <td><?= $view->e($assessment['data1']) ?></td>
                    <td><?= $view->datetime($assessment['origdate']) ?></td>
                    <td><?= $view->datetime($assessment['moddate']) ?></td>
                    <td>
                        <div class="btn-group btn-group-sm">
                            <!-- View button - opens in modal -->
                            <button type="button"
                                    class="btn btn-outline-primary"
                                    data-action="modal"
                                    data-url="/app/assessments/<?= $view->e($assessment['id']) ?>"
                                    data-size="lg">
                                View
                            </button>

                            <!-- Edit button - opens in modal -->
                            <button type="button"
                                    class="btn btn-outline-secondary"
                                    data-action="modal"
                                    data-url="/app/assessments/<?= $view->e($assessment['id']) ?>/edit"
                                    data-size="lg">
                                Edit
                            </button>

                            <!-- Delete button - with confirmation -->
                            <button type="button"
                                    class="btn btn-outline-danger"
                                    data-action="confirm"
                                    data-message="Are you sure you want to delete this assessment?"
                                    data-url="/app/assessments/<?= $view->e($assessment['id']) ?>"
                                    data-method="DELETE">
                                Delete
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>

                <?php if (empty($assessments)): ?>
                <tr>
                    <td colspan="6" class="text-center text-muted py-4">
                        No assessments found.
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php $view->endSection(); ?>
