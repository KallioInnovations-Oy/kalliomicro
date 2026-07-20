<?php

declare(strict_types=1);

namespace Tests\Database;

use BadMethodCallException;
use Tests\Support\FakeConnection;
use Tests\TestCase;

class QueryBuilderCallTest extends TestCase
{
    public function testUnknownMethodPointsAtDocs(): void
    {
        try {
            (new FakeConnection())->table('users')->fooBar();
            $this->fail('Expected BadMethodCallException');
        } catch (BadMethodCallException $e) {
            $this->assertStringContainsString('fooBar', $e->getMessage());
            $this->assertStringContainsString('docs/database.md', $e->getMessage());
        }
    }

    public function testLaravelChunkGetsForPageHint(): void
    {
        try {
            (new FakeConnection())->table('users')->chunk(100, fn () => null);
            $this->fail('Expected BadMethodCallException');
        } catch (BadMethodCallException $e) {
            $this->assertStringContainsString('forPage', $e->getMessage());
        }
    }

    public function testLaravelFindGetsFirstHint(): void
    {
        try {
            (new FakeConnection())->table('users')->find(1);
            $this->fail('Expected BadMethodCallException');
        } catch (BadMethodCallException $e) {
            $this->assertStringContainsString('first()', $e->getMessage());
        }
    }
}
