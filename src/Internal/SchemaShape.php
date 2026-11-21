<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

/**
 * Whether a Schema Object describes an array or an object, by declared type
 * or by the keyword that implies it. Three classes carried this pair
 * verbatim; one place decides it now.
 *
 * It is also where the shape of a Schema Object is named, once, for every
 * compiler section to import (#165): `Schema` is a decoded JSON object,
 * `Subschema` the boolean-or-object form JSON Schema allows wherever a
 * subschema stands (`additionalProperties`, `items`, `not`, `if`, …).
 *
 * @psalm-type Schema = array<string, mixed>
 * @psalm-type Subschema = bool|Schema
 *
 * @internal
 */
final readonly class SchemaShape
{
    /** @param Schema $schema */
    public static function isArray(array $schema): bool
    {
        return self::declares($schema, 'array') || array_key_exists('items', $schema)
            || array_key_exists('prefixItems', $schema) || array_key_exists('contains', $schema);
    }

    /** @param Schema $schema */
    public static function isObject(array $schema): bool
    {
        return self::declares($schema, 'object') || array_key_exists('properties', $schema);
    }

    /**
     * RFC 6570 treats an empty list or map as undefined, so the materializer
     * omits it, and a multipart entity with no parts is not one: a required
     * container has to be generated non-empty.
     *
     * @param Schema $schema
     * @return Schema
     */
    public static function nonEmpty(array $schema): array
    {
        if (self::isArray($schema)) {
            $min = is_int($schema['minItems'] ?? null) ? (int) $schema['minItems'] : 0;

            return array_merge($schema, ['minItems' => max(1, $min)]);
        }
        if (self::isObject($schema)) {
            $min = is_int($schema['minProperties'] ?? null) ? (int) $schema['minProperties'] : 0;

            return array_merge($schema, ['minProperties' => max(1, $min)]);
        }

        return $schema;
    }

    /**
     * OAS 3.1 spells a type as a union, so membership decides and not
     * identity. Reading only the scalar form made a free-form
     * `type: ['object', 'null']` look like neither an object nor an array: the
     * generator produced a map and the wire conversion then rejected it as a
     * non-scalar.
     *
     * @param Schema $schema
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
