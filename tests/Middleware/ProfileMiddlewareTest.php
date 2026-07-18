<?php

declare(strict_types=1);

namespace Tests\Middleware;

use KallioMicro\Auth\Session;
use KallioMicro\Core\Config;
use KallioMicro\Http\Request;
use KallioMicro\Http\Response;
use KallioMicro\Middleware\ProfileMiddleware;
use Tests\TestCase;

class ProfileMiddlewareTest extends TestCase
{
    private function session(?int $profileId, bool $authenticated = true): Session
    {
        return new class (
            new Config(sys_get_temp_dir() . '/km-tests-noconfig'),
            $profileId,
            $authenticated
        ) extends Session {
            public function __construct(
                Config $config,
                private ?int $profileId,
                private bool $authenticated
            ) {
                parent::__construct($config);
            }

            public function isAuthenticated(): bool
            {
                return $this->authenticated;
            }

            public function getProfileId(): ?int
            {
                return $this->profileId;
            }
        };
    }

    private function next(): \Closure
    {
        return fn (Request $request): Response => Response::html('passed');
    }

    public function testAllowedProfilePasses(): void
    {
        $middleware = new ProfileMiddleware($this->session(2), 1, 2);

        $response = $middleware->handle(Request::create('/app', 'GET'), $this->next());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('passed', $response->getContent());
    }

    public function testDisallowedProfileIsForbidden(): void
    {
        $middleware = new ProfileMiddleware($this->session(3), 1, 2);

        $response = $middleware->handle(Request::create('/app', 'GET'), $this->next());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMissingProfileIdFailsClosedAgainstProfileZero(): void
    {
        // Loose in_array(null, [0]) is true: before the strict comparison this
        // admitted every authenticated user whose session has no profile_id
        $middleware = new ProfileMiddleware($this->session(null), 0);

        $response = $middleware->handle(Request::create('/app', 'GET'), $this->next());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMissingProfileIdFailsClosedForJsonToo(): void
    {
        $middleware = new ProfileMiddleware($this->session(null), 0);
        $request = Request::create('/api/x', 'GET', [], ['Accept' => 'application/json']);

        $response = $middleware->handle($request, $this->next());

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testProfileZeroIsStillAllowedWhenItIsTheRealProfileId(): void
    {
        // The strict fix must not lock out a genuine profile id of 0
        $middleware = new ProfileMiddleware($this->session(0), 0);

        $response = $middleware->handle(Request::create('/app', 'GET'), $this->next());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testUnauthenticatedIsRedirectedToLogin(): void
    {
        $middleware = new ProfileMiddleware($this->session(1, false), 1);

        $response = $middleware->handle(Request::create('/app', 'GET'), $this->next());

        $this->assertSame(302, $response->getStatusCode());
    }
}
