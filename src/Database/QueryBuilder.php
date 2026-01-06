<?php

declare(strict_types=1);

namespace KallioMicro\Database;

use InvalidArgumentException;

/**
 * QueryBuilder - Fluent SQL query builder
 *
 * Provides a clean, chainable API for building SQL queries
 * with automatic parameter binding for security.
 */
class QueryBuilder
{
    private Connection $connection;
    private string $table;

    /** @var string[] */
    private array $columns = ['*'];

    /** @var array<int, array{type: string, table: string, first: string, operator: string, second: string}> */
    private array $joins = [];

    /** @var array<int, array{type: string, column: string, operator: string, value: mixed, boolean: string}> */
    private array $wheres = [];

    /** @var array<string, mixed> */
    private array $bindings = [];

    private int $bindingIndex = 0;

    /** @var string[] */
    private array $groupBy = [];

    /** @var array<int, array{column: string, direction: string}> */
    private array $orderBy = [];

    private ?int $limitValue = null;
    private ?int $offsetValue = null;

    private bool $distinct = false;

    public function __construct(Connection $connection, string $table)
    {
        $this->connection = $connection;
        $this->table = $table;
    }

    /**
     * Set the columns to select
     *
     * @param string|string[] $columns
     */
    public function select(string|array $columns = ['*']): self
    {
        $this->columns = is_array($columns) ? $columns : func_get_args();
        return $this;
    }

