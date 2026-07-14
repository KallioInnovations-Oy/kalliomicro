# Routing, Middleware & HTTP Layer

> Sources: `src/Routing/Router.php`, `src/Routing/Route.php`, `src/Middleware/`, `src/Http/`.

---

## Router

Routes are declared in `routes/web.php` and `routes/api.php`, which receive `$router` from the entry point.

### Registering routes

```php
$router->get(string $path, array|Closure|string $handler): Route      // also post/put/patch/delete
$router->match(array $methods, string $path, $handler): Route          // returns the LAST created Route
$router->any(string $path, $handler): Route                            // GET/POST/PUT/PATCH/DELETE
$router->resource(string $path, string $controller): void
$router->apiResource(string $path, string $controller): void
$router->group(array $attributes, Closure $callback): void
```

Handlers: `[Controller::class, 'method']` (preferred), a `Closure`, or `'Controller@method'`. Controllers are container-resolved; handler arguments are injected by name (`request` plus route params).

`resource()` generates the 7 conventional routes (`index`, `create`, `store`, `show`, `edit`, `update`, `destroy`), `apiResource()` 5 (no `create`/`edit`). Each generated route is named `{path}.{action}` — note the name literally embeds the path, e.g. `/assessments.index`.

### Groups

`group(['prefix' => '/app', 'middleware' => […]], fn (Router $router) => …)` — prefixes concatenate and middleware arrays merge (parent first) across arbitrary nesting; state is saved/restored around the callback. **Every group must state its middleware array explicitly** — `/app` and `/api` groups require an auth guard (the shipped route files show the pattern with commented placeholders).

### Route parameters and constraints

- `{param}` matches one non-slash segment; `{param?}` is optional. Trailing slashes are always optional.
- Constraints: `->where('id', '[0-9]+')`, `->whereArray()`, `->whereNumber()`, `->whereAlpha()`, `->whereAlphaNumeric()`, `->whereUuid()`; defaults for optional params via `->default('param', 'value')`.
- Params arrive as string arguments after `$request` (by name), and via `$request->route('param')`.

### Named routes

