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
            // Use constant-time comparison even for non-existent users (prevent timing attacks)
            password_verify($password, '$2y$10$dummyhashtopreventtimingattacks');
            return AuthResult::failure('Invalid credentials');
        }

        // Check if user is active
        $activeColumn = $this->config['active_column'];
        if (isset($user[$activeColumn]) && !$user[$activeColumn]) {
            return AuthResult::failure('Account is disabled');
        }

        // Verify password
        $passwordColumn = $this->config['password_column'];
        if (!password_verify($password, $user[$passwordColumn] ?? '')) {
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
