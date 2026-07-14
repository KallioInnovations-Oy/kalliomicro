<?php /** Read-only assessment detail — modal content, also embedded by show.php. */ ?>
<div class="modal-header">
    <h5 class="modal-title">
        Assessment #<?= $view->e($assessment['id']) ?>
        <span class="badge bg-secondary ms-2">
            <?= $view->e(str_replace('assessment', '', $assessment['rowtype'])) ?>
        </span>
    </h5>
    <button type="button" class="btn-close" data-action="close-modal" aria-label="Close"></button>
</div>

<div class="modal-body">
    <dl class="row mb-0">
        <dt class="col-sm-4">Order name</dt>
        <dd class="col-sm-8"><?= $view->e($assessment['data1']) ?></dd>

        <dt class="col-sm-4">Order address</dt>
        <dd class="col-sm-8"><?= $view->e($assessment['data2']) ?></dd>

        <dt class="col-sm-4">Reporter</dt>
        <dd class="col-sm-8"><?= $view->e($assessment['data3']) ?></dd>

        <dt class="col-sm-4">Phone</dt>
        <dd class="col-sm-8"><?= $view->e($assessment['data4']) ?></dd>

        <dt class="col-sm-4">Email</dt>
        <dd class="col-sm-8"><?= $view->e($assessment['data5']) ?></dd>

        <dt class="col-sm-4">Description</dt>
        <dd class="col-sm-8"><?= nl2br($view->e($assessment['data6'])) ?></dd>

        <dt class="col-sm-4">Created</dt>
        <dd class="col-sm-8"><?= $view->datetime($assessment['origdate']) ?></dd>

        <dt class="col-sm-4">Modified</dt>
        <dd class="col-sm-8"><?= $view->datetime($assessment['moddate']) ?></dd>
    </dl>
</div>

<div class="modal-footer">
    <button type="button" class="btn btn-secondary" data-action="close-modal">Close</button>
    <button type="button"
            class="btn btn-primary"
            data-action="modal"
            data-url="/app/assessments/<?= $view->e($assessment['id']) ?>/edit"
            data-size="lg">
        Edit
    </button>
</div>
