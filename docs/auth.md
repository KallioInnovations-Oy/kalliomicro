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

- Unknown user → `password_verify` against a dummy bcrypt hash before the generic `Invalid credentials` (timing-attack mitigation).
- Disabled accounts report `Account is disabled`; the password column is stripped from the returned user array.
- Public utilities: `hashPassword(string): string`, `verifyPassword(string, string): bool`.

### Entra ID (`EntraIdAuthProvider`)

OAuth2 authorization-code with **PKCE (S256)** against `login.microsoftonline.com/{tenant}`; user info from Graph `/me`. CSRF `state` + PKCE verifier live in session keys `_oauth_state` / `_oauth_code_verifier`; state compared with `hash_equals`. Requires `tenant_id`, `client_id`, `redirect_uri` (throws at construction); `client_secret` optional (public-client PKCE supported). `refreshToken()` available. Config env: `ENTRA_TENANT_ID/CLIENT_ID/CLIENT_SECRET/REDIRECT_URI`.

### Google (`GoogleAuthProvider`)

OAuth2 authorization-code (client secret, no PKCE), `access_type=offline`, `prompt=select_account`; optional `hosted_domain` restriction (sent as `hd` *and* verified on callback). Produces `verified_email` — check it before trusting the email. Config env: `GOOGLE_CLIENT_ID/SECRET/REDIRECT_URI/HOSTED_DOMAIN`.

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

**Scope notes (by design):**

- **Native PHP file sessions.** There is no database session handler in the base — a multi-replica deployment (load-balanced containers with ephemeral disks) registers its own `SessionHandlerInterface` before scaling out.
- **`impersonate()` is a mechanism, not a policy** — it swaps the session user for whatever array you pass, with no authorization check. The *caller* gates it (admin role check or equivalent); the base cannot know which role model a deployment uses.

`setIntendedUrl()` sanitizes its input to a **same-origin relative path** (absolute URLs are reduced to path + query; protocol-relative, scheme-prefixed and backslash-prefixed values collapse to `/`) — the post-login redirect cannot become an open redirect even though `AuthMiddleware` feeds it the raw request URL.

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
