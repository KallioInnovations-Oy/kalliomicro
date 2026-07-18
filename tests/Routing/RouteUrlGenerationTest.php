<?php

declare(strict_types=1);

namespace Tests\Routing;

use InvalidArgumentException;
use KallioMicro\Routing\Route;
use Tests\TestCase;

class RouteUrlGenerationTest extends TestCase
{
    private function route(string $path): Route
    {
        return new Route('GET', $path, fn () => null);
    }

    public function testParameterValuesAreEncodedAsSinglePathSegments(): void
    {
        // Regression: values were substituted raw, so an id could graft a query
        // string and fragment onto the URL, or invent an extra path segment
        $route = $this->route('/users/{id}');

        $this->assertSame('/users/1%3Fx%3D2%23y', $route->generateUrl(['id' => '1?x=2#y']));
        $this->assertSame('/users/a%2Fb', $route->generateUrl(['id' => 'a/b']));
        $this->assertSame('/users/42', $route->generateUrl(['id' => '42']));
    }

    public function testMissingRequiredParameterThrowsSelfDescribingError(): void
    {
        // Regression: this used to emit the literal "/users/{id}" into an href
        try {
            $this->route('/users/{id}')->generateUrl([]);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('/users/{id}', $e->getMessage());
            $this->assertStringContainsString('[id]', $e->getMessage());
            $this->assertStringContainsString('none', $e->getMessage());
        }
    }

    public function testMissingRequiredParameterNamesWhatWasGiven(): void
    {
        try {
            $this->route('/posts/{post}/comments/{comment}')->generateUrl(['post' => '7']);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('[comment]', $e->getMessage());
            $this->assertStringContainsString('post', $e->getMessage());
        }
    }

    public function testOptionalParametersStayOptional(): void
    {
        $route = $this->route('/users/{id?}');

        $this->assertSame('/users', $route->generateUrl([]));
        $this->assertSame('/users/5', $route->generateUrl(['id' => '5']));
    }

    public function testGeneratedUrlRoundTripsThroughParameterExtraction(): void
    {
        // Encoding on the way out and decoding on the way in must agree,
        // otherwise url() produces links the router cannot match back
        $route = $this->route('/tags/{tag}');

        $url = $route->generateUrl(['tag' => 'a/b c']);

        $this->assertSame(['tag' => 'a/b c'], $route->extractParams($url));
    }
}
