<?php

declare(strict_types=1);

namespace Tests\Console;

use DateTime;
use KallioMicro\Console\Commands\ScheduleRunCommand;
use KallioMicro\Console\Console;
use ReflectionMethod;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    private function isDue(string $expression, string $time): bool
    {
        $command = new ScheduleRunCommand(new Console($this->app));
        $method = new ReflectionMethod($command, 'isDue');

        return $method->invoke($command, $expression, new DateTime($time));
    }

    public function testSchedulingSameCommandTwiceKeepsBothEntries(): void
    {
        $console = new Console($this->app);
        $console->schedule('task:backup', '0 2 * * *');
        $console->schedule('task:backup', '0 14 * * *');

        $this->assertCount(2, $console->getScheduledTasks());
    }

    public function testWildcardIsAlwaysDue(): void
    {
        $this->assertTrue($this->isDue('* * * * *', '2026-07-17 13:37'));
    }

    public function testExactMinuteAndHour(): void
    {
        $this->assertTrue($this->isDue('30 2 * * *', '2026-07-17 02:30'));
        $this->assertFalse($this->isDue('30 2 * * *', '2026-07-17 02:31'));
        $this->assertFalse($this->isDue('30 2 * * *', '2026-07-17 03:30'));
    }

    public function testStepExpression(): void
    {
        $this->assertTrue($this->isDue('*/5 * * * *', '2026-07-17 10:10'));
        $this->assertFalse($this->isDue('*/5 * * * *', '2026-07-17 10:11'));
    }

    public function testRangeExpression(): void
    {
        $this->assertTrue($this->isDue('10-20 * * * *', '2026-07-17 10:15'));
        $this->assertFalse($this->isDue('10-20 * * * *', '2026-07-17 10:25'));
    }

    public function testListExpression(): void
    {
        $this->assertTrue($this->isDue('1,3,5 * * * *', '2026-07-17 10:03'));
        $this->assertFalse($this->isDue('1,3,5 * * * *', '2026-07-17 10:04'));
    }

    public function testRangeWithStep(): void
    {
        $this->assertTrue($this->isDue('10-20/5 * * * *', '2026-07-17 10:15'));
        $this->assertFalse($this->isDue('10-20/5 * * * *', '2026-07-17 10:14'));
    }

    public function testMalformedExpressionIsNeverDue(): void
    {
        $this->assertFalse($this->isDue('* * *', '2026-07-17 10:00'));
    }

    public function testZeroStepDoesNotCrashAndIsNeverDue(): void
    {
        $this->assertFalse($this->isDue('*/0 * * * *', '2026-07-17 10:00'));
        $this->assertFalse($this->isDue('10-20/0 * * * *', '2026-07-17 10:15'));
    }

    public function testListWithStepIsUnsupportedAndNeverDue(): void
    {
        // '10,20/2' is outside the documented grammar (steps apply to * and
        // ranges only); it must consistently never match rather than crash
        $this->assertFalse($this->isDue('10,20/2 * * * *', '2026-07-17 10:10'));
    }
}
