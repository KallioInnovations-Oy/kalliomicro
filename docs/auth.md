# Authentication & Sessions

> Sources: `src/Auth/AuthManager.php` (also declares `AuthResult` + the provider interfaces), `src/Auth/Providers/`, `src/Auth/Session.php`, `config/auth.php`, `config/session.php`.

---

## AuthManager

`KallioMicro\Auth\AuthManager` — `__construct(Config $config, Session $session, ?Connection $db = null)`. Default provider from `auth.default` (`local`); per-provider config from `auth.providers.{name}`. Accessible via the `auth()` helper (registered as a singleton in `public/index.php`).

```php
public function attempt(array $credentials): AuthResult              // default provider
public function attemptWith(string $provider, array $credentials): AuthResult
public function logout(): void
public function check(): bool
public function user(): ?array
public function id(): ?int
public function getAuthorizationUrl(string $provider): string        // OAuth providers only
public function handleOAuthCallback(string $provider, array $params): AuthResult
public function registerProvider(string $name, AuthProviderInterface $provider): void
```

On a successful `attempt*()` **and** a successful `handleOAuthCallback()`, the manager calls `Session::login()` with the provider's user array.

`AuthResult` is immutable: `AuthResult::success($user, $message = '')`, `::failure($message)`, `::redirect($url)`; accessors `isSuccess()`, `getMessage()`, `getUser()`, `getRedirectUrl()`, `needsRedirect()`.

Provider contracts:

```php
interface AuthProviderInterface { authenticate(array $credentials): AuthResult; getName(): string; }
interface OAuthProviderInterface extends AuthProviderInterface { getAuthorizationUrl(): string; handleCallback(array $params): AuthResult; }
```

**Scope notes (by design)** — the base ships authentication *mechanisms*; the policies are the deployment's to own:

- **No login throttling** — there is no attempt counter or lockout. A deployment exposing a login form publicly adds its own rate limiting (application table or infrastructure).
- **OAuth callback logs the provider user in directly.** Deployments that require pre-provisioned accounts (SSO users must already exist locally) implement that policy in their auth controller: resolve the provider identity to a local user record and call `Session::login()` with the *local* user only.

## Providers

### Local (`LocalAuthProvider`)

DB username/password against `core_users` (table/columns configurable: `table`, `username_column`, `password_column`, `active_column`). Hashing: `password_hash` with `PASSWORD_DEFAULT`, auto-rehash on login. Hardened:

- Unknown user → `password_verify` against a dummy hash generated from the **configured** `hash_algo` before the generic `Invalid credentials`. Generating it (rather than hardcoding one) is what makes the mitigation work: a fixed cost-10 bcrypt string against `PASSWORD_DEFAULT`'s cost 12 measured 46ms versus 184ms, which is a trivially separable remote user-enumeration oracle.
- Every failure path performs the password verification, including the disabled-account path — it used to return before `password_verify` ran at all (~0ms), so response time alone separated disabled / nonexistent / wrong-password even when all three returned the same message.
- Disabled accounts report `Account is disabled`; the password column is stripped from the returned user array.
- **The `active_column` check fails closed.** A row missing the configured column entirely — a typo'd config name, or a `SELECT` that omitted it — is treated as disabled, not as unchecked. Values are read as booleans: `1`/`true`/`on`/`yes`/`Y`/`T` mean active; `0`/`'0'`/`false`/`no`/`off`/`N`/`F`/`''`/`NULL` and anything else mean disabled. Previously the check was skipped whenever the value was `NULL` or the key was absent, so a disabled account authenticated, and `'N'` passed as a truthy string.
- Public utilities: `hashPassword(string): string`, `verifyPassword(string, string): bool`.

### Entra ID (`EntraIdAuthProvider`)

OAuth2 authorization-code with **PKCE (S256)** against `login.microsoftonline.com/{tenant}`; user info from Graph `/me`. CSRF `state` + PKCE verifier live in session keys `_oauth_state` / `_oauth_code_verifier`; state compared with `hash_equals`. See the state contract below — it applies to every OAuth provider. Requires `tenant_id`, `client_id`, `redirect_uri` (throws at construction); `client_secret` optional (public-client PKCE supported). `refreshToken()` available. Config env: `ENTRA_TENANT_ID/CLIENT_ID/CLIENT_SECRET/REDIRECT_URI`.

### Google (`GoogleAuthProvider`)

OAuth2 authorization-code (client secret, no PKCE), `access_type=offline`, `prompt=select_account`; optional `hosted_domain` restriction (sent as `hd` *and* verified on callback). Produces `verified_email` — check it before trusting the email. Config env: `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI/HOSTED_DOMAIN`.

### OAuth `state` contract (both OAuth providers)

`handleCallback()` consumes `_oauth_state` **before** comparing it and regardless of outcome, so a state is single-use and cannot be replayed after a failed attempt. Both the stored and the received state must be non-empty: `hash_equals('', '')` is `true`, so a victim who never started a flow — and therefore has no `_oauth_state` — would otherwise accept a callback carrying an empty `state`. That is login CSRF: the attacker plants their own authorization code and the victim's browser ends up logged into the attacker's account. A non-string `state` is rejected rather than raising.

