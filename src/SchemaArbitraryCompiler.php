<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * Compiles the explicit JSON-compatible schema subset into shrinkable values.
 *
 * @api
 */
final readonly class SchemaArbitraryCompiler
{
    private const int MAX_COLLECTION_SIZE = 16;
    private const int MAX_STRING_LENGTH = 64;

    /**
     * @param array<string, mixed> $schema
     */
    public function compile(array $schema): ArbitraryInterface
    {
        $this->assertSupported($schema);
        if (array_key_exists('const', $schema)) {
            return Gen::constant($schema['const']);
        }
        if (array_key_exists('enum', $schema)) {
            $values = $schema['enum'];
            if (!is_array($values) || $values === []) {
                throw UnsupportedGeneration::forSchema('enum must be a non-empty list');
            }

            return Gen::elements(array_values($values));
        }

        $type = $this->type($schema);

        return match ($type) {
            'string' => $this->string($schema),
            'integer' => $this->integer($schema),
            'number' => $this->number($schema),
            'boolean' => Gen::bool(),
            'null' => Gen::constant(null),
            'array' => $this->array($schema),
            'object' => $this->object($schema),
            default => throw UnsupportedGeneration::forSchema(sprintf('type "%s" is not supported', $type)),
        };
    }

    /** @param array<string, mixed> $schema */
    private function string(array $schema): ArbitraryInterface
    {
        $min = $this->nonNegativeInt($schema, 'minLength', 0);
        $max = $this->nonNegativeInt($schema, 'maxLength', self::MAX_STRING_LENGTH);
        $max = min($max, self::MAX_STRING_LENGTH);
        if ($max === 0) {
            if ($min !== 0) {
                throw UnsupportedGeneration::forSchema('minLength exceeds maxLength or the generation budget');
            }

            return Gen::constant('');
        }
        if ($min > $max) {
            throw UnsupportedGeneration::forSchema('minLength exceeds maxLength or the generation budget');
        }

        return Gen::stringOf($min, $max);
    }

    /** @param array<string, mixed> $schema */
    private function integer(array $schema): ArbitraryInterface
    {
        $min = $this->integerBound($schema, 'minimum', -1000);
        $max = $this->integerBound($schema, 'maximum', 1000);
        if (($schema['exclusiveMinimum'] ?? false) === true) {
            ++$min;
        }
        if (($schema['exclusiveMaximum'] ?? false) === true) {
            --$max;
        }
        if ($min > $max) {
            throw UnsupportedGeneration::forSchema('integer bounds leave no value');
        }

        return Gen::intBetween($min, $max);
    }

    /** @param array<string, mixed> $schema */
    private function number(array $schema): ArbitraryInterface
    {
        $min = $this->numberBound($schema, 'minimum', -1000.0);
        $max = $this->numberBound($schema, 'maximum', 1000.0);
        if (($schema['exclusiveMinimum'] ?? false) === true) {
            $min += 0.1;
        }
        if (($schema['exclusiveMaximum'] ?? false) === true) {
            $max -= 0.1;
        }
        if ($min > $max) {
            throw UnsupportedGeneration::forSchema('number bounds leave no value');
        }

        return Gen::floatBetween($min, $max);
    }

    /** @param array<string, mixed> $schema */
    private function array(array $schema): ArbitraryInterface
    {
        $items = $schema['items'] ?? null;
        if (!is_array($items) || array_is_list($items)) {
            throw UnsupportedGeneration::forSchema('array items must be a schema object');
        }
        /** @var array<string, mixed> $items */
        $min = $this->nonNegativeInt($schema, 'minItems', 0);
        $max = min($this->nonNegativeInt($schema, 'maxItems', self::MAX_COLLECTION_SIZE), self::MAX_COLLECTION_SIZE);
        if ($min > $max) {
            throw UnsupportedGeneration::forSchema('minItems exceeds maxItems or the generation budget');
        }

        $element = $this->compile($items);

        return ($schema['uniqueItems'] ?? false) === true
            ? Gen::uniqueArrayOf($element, $min, $max)
            : Gen::arrayOf($element, $min, $max);
    }

    /** @param array<string, mixed> $schema */
    private function object(array $schema): ArbitraryInterface
    {
        $properties = $schema['properties'] ?? [];
        if (!is_array($properties) || array_is_list($properties)) {
            throw UnsupportedGeneration::forSchema('object properties must be an object');
        }
        $shape = [];
        foreach ($properties as $name => $property) {
            if (!is_string($name) || !is_array($property) || array_is_list($property)) {
                throw UnsupportedGeneration::forSchema('object properties must contain named schema objects');
            }
            /** @var array<string, mixed> $property */
            $shape[$name] = $this->compile($property);
        }
        $required = $schema['required'] ?? [];
        if (!is_array($required) || !array_is_list($required)) {
            throw UnsupportedGeneration::forSchema('required must be a list of property names');
        }
        foreach (array_keys($required) as $index) {
            if (!is_string($required[$index]) || !array_key_exists($required[$index], $shape)) {
                throw UnsupportedGeneration::forSchema('required properties without a schema are not supported');
            }
        }
        if ($shape === []) {
            return Gen::constant([]);
        }

        return Gen::record($shape);
    }

    /** @param array<string, mixed> $schema */
    private function type(array $schema): string
    {
        $types = $this->types($schema['type'] ?? null);
        if ($types !== null) {
            foreach ($types as $candidate) {
                if ($candidate !== 'null') {
                    return $candidate;
                }
            }
            if (in_array('null', $types, strict: true)) {
                return 'null';
            }
        }
        if (array_key_exists('properties', $schema)) {
            return 'object';
        }
        if (array_key_exists('items', $schema)) {
            return 'array';
        }

        throw UnsupportedGeneration::forSchema('a type, properties, or items declaration is required');
    }

    /** @param array<string, mixed> $schema */
    private function assertSupported(array $schema): void
    {
        foreach ([
            '$ref', 'allOf', 'anyOf', 'oneOf', 'not', 'if', 'then', 'else',
            'contains', 'prefixItems', 'pattern', 'patternProperties',
            'propertyNames', 'unevaluatedProperties', 'multipleOf', 'format',
            'minProperties', 'maxProperties',
        ] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                throw UnsupportedGeneration::forSchema(sprintf('keyword "%s" is outside the initial support matrix', $keyword));
            }
        }
        if (array_key_exists('additionalProperties', $schema) && !is_bool($schema['additionalProperties'])) {
            throw UnsupportedGeneration::forSchema('schema-valued additionalProperties is not supported');
        }
        if (($schema['exclusiveMinimum'] ?? false) !== false && !is_bool($schema['exclusiveMinimum'])) {
            throw UnsupportedGeneration::forSchema('numeric exclusiveMinimum is not supported');
        }
        if (($schema['exclusiveMaximum'] ?? false) !== false && !is_bool($schema['exclusiveMaximum'])) {
            throw UnsupportedGeneration::forSchema('numeric exclusiveMaximum is not supported');
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @return int<0, max>
     */
    private function nonNegativeInt(array $schema, string $keyword, int $default): int
    {
        $value = $schema[$keyword] ?? $default;
        if (!is_int($value) || $value < 0) {
            throw UnsupportedGeneration::forSchema(sprintf('%s must be a non-negative integer', $keyword));
        }

        return $value;
    }

    /**
     * @return list<string>|null
     */
    private function types(mixed $value): ?array
    {
        if (is_string($value)) {
            return [$value];
        }
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }

        $types = [];
        foreach (array_keys($value) as $index) {
            if (!is_string($value[$index])) {
                return null;
            }
            $types[] = $value[$index];
        }

        return $types;
    }

    /** @param array<string, mixed> $schema */
    private function integerBound(array $schema, string $keyword, int $default): int
    {
        $value = $schema[$keyword] ?? $default;
        if (!is_int($value)) {
            throw UnsupportedGeneration::forSchema(sprintf('%s for an integer must be an integer', $keyword));
        }

        return $value;
    }

    /** @param array<string, mixed> $schema */
    private function numberBound(array $schema, string $keyword, float $default): float
    {
        $value = $schema[$keyword] ?? $default;
        if (!is_int($value) && !is_float($value)) {
            throw UnsupportedGeneration::forSchema(sprintf('%s for a number must be numeric', $keyword));
        }

        return (float) $value;
    }
}
