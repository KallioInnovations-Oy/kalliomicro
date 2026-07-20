<?php

declare(strict_types=1);

namespace KallioMicro\Database;

/**
 * RawExpression - Represents a raw SQL expression
 *
 * Used to insert raw SQL into queries without escaping. Lives in its own file
 * so `new RawExpression(...)` autoloads in a fresh process — declared inside
 * QueryBuilder.php it existed only once that class had been loaded.
 */
class RawExpression
{
    public function __construct(
        public readonly string $expression
    ) {}

    public function __toString(): string
    {
        return $this->expression;
    }
}
