<?php

declare(strict_types=1);

namespace Tests\Support;

use KallioMicro\Auth\Session;
use KallioMicro\Core\Config;

/**
 * Session stand-in with a fixed CSRF token — avoids native session_start()
 * under PHPUnit entirely.
 */
class StubSession extends Session
{
    public function __construct(private string $validToken)
    {
        parent::__construct(new Config(sys_get_temp_dir() . '/km-tests-noconfig'));
    }

    public function verifyCsrfToken(?string $token): bool
    {
        return $token === $this->validToken;
    }
}
