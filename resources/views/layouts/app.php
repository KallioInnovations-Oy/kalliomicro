<!DOCTYPE html>
<html lang="<?= $view->e(config('app.locale', 'en')) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= $view->e($csrf_token) ?>">
    <title><?= $view->e($title ?? config('app.name')) ?></title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">

    <!-- Custom styles -->
    <style>
        .flash-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 9999;
            max-width: 400px;
        }
        .flash-container .alert {
            margin-bottom: 10px;
        }
        .is-loading {
            opacity: 0.6;
            pointer-events: none;
        }
        .modal.km-modal {
            z-index: 1050;
        }
        .modal.km-modal[data-level="2"] { z-index: 1060; }
        .modal.km-modal[data-level="3"] { z-index: 1070; }
        .km-backdrop[data-level="2"] { z-index: 1055; }
        .km-backdrop[data-level="3"] { z-index: 1065; }
    </style>

    <?= $view->yield('styles') ?>
</head>
<body>
    <!-- Flash messages container -->
    <div id="flash-messages" class="flash-container"></div>

    <!-- Modal container -->
    <div id="modal-container"></div>

    <!-- Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark bg-dark">
        <div class="container">
            <a class="navbar-brand" href="/"><?= $view->e(config('app.name')) ?></a>
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            <div class="collapse navbar-collapse" id="navbarNav">
                <ul class="navbar-nav me-auto">
                    <?php if ($view->isAuth()): ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/app/dashboard">Dashboard</a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="/app/assessments">Assessments</a>
                        </li>
                    <?php endif; ?>
                </ul>
                <ul class="navbar-nav">
                    <?php if ($view->isAuth()): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">
                                <?= $view->e($user['firstname'] ?? $user['username'] ?? 'User') ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li><a class="dropdown-item" href="/app/settings">Settings</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="/logout">Logout</a></li>
                            </ul>
                        </li>
                    <?php else: ?>
                        <li class="nav-item">
                            <a class="nav-link" href="/login">Login</a>
                        </li>
                    <?php endif; ?>
                </ul>
            </div>
        </div>
    </nav>

    <!-- Main content -->
    <main class="container py-4">
        <?php /* Section-based pages capture their body into the 'content' section;
                 $content (the child's direct output) is only the fallback for
                 templates that emit output without sections. Bare $content here
                 would render section-based pages EMPTY. */ ?>
        <?= $view->yield('content', $content ?? '') ?>
    </main>

    <!-- Footer -->
    <footer class="bg-light py-3 mt-auto">
        <div class="container text-center text-muted">
            &copy; <?= date('Y') ?> <?= $view->e(config('app.name')) ?>
        </div>
    </footer>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

    <!-- KallioMicro Framework JS -->
    <script src="/assets/js/kalliomicro.js"></script>

    <?= $view->yield('scripts') ?>
</body>
</html>
