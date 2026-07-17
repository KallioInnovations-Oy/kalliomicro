<?php

declare(strict_types=1);

namespace Tests\Support;

use KallioMicro\Http\Controller;
use KallioMicro\Http\Response;

/**
 * Exposes protected Controller methods for testing.
 */
class TestableController extends Controller
{
    /**
     * @param array<string, string|string[]> $rules
     * @param array<string, string> $messages
     * @return array{valid: bool, errors: array<string, string[]>, data: array<string, mixed>}
     */
    public function runValidate(array $rules, array $messages = []): array
    {
        return $this->validate($rules, $messages);
    }

    public function runBack(): Response
    {
        return $this->back();
    }
}
