<?php

declare(strict_types=1);

namespace Tests\Auth;

use KallioMicro\Auth\Session;
use Tests\TestCase;

class SanitizeRelativeUrlTest extends TestCase
{
    public function testProtocolRelativeUrlCollapsesToRoot(): void
    {
        $this->assertSame('/', Session::sanitizeRelativeUrl('//evil.example/path'));
    }

    public function testAbsoluteUrlReducedToPathAndQuery(): void
    {
        $this->assertSame('/app/items?x=1', Session::sanitizeRelativeUrl('https://any.host/app/items?x=1'));
    }

    public function testBackslashTricksCollapseToRoot(): void
    {
        $this->assertSame('/', Session::sanitizeRelativeUrl('/\\evil.example'));
        $this->assertSame('/', Session::sanitizeRelativeUrl('\\evil.example'));
    }

    public function testNonRootedValueCollapsesToRoot(): void
    {
        $this->assertSame('/', Session::sanitizeRelativeUrl('evil.example/path'));
    }

    public function testCleanRelativePathPassesThrough(): void
    {
        $this->assertSame('/app/items?page=3', Session::sanitizeRelativeUrl('/app/items?page=3'));
    }

    /**
     * Regression: the // guard used to run before the absolute-URL reduction,
     * and parse_url() can itself produce a protocol-relative path. The result
     * was a working open redirect through both setIntendedUrl() and back().
     *
     * @dataProvider protocolRelativeAfterReduction
     */
    public function testAbsoluteUrlCannotSmuggleAProtocolRelativePath(string $url): void
    {
        $this->assertSame('/', Session::sanitizeRelativeUrl($url));
    }

    public static function protocolRelativeAfterReduction(): array
    {
        return [
            'double slash after host'  => ['https://app.example.com//evil.example/x'],
            'same host as victim'      => ['https://victimhost//evil.example/x'],
            'backslash after host'     => ['https://app.example.com/\\evil.example/x'],
            'trailing double slash'    => ['https://app.example.com//'],
            'scheme-relative directly' => ['//evil.example/x'],
        ];
    }

    /**
     * @dataProvider controlCharacterUrls
     */
    public function testControlCharactersCollapseToRoot(string $url): void
    {
        $this->assertSame('/', Session::sanitizeRelativeUrl($url));
    }

    public static function controlCharacterUrls(): array
    {
        return [
            'CRLF header split' => ["/ok\r\nSet-Cookie: a=b"],
            'bare newline'      => ["/ok\nX: y"],
            'NUL truncation'    => ["/ok\0/evil"],
        ];
    }

    public function testBackslashSlashIsAlsoProtocolRelative(): void
    {
        $this->assertSame('/', Session::sanitizeRelativeUrl('\\/evil.example'));
        $this->assertSame('/', Session::sanitizeRelativeUrl('\\\\evil.example'));
    }
}
