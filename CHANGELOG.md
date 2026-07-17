# Changelog

All notable changes to the KallioMicro base framework. Downstream projects carry
a copy of this framework — before reporting a framework bug, compare your copy's
`Application::version()` against this file; the issue may already be fixed in a
newer base.

Format follows [Keep a Changelog](https://keepachangelog.com/).

## [1.1.0] – 2026-07-17

### Added

- **Class-string route middleware** — `Route::middleware()`, route-group
  `middleware`, and `Application::middleware()` accept a class-string of a
  `MiddlewareInterface` implementation; resolved through the container at
  dispatch time (constructor dependencies auto-wire). Parameterized middleware
  still use the closure form.
- **`QueryBuilder::paginate(int $page, int $perPage)`** — returns
  `{data, total, per_page, current_page, last_page, from, to}`; counts on a
  clone with ORDER BY/LIMIT stripped. Throws on `groupBy()` (COUNT would
  collapse the groups).
- **Scheduler overlap lock** — `schedule:run` holds a per-task non-blocking
  flock (`storage/framework/schedule-*.lock`) while a task executes; a task
  still running from a previous tick is skipped (reported, not failed).
  Host-local: multi-host schedulers still need a distributed lock in the task.
- **Per-field validation error rendering** — `kalliomicro.js` now reads
  `data.validation_errors` from `ApiResponse::validationError()` and renders
  per-field `is-invalid` + `.invalid-feedback`, cleared on next submit.
- **Self-describing boundary errors** — unknown validation rules and unknown
  `QueryBuilder` methods now throw messages that list the shipped surface and
  point at the docs (including hints for common Laravel methods that are
  intentionally not shipped).
- **`app.trusted_proxies` config** — see Changed: `Request::ip()`.
- **`app.fallback_locale` config key** (was read by ViewEngine but undefined).
- **PHPUnit smoke-test suite** (`composer test`, require-dev only; runtime
  dependencies unchanged) covering QueryBuilder SQL/guards/pagination,
  validation rules, URL sanitizer, CSRF token precedence, router dispatch and
  middleware resolution, cron matching, and trusted-proxy `ip()`.

### Changed

- **`Request::ip()` no longer trusts `X-Forwarded-For` by default.** XFF is
  honored only when `REMOTE_ADDR` is listed in `app.trusted_proxies`, and the
  client is taken as the rightmost non-trusted entry (first-entry was
  client-forgeable). Proxy awareness covers `ip()` only — `isSecure()`/`url()`
  do not consult `X-Forwarded-Proto`/`X-Forwarded-Host` (scope note in docs).
- **`Controller::back()` is same-origin only** — a cross-origin Referer falls
  back to `/`; same-origin referers are reduced to path + query. Handles
  missing `Host` headers and bracketed IPv6 hosts.
- **`Console::schedule()` keeps every entry** — scheduling the same command
  twice no longer silently overwrites the first schedule; both run when due.
- **`Session::sanitizeRelativeUrl()` is `public static`** — reused by
  `Controller::back()` and directly testable.
- **One middleware class per file** — `GuestMiddleware`, `RoleMiddleware`,
  and `ProfileMiddleware` moved out of `AuthMiddleware.php`, and
  `MiddlewareInterface` out of `Middleware.php`, into their own files so
  PSR-4 can autoload them (required for class-string middleware). Class
  names and namespaces are unchanged.
- **Middleware class-strings resolve lazily** — a middleware behind one that
  short-circuits is never constructed; route and global middleware share one
  resolver (`Application::resolveMiddleware()`).
- **`paginate()` no longer mutates the builder** — the data query runs on a
  clone, so the builder stays reusable after paginating; a page past the end
  skips the data query entirely; `distinct()` now throws like `groupBy()`
  (the count would include duplicates).

### Fixed

- **Cron `a-b/n` step expressions** (e.g. `10-20/5`) were misread as the plain
  range `a-b`, ignoring the step — the range branch shadowed the step branch.
- **Cron zero/malformed steps** (`*/0`) crashed the whole `schedule:run` tick
  with a `DivisionByZeroError`; malformed fields are now simply never due.
- **A lock-file permission failure on one scheduled task** aborted every
  remaining due task; it now fails only that task and the tick continues.
- **`Connection::update()`/`delete()` with an empty `$where`** produced
  malformed SQL; both now refuse to run without conditions (matching the
  QueryBuilder guard).
- **Per-field validation rendering never touches unrelated forms** — without
  a resolvable source form nothing is rendered (previously fell back to
  document scope), and a template-authored `.invalid-feedback` overwritten by
  a server message is restored to its original text on the next submit.
- **Zero-arg closure route handlers fataled** with "Unknown named parameter
  $request" — `Container::call()` now resolves closure parameters by name via
  reflection, same as controller methods, passing only what is declared. The
  shipped `/api/health` route was broken by this.
- **Exception status codes were flattened to 500** on web requests —
  `Application::handleException()` now maps through
  `ExceptionHandler::getHttpCode()` (now static), so `requireCsrf()` renders
  403, `HttpException::notFound()` renders 404 (with its headers), and
  4xx messages are shown to the client instead of "Internal Server Error".
- **Demo app:** `AuthController::redirect()` fatally collided with the base
  `Controller::redirect()` helper (renamed to `redirectToProvider()`); the
  demo login now calls `requireCsrf()` per the security checklist; `/api/health`
  reports `Application::version()` instead of a hardcoded string.

## [1.0.0]

Baseline: service container with auto-wiring, HTTP router (groups, named
routes, param constraints), fluent QueryBuilder with binding + identifier
validation, controller-level validation, multi-provider auth (local, Entra ID,
LDAP, Google) with secure sessions and CSRF, native PHP view engine
(layouts/sections/i18n helpers), `ApiResponse` action system with the
`kalliomicro.js` client, console with cron-style scheduler, logging and
notifications. Single runtime dependency (PHPMailer).
