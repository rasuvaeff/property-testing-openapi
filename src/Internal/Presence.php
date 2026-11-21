<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * Whether an optional member is sent: a parameter, a multipart property. The
 * choice is drawn as `['included' => bool, 'value' => …]` and read back by
 * whoever assembles the case, so absent and present-but-empty stay apart.
 *
 * @internal
 */
final readonly class Presence
{
    public static function included(ArbitraryInterface $value): ArbitraryInterface
    {
        return Gen::map($value, static fn(mixed $value): array => ['included' => true, 'value' => $value]);
    }

    public static function optional(ArbitraryInterface $value): ArbitraryInterface
    {
        return Gen::frequency([
            [1, Gen::constant(['included' => false])],
            [1, self::included($value)],
        ]);
    }
}
