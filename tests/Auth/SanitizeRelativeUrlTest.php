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
}
