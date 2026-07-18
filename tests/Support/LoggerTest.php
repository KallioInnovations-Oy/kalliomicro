<?php

declare(strict_types=1);

namespace Tests\Support;

use KallioMicro\Support\Logger;
use Tests\TestCase;

/**
 * The file writer interpolated the message straight into a newline-terminated
 * sprintf, so anything logging a username, a URL or an exception message
 * carrying user input could forge audit entries byte-identical in shape to
 * genuine ones. Separately, context values were read as mixed and handed to
 * int/?string/string parameters under strict_types, so a wrong-typed context
 * scalar raised a TypeError out of the logger and killed its caller.
 */
class LoggerTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = sys_get_temp_dir() . '/km-logger-' . getmypid() . '.log';
        @unlink($this->logFile);
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    private function logger(): Logger
    {
        return new Logger(null, $this->logFile);
    }

    private function lines(): array
    {
        return array_values(array_filter(explode("\n", (string) file_get_contents($this->logFile)), 'strlen'));
    }

    public function testForgedLogLineStaysOnOneLine(): void
    {
        $this->logger()->error("real entry\n[2026-07-18 00:00:00] [ERROR] [Auth] [user:0] forged entry");

        $this->assertCount(1, $this->lines());
    }

    public function testCarriageReturnsAreAlsoNeutralised(): void
    {
        $this->logger()->error("a\r\nb");
        $this->logger()->error("c\rd");

        $this->assertCount(2, $this->lines());
    }

    public function testNewlineInAContextInterpolatedValueCannotSplitTheLine(): void
    {
        // Interpolation happens before formatting, so a placeholder value is
        // just as capable of injecting a newline as the message itself.
        $this->logger()->error('user {name} failed', ['name' => "bob\n[FORGED] admin login"]);

        $this->assertCount(1, $this->lines());
    }

    public function testNewlineInTheSourceCannotSplitTheLine(): void
    {
        $this->logger()->error('msg', ['source' => "Auth\n[FORGED]"]);

        $this->assertCount(1, $this->lines());
    }

    public function testTheMessageIsStillLegible(): void
    {
        $this->logger()->error('plain message');

        $this->assertStringContainsString('plain message', $this->lines()[0]);
        $this->assertStringContainsString('[ERROR]', $this->lines()[0]);
    }

    /**
     * @dataProvider misTypedContext
     */
    public function testMisTypedContextDoesNotKillTheCaller(array $context): void
    {
        $this->logger()->error('message', $context);

        $this->assertCount(1, $this->lines());
    }

    public static function misTypedContext(): array
    {
        return [
            'string user_id'   => [['user_id' => '42']],
            'garbage user_id'  => [['user_id' => 'abc']],
            'array user_id'    => [['user_id' => ['x']]],
            'int source_id'    => [['source_id' => 123]],
            'int source'       => [['source' => 7]],
            'array source'     => [['source' => ['x']]],
            'null source'      => [['source' => null]],
        ];
    }

    public function testNumericStringUserIdIsStillHonoured(): void
    {
        $this->logger()->error('message', ['user_id' => '42']);

        $this->assertStringContainsString('[user:42]', $this->lines()[0]);
    }

    /**
     * getDefaultLogPath() used to consult a KALLIOMICRO_BASE_PATH constant that
     * only the console entry script defines — and that script passes an
     * explicit path, so the branch never ran. The web entry point, the one that
     * actually reaches this default, never defined it at all. Application owns
     * the base path, so a relocated src/ no longer sends web logs somewhere
     * nobody looks.
     */
    public function testDefaultLogPathFollowsTheApplicationBasePath(): void
    {
        $method = new \ReflectionMethod(Logger::class, 'getDefaultLogPath');

        $this->assertSame(
            $this->app->storagePath('logs/app.log'),
            $method->invoke(new Logger())
        );
    }

    public function testRemainingContextIsStillSerialised(): void
    {
        $this->logger()->error('message', ['order_id' => 7]);

        $this->assertStringContainsString('"order_id":7', $this->lines()[0]);
    }
}
