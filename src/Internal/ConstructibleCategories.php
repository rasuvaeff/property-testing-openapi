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
     * Builds every category, in registry order, and keeps the ones the
     * document admits.
     *
     * @template K of array-key
     * @template T
     * @param array<K, \Closure(): ArbitraryInterface<T>> $factories
     * @return array<K, ArbitraryInterface<T>>
     */
    public static function built(array $factories): array
    {
        $categories = [];
        foreach ($factories as $key => $factory) {
            try {
                $categories[$key] = $factory();
            } catch (UnsupportedGeneration) {
            }
        }

        return $categories;
    }

    /**
     * @template T
     * @param array<array-key, ArbitraryInterface<T>> $categories
     * @param non-empty-string $missing
     * @return ArbitraryInterface<T>
     */
    public static function union(array $categories, string $missing): ArbitraryInterface
    {
        if ($categories === []) {
            throw new UnsupportedGeneration($missing);
        }

        return Gen::frequency(array_map(static fn(ArbitraryInterface $category): array => [1, $category], array_values($categories)));
    }
}
