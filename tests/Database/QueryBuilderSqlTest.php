<?php

declare(strict_types=1);

namespace Tests\Database;

use InvalidArgumentException;
use Tests\Support\FakeConnection;
use Tests\TestCase;

/**
 * SQL assertions here are deliberately whole-string (assertSame), not
 * assertStringContainsString. Substring assertions are what let `SELECT \`*\``
 * and `LIMIT 15 OFFSET -15` — both invalid on any real MySQL — pass this suite
 * for an entire release: every substring the tests looked for was still
 * present. There is no database driver in the dev dependencies to catch that
 * downstream, so the compiled string is the contract.
 */
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

        $this->assertSame(
            'SELECT * FROM `users` WHERE `active` = :p0 AND `age` > :p1',
            $sql
        );
        $this->assertSame([1, 18], array_values($qb->getBindings()));
    }

    /**
     * The default column list is ['*'], which used to be backticked into
     * `SELECT \`*\`` — a column literally named '*', rejected by MySQL with
     * 1054. Every get()/first()/pluck() without an explicit select() was broken.
     */
    public function testWildcardIsNotQuotedAsAnIdentifier(): void
    {
        $this->assertSame('SELECT * FROM `users`', $this->connection->table('users')->toSql());
    }

    public function testQualifiedWildcardKeepsTheTableQuotedButNotTheStar(): void
    {
        $this->assertSame(
            'SELECT `t`.* FROM `users`',
            $this->connection->table('users')->select(['t.*'])->toSql()
        );
    }

    public function testJoinOrderLimitOffsetCompile(): void
    {
        $sql = $this->connection->table('users')
            ->join('orders', 'users.id', '=', 'orders.user_id')
            ->orderBy('name')
            ->limit(10)
            ->offset(5)
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `users` INNER JOIN `orders` ON `users`.`id` = `orders`.`user_id`'
            . ' ORDER BY `name` ASC LIMIT 10 OFFSET 5',
            $sql
        );
    }

    public function testEmptyWhereInMatchesNothing(): void
    {
        $sql = $this->connection->table('users')->whereIn('id', [])->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE 0 = 1', $sql);
    }

    /**
     * AND binds tighter than OR, so an appended "0 = 1" attaches to the last
     * OR branch only and "a = 1 OR b = 2 AND 0 = 1" reduces to "a = 1". An
     * empty permission allowlist then fails OPEN. The guard has to absorb the
     * whole clause — parenthesising the fragment alone does not fix it.
     */
    public function testEmptyWhereInIsNotDefeatedByAPrecedingOrWhere(): void
    {
        $sql = $this->connection->table('users')
            ->where('a', 1)
            ->orWhere('b', 2)
            ->whereIn('c', [])
            ->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE 0 = 1', $sql);
    }

    public function testEmptyWhereNotInMatchesEverything(): void
    {
        $sql = $this->connection->table('users')->whereNotIn('id', [])->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE 1 = 1', $sql);
    }

    public function testNullWhereCompilesToIsNull(): void
    {
        $sql = $this->connection->table('users')->where('deleted_at', null)->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NULL', $sql);
    }

    public function testNotEqualNullCompilesToIsNotNull(): void
    {
        $sql = $this->connection->table('users')->where('deleted_at', '!=', null)->toSql();

        $this->assertSame('SELECT * FROM `users` WHERE `deleted_at` IS NOT NULL', $sql);
    }

    /**
     * forPage() is fed straight from ?page= in the ordinary controller
     * pattern, so page 0 or -3 is user input. It used to compile to
     * OFFSET -15, a 1064 syntax error — ?page=0 was a guaranteed 500.
     */
    public function testForPageClampsNonPositivePages(): void
    {
        $this->assertSame(
            'SELECT * FROM `users` LIMIT 15 OFFSET 0',
            $this->connection->table('users')->forPage(0, 15)->toSql()
        );
        $this->assertSame(
            'SELECT * FROM `users` LIMIT 15 OFFSET 0',
            $this->connection->table('users')->forPage(-3, 15)->toSql()
        );
    }

    public function testNegativeLimitAndOffsetAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->connection->table('users')->limit(-1);
    }

    public function testNegativeOffsetIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->connection->table('users')->offset(-1);
    }

    /**
     * MySQL has no bare OFFSET; without a LIMIT it is a 1064 syntax error.
     */
    public function testOffsetWithoutLimitEmitsASentinelLimit(): void
    {
        $this->assertSame(
            'SELECT * FROM `users` LIMIT 18446744073709551615 OFFSET 10',
            $this->connection->table('users')->offset(10)->toSql()
        );
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
