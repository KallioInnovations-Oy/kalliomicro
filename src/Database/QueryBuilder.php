<?php

declare(strict_types=1);

namespace KallioMicro\Database;

use Closure;
use InvalidArgumentException;
use RuntimeException;

/**
 * QueryBuilder - Fluent SQL query builder
 *
 * Provides a clean, chainable API for building SQL queries
 * with automatic parameter binding for security.
 */
class QueryBuilder
{
    /**
     * Operators that may be interpolated into compiled SQL
     *
     * Shared by JOIN and WHERE — both interpolate the operator verbatim, so
     * both must validate it.
     */
    private const COMPARISON_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'];

    private Connection $connection;
    private string $table;

    /** @var array<int, string|RawExpression> */
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
        $columns = is_array($columns) ? $columns : func_get_args();

        foreach ($columns as $column) {
            if (!$column instanceof RawExpression) {
                $this->validateIdentifier($column);
            }
        }

        $this->columns = $columns;
        return $this;
    }

    /**
     * Add columns to select
     */
    public function addSelect(string ...$columns): self
    {
        foreach ($columns as $column) {
            $this->validateIdentifier($column);
        }

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
        if (!in_array(strtoupper($operator), self::COMPARISON_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid JOIN operator: {$operator}");
        }

        $this->joins[] = [
            'type' => $type,
            'table' => $this->validateIdentifier($table),
            'first' => $this->validateIdentifier($first),
            'operator' => $operator,
            'second' => $this->validateIdentifier($second),
        ];
        return $this;
    }

    /**
     * Add a WHERE clause, or a parenthesised group of them
     *
     * Passing a Closure opens a nested group:
     *
     *     ->where('tenant_id', $tenant)
     *     ->where(fn ($q) => $q->where('owner_id', $user)->orWhere('public', 1))
     *     // WHERE `tenant_id` = :p0 AND (`owner_id` = :p1 OR `public` = :p2)
     *
     * Grouping exists because conditions otherwise compile as a flat list and
     * SQL binds AND tighter than OR, so a mixed chain does not mean what it
     * reads like. Before this, the only way to express the query above was
     * whereRaw() — pushing authorization filters, of all things, into the one
     * escape hatch that accepts arbitrary SQL text.
     */
    public function where(string|Closure $column, mixed $operatorOrValue = null, mixed $value = null): self
    {
        if ($column instanceof Closure) {
            return $this->addNestedWhere($column, 'AND');
        }

        return $this->addWhere('basic', $column, $operatorOrValue, $value, 'AND', func_num_args());
    }

    /**
     * Add an OR WHERE clause, or a parenthesised group of them
     */
    public function orWhere(string|Closure $column, mixed $operatorOrValue = null, mixed $value = null): self
    {
        if ($column instanceof Closure) {
            return $this->addNestedWhere($column, 'OR');
        }

        return $this->addWhere('basic', $column, $operatorOrValue, $value, 'OR', func_num_args());
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
            'column' => $this->validateIdentifier($column),
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
            'column' => $this->validateIdentifier($column),
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
            'column' => $this->validateIdentifier($column),
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
        // The builder uses named placeholders internally, so rewrite each positional
        // ? in the fragment to a fresh named key bound to the matching value.
        $placeholderCount = substr_count($sql, '?');
        if ($placeholderCount !== count($bindings)) {
            throw new InvalidArgumentException(sprintf(
                'whereRaw() placeholder count (%d) does not match binding count (%d)',
                $placeholderCount,
                count($bindings)
            ));
        }

        foreach ($bindings as $value) {
            $key = $this->addBinding($value);
            $position = strpos($sql, '?');
            $sql = substr_replace($sql, ':' . $key, (int) $position, 1);
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

    private function addWhere(string $type, string $column, mixed $operatorOrValue, mixed $value, string $boolean, int $numArgs = 3): self
    {
        if ($numArgs === 2) {
            // where('column', $value) shorthand: equality, or IS NULL when $value is null
            if ($operatorOrValue === null) {
                return $this->addNullWhere($column, false, $boolean);
            }
            $value = $operatorOrValue;
            $operatorOrValue = '=';
        } elseif ($value === null) {
            // Explicit null comparison: SQL "= NULL" never matches, so map to IS (NOT) NULL
            return match (strtoupper((string) $operatorOrValue)) {
                '=' => $this->addNullWhere($column, false, $boolean),
                '!=', '<>' => $this->addNullWhere($column, true, $boolean),
                default => throw new InvalidArgumentException(
                    "Cannot compare column {$column} to NULL with operator {$operatorOrValue}; use whereNull()/whereNotNull()"
                ),
            };
        }

        $bindingKey = $this->addBinding($value);

        $this->wheres[] = [
            'type' => $type,
            'column' => $this->validateIdentifier($column),
            'operator' => $this->validateOperator((string) $operatorOrValue),
            'value' => $bindingKey,
            'boolean' => $boolean,
        ];

        return $this;
    }

    /**
     * Capture a closure's conditions as one parenthesised group
     *
     * The sub-builder continues this builder's placeholder numbering and hands
     * its bindings back, so nested groups cannot collide with the outer query
     * on :pN — the whole point of binding is lost if two values share a name.
     */
    private function addNestedWhere(Closure $callback, string $boolean): self
    {
        $nested = new self($this->connection, $this->table);
        $nested->bindingIndex = $this->bindingIndex;

        $callback($nested);

        if ($nested->wheres === []) {
            // An empty group would compile to "()", a syntax error. Nothing
            // was asked for, so nothing is added.
            return $this;
        }

        $this->bindingIndex = $nested->bindingIndex;
        $this->bindings = array_merge($this->bindings, $nested->bindings);

        $this->wheres[] = [
            'type' => 'nested',
            'column' => '',
            'operator' => '',
            'value' => $nested->wheres,
            'boolean' => $boolean,
        ];

        return $this;
    }

    private function addNullWhere(string $column, bool $not, string $boolean): self
    {
        $this->wheres[] = [
            'type' => $not ? 'notNull' : 'null',
            'column' => $this->validateIdentifier($column),
            'operator' => $not ? 'IS NOT' : 'IS',
            'value' => null,
            'boolean' => $boolean,
        ];
        return $this;
    }

    private function addWhereIn(string $column, array $values, string $boolean, bool $not): self
    {
        $column = $this->validateIdentifier($column);

        if ($values === []) {
            // IN () is invalid SQL. An empty IN list matches nothing; an empty
            // NOT IN list matches everything.
            //
            // 'alwaysFalse' is absorbing when joined with AND, and compileWheres()
            // collapses the whole clause to it. Merely appending "0 = 1" is not
            // enough — AND binds tighter than OR, so "a = 1 OR b = 2 AND 0 = 1"
            // reduces to "a = 1" and an empty permission allowlist fails OPEN.
            // Parenthesising the fragment does not help either; the grouping has
            // to swallow the preceding conditions, not itself.
            $this->wheres[] = [
                'type' => $not ? 'alwaysTrue' : 'alwaysFalse',
                'column' => $not ? '1 = 1' : '0 = 1',
                'operator' => '',
                'value' => null,
                'boolean' => $boolean,
            ];
            return $this;
        }

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
        $this->groupBy = array_merge(
            $this->groupBy,
            array_map(fn (string $column): string => $this->validateIdentifier($column), $columns)
        );
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
            'column' => $this->validateIdentifier($column),
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
        if ($limit < 0) {
            throw new InvalidArgumentException("LIMIT cannot be negative: {$limit}");
        }

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
        if ($offset < 0) {
            throw new InvalidArgumentException("OFFSET cannot be negative: {$offset}");
        }

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
        // Clamped, not validated: forPage() is fed straight from ?page= in the
        // ordinary controller pattern, so page 0 or -3 is user input rather
        // than a programming error. paginate() clamps identically.
        $page = max(1, $page);
        $perPage = max(1, $perPage);

        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * Execute the query for a page and return data plus pagination metadata.
     *
     * Rendering the page links is view-layer policy and stays downstream;
     * this only owns the mechanism (count + page slice + metadata).
     *
     * @return array{data: array<int, array<string, mixed>>, total: int, per_page: int,
     *               current_page: int, last_page: int, from: int|null, to: int|null}
     */
    public function paginate(int $page = 1, int $perPage = 15): array
    {
        if ($this->groupBy !== []) {
            throw new RuntimeException(
                'paginate() does not support groupBy() — the COUNT aggregate collapses the groups. '
                . 'Compute the total yourself and use forPage().'
            );
        }

        if ($this->distinct) {
            throw new RuntimeException(
                'paginate() does not support distinct() — DISTINCT is a no-op on the COUNT aggregate, '
                . 'so the total would count duplicate rows. Compute the total yourself and use forPage().'
            );
        }

        $page = max(1, $page);
        $perPage = max(1, $perPage);
        $offset = ($page - 1) * $perPage;

        // Both queries run on clones: the count with ORDER BY / LIMIT / OFFSET
        // stripped (they cannot change the total but produce slower — or, with
        // LIMIT, wrong — counts), the data as a page slice. The builder itself
        // is left untouched, so it stays reusable after paginate().
        $countQuery = clone $this;
        $countQuery->orderBy = [];
        $countQuery->limitValue = null;
        $countQuery->offsetValue = null;
        $total = $countQuery->count();

        // A page past the end is knowably empty — skip the offset scan.
        $data = $offset < $total ? (clone $this)->forPage($page, $perPage)->get() : [];
        $from = $data === [] ? null : $offset + 1;

        return [
            'data' => $data,
            'total' => $total,
            'per_page' => $perPage,
            'current_page' => $page,
            'last_page' => max(1, (int) ceil($total / $perPage)),
            'from' => $from,
            'to' => $from === null ? null : $from + count($data) - 1,
        ];
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
        $this->columns = [$this->validateIdentifier($column)];

        // first() clamps and this did not, so reading one value pulled the
        // whole matching set across the wire to discard all but its first row.
        $this->limit(1);

        return $this->connection->selectValue($this->toSql(), $this->bindings);
    }

    /**
     * Get an array of values for a single column
     *
     * @return array<int, mixed>
     */
    public function pluck(string $column, ?string $key = null): array
    {
        $this->validateIdentifier($column);
        if ($key !== null) {
            $this->validateIdentifier($key);
        }

        $this->columns = $key ? [$key, $column] : [$column];
        $results = $this->get();

        // A qualified column arrives keyed by its bare name — MySQL labels
        // `users`.`name` as 'name' — so looking it up as 'users.name' silently
        // returned an empty array (or, keyed, a warning per row).
        $columnKey = $this->resultKey($column);
        $keyKey = $key !== null ? $this->resultKey($key) : null;

        if ($keyKey !== null) {
            $plucked = [];
            foreach ($results as $row) {
                $plucked[$row[$keyKey]] = $row[$columnKey];
            }
            return $plucked;
        }

        return array_column($results, $columnKey);
    }

    /**
     * The array key a selected column arrives under in a result row
     */
    private function resultKey(string $column): string
    {
        $separator = strrpos($column, '.');

        return $separator === false ? $column : substr($column, $separator + 1);
    }

    /**
     * Get the count of results
     */
    public function count(string $column = '*'): int
    {
        $column = $this->validateIdentifier($column);
        $this->columns = [new RawExpression("COUNT(" . $this->connection->quoteIdentifier($column) . ") as aggregate")];
        return (int) ($this->first()['aggregate'] ?? 0);
    }

    /**
     * Get the sum of a column
     */
    public function sum(string $column): float
    {
        $column = $this->validateIdentifier($column);
        $this->columns = [new RawExpression("SUM(" . $this->connection->quoteIdentifier($column) . ") as aggregate")];
        return (float) ($this->first()['aggregate'] ?? 0);
    }

    /**
     * Get the average of a column
     */
    public function avg(string $column): float
    {
        $column = $this->validateIdentifier($column);
        $this->columns = [new RawExpression("AVG(" . $this->connection->quoteIdentifier($column) . ") as aggregate")];
        return (float) ($this->first()['aggregate'] ?? 0);
    }

    /**
     * Get the minimum value of a column
     */
    public function min(string $column): mixed
    {
        $column = $this->validateIdentifier($column);
        $this->columns = [new RawExpression("MIN(" . $this->connection->quoteIdentifier($column) . ") as aggregate")];
        return $this->first()['aggregate'] ?? null;
    }

    /**
     * Get the maximum value of a column
     */
    public function max(string $column): mixed
    {
        $column = $this->validateIdentifier($column);
        $this->columns = [new RawExpression("MAX(" . $this->connection->quoteIdentifier($column) . ") as aggregate")];
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
        if (empty($this->wheres)) {
            throw new RuntimeException(
                'Refusing to UPDATE without a WHERE clause; add ->whereRaw(\'1 = 1\') to deliberately affect all rows.'
            );
        }

        $setParts = [];
        $bindings = $this->bindings;

        foreach ($data as $column => $value) {
            if ($value instanceof RawExpression) {
                // Raw expressions (e.g. increment/decrement) are inlined, never bound
                $setParts[] = sprintf('%s = %s', $this->connection->quoteIdentifier($column), $value);
                continue;
            }

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
        if (empty($this->wheres)) {
            throw new RuntimeException(
                'Refusing to DELETE without a WHERE clause; add ->whereRaw(\'1 = 1\') to deliberately affect all rows.'
            );
        }

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
        $column = $this->validateIdentifier($column);

        // Quoted on the right-hand side too: validation only proves the shape
        // is an identifier, it does not make a reserved word like `order` or
        // `key` legal unquoted.
        return $this->update([
            $column => new RawExpression($this->connection->quoteIdentifier($column) . " + {$amount}"),
        ]);
    }

    /**
     * Decrement a column's value
     */
    public function decrement(string $column, int $amount = 1): int
    {
        $column = $this->validateIdentifier($column);
        return $this->update([
            $column => new RawExpression($this->connection->quoteIdentifier($column) . " - {$amount}"),
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
        return implode(', ', array_map(
            fn ($column) => $column instanceof RawExpression
                ? (string) $column
                : $this->connection->quoteIdentifier($column),
            $this->columns
        ));
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
                $this->connection->quoteIdentifier($join['first']),
                $join['operator'],
                $this->connection->quoteIdentifier($join['second'])
            );
        }
        return $sql;
    }

    private function compileWheres(): string
    {
        if (empty($this->wheres)) {
            return '';
        }

        return ' WHERE ' . $this->compileWhereList($this->wheres);
    }

    /**
     * Is this condition list unconditionally false?
     *
     * True when it contains an AND-joined always-false condition — an empty
     * whereIn() — at this level or inside an AND-joined nested group. Such a
     * condition absorbs everything beside it, and recursing matters: a group
     * that is itself always false makes its AND-joined parent false too.
     *
     * @param array<int, array{type: string, column: string, operator: string, value: mixed, boolean: string}> $wheres
     */
    private function listIsAlwaysFalse(array $wheres): bool
    {
        foreach ($wheres as $where) {
            if ($where['boolean'] !== 'AND') {
                continue;
            }

            if ($where['type'] === 'alwaysFalse') {
                return true;
            }

            if ($where['type'] === 'nested' && $this->listIsAlwaysFalse($where['value'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compile one condition list — recursive, so nested groups reuse it
     *
     * @param array<int, array{type: string, column: string, operator: string, value: mixed, boolean: string}> $wheres
     */
    private function compileWhereList(array $wheres): string
    {
        // Checked per list rather than only at the top level: an empty
        // whereIn() inside a group has to nullify that group, or the
        // fail-open the guard exists to prevent simply moves inside the parens.
        if ($this->listIsAlwaysFalse($wheres)) {
            return '0 = 1';
        }

        $parts = [];
        foreach ($wheres as $i => $where) {
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
                case 'alwaysTrue':
                case 'alwaysFalse':
                    $clause .= $where['column'];
                    break;

                case 'nested':
                    $clause .= '(' . $this->compileWhereList($where['value']) . ')';
                    break;
            }

            $parts[] = $clause;
        }

        return implode('', $parts);
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
            // MySQL has no bare OFFSET; the documented idiom for "skip n, take
            // everything" is a LIMIT of the maximum unsigned BIGINT.
            return $this->offsetValue === null ? '' : ' LIMIT 18446744073709551615';
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
     * Validate an SQL identifier (column or table reference)
     *
     * Allows a bare identifier, a two-part table.column reference, table.* or *.
     * Aliases (AS), functions, and cross-database references are rejected —
     * use Connection raw queries with bindings for those.
     */
    /**
     * Validate a comparison operator against the allowlist
     *
     * Operators are interpolated into the compiled SQL, so an unvalidated one
     * is an injection sink in its own right — '= 1 OR 1=1 AND a =' would ride
     * in alongside a correctly quoted column.
     */
    private function validateOperator(string $operator): string
    {
        $normalized = strtoupper(trim($operator));

        if (!in_array($normalized, self::COMPARISON_OPERATORS, true)) {
            throw new InvalidArgumentException("Invalid operator: {$operator}");
        }

        return $normalized;
    }

    private function validateIdentifier(string $identifier): string
    {
        if ($identifier === '*') {
            return $identifier;
        }

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z_][a-zA-Z0-9_]*|\.\*)?$/', $identifier)) {
            throw new InvalidArgumentException("Invalid identifier: {$identifier}");
        }

        return $identifier;
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

    /**
     * Fail loudly and helpfully on unknown methods — this builder is
     * deliberately smaller than Laravel's; common Laravel calls get pointed
     * at the local equivalent instead of a bare "undefined method".
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        $hints = [
            'find'           => "use first() with where('id', \$id)",
            'firstOrFail'    => 'use first() and handle null in the caller',
            'chunk'          => 'loop forPage($page, $size)->get() until it returns an empty array',
            'with'           => 'no ORM/relations — use join() or a second query',
            'whereHas'       => 'no ORM/relations — use join() or Connection::select() with raw SQL',
            'insertGetId'    => 'insert() already returns the last insert id',
            'updateOrInsert' => 'use upsert()',
            'selectRaw'      => 'pass a RawExpression to select(), or use Connection::select() with bindings',
            'orderByRaw'     => 'not supported — use Connection::select() with raw SQL',
            'when'           => 'use a plain if around the chain',
        ];

        $hint = isset($hints[$method]) ? " Hint: {$hints[$method]}." : '';

        throw new \BadMethodCallException(sprintf(
            "Call to undefined method %s::%s().%s This is KallioMicro's QueryBuilder, not Laravel's — see docs/database.md for the shipped method list.",
            static::class,
            $method,
            $hint
        ));
    }
}
