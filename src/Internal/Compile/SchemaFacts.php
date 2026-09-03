<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile;

use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Shared schema-shape probes for the compiler sections.
 *
 * @internal
 */
final readonly class SchemaFacts
{
    /**
     * @return list<string>|null
     */
    public function types(mixed $value): ?array
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

    /**
     * @param array<string, mixed> $schema
     * @return int<0, max>
     */
    public function nonNegativeInt(array $schema, string $keyword, int $default): int
    {
        $value = $schema[$keyword] ?? $default;
        if (!is_int($value) || $value < 0) {
            throw UnsupportedGeneration::forSchema(sprintf('%s must be a non-negative integer', $keyword));
        }

        return $value;
    }

    /**
     * A numeric bound of an integer schema; a fractional bound rounds inward
     * (`minimum: 0.5` admits 1, `maximum: 2.5` admits 2).
     *
     * @param array<string, mixed> $schema
     */
    public function integerBound(array $schema, string $keyword, int $default): int
    {
        $value = $schema[$keyword] ?? $default;
        if (is_float($value) && is_finite($value) && abs($value) < PHP_INT_MAX) {
            $value = $keyword === 'minimum' ? (int) ceil($value) : (int) floor($value);
        }
        if (!is_int($value)) {
            throw UnsupportedGeneration::forSchema(sprintf('%s for an integer must be numeric', $keyword));
        }

        return $value;
    }

    /** @param array<string, mixed> $schema */
    public function numberBound(array $schema, string $keyword, float $default): float
    {
        $value = $schema[$keyword] ?? $default;
        if (!is_int($value) && !is_float($value)) {
            throw UnsupportedGeneration::forSchema(sprintf('%s for a number must be numeric', $keyword));
        }

        return (float) $value;
    }

    /**
     * @param array<string, mixed> $schema
     * @return bool|array<string, mixed>
     */
    public function additionalPropertiesSchema(array $schema): bool|array
    {
        if (!array_key_exists('additionalProperties', $schema)) {
            return true;
        }

        return $this->booleanOrSchema($schema['additionalProperties']);
    }

    /** @return array<string, mixed> */
    public function schemaObject(mixed $value, string $error): array
    {
        if (!is_array($value) || $value !== [] && array_is_list($value)) {
            throw UnsupportedGeneration::forSchema($error);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /** @return bool|array<string, mixed> */
    private function booleanOrSchema(mixed $value): bool|array
    {
        if (is_bool($value)) {
            return $value;
        }

        return $this->schemaObject($value, 'additionalProperties must be a boolean or schema object');
    }
}
