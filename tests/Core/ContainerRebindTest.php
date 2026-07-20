<?php

declare(strict_types=1);

namespace Tests\Core;

use ArrayObject;
use KallioMicro\Core\Container;
use Tests\TestCase;

class ContainerRebindTest extends TestCase
{
    private function container(): Container
    {
        return new Container();
    }

    public function testRebindingAResolvedSingletonTakesEffect(): void
    {
        // The defect: make() returned the cached instance forever, so an
        // override registered after any resolve was silently ignored — a
        // downstream bootstrap was correct only by line ordering
        $container = $this->container();
        $container->singleton('svc', fn () => new ArrayObject(['base']));

        $this->assertSame('base', $container->make('svc')[0]);

        $container->singleton('svc', fn () => new ArrayObject(['override']));

        $this->assertSame('override', $container->make('svc')[0]);
    }

    public function testRebindingWithBindDowngradesResolvedSingleton(): void
    {
        $container = $this->container();
        $container->singleton('svc', fn () => new ArrayObject(['base']));
        $container->make('svc');

        $container->bind('svc', fn () => new ArrayObject(['override']));

        $first = $container->make('svc');
        $second = $container->make('svc');

        $this->assertSame('override', $first[0]);
        $this->assertNotSame($first, $second, 'bind() after singleton() must stop caching');
    }

    public function testSingletonStillReturnsTheSameInstance(): void
    {
        $container = $this->container();
        $container->singleton('svc', fn () => new ArrayObject(['base']));

        $this->assertSame($container->make('svc'), $container->make('svc'));
    }

    public function testInstanceSeamStillOverwritesAndWins(): void
    {
        // instance() is the documented test seam (docs/conventions.md) —
        // it must keep overwriting a previously resolved singleton
        $container = $this->container();
        $container->singleton('svc', fn () => new ArrayObject(['base']));
        $container->make('svc');

        $double = new ArrayObject(['double']);
        $container->instance('svc', $double);

        $this->assertSame($double, $container->make('svc'));
    }

    public function testRebindAfterInstanceReplacesTheSwappedDouble(): void
    {
        $container = $this->container();
        $container->instance('svc', new ArrayObject(['double']));

        $container->singleton('svc', fn () => new ArrayObject(['real']));

        $this->assertSame('real', $container->make('svc')[0]);
    }
}
