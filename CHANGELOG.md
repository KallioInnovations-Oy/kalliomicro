# Changelog

All notable changes to the KallioMicro base framework. Downstream projects carry
a copy of this framework — before reporting a framework bug, compare your copy's
`Application::version()` against this file; the issue may already be fixed in a
newer base.

Format follows [Keep a Changelog](https://keepachangelog.com/).

## [1.2.5] – 2026-07-20

### Fixed

- **`Response::download()` / `Response::file()` interpolated the caller's
  filename verbatim into the quoted `filename=` parameter of
  `Content-Disposition`.** A `"` in the name broke the quoted-string and left
  browser behaviour undefined, and non-ASCII names were shipped as raw bytes
  instead of the RFC 6266 `filename*` parameter. Reported from a downstream
  port (MESOv4).

  The parameter is now built safely: `filename=` carries an ASCII fallback
  (`"`, `\`, `%`, control and non-ASCII characters each replaced with `_`),
  and when anything was replaced the exact name is added as
  `filename*=UTF-8''…` (RFC 5987), which conforming clients prefer. Plain
  ASCII names produce the same header as before. Note this was never a header
  injection vector — PHP's `header()` rejects values containing CR/LF — the
  defect was a malformed or mangled filename.

## [1.2.4] – 2026-07-19

Two defects reported from a downstream port. Both were silent, and both had
been present since before 1.0 — neither was introduced by the 1.2.x work.

**Upgrade note:** database errors that previously passed unnoticed now throw.
That is the intended behaviour, but a project that has been running on the
broken configuration may surface failures it was never told about.

### Fixed

- **PDO driver options were assembled with `array_merge()`, which renumbers
  integer keys** — and every PDO attribute is an integer constant. The four
  defaults came out as keys `0,1,2,3` with their values still in order, so each
  setting landed on an unrelated attribute. The consequences were all silent:

  - Key `3` **is** `ATTR_ERRMODE`, and it received `false` — that is
    `ERRMODE_SILENT`. **A stock connection did not throw on SQL errors.**
    Failed statements returned no rows and no complaint.
  - `ATTR_EMULATE_PREPARES => false` never arrived, leaving MySQL on PDO's
    default of **emulated prepares**, contradicting the "native prepared
    statements" guarantee in `docs/database.md`.
  - `ATTR_DEFAULT_FETCH_MODE` never arrived, so rows came back `FETCH_BOTH`.
  - `ATTR_AUTOCOMMIT`, `ATTR_PREFETCH` and `ATTR_TIMEOUT` received values
    intended for other attributes entirely.
  - **A downstream's own options were renumbered too.** This is why the
    `MYSQL_ATTR_FOUND_ROWS` escape hatch documented in 1.2.1 had no effect —
    that documentation was wrong, and is corrected.

  Now `array_replace()`, which preserves integer keys and gives config the last
  word. Option assembly moved into a `buildOptions()` method so it can be
  asserted without a live database.

- **Request-path exceptions were rendered but never logged.**
  `Application::handleException()` called `render()`, which does not log;
  `logException()` ran only from the globally-registered handler path, and
  `public/index.php` does not register one. A production 500 was shown to the
  visitor and left no trace anywhere. It now calls `report()` before `render()`.

  Reporting is best-effort and guarded twice — `report()` swallows its own
  failures, and the call site catches as well. A handler that logs to the
  database is a normal thing to bind, and the database being down is a normal
  reason for the 500 in the first place; a reporter that throws must not cost
  the visitor the error page.

### Added

- **A default `Logger` binding.** `boot()` registers a file logger
  (`storage/logs/app.log`) if nothing else claimed `Logger::class`, and passes
  it to the default `ExceptionHandler`. Without a logger `report()` has nowhere
  to write, so the fix above would have been a no-op in a stock application.
  Bind your own `Logger` and the error handler picks it up.

### Documentation

- `docs/database.md` — the `options` merge semantics, and the corrected
  `MYSQL_ATTR_FOUND_ROWS` note.
- `docs/overview.md` — request-path reporting, the two boot-time bindings, and
  how to replace either.

## [1.2.3] – 2026-07-18

Two silent no-ops, both the same shape as the dead code removed in 1.2.2:
something that looks like it is configured or rendering, and isn't.

### Changed

- **`partial()` rejects a template that calls `extends()`.** Rendering without
  layout handling means a template that captures its body into sections has
  nowhere to put it: the call returned an **empty string** and the content
  disappeared without a word. 1.2.0 stopped such a template injecting a whole
  `<!DOCTYPE html>` document into a modal, but the replacement outcome was no
  better — silently blank. `partial()`, `include()`, `component()` and
  `Controller::renderPartial()` / `renderToResponse()` now throw a
  `RuntimeException` naming the template and the layout it asked for, and the
  engine's layout state is restored so the *next* render is unaffected.

  A partial calling `section()` **without** `extends()` still works — that is
  how a partial contributes to a parent page's `scripts` section, and no
  shipped view relies on the rejected combination.

- **`config('app.timezone')` is applied.** It has shipped in `config/app.php`
  since the beginning and nothing ever read it, so `date()` and `DateTime` ran
  on whatever php.ini said while the config file claimed `Europe/Helsinki`.
  `Application::boot()` now calls `date_default_timezone_set()`. An unknown
  identifier throws rather than falling back, because the failure being
  prevented is an entire application quietly keeping the wrong clock — the same
  hazard as the unasserted session time zone documented in `database.md`.

  **Check this on upgrade:** if you were relying on the php.ini default, your
  application clock moves to `Europe/Helsinki` unless you set `app.timezone`
  yourself. Set it to `UTC` to line timestamps up with `UTC_TIMESTAMP()`.

`app.name` and `app.env` remain application-only keys — documented as such,
since `config/` is downstream-editable territory and both are conventional for
application code to read.

## [1.2.2] – 2026-07-18

Removes dead code from `src/`. Standing rule, recorded here because it governs
future changes: **dead code does not ship unless it is documented framing for an
extendable feature.** Unreachable branches read as intent — the next maintainer
assumes they matter and preserves them.

Nothing here changes behaviour for correct code. The removed members had no
callers anywhere in `src/`, `app/`, `routes/`, `tests/`, `public/` or `console`.

### Removed

- **`Router::$groupMiddleware`** — declared and never written or read; the whole
  file contained one reference, the declaration. Superseded by
  `$currentGroupMiddleware`, which is what `group()` saves and restores.
- **`Router::registerNamed()`** — undocumented public method superseded by the
  lazy name resolution in `url()`. It was also a trap: a route registered
  through it landed in `$namedRoutes` but not `$routes`, so it generated URLs
  for a path the router could never dispatch.
- **`Middleware::respond()` and `Middleware::next()`** — identity passthroughs
  (`return $response;`, `return $next($request);`) that no shipped middleware
  called. The abstract class remains as the `MiddlewareInterface` carrier;
  extending it was always optional, and `docs/conventions.md` tells downstream
  middleware to implement the interface instead.
- **`ViewEngine`'s `$cachePath` constructor parameter**, property and
  assignment. Templates are plain PHP includes, so nothing is compiled or
  cached; the value was stored in a private property with no accessor and never
  read — write-only state, not an extension point, since a downstream could not
  reach it either. `Application` no longer computes a storage path for it. PHP
  ignores surplus arguments to userland functions, so an existing
  `new ViewEngine($views, $cache)` call keeps working.
- **`Logger`'s `KALLIOMICRO_BASE_PATH` branch.** Only the `console` entry script
  defines that constant, and `console` passes an explicit log path — so the
  guard could never be true. The web entry point, the one that actually reaches
  this default, never defined it at all. `getDefaultLogPath()` now asks
  `Application` for the base path, which is also correct if a downstream ever
  relocates `src/`. The constant remains an application-layer convenience for
  `app/` commands; framework code no longer reads it.

### Fixed

- **`Controller::renderToResponse()` applied the page layout.** It called
  `render()` and fed the result into a DOM `replace` action — the second door to
  the `<!DOCTYPE html>`-injected-into-a-target bug that 1.2.0 fixed for
  `renderPartial()`, left open because the two methods were fixed separately. It
  now renders as a partial. Documented public API, so it is repaired rather than
  removed.
- **`ViewEngine` and `Communicator` guarded on the wrong condition.**
  `function_exists('config')` can never be false — `helpers.php` is in
  composer's `autoload.files`, so `config()` exists the moment the autoloader
  that makes these classes loadable has run. The real hazard is `config()`
  *without an Application*: it resolves through `app()` and fataled with "Call
  to a member function make() on null". `ViewEngine` now guards on the
  container, so a bare `new ViewEngine($path)` calling `t()` no longer dies;
  `Communicator` keeps only the container half of its guard.

### Documented

- **`Application::booting()`** — a real extension point with no callers and no
  spec entry. It is the seam for claiming the `ExceptionHandler` binding before
  `boot()` registers the default, which `docs/overview.md` promised without
  naming the method that provides it. Now documented with a worked example.

## [1.2.1] – 2026-07-18

Closes the QueryBuilder items left open by 1.2.0. One new method, four fixes,
two known limits documented rather than changed. No breaking changes.

### Added

- **Nested `where()` / `orWhere()` groups.** Passing a `Closure` opens a
  parenthesised group:

  ```php
  ->where('tenant_id', $tenant)
  ->where(fn ($q) => $q->where('owner_id', $user)->orWhere('public', 1))
  // WHERE `tenant_id` = :p0 AND (`owner_id` = :p1 OR `public` = :p2)
  ```

  Conditions compile as a flat list and SQL binds `AND` tighter than `OR`, so a
  mixed chain does not mean what it reads like — `where->orWhere->where` is
  `a OR (b AND c)`. That behaviour is unchanged, because it is what SQL means
  and re-grouping it would alter every existing query; what changes is that
  expressing the *other* reading no longer requires `whereRaw()`. Sending
  authorization filters — the queries where grouping matters most — through the
  one method that accepts arbitrary SQL text was a bad incentive to ship.

  Groups nest. The sub-builder continues the outer placeholder numbering, so
  bindings cannot collide on `:pN`. Column and operator validation applies
  identically inside a group. An empty closure adds nothing rather than
  emitting `()`. The empty-`whereIn()` guard composes: an empty `IN` nullifies
  its own group, and an always-false group joined with `AND` nullifies the
  whole query.

### Fixed

- **`pluck()` on a qualified column returned an empty array.** MySQL labels
  `` `users`.`name` `` as `name`, so looking the value up as `users.name` found
  nothing — silently, and per-row warnings in the keyed form.
- **`value()` did not apply `LIMIT 1`**, so reading one value pulled the whole
  matching set across the wire. `first()` already clamped; the asymmetry was an
  oversight.
- **`affectedRows()` reported the previous statement after a failure.**
  `lastStatement` was assigned after `execute()`, so a throwing statement left
  a stale count behind — which reads as a successful write that never happened.
- **`SET NAMES` interpolated charset and collation unvalidated.** Not
  exploitable (both come from config, not from a request), but it was the only
  interpolated statement in a class whose stated contract is "no interpolation,
  ever"; both are now validated against `[A-Za-z0-9_]+`.

### Documented, not changed

- **`insert()` returns 0 on a table with no AUTO_INCREMENT** — that is what
  `lastInsertId()` reports, and it is indistinguishable from failure. A failed
  insert throws, so treat "no exception" as success there. Same shape as the
  `upsert()` note in 1.2.0.
- **`sql_mode` and the session time zone are not asserted.** A non-strict server
  silently truncates oversize values, and `NOW()` follows the server zone while
  application code writes GMT — measured 180 minutes apart on a development
  machine. Both are deployment policy, consistent with the line drawn for the
  scheduler lock and session store, and both can be set per connection through
  `config('database.connections.*.options')`.

### Internal

- `FakeConnection` stubs `selectValue()`. Without it, `value()` fell through to
  the real `Connection` and tried to open a database handle mid-test — the same
  class of gap that let ``SELECT `*` `` pass a green suite for a release.

## [1.2.0] – 2026-07-18

Closes an eight-area adversarial audit of `src/` run from a downstream project.
Every item below was reproduced by *executing* the framework, fixed, and covered
by a test. **Upgrading is strongly recommended** — the Security section contains
four remotely exploitable defects.

If you maintain a downstream copy, read "Behavior changes to check" last: a few
fixes are deliberately breaking, because the old behavior failed open.

### Security

- **Open redirect via `Session::sanitizeRelativeUrl()`.** The protocol-relative
  and backslash guards ran on the *input*, then the absolute-URL branch replaced
  the URL with `parse_url(..., PHP_URL_PATH)` — which can itself start with `//`
  — and never re-checked. `https://victimhost//evil.example/x` reduced to
  `//evil.example/x`. `Controller::back()` was the reliable vector: its
  same-origin check passes because the host parses as `victimhost`, so a single
  `Referer` header produced an off-site redirect. Guards now run *after* the
  reduction, cover every `/`/`\` combination, and reject control characters.
- **Arbitrary file inclusion via view data.** `ViewEngine::renderFile()`
  extracted view data with the default `EXTR_OVERWRITE` into the scope holding
  the include target, so a data key named `__path` included and executed an
  arbitrary file — remote code execution for any project that flattens request
  input into view data. Now `EXTR_SKIP`.
- **SQL injection in the `where` family.** `where`/`orWhere`/`whereIn`/
  `whereNotIn`/`whereNull`/`whereNotNull`/`whereBetween`/`groupBy`/`orderBy`
  never validated their column, while `select`/`join`/aggregates did — so the
  ordinary `?sort=<column>` pattern was directly injectable. The `where`
  **operator** was interpolated unvalidated too, though JOIN allowlisted it.
  Underneath, `Connection::quoteIdentifier()` returned any identifier containing
  a backtick or space *unquoted and unescaped*. All of them validate now, and
  quoting fails closed.
- **OAuth login CSRF.** `hash_equals('', '')` is `true`, so a victim with no
  flow in progress accepted a callback carrying an empty `state`, logging their
  browser into the attacker's account. Google was directly exploitable; Entra
  survived only incidentally via PKCE. Both sides must now be non-empty, and the
  state is consumed before comparison so it is single-use on every path.
- **Reflected XSS and production message leak in `Application::handleException()`.**
  It was a second, weaker copy of `ExceptionHandler`: unescaped message and trace
  in HTML, the raw trace (including call `args`) on the JSON path, and a
  `$status < 500` branch that returned raw exception messages for *any* 4xx in
  production. Deleted; it delegates to `ExceptionHandler` now.
- **Log injection.** `Logger` wrote messages into a newline-terminated `sprintf`
  with no CR/LF handling, so anything logging user-influenced text could forge
  audit entries byte-identical to genuine ones.
- **`LocalAuthProvider::$active_column` failed open.** `isset($u[$c]) && !$u[$c]`
  is false for a NULL value *and* a missing key, so the check was skipped and a
  disabled account authenticated; `'N'` and `'false'` passed as truthy strings.
- **User-enumeration timing oracle.** The unknown-user branch verified against a
  hardcoded cost-10 bcrypt string while real hashes use `PASSWORD_DEFAULT`
  (cost 12): 46 ms versus 184 ms. The disabled-account branch returned before
  `password_verify` ran at all (~0 ms), separating three states by response time.
- **`ProfileMiddleware` authorization bypass.** A loose `in_array` made
  `in_array(null, [0])` true, so `ProfileMiddleware($session, 0)` admitted any
  authenticated user whose session lacked `profile_id`.
- **CSRF token never rotated.** `regenerateCsrfToken()` had no caller beyond its
  one-time init, so one token spanned the whole browser session across every
  privilege boundary and survived logout. It now rotates on `login()`,
  `logout()`, `impersonate()` and `stopImpersonating()`.
- **Client JS injected non-JSON responses as HTML.** A response whose body was
  not JSON was wrapped as modal content and passed to `insertAdjacentHTML`, so a
  followed redirect to an HTML login page injected that whole page into the
  modal. `showModal` also interpolated `id` and `size` into attributes unescaped.

### Fixed

- **`DotEnv` silently discarded the rest of the file.** A quoted value was
  "complete" only if the line *ended* with the quote, so `KEY="value" # comment`
  started a multiline value and absorbed every following line; `parse()` never
  flushed, so anything not later closed vanished with no error. Verified losing
  `APP_URL` and the database credentials while leaving `APP_DEBUG` truthy.
- **`SELECT *` compiled to ``SELECT `*` ``**, invalid on MySQL — so `get()`,
  `first()`, `pluck()` and `paginate()` failed unless the caller passed explicit
  columns. `*` and `table.*` now pass through unquoted.
- **`forPage()` did not clamp**: `forPage(0, 15)` emitted `OFFSET -15`, so
  `?page=0` was a guaranteed 500. `limit()`/`offset()` reject negatives, and an
  `offset()` without a `limit()` emits a sentinel `LIMIT` (MySQL has no bare
  `OFFSET`).
- **Empty `whereIn([])` failed open.** The `0 = 1` guard was appended, and AND
  binds tighter than OR, so `a = 1 OR b = 2 AND 0 = 1` reduced to `a = 1` — an
  empty permission allowlist returned rows. It now collapses the whole clause.
- **`ExceptionHandler` cascaded into an uncaught fatal.** No level mask meant a
  warning raised *inside* the handler (`file_put_contents` on an unwritable log
  directory) became an `ErrorException` that escaped and destroyed the original
  error, printed twice because `handleShutdown()` re-entered on the fatal the
  handler had just produced. Adds a level mask, a re-entrancy guard, try/catch
  around reporting, and a `headers_sent()` guard.
- **Global middleware did not run on the exception path**, so security headers
  set there vanished on every thrown 404/403/500. Error responses are now
  rendered at the destination and travel back out through the stack.
- **`env()` treated `off`/`no`/`disabled` as truthy**, so `APP_DEBUG=off`
  *enabled* debug and shipped stack traces in production.
- **Cron fields mixing a list and a range over-matched.** `-` was tested before
  `,`, so `0 1,3-5 * * *` was due at 02:00 and `0 1-5,10 * * *` was not due at
  10:00 — tasks ran at times they were never scheduled for.
- **`Container` ignored a re-bind after resolution**, so a bootstrap overriding a
  base binding was correct only by ordering accident.
- **`ViewEngine` corrupted the response on a mid-section exception** (one
  `ob_end_clean()` discarded the section buffer and orphaned the render's own,
  flushing the aborted page ahead of the error page), and `render()` reset
  `sections` but not `currentSection`/`currentLayout`, so a failed render wrapped
  the *next* page in a layout it never requested.
- **`ViewEngine::e()` lacked `ENT_SUBSTITUTE`**, so one invalid UTF-8 byte
  blanked the entire field instead of the bad character.
- **`ViewEngine::exists()` returned true for directories.**
- **`Controller::renderPartial()` called `render()`**, so a modal template using
  `extends()` injected a whole `<!DOCTYPE html>` document into the modal body.
- **`Logger` dropped structured context** on the database path (`$contextJson`
  was computed and never used) and **raised a `TypeError` out of the logger**
  when a context scalar had the wrong type — killing the caller rather than
  falling back to file.
- **`Communicator` swapped its config instead of merging**, so a caller passing
  one key lost the other six defaults and the unguarded reads that followed
  raised warnings that escaped `sendEmail()` as `ErrorException`s, breaking its
  documented "always returns a Result" contract.
- **HEAD and OPTIONS were answered 405**, breaking `curl -I` and most uptime
  monitors (RFC 9110 §9.3.2).
- **Route paths were not regex-escaped**, so `/files/report.pdf` also matched
  `/files/reportXpdf`, and an unbalanced `(` made a route permanently unmatchable
  plus a PHP warning on every request. Route params are `rawurldecode`d;
  `generateUrl()` `rawurlencode`s them.
- **`Router::group()` leaked** its prefix and middleware onto later routes when
  the group callback threw.
- **A failing `commit()` was masked** by `rollback()`'s own "no active
  transaction", which replaced the real cause.
- **Array bindings stringified to the literal `'Array'`** and were written to the
  column; they now throw.
- **Aggregates and `increment()`/`decrement()` validated but never quoted** their
  column, so any reserved-word column broke.
- **`ApiResponse::toJson(): string` could return `false`** — a `TypeError` under
  `strict_types` naming nothing about the cause.

### Added

- **`database/schema.sql`** — DDL for `core_users` and `core_logs`. The base has
  always *required* these tables without defining them, so a fresh project fell
  back to file logging on every write until someone reverse-engineered the column
  list out of `Logger::writeToDatabase()`. `core_logs` gains a `context` column.
- **`Session::getLoginTime()`** — `_login_time` was written and never read.
  Idle/absolute timeout remains deliberately unshipped policy; this is the
  mechanism a deployment needs to build one.
- **`config/notifications.php`** — `Communicator` held the only `env()` calls
  outside `config/`, against the project's own security checklist.
- **HEAD and OPTIONS handling** — HEAD serves the GET route with the body
  stripped; OPTIONS answers 204 with `Allow`. CORS preflight stays a middleware
  concern.
- **`ExceptionHandler` is container-bound**, so a downstream can swap or
  configure it (custom renderer, hidden paths, logger) via `instance()`.

### Changed — check these before upgrading

Deliberately breaking, because each old behavior failed open or silently:

- **`Connection::quoteIdentifier()` throws** on an identifier containing a
  backtick or space instead of returning it unquoted. Aliases belong in a
  `RawExpression`.
- **`where()` operators are allowlisted.** An operator outside
  `= != <> < > <= >= LIKE "NOT LIKE"` now throws.
- **`e()` and `$view->e()` are the same strict function.** Arrays, plain objects
  and resources throw `InvalidArgumentException` instead of printing the literal
  text `Array` into the page. Pick the field, or `implode()` first.
- **`Router::toResponse()` throws** on return types outside the documented set
  (`Response`, array/object, string, `null`). A bare `return false;` used to
  become a 200 with an empty body, so a refused action read as success.
- **`Route::generateUrl()` throws** when a required parameter is missing instead
  of emitting the literal `/users/{id}`.
- **`update()`/`delete()`/`affectedRows()` still report *changed*, not *matched*,
  rows** — unchanged behavior, now documented. Set `MYSQL_ATTR_FOUND_ROWS` in
  your connection `options` if you want matched-row semantics.
- **`Controller::renderPartial()` renders a partial**, which means view composers
  no longer run for it (`partial()` does not run them; `render()` did).
- **An unterminated quote in `.env` now raises** instead of silently discarding
  the remaining variables.
- **A non-JSON response body no longer becomes modal content** in the client.
  Endpoints feeding `data-action` must return JSON.

### Internal

- `AuthProviderInterface`, `OAuthProviderInterface`, `AuthResult` and
  `RawExpression` moved into their own files. Declared inside `AuthManager.php`
  and `QueryBuilder.php` they were not autoloadable, so `new RawExpression(...)`
  failed in a fresh process despite being documented API.
- `Connection::transaction()`'s dispatch through `$this->beginTransaction()` /
  `commit()` / `rollback()` is now documented as **contract** — it is what makes
  savepoint-nesting subclasses possible.
- SQL assertions in `tests/` are whole-string. Substring assertions are why
  ``SELECT `*` `` and `LIMIT 15 OFFSET -15` passed a green suite for a release.

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
