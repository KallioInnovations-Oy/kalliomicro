<?php $view->extends('layouts.app'); ?>

<?php $view->section('content'); ?>
<div class="container">
    <div class="hero">
        <h1>KallioMicro Framework</h1>
        <p class="lead">A modern, secure PHP 8+ MVC framework built with SOLID principles and minimal dependencies.</p>

        <div class="features">
            <div class="feature">
                <h3>Security First</h3>
                <p>CSRF protection, prepared statements, secure session handling</p>
            </div>
            <div class="feature">
                <h3>SOLID Principles</h3>
                <p>Clean separation of concerns, dependency injection</p>
            </div>
            <div class="feature">
                <h3>Minimal Dependencies</h3>
                <p>Only essential packages, lightweight and fast</p>
            </div>
            <div class="feature">
                <h3>Unified Response System</h3>
                <p>Declarative actions, no eval(), type-safe frontend integration</p>
            </div>
        </div>

        <div class="actions">
            <a href="/login" class="btn btn-primary">Login</a>
            <a href="/api/health" class="btn btn-secondary">API Health Check</a>
        </div>
    </div>
</div>
<?php $view->endSection(); ?>