    /**
     * Add columns to select
     */
    public function addSelect(string ...$columns): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }
        $this->columns = array_merge($this->columns, $columns);
        return $this;
    }

    /**
     * Select distinct rows
     */
    public function distinct(): self
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Add a JOIN clause
     */
    public function join(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('INNER', $table, $first, $operator, $second);
    }

    /**
     * Add a LEFT JOIN clause
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('LEFT', $table, $first, $operator, $second);
    }

    /**
     * Add a RIGHT JOIN clause
     */
    public function rightJoin(string $table, string $first, string $operator, string $second): self
    {
        return $this->addJoin('RIGHT', $table, $first, $operator, $second);
    }

    private function addJoin(string $type, string $table, string $first, string $operator, string $second): self
    {
        $this->joins[] = [
            'type' => $type,
            'table' => $table,
            'first' => $first,
            'operator' => $operator,
            'second' => $second,
        ];
        return $this;
    }

    /**
     * Add a WHERE clause
     */
    public function where(string $column, mixed $operatorOrValue = null, mixed $value = null): self
    {
        return $this->addWhere('basic', $column, $operatorOrValue, $value, 'AND');
    }

    /**
     * Add an OR WHERE clause
     */
    public function orWhere(string $column, mixed $operatorOrValue = null, mixed $value = null): self
    {
        return $this->addWhere('basic', $column, $operatorOrValue, $value, 'OR');
    }

    /**
     * Add a WHERE IN clause
     */
    public function whereIn(string $column, array $values): self
    {
        return $this->addWhereIn($column, $values, 'AND', false);
    }

    /**
     * Add a WHERE NOT IN clause
     */
    public function whereNotIn(string $column, array $values): self
    {
        return $this->addWhereIn($column, $values, 'AND', true);
    }

    /**
     * Add a WHERE NULL clause
     */
    public function whereNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'null',
            'column' => $column,
            'operator' => 'IS',
            'value' => null,
            'boolean' => 'AND',
        ];
        return $this;
    }

    /**
     * Add a WHERE NOT NULL clause
     */
    public function whereNotNull(string $column): self
    {
        $this->wheres[] = [
            'type' => 'notNull',
            'column' => $column,
            'operator' => 'IS NOT',
            'value' => null,
            'boolean' => 'AND',
        ];
        return $this;
    }

    /**
     * Add a WHERE BETWEEN clause
     */
    public function whereBetween(string $column, mixed $min, mixed $max): self
    {
        $minKey = $this->addBinding($min);
        $maxKey = $this->addBinding($max);

        $this->wheres[] = [
            'type' => 'between',
            'column' => $column,
            'operator' => 'BETWEEN',
            'value' => [$minKey, $maxKey],
            'boolean' => 'AND',
        ];
        return $this;
    }

    /**
     * Add a WHERE LIKE clause
     */
    public function whereLike(string $column, string $value): self
    {
        return $this->where($column, 'LIKE', $value);
    }

    /**
     * Add a raw WHERE clause
     */
    public function whereRaw(string $sql, array $bindings = []): self
    {
        foreach ($bindings as $value) {
            $this->addBinding($value);
        }

        $this->wheres[] = [
            'type' => 'raw',
            'column' => $sql,
            'operator' => '',
            'value' => null,
            'boolean' => 'AND',
        ];
        return $this;
    }

    private function addWhere(string $type, string $column, mixed $operatorOrValue, mixed $value, string $boolean): self
    {
        // Handle where('column', 'value') shorthand for equality
        if ($value === null && $operatorOrValue !== null) {
            $value = $operatorOrValue;
            $operatorOrValue = '=';
        }

        $bindingKey = $this->addBinding($value);

        $this->wheres[] = [
            'type' => $type,
            'column' => $column,
            'operator' => $operatorOrValue,
            'value' => $bindingKey,
            'boolean' => $boolean,
        ];

        return $this;
    }

    private function addWhereIn(string $column, array $values, string $boolean, bool $not): self
    {
        $placeholders = [];
        foreach ($values as $value) {
            $placeholders[] = $this->addBinding($value);
        }

        $this->wheres[] = [
            'type' => $not ? 'notIn' : 'in',
            'column' => $column,
            'operator' => $not ? 'NOT IN' : 'IN',
            'value' => $placeholders,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Add a GROUP BY clause
     */
    public function groupBy(string ...$columns): self
    {
        $this->groupBy = array_merge($this->groupBy, $columns);
        return $this;
    }

    /**
     * Add an ORDER BY clause
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction);
        if (!in_array($direction, ['ASC', 'DESC'])) {
            throw new InvalidArgumentException('Order direction must be ASC or DESC');
        }

        $this->orderBy[] = [
            'column' => $column,
            'direction' => $direction,
        ];
        return $this;
    }

    /**
     * Order by descending
     */
    public function orderByDesc(string $column): self
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Order by latest (created_at DESC)
     */
    public function latest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'DESC');
    }

    /**
     * Order by oldest (created_at ASC)
     */
    public function oldest(string $column = 'created_at'): self
    {
        return $this->orderBy($column, 'ASC');
    }

    /**
     * Set the LIMIT
     */
    public function limit(int $limit): self
    {
        $this->limitValue = $limit;
        return $this;
    }

    /**
     * Alias for limit
     */
    public function take(int $count): self
    {
        return $this->limit($count);
    }

    /**
     * Set the OFFSET
     */
    public function offset(int $offset): self
    {
        $this->offsetValue = $offset;
        return $this;
    }

    /**
     * Alias for offset
     */
    public function skip(int $count): self
    {
        return $this->offset($count);
    }

    /**
     * Paginate results
     */
    public function forPage(int $page, int $perPage = 15): self
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    // Execution methods

    /**
     * Execute the query and get all results
     *
     * @return array<int, array<string, mixed>>
     */
    public function get(): array
    {
        return $this->connection->select($this->toSql(), $this->bindings);
    }

    /**
     * Execute the query and get the first result
     *
     * @return array<string, mixed>|null
     */
    public function first(): ?array
    {
        return $this->limit(1)->connection->selectOne($this->toSql(), $this->bindings);
    }

    /**
     * Get a single column's value from the first result
     */
    public function value(string $column): mixed
    {
        $this->columns = [$column];
        return $this->connection->selectValue($this->toSql(), $this->bindings);
    }

    /**
     * Get an array of values for a single column
     *
     * @return array<int, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $this->columns = $key ? [$key, $column] : [$column];
        $results = $this->get();

        if ($key) {
            $plucked = [];
            foreach ($results as $row) {
                $plucked[$row[$key]] = $row[$column];
            }
            return $plucked;
        }

        return array_column($results, $column);
    }

    /**
     * Get the count of results
     */
    public function count(string $column = '*'): int
    {
        $this->columns = ["COUNT({$column}) as aggregate"];
        return (int) ($this->first()['aggregate'] ?? 0);
    }

    /**
     * Get the sum of a column
     */
    public function sum(string $column): float
    {
        $this->columns = ["SUM({$column}) as aggregate"];
        return (float) ($this->first()['aggregate'] ?? 0);
    }

    /**
     * Get the average of a column
     */
    public function avg(string $column): float
    {
        $this->columns = ["AVG({$column}) as aggregate"];
        return (float) ($this->first()['aggregate'] ?? 0);
    }

    /**
     * Get the minimum value of a column
     */
    public function min(string $column): mixed
    {
        $this->columns = ["MIN({$column}) as aggregate"];
        return $this->first()['aggregate'] ?? null;
    }

    /**
     * Get the maximum value of a column
     */
    public function max(string $column): mixed
    {
        $this->columns = ["MAX({$column}) as aggregate"];
        return $this->first()['aggregate'] ?? null;
    }

    /**
     * Check if any records exist
     */
    public function exists(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Check if no records exist
     */
    public function doesntExist(): bool
    {
        return !$this->exists();
    }

    /**
     * Insert a new record
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        return $this->connection->insert($this->table, $data);
    }

    /**
     * Insert or update on duplicate key
     *
     * @param array<string, mixed> $data
     * @param string[] $updateColumns
     */
    public function upsert(array $data, array $updateColumns = []): int
    {
        return $this->connection->upsert($this->table, $data, $updateColumns);
    }

    /**
     * Update records matching the WHERE clauses
     *
     * @param array<string, mixed> $data
     */
    public function update(array $data): int
    {
        $setParts = [];
        $bindings = $this->bindings;

        foreach ($data as $column => $value) {
            $key = $this->generateBindingKey();
            $setParts[] = sprintf('%s = :%s', $this->connection->quoteIdentifier($column), $key);
            $bindings[$key] = $value;
        }

        $sql = sprintf(
            'UPDATE %s SET %s%s',
            $this->connection->quoteIdentifier($this->table),
            implode(', ', $setParts),
            $this->compileWheres()
        );

        $this->connection->query($sql, $bindings);
        return $this->connection->affectedRows();
    }

    /**
     * Delete records matching the WHERE clauses
     */
    public function delete(): int
    {
        $sql = sprintf(
            'DELETE FROM %s%s',
            $this->connection->quoteIdentifier($this->table),
            $this->compileWheres()
        );

        $this->connection->query($sql, $this->bindings);
        return $this->connection->affectedRows();
    }

    /**
     * Increment a column's value
     */
    public function increment(string $column, int $amount = 1): int
    {
        return $this->update([
            $column => new RawExpression("{$column} + {$amount}"),
        ]);
    }

    /**
     * Decrement a column's value
     */
    public function decrement(string $column, int $amount = 1): int
    {
        return $this->update([
            $column => new RawExpression("{$column} - {$amount}"),
        ]);
    }

    // SQL generation

    /**
     * Generate the SQL query string
     */
    public function toSql(): string
    {
        $sql = $this->distinct ? 'SELECT DISTINCT ' : 'SELECT ';
        $sql .= $this->compileColumns();
        $sql .= ' FROM ' . $this->connection->quoteIdentifier($this->table);
        $sql .= $this->compileJoins();
        $sql .= $this->compileWheres();
        $sql .= $this->compileGroupBy();
        $sql .= $this->compileOrderBy();
        $sql .= $this->compileLimit();
        $sql .= $this->compileOffset();

        return $sql;
    }

    private function compileColumns(): string
    {
        return implode(', ', $this->columns);
    }

    private function compileJoins(): string
    {
        if (empty($this->joins)) {
            return '';
        }

        $sql = '';
        foreach ($this->joins as $join) {
            $sql .= sprintf(
                ' %s JOIN %s ON %s %s %s',
                $join['type'],
                $this->connection->quoteIdentifier($join['table']),
                $join['first'],
                $join['operator'],
                $join['second']
            );
        }
        return $sql;
    }

    private function compileWheres(): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        $parts = [];
        foreach ($this->wheres as $i => $where) {
            $clause = '';

            if ($i > 0) {
                $clause .= ' ' . $where['boolean'] . ' ';
            }

            switch ($where['type']) {
                case 'basic':
                    $clause .= sprintf(
                        '%s %s :%s',
                        $this->connection->quoteIdentifier($where['column']),
                        $where['operator'],
                        $where['value']
                    );
                    break;

                case 'in':
                case 'notIn':
                    $placeholders = array_map(fn($k) => ":{$k}", $where['value']);
                    $clause .= sprintf(
                        '%s %s (%s)',
                        $this->connection->quoteIdentifier($where['column']),
                        $where['operator'],
                        implode(', ', $placeholders)
                    );
                    break;

                case 'null':
                case 'notNull':
                    $clause .= sprintf(
                        '%s %s NULL',
                        $this->connection->quoteIdentifier($where['column']),
                        $where['operator']
                    );
                    break;

                case 'between':
                    $clause .= sprintf(
                        '%s BETWEEN :%s AND :%s',
                        $this->connection->quoteIdentifier($where['column']),
                        $where['value'][0],
                        $where['value'][1]
                    );
                    break;

                case 'raw':
                    $clause .= $where['column'];
                    break;
            }

            $parts[] = $clause;
        }

        return ' WHERE ' . implode('', $parts);
    }

    private function compileGroupBy(): string
    {
        if (empty($this->groupBy)) {
            return '';
        }

        return ' GROUP BY ' . implode(', ', array_map(
            [$this->connection, 'quoteIdentifier'],
            $this->groupBy
        ));
    }

    private function compileOrderBy(): string
    {
        if (empty($this->orderBy)) {
            return '';
        }

        $parts = array_map(
            fn($o) => $this->connection->quoteIdentifier($o['column']) . ' ' . $o['direction'],
            $this->orderBy
        );

        return ' ORDER BY ' . implode(', ', $parts);
    }

    private function compileLimit(): string
    {
        if ($this->limitValue === null) {
            return '';
        }
        return ' LIMIT ' . $this->limitValue;
    }

    private function compileOffset(): string
    {
        if ($this->offsetValue === null) {
            return '';
        }
        return ' OFFSET ' . $this->offsetValue;
    }

    // Binding helpers

    private function addBinding(mixed $value): string
    {
        $key = $this->generateBindingKey();
        $this->bindings[$key] = $value;
        return $key;
    }

    private function generateBindingKey(): string
    {
        return 'p' . $this->bindingIndex++;
    }

    /**
     * Get the current bindings
     *
     * @return array<string, mixed>
     */
    public function getBindings(): array
    {
        return $this->bindings;
    }

    /**
     * Clone the query builder
     */
    public function clone(): self
    {
        return clone $this;
    }
}

/**
 * RawExpression - Represents a raw SQL expression
 *
 * Used to insert raw SQL into queries without escaping.
 */
class RawExpression
{
    public function __construct(
        public readonly string $expression
    ) {}

    public function __toString(): string
    {
        return $this->expression;
    }
}
