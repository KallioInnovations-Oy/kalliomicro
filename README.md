# KallioMicro Framework

A modern, secure PHP 8+ MVC framework built with SOLID principles and minimal dependencies.

## Features

- **Security First**: CSRF protection, prepared statements, secure session handling
- **SOLID Principles**: Clean separation of concerns, dependency injection
- **Minimal Dependencies**: Only essential packages (Twig optional, dotenv, monolog)
- **Unified Response System**: Declarative actions, no eval()
- **Modern JavaScript Handler**: Single entry point for all AJAX interactions
- **Multi-Auth Support**: Local, Entra ID, LDAP, Google
- **Flexible Templating**: Native PHP or Twig integration
- **Query Builder**: Fluent database interface with automatic escaping
- **Unified Logging**: Database and file logging with PSR-3 support
- **Notifications**: Email (Symfony Mailer) and webhook support (Teams, Slack)
- **Error Handling**: Global exception handler with formatted output
- **CLI Console**: Task runner with cron scheduling

## Directory Structure

```
framework/
├── app/                    # Application code
│   └── Controllers/        # Your controllers
├── config/                 # Configuration files
├── public/                 # Web root
│   └── index.php          # Entry point
├── resources/
│   ├── views/             # Templates
│   └── assets/            # CSS, JS, images
├── routes/                # Route definitions
│   ├── web.php            # Web routes
│   └── api.php            # API routes
├── src/                   # Framework core
│   ├── Core/              # Application, Container, Config
│   ├── Http/              # Request, Response, Controller
│   ├── Routing/           # Router, Route
│   ├── Database/          # Connection, QueryBuilder
│   ├── Auth/              # Session, AuthManager, Providers
│   ├── Middleware/        # Middleware classes
│   ├── View/              # ViewEngine
│   └── Support/           # Helpers
└── tests/                 # Test files
```

## Quick Start

### 1. Installation

```bash
composer install
cp .env.example .env
# Edit .env with your database credentials
```

### 2. Define Routes

```php
// routes/web.php
$router->get('/', [HomeController::class, 'index']);
$router->resource('/assessments', AssessmentController::class);
```

### 3. Create Controller

```php
class AssessmentController extends Controller
{
    public function index(Request $request): Response
    {
        $assessments = $this->table('assessments')->get();

        return $this->render('assessments.index', [
            'assessments' => $assessments,
        ]);
    }

    public function store(Request $request): Response
    {
        $this->requireCsrf();

        $validation = $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email',
        ]);

        if (!$validation['valid']) {
            return ApiResponse::validationError('Fix errors', $validation['errors'])
                ->toResponse();
        }

        $id = $this->table('assessments')->insert([...]);

        return ApiResponse::success('Saved!')
            ->flash('Assessment created!', ApiResponse::CODE_SUCCESS)
            ->updateField('#form_id', $id)
            ->closeModal()
            ->refreshTable('#table')
            ->toResponse();
    }
}
```

## Unified Response System

All API responses follow this structure:

```json
{
    "success": true,
    "code": 1,
    "message": "Operation successful",
    "actions": [
        {"type": "flash", "level": 1, "message": "Saved!"},
        {"type": "replace", "target": "#container", "content": "<html>"},
        {"type": "close_modal"}
    ],
    "data": {...}
}
```

### Available Actions

| Action | Description |
|--------|-------------|
| `flash` | Show notification message |
| `replace` | Replace element content |
| `append` | Append to element |
| `prepend` | Prepend to element |
| `remove` | Remove element |
| `update_field` | Update form field value |
| `redirect` | Redirect to URL |
| `open_tab` | Open URL in new tab |
| `modal` | Show modal dialog |
| `nested_modal` | Show nested modal (2nd/3rd level) |
| `close_modal` | Close current modal |
| `close_all_modals` | Close all modals |
| `refresh_table` | Refresh DataTable |
| `clear_form` | Clear form fields |
| `toggle_visibility` | Show/hide element |
| `scroll_to` | Scroll to element |
| `focus` | Focus element |
| `download` | Trigger file download |
| `confirm` | Show confirmation before proceeding |

## JavaScript Handler

```html
<!-- Simple AJAX load -->
<button data-action="load" data-url="/api/data" data-target="#container">
    Load Data
</button>

<!-- Form submit -->
<button data-action="submit" data-form="myForm">
    Save
</button>

<!-- With confirmation -->
<button data-action="confirm"
        data-message="Are you sure?"
        data-url="/api/delete"
        data-method="DELETE">
    Delete
</button>

<!-- Open modal -->
<button data-action="modal" data-url="/forms/edit" data-size="lg">
    Edit
</button>
```

