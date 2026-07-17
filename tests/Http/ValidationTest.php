<?php

declare(strict_types=1);

namespace Tests\Http;

use InvalidArgumentException;
use KallioMicro\Http\Request;
use Tests\Support\TestableController;
use Tests\TestCase;

class ValidationTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function controller(array $input): TestableController
    {
        $this->app->instance(Request::class, Request::create('/submit', 'POST', $input));
        return new TestableController($this->app);
    }

    public function testRequiredFieldMissingFails(): void
    {
        $result = $this->controller([])->runValidate(['name' => 'required']);

        $this->assertFalse($result['valid']);
        $this->assertArrayHasKey('name', $result['errors']);
    }

    public function testValidInputPasses(): void
    {
        $result = $this->controller([
            'name' => 'Ville',
            'email' => 'ville@example.com',
            'age' => '42',
        ])->runValidate([
            'name' => 'required|string',
            'email' => 'required|email',
            'age' => 'required|integer',
        ]);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['errors']);
    }

    public function testInvalidEmailFails(): void
    {
        $result = $this->controller(['email' => 'not-an-email'])->runValidate(['email' => 'email']);

        $this->assertFalse($result['valid']);
    }

    public function testInRuleRejectsUnknownValue(): void
    {
        $result = $this->controller(['status' => 'bogus'])->runValidate(['status' => 'in:draft,published']);

        $this->assertFalse($result['valid']);
    }

    public function testConfirmedRuleRequiresMatchingField(): void
    {
        $result = $this->controller([
            'password' => 'secret123',
            'password_confirmation' => 'different',
        ])->runValidate(['password' => 'confirmed']);

        $this->assertFalse($result['valid']);
    }

    public function testRegexRuleWithCommasParsesWholePattern(): void
    {
        $result = $this->controller(['code' => 'ab12'])->runValidate(['code' => 'regex:/^[a-z]{2,4}\d{2,4}$/']);

        $this->assertTrue($result['valid']);
    }

    public function testMaxIsLengthSemanticsForPlainStrings(): void
    {
        // A 300-char string violates max:255 by length, even though it's not numeric
        $result = $this->controller(['bio' => str_repeat('a', 300)])->runValidate(['bio' => 'max:255']);

        $this->assertFalse($result['valid']);
    }

    public function testMaxIsNumericSemanticsWhenNumericRuleDeclared(): void
    {
        // '300' is only 3 chars, but numeric|max:255 must compare the value
        $result = $this->controller(['amount' => '300'])->runValidate(['amount' => 'numeric|max:255']);

        $this->assertFalse($result['valid']);
    }

    public function testUnknownRuleThrowsSelfDescribingBoundaryError(): void
    {
        try {
            $this->controller(['email' => 'a@b.c'])->runValidate(['email' => 'unique:users']);
            $this->fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unique', $e->getMessage());
            $this->assertStringContainsString('docs/validation.md', $e->getMessage());
            $this->assertStringContainsString('Shipped rules', $e->getMessage());
        }
    }
}
