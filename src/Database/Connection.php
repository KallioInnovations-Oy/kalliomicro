<?php

declare(strict_types=1);

namespace KallioMicro\Database;

use InvalidArgumentException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

/**
 * Connection - Database connection wrapper using PDO
 *
 * Provides a secure, prepared-statement-based interface to the database.
 * All queries use parameter binding to prevent SQL injection.
 */
class Connection
{
    private ?PDO $pdo = null;

    /** @var array<string, mixed> */
    private array $config;

    private ?PDOStatement $lastStatement = null;

    public function __construct(array $config)
    {
        $this->config = array_merge([
            'driver' => 'mysql',
            'host' => 'localhost',
            'port' => 3306,
            'database' => '',
            'username' => '',
            'password' => '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'options' => [],
        ], $config);
    }

    /**
     * Get the PDO instance, connecting if necessary
     */
    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            $this->connect();
        }

        return $this->pdo;
    }

    /**
     * Establish the database connection
     */
    private function connect(): void
    {
        $dsn = $this->buildDsn();

        $options = array_merge([
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ], $this->config['options']);

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->config['username'],
                $this->config['password'],
                $options
            );

            // Set charset for MySQL
            if ($this->config['driver'] === 'mysql') {
                $charset = $this->config['charset'];
                $collation = $this->config['collation'];
                $this->pdo->exec("SET NAMES '{$charset}' COLLATE '{$collation}'");
            }
        } catch (PDOException $e) {
            throw new RuntimeException(
                "Database connection failed: " . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }
    }

    /**
     * Build the DSN string based on driver
     */
    private function buildDsn(): string
    {
        $driver = $this->config['driver'];

        return match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['database'],
                $this->config['charset']
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $this->config['host'],
                $this->config['port'],
                $this->config['database']
            ),
            'sqlite' => sprintf('sqlite:%s', $this->config['database']),
            default => throw new RuntimeException("Unsupported database driver: {$driver}"),
        };
    }

    /**
     * Execute a raw query with parameter binding
     *
     * @param array<string, mixed>|array<int, mixed> $bindings
     */
    public function query(string $sql, array $bindings = []): PDOStatement
    {
        $statement = $this->getPdo()->prepare($sql);
        $this->bindValues($statement, $bindings);
        $statement->execute();
        $this->lastStatement = $statement;

        return $statement;
    }

    /**
     * Execute a SELECT query and return all results
     *
     * @param array<string, mixed>|array<int, mixed> $bindings
     * @return array<int, array<string, mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->query($sql, $bindings)->fetchAll();
    }

    /**
     * Execute a SELECT query and return the first row
     *
     * @param array<string, mixed>|array<int, mixed> $bindings
     * @return array<string, mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $result = $this->query($sql, $bindings)->fetch();
        return $result === false ? null : $result;
    }

    /**
     * Execute a SELECT query and return a single column value
     *
     * @param array<string, mixed>|array<int, mixed> $bindings
     */
    public function selectValue(string $sql, array $bindings = []): mixed
    {
        $result = $this->query($sql, $bindings)->fetchColumn();
        return $result === false ? null : $result;
    }

    /**
     * Execute an INSERT statement
     *
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ":{$col}", $columns);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders)
        );

        $this->query($sql, $data);

        return (int) $this->getPdo()->lastInsertId();
    }

    /**
     * Execute an INSERT or UPDATE on duplicate key
     *
     * @param array<string, mixed> $data
     */
    public function upsert(string $table, array $data, array $updateColumns = []): int
    {
        $columns = array_keys($data);
        $placeholders = array_map(fn($col) => ":{$col}", $columns);

        if (empty($updateColumns)) {
            $updateColumns = $columns;
        }

        $updateParts = array_map(
            fn($col) => sprintf('%s = VALUES(%s)', $this->quoteIdentifier($col), $this->quoteIdentifier($col)),
            $updateColumns
        );

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s',
            $this->quoteIdentifier($table),
            implode(', ', array_map([$this, 'quoteIdentifier'], $columns)),
            implode(', ', $placeholders),
            implode(', ', $updateParts)
        );

        $this->query($sql, $data);

        return (int) $this->getPdo()->lastInsertId();
    }

    /**
     * Execute an UPDATE statement
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $where
     */
    public function update(string $table, array $data, array $where): int
    {
        if ($where === []) {
            throw new RuntimeException(
                'Refusing to UPDATE without conditions; use query() with explicit SQL to deliberately affect all rows.'
            );
        }

        $setParts = [];
        $bindings = [];

        foreach ($data as $column => $value) {
            $placeholder = "set_{$column}";
            $setParts[] = sprintf('%s = :%s', $this->quoteIdentifier($column), $placeholder);
            $bindings[$placeholder] = $value;
        }

        $whereParts = [];
        foreach ($where as $column => $value) {
            $placeholder = "where_{$column}";
            $whereParts[] = sprintf('%s = :%s', $this->quoteIdentifier($column), $placeholder);
            $bindings[$placeholder] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(', ', $setParts),
            implode(' AND ', $whereParts)
        );

        $this->query($sql, $bindings);

        return $this->lastStatement->rowCount();
    }

    /**
     * Execute a DELETE statement
     *
     * @param array<string, mixed> $where
     */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new RuntimeException(
                'Refusing to DELETE without conditions; use query() with explicit SQL to deliberately affect all rows.'
            );
        }

        $whereParts = [];
        $bindings = [];

        foreach ($where as $column => $value) {
            $whereParts[] = sprintf('%s = :%s', $this->quoteIdentifier($column), $column);
            $bindings[$column] = $value;
        }

        $sql = sprintf(
            'DELETE FROM %s WHERE %s',
            $this->quoteIdentifier($table),
            implode(' AND ', $whereParts)
        );

        $this->query($sql, $bindings);

        return $this->lastStatement->rowCount();
    }

    /**
     * Start a new query builder for a table
     */
    public function table(string $table): QueryBuilder
    {
        return new QueryBuilder($this, $table);
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): bool
    {
        return $this->getPdo()->beginTransaction();
    }

    /**
     * Commit the current transaction
     */
    public function commit(): bool
    {
        return $this->getPdo()->commit();
    }

    /**
     * Rollback the current transaction
     */
    public function rollback(): bool
    {
        return $this->getPdo()->rollBack();
    }

    /**
     * Execute a callback within a transaction
     *
     * Dispatches through $this->beginTransaction()/commit()/rollback() rather
     * than reaching for the PDO handle directly. That indirection is CONTRACT,
     * not incidental: it is what lets a Connection subclass implement savepoint
     * nesting (a named construction seam in docs/conventions.md). Inlining
     * $this->getPdo()->beginTransaction() here would silently break every such
     * subclass.
     *
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            // A failing commit() leaves no active transaction, so rollback()
            // throws its own "no active transaction" — which would REPLACE the
            // real cause and report the wrong failure. Keep the original.
            try {
                $this->rollback();
            } catch (\Throwable $rollbackFailure) {
                throw new RuntimeException(
                    'Transaction failed and could not be rolled back: ' . $e->getMessage()
                    . ' (rollback also failed: ' . $rollbackFailure->getMessage() . ')',
                    (int) $e->getCode(),
                    $e
                );
            }

            throw $e;
        }
    }

    /**
     * Get the last insert ID
     */
    public function lastInsertId(): int
    {
        return (int) $this->getPdo()->lastInsertId();
    }

    /**
     * Get the number of affected rows from the last statement
     */
    public function affectedRows(): int
    {
        return $this->lastStatement?->rowCount() ?? 0;
    }

    /**
     * Quote an identifier (table/column name)
     *
     * This is a quoting mechanism, not a validator — callers still validate
     * shape first (QueryBuilder::validateIdentifier()). It fails closed: an
     * identifier it cannot safely quote raises rather than passing through.
     *
     * @throws InvalidArgumentException when the identifier cannot be quoted
     */
    public function quoteIdentifier(string $identifier): string
    {
        // The wildcard is not an identifier and must not be backticked —
        // `*` means a column literally named '*' and is never what was meant.
        if ($identifier === '*') {
            return '*';
        }

        // Handle table.column notation (including table.*)
        if (str_contains($identifier, '.')) {
            return implode('.', array_map([$this, 'quoteIdentifier'], explode('.', $identifier)));
        }

        // Previously these were returned unquoted AND unescaped to accommodate
        // aliases, which made the method an injection passthrough for exactly
        // the inputs that matter. Aliases belong in a RawExpression.
        if (str_contains($identifier, '`') || str_contains($identifier, ' ')) {
            throw new InvalidArgumentException(
                "Cannot quote identifier containing a backtick or space: {$identifier}. "
                . 'Use RawExpression for aliases and expressions.'
            );
        }

        return '`' . $identifier . '`';
    }

    /**
     * Bind values to a prepared statement
     *
     * @param array<string, mixed>|array<int, mixed> $bindings
     */
    private function bindValues(PDOStatement $statement, array $bindings): void
    {
        foreach ($bindings as $key => $value) {
            $param = is_int($key) ? $key + 1 : ":{$key}";

            // An array or object binding used to reach PDO and stringify to
            // the literal 'Array' (with a notice), writing that into the
            // column. Almost always a forgotten whereIn() or a mis-shaped
            // payload — say so rather than persisting nonsense.
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException(sprintf(
                    'Binding [%s] must be scalar or null, %s given. '
                    . 'Use whereIn() for a list of values.',
                    is_int($key) ? "#{$param}" : $key,
                    get_debug_type($value)
                ));
            }

            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                is_null($value) => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };

            $statement->bindValue($param, $value, $type);
        }
    }

    /**
     * Close the connection
     */
    public function disconnect(): void
    {
        $this->pdo = null;
    }

    /**
     * Check if connected
     */
    public function isConnected(): bool
    {
        return $this->pdo !== null;
    }

    /**
     * Get the table prefix
     */
    public function getPrefix(): string
    {
        return $this->config['prefix'];
    }

    /**
     * Get the database name
     */
    public function getDatabaseName(): string
    {
        return $this->config['database'];
    }
}
