<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\OpenApi\InvalidCase;

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
 * `CaseData`, which has to stay data-only.
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
        $defs = CaseMaps::schemaObject($schema['$defs'] ?? [], 'Schema $defs must be an object');

        return $this->jsonValueIn($value, $schema, $defs);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $defs the `$defs` of the schema root: what a
     *        local `$ref` below it — the contract's form for a schema that
     *        refers to itself — is read as, so a nested empty object is still
     *        known to be an object
     */
    private function jsonValueIn(mixed $value, array $schema, array $defs): mixed
    {
        if (isset($schema['$ref'])) {
            $schema = $this->dereference($schema['$ref'], $defs);
        }
        $schema = $this->encodingSchema($schema, $value);
        if (is_array($value) && !SchemaShape::isArray($schema) && !SchemaShape::isObject($schema)) {
            $schema = $this->branchFor($schema, $value, $defs) ?? $schema;
        }
        if (SchemaShape::isArray($schema) && is_array($value) && array_is_list($value)) {
            $result = [];
            /** @var mixed $item */
            foreach ($value as $index => $item) {
                /** @var array<array-key, mixed> $prefix */
                $prefix = is_array($schema['prefixItems'] ?? null) ? $schema['prefixItems'] : [];
                $member = $this->memberSchema(array_key_exists($index, $prefix)
                    ? $prefix[$index]
                    : ($schema['items'] ?? $schema['contains'] ?? $schema['unevaluatedItems'] ?? []));
                $items = is_bool($member) ? [] : CaseMaps::schemaObject($member, 'Array items must be a schema object');
                if (is_array($schema['contains'] ?? null)) {
                    $items = $this->encodingSchema(['allOf' => [$items, $schema['contains']]], $item);
                }
                $result = [...$result, $this->jsonValueIn($item, $items, $defs)];
            }

            return $result;
        }
        // `array_is_list()` is not a guess here: a negative case deliberately
        // sends a list where an object is declared, and the wire has to carry
        // that mismatch rather than have it encoded away. The cost is that an
        // object whose member names run 0, 1, … is indistinguishable from a
        // list in a JSON-compatible PHP value — see the class docblock.
        if (SchemaShape::isObject($schema) && is_array($value) && ($value === [] || !array_is_list($value))) {
            $properties = CaseMaps::memberMap($schema['properties'] ?? [], 'Object properties must be an object');
            /** @var array<array-key, mixed> $result */
            $result = [];
            foreach (array_keys($value) as $name) {
                // A JSON member name is whatever the document wrote,
                // including a numeric one — PHP normalizes the array key
                // `"2020"` to an integer, and refusing it here made a legal
                // document impossible to encode. No cast: an array key is
                // normalized back either way, and `json_encode()` renders an
                // integer key as the string name it came from.
                $property = CaseMaps::schemaObject($properties[$name] ?? $this->additionalSchema($schema), 'Object property must be a schema object');
                $result = array_replace($result, [$name => $this->jsonValueIn($value[$name], $property, $defs)]);
            }

            return (object) $result;
        }

        return $value;
    }

    /**
     * The def a local reference names — `#/$defs/<name>`, the name
     * JSON-Pointer-escaped — which is the only `$ref` a compiled schema
     * carries.
     *
     * @param array<string, mixed> $defs
     * @return array<string, mixed>
     */
    private function dereference(mixed $reference, array $defs): array
    {
        if (!is_string($reference) || !str_starts_with($reference, '#/$defs/')) {
            throw new InvalidCase('Schema $ref must be a local reference into $defs');
        }
        /** @var non-empty-string $reference */
        $name = str_replace(['~1', '~0'], ['/', '~'], substr($reference, strlen('#/$defs/')));

        return CaseMaps::schemaObject($defs[$name] ?? null, sprintf('Schema $ref "%s" names no $defs member', $reference));
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
        if (is_array($schema['additionalProperties'] ?? null)) {
            return $schema['additionalProperties'];
        }
        if (is_array($schema['unevaluatedProperties'] ?? null)) {
            return $schema['unevaluatedProperties'];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function encodingSchema(array $schema, mixed $value): array
    {
        /** @var list<mixed> $branches */
        $branches = is_array($schema['allOf'] ?? null) ? $schema['allOf'] : [];
        if (is_array($value) && is_array($schema['dependentSchemas'] ?? null)) {
            /** @var array<array-key, mixed> $dependentSchemas */
            $dependentSchemas = $schema['dependentSchemas'];
            foreach (array_keys($dependentSchemas) as $trigger) {
                if (array_key_exists($trigger, $value) && is_array($dependentSchemas[$trigger] ?? null)) {
                    $branches[] = CaseMaps::schemaObject($dependentSchemas[$trigger], 'Dependent schema must be an object');
                }
            }
        }
        foreach ($branches as $branch) {
            if (!is_array($branch)) {
                continue;
            }
            $branch = $this->encodingSchema(CaseMaps::schemaObject($branch, 'Applicator branch must be a schema object'), $value);
            foreach (['type', 'items', 'prefixItems'] as $keyword) {
                if (!array_key_exists($keyword, $schema) && array_key_exists($keyword, $branch)) {
                    $schema = [...$schema, $keyword => $branch[$keyword]];
                }
            }
            if (is_array($branch['properties'] ?? null)) {
                $properties = CaseMaps::memberMap($schema['properties'] ?? [], 'Object properties must be an object');
                $members = CaseMaps::memberMap($branch['properties'], 'Object properties must be an object');
                $schema['properties'] = array_replace($properties, $members);
            }
        }

        return $schema;
    }

    /**
     * The branch of a union or a conditional that gives a container its
     * shape, when the schema itself declares none: `anyOf: [{object},
     * {null}]` is an object schema for a map, and its empty value is `{}` on
     * the wire, not `[]` (#154). A list takes the first array branch and a
     * map the first object branch; the empty value — both at once in PHP —
     * takes a branch only when the branches declare one of the two shapes and
     * not the other, and otherwise stays as it was.
     *
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $value
     * @param array<string, mixed> $defs
     * @return null|array<string, mixed>
     */
    private function branchFor(array $schema, array $value, array $defs): ?array
    {
        $arrays = [];
        $objects = [];
        foreach (['anyOf', 'oneOf'] as $keyword) {
            $this->classifyEach($schema[$keyword] ?? null, $value, $defs, $arrays, $objects);
        }
        foreach (['then', 'else'] as $keyword) {
            $this->classify($schema[$keyword] ?? null, $value, $defs, $arrays, $objects);
        }

        return match (true) {
            $value === [] && $arrays === [] && $objects !== [] => $objects[0],
            $value === [] && $objects === [] && $arrays !== [] => $arrays[0],
            $value !== [] && array_is_list($value) => $arrays[0] ?? null,
            $value !== [] => $objects[0] ?? null,
            default => null,
        };
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, mixed> $defs
     * @param list<array<string, mixed>> $arrays
     * @param list<array<string, mixed>> $objects
     */
    private function classifyEach(mixed $members, array $value, array $defs, array &$arrays, array &$objects): void
    {
        if (!is_array($members)) {
            return;
        }
        foreach (array_keys($members) as $index) {
            $this->classify($members[$index], $value, $defs, $arrays, $objects);
        }
    }

    /**
     * @param array<array-key, mixed> $value
     * @param array<string, mixed> $defs
     * @param list<array<string, mixed>> $arrays
     * @param list<array<string, mixed>> $objects
     */
    private function classify(mixed $member, array $value, array $defs, array &$arrays, array &$objects): void
    {
        if (!is_array($member) || ($member !== [] && array_is_list($member))) {
            return;
        }
        /** @var array<string, mixed> $member */
        if (isset($member['$ref'])) {
            $member = $this->dereference($member['$ref'], $defs);
        }
        $member = $this->encodingSchema($member, $value);
        if (SchemaShape::isArray($member)) {
            $arrays[] = $member;
        }
        if (SchemaShape::isObject($member)) {
            $objects[] = $member;
        }
    }

    /** @return bool|array<string, mixed> */
    private function memberSchema(mixed $value): bool|array
    {
        if (is_bool($value)) {
            return $value;
        }

        return CaseMaps::schemaObject($value, 'Array items must be a schema object');
    }
}
