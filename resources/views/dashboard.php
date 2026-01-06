<?php $view->extends('layouts.app'); ?>

<?php $view->section('content'); ?>
<div class="container">
    <h1>Dashboard</h1>

    <?php if ($user): ?>
        <div class="card">
            <h3>Welcome, <?= $view->e($user['username'] ?? $user['name'] ?? 'User') ?>!</h3>
            <p>You are logged in.</p>
            <a href="/logout" class="btn btn-secondary">Logout</a>
        </div>
    <?php else: ?>
        <div class="alert alert-warning">
            <p>You need to be logged in to view this page.</p>
            <a href="/login" class="btn btn-primary">Login</a>
        </div>
    <?php endif; ?>

    <div class="stats">
        <div class="stat-card">
            <h4>Assessments</h4>
            <p class="stat-number">0</p>
            <a href="/app/assessments">View All</a>
        </div>
    </div>
</div>
<?php $view->endSection(); ?>
