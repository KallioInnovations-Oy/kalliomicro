<?php

declare(strict_types=1);

namespace Tests\View;

use KallioMicro\View\ViewEngine;
use RuntimeException;
use Tests\TestCase;

/**
 * Template resolution answered "yes" for anything on disk, directories
 * included: `exists('assessments')` was true because resources/views/assessments
 * is a folder, and rendering it then failed deep inside include() instead of
 * with a self-describing "View not found".
 */
class ViewEngineExistsTest extends TestCase
{
    private string $viewPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewPath = sys_get_temp_dir() . '/km-view-exists-' . getmypid();
        @mkdir($this->viewPath . '/assessments', 0777, true);
        file_put_contents($this->viewPath . '/assessments/form.php', 'FORM');
    }

    protected function tearDown(): void
    {
        @unlink($this->viewPath . '/assessments/form.php');
        @rmdir($this->viewPath . '/assessments');
        @rmdir($this->viewPath);

        parent::tearDown();
    }

    public function testDirectoryIsNotATemplate(): void
    {
        $engine = new ViewEngine($this->viewPath);

        $this->assertFalse($engine->exists('assessments'));
    }

    public function testRenderingADirectoryReportsViewNotFound(): void
    {
        $engine = new ViewEngine($this->viewPath);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('View not found');
        $engine->render('assessments');
    }

    public function testRealTemplatesStillResolve(): void
    {
        $engine = new ViewEngine($this->viewPath);

        $this->assertTrue($engine->exists('assessments.form'));
        $this->assertSame('FORM', $engine->render('assessments.form'));
    }
}
