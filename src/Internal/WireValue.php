<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

/**
 * The wire spelling of a scalar value.
 *
 * Four call sites carried this conversion, differing only in how they worded
 * the failure — so the conversion is here and the wording stays with the
 * caller, which is the one that knows what the value was supposed to be.
 *
 * @internal
 */
final readonly class WireValue
{
    /** Returns `null` for a value that has no wire spelling. */
    public static function of(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => null,
        };
    }
}
