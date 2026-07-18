<?php

declare(strict_types=1);

namespace Tests\Database;

use InvalidArgumentException;
use KallioMicro\Database\QueryBuilder;
use Tests\Support\FakeConnection;
use Tests\TestCase;

/**
 * The where/orderBy/groupBy/whereIn/whereNull family used to accept any string
 * as a column and any string as an operator, while select/join/aggregates
 * validated. The ordinary ?sort=<column> controller pattern was injectable.
 */
class QueryBuilderIdentifierGuardTest extends TestCase
{
    private function builder(): QueryBuilder
    {
        return new QueryBuilder(new FakeConnection(), 'users');
    }

    /**
     * @dataProvider injectedColumns
     */
    public function testColumnInjectionIsRejected(string $method, array $args): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier');

        $this->builder()->{$method}(...$args);
    }

    public static function injectedColumns(): array
    {
        return [
            'orderBy subselect'  => ['orderBy', ['(SELECT 1 FROM core_login_attempts)', 'ASC']],
            'where tautology'    => ['where', ['id = 1 OR 1=1 AND id', '>', 0]],
            'whereNull comment'  => ['whereNull', ['a IS NULL OR 1=1 -- x']],
            'whereNotNull'       => ['whereNotNull', ['a IS NOT NULL OR 1=1 -- x']],
            'groupBy subselect'  => ['groupBy', ['b, (SELECT 1)']],
            'whereIn break-out'  => ['whereIn', ['x) OR 1=1 -- ', [1, 2]]],
            'whereNotIn'         => ['whereNotIn', ['x) OR 1=1 -- ', [1, 2]]],
            'whereBetween'       => ['whereBetween', ['x) OR 1=1 -- ', 1, 9]],
            'orWhere'            => ['orWhere', ['id = 1 OR 1=1 AND id', '>', 0]],
        ];
    }

    /**
     * The operator is interpolated into the compiled SQL, so it is an
     * injection sink even when the column is correctly quoted. JOIN always
     * allowlisted it; WHERE did not.
     */
    public function testOperatorInjectionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid operator');

        $this->builder()->where('a', '= 1 OR 1=1 AND a =', 5);
    }

    public function testLegitimateOperatorsSurvive(): void
    {
        foreach (['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE'] as $operator) {
            $sql = $this->builder()->where('a', $operator, 1)->toSql();
            $this->assertStringContainsString("`a` {$operator} :p0", $sql);
        }
    }

    public function testOperatorIsNormalisedToUppercase(): void
    {
        $this->assertStringContainsString(
            '`a` LIKE :p0',
            $this->builder()->where('a', 'like', '%x%')->toSql()
        );
    }

    public function testValidColumnShapesStillCompile(): void
    {
        $sql = $this->builder()
            ->where('users.id', '>', 1)
            ->whereNull('deleted_at')
            ->groupBy('role_id', 'users.tenant_id')
            ->orderBy('created_at', 'DESC')
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `users` WHERE `users`.`id` > :p0 AND `deleted_at` IS NULL'
            . ' GROUP BY `role_id`, `users`.`tenant_id` ORDER BY `created_at` DESC',
            $sql
        );
    }

    /**
     * quoteIdentifier() used to return anything containing a backtick or a
     * space unquoted AND unescaped — a passthrough for exactly the inputs that
     * matter. It must fail closed instead.
     */
    public function testQuoteIdentifierFailsClosedOnUnquotableInput(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new FakeConnection())->quoteIdentifier('id` OR 1=1 -- ');
    }
}
