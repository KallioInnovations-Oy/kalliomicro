<?php

declare(strict_types=1);

namespace Tests\Http;

use KallioMicro\Http\Response;
use Tests\TestCase;

/**
 * Content-Disposition encoding in Response::download() / Response::file().
 *
 * The filename used to be interpolated verbatim into the quoted filename=
 * parameter, so a `"` produced a malformed header and non-ASCII names were
 * shipped as raw bytes instead of an RFC 6266 filename* parameter.
 */
class ResponseDispositionTest extends TestCase
{
    public function testPlainAsciiFilenamePassesThroughUnchanged(): void
    {
        $response = Response::download('data', 'report.pdf');

        $this->assertSame(
            'attachment; filename="report.pdf"',
            $response->getHeader('Content-Disposition'),
            'a safe name must not grow a filename* parameter'
        );
        $this->assertSame('application/octet-stream', $response->getHeader('Content-Type'));
        $this->assertSame('4', $response->getHeader('Content-Length'));
    }

    public function testFileUsesInlineDisposition(): void
    {
        $response = Response::file('data', 'report.pdf', 'application/pdf');

        $this->assertSame('inline; filename="report.pdf"', $response->getHeader('Content-Disposition'));
        $this->assertSame('application/pdf', $response->getHeader('Content-Type'));
    }

    public function testDoubleQuoteCannotBreakTheQuotedString(): void
    {
        $response = Response::download('data', 'Report "Q2".pdf');

        $this->assertSame(
            'attachment; filename="Report _Q2_.pdf"; filename*=UTF-8\'\'Report%20%22Q2%22.pdf',
            $response->getHeader('Content-Disposition')
        );
    }

    public function testBackslashAndPercentAreReplacedInTheFallback(): void
    {
        $response = Response::download('data', 'a\\b%20c.txt');

        $this->assertSame(
            'attachment; filename="a_b_20c.txt"; filename*=UTF-8\'\'a%5Cb%2520c.txt',
            $response->getHeader('Content-Disposition')
        );
    }

    public function testNonAsciiFilenameGetsAnRfc5987ExtendedParameter(): void
    {
        $response = Response::download('data', 'tilinpäätös.pdf');

        $this->assertSame(
            'attachment; filename="tilinp__t_s.pdf"; filename*=UTF-8\'\'tilinp%C3%A4%C3%A4t%C3%B6s.pdf',
            $response->getHeader('Content-Disposition')
        );
    }

    public function testControlCharactersNeverReachTheHeaderValue(): void
    {
        // PHP's header() refuses values containing CR/LF outright — the
        // fallback must neutralise them or send() would drop the header.
        $response = Response::file("data", "evil\r\nX-Injected: 1.txt", 'text/plain');

        $disposition = $response->getHeader('Content-Disposition');

        $this->assertStringNotContainsString("\r", $disposition);
        $this->assertStringNotContainsString("\n", $disposition);
        $this->assertStringStartsWith('inline; filename="evil__X-Injected: 1.txt"', $disposition);
    }
}
