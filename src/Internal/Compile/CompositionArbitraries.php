<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Compiles the supported, constructive subset of composition keywords.
 *
 * @internal
 */
final readonly class CompositionArbitraries
{
    public function __construct(
        private SchemaArbitraryCompiler $compiler,
        private SchemaFacts $facts,
    ) {}

    /** @param array<string, mixed> $schema */
    public function combinator(array $schema): ?ArbitraryInterface
    {
        foreach (['anyOf', 'oneOf', 'allOf'] as $keyword) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            $this->assertNoCombinatorSiblings($schema, $keyword);
            $schemas = $this->schemaBranches($schema[$keyword], $keyword);

            if ($keyword === 'allOf') {
                return $this->compiler->compile($this->mergeAllOf($schemas));
            }
            if ($keyword === 'oneOf' && !$this->areDisjoint($schemas)) {
                throw UnsupportedGeneration::forSchema('oneOf branches must be provably disjoint');
            }

            $pairs = [];
            foreach ($schemas as $branch) {
                $pairs[] = [1, $this->compiler->compile($branch)];
            }

            return Gen::frequency($pairs);
        }

        return null;
    }

    /** @param array<string, mixed> $schema */
    public function not(array $schema): ArbitraryInterface
    {
        if (!is_array($schema['not']) || ($schema['not'] !== [] && array_is_list($schema['not']))) {
            throw UnsupportedGeneration::forSchema('not must be a schema object');
        }
        /** @var array<string, mixed> $forbidden */
        $forbidden = $schema['not'];
        $this->assertNotSchema($forbidden);
        unset($schema['not']);
        $source = $this->compiler->compile($schema);
        if (array_key_exists('const', $schema) && array_key_exists('const', $forbidden)
            && $schema['const'] === $forbidden['const']) {
            throw UnsupportedGeneration::forSchema('not excludes the only const value');
        }
        if (array_key_exists('enum', $schema) && is_array($schema['enum'])
            && array_key_exists('enum', $forbidden) && is_array($forbidden['enum'])
            && $this->enumIsFullyExcluded(array_values($schema['enum']), array_values($forbidden['enum']))) {
            throw UnsupportedGeneration::forSchema('not excludes every enum value');
        }

        return Gen::filter($source, fn(mixed $value): bool => !$this->matchesNot($value, $forbidden));
    }

    /** @param array<string, mixed> $schema */
    private function assertNotSchema(array $schema): void
    {
        $allowed = ['const' => true, 'enum' => true, 'type' => true];
        foreach (array_keys($schema) as $keyword) {
            if (!isset($allowed[$keyword])) {
                throw UnsupportedGeneration::forSchema(sprintf('not keyword "%s" is outside the supported subset', $keyword));
            }
        }
        if ($schema === [] || array_key_exists('const', $schema) && array_key_exists('enum', $schema)) {
            throw UnsupportedGeneration::forSchema('not cannot combine const and enum');
        }
        if (array_key_exists('enum', $schema)
            && (!is_array($schema['enum']) || $schema['enum'] === [] || !array_is_list($schema['enum']))
        ) {
            throw UnsupportedGeneration::forSchema('not enum must be a non-empty list');
        }
        if (array_key_exists('type', $schema) && (($types = $this->facts->types($schema['type'])) === null || $types === [])) {
            throw UnsupportedGeneration::forSchema('not type must be a string or list of strings');
        }
        if (array_key_exists('type', $schema)) {
            /** @var list<string> $types */
            $types = $this->facts->types($schema['type']) ?? [];
            foreach ($types as $type) {
                if (!in_array($type, ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string'], strict: true)) {
                    throw UnsupportedGeneration::forSchema(sprintf('not type "%s" is not supported', $type));
                }
            }
        }
    }

    /** @param list<mixed> $allowed @param list<mixed> $forbidden */
    private function enumIsFullyExcluded(array $allowed, array $forbidden): bool
    {
        foreach (array_keys($allowed) as $index) {
            if (!in_array($allowed[$index], $forbidden, strict: true)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $schema */
    private function matchesNot(mixed $value, array $schema): bool
    {
        if (array_key_exists('const', $schema)) {
            return $value === $schema['const'];
        }
        if (array_key_exists('enum', $schema)) {
            $enumValue = $schema['enum'];
            if (!is_array($enumValue)) {
                throw new \LogicException('not enum predicate has an invalid shape');
            }
            /** @var list<mixed> $enum */
            $enum = array_values($enumValue);

            return in_array($value, $enum, strict: true);
        }
        $types = $this->facts->types($schema['type'] ?? null);
        if ($types === null) {
            throw new \LogicException('not predicate has no supported assertion');
        }
        foreach ($types as $type) {
            if ($this->valueType($value) === $type || $type === 'number' && is_int($value)) {
                return true;
            }
        }

        return false;
    }

    private function valueType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) && array_is_list($value) => 'array',
            is_array($value) => 'object',
            default => throw new \LogicException('Generated value is not JSON-compatible'),
        };
    }

    /** @param list<array<string, mixed>> $branches
     * @return array<string, mixed>
     */
    private function mergeAllOf(array $branches): array
    {
        $merged = [];
        $required = [];
        $properties = [];
        foreach ($branches as $branch) {
            if (array_key_exists('type', $branch) && array_key_exists('type', $merged) && $branch['type'] !== $merged['type']) {
                throw UnsupportedGeneration::forSchema('allOf branches have conflicting types');
            }
            foreach (array_keys($branch) as $key) {
                if ($key === 'required' || $key === 'properties') {
                    continue;
                }
                if (array_key_exists($key, $merged) && $merged[$key] !== $branch[$key]) {
                    throw UnsupportedGeneration::forSchema(sprintf('allOf constraint "%s" cannot be merged safely', $key));
                }
                $merged = array_merge($merged, [$key => $branch[$key]]);
            }
            if (isset($branch['required'])) {
                if (!is_array($branch['required']) || !array_is_list($branch['required'])) {
                    throw UnsupportedGeneration::forSchema('allOf required must be a list');
                }
                foreach (array_keys($branch['required']) as $index) {
                    /** @var mixed $name */
                    $name = $branch['required'][$index];
                    if (!is_string($name)) {
                        throw UnsupportedGeneration::forSchema('allOf required must contain property names');
                    }
                    $required[$name] = true;
                }
            }
            if (isset($branch['properties'])) {
                if (!is_array($branch['properties']) || ($branch['properties'] !== [] && array_is_list($branch['properties']))) {
                    throw UnsupportedGeneration::forSchema('allOf properties must be an object');
                }
                /** @var array<string, mixed> $propertyMap */
                $propertyMap = $this->facts->schemaObject($branch['properties'], 'allOf properties must be an object');
                foreach (array_keys($propertyMap) as $name) {
                    if (!is_array($propertyMap[$name]) || ($propertyMap[$name] !== [] && array_is_list($propertyMap[$name]))) {
                        throw UnsupportedGeneration::forSchema('allOf properties must contain schema objects');
                    }
                    /** @var array<string, mixed> $property */
                    $property = $propertyMap[$name];
                    if (isset($properties[$name])) {
                        /** @var array<string, mixed> $existing */
                        $existing = $properties[$name];
                        $properties[$name] = $this->mergeAllOf([$existing, $property]);
                    } else {
                        /** @var array<string, mixed> $property */
                        $properties[$name] = $property;
                    }
                }
            }
        }
        if ($properties !== []) {
            $merged['properties'] = $properties;
        }
        if ($required !== []) {
            $merged['required'] = array_keys($required);
        }

        return $merged;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function schemaBranches(mixed $value, string $keyword): array
    {
        if (!is_array($value) || !array_is_list($value) || $value === []) {
            throw UnsupportedGeneration::forSchema(sprintf('%s must be a non-empty list', $keyword));
        }
        $schemas = [];
        foreach (array_keys($value) as $index) {
            /** @var mixed $branchValue */
            $branchValue = $value[$index];
            if (!is_array($branchValue) || ($branchValue !== [] && array_is_list($branchValue))) {
                throw UnsupportedGeneration::forSchema(sprintf('%s branches must be schema objects', $keyword));
            }
            /** @var array<string, mixed> $branch */
            $branch = $branchValue;
            $schemas[] = $branch;
        }

        return $schemas;
    }

    /** @param list<array<string, mixed>> $branches */
    private function areDisjoint(array $branches): bool
    {
        $seen = [];
        foreach ($branches as $branch) {
            $types = $this->facts->types($branch['type'] ?? null);
            if ($types === null || count($types) !== 1) {
                return false;
            }
            $type = $types[0];
            if (isset($seen[$type])) {
                return false;
            }
            $seen[$type] = true;
        }

        return true;
    }

    /** @param array<string, mixed> $schema */
    private function assertNoCombinatorSiblings(array $schema, string $keyword): void
    {
        $annotations = [
            '$comment' => true,
            'deprecated' => true,
            'description' => true,
            'examples' => true,
            'title' => true,
        ];
        foreach (array_keys($schema) as $name) {
            if ($name === $keyword || isset($annotations[$name])) {
                continue;
            }

            throw UnsupportedGeneration::forSchema(sprintf('%s with sibling keyword "%s" is outside the supported subset', $keyword, $name));
        }
    }
}