Do not rely on PKCE to cover this. Entra survived the empty-state case only because the token exchange also needs a stored `code_verifier`; Google has no PKCE and was directly exploitable. Each check has to stand on its own.

### LDAP (`LdapAuthProvider`)

Service-account bind → search (`user_filter`, default `(sAMAccountName={username})`, input escaped with `ldap_escape` — filter-injection safe) → **bind as the found DN** as the password check. Supports `ldaps://` and STARTTLS. Configurable AD attribute map (mail, givenName, sn, displayName, …). `getUserGroups($userDn)` returns group CNs. Requires `ext-ldap`.

---

## Session

`KallioMicro\Auth\Session` wraps **native PHP `$_SESSION`** with secure defaults from `config/session.php`: strict mode, cookie-only, HttpOnly, `SameSite=Lax`, Secure (default on), cookie name `meso_session`, lifetime 120 min, id regeneration every 300 s and on every privilege change. All accessors lazily `start()`.

```php
// data:   get / set / has / forget / all
// flash:  flash($key, $value) / getFlash() (read-and-clear all) / getFlashValue($key)
// csrf:   getCsrfToken() / regenerateCsrfToken() / verifyCsrfToken(?string)  — hash_equals, 32 random bytes
// auth:   login(array $user)   — regenerates the session id first (fixation defense)
//         logout() / isAuthenticated() / getUser() / getUserId() / getProfileId()
//         getUserRoles()       — $user['roles'] ?? []
//         updateUser(array $data)
// impersonation: impersonate(array $user) / stopImpersonating() / isImpersonating() / getOriginalUser()
// redirect: setIntendedUrl($url) / pullIntendedUrl($default = '/')
```

Reserved session keys: `_csrf_token`, `_last_regenerated`, `_authenticated`, `_user`, `_login_time`, `_flash`, `_impersonating`, `_original_user`, `_intended_url`, `_oauth_state`, `_oauth_code_verifier`.

**Privilege changes rotate both the session id and the CSRF token** — `login()`, `logout()`, `impersonate()` and `stopImpersonating()`. Rotating the token matters independently of the id: it was previously minted once and never replaced, so its validity window was the whole browser session and anything exposed pre-authentication stayed usable for authenticated writes afterwards.

**`logout()` clears the session wholesale** and mints a fresh token, rather than unsetting known keys. Unsetting left flash data, the intended URL and any in-progress impersonation behind for the next person to use that browser, and obliged every downstream to maintain its own cleanup list.

**Scope notes (by design):**

- **Native PHP file sessions.** There is no database session handler in the base — a multi-replica deployment (load-balanced containers with ephemeral disks) registers its own `SessionHandlerInterface` before scaling out.
- **No idle or absolute session timeout.** The cookie lifetime is refreshed by every id regeneration, so an active session slides indefinitely with no re-authentication boundary. `getLoginTime()` is the mechanism a deployment needs to impose one; the policy — how long, and whether idle or absolute — belongs to the deployment.
- **`impersonate()` is a mechanism, not a policy** — it swaps the session user for whatever array you pass, with no authorization check. The *caller* gates it (admin role check or equivalent); the base cannot know which role model a deployment uses.

`setIntendedUrl()` sanitizes its input to a **same-origin relative path**, so the post-login redirect does not become an open redirect even though `AuthMiddleware` feeds it the raw request URL. The sanitizer is `Session::sanitizeRelativeUrl()`, a `public static` pure function also backing `Controller::back()` — reuse it for any redirect target that a client can influence.

Order matters and is deliberate: an absolute URL is reduced to path + query **first**, then the guards run on the result. `parse_url('https://victimhost//evil.example/x', PHP_URL_PATH)` is `//evil.example/x`, so guards applied only to the input were bypassed by the reduction — and `Controller::back()`'s same-origin check does not help, since the host parses as `victimhost`. After reduction, anything starting with two slashes or backslashes in any combination, anything containing a control character, and anything not rooted at `/` collapses to `/`.

These policy contracts are also documented **in the code** — `Session::impersonate()`, `AuthManager::attemptWith()` / `handleOAuthCallback()` carry docblocks stating what the caller owns, so the guidance is visible at the call site, not only here.

---

## CSRF protection

Two cooperating layers:

1. **Server:** `CsrfMiddleware` (route-level) or `$this->requireCsrf()` (per controller method — the convention for state-changing endpoints). Both accept the `csrf_token` body field **or** the `X-CSRF-Token` header, verified with `hash_equals`.
2. **Client:** the layout exposes `<meta name="csrf-token">`; `kalliomicro.js` sends the `X-CSRF-Token` header on every POST/PUT/PATCH/DELETE and appends the `csrf_token` field to AJAX form submissions. Plain forms include `<?= $view->csrf() ?>`.

## Roles and profiles

Authorization primitives in the base framework are intentionally thin:

- **Roles** — an array on the session user (`$user['roles']`); checked by `RoleMiddleware` (any-of), `$view->hasRole()`, or application code. The framework does not define role storage — the app decides where roles come from (a column, a join table, provider groups).
- **`profile_id`** — an integer permission level on the user row; checked by `ProfileMiddleware` (allowlist).
- There is **no permission/RBAC service, no API token auth, no rate limiting** in the base — downstream projects add these when they need them.
