<?php $view->extends('layouts.app'); ?>

<?php $view->section('content'); ?>
<div class="container">
    <div class="login-form">
        <h2>Login</h2>

        <?php if (!empty($error)): ?>
            <div class="alert alert-error"><?= $view->e($error) ?></div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <ul>
                <?php foreach ($errors as $field => $fieldErrors): ?>
                    <?php foreach ($fieldErrors as $err): ?>
                        <li><?= $view->e($err) ?></li>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <form method="POST" action="/login">
            <?= $view->csrf() ?>

            <div class="form-group">
                <label for="username">Username</label>
                <input type="text" id="username" name="username" required autofocus
                       class="form-control" placeholder="Enter your username">
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required
                       class="form-control" placeholder="Enter your password">
            </div>

            <button type="submit" class="btn btn-primary btn-block">Login</button>
        </form>

        <div class="oauth-providers">
            <p>Or login with:</p>
            <a href="/auth/entra" class="btn btn-oauth">Microsoft Entra ID</a>
            <a href="/auth/google" class="btn btn-oauth">Google</a>
        </div>
    </div>
</div>
<?php $view->endSection(); ?>