## Authentication

```php
// Login with local provider
$result = auth()->attempt([
    'username' => $username,
    'password' => $password,
]);

if ($result->isSuccess()) {
    return redirect('/dashboard');
}

// OAuth (Entra ID, Google)
$url = auth()->getAuthorizationUrl('entra');
return redirect($url);

// Handle callback
$result = auth()->handleOAuthCallback('entra', $request->queryAll());
```

## Query Builder

```php
// Select
$users = db('users')
    ->select(['id', 'name', 'email'])
    ->where('active', true)
    ->where('role', 'admin')
    ->orderBy('name')
    ->get();

// Insert
$id = db('users')->insert([
    'name' => 'John',
    'email' => 'john@example.com',
]);

// Update
db('users')
    ->where('id', $id)
    ->update(['name' => 'Jane']);

// Delete
db('users')
    ->where('id', $id)
    ->delete();
```

## Middleware

```php
// In routes
$router->group(['middleware' => [AuthMiddleware::class]], function ($router) {
    $router->get('/dashboard', [DashboardController::class, 'index']);
});

// Custom middleware
class LoggingMiddleware extends Middleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Before
        $start = microtime(true);

        $response = $next($request);

        // After
        $duration = microtime(true) - $start;
        error_log("Request took {$duration}s");

        return $response;
    }
}
```

## Status Codes

| Code | Meaning |
|------|---------|
| 0 | Bypass (info that should always show) |
| 1 | Success |
| 2 | Info |
| 3 | Warning |
| 4 | Error |

## Logging

```php
// Get logger instance
$logger = new Logger($db, '/path/to/logs/app.log');

// PSR-3 methods
$logger->info('User logged in', ['user_id' => 123]);
$logger->warning('Rate limit approaching');
$logger->error('Database connection failed');

// KallioMicro-specific methods
$logger->bypass('Debug info');    // Level 0 - always shows
$logger->success('Task complete'); // Level 1

// Channel support
$logger->channel('auth')->info('Login attempt');
```

## Notifications

```php
$communicator = new Communicator($logger);

// Send email
$result = $communicator->sendEmail([
    'to' => 'user@example.com',
    'subject' => 'Welcome!',
    'body' => '<h1>Hello</h1><p>Welcome to KallioMicro.</p>',
    'attachments' => ['/path/to/file.pdf'],
]);

// Teams notification
$communicator->sendTeamsNotification(
    'Alert: Server Issue',
    'Database connection pool exhausted.',
    null,        // Use default webhook
    'FF0000'     // Red theme color
);

// Slack notification
$communicator->sendSlackNotification(
    'Deployment complete :rocket:',
    '#deployments'
);
```

## Console Commands

Run tasks from the command line:

```bash
# List all commands
php console list

# Show help for a command
php console help task:backup

# Run scheduled tasks (call from cron)
php console schedule:run

# Run a specific task
php console task:backup --type=daily
```

### Creating Commands

```php
// app/Console/Commands/ImportCommand.php
class ImportCommand extends TaskCommand
{
    protected string $name = 'task:import';
    protected string $description = 'Import data from external source';
    protected string $schedule = '0 */6 * * *'; // Every 6 hours

    public function handle(Input $input): int
    {
        $this->logStart();

        $source = $input->getArgument(0, 'default');
        $this->info("Importing from: {$source}");

        // Your import logic here...

        $this->logComplete('Imported 150 records');
        return 0;
    }
}
```

### Registering Commands

```php
// In console entry point
$console->registerClass(App\Console\Commands\ImportCommand::class);
$console->schedule('task:import', '0 */6 * * *');
```

### Cron Setup

Add to crontab for scheduled task execution:

```
* * * * * cd /path/to/app && php console schedule:run >> /dev/null 2>&1
```

## Error Handling

The framework provides unified exception handling:

```php
// Register global handler
$handler = new ExceptionHandler($logger, $communicator);
$handler->setDebug(env('APP_DEBUG', false));
$handler->setNotifyOnCritical(true);
$handler->register();

// Throw HTTP exceptions
throw HttpException::notFound('User not found');
throw HttpException::forbidden('Access denied');
throw HttpException::validationError('Invalid email format');
```

In debug mode, exceptions show detailed stack traces. In production, users see friendly error pages while details are logged and optionally sent via notification.

## License

Proprietary - Mesvac Oy
