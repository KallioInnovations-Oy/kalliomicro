<?php

declare(strict_types=1);

namespace Tests\View;

use KallioMicro\View\ViewEngine;
use Tests\TestCase;

/**
 * renderFile() extracts view data into the same scope that holds the include
 * target. With extract()'s default EXTR_OVERWRITE, a data key named '__path'
 * replaced that target and the engine included an arbitrary file — remote code
 * execution for any downstream that flattens request input into view data
 * (the natural "repopulate the form" pattern).
 */
class ViewEngineDataIsolationTest extends TestCase
{
    private string $viewPath;
    private string $outsideFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->viewPath = sys_get_temp_dir() . '/km-view-isolation-' . getmypid();
        @mkdir($this->viewPath, 0777, true);
        file_put_contents($this->viewPath . '/page.php', 'INTENDED');

        $this->outsideFile = sys_get_temp_dir() . '/km-outside-' . getmypid() . '.php';
        file_put_contents($this->outsideFile, '<?php echo "ESCAPED"; ?>');
    }

    protected function tearDown(): void
    {
        @unlink($this->viewPath . '/page.php');
        @rmdir($this->viewPath);
        @unlink($this->outsideFile);

        parent::tearDown();
    }

    public function testDataKeyCannotRedirectTheIncludeTarget(): void
    {
        $engine = new ViewEngine($this->viewPath);

        $this->assertSame('INTENDED', $engine->render('page', ['__path' => $this->outsideFile]));
    }

    public function testDataKeyCannotReplaceTheDataArrayItself(): void
    {
        $engine = new ViewEngine($this->viewPath);

        $this->assertSame('INTENDED', $engine->render('page', ['__data' => ['x' => 1]]));
    }

    /**
     * extract() raises "Cannot re-assign $this" under EXTR_OVERWRITE; skipping
     * existing symbols means a 'this' key is simply unavailable instead.
     */
    public function testDataKeyNamedThisDoesNotFatal(): void
    {
        $engine = new ViewEngine($this->viewPath);

        $this->assertSame('INTENDED', $engine->render('page', ['this' => 'anything']));
    }

    public function testOrdinaryDataStillReachesTheTemplate(): void
    {
        file_put_contents($this->viewPath . '/greet.php', '<?php echo $name; ?>');

        $engine = new ViewEngine($this->viewPath);
        $this->assertSame('Ada', $engine->render('greet', ['name' => 'Ada']));

        @unlink($this->viewPath . '/greet.php');
    }
}
