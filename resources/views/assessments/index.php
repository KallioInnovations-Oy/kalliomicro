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
                <?php /* Rows live in a partial so the AJAX search endpoint renders
                         the exact same markup — the two can never drift apart. */ ?>
                <?= $view->partial('assessments.partials.table-rows', ['assessments' => $assessments]) ?>
            </tbody>
        </table>
    </div>
</div>
<?php $view->endSection(); ?>
