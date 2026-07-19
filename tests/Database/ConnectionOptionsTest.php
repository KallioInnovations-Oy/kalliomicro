<?php

declare(strict_types=1);

namespace Tests\Database;

use KallioMicro\Database\Connection;
use PDO;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The driver options were assembled with array_merge(), which RENUMBERS
 * integer keys — and every PDO attribute is an integer constant. The four
 * defaults came out as keys 0,1,2,3 with their values still in order, so each
 * setting landed on an unrelated attribute. Silently: key 3 is ATTR_ERRMODE
 * and it received `false`, i.e. ERRMODE_SILENT, so a stock connection stopped
 * throwing on SQL errors altogether.
 */
class ConnectionOptionsTest extends TestCase
{
    /**
     * @param array<int, mixed> $configured
     * @return array<int, mixed>
     */
    private function options(array $configured = []): array
    {
        $connection = new Connection([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'nonexistent',
            'username' => 'u',
            'password' => 'p',
            'options' => $configured,
        ]);

        $method = new ReflectionMethod($connection, 'buildOptions');

        return $method->invoke($connection);
    }

    public function testAttributeKeysSurviveIntact(): void
    {
        $options = $this->options();

        $this->assertSame(
            [
                PDO::ATTR_ERRMODE,
                PDO::ATTR_DEFAULT_FETCH_MODE,
                PDO::ATTR_EMULATE_PREPARES,
                PDO::ATTR_STRINGIFY_FETCHES,
            ],
            array_keys($options),
            'PDO attribute constants were renumbered into 0,1,2,3'
        );
    }

    /**
     * The headline consequence: errors stopped being exceptions.
     */
    public function testErrorModeIsException(): void
    {
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $this->options()[PDO::ATTR_ERRMODE]);
        $this->assertNotSame(PDO::ERRMODE_SILENT, $this->options()[PDO::ATTR_ERRMODE]);
    }

    /**
     * docs/database.md guarantees native prepared statements. The attribute
     * never reached PDO, so MySQL ran on the default — emulation ON.
     */
    public function testEmulatedPreparesAreDisabled(): void
    {
        $this->assertFalse($this->options()[PDO::ATTR_EMULATE_PREPARES]);
    }

    public function testFetchModeIsAssociative(): void
    {
        $this->assertSame(PDO::FETCH_ASSOC, $this->options()[PDO::ATTR_DEFAULT_FETCH_MODE]);
    }

    /**
     * docs/database.md offers connection options as the way to opt into
     * matched-row semantics. Renumbering broke that escape hatch too — the
     * key became 4 and PDO never saw it.
     */
    public function testConfiguredOptionsReachPdoUnderTheirOwnKeys(): void
    {
        $options = $this->options([PDO::MYSQL_ATTR_FOUND_ROWS => true]);

        $this->assertArrayHasKey(PDO::MYSQL_ATTR_FOUND_ROWS, $options);
        $this->assertTrue($options[PDO::MYSQL_ATTR_FOUND_ROWS]);
    }

    public function testConfiguredOptionsOverrideTheDefaults(): void
    {
        $options = $this->options([PDO::ATTR_EMULATE_PREPARES => true]);

        $this->assertTrue(
            $options[PDO::ATTR_EMULATE_PREPARES],
            'config must have the last word over framework defaults'
        );
    }

    public function testDefaultsSurviveANonArrayOptionsValue(): void
    {
        $connection = new Connection([
            'driver' => 'mysql',
            'host' => '127.0.0.1',
            'database' => 'nonexistent',
            'username' => 'u',
            'password' => 'p',
        ]);

        $options = (new ReflectionMethod($connection, 'buildOptions'))->invoke($connection);

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
    }
}
