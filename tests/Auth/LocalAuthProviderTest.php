<?php

declare(strict_types=1);

namespace Tests\Auth;

use KallioMicro\Auth\Providers\LocalAuthProvider;
use Tests\Support\FakeConnection;
use Tests\TestCase;

/**
 * The active_column check was `isset($user[$col]) && !$user[$col]`, which is
 * false for both a missing key and a NULL value — so the check was skipped
 * entirely and a disabled account authenticated. 'N' and 'false' are truthy
 * strings and passed for the same reason. It has to fail closed.
 */
class LocalAuthProviderTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private function providerFor(array $userRow): LocalAuthProvider
    {
        $connection = new FakeConnection();
        $connection->queueSelectOne($userRow);

        return new LocalAuthProvider($connection);
    }

    /**
     * Hashed once for the whole class. At PASSWORD_DEFAULT's cost this is
     * ~180ms a call, and the data providers below would otherwise pay it
     * twenty times over for no added coverage.
     */
    private static ?string $passwordHash = null;

    private function row(mixed $active, bool $includeActive = true): array
    {
        self::$passwordHash ??= password_hash(self::PASSWORD, PASSWORD_DEFAULT);

        $row = [
            'id' => 1,
            'username' => 'bob',
            'password' => self::$passwordHash,
        ];

        if ($includeActive) {
            $row['active'] = $active;
        }

        return $row;
    }

    private function attempt(array $userRow): bool
    {
        return $this->providerFor($userRow)
            ->authenticate(['username' => 'bob', 'password' => self::PASSWORD])
            ->isSuccess();
    }

    /**
     * @dataProvider disabledValues
     */
    public function testDisabledAccountsAreRejected(mixed $active): void
    {
        $this->assertFalse($this->attempt($this->row($active)));
    }

    public static function disabledValues(): array
    {
        return [
            'null'          => [null],
            'zero int'      => [0],
            'zero string'   => ['0'],
            'N flag'        => ['N'],
            'lowercase n'   => ['n'],
            'F flag'        => ['F'],
            'false string'  => ['false'],
            'no'            => ['no'],
            'off'           => ['off'],
            'empty string'  => [''],
        ];
    }

    /**
     * @dataProvider activeValues
     */
    public function testActiveAccountsAreAccepted(mixed $active): void
    {
        $this->assertTrue($this->attempt($this->row($active)));
    }

    public static function activeValues(): array
    {
        return [
            'one int'     => [1],
            'one string'  => ['1'],
            'true bool'   => [true],
            'true string' => ['true'],
            'Y flag'      => ['Y'],
            'lowercase y' => ['y'],
            'T flag'      => ['T'],
            'yes'         => ['yes'],
            'on'          => ['on'],
        ];
    }

    /**
     * A typo'd active_column config, or a SELECT that omitted the column,
     * used to disable the check rather than the account.
     */
    public function testMissingActiveColumnFailsClosed(): void
    {
        $this->assertFalse($this->attempt($this->row(null, includeActive: false)));
    }

    public function testWrongPasswordIsRejectedForAnActiveUser(): void
    {
        $connection = new FakeConnection();
        $connection->queueSelectOne($this->row(1));

        $result = (new LocalAuthProvider($connection))
            ->authenticate(['username' => 'bob', 'password' => 'wrong']);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Invalid credentials', $result->getMessage());
    }

    public function testSuccessfulLoginStripsThePasswordColumn(): void
    {
        $connection = new FakeConnection();
        $connection->queueSelectOne($this->row(1));

        $result = (new LocalAuthProvider($connection))
            ->authenticate(['username' => 'bob', 'password' => self::PASSWORD]);

        $this->assertTrue($result->isSuccess());
        $this->assertArrayNotHasKey('password', $result->getUser());
    }

    /**
     * The unknown-user branch verified against a hardcoded cost-10 bcrypt
     * string while real hashes use PASSWORD_DEFAULT (cost 12 on PHP 8.4):
     * 46ms versus 184ms, a remotely separable user-enumeration oracle. The
     * dummy hash has to track the configured algorithm.
     */
    public function testDummyHashCostMatchesTheConfiguredAlgorithm(): void
    {
        $connection = new FakeConnection();
        $connection->queueSelectOne(null);

        $provider = new LocalAuthProvider($connection);
        $result = $provider->authenticate(['username' => 'ghost', 'password' => self::PASSWORD]);

        $this->assertFalse($result->isSuccess());
        $this->assertSame('Invalid credentials', $result->getMessage());

        $reflection = new \ReflectionMethod($provider, 'dummyHash');
        $dummy = $reflection->invoke($provider);

        $this->assertSame(
            password_get_info(password_hash('x', PASSWORD_DEFAULT))['options'],
            password_get_info($dummy)['options']
        );
        $this->assertSame(
            password_get_info(password_hash('x', PASSWORD_DEFAULT))['algoName'],
            password_get_info($dummy)['algoName']
        );
    }

    /**
     * A disabled account used to return before password_verify ran at all
     * (~0ms), so response time separated disabled / nonexistent /
     * wrong-password even when every message was identical.
     */
    public function testDisabledAccountStillPerformsPasswordVerification(): void
    {
        $connection = new FakeConnection();
        $connection->queueSelectOne($this->row(0));

        $start = hrtime(true);
        $result = (new LocalAuthProvider($connection))
            ->authenticate(['username' => 'bob', 'password' => 'wrong']);
        $elapsedMs = (hrtime(true) - $start) / 1_000_000;

        $this->assertFalse($result->isSuccess());

        // A skipped verify returns in well under a millisecond; a real bcrypt
        // verify at PASSWORD_DEFAULT's cost cannot.
        $this->assertGreaterThan(5.0, $elapsedMs);
    }
}
