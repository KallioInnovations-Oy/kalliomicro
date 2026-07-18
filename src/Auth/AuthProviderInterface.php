<?php

declare(strict_types=1);

namespace KallioMicro\Auth;

/**
 * AuthProviderInterface - Contract for authentication providers
 */
interface AuthProviderInterface
{
    /**
     * Attempt to authenticate a user
     *
     * @param array<string, mixed> $credentials
     */
    public function authenticate(array $credentials): AuthResult;

    /**
     * Get provider name
     */
    public function getName(): string;
}
