<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile;

/**
 * The keywords of a Schema Object that describe a value without constraining
 * it, in one place: the conjunction of `allOf` carries them through rather
 * than merging them as constraints, a combinator admits them beside itself,
 * and a schema made of nothing else admits any value (#153, #160).
 *
 * A mirror of the contract's own annotation list (`JsonPointerResolver`,
 * private there) plus every specification extension (`x-*`) and the
 * `discriminator`, which the contract reads only to word a failure, never to
 * reach a verdict. `readOnly` and `writeOnly` are left out on purpose: they
 * are annotations to JSON Schema, but the direction rewrite reads them, so
 * this package keeps treating them as what decides a property's presence.
 *
 * @internal
 */
final readonly class Annotations
{
    private const array KEYWORDS = [
        '$comment' => true,
        'default' => true,
        'deprecated' => true,
        'description' => true,
        'discriminator' => true,
        'example' => true,
        'examples' => true,
        'externalDocs' => true,
        'title' => true,
        'xml' => true,
    ];

    public static function is(string $keyword): bool
    {
        return isset(self::KEYWORDS[$keyword]) || str_starts_with($keyword, 'x-');
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    public static function strip(array $schema): array
    {
        return array_filter($schema, static fn(string $keyword): bool => !self::is($keyword), ARRAY_FILTER_USE_KEY);
    }
}
