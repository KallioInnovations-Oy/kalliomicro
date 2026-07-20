<?php

declare(strict_types=1);

namespace Tests;

use KallioMicro\Core\Application;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case: boots a fresh Application per test so the global container
 * (app(), config()) always points at a known-clean instance.
 */
abstract class TestCase extends BaseTestCase
{
    protected Application $app;

    protected function setUp(): void
    {
        parent::setUp();

        $basePath = sys_get_temp_dir() . '/km-tests';
        foreach (['/resources/views', '/storage/cache/views', '/storage/framework'] as $dir) {
            if (!is_dir($basePath . $dir)) {
                mkdir($basePath . $dir, 0775, true);
            }
        }

        $this->app = new Application($basePath);
    }
}
