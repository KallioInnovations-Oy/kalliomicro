<?php

declare(strict_types=1);

namespace Tests\View;

use InvalidArgumentException;
use KallioMicro\View\ViewEngine;
use Tests\TestCase;

/**
 * The escaping helpers are the last stop before output, so their failure modes
 * are silent by nature: one invalid UTF-8 byte from a legacy import made
 * htmlspecialchars() return '' and blanked the WHOLE field, and a non-string
 * value behaved differently depending on whether the template reached for
 * $view->e() or the global e() — which docs/views.md calls interchangeable.
 */
class ViewEngineEscapingTest extends TestCase
{
    private ViewEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = new ViewEngine(sys_get_temp_dir());
    }

    public function testInvalidUtf8LosesOnlyTheBadByte(): void
    {
        $escaped = $this->engine->e("Bj\xF6rn Ahlst<r>m");

        $this->assertStringContainsString('Bj', $escaped, 'invalid byte blanked the entire value');
        $this->assertStringContainsString('rn Ahlst', $escaped);
        $this->assertStringContainsString('&lt;r&gt;', $escaped, 'escaping must still apply');
    }

    public function testAttrSurvivesInvalidUtf8(): void
    {
        $this->assertStringContainsString('Bj', $this->engine->attr("Bj\xF6rn"));
    }

    public function testJsSurvivesInvalidUtf8(): void
    {
        // json_encode() returns false on malformed UTF-8, which the string
        // return type turned into a TypeError mid-template.
        $this->assertStringContainsString('Bj', $this->engine->js("Bj\xF6rn"));
    }

    public function testValidUtf8IsUntouched(): void
    {
        $this->assertSame('Björn', $this->engine->e('Björn'));
        // json_encode() escapes non-ASCII by default — the flags stay as shipped
        $this->assertSame('"Bj\u00f6rn"', $this->engine->js('Björn'));
    }

    public function testNullAndScalarsEscapeIdenticallyInBothHelpers(): void
    {
        $this->assertSame('', $this->engine->e(null));
        $this->assertSame('', e(null));
        $this->assertSame('&lt;b&gt;', $this->engine->e('<b>'));
        $this->assertSame('&lt;b&gt;', e('<b>'));
        $this->assertSame('42', $this->engine->e(42));
        $this->assertSame('42', e(42));
        $this->assertSame('3.5', $this->engine->e(3.5));
        $this->assertSame('3.5', e(3.5));
    }

    public function testStringableObjectsEscapeInBothHelpers(): void
    {
        $value = new class {
            public function __toString(): string
            {
                return '<b>Ada</b>';
            }
        };

        $this->assertSame('&lt;b&gt;Ada&lt;/b&gt;', $this->engine->e($value));
        $this->assertSame('&lt;b&gt;Ada&lt;/b&gt;', e($value));
    }

    public function testArrayIsRejectedByTheMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine->e(['a', 'b']);
    }

    public function testArrayIsRejectedByTheHelper(): void
    {
        $this->expectException(InvalidArgumentException::class);
        e(['a', 'b']);
    }

    public function testNonStringableObjectIsRejectedByTheMethod(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->engine->e(new \stdClass());
    }

    public function testNonStringableObjectIsRejectedByTheHelper(): void
    {
        $this->expectException(InvalidArgumentException::class);
        e(new \stdClass());
    }
}
