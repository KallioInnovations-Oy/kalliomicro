<?php $view->extends('layouts.app'); ?>

<?php $view->section('content'); ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Edit Assessment #<?= $view->e($assessment['id']) ?></h1>
    <a href="/app/assessments" class="btn btn-outline-secondary">Back to list</a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-8">
        <div class="card">
            <?= $view->partial('assessments.form', ['assessment' => $assessment]) ?>
        </div>
    </div>
</div>
<?php $view->endSection(); ?>
