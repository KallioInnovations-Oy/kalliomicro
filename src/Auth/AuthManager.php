<?php

declare(strict_types=1);

namespace KallioMicro\Auth;

use KallioMicro\Core\Config;
use KallioMicro\Database\Connection;
use KallioMicro\Auth\Providers\LocalAuthProvider;
use KallioMicro\Auth\Providers\EntraIdAuthProvider;
use KallioMicro\Auth\Providers\LdapAuthProvider;
use KallioMicro\Auth\Providers\GoogleAuthProvider;
use RuntimeException;

/**
 * AuthManager - Manages authentication providers
 *
 * Supports multiple authentication methods:
 * - Local (database username/password)
 * - Entra ID (Microsoft Azure AD / OAuth2)
 * - LDAP (Active Directory)
 * - Google (OAuth2 - boilerplate)
 */
class AuthManager
{
    private Config $config;
    private Session $session;
    private ?Connection $db;

    /** @var array<string, AuthProviderInterface> */
    private array $providers = [];

    private string $defaultProvider;

    public function __construct(Config $config, Session $session, ?Connection $db = null)
    {
        $this->config = $config;
        $this->session = $session;
        $this->db = $db;
        $this->defaultProvider = $config->get('auth.default', 'local');
    }

    /**
     * Register an authentication provider
     */
    public function registerProvider(string $name, AuthProviderInterface $provider): void
    {
        $this->providers[$name] = $provider;
    }

    /**
     * Get a provider by name
     */
    public function provider(string $name): AuthProviderInterface
    {
        if (!isset($this->providers[$name])) {
            $this->providers[$name] = $this->createProvider($name);
        }

        return $this->providers[$name];
    }

    /**
     * Create a provider instance
     */
    private function createProvider(string $name): AuthProviderInterface
    {
        $config = $this->config->get("auth.providers.{$name}", []);

        return match ($name) {
            'local' => new LocalAuthProvider($this->db, $config),
            'entra' => new EntraIdAuthProvider($config),
            'ldap' => new LdapAuthProvider($config),
            'google' => new GoogleAuthProvider($config),
            default => throw new RuntimeException("Unknown auth provider: {$name}"),
        };
    }

    /**
     * Attempt authentication with the default provider
     *
     * @param array<string, mixed> $credentials
     */
    public function attempt(array $credentials): AuthResult
    {
        return $this->attemptWith($this->defaultProvider, $credentials);
    }

    /**
     * Attempt authentication with a specific provider
     *
     * Policy note: the base framework ships NO login throttling — there is no
     * attempt counter or lockout here. A deployment exposing a login form
     * publicly wraps this call with its own rate limiting (attempt table
     * keyed on ip+username, or infrastructure-level controls).
     *
     * @param array<string, mixed> $credentials
     */
    public function attemptWith(string $provider, array $credentials): AuthResult
    {
        $result = $this->provider($provider)->authenticate($credentials);

        if ($result->isSuccess()) {
            $this->session->login($result->getUser());
        }

        return $result;
    }

    /**
     * Log out the current user
     */
    public function logout(): void
    {
        $this->session->logout();
    }

    /**
     * Check if user is authenticated
     */
    public function check(): bool
    {
        return $this->session->isAuthenticated();
    }

    /**
     * Get the authenticated user
     *
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        return $this->session->getUser();
    }

    /**
     * Get user ID
     */
    public function id(): ?int
    {
        return $this->session->getUserId();
    }

    /**
     * Get OAuth authorization URL for a provider
     */
    public function getAuthorizationUrl(string $provider): string
    {
        $authProvider = $this->provider($provider);

        if (!$authProvider instanceof OAuthProviderInterface) {
            throw new RuntimeException("Provider {$provider} does not support OAuth");
        }

        return $authProvider->getAuthorizationUrl();
    }

    /**
     * Handle OAuth callback
     *
     * Policy note: on success this logs the PROVIDER's user straight into the
     * session. If your deployment requires pre-provisioned accounts (SSO
     * users must already exist locally), do not use this default flow as-is —
     * resolve the provider identity to a local user record in your auth
     * controller and call Session::login() with the LOCAL user only, so a
     * tenant-valid but un-provisioned identity never gets a session.
     *
     * @param array<string, mixed> $params
     */
    public function handleOAuthCallback(string $provider, array $params): AuthResult
    {
        $authProvider = $this->provider($provider);

        if (!$authProvider instanceof OAuthProviderInterface) {
            throw new RuntimeException("Provider {$provider} does not support OAuth");
        }

        $result = $authProvider->handleCallback($params);

        if ($result->isSuccess()) {
            $this->session->login($result->getUser());
        }

        return $result;
    }
}
