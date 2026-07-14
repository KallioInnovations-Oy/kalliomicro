# KallioMicro — Framework Overview

> Sources: `src/Core/`, `src/Support/`, `public/index.php`, `console`, `config/`, `composer.json`.

KallioMicro is a minimal, secure PHP 8.1+ MVC framework (`Application::VERSION = '1.0.0'`). One production dependency: `phpmailer/phpmailer`. Everything else — DI container, router, query builder, auth, view engine, console — is implemented in `src/`.

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
| `src/Http/` | Core, Database, View, Auth | `Request`, `Response`, `ApiResponse`, `Controller`, `HttpException`, `MiddlewareInterface` |
| `src/Routing/` | Core, Http | `Router`, `Route` |
| `src/Auth/` | Core, Database | `AuthManager` (+ `AuthResult`, provider interfaces), `Providers/`, `Session` |
| `src/Middleware/` | Http, Auth | `Auth`/`Guest`/`Role`/`Profile`/`Csrf` middleware |
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

`run()` captures the `Request`, runs `handle()` (boot → global middleware pipeline, outermost-first in registration order → `Router::dispatch()`), sends the `Response`, then `terminate()` (no-op hook). Any `Throwable` inside `handle()` renders a 500 — JSON (`{error, message[, trace]}`) for `expectsJson()` requests, HTML otherwise; message and trace are exposed only when `config('app.debug')` is true.

### CLI — `console`

Guards `PHP_SAPI === 'cli'`, defines `KALLIOMICRO_BASE_PATH`, locates the composer autoloader, loads `.env` + helpers, creates the `Application`, a file-only `Logger` (`storage/logs/console.log`), and an `ExceptionHandler` (debug from `config('app.debug')`), then registers commands and scheduled tasks explicitly before `$console->run($argv)`. Exit code = the command's return value. See [console.md](console.md).

### Error handling — `KallioMicro\Support\ExceptionHandler`

Registers exception/error/shutdown handlers. Rendering: CLI → colored STDERR (exit 1); JSON-wanting requests → JSON (debug adds exception/file/line/trace); web → full debug page in debug mode, generic error page otherwise. Paths listed via `setHiddenPaths()` are redacted in traces. Critical errors (`Error`, `PDOException`, `RuntimeException`) can notify Teams via `Communicator` when `notifyOnCritical` is enabled.

Status-code mapping (`getHttpCode`): `HttpException` → its status code; `InvalidArgumentException` → 400; everything else → 500.

> ⚠ **Known limitation (as of 2026-07-14):** the handler does *not* honor a generic exception's `code` property — `throw new RuntimeException('...', 403)` (as `Controller::requireCsrf()` does) renders as **500**, not 403. Throw `HttpException::forbidden()` when the status matters, or rely on `CsrfMiddleware` (which returns a proper 403 response).

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

- **`Logger`** — DB (`core_logs`: origdate, user_id, rowtype, logsource, logsourceid, eventtype, eventdescription) or file (`storage/logs/app.log`); KallioMicro levels `0=BYPASS, 1=SUCCESS, 2=INFO, 3=WARNING, 4=ERROR` plus PSR-3 method names mapped onto them; `{key}` context interpolation; per-channel clones via `channel()`; DB failure falls back to file.
- **`Communicator`** — SMTP email via PHPMailer (`sendEmail`), Microsoft Teams (`sendTeamsNotification`), Slack (`sendSlackNotification`), generic webhooks (`sendWebhook`; TLS verification on). Returns `CommunicatorResult` (`isSuccess()`/`isFailure()`). Config from `MAIL_*` / `WEBHOOK_*` env by default.
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
