<?php

declare(strict_types=1);

namespace Tests\Support;

use KallioMicro\Database\Connection;

/**
 * Connection stand-in that records executed SQL and returns canned rows —
 * lets QueryBuilder execution paths (paginate, count) run without a database
 * driver being installed.
 */
class FakeConnection extends Connection
{
    /** @var array<int, array{sql: string, bindings: array<string, mixed>}> */
    public array $queries = [];

    /** @var array<int, array<int, array<string, mixed>>> Queued result sets for select() */
    private array $selectResults = [];

    /** @var array<int, array<string, mixed>|null> Queued rows for selectOne() */
    private array $selectOneResults = [];

    public function __construct()
    {
        parent::__construct(['driver' => 'sqlite', 'database' => ':memory:']);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function queueSelect(array $rows): void
    {
        $this->selectResults[] = $rows;
    }

    /**
     * @param array<string, mixed>|null $row
     */
    public function queueSelectOne(?array $row): void
    {
        $this->selectOneResults[] = $row;
    }

    public function select(string $sql, array $bindings = []): array
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];
        return array_shift($this->selectResults) ?? [];
    }

    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $this->queries[] = ['sql' => $sql, 'bindings' => $bindings];
        return array_shift($this->selectOneResults);
    }
}
