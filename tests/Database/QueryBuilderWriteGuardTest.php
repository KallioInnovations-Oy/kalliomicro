<?php

declare(strict_types=1);

namespace Tests\Database;

use RuntimeException;
use Tests\Support\FakeConnection;
use Tests\TestCase;

class QueryBuilderWriteGuardTest extends TestCase
{
    public function testQueryBuilderUpdateWithoutWhereThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to UPDATE');

        (new FakeConnection())->table('users')->update(['active' => 0]);
    }

    public function testQueryBuilderDeleteWithoutWhereThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to DELETE');

        (new FakeConnection())->table('users')->delete();
    }

    public function testConnectionUpdateWithEmptyWhereThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to UPDATE');

        (new FakeConnection())->update('users', ['active' => 0], []);
    }

    public function testConnectionDeleteWithEmptyWhereThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to DELETE');

        (new FakeConnection())->delete('users', []);
    }
}
