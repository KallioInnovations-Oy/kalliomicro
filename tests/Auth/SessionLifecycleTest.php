<?php

declare(strict_types=1);

namespace Tests\Auth;

use Tests\Support\ArraySession;
use Tests\TestCase;

/**
 * regenerateCsrfToken() had no caller beyond the one-time init, so the CSRF
 * token's validity window was the entire browser session — it survived login
 * AND logout, spanning every privilege boundary. logout() unset three keys and
 * left everything else (flash data, intended URL, an in-progress
 * impersonation) for the next person to use that browser.
 */
class SessionLifecycleTest extends TestCase
{
    private ArraySession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new ArraySession();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        parent::tearDown();
    }

    public function testLoginRotatesTheCsrfToken(): void
    {
        $before = $this->session->getCsrfToken();
        $this->assertNotSame('', $before);

        $this->session->login(['id' => 1, 'username' => 'bob']);

        $this->assertNotSame($before, $this->session->getCsrfToken());
    }

    public function testLogoutRotatesTheCsrfToken(): void
    {
        $this->session->login(['id' => 1, 'username' => 'bob']);
        $authenticatedToken = $this->session->getCsrfToken();

        $this->session->logout();

        $this->assertNotSame($authenticatedToken, $this->session->getCsrfToken());
        $this->assertNotSame('', $this->session->getCsrfToken());
    }

    public function testLogoutClearsEverySessionKey(): void
    {
        $this->session->login(['id' => 1, 'username' => 'bob']);
        $this->session->set('cart', ['item-1', 'item-2']);
        $this->session->flash('notice', 'saved');
        $this->session->setIntendedUrl('/app/secret');

        $this->session->logout();

        $this->assertFalse($this->session->isAuthenticated());
        $this->assertNull($this->session->get('cart'));
        $this->assertArrayNotHasKey('_flash', $_SESSION);
        $this->assertArrayNotHasKey('_intended_url', $_SESSION);
        $this->assertArrayNotHasKey('_user', $_SESSION);
    }

    /**
     * Logging out mid-impersonation used to leave _original_user and
     * _impersonating in the session entirely.
     */
    public function testLogoutClearsAnInProgressImpersonation(): void
    {
        $this->session->login(['id' => 1, 'username' => 'admin']);
        $this->session->impersonate(['id' => 9, 'username' => 'victim']);
        $this->assertTrue($this->session->isImpersonating());

        $this->session->logout();

        $this->assertFalse($this->session->isImpersonating());
        $this->assertArrayNotHasKey('_original_user', $_SESSION);
    }

    /**
     * docs/auth.md promises regeneration "on every privilege change", and
     * impersonation is the largest one the framework offers.
     */
    public function testImpersonationRegeneratesIdAndToken(): void
    {
        $this->session->login(['id' => 1, 'username' => 'admin']);

        $tokenAsAdmin = $this->session->getCsrfToken();
        $regeneratesBefore = $this->session->regenerateCount;

        $this->session->impersonate(['id' => 9, 'username' => 'victim']);

        $this->assertNotSame($tokenAsAdmin, $this->session->getCsrfToken());
        $this->assertGreaterThan($regeneratesBefore, $this->session->regenerateCount);
    }

    public function testStopImpersonatingRegeneratesIdAndTokenAndRestoresUser(): void
    {
        $this->session->login(['id' => 1, 'username' => 'admin']);
        $this->session->impersonate(['id' => 9, 'username' => 'victim']);

        $tokenAsVictim = $this->session->getCsrfToken();
        $regeneratesBefore = $this->session->regenerateCount;

        $this->session->stopImpersonating();

        $this->assertNotSame($tokenAsVictim, $this->session->getCsrfToken());
        $this->assertGreaterThan($regeneratesBefore, $this->session->regenerateCount);
        $this->assertSame('admin', $this->session->getUser()['username']);
        $this->assertFalse($this->session->isImpersonating());
    }

    /**
     * _login_time was written and never read by anything. It is the only
     * mechanism a deployment has for an idle/absolute timeout, which the base
     * deliberately does not ship as policy.
     */
    public function testLoginTimeIsReadable(): void
    {
        $this->assertNull($this->session->getLoginTime());

        $before = time();
        $this->session->login(['id' => 1, 'username' => 'bob']);

        $this->assertGreaterThanOrEqual($before, $this->session->getLoginTime());
    }

    public function testLoginTimeIsClearedByLogout(): void
    {
        $this->session->login(['id' => 1, 'username' => 'bob']);
        $this->session->logout();

        $this->assertNull($this->session->getLoginTime());
    }
}
