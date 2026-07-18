<?php

declare(strict_types=1);

namespace KallioMicro\Auth;

/**
 * OAuthProviderInterface - Additional contract for OAuth providers
 */
interface OAuthProviderInterface extends AuthProviderInterface
{
    /**
     * Get the authorization URL for OAuth flow
     */
    public function getAuthorizationUrl(): string;

    /**
     * Handle OAuth callback
     *
     * @param array<string, mixed> $params
     */
    public function handleCallback(array $params): AuthResult;
}
