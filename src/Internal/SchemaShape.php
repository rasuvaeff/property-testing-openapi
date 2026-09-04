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
        return ($schema['type'] ?? null) === 'array' || array_key_exists('items', $schema);
    }

    /** @param array<string, mixed> $schema */
    public static function isObject(array $schema): bool
    {
        return ($schema['type'] ?? null) === 'object' || array_key_exists('properties', $schema);
    }
}
