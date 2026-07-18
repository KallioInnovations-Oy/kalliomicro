<?php

declare(strict_types=1);

namespace Tests\View;

use KallioMicro\Http\Controller;
use KallioMicro\View\ViewEngine;
use Tests\TestCase;

/**
 * renderPartial() is the documented way to build modal content
 * (docs/api-response.md). It called render(), so a modal template that happens
 * to call extends() — the pattern every full-page template uses — injected a
 * whole <!DOCTYPE html> document into the modal body.
 */
class ControllerRenderPartialTest extends TestCase
{
    private string $viewPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewPath = sys_get_temp_dir() . '/km-view-partial-' . getmypid();
        @mkdir($this->viewPath . '/layouts', 0777, true);
        file_put_contents($this->viewPath . '/layouts/app.php', '<!DOCTYPE html><body><?= $view->yield("content") ?></body>');
        file_put_contents(
            $this->viewPath . '/modal.php',
            '<?php $view->extends("layouts.app"); $view->section("content"); ?>'
            . 'MODAL BODY<?php $view->endSection(); ?>'
        );
        file_put_contents($this->viewPath . '/rows.php', '<?= $view->e($label) ?>');

        $this->app->instance(ViewEngine::class, new ViewEngine($this->viewPath));
    }

    protected function tearDown(): void
    {
        @unlink($this->viewPath . '/layouts/app.php');
        @unlink($this->viewPath . '/modal.php');
        @unlink($this->viewPath . '/rows.php');
        @rmdir($this->viewPath . '/layouts');
        @rmdir($this->viewPath);

        parent::tearDown();
    }

    private function controller(): object
    {
        return new class ($this->app) extends Controller {
            /**
             * @param array<string, mixed> $data
             */
            public function runRenderPartial(string $template, array $data = []): string
            {
                return $this->renderPartial($template, $data);
            }

            /**
             * @param array<string, mixed> $data
             */
            public function runRenderToResponse(string $template, string $target, array $data = []): \KallioMicro\Http\ApiResponse
            {
                return $this->renderToResponse($template, $target, $data);
            }
        };
    }

    /**
     * Rendering a page template as a partial used to inject the whole
     * <!DOCTYPE html> document into the modal body; 1.2.0 stopped that, but the
     * result was then an empty string — the body silently disappeared. Neither
     * is a usable outcome, so it now says so.
     */
    public function testRenderPartialRejectsATemplateThatExtendsALayout(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("View [modal] calls extends('layouts.app')");

        $this->controller()->runRenderPartial('modal');
    }

    public function testAFailedPartialDoesNotLeaveItsLayoutOnTheEngine(): void
    {
        try {
            $this->controller()->runRenderPartial('modal');
        } catch (\RuntimeException) {
            // expected
        }

        // The next render must not inherit the rejected partial's layout.
        $this->assertSame('&lt;b&gt;', $this->controller()->runRenderPartial('rows', ['label' => '<b>']));
    }

    public function testRenderPartialStillRendersOrdinaryPartials(): void
    {
        $this->assertSame('&lt;b&gt;', $this->controller()->runRenderPartial('rows', ['label' => '<b>']));
    }

    /**
     * renderToResponse() feeds its output into a DOM replace action, so it has
     * exactly the same constraint — and it was the second door to the same
     * bug, left open when 1.2.0 fixed renderPartial().
     */
    public function testRenderToResponseDoesNotApplyTheTemplatesLayout(): void
    {
        try {
            $this->controller()->runRenderToResponse('modal', '#target');
            $this->fail('a page template rendered into a DOM target should be rejected');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('rendered as a partial', $e->getMessage());
        }

        // An ordinary partial — the shape this method is actually for —
        // still reaches the target intact.
        $ordinary = json_encode(
            $this->controller()->runRenderToResponse('rows', '#target', ['label' => 'ROW'])->getActions()
        );
        $this->assertStringContainsString('ROW', (string) $ordinary);
        $this->assertStringContainsString('"target":"#target"', (string) $ordinary);
    }
}
