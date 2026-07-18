<?php

declare(strict_types=1);

namespace Tests\Support;

use Tests\TestCase;

class EnvHelperTest extends TestCase
{
    private const KEY = 'KM_ENV_HELPER_TEST';

    protected function tearDown(): void
    {
        unset($_ENV[self::KEY]);
        putenv(self::KEY);
        parent::tearDown();
    }

    private function env(string $raw): mixed
    {
        $_ENV[self::KEY] = $raw;
        return env(self::KEY);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function coercionProvider(): array
    {
        return [
            'true' => ['true', true],
            '(true)' => ['(true)', true],
            'TRUE uppercase' => ['TRUE', true],
            'false' => ['false', false],
            '(false)' => ['(false)', false],
            'FALSE uppercase' => ['FALSE', false],
            'off' => ['off', false],
            '(off)' => ['(off)', false],
            'OFF uppercase' => ['OFF', false],
            'no' => ['no', false],
            '(no)' => ['(no)', false],
            'disabled' => ['disabled', false],
            '(disabled)' => ['(disabled)', false],
            'null' => ['null', null],
            '(null)' => ['(null)', null],
            'empty' => ['empty', ''],
            '(empty)' => ['(empty)', ''],
            'plain string is untouched' => ['mysql', 'mysql'],
            'numeric string is untouched' => ['3306', '3306'],
            'on stays a truthy string' => ['on', 'on'],
        ];
    }

    /**
     * @dataProvider coercionProvider
     */
    public function testCoercion(string $raw, mixed $expected): void
    {
        $this->assertSame($expected, $this->env($raw));
    }

    public function testOffDoesNotEnableDebug(): void
    {
        // The defect: 'off' came back as a non-empty string, so APP_DEBUG=off
        // enabled debug and shipped stack traces from production
        $this->assertFalse((bool) $this->env('off'));
    }

    public function testMissingKeyReturnsDefault(): void
    {
        $this->assertSame('fallback', env('KM_ENV_HELPER_ABSENT', 'fallback'));
    }
}
