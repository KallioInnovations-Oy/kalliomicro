<?php

declare(strict_types=1);

namespace Tests\Database;

use KallioMicro\Database\Connection;
use PDO;
use PDOException;
use PDOStatement;
use Tests\TestCase;

/**
 * lastStatement was assigned AFTER execute(), so a statement that threw left
 * it pointing at the previous one and affectedRows() went on reporting that
 * statement's count. A stale number is worse than zero: it reads as a
 * successful write that never happened.
 */
class ConnectionAffectedRowsTest extends TestCase
{
    public function testAffectedRowsIsNotStaleAfterAFailedStatement(): void
    {
        $succeeding = $this->createMock(PDOStatement::class);
        $succeeding->method('execute')->willReturn(true);
        $succeeding->method('rowCount')->willReturn(5);

        $failing = $this->createMock(PDOStatement::class);
        $failing->method('execute')->willThrowException(new PDOException('syntax error'));
        $failing->method('rowCount')->willReturn(0);

        $pdo = $this->createMock(PDO::class);
        $pdo->method('prepare')->willReturnOnConsecutiveCalls($succeeding, $failing);

        $connection = new class ($pdo) extends Connection {
            public function __construct(private PDO $handle)
            {
                parent::__construct(['driver' => 'mysql', 'database' => 'test']);
            }

            public function getPdo(): PDO
            {
                return $this->handle;
            }
        };

        $connection->query('UPDATE t SET a = 1');
        $this->assertSame(5, $connection->affectedRows());

        try {
            $connection->query('THIS IS NOT SQL');
            $this->fail('the failing statement should have thrown');
        } catch (PDOException) {
            // expected
        }

        $this->assertSame(
            0,
            $connection->affectedRows(),
            'affectedRows() must not report the previous statement after a failure'
        );
    }
}
