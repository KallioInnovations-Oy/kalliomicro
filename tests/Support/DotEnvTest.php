<?php

declare(strict_types=1);

namespace Tests\Support;

use KallioMicro\Support\DotEnv;
use RuntimeException;
use Tests\TestCase;

/**
 * parseValue() treated a quoted value as complete only if the line *ended*
 * with the quote character, so a quoted value followed by an inline comment
 * looked like the start of a multiline value and absorbed every subsequent
 * line. parse() never flushed after the loop, so if nothing closed the quote
 * the remaining variables were discarded with no error at all — production
 * silently running in debug mode against the wrong database.
 */
class DotEnvTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = sys_get_temp_dir() . '/km-dotenv-' . getmypid();
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->dir . '/.env');
        @rmdir($this->dir);

        parent::tearDown();
    }

    private function parse(string $contents): array
    {
        file_put_contents($this->dir . '/.env', $contents);

        $env = new DotEnv($this->dir);
        $env->load();

        return $env->all();
    }

    public function testQuotedValueWithInlineCommentDoesNotSwallowTheRestOfTheFile(): void
    {
        $vars = $this->parse(
            "APP_NAME=Kallio\n"
            . "APP_DEBUG=\"false\"   # set to true only on the staging box\n"
            . "APP_URL=https://real.example\n"
            . "DB_PASSWORD=s3cret\n"
        );

        $this->assertSame('false', $vars['APP_DEBUG']);
        $this->assertSame('https://real.example', $vars['APP_URL']);
        $this->assertSame('s3cret', $vars['DB_PASSWORD']);
    }

    public function testSingleQuotedValueWithInlineComment(): void
    {
        $vars = $this->parse("A='value' # comment\nB=2\n");

        $this->assertSame('value', $vars['A']);
        $this->assertSame('2', $vars['B']);
    }

    public function testMultilineQuotedValuesStillWork(): void
    {
        $vars = $this->parse("A=1\nMULTI=\"line one\nline two\"\nB=2\n");

        $this->assertSame("line one\nline two", $vars['MULTI']);
        $this->assertSame('2', $vars['B']);
    }

    public function testUnquotedInlineCommentStillStripped(): void
    {
        $this->assertSame('bare', $this->parse("A=bare # comment\n")['A']);
    }

    public function testHashInsideQuotesIsNotAComment(): void
    {
        $this->assertSame('a#b', $this->parse("A=\"a#b\"\n")['A']);
    }

    public function testEscapedQuoteDoesNotTerminateTheValue(): void
    {
        $this->assertSame('say "hi"', $this->parse("A=\"say \\\"hi\\\"\"\n")['A']);
    }

    /**
     * Silent partial config is the worst outcome available, so this raises.
     */
    public function testUnterminatedQuoteRaisesInsteadOfDiscardingSilently(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unterminated double quote');

        $this->parse("A=1\nB=\"never closed\nC=3\n");
    }
}
