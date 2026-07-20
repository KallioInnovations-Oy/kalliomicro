<?php

declare(strict_types=1);

namespace KallioMicro\Auth\Providers;

use KallioMicro\Auth\AuthProviderInterface;
use KallioMicro\Auth\OAuthProviderInterface;
use KallioMicro\Auth\AuthResult;
use RuntimeException;

/**
 * EntraIdAuthProvider - Microsoft Entra ID (Azure AD) OAuth2 authentication
 *
 * Implements OAuth2 authorization code flow with PKCE for secure authentication.
 */
class EntraIdAuthProvider implements OAuthProviderInterface
{
    /** @var array<string, mixed> */
    private array $config;

    private const AUTHORIZE_ENDPOINT = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize';
    private const TOKEN_ENDPOINT = 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token';
    private const GRAPH_ENDPOINT = 'https://graph.microsoft.com/v1.0/me';

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'tenant_id' => '',
            'client_id' => '',
            'client_secret' => '',
            'redirect_uri' => '',
            'scopes' => ['openid', 'profile', 'email', 'User.Read'],
        ], $config);

        $this->validateConfig();
    }

    private function validateConfig(): void
    {
        $required = ['tenant_id', 'client_id', 'redirect_uri'];
        foreach ($required as $key) {
            if (empty($this->config[$key])) {
                throw new RuntimeException("Entra ID config missing: {$key}");
            }
        }
    }

    public function getName(): string
    {
        return 'entra';
    }

    public function authenticate(array $credentials): AuthResult
    {
        // For OAuth, redirect to authorization URL
        return AuthResult::redirect($this->getAuthorizationUrl());
    }

    public function getAuthorizationUrl(): string
    {
        // Generate PKCE code verifier and challenge
        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);

        // Store code verifier in session for token exchange
        $_SESSION['_oauth_code_verifier'] = $codeVerifier;

        // Generate state for CSRF protection
        $state = bin2hex(random_bytes(16));
        $_SESSION['_oauth_state'] = $state;

        $params = [
            'client_id' => $this->config['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => implode(' ', $this->config['scopes']),
            'response_mode' => 'query',
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
        ];

        $url = str_replace('{tenant}', $this->config['tenant_id'], self::AUTHORIZE_ENDPOINT);
        return $url . '?' . http_build_query($params);
    }

    public function handleCallback(array $params): AuthResult
    {
        // Verify state. The expected value is consumed up front — before the
        // comparison and regardless of outcome — so a state is single-use and
        // survives no failed attempt.
        $state = is_string($params['state'] ?? null) ? $params['state'] : '';
        $expectedState = is_string($_SESSION['_oauth_state'] ?? null) ? $_SESSION['_oauth_state'] : '';
        unset($_SESSION['_oauth_state']);

        // Both sides must be non-empty: hash_equals('', '') is true, so a
        // victim who never started a flow has no _oauth_state and a callback
        // carrying an empty state would otherwise pass. PKCE currently masks
        // this here (the exchange fails without a verifier) — the state check
        // must stand on its own regardless.
        if ($expectedState === '' || $state === '' || !hash_equals($expectedState, $state)) {
            return AuthResult::failure('Invalid state parameter');
        }

        // Check for errors
        if (isset($params['error'])) {
            $error = $params['error_description'] ?? $params['error'];
            return AuthResult::failure("OAuth error: {$error}");
        }

        // Exchange code for tokens
        $code = $params['code'] ?? '';
        if (empty($code)) {
            return AuthResult::failure('No authorization code received');
        }

        // An error response from the token endpoint still decodes to an array,
        // so a null check alone lets a token-less payload through to a
        // string-typed getUserInfo() and turns a failed login into a TypeError.
        $tokens = $this->exchangeCodeForTokens($code);
        if ($tokens === null || !is_string($tokens['access_token'] ?? null)) {
            return AuthResult::failure('Failed to exchange code for tokens');
        }

        // Get user info from Microsoft Graph
        $userInfo = $this->getUserInfo($tokens['access_token']);
        if ($userInfo === null) {
            return AuthResult::failure('Failed to get user information');
        }

        // Clean up session (state was already consumed above)
        unset($_SESSION['_oauth_code_verifier']);

        // Map to user array
        $user = $this->mapUserInfo($userInfo, $tokens);

        return AuthResult::success($user, 'Login successful');
    }

    /**
     * Exchange authorization code for tokens
     *
     * @return array<string, mixed>|null
     */
    private function exchangeCodeForTokens(string $code): ?array
    {
        $codeVerifier = $_SESSION['_oauth_code_verifier'] ?? '';

        $params = [
            'client_id' => $this->config['client_id'],
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $this->config['redirect_uri'],
            'code_verifier' => $codeVerifier,
        ];

        // Include client secret if configured (for confidential clients)
        if (!empty($this->config['client_secret'])) {
            $params['client_secret'] = $this->config['client_secret'];
        }

        $url = str_replace('{tenant}', $this->config['tenant_id'], self::TOKEN_ENDPOINT);

        $response = $this->httpPost($url, $params);

        if ($response === null || isset($response['error'])) {
            return null;
        }

        return $response;
    }

    /**
     * Get user information from Microsoft Graph
     *
     * @return array<string, mixed>|null
     */
    private function getUserInfo(string $accessToken): ?array
    {
        $ch = curl_init(self::GRAPH_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || $response === false) {
            return null;
        }

        return json_decode($response, true);
    }

    /**
     * Map Microsoft Graph user info to application user format
     *
     * @param array<string, mixed> $userInfo
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    private function mapUserInfo(array $userInfo, array $tokens): array
    {
        return [
            'provider' => 'entra',
            'provider_id' => $userInfo['id'] ?? '',
            'email' => $userInfo['mail'] ?? $userInfo['userPrincipalName'] ?? '',
            'username' => explode('@', $userInfo['userPrincipalName'] ?? '')[0],
            'firstname' => $userInfo['givenName'] ?? '',
            'lastname' => $userInfo['surname'] ?? '',
            'display_name' => $userInfo['displayName'] ?? '',
            'job_title' => $userInfo['jobTitle'] ?? '',
            'department' => $userInfo['department'] ?? '',
            'office_location' => $userInfo['officeLocation'] ?? '',
            'phone' => $userInfo['mobilePhone'] ?? $userInfo['businessPhones'][0] ?? '',
            'access_token' => $tokens['access_token'] ?? '',
            'refresh_token' => $tokens['refresh_token'] ?? '',
            'token_expires' => time() + ($tokens['expires_in'] ?? 3600),
        ];
    }

    /**
     * Refresh access token
     *
     * @return array<string, mixed>|null
     */
    public function refreshToken(string $refreshToken): ?array
    {
        $params = [
            'client_id' => $this->config['client_id'],
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'scope' => implode(' ', $this->config['scopes']),
        ];

        if (!empty($this->config['client_secret'])) {
            $params['client_secret'] = $this->config['client_secret'];
        }

        $url = str_replace('{tenant}', $this->config['tenant_id'], self::TOKEN_ENDPOINT);

        return $this->httpPost($url, $params);
    }

    /**
     * Generate PKCE code verifier
     */
    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Generate PKCE code challenge from verifier
     */
    private function generateCodeChallenge(string $codeVerifier): string
    {
        $hash = hash('sha256', $codeVerifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * Make HTTP POST request
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function httpPost(string $url, array $data): ?array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return null;
        }

        return json_decode($response, true);
    }
}
