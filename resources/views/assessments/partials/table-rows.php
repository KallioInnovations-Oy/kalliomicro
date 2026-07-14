<?php /** Table body rows — shared by the index page and the AJAX search endpoint. */ ?>
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
