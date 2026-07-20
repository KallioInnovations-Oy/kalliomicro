<?php

declare(strict_types=1);

namespace KallioMicro\Auth;

use KallioMicro\Core\Config;

/**
 * Session - Secure session management
 *
 * Handles session lifecycle, CSRF protection, and user authentication state.
 * Implements security best practices for session handling.
 *
 * Storage scope: native PHP file sessions. A multi-replica deployment
 * (load-balanced containers with ephemeral disks) must register its own
 * SessionHandlerInterface via session_set_save_handler() BEFORE start(),
 * or sessions will randomly vanish across replicas.
 */
class Session
{
    private Config $config;
    private bool $started = false;

    /** @var array<string, mixed>|null Cached user data */
    private ?array $user = null;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Start the session with secure settings
     */
    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            return;
        }

        // Configure session security
        $this->configureSession();

        session_start();
        $this->started = true;

        // Regenerate ID periodically for security
        $this->checkRegeneration();

        // Initialize CSRF token if not exists
        if (!isset($_SESSION['_csrf_token'])) {
            $this->regenerateCsrfToken();
        }
    }

    /**
     * Configure secure session settings
     */
    private function configureSession(): void
    {
        $lifetime = $this->config->get('session.lifetime', 120) * 60;
        $secure = $this->config->get('session.secure', true);
        $httpOnly = $this->config->get('session.http_only', true);
        $sameSite = $this->config->get('session.same_site', 'Lax');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', $httpOnly ? '1' : '0');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_samesite', $sameSite);
        ini_set('session.gc_maxlifetime', (string) $lifetime);

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'domain' => $this->config->get('session.domain', ''),
            'secure' => $secure,
            'httponly' => $httpOnly,
            'samesite' => $sameSite,
        ]);

        $sessionName = $this->config->get('session.cookie', 'meso_session');
        session_name($sessionName);
    }

    /**
     * Check if session ID should be regenerated
     */
    private function checkRegeneration(): void
    {
        $regenerateInterval = $this->config->get('session.regenerate_interval', 300);
        $lastRegenerated = $_SESSION['_last_regenerated'] ?? 0;

        if (time() - $lastRegenerated > $regenerateInterval) {
            $this->regenerate();
        }
    }

    /**
     * Regenerate session ID
     */
    public function regenerate(bool $deleteOld = true): void
    {
        session_regenerate_id($deleteOld);
        $_SESSION['_last_regenerated'] = time();
    }

    /**
     * Destroy the session
     */
    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();
        $this->started = false;
        $this->user = null;
    }

    // Session data access

    /**
     * Get a session value
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Set a session value
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    /**
     * Check if a session key exists
     */
    public function has(string $key): bool
    {
        $this->ensureStarted();
        return isset($_SESSION[$key]);
    }

    /**
     * Remove a session key
     */
    public function forget(string $key): void
    {
        $this->ensureStarted();
        unset($_SESSION[$key]);
    }

    /**
     * Get all session data
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->ensureStarted();
        return $_SESSION;
    }

    // Flash messages

    /**
     * Set a flash message (available only for next request)
     */
    public function flash(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Get flash data and remove it
     *
     * @return array<string, mixed>
     */
    public function getFlash(): array
    {
        $this->ensureStarted();
        $flash = $_SESSION['_flash'] ?? [];
        unset($_SESSION['_flash']);
        return $flash;
    }

    /**
     * Get a specific flash value
     */
    public function getFlashValue(string $key, mixed $default = null): mixed
    {
        $this->ensureStarted();
        $value = $_SESSION['_flash'][$key] ?? $default;
        unset($_SESSION['_flash'][$key]);
        return $value;
    }

    // CSRF Protection

    /**
     * Get the current CSRF token
     */
    public function getCsrfToken(): string
    {
        $this->ensureStarted();
        return $_SESSION['_csrf_token'] ?? '';
    }

    /**
     * Regenerate the CSRF token
     */
    public function regenerateCsrfToken(): string
    {
        $this->ensureStarted();
        $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        return $_SESSION['_csrf_token'];
    }

    /**
     * Verify a CSRF token
     */
    public function verifyCsrfToken(?string $token): bool
    {
        if ($token === null) {
            return false;
        }

        $sessionToken = $this->getCsrfToken();

        if (empty($sessionToken)) {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    // Authentication state

    /**
     * Log in a user
     *
     * @param array<string, mixed> $user User data to store
     */
    public function login(array $user): void
    {
        $this->ensureStarted();

        // Regenerate session ID on login (prevent session fixation)
        $this->regenerate();

        // And rotate the CSRF token with it. The token's validity window was
        // otherwise the whole browser session, spanning every privilege
        // boundary — anything exposed pre-auth stayed usable for authenticated
        // writes afterwards.
        $this->regenerateCsrfToken();

        $_SESSION['_authenticated'] = true;
        $_SESSION['_user'] = $user;
        $_SESSION['_login_time'] = time();

        $this->user = $user;
    }

    /**
     * Log out the current user
     *
     * Clears the session wholesale rather than unsetting known keys. The old
     * version removed three, leaving flash data, the intended URL, an
     * in-progress impersonation and every application key behind — so per-user
     * state leaked across logout into the next person to use that browser, and
     * each downstream had to hand-maintain its own cleanup list.
     */
    public function logout(): void
    {
        $this->ensureStarted();

        $_SESSION = [];
        $this->user = null;

        // Regenerate session ID on logout
        $this->regenerate();

        // A fresh token for the anonymous session: the old one outlived logout
        // entirely, so a token captured while authenticated stayed valid.
        $this->regenerateCsrfToken();
    }

    /**
     * Check if user is authenticated
     */
    public function isAuthenticated(): bool
    {
        $this->ensureStarted();
        return $_SESSION['_authenticated'] ?? false;
    }

    /**
     * Unix timestamp of the current login, or null when not authenticated
     *
     * The base ships **no idle or absolute session timeout** — the cookie
     * lifetime is refreshed by every id regeneration, so an active session
     * slides indefinitely with no re-authentication boundary. That is a policy
     * the deployment owns (see the scope notes in docs/auth.md), and this
     * accessor is the mechanism it needs:
     *
     *     $age = time() - ($session->getLoginTime() ?? time());
     *     if ($age > 43200) { $session->logout(); }
     *
     * Until 1.2.0 `_login_time` was written and never read by anything.
     */
    public function getLoginTime(): ?int
    {
        $this->ensureStarted();

        $loginTime = $_SESSION['_login_time'] ?? null;

        return is_int($loginTime) ? $loginTime : null;
    }

    /**
     * Get the authenticated user
     *
     * @return array<string, mixed>|null
     */
    public function getUser(): ?array
    {
        $this->ensureStarted();

        if ($this->user === null && $this->isAuthenticated()) {
            $this->user = $_SESSION['_user'] ?? null;
        }

        return $this->user;
    }

    /**
     * Get user ID
     */
    public function getUserId(): ?int
    {
        $user = $this->getUser();
        return isset($user['id']) ? (int) $user['id'] : null;
    }

    /**
     * Get user's profile ID (permission level)
     */
    public function getProfileId(): ?int
    {
        $user = $this->getUser();
        return isset($user['profile_id']) ? (int) $user['profile_id'] : null;
    }

    /**
     * Get user's roles
     *
     * @return string[]
     */
    public function getUserRoles(): array
    {
        $user = $this->getUser();
        return $user['roles'] ?? [];
    }

    /**
     * Update user data in session
     *
     * @param array<string, mixed> $data
     */
    public function updateUser(array $data): void
    {
        $this->ensureStarted();

        if (!$this->isAuthenticated()) {
            return;
        }

        $_SESSION['_user'] = array_merge($_SESSION['_user'] ?? [], $data);
        $this->user = $_SESSION['_user'];
    }

    // Impersonation

    /**
     * Start impersonating another user — MECHANISM ONLY, no authorization here
     *
     * The base framework does not know which role model a deployment uses,
     * so this method performs NO permission check: it swaps the session user
     * for whatever array it is given. The caller MUST gate it first, e.g.:
     *
     *     if (!in_array('admin', $session->getUserRoles(), true)) {
     *         throw HttpException::forbidden();
     *     }
     *     $session->impersonate($targetUser);
     *
     * Note also that role/profile middleware sees the IMPERSONATED user —
     * keep the stop-impersonating route outside any role-gated group.
     *
     * @param array<string, mixed> $user
     */
    public function impersonate(array $user): void
    {
        $this->ensureStarted();

        if (!$this->isAuthenticated()) {
            return;
        }

        // Store original user
        $_SESSION['_original_user'] = $_SESSION['_user'];
        $_SESSION['_impersonating'] = true;
        $_SESSION['_user'] = $user;

        // Impersonation is the largest privilege change the framework offers,
        // so it gets the same treatment as login.
        $this->regenerate();
        $this->regenerateCsrfToken();

        $this->user = $user;
    }

    /**
     * Stop impersonating and return to original user
     */
    public function stopImpersonating(): void
    {
        $this->ensureStarted();

        if (!$this->isImpersonating()) {
            return;
        }

        $_SESSION['_user'] = $_SESSION['_original_user'];
        unset($_SESSION['_original_user']);
        unset($_SESSION['_impersonating']);

        // Returning to the original identity is a privilege change too
        $this->regenerate();
        $this->regenerateCsrfToken();

        $this->user = $_SESSION['_user'];
    }

    /**
     * Check if currently impersonating
     */
    public function isImpersonating(): bool
    {
        $this->ensureStarted();
        return $_SESSION['_impersonating'] ?? false;
    }

    /**
     * Get the original user (when impersonating)
     *
     * @return array<string, mixed>|null
     */
    public function getOriginalUser(): ?array
    {
        $this->ensureStarted();
        return $_SESSION['_original_user'] ?? null;
    }

    // Redirect helpers

    /**
     * Store intended URL for redirect after login
     *
     * The URL is attacker-influenceable (AuthMiddleware feeds it the request
     * URL), so it is sanitized to a same-origin relative path here — an
     * absolute or protocol-relative URL must never survive the round trip,
     * or the post-login redirect becomes an open redirect.
     */
    public function setIntendedUrl(string $url): void
    {
        $this->set('_intended_url', self::sanitizeRelativeUrl($url));
    }

    /**
     * Reduce a URL to a safe same-origin relative path (+ query)
     *
     * Pure function, public static so other redirect sinks (Controller::back())
     * reuse the same open-redirect defense instead of reimplementing it.
     */
    public static function sanitizeRelativeUrl(string $url): string
    {
        // Absolute URL (scheme:...) — keep only path + query. This runs FIRST
        // so every guard below inspects the final value: parse_url() on
        // 'https://host//evil.com/x' yields '//evil.com/x', so a guard applied
        // only to the input would be bypassed by the reduction.
        if (preg_match('#^[a-z][a-z0-9+.\-]*:#i', $url)) {
            $path = parse_url($url, PHP_URL_PATH);
            $query = parse_url($url, PHP_URL_QUERY);
            $url = (is_string($path) && $path !== '' ? $path : '/')
                . (is_string($query) && $query !== '' ? "?{$query}" : '');
        }

        // Control characters — CR/LF would split a Location header, NUL truncates
        if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
            return '/';
        }

        // Any two leading slashes are protocol-relative (//evil.com), and
        // browsers normalize \ to /, so /\, \/ and \\ are equivalent tricks.
        if (preg_match('#^[/\\\\]{2}#', $url)) {
            return '/';
        }

        if (str_starts_with($url, '\\')) {
            return '/';
        }

        return str_starts_with($url, '/') ? $url : '/';
    }

    /**
     * Get and clear intended URL
     */
    public function pullIntendedUrl(string $default = '/'): string
    {
        $url = $this->get('_intended_url', $default);
        $this->forget('_intended_url');
        return $url;
    }

    /**
     * Ensure session is started
     */
    private function ensureStarted(): void
    {
        if (!$this->started) {
            $this->start();
        }
    }
}
