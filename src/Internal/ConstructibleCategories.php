<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Uniformly weighted choice among the misuse categories a document actually
 * admits.
 *
 * A category that cannot be constructed for this operation fails closed with
 * {@see UnsupportedGeneration} at build time, and is dropped here rather than
 * propagated: a document that declares no enum simply has no enum misuse. No
 * category surviving is itself an error, and the caller words it.
 *
 * @internal
 */
final readonly class ConstructibleCategories
{
    /**
     * @template T
     * @param list<\Closure(): ArbitraryInterface<T>> $factories
     * @param non-empty-string $missing
     * @return ArbitraryInterface<T>
     */
    public static function anyOf(array $factories, string $missing): ArbitraryInterface
    {
        $pairs = [];
        foreach ($factories as $factory) {
            try {
                $pairs[] = [1, $factory()];
            } catch (UnsupportedGeneration) {
            }
        }
        if ($pairs === []) {
            throw new UnsupportedGeneration($missing);
        }

        return Gen::frequency($pairs);
    }
}
