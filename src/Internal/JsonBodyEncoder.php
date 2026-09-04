<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Encodes a JSON-compatible logical value as a JSON document, turning maps
 * that the schema declares as objects into `stdClass` so an empty object
 * stays `{}` on the wire.
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
        if (SchemaShape::isObject($schema) && is_array($value) && ($value === [] || !array_is_list($value))) {
            $properties = $this->schemaObject($schema['properties'] ?? [], 'Object properties must be an object');
            /** @var array<string, mixed> $result */
            $result = [];
            foreach (array_keys($value) as $name) {
                if (!is_string($name)) {
                    throw new UnsupportedGeneration('JSON object keys must be strings');
                }
                $property = $this->schemaObject($properties[$name] ?? $this->additionalSchema($schema), 'Object property must be a schema object');
                $result = array_merge($result, [$name => $this->jsonValue($value[$name], $property)]);
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

    /** @return array<string, mixed> */
    public function schemaObject(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new UnsupportedGeneration($message);
        }
        if ($value !== [] && array_is_list($value)) {
            throw new UnsupportedGeneration($message);
        }

        /** @var array<string, mixed> $result */
        $result = [];
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new UnsupportedGeneration($message);
            }
            $result = array_merge($result, [$key => $value[$key]]);
        }

        return $result;
    }

}
