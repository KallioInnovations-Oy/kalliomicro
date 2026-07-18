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
        };
    }

    public function testRenderPartialDoesNotApplyTheTemplatesLayout(): void
    {
        $content = $this->controller()->runRenderPartial('modal');

        $this->assertStringNotContainsString('<!DOCTYPE html>', $content, 'a whole page document was injected into the modal body');
    }

    public function testRenderPartialStillRendersOrdinaryPartials(): void
    {
        $this->assertSame('&lt;b&gt;', $this->controller()->runRenderPartial('rows', ['label' => '<b>']));
    }
}
