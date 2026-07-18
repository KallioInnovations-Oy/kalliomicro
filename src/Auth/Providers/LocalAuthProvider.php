<?php

declare(strict_types=1);

namespace KallioMicro\Auth\Providers;

use KallioMicro\Auth\AuthProviderInterface;
use KallioMicro\Auth\AuthResult;
use KallioMicro\Database\Connection;

/**
 * LocalAuthProvider - Database-based username/password authentication
 *
 * Authenticates users against the local database using secure password hashing.
 */
class LocalAuthProvider implements AuthProviderInterface
{
    private ?Connection $db;

    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(?Connection $db, array $config = [])
    {
        $this->db = $db;
        $this->config = array_merge([
            'table' => 'core_users',
            'username_column' => 'username',
            'password_column' => 'password',
            'active_column' => 'active',
            'hash_algo' => PASSWORD_DEFAULT,
        ], $config);
    }

    public function getName(): string
    {
        return 'local';
    }

    public function authenticate(array $credentials): AuthResult
    {
        if ($this->db === null) {
            return AuthResult::failure('Database not configured');
        }

        $username = $credentials['username'] ?? '';
        $password = $credentials['password'] ?? '';

        if (empty($username) || empty($password)) {
            return AuthResult::failure('Username and password are required');
        }

        // Find user by username
        $user = $this->findUser($username);

        if ($user === null) {
            // Burn the same work a real verify costs, so response time does not
            // reveal whether the account exists. The hash must be generated
            // from the configured algorithm: a hardcoded cost-10 bcrypt string
            // against PASSWORD_DEFAULT's cost 12 measured 46ms vs 184ms here,
            // which is a trivially separable remote oracle.
            password_verify($password, $this->dummyHash());
            return AuthResult::failure('Invalid credentials');
        }

        // Verify password BEFORE the active check, so a disabled account costs
        // the same as a wrong password. Returning early on inactive skipped
        // password_verify entirely (~0ms), separating disabled / nonexistent /
        // wrong-password by response time alone.
        $passwordColumn = $this->config['password_column'];
        $passwordValid = password_verify($password, (string) ($user[$passwordColumn] ?? ''));

        // Fails CLOSED: a missing key or a null value used to make isset() false
        // and skip the check entirely, authenticating a disabled account. 'N'
        // and 'false' are truthy strings and passed for the same reason.
        $activeColumn = $this->config['active_column'];
        if (!$this->isActive($user, $activeColumn)) {
            return AuthResult::failure('Account is disabled');
        }

        if (!$passwordValid) {
            return AuthResult::failure('Invalid credentials');
        }

        // Check if password needs rehashing
        if (password_needs_rehash($user[$passwordColumn], $this->config['hash_algo'])) {
            $this->rehashPassword($user['id'], $password);
        }

        // Remove sensitive data before returning
        unset($user[$passwordColumn]);

        return AuthResult::success($user, 'Login successful');
    }

    /**
     * Decide whether a user row is active
     *
     * Fails closed on absence: if the configured active_column is not present
     * in the row at all — a typo'd config name, or a SELECT that omitted it —
     * the account is treated as disabled rather than silently unchecked.
     * Values are interpreted as booleans. Single-letter Y/N and T/F flags are
     * handled explicitly because FILTER_VALIDATE_BOOLEAN does not know them
     * and would read a perfectly active 'Y' as disabled; beyond those, the
     * usual 1/true/on/yes spellings mean active and everything else — 0, '0',
     * 'N', 'false', '' and NULL — means disabled.
     *
     * @param array<string, mixed> $user
     */
    private function isActive(array $user, string $activeColumn): bool
    {
        if (!array_key_exists($activeColumn, $user)) {
            return false;
        }

        $value = $user[$activeColumn];

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            if ($normalized === 'y' || $normalized === 't') {
                return true;
            }

            if ($normalized === 'n' || $normalized === 'f') {
                return false;
            }
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN) === true;
    }

    /**
     * A throwaway hash matching the configured algorithm and cost
     *
     * Generated once per process. The cost must track the real one or the
     * unknown-user branch is measurably cheaper than a genuine verify.
     */
    private function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash('', $this->config['hash_algo']);
    }

    /**
     * Find user by username
     *
     * @return array<string, mixed>|null
     */
    private function findUser(string $username): ?array
    {
        $table = $this->config['table'];
        $usernameColumn = $this->config['username_column'];

        return $this->db->selectOne(
            "SELECT * FROM {$table} WHERE {$usernameColumn} = :username LIMIT 1",
            ['username' => $username]
        );
    }

    /**
     * Rehash password with current algorithm
     */
    private function rehashPassword(int $userId, string $password): void
    {
        $table = $this->config['table'];
        $passwordColumn = $this->config['password_column'];

        $newHash = password_hash($password, $this->config['hash_algo']);

        $this->db->update($table, [$passwordColumn => $newHash], ['id' => $userId]);
    }

    /**
     * Hash a password
     */
    public function hashPassword(string $password): string
    {
        return password_hash($password, $this->config['hash_algo']);
    }

    /**
     * Verify a password against a hash
     */
    public function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }
}
