<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

/**
 * Whether a Schema Object describes an array or an object, by declared type
 * or by the keyword that implies it. Three classes carried this pair
 * verbatim; one place decides it now.
 *
 * @internal
 */
final readonly class SchemaShape
{
    /** @param array<string, mixed> $schema */
    public static function isArray(array $schema): bool
    {
        return self::declares($schema, 'array') || array_key_exists('items', $schema);
    }

    /** @param array<string, mixed> $schema */
    public static function isObject(array $schema): bool
    {
        return self::declares($schema, 'object') || array_key_exists('properties', $schema);
    }

    /**
     * OAS 3.1 spells a type as a union, so membership decides and not
     * identity. Reading only the scalar form made a free-form
     * `type: ['object', 'null']` look like neither an object nor an array: the
     * generator produced a map and the wire conversion then rejected it as a
     * non-scalar.
     *
     * @param array<string, mixed> $schema
     */
    private static function declares(array $schema, string $type): bool
    {
        // Read in place rather than through a local: the value is `mixed`, and
        // the annotation that would tell psalm so is one rector removes.
        if (!array_key_exists('type', $schema)) {
            return false;
        }
        if ($schema['type'] === $type) {
            return true;
        }

        return is_array($schema['type']) && in_array($type, $schema['type'], strict: true);
    }
}
