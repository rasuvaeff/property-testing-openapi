<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Encodes a JSON-compatible logical value as a JSON document, turning maps
 * that the schema declares as objects into `stdClass` so an empty object
 * stays `{}` on the wire.
 *
 * A member name is whatever the document wrote, including a numeric one:
 * PHP normalizes the array key `"2020"` to an integer, and that name is cast
 * back rather than refused. The one shape this cannot represent is an object
 * whose names run 0, 1, … without a gap — in a JSON-compatible PHP value that
 * is a list, and a list is what a negative case sends when it means to
 * violate an object schema. Distinguishing them would need a marker in
 * `RequestCaseData`, which has to stay data-only.
 *
 * @internal
 */
final readonly class JsonBodyEncoder
{
    /** @param array<string, mixed> $schema */
    public function encode(mixed $value, array $schema): string
    {
        return json_encode($this->jsonValue($value, $schema), JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $schema */
    public function jsonValue(mixed $value, array $schema): mixed
    {
        if (SchemaShape::isArray($schema) && is_array($value) && array_is_list($value)) {
            $items = $this->schemaObject($schema['items'] ?? null, 'Array items must be a schema object');

            return array_map(fn(mixed $item): mixed => $this->jsonValue($item, $items), $value);
        }
        // `array_is_list()` is not a guess here: a negative case deliberately
        // sends a list where an object is declared, and the wire has to carry
        // that mismatch rather than have it encoded away. The cost is that an
        // object whose member names run 0, 1, … is indistinguishable from a
        // list in a JSON-compatible PHP value — see the class docblock.
        if (SchemaShape::isObject($schema) && is_array($value) && ($value === [] || !array_is_list($value))) {
            $properties = $this->memberMap($schema['properties'] ?? [], 'Object properties must be an object');
            /** @var array<array-key, mixed> $result */
            $result = [];
            foreach (array_keys($value) as $name) {
                // A JSON member name is whatever the document wrote,
                // including a numeric one — PHP normalizes the array key
                // `"2020"` to an integer, and refusing it here made a legal
                // document impossible to encode. No cast: an array key is
                // normalized back either way, and `json_encode()` renders an
                // integer key as the string name it came from.
                $property = $this->schemaObject($properties[$name] ?? $this->additionalSchema($schema), 'Object property must be a schema object');
                $result = array_replace($result, [$name => $this->jsonValue($value[$name], $property)]);
            }

            return (object) $result;
        }

        return $value;
    }

    /**
     * The schema a key outside `properties` is declared by. Losing it encoded a
     * nested empty object as `[]`, which the contract then rejected.
     *
     * @param array<string, mixed> $schema
     */
    private function additionalSchema(array $schema): mixed
    {
        // `true` and `false` carry no shape of their own.
        return is_array($schema['additionalProperties'] ?? null) ? $schema['additionalProperties'] : [];
    }

    /**
     * A Schema Object: a map keyed by JSON Schema keywords, which are never
     * numeric, so `array<string, mixed>` is a promise PHP can keep here.
     *
     * @return array<string, mixed>
     */
    public function schemaObject(mixed $value, string $message): array
    {
        /** @var array<string, mixed> $map */
        $map = $this->memberMap($value, $message);

        return $map;
    }

    /**
     * A map keyed by member name — a `properties` declaration, or an object
     * value. The key type is `array-key` and not `string` because PHP
     * normalizes a numeric-string key to an integer: a member the document
     * named `"2020"` really does arrive as `int 2020`. Saying `string` here
     * was the lie behind refusing such a name outright.
     *
     * `array_replace`, not `array_merge`: the latter renumbers integer-like
     * keys, so the very names this exists to preserve would be the ones it
     * lost.
     *
     * @return array<array-key, mixed>
     */
    private function memberMap(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new UnsupportedGeneration($message);
        }
        if ($value !== [] && array_is_list($value)) {
            throw new UnsupportedGeneration($message);
        }

        /** @var array<array-key, mixed> $result */
        $result = [];
        foreach (array_keys($value) as $key) {
            $result = array_replace($result, [$key => $value[$key]]);
        }

        return $result;
    }

}
