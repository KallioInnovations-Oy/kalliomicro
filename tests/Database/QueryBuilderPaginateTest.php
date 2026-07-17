<?php

declare(strict_types=1);

namespace Tests\Database;

use RuntimeException;
use Tests\Support\FakeConnection;
use Tests\TestCase;

class QueryBuilderPaginateTest extends TestCase
{
    public function testPaginateReturnsDataAndMetadata(): void
    {
        $connection = new FakeConnection();
        $connection->queueSelectOne(['aggregate' => 5]);
        $connection->queueSelect([['id' => 3], ['id' => 4]]);

        $result = $connection->table('users')->paginate(2, 2);

        $this->assertSame([['id' => 3], ['id' => 4]], $result['data']);
        $this->assertSame(5, $result['total']);
        $this->assertSame(2, $result['per_page']);
        $this->assertSame(2, $result['current_page']);
        $this->assertSame(3, $result['last_page']);
        $this->assertSame(3, $result['from']);
        $this->assertSame(4, $result['to']);
    }

    public function testCountCloneStripsOrderByAndLimit(): void
    {
        $connection = new FakeConnection();
        $connection->queueSelectOne(['aggregate' => 10]);
        $connection->queueSelect([['id' => 1]]);

        $connection->table('users')->orderBy('name')->limit(3)->paginate(1, 15);

        [$countQuery, $dataQuery] = $connection->queries;

        $this->assertStringContainsString('COUNT(*)', $countQuery['sql']);
        $this->assertStringNotContainsString('ORDER BY', $countQuery['sql']);
        $this->assertStringContainsString('ORDER BY', $dataQuery['sql']);
        $this->assertStringContainsString('LIMIT 15', $dataQuery['sql']);
    }

    public function testEmptyResultSkipsDataQuery(): void
    {
        $connection = new FakeConnection();
        $connection->queueSelectOne(['aggregate' => 0]);

        $result = $connection->table('users')->paginate(1, 15);

        $this->assertSame([], $result['data']);
        $this->assertSame(0, $result['total']);
        $this->assertSame(1, $result['last_page']);
        $this->assertNull($result['from']);
        $this->assertNull($result['to']);
        $this->assertCount(1, $connection->queries);
    }

    public function testPageAndPerPageClampToOne(): void
    {
        $connection = new FakeConnection();
        $connection->queueSelectOne(['aggregate' => 1]);
        $connection->queueSelect([['id' => 1]]);

        $result = $connection->table('users')->paginate(-3, 0);

        $this->assertSame(1, $result['current_page']);
        $this->assertSame(1, $result['per_page']);
    }

    public function testPaginateWithGroupByThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('groupBy');

        (new FakeConnection())->table('users')->groupBy('role')->paginate();
    }

    public function testPaginateWithDistinctThrows(): void
    {
        // SELECT DISTINCT COUNT(*) would count duplicates — refuse loudly
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('distinct');

        (new FakeConnection())->table('users')->distinct()->paginate();
    }

    public function testPaginateDoesNotMutateTheBuilder(): void
    {
        $connection = new FakeConnection();
        $connection->queueSelectOne(['aggregate' => 5]);
        $connection->queueSelect([['id' => 1]]);

        $qb = $connection->table('users')->where('active', 1);
        $qb->paginate(2, 2);

        // The builder must stay reusable: no page slice left behind
        $this->assertStringNotContainsString('LIMIT', $qb->toSql());
        $this->assertStringNotContainsString('OFFSET', $qb->toSql());
    }

    public function testOutOfRangePageSkipsTheDataQuery(): void
    {
        $connection = new FakeConnection();
        $connection->queueSelectOne(['aggregate' => 50]);

        $result = $connection->table('users')->paginate(500, 15);

        $this->assertSame([], $result['data']);
        $this->assertSame(50, $result['total']);
        $this->assertNull($result['from']);
        $this->assertCount(1, $connection->queries);
    }
}
