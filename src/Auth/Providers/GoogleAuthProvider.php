<?php

declare(strict_types=1);

namespace KallioMicro\Auth\Providers;

use KallioMicro\Auth\AuthProviderInterface;
use KallioMicro\Auth\OAuthProviderInterface;
use KallioMicro\Auth\AuthResult;
use RuntimeException;

/**
 * GoogleAuthProvider - Google OAuth2 authentication
 *
 * Boilerplate implementation for Google Sign-In.
 * Configure in auth.providers.google config.
 */
class GoogleAuthProvider implements OAuthProviderInterface
{
    /** @var array<string, mixed> */
    private array $config;

    private const AUTHORIZE_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const USERINFO_ENDPOINT = 'https://www.googleapis.com/oauth2/v2/userinfo';

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'client_id' => '',
            'client_secret' => '',
            'redirect_uri' => '',
            'scopes' => ['openid', 'email', 'profile'],
            'hosted_domain' => '', // Restrict to specific Google Workspace domain
        ], $config);
    }

    public function getName(): string
    {
        return 'google';
    }

    public function authenticate(array $credentials): AuthResult
    {
        // For OAuth, redirect to authorization URL
        return AuthResult::redirect($this->getAuthorizationUrl());
    }

    public function getAuthorizationUrl(): string
    {
        // Generate state for CSRF protection
        $state = bin2hex(random_bytes(16));
        $_SESSION['_oauth_state'] = $state;

        $params = [
            'client_id' => $this->config['client_id'],
            'response_type' => 'code',
            'redirect_uri' => $this->config['redirect_uri'],
            'scope' => implode(' ', $this->config['scopes']),
            'state' => $state,
            'access_type' => 'offline', // Get refresh token
            'prompt' => 'select_account', // Always show account selector
        ];

        // Restrict to specific domain if configured
        if (!empty($this->config['hosted_domain'])) {
            $params['hd'] = $this->config['hosted_domain'];
        }

        return self::AUTHORIZE_ENDPOINT . '?' . http_build_query($params);
    }

    public function handleCallback(array $params): AuthResult
    {
        // Verify state
        $state = $params['state'] ?? '';
        $expectedState = $_SESSION['_oauth_state'] ?? '';

        if (!hash_equals($expectedState, $state)) {
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

        $tokens = $this->exchangeCodeForTokens($code);
        if ($tokens === null) {
            return AuthResult::failure('Failed to exchange code for tokens');
        }

        // Get user info
        $userInfo = $this->getUserInfo($tokens['access_token']);
        if ($userInfo === null) {
            return AuthResult::failure('Failed to get user information');
        }

        // Verify hosted domain if configured
        if (!empty($this->config['hosted_domain'])) {
            $domain = $userInfo['hd'] ?? '';
            if ($domain !== $this->config['hosted_domain']) {
                return AuthResult::failure('Invalid email domain');
            }
        }

        // Clean up session
        unset($_SESSION['_oauth_state']);

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
        $params = [
            'client_id' => $this->config['client_id'],
            'client_secret' => $this->config['client_secret'],
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->config['redirect_uri'],
        ];

        return $this->httpPost(self::TOKEN_ENDPOINT, $params);
    }

    /**
     * Get user information from Google
     *
     * @return array<string, mixed>|null
     */
    private function getUserInfo(string $accessToken): ?array
    {
        $ch = curl_init(self::USERINFO_ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $accessToken,
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
     * Map Google user info to application user format
     *
     * @param array<string, mixed> $userInfo
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    private function mapUserInfo(array $userInfo, array $tokens): array
    {
        return [
            'provider' => 'google',
            'provider_id' => $userInfo['id'] ?? '',
            'email' => $userInfo['email'] ?? '',
            'username' => explode('@', $userInfo['email'] ?? '')[0],
            'firstname' => $userInfo['given_name'] ?? '',
            'lastname' => $userInfo['family_name'] ?? '',
            'display_name' => $userInfo['name'] ?? '',
            'picture' => $userInfo['picture'] ?? '',
            'locale' => $userInfo['locale'] ?? '',
            'verified_email' => $userInfo['verified_email'] ?? false,
            'hosted_domain' => $userInfo['hd'] ?? '',
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
            'client_secret' => $this->config['client_secret'],
            'refresh_token' => $refreshToken,
            'grant_type' => 'refresh_token',
        ];

        return $this->httpPost(self::TOKEN_ENDPOINT, $params);
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
