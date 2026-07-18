<?php

declare(strict_types=1);

namespace Tests\Support;

use KallioMicro\Auth\Session;
use KallioMicro\Core\Config;

/**
 * Session backed by plain $_SESSION with the native calls stubbed out
 *
 * The suite deliberately never calls session_start() (see StubSession), but
 * the login/logout lifecycle — which keys survive, when the CSRF token
 * rotates — is exactly the behaviour worth testing. Overriding only start()
 * and regenerate() leaves every mutation under test running for real.
 */
class ArraySession extends Session
{
    public int $regenerateCount = 0;

    public function __construct()
    {
        parent::__construct(new Config(sys_get_temp_dir() . '/km-tests-noconfig'));

        $_SESSION = [];
        $this->start();
    }

    public function start(): void
    {
        // Native session start is not available under PHPUnit; the CSRF token
        // is minted here so the rest of the class behaves as it would live.
        //
        // Written straight to $_SESSION rather than via regenerateCsrfToken():
        // that method calls ensureStarted(), the parent's `started` flag is
        // private and so never latches for a subclass, and the two recurse
        // until the process runs out of memory.
        if (!isset($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
        }
    }

    public function regenerate(bool $deleteOld = true): void
    {
        $this->regenerateCount++;
    }
}