`->name('users.show')` names a route; `Router::url($name, $params)` / the global `url()` helper generate URLs from it (names are resolved lazily on first lookup, so registration order doesn't matter). Unknown names throw `RuntimeException`.

### Dispatch semantics

- Routes match **in registration order, first match wins** — register literal paths before overlapping wildcard paths.
- Wrong HTTP verb on an existing path → **405 Method Not Allowed** with an `Allow` header listing the valid verbs (JSON body for `wantsJson()` requests, HTML otherwise).
- No path match at all → 404 (JSON or HTML by the same rule).
- Controller return values are coerced: `Response` as-is, array/object → JSON, string → HTML, `null` → 204.

---

## Middleware

Contract: `KallioMicro\Http\MiddlewareInterface::handle(Request $request, Closure $next): Response`. A middleware short-circuits by returning a `Response` without calling `$next($request)`.

Two tiers:

- **Global middleware** — closures registered with `Application::middleware()` in `public/index.php`; run for every request, outermost-first in registration order. The shipped global middleware starts the session.
- **Route middleware** — attached to routes/groups. ⚠ `Route::middleware()` accepts **closures only** — class-string middleware is wrapped at the route file:

```php
'middleware' => [
    fn ($req, $next) => (new AuthMiddleware(app(Session::class)))->handle($req, $next),
],
```

### Middleware catalog

| Class | Guards | On failure |
|---|---|---|
| `AuthMiddleware(Session, $loginUrl = '/login')` | session authenticated | JSON: 401; web: stores intended URL, 302 to login |
| `GuestMiddleware(Session, $homeUrl = '/')` | NOT authenticated (login pages) | JSON: 400; web: redirect home |
| `RoleMiddleware(Session, string ...$roles)` | user holds **any** listed role (from the session user's `roles` array) | 403 (JSON or HTML) |
| `ProfileMiddleware(Session, int ...$profileIds)` | `profile_id` in allowlist | unauthenticated: 401/redirect; wrong profile: 403 |
| `CsrfMiddleware(Session, array $except = [])` | valid CSRF token on non-GET/HEAD/OPTIONS; `$except` supports `*` wildcards | JSON: 403 `CSRF token mismatch`; web: 403 HTML |

Roles and `profile_id` are read straight from the session user array — the framework has no permission/RBAC service; downstream projects own role assignment and, if needed, a finer-grained authorization layer.

---

## Request

`KallioMicro\Http\Request` — captured once per request (`Request::capture()`), bound as a singleton. `Request::create()` exists for tests.

- **Method spoofing:** a POST with `_method=PUT|PATCH|DELETE` (the `$view->method('PUT')` hidden field) is promoted in the constructor, so the router matches the spoofed verb. Only POST can spoof, only to those three verbs; a real PUT/PATCH/DELETE is never demoted.
- **Input precedence:** `input($key)` reads POST → query. `all()` merges query + post. A JSON request body is **not** merged — read it explicitly via `json(): ?array` or `content()`.
- Typed accessors: `string()`, `integer()`, `float()`, `boolean()`; plus `only()` / `except()`, `query()`/`post()` and their `*All()` forms, `has()`.
- Headers are case-insensitive (`header('x-csrf-token')`); cookies via `cookie()`; uploads via `file()`/`hasFile()`.
- Content negotiation: `expectsJson()` (Accept), `isJson()` (Content-Type), `isAjax()` (X-Requested-With — kalliomicro.js sets it on every request), `wantsJson()` = either.
- Route params: `route($key)` / `routeParams()`; middleware-to-controller data: `setAttribute()` / `getAttribute()`.
- ⚠ **`ip()` blindly trusts `X-Forwarded-For`** (returns its first entry when present, else `REMOTE_ADDR`). Behind anything other than a trusted proxy that overwrites the header, the client controls this value — do not use it for security decisions without infrastructure guarantees.

## Response

`KallioMicro\Http\Response` factories:

```php
Response::json(array|object $data, int $status = 200)   // JSON_UNESCAPED_UNICODE, throws on encode failure
Response::html(string $content, int $status = 200)
Response::text(string $content, int $status = 200)
Response::noContent()                                   // 204
Response::redirect(string $url, int $status = 302)
Response::download(string $content, string $filename, string $contentType = 'application/octet-stream')
Response::file(string $content, string $filename, string $contentType)   // inline disposition
```

Fluent: `status()`, `content()`, `header()` (replaces), `addHeader()` (appends), `withHeaders()`, `cookie()` / `forgetCookie()` (SameSite-aware). Getters: `getContent()`, `getStatusCode()`, `getStatusText()`, `getHeaders()` (`name => string[]`), `getHeader()`. Predicates: `isSuccessful()`, `isRedirection()`, `isClientError()`, `isServerError()`, `isOk()`, `isNotFound()`. `send()` emits status line, headers, cookies, body.

## Controller base class

Controllers extend `KallioMicro\Http\Controller`. The constructor resolves `$this->request` and — when bound — `$this->db`, `$this->view`, `$this->session` (protected properties), then calls the overridable `boot()` hook.

Method signature convention: `public function action(Request $request, string $id): Response`.

```php
// Rendering
protected function render(string $template, array $data = [], int $status = 200): Response
protected function renderPartial(string $template, array $data = []): string        // modal content
protected function renderToResponse(string $template, string $target, array $data = []): ApiResponse
protected function prepareViewData(array $data): array
    // injects csrf_token, user, flash into $data AND shares csrf_token + user into the
    // ViewEngine (so $view->csrf(), isAuth(), hasRole() work). getFlash() clears flash.

// Input & validation
protected function input(string $key, mixed $default = null): mixed
protected function all(): array
protected function only(array $keys): array
protected function route(string $key, mixed $default = null): mixed
protected function validate(array $rules, array $messages = []): array   // see validation.md
protected function isAjax(): bool
protected function wantsJson(): bool

// CSRF
protected function verifyCsrf(): bool     // csrf_token field OR X-CSRF-Token header, hash_equals
protected function requireCsrf(): void    // throws on mismatch — see ⚠ below

// Auth
protected function isAuthenticated(): bool
protected function user(): ?array
protected function userId(): ?int

// Responses & data
protected function success(string $message = ''): ApiResponse
protected function error(string $message, int $httpStatus = 400): ApiResponse
protected function json(array|object $data, int $status = 200): Response
protected function html(string $content, int $status = 200): Response
protected function redirect(string $url, int $status = 302): Response
protected function back(): Response
protected function table(string $table): QueryBuilder
```

**Every state-changing method (store/update/destroy) calls `$this->requireCsrf()` as its first line.**

⚠ Caveats (as of 2026-07-14): `requireCsrf()` throws `RuntimeException('CSRF token mismatch', 403)`, but the exception handler maps plain `RuntimeException` to **500** (see [overview.md](overview.md)) — prefer route-level `CsrfMiddleware` for a proper 403, or throw `HttpException::forbidden()`. `back()` redirects to the raw `Referer` header (default `/`) — it does not verify same-origin; don't use it after security-sensitive actions.

There are **no** `can()` / `hasRole()` / `authorize()` helpers on the controller — role checks go through the session user (`$this->user()['roles']`) or route-level `RoleMiddleware`; permission systems are a downstream concern.

## HttpException

`KallioMicro\Http\HttpException` (extends `RuntimeException`) carries a status code + optional headers; the exception handler renders it at that status. Static factories: `badRequest()`, `unauthorized()`, `forbidden()`, `notFound()`, `methodNotAllowed()`, `validationError()`, `tooManyRequests()`, `serverError()`. **This is the correct way to abort with a specific HTTP status from anywhere.**
