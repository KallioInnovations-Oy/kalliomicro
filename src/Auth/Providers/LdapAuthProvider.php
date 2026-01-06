<?php

declare(strict_types=1);

namespace KallioMicro\Auth\Providers;

use KallioMicro\Auth\AuthProviderInterface;
use KallioMicro\Auth\AuthResult;
use RuntimeException;

/**
 * LdapAuthProvider - LDAP/Active Directory authentication
 *
 * Authenticates users against an LDAP server (typically Active Directory).
 * Boilerplate implementation - extend and customize as needed.
 */
class LdapAuthProvider implements AuthProviderInterface
{
    /** @var array<string, mixed> */
    private array $config;

    /** @var resource|null */
    private $connection = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'host' => '',
            'port' => 389,
            'use_ssl' => false,
            'use_tls' => false,
            'base_dn' => '',
            'bind_dn' => '', // Service account DN for searching
            'bind_password' => '',
            'user_filter' => '(sAMAccountName={username})',
            'timeout' => 10,
            'version' => 3,
            // Attribute mapping
            'attributes' => [
                'username' => 'sAMAccountName',
                'email' => 'mail',
                'firstname' => 'givenName',
                'lastname' => 'sn',
                'display_name' => 'displayName',
                'phone' => 'telephoneNumber',
                'department' => 'department',
                'title' => 'title',
                'employee_number' => 'employeeID',
                'manager' => 'manager',
            ],
        ], $config);
    }

    public function getName(): string
    {
        return 'ldap';
    }

    public function authenticate(array $credentials): AuthResult
    {
        if (!extension_loaded('ldap')) {
            return AuthResult::failure('LDAP extension not available');
        }

        $username = $credentials['username'] ?? '';
        $password = $credentials['password'] ?? '';

        if (empty($username) || empty($password)) {
            return AuthResult::failure('Username and password are required');
        }

        try {
            // Connect to LDAP server
            $this->connect();

            // Search for user
            $userEntry = $this->findUser($username);

            if ($userEntry === null) {
                return AuthResult::failure('Invalid credentials');
            }

            // Attempt to bind as user (verify password)
            $userDn = $userEntry['dn'];
            if (!$this->bindAs($userDn, $password)) {
                return AuthResult::failure('Invalid credentials');
            }

            // Map user attributes
            $user = $this->mapUserAttributes($userEntry);
            $user['provider'] = 'ldap';

            return AuthResult::success($user, 'Login successful');

        } catch (\Throwable $e) {
            return AuthResult::failure('LDAP error: ' . $e->getMessage());
        } finally {
            $this->disconnect();
        }
    }

    /**
     * Connect to LDAP server
     */
    private function connect(): void
    {
        $protocol = $this->config['use_ssl'] ? 'ldaps://' : 'ldap://';
        $host = $protocol . $this->config['host'];

        $this->connection = ldap_connect($host, $this->config['port']);

        if ($this->connection === false) {
            throw new RuntimeException('Failed to connect to LDAP server');
        }

        // Set options
        ldap_set_option($this->connection, LDAP_OPT_PROTOCOL_VERSION, $this->config['version']);
        ldap_set_option($this->connection, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($this->connection, LDAP_OPT_NETWORK_TIMEOUT, $this->config['timeout']);

        // Start TLS if configured
        if ($this->config['use_tls']) {
            if (!ldap_start_tls($this->connection)) {
                throw new RuntimeException('Failed to start TLS');
            }
        }

        // Bind with service account for searching
        if (!empty($this->config['bind_dn'])) {
            if (!$this->bindAs($this->config['bind_dn'], $this->config['bind_password'])) {
                throw new RuntimeException('Failed to bind with service account');
            }
        }
    }

    /**
     * Bind to LDAP as specific user
     */
    private function bindAs(string $dn, string $password): bool
    {
        // Suppress warnings, handle errors via return value
        return @ldap_bind($this->connection, $dn, $password);
    }

    /**
     * Find user by username
     *
     * @return array<string, mixed>|null
     */
    private function findUser(string $username): ?array
    {
        $filter = str_replace('{username}', ldap_escape($username, '', LDAP_ESCAPE_FILTER), $this->config['user_filter']);

        $attributes = array_values($this->config['attributes']);

        $search = @ldap_search(
            $this->connection,
            $this->config['base_dn'],
            $filter,
            $attributes
        );

        if ($search === false) {
            return null;
        }

        $entries = ldap_get_entries($this->connection, $search);

        if ($entries['count'] === 0) {
            return null;
        }

        return $this->normalizeEntry($entries[0]);
    }

    /**
     * Normalize LDAP entry (lowercase keys, extract first values)
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function normalizeEntry(array $entry): array
    {
        $normalized = ['dn' => $entry['dn']];

        foreach ($entry as $key => $value) {
            if (is_numeric($key) || $key === 'count' || $key === 'dn') {
                continue;
            }

            $key = strtolower($key);

            if (is_array($value) && isset($value['count'])) {
                $normalized[$key] = $value['count'] > 0 ? $value[0] : null;
            } else {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Map LDAP attributes to user array
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>
     */
    private function mapUserAttributes(array $entry): array
    {
        $user = [];

        foreach ($this->config['attributes'] as $userKey => $ldapAttr) {
            $ldapAttr = strtolower($ldapAttr);
            $user[$userKey] = $entry[$ldapAttr] ?? null;
        }

        return $user;
    }

    /**
     * Disconnect from LDAP server
     */
    private function disconnect(): void
    {
        if ($this->connection !== null) {
            @ldap_unbind($this->connection);
            $this->connection = null;
        }
    }

    /**
     * Get groups for a user DN
     *
     * @return string[]
     */
    public function getUserGroups(string $userDn): array
    {
        try {
            $this->connect();

            $filter = "(member={$userDn})";
            $search = @ldap_search($this->connection, $this->config['base_dn'], $filter, ['cn']);

            if ($search === false) {
                return [];
            }

            $entries = ldap_get_entries($this->connection, $search);
            $groups = [];

            for ($i = 0; $i < $entries['count']; $i++) {
                if (isset($entries[$i]['cn'][0])) {
                    $groups[] = $entries[$i]['cn'][0];
                }
            }

            return $groups;

        } catch (\Throwable $e) {
            return [];
        } finally {
            $this->disconnect();
        }
    }
}
