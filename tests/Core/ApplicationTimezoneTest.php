<?php

declare(strict_types=1);

namespace Tests\Core;

use InvalidArgumentException;
use KallioMicro\Core\Config;
use Tests\TestCase;

/**
 * config/app.php has shipped a 'timezone' key since the beginning and nothing
 * ever read it, so every date() call ran on whatever php.ini said. A config key
 * that does nothing is worse than an absent one — it reads as a setting that
 * has been made.
 */
class ApplicationTimezoneTest extends TestCase
{
    private string $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = date_default_timezone_get();
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->original);
        parent::tearDown();
    }

    private function withTimezone(mixed $timezone): void
    {
        $config = new Config(sys_get_temp_dir() . '/km-tests-noconfig');
        $config->set('app.timezone', $timezone);
        $this->app->instance(Config::class, $config);
    }

    public function testBootAppliesTheConfiguredTimezone(): void
    {
        date_default_timezone_set('UTC');
        $this->withTimezone('Europe/Helsinki');

        $this->app->boot();

        $this->assertSame('Europe/Helsinki', date_default_timezone_get());
    }

    public function testAnUnknownTimezoneRaisesRatherThanRunningOnTheWrongClock(): void
    {
        $this->withTimezone('Europe/Helsinky');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('valid timezone identifier');

        $this->app->boot();
    }

    public function testANonStringTimezoneRaises(): void
    {
        $this->withTimezone(3);

        $this->expectException(InvalidArgumentException::class);

        $this->app->boot();
    }

    public function testAnAbsentTimezoneLeavesTheDefaultAlone(): void
    {
        date_default_timezone_set('UTC');
        $this->withTimezone(null);

        $this->app->boot();

        $this->assertSame('UTC', date_default_timezone_get());
    }
}
