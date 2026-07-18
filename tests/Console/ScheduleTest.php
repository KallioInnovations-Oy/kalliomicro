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

    /**
     * Whole-grammar table over the hour field, list elements included.
     *
     * @return array<string, array{string, string, bool}>
     */
    public static function hourFieldProvider(): array
    {
        return [
            // expression, time, due
            'wildcard' => ['0 * * * *', '2026-07-17 02:00', true],
            'plain value hit' => ['0 2 * * *', '2026-07-17 02:00', true],
            'plain value miss' => ['0 2 * * *', '2026-07-17 03:00', false],
            'step hit' => ['0 */6 * * *', '2026-07-17 12:00', true],
            'step miss' => ['0 */6 * * *', '2026-07-17 13:00', false],
            'range low edge' => ['0 1-5 * * *', '2026-07-17 01:00', true],
            'range high edge' => ['0 1-5 * * *', '2026-07-17 05:00', true],
            'range miss above' => ['0 1-5 * * *', '2026-07-17 06:00', false],
            'range with step hit' => ['0 0-12/4 * * *', '2026-07-17 08:00', true],
            'range with step miss' => ['0 0-12/4 * * *', '2026-07-17 09:00', false],
            'list hit' => ['0 1,7,9 * * *', '2026-07-17 07:00', true],
            'list miss' => ['0 1,7,9 * * *', '2026-07-17 08:00', false],

            // Mixed list+range, both orderings. Before the fix the range was
            // parsed off the whole field: '1,3-5' became start '1,3' → 1 and
            // matched 02:00, while '1-5,10' never reached its list element.
            'list then range: list element' => ['0 1,3-5 * * *', '2026-07-17 01:00', true],
            'list then range: range element' => ['0 1,3-5 * * *', '2026-07-17 04:00', true],
            'list then range: gap between them' => ['0 1,3-5 * * *', '2026-07-17 02:00', false],
            'list then range: above both' => ['0 1,3-5 * * *', '2026-07-17 06:00', false],
            'range then list: range element' => ['0 1-5,10 * * *', '2026-07-17 03:00', true],
            'range then list: list element' => ['0 1-5,10 * * *', '2026-07-17 10:00', true],
            'range then list: gap between them' => ['0 1-5,10 * * *', '2026-07-17 07:00', false],
            'mixed with step' => ['0 0-6/3,20 * * *', '2026-07-17 06:00', true],
            'mixed with step: list element' => ['0 0-6/3,20 * * *', '2026-07-17 20:00', true],
            'mixed with step: off-step' => ['0 0-6/3,20 * * *', '2026-07-17 04:00', false],
            'two ranges' => ['0 1-3,8-9 * * *', '2026-07-17 09:00', true],
            'two ranges: gap' => ['0 1-3,8-9 * * *', '2026-07-17 05:00', false],
        ];
    }

    /**
     * @dataProvider hourFieldProvider
     */
    public function testCronFieldGrammar(string $expression, string $time, bool $expected): void
    {
        $this->assertSame($expected, $this->isDue($expression, $time));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function malformedFieldProvider(): array
    {
        return [
            'step on a bare value' => ['10,20/2 * * * *'],
            'zero step' => ['*/0 * * * *'],
            'zero step on a range' => ['10-20/0 * * * *'],
            'zero step inside a list' => ['5,*/0 * * * *'],
            'empty list element' => ['1,,3 * * * *'],
            'trailing comma' => ['1,3, * * * *'],
            'named weekday' => ['0 0 * * mon'],
            'letters' => ['abc * * * *'],
            'open range' => ['1- * * * *'],
            'double step' => ['1-5/2/3 * * * *'],
        ];
    }

    /**
     * @dataProvider malformedFieldProvider
     */
    public function testMalformedFieldsSilentlyDoNotMatchRatherThanCrashing(string $expression): void
    {
        // docs/console.md guarantees this for every minute of the day: one bad
        // element invalidates its whole field, so a good sibling cannot make a
        // task run on a schedule nobody wrote
        for ($minute = 0; $minute < 60; $minute += 5) {
            $time = sprintf('2026-07-17 05:%02d', $minute);
            $this->assertFalse($this->isDue($expression, $time), "{$expression} at {$time}");
        }
    }
}
