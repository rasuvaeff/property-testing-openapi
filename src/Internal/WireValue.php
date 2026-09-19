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
 * A float is spelled the way `json_encode()` spells it: the shortest
 * decimal that reads back as the same double (`serialize_precision=-1`),
 * with an exponent where that is shorter. `(string)` rounds to `precision`
 * — fourteen significant digits — and put a *different* number on the wire
 * for any value that needed more: `846608010056.187` went as
 * `846608010056.19`, which is no longer a multiple of `0.001`, and the
 * value just below `1000.0` went as `1000`. The JSON number grammar is the
 * one the validator reads a numeric parameter by (#117), and an exponent
 * is part of it.
 *
 * @internal
 */
final readonly class WireValue
{
    /**
     * Returns `null` for a value that has no wire spelling — a container, an
     * object, or a float that is not a number.
     */
    public static function of(mixed $value): ?string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => is_finite($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => null,
        };
    }
}
