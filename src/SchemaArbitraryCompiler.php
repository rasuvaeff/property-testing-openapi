<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\CompositionArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ContainerArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\ScalarArbitraries;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile\SchemaFacts;

/**
 * Compiles the explicit JSON-compatible schema subset into shrinkable values.
 *
 * @api
 */
final readonly class SchemaArbitraryCompiler
{
    private SchemaFacts $facts;

    private CompositionArbitraries $composition;

    private ScalarArbitraries $scalars;

    private ContainerArbitraries $containers;

    public function __construct()
    {
        $facts = new SchemaFacts();
        $this->facts = $facts;
        $this->composition = new CompositionArbitraries($this, $facts);
        $this->scalars = new ScalarArbitraries($facts);
        $this->containers = new ContainerArbitraries($this, $facts);
    }

    /**
     * @param array<string, mixed> $schema
     */
    public function compile(array $schema): ArbitraryInterface
    {
        $combinator = $this->composition->combinator($schema);
        if ($combinator instanceof ArbitraryInterface) {
            return $combinator;
        }
        if (array_key_exists('not', $schema)) {
            return $this->composition->not($schema);
        }
        $this->assertSupported($schema);
        if ($schema === []) {
            return $this->containers->additionalValue();
        }
        if (($schema['nullable'] ?? false) === true) {
            unset($schema['nullable']);

            return Gen::nullable($this->compile($schema));
        }
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

        $types = $this->facts->types($schema['type'] ?? null);
        if ($types !== null && count($types) > 1) {
            $branches = [];
            foreach ($types as $candidate) {
                $branch = $schema;
                $branch['type'] = $candidate;
                $branches[] = $this->compile($branch);
            }

            return Gen::frequency(array_map(static fn(ArbitraryInterface $branch): array => [1, $branch], $branches));
        }

        $type = $this->type($schema);

        return match ($type) {
            'string' => $this->scalars->string($schema),
            'integer' => $this->scalars->integer($schema),
            'number' => $this->scalars->number($schema),
            'boolean' => Gen::bool(),
            'null' => Gen::constant(null),
            'array' => $this->containers->array($schema),
            'object' => $this->containers->object($schema),
            default => throw UnsupportedGeneration::forSchema(sprintf('type "%s" is not supported', $type)),
        };
    }

    /** @param array<string, mixed> $schema */
    private function type(array $schema): string
    {
        $types = $this->facts->types($schema['type'] ?? null);
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
            '$ref', 'allOf', 'anyOf', 'oneOf', 'if', 'then', 'else',
            'contains', 'prefixItems', 'patternProperties',
            'propertyNames', 'unevaluatedProperties',
        ] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                throw UnsupportedGeneration::forSchema(sprintf('keyword "%s" is outside the initial support matrix', $keyword));
            }
        }
        if (array_key_exists('additionalProperties', $schema)) {
            $this->facts->additionalPropertiesSchema($schema);
        }
        if (($schema['exclusiveMinimum'] ?? false) !== false && !is_bool($schema['exclusiveMinimum'])) {
            throw UnsupportedGeneration::forSchema('numeric exclusiveMinimum is not supported');
        }
        if (($schema['exclusiveMaximum'] ?? false) !== false && !is_bool($schema['exclusiveMaximum'])) {
            throw UnsupportedGeneration::forSchema('numeric exclusiveMaximum is not supported');
        }
    }
}
