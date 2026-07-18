<?php

declare(strict_types=1);

namespace Tests\View;

use KallioMicro\View\ViewEngine;
use RuntimeException;
use Tests\TestCase;

/**
 * A template that throws must leave nothing behind: no half-rendered markup in
 * an output buffer the engine forgot to close, and no engine state that steers
 * the NEXT render. Both bit the singleton in production — the aborted page was
 * flushed at shutdown ahead of the error page, and the following render was
 * wrapped in a layout it never asked for.
 */
class ViewEngineRenderFailureTest extends TestCase
{
    private string $viewPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewPath = sys_get_temp_dir() . '/km-view-failure-' . getmypid();
        @mkdir($this->viewPath . '/layouts', 0777, true);

        file_put_contents(
            $this->viewPath . '/throws-in-section.php',
            '<?php $view->extends("layouts.wrap"); $view->section("content"); ?>'
            . 'HALF-RENDERED<?php throw new \RuntimeException("template blew up"); ?>'
        );
        file_put_contents(
            $this->viewPath . '/throws-plain.php',
            'HALF-RENDERED<?php throw new \RuntimeException("template blew up"); ?>'
        );
        file_put_contents($this->viewPath . '/plain.php', 'PLAIN');
        file_put_contents($this->viewPath . '/orphan-end.php', '<?php $view->endSection(); ?>');
        file_put_contents($this->viewPath . '/layouts/wrap.php', 'WRAPPED');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->viewPath . '/layouts/*') ?: [] as $file) {
            @unlink($file);
        }
        foreach (glob($this->viewPath . '/*.php') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->viewPath . '/layouts');
        @rmdir($this->viewPath);

        parent::tearDown();
    }

    /**
     * Runs a render expected to throw and reports how many output buffers it
     * left open, restoring the entry depth so a leak here cannot cascade into
     * the rest of the suite.
     */
    private function renderExpectingFailure(ViewEngine $engine, string $template): int
    {
        $entryLevel = ob_get_level();

        try {
            $engine->render($template);
            $this->fail("Expected {$template} to throw");
        } catch (RuntimeException) {
            $leaked = ob_get_level() - $entryLevel;
            while (ob_get_level() > $entryLevel) {
                ob_end_clean();
            }

            return $leaked;
        }
    }

    public function testThrowInsideSectionLeavesNoOpenBuffer(): void
    {
        $engine = new ViewEngine($this->viewPath);

        $this->assertSame(
            0,
            $this->renderExpectingFailure($engine, 'throws-in-section'),
            'aborted render left a buffer open; its output is flushed at shutdown ahead of the error page'
        );
    }

    public function testThrowOutsideSectionLeavesNoOpenBuffer(): void
    {
        $engine = new ViewEngine($this->viewPath);

        $this->assertSame(0, $this->renderExpectingFailure($engine, 'throws-plain'));
    }

    public function testFailedRenderDoesNotApplyItsLayoutToTheNextRender(): void
    {
        $engine = new ViewEngine($this->viewPath);
        $this->renderExpectingFailure($engine, 'throws-in-section');

        $this->assertSame('PLAIN', $engine->render('plain'), 'stale currentLayout leaked into the next render');
    }

    public function testFailedRenderDoesNotLeaveASectionOpenForTheNextRender(): void
    {
        $engine = new ViewEngine($this->viewPath);
        $this->renderExpectingFailure($engine, 'throws-in-section');

        // With a stale currentSection, endSection() silently swallows the next
        // render's own buffer instead of reporting the unbalanced call.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No section started');
        $engine->render('orphan-end');
    }

    public function testSectionsStillWorkAfterAFailedRender(): void
    {
        $engine = new ViewEngine($this->viewPath);
        $this->renderExpectingFailure($engine, 'throws-in-section');

        file_put_contents(
            $this->viewPath . '/sectioned.php',
            '<?php $view->section("content"); ?>BODY<?php $view->endSection(); ?>'
            . '<?= $view->yield("content") ?>'
        );

        $this->assertSame('BODY', $engine->render('sectioned'));
    }
}
