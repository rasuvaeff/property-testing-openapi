<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

/**
 * Media type normalization and the JSON test, shared by everything that picks
 * a `content` entry: the case generators, the materializers, the transports
 * and the negative body targets each used to spell both out.
 *
 * @internal
 */
final readonly class MediaType
{
    /**
     * The bare type/subtype of a `content` key or a Content-Type header,
     * lowercased and stripped of its parameters.
     */
    public static function normalize(string $value): string
    {
        return strtolower(trim(explode(';', $value, 2)[0]));
    }

    /**
     * Whether a media type carries a JSON payload: the JSON media type
     * itself, or any of the `+json` structured syntax suffixes. Accepts an
     * unnormalized value.
     */
    public static function isJson(string $value): bool
    {
        $normalized = self::normalize($value);

        return $normalized === 'application/json' || str_ends_with($normalized, '+json');
    }
}
