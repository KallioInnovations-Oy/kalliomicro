# KallioMicro — Framework Overview

> Sources: `src/Core/`, `src/Support/`, `public/index.php`, `console`, `config/`, `composer.json`.

KallioMicro is a minimal, secure PHP 8.1+ MVC framework (`Application::VERSION = '1.1.0'`; behavior changes are tracked in the root [CHANGELOG.md](../CHANGELOG.md)). One production dependency: `phpmailer/phpmailer` (PHPUnit ships as require-dev only — `composer test`). Everything else — DI container, router, query builder, auth, view engine, console — is implemented in `src/`.

## Design philosophy

1. **Framework / application split.** `src/` (namespace `KallioMicro\`) is framework code; `app/` (namespace `App\`) is application code — here it holds example scaffolding (demo controllers, commands) showing downstream projects the intended patterns. Framework code never imports `App\*`.
2. **No magic.** No ORM, no annotations, no auto-discovery. Rows are associative arrays; services and console commands are registered explicitly.
3. **Security by default.** Native prepared statements with identifier allowlisting, CSRF at two layers, escaping helpers, session-fixation defense, timing-safe login.
4. **Complexity budget.** Every abstraction must name the concrete requirement it serves today (see [conventions.md](conventions.md)).

## Module map and dependency rules

| Module | Depends on | Contents |
|---|---|---|
| `src/Core/` | nothing | `Application`, `Container`, `Config` |
| `src/Database/` | standalone | `Connection`, `QueryBuilder` (+ `RawExpression`) |
| `src/View/` | standalone | `ViewEngine` |
| `src/Http/` | Core, Database, View, Auth | `Request`, `Response`, `ApiResponse`, `Controller`, `HttpException` |
| `src/Routing/` | Core, Http | `Router`, `Route` |
| `src/Auth/` | Core, Database | `AuthManager` (+ `AuthResult`, provider interfaces), `Providers/`, `Session` |
| `src/Middleware/` | Http, Auth | `MiddlewareInterface`, abstract `Middleware`, `Auth`/`Guest`/`Role`/`Profile`/`Csrf` middleware (one class per file — required for PSR-4 class-string resolution) |
| `src/Console/` | Core, Support | `Console` kernel, `Command`, `Input`, built-in commands |
| `src/Support/` | Core | `helpers.php`, `DotEnv`, `Logger`, `ExceptionHandler`, `Communicator` |

Detailed docs: [routing-and-middleware.md](routing-and-middleware.md) · [database.md](database.md) · [auth.md](auth.md) · [api-response.md](api-response.md) · [views.md](views.md) · [validation.md](validation.md) · [console.md](console.md) · [conventions.md](conventions.md)

---

## The DI container

`KallioMicro\Core\Container` — a small PSR-11-shaped container (`has()`/`get()` without the interface package) with reflection autowiring.

```php
public function bind(string $abstract, Closure|string|null $concrete = null): void
public function singleton(string $abstract, Closure|string|null $concrete = null): void
public function instance(string $abstract, object $instance): object
public function alias(string $abstract, string $alias): void
public function has(string $id): bool
public function get(string $id): mixed          // = make($id)
public function make(string $abstract, array $parameters = []): mixed
public function call(callable|array $callback, array $parameters = []): mixed  // method injection
public function flush(): void
```

Resolution rules:

- Closure bindings are invoked as `$concrete($this, $parameters)`; string bindings and unbound classes are reflection-built. Constructor params resolve in order: explicit `$parameters` by name → typed class dependency (recursive `make()`) → default value → `null` for nullable → `RuntimeException`.
- **Unbound, non-singleton classes are built fresh on every `make()`** — only `singleton()` bindings (and `instance()`) are cached.
- `has()` returns `false` for classes that are merely autowirable but unbound.

`KallioMicro\Core\Application` extends the container and adds: path helpers (`basePath`, `configPath`, `publicPath`, `resourcePath`, `storagePath`), a static instance accessor (used by the `app()` helper), and the HTTP kernel (`handle()`, `run()`, global middleware).

The `Application` constructor self-registers five core singletons with string aliases: `Config` (`'config'`), `Router` (`'router'`), `Request` (`'request'`, via `Request::capture()`), `ViewEngine` (`'view'`), `Session` (`'session'`).

`registerDatabase(string $name, array $config)` binds `"db.{name}"` connections from `config/database.php`; the connection named `default` — or the first one registered — is aliased to `Connection::class` and `'db'`.

---

## Configuration and environment

Two layers, with a hard rule between them:

1. **`.env` → `env()`** — `KallioMicro\Support\DotEnv` parses `.env` at the repo root (`safeLoad()` — a missing file is fine; `load()` throws) into `$_ENV`/`putenv` without overwriting existing values. Values are stored as **strings**; type coercion (`'true'` → `true`, `'false'` → `false`, `'null'` → `null`, `'empty'` → `''`, parenthesized variants too) happens in the `env()` *helper*, not the loader. `required([...])` throws when listed keys are absent.

   Quoted values may carry an inline comment (`KEY="value"  # note`): the parser locates the closing quote rather than checking whether the line ends with one, so a value is multiline only when its quote genuinely does not close on that line. An **unterminated quote raises** — previously it absorbed every following line and discarded the rest of the file with no error, which silently booted the app in debug mode against whatever database `config/` defaulted to. There is no variable interpolation: `KEY="${OTHER}"` stores the literal `${OTHER}`.
2. **`config/*.php` → `config()`** — `KallioMicro\Core\Config` eagerly `require`s every `config/*.php`; the file basename is the top-level key and dot notation reads into it (`config('app.debug')`). Also implements `ArrayAccess`.

**Rule: `env()` may only be called inside `config/*.php`** (exception: entry-script crash handlers, where config may not be loadable). Application code reads `config()`.

Config files shipped: `app` (name, env, debug, url, timezone `Europe/Helsinki`, locale), `auth` (default provider + `local`/`entra`/`ldap`/`google` provider config), `database` (default + connections), `session` (cookie, lifetime, secure, http_only, same_site, domain, regenerate_interval). Add new config surfaces as new files in `config/`.

---

## Entry points

There are exactly two, and each wires its own services — **there is no shared bootstrap file** in the base framework. Downstream projects that grow CLI service needs should extract shared bindings into a file both entry points `require`.

### Web — `public/index.php`

```
require vendor/autoload.php
DotEnv::create(__DIR__ . '/..')->safeLoad()
require src/Support/helpers.php
$app = new Application(dirname(__DIR__))
registerDatabase() per config('database.connections')
$app->singleton(AuthManager::class, ...)          ← service registrations live here
$app->middleware(fn ($req, $next) => ...)          ← global middleware: session start
require routes/web.php + routes/api.php
$app->run()
```

`run()` captures the `Request`, runs `handle()` (boot → global middleware pipeline, outermost-first in registration order → `Router::dispatch()`), sends the `Response`, then `terminate()` (no-op hook). A `Throwable` inside `handle()` is rendered by `ExceptionHandler` (see below) at the status `getHttpCode()` maps it to — not a blanket 500, so `abort()` and `HttpException` surface correctly — as JSON for `expectsJson()` requests and HTML otherwise. Rendering happens **at the destination**, so the error response travels back out through the global middleware stack like any other.

### CLI — `console`

Guards `PHP_SAPI === 'cli'`, defines `KALLIOMICRO_BASE_PATH`, locates the composer autoloader, loads `.env` + helpers, creates the `Application`, a file-only `Logger` (`storage/logs/console.log`), and an `ExceptionHandler` (debug from `config('app.debug')`), then registers commands and scheduled tasks explicitly before `$console->run($argv)`. Exit code = the command's return value. See [console.md](console.md).

### Error handling — `KallioMicro\Support\ExceptionHandler`

Registers exception/error/shutdown handlers. Rendering: CLI → colored STDERR (exit 1); JSON-wanting requests → JSON (debug adds exception/file/line/trace); web → full debug page in debug mode, generic error page otherwise. Paths listed via `setHiddenPaths()` are redacted in traces. Critical errors (`Error`, `PDOException`, `RuntimeException`) can notify Teams via `Communicator` when `notifyOnCritical` is enabled.

Status-code mapping (`getHttpCode`, public): `HttpException` → its status code; any exception whose `code` is an int in 400–599 → that status (`throw new RuntimeException('...', 403)` renders as 403); `InvalidArgumentException` → 400; everything else → 500. `HttpException` remains the preferred way to abort with a specific status.

**It is the only error renderer.** `Application::handleException()` resolves `ExceptionHandler` from the container and delegates, so a web request and the registered global handler cannot disagree about escaping, trace contents, or what a production response is allowed to say. Bind your own instance (custom renderer, hidden paths, a logger) before the first request to replace the default; `Application::boot()` only registers one if the binding is unclaimed.

**Survivability**, because an error page that crashes is worse than useless: the error handler is registered with a level mask, so a warning raised *inside* it — the classic being `file_put_contents` failing when the log directory is unwritable — no longer becomes an `ErrorException` that escapes and destroys the original error. A re-entrancy guard stops `handleShutdown()` re-entering on a fatal the handler itself produced (which printed every crash twice), logging and notification failures are swallowed rather than replacing the exception being reported, and headers are only emitted when `headers_sent()` is false, so an exception raised mid-render appends to the partial output instead of cascading.

---

## Support helpers (`src/Support/helpers.php`)

Global functions (each guarded by `function_exists`):

| Helper | Behavior |
|---|---|
| `env($key, $default)` | `$_ENV`/`getenv` with true/false/null/empty coercion — **config files only** |
| `app($abstract = null)` | Application instance, or `make($abstract)` |
| `config($key, $default)` | Dot-notation config read |
| `view($template, $data)` | Render a template to string |
| `request()` / `session($key)` / `auth()` | Core service accessors |
| `db($table = null)` | `Connection`, or `QueryBuilder` for `$table` |
| `response()` | New `ApiResponse` |
| `redirect($url, $status = 302)` | Redirect `Response` |
| `csrf_token()` / `csrf_field()` | Session CSRF token / hidden input markup |
| `e(?string $value)` | `htmlspecialchars` (ENT_QUOTES \| ENT_HTML5, UTF-8), null-safe |
| `url($name, $params)` | URL for a named route (see [routing-and-middleware.md](routing-and-middleware.md)) |
| `asset($path)` | `{app.url}/assets/{path}` |
| `dd(...)` / `dump(...)` | Debug output |

Other Support classes:

- **`Logger`** — DB (`core_logs`: origdate, user_id, rowtype, logsource, logsourceid, eventtype, eventdescription, context) or file (`storage/logs/app.log`); KallioMicro levels `0=BYPASS, 1=SUCCESS, 2=INFO, 3=WARNING, 4=ERROR` plus PSR-3 method names mapped onto them; `{key}` context interpolation; per-channel clones via `channel()`; DB failure falls back to file. The DDL for `core_logs` is in [`database/schema.sql`](../database/schema.sql) — the column list is a contract, and a mismatch makes every insert fail 42S22 and fall back to file. CR/LF in the message, source and source id are escaped so one call can never write more than one line (log-injection defence), and the special context keys `user_id`/`source_id`/`source` are coerced rather than trusted — a wrong-typed value used to raise a `TypeError` out of the logger and kill its caller.
- **`Communicator`** — SMTP email via PHPMailer (`sendEmail`), Microsoft Teams (`sendTeamsNotification`), Slack (`sendSlackNotification`), generic webhooks (`sendWebhook`; TLS verification on). Returns `CommunicatorResult` (`isSuccess()`/`isFailure()`). Config from `config/notifications.php` (`notifications.email` / `notifications.webhooks`, populated from `MAIL_*` / `WEBHOOK_*` env); constructor arguments merge *over* that, so a partial array overrides only the keys it names.
- **`DotEnv`** — see above.

---

## Directory contract

```
public/index.php        web entry — the only public PHP file; service wiring lives here
console                 CLI entry — command + schedule registration lives here
config/*.php            the only place env() is read
routes/web.php          session-auth HTML routes
routes/api.php          /api routes (JSON)
src/                    framework (KallioMicro\)
app/                    application (App\): Controllers/, Console/Commands/ — example scaffolding
resources/views/        native PHP templates (dot notation → path)
public/assets/          static assets served as /assets/* (kalliomicro.js client)
storage/                logs, cache
```
