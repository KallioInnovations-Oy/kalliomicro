<?php

declare(strict_types=1);

namespace Tests\Database;

use InvalidArgumentException;
use KallioMicro\Database\Connection;
use Tests\Support\FakeConnection;
use Tests\TestCase;

class QueryBuilderColumnResultTest extends TestCase
{
    private FakeConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->connection = new FakeConnection();
    }

    /**
     * A qualified column arrives keyed by its bare name — MySQL labels
     * `users`.`name` as 'name' — so array_column($rows, 'users.name') found
     * nothing and pluck() silently returned an empty array.
     */
    public function testPluckHandlesAQualifiedColumn(): void
    {
        $this->connection->queueSelect([
            ['name' => 'Ada'],
            ['name' => 'Grace'],
        ]);

        $this->assertSame(
            ['Ada', 'Grace'],
            $this->connection->table('users')->pluck('users.name')
        );
    }

    public function testPluckHandlesQualifiedColumnAndKey(): void
    {
        $this->connection->queueSelect([
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Grace'],
        ]);

        $this->assertSame(
            [1 => 'Ada', 2 => 'Grace'],
            $this->connection->table('users')->pluck('users.name', 'users.id')
        );
    }

    public function testPluckStillHandlesBareColumns(): void
    {
        $this->connection->queueSelect([['name' => 'Ada']]);

        $this->assertSame(['Ada'], $this->connection->table('users')->pluck('name'));
    }

    /**
     * first() clamped and value() did not, so reading a single value pulled
     * the whole matching set across the wire.
     */
    public function testValueLimitsToOneRow(): void
    {
        $this->connection->queueSelectValue('ada@example.com');
        $this->connection->table('users')->where('active', 1)->value('email');

        $this->assertStringContainsString(
            'LIMIT 1',
            $this->connection->queries[0]['sql']
        );
    }

    /**
     * @dataProvider invalidCharsetTokens
     */
    public function testInvalidCharsetIsRejectedBeforeInterpolation(string $charset): void
    {
        $connection = new Connection([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'nonexistent',
            'username' => 'u',
            'password' => 'p',
            'charset' => $charset,
        ]);

        // Reached via the private validator rather than a live connection —
        // the point is that the value never reaches string interpolation.
        $method = new \ReflectionMethod($connection, 'validateCharsetToken');

        $this->expectException(InvalidArgumentException::class);
        $method->invoke($connection, $charset, 'charset');
    }

    public static function invalidCharsetTokens(): array
    {
        return [
            'quote break-out' => ["utf8mb4'; DROP TABLE core_users; --"],
            'space'           => ['utf8mb4 utf8'],
            'empty'           => [''],
        ];
    }

    public function testValidCharsetPasses(): void
    {
        $connection = new Connection([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'nonexistent',
            'username' => 'u',
            'password' => 'p',
        ]);

        $method = new \ReflectionMethod($connection, 'validateCharsetToken');

        $this->assertSame('utf8mb4', $method->invoke($connection, 'utf8mb4', 'charset'));
        $this->assertSame(
            'utf8mb4_unicode_ci',
            $method->invoke($connection, 'utf8mb4_unicode_ci', 'collation')
        );
    }
}
