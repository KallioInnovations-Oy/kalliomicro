<?php

declare(strict_types=1);

namespace Tests\Auth;

use KallioMicro\Auth\Providers\EntraIdAuthProvider;
use KallioMicro\Auth\Providers\GoogleAuthProvider;
use Tests\TestCase;

/**
 * hash_equals('', '') is true. A victim who never started an OAuth flow has no
 * _oauth_state, so a callback carrying an empty state passed the CSRF check —
 * letting an attacker plant their own authorization code and log the victim's
 * browser into the attacker's account.
 *
 * Entra escaped only incidentally (PKCE fails the exchange without a stored
 * verifier). The state check has to stand on its own in both providers.
 */
class OAuthStateTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    private function google(): GoogleAuthProvider
    {
        return new GoogleAuthProvider([
            'client_id' => 'cid',
            'client_secret' => 'secret',
            'redirect_uri' => 'https://app.example/callback',
        ]);
    }

    private function entra(): EntraIdAuthProvider
    {
        return new EntraIdAuthProvider([
            'tenant_id' => 'tid',
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/callback',
        ]);
    }

    /**
     * @dataProvider providers
     */
    public function testEmptyStateWithNoFlowStartedIsRejected(string $provider): void
    {
        $result = $this->{$provider}()->handleCallback(['code' => 'attacker_code', 'state' => '']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Invalid state parameter', $result->getMessage());
    }

    /**
     * @dataProvider providers
     */
    public function testMissingStateParameterIsRejected(string $provider): void
    {
        $result = $this->{$provider}()->handleCallback(['code' => 'attacker_code']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Invalid state parameter', $result->getMessage());
    }

    /**
     * @dataProvider providers
     */
    public function testStoredStateWithEmptyCallbackStateIsRejected(string $provider): void
    {
        $_SESSION['_oauth_state'] = 'expected-value';

        $result = $this->{$provider}()->handleCallback(['code' => 'attacker_code', 'state' => '']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Invalid state parameter', $result->getMessage());
    }

    /**
     * @dataProvider providers
     */
    public function testNonStringStateIsRejectedRatherThanRaising(string $provider): void
    {
        $_SESSION['_oauth_state'] = 'expected-value';

        $result = $this->{$provider}()->handleCallback(['state' => ['array'], 'code' => 'c']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Invalid state parameter', $result->getMessage());
    }

    /**
     * The state is consumed before the comparison, so it cannot be replayed
     * after a failed attempt — previously it was only unset on the success
     * path and survived every early return.
     */
    public function testStateIsConsumedEvenWhenTheCallbackFailsLater(): void
    {
        $_SESSION['_oauth_state'] = 'expected-value';

        // Matching state, but no code — fails after the state check.
        $this->google()->handleCallback(['state' => 'expected-value']);

        $this->assertArrayNotHasKey('_oauth_state', $_SESSION);

        $replay = $this->google()->handleCallback(['state' => 'expected-value', 'code' => 'c']);
        $this->assertFalse($replay->isSuccess());
        $this->assertSame('Invalid state parameter', $replay->getMessage());
    }

    public static function providers(): array
    {
        return [
            'google' => ['google'],
            'entra' => ['entra'],
        ];
    }
}
