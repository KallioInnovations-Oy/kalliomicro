<?php
$isEdit = isset($assessment) && $assessment !== null;
$formAction = $isEdit ? "/app/assessments/{$assessment['id']}" : "/app/assessments";
$formMethod = $isEdit ? 'PUT' : 'POST';
?>

<div class="modal-header">
    <h5 class="modal-title">
        <?= $isEdit ? 'Edit Assessment' : 'New Assessment' ?>
    </h5>
    <button type="button" class="btn-close" data-action="close-modal"></button>
</div>

<form id="assessment-form" data-ajax="true" action="<?= $formAction ?>" method="POST">
    <?= $view->csrf() ?>
    <?php if ($isEdit): ?>
        <?= $view->method('PUT') ?>
    <?php endif; ?>

    <input type="hidden" name="form_id" id="form_id" value="<?= $view->e($assessment['id'] ?? '') ?>">

    <div class="modal-body">
        <!-- Assessment Type -->
        <div class="mb-3">
            <label for="rowtype" class="form-label">Assessment Type *</label>
            <select class="form-select" id="rowtype" name="rowtype" required <?= $isEdit ? 'disabled' : '' ?>>
                <option value="">Select type...</option>
                <option value="assessmentaccident" <?= $view->selected($assessment['rowtype'] ?? '', 'assessmentaccident') ?>>
                    Accident Report
                </option>
                <option value="assessmentinstallation" <?= $view->selected($assessment['rowtype'] ?? '', 'assessmentinstallation') ?>>
                    Installation Assessment
                </option>
                <option value="assessmentsiterisk" <?= $view->selected($assessment['rowtype'] ?? '', 'assessmentsiterisk') ?>>
                    Site Risk Assessment
                </option>
            </select>
        </div>

        <hr>
        <h6>Location Details</h6>

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="order_name" class="form-label">Site Name *</label>
                <input type="text"
                       class="form-control"
                       id="order_name"
                       name="order_name"
                       value="<?= $view->e($assessment['data1'] ?? '') ?>"
                       required>
            </div>
            <div class="col-md-6 mb-3">
                <label for="order_address" class="form-label">Address *</label>
                <input type="text"
                       class="form-control"
                       id="order_address"
                       name="order_address"
                       value="<?= $view->e($assessment['data2'] ?? '') ?>"
                       required>
            </div>
        </div>

        <hr>
        <h6>Reporter Information</h6>

        <div class="row">
            <div class="col-md-4 mb-3">
                <label for="reportername" class="form-label">Name *</label>
                <input type="text"
                       class="form-control"
                       id="reportername"
                       name="reportername"
                       value="<?= $view->e($assessment['data3'] ?? '') ?>"
                       required>
            </div>
            <div class="col-md-4 mb-3">
                <label for="reporterphone" class="form-label">Phone *</label>
                <input type="text"
                       class="form-control"
                       id="reporterphone"
                       name="reporterphone"
                       value="<?= $view->e($assessment['data4'] ?? '') ?>"
                       required>
            </div>
            <div class="col-md-4 mb-3">
                <label for="reporteremail" class="form-label">Email *</label>
                <input type="email"
                       class="form-control"
                       id="reporteremail"
                       name="reporteremail"
                       value="<?= $view->e($assessment['data5'] ?? '') ?>"
                       required>
            </div>
        </div>

        <hr>
        <h6>Details</h6>

        <div class="mb-3">
            <label for="description" class="form-label">Description</label>
            <textarea class="form-control"
                      id="description"
                      name="description"
                      rows="4"><?= $view->e($assessment['data6'] ?? '') ?></textarea>
        </div>

        <!-- Nested modal example - open client search -->
        <div class="mb-3">
            <label class="form-label">Search Existing Client</label>
            <button type="button"
                    class="btn btn-outline-secondary btn-sm"
                    data-action="modal"
                    data-url="/app/clients/search"
                    data-size="lg">
                Search Clients...
            </button>
            <small class="text-muted d-block mt-1">
                Opens a nested modal for searching clients
            </small>
        </div>
    </div>

    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-action="close-modal">
            Cancel
        </button>
        <button type="submit" class="btn btn-primary" data-action="submit" data-form="assessment-form">
            <?= $isEdit ? 'Save Changes' : 'Create Assessment' ?>
        </button>
    </div>
</form>
