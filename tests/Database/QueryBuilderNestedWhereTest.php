<?php

declare(strict_types=1);

namespace Tests\Database;

use InvalidArgumentException;
use KallioMicro\Database\QueryBuilder;
use Tests\Support\FakeConnection;
use Tests\TestCase;

/**
 * Conditions compile as a flat list and SQL binds AND tighter than OR, so a
 * mixed chain does not mean what it reads like. Until 1.2.1 the only way to
 * express "(a OR b) AND c" was whereRaw() — which pushed authorization
 * filters, of all things, into the one escape hatch that takes arbitrary SQL.
 */
class QueryBuilderNestedWhereTest extends TestCase
{
    private FakeConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new FakeConnection();
    }

    private function builder(): QueryBuilder
    {
        return $this->connection->table('docs');
    }

    public function testClosureProducesAParenthesisedGroup(): void
    {
        $sql = $this->builder()
            ->where('tenant_id', 7)
            ->where(fn (QueryBuilder $q) => $q->where('owner_id', 3)->orWhere('public', 1))
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `docs` WHERE `tenant_id` = :p0 AND (`owner_id` = :p1 OR `public` = :p2)',
            $sql
        );
    }

    public function testGroupMayComeFirst(): void
    {
        $sql = $this->builder()
            ->where(fn (QueryBuilder $q) => $q->where('a', 1)->orWhere('b', 2))
            ->where('c', 3)
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `docs` WHERE (`a` = :p0 OR `b` = :p1) AND `c` = :p2',
            $sql
        );
    }

    public function testOrWhereAcceptsAGroupToo(): void
    {
        $sql = $this->builder()
            ->where('a', 1)
            ->orWhere(fn (QueryBuilder $q) => $q->where('b', 2)->where('c', 3))
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `docs` WHERE `a` = :p0 OR (`b` = :p1 AND `c` = :p2)',
            $sql
        );
    }

    public function testGroupsNest(): void
    {
        $sql = $this->builder()
            ->where(fn (QueryBuilder $q) => $q
                ->where('a', 1)
                ->orWhere(fn (QueryBuilder $inner) => $inner->where('b', 2)->where('c', 3)))
            ->toSql();

        $this->assertSame(
            'SELECT * FROM `docs` WHERE (`a` = :p0 OR (`b` = :p1 AND `c` = :p2))',
            $sql
        );
    }

    /**
     * A sub-builder with its own placeholder counter would emit a second :p0
     * and one value would overwrite the other.
     */
    public function testNestedBindingsDoNotCollide(): void
    {
        $builder = $this->builder()
            ->where('a', 1)
            ->where(fn (QueryBuilder $q) => $q->where('b', 2)->orWhere('c', 3))
            ->where('d', 4);

        $this->assertSame(
            'SELECT * FROM `docs` WHERE `a` = :p0 AND (`b` = :p1 OR `c` = :p2) AND `d` = :p3',
            $builder->toSql()
        );
        $this->assertSame(
            ['p0' => 1, 'p1' => 2, 'p2' => 3, 'p3' => 4],
            $builder->getBindings()
        );
    }

    public function testFlatChainStillFollowsSqlPrecedence(): void
    {
        // Unchanged on purpose: this is what SQL means, and silently
        // re-grouping it would break every existing query.
        $this->assertSame(
            'SELECT * FROM `docs` WHERE `a` = :p0 OR `b` = :p1 AND `c` = :p2',
            $this->builder()->where('a', 1)->orWhere('b', 2)->where('c', 3)->toSql()
        );
    }

    public function testEmptyClosureAddsNothingRatherThanEmptyParentheses(): void
    {
        $this->assertSame(
            'SELECT * FROM `docs` WHERE `a` = :p0',
            $this->builder()->where('a', 1)->where(fn (QueryBuilder $q) => null)->toSql()
        );
    }

    /**
     * The empty-whereIn guard has to hold inside a group, or the fail-open it
     * exists to prevent just moves inside the parentheses.
     */
    public function testEmptyWhereInNullifiesItsOwnGroup(): void
    {
        $sql = $this->builder()
            ->where('a', 1)
            ->orWhere(fn (QueryBuilder $q) => $q->where('b', 2)->orWhere('c', 3)->whereIn('d', []))
            ->toSql();

        $this->assertSame('SELECT * FROM `docs` WHERE `a` = :p0 OR (0 = 1)', $sql);
    }

    /**
     * ...and a group that is always false absorbs its AND-joined parent.
     */
    public function testAlwaysFalseGroupNullifiesTheWholeQuery(): void
    {
        $sql = $this->builder()
            ->where('a', 1)
            ->orWhere('b', 2)
            ->where(fn (QueryBuilder $q) => $q->whereIn('d', []))
            ->toSql();

        $this->assertSame('SELECT * FROM `docs` WHERE 0 = 1', $sql);
    }

    public function testIdentifierValidationAppliesInsideGroups(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid identifier');

        $this->builder()->where(fn (QueryBuilder $q) => $q->where('x = 1 OR 1=1 AND x', '>', 0));
    }

    public function testOperatorValidationAppliesInsideGroups(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid operator');

        $this->builder()->where(fn (QueryBuilder $q) => $q->where('x', '= 1 OR 1=1 AND x =', 0));
    }
}
