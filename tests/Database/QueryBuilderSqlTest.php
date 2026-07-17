<?php

declare(strict_types=1);

namespace Tests\Database;

use InvalidArgumentException;
use Tests\Support\FakeConnection;
use Tests\TestCase;

class QueryBuilderSqlTest extends TestCase
{
    private FakeConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new FakeConnection();
    }

    public function testBasicSelectWithWhereBindsValues(): void
    {
        $qb = $this->connection->table('users')->where('active', 1)->where('age', '>', 18);

        $sql = $qb->toSql();

        $this->assertStringContainsString('FROM', $sql);
        $this->assertStringContainsString('WHERE', $sql);
        $this->assertSame([1, 18], array_values($qb->getBindings()));
    }

    public function testJoinOrderLimitOffsetCompile(): void
    {
        $sql = $this->connection->table('users')
            ->join('orders', 'users.id', '=', 'orders.user_id')
            ->orderBy('name')
            ->limit(10)
            ->offset(5)
            ->toSql();

        $this->assertStringContainsString('JOIN', $sql);
        $this->assertStringContainsString('ORDER BY', $sql);
        $this->assertStringContainsString('LIMIT 10', $sql);
        $this->assertStringContainsString('OFFSET 5', $sql);
    }

    public function testEmptyWhereInMatchesNothing(): void
    {
        $sql = $this->connection->table('users')->whereIn('id', [])->toSql();

        $this->assertStringContainsString('0 = 1', $sql);
    }

    public function testEmptyWhereNotInMatchesEverything(): void
    {
        $sql = $this->connection->table('users')->whereNotIn('id', [])->toSql();

        $this->assertStringContainsString('1 = 1', $sql);
    }

    public function testNullWhereCompilesToIsNull(): void
    {
        $sql = $this->connection->table('users')->where('deleted_at', null)->toSql();

        $this->assertStringContainsString('IS NULL', $sql);
    }

    public function testNotEqualNullCompilesToIsNotNull(): void
    {
        $sql = $this->connection->table('users')->where('deleted_at', '!=', null)->toSql();

        $this->assertStringContainsString('IS NOT NULL', $sql);
    }

    public function testWhereRawPlaceholderCountMismatchThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('placeholder count');

        $this->connection->table('users')->whereRaw('a = ? AND b = ?', [1]);
    }

    public function testInvalidIdentifierRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier');

        $this->connection->table('users')->count('id; DROP TABLE users');
    }
}
