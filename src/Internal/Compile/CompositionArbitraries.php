<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Random;

/**
 * Compiles the supported, constructive subset of composition keywords.
 *
 * @internal
 */
final readonly class CompositionArbitraries
{
    private const int PROBES = 8;

    private const int PROBE_SEED = 11;

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
                $numeric = $this->integerAndNumberBranches($schemas);
                if ($numeric === null) {
                    throw UnsupportedGeneration::forSchema('oneOf branches must be provably disjoint');
                }

                return $this->numericOneOf($schemas, $numeric[0], $numeric[1]);
            }

            $pairs = [];
            foreach ($schemas as $branch) {
                $pairs[] = [1, $this->compiler->compile($branch)];
            }

            return Gen::frequency($pairs);
        }

        return null;
    }

    /**
     * The one overlap `oneOf` can carry between branches of different
     * declared types: a single `integer` branch beside a single `number`
     * branch, every other branch disjoint from both. `[$integerIndex,
     * $numberIndex]`, or `null` for any other overlap.
     *
     * @param list<array<string, mixed>> $branches
     * @return null|array{int, int}
     */
    private function integerAndNumberBranches(array $branches): ?array
    {
        $byType = [];
        foreach ($branches as $index => $branch) {
            $types = $this->facts->types($branch['type'] ?? null);
            if ($types === null || count($types) !== 1) {
                return null;
            }
            $byType[$types[0]][] = $index;
        }
        foreach ($byType as $type => $indexes) {
            if (count($indexes) !== 1) {
                return null;
            }
        }
        if (!isset($byType['integer'], $byType['number'])) {
            return null;
        }

        return [$byType['integer'][0], $byType['number'][0]];
    }

    /**
     * `oneOf` over an `integer` and a `number` branch. Every integer is also
     * a number, and JSON Schema reads `1.0` as an integer, so a value is
     * valid only when exactly one branch admits it: a non-integral float, or
     * an integer the number branch's own keywords reject (#121). The number
     * branch is generated without integral values; the integer branch keeps
     * only what the number branch's bounds and multiple refuse, and is left
     * out when they refuse nothing. A number branch that carries a keyword
     * this cannot read is refused, because an integer it may admit cannot be
     * told from one it does not.
     *
     * @param list<array<string, mixed>> $branches
     */
    private function numericOneOf(array $branches, int $integerIndex, int $numberIndex): ArbitraryInterface
    {
        $number = $branches[$numberIndex];
        foreach (array_keys($number) as $keyword) {
            if (!in_array($keyword, ['type', 'minimum', 'maximum', 'exclusiveMinimum', 'exclusiveMaximum', 'multipleOf', 'description', 'title', '$comment', 'deprecated', 'examples', 'example'], strict: true)) {
                throw UnsupportedGeneration::forSchema(sprintf('oneOf over integer and number cannot read number keyword "%s" to keep the branches apart', $keyword));
            }
        }
        $pairs = [];
        foreach ($branches as $index => $branch) {
            if ($index === $numberIndex) {
                $floats = Gen::filter($this->compiler->compile($branch), static fn(mixed $value): bool => is_float($value) && floor($value) !== $value);
                if (!$this->yieldsSomething($floats)) {
                    throw UnsupportedGeneration::forSchema('oneOf number branch admits no value outside the integer branch');
                }
                $pairs[] = [1, $floats];
            } elseif ($index === $integerIndex) {
                $integers = Gen::filter($this->compiler->compile($branch), fn(mixed $value): bool => is_int($value) && !$this->numberBranchAdmits($value, $number));
                if ($this->yieldsSomething($integers)) {
                    $pairs[] = [1, $integers];
                }
            } else {
                $pairs[] = [1, $this->compiler->compile($branch)];
            }
        }

        return Gen::frequency($pairs);
    }

    /** @param array<string, mixed> $number */
    private function numberBranchAdmits(int $value, array $number): bool
    {
        $minimum = $this->facts->numberBound($number, 'minimum', -INF);
        $maximum = $this->facts->numberBound($number, 'maximum', INF);
        if ($value < $minimum || $value > $maximum) {
            return false;
        }
        if ((($number['exclusiveMinimum'] ?? false) === true && (float) $value === $minimum)
            || (($number['exclusiveMaximum'] ?? false) === true && (float) $value === $maximum)) {
            return false;
        }
        /** @var mixed $multiple */
        $multiple = $number['multipleOf'] ?? null;
        if (is_int($multiple) && $multiple > 0) {
            return $value % $multiple === 0;
        }
        if (is_float($multiple) && $multiple > 0) {
            return abs((float) $value - round((float) $value / $multiple) * $multiple) < 1e-14;
        }

        return true;
    }

    /**
     * Whether a filtered branch produces anything at all, judged the way the
     * pattern probe does: deterministic draws, twice the budget the filter
     * gets at run time, so a branch that fails here is one that would have
     * exhausted mid-run.
     */
    private function yieldsSomething(ArbitraryInterface $arbitrary): bool
    {
        $random = new Random(self::PROBE_SEED);
        for ($probe = 0; $probe < self::PROBES; ++$probe) {
            try {
                $arbitrary->generate($random);

                return true;
            } catch (GenerationExhaustedException) {
                continue;
            }
        }

        return false;
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
        $this->assertNotLeavesAType($schema, $forbidden);
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
        if ($schema === [] || (array_key_exists('const', $schema) && array_key_exists('enum', $schema))) {
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

    /**
     * A `not` that is a pure type predicate must leave at least one declared
     * (or structurally implied) type of the source, or it rejects every value.
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $forbidden
     */
    private function assertNotLeavesAType(array $schema, array $forbidden): void
    {
        if (array_key_exists('const', $forbidden) || array_key_exists('enum', $forbidden)) {
            return;
        }
        $forbiddenTypes = $this->facts->types($forbidden['type'] ?? null) ?? [];
        $sourceTypes = $this->facts->types($schema['type'] ?? null) ?? match (true) {
            array_key_exists('properties', $schema) => ['object'],
            array_key_exists('items', $schema) => ['array'],
            default => null,
        };
        if ($sourceTypes === null || $sourceTypes === []) {
            return;
        }
        foreach ($sourceTypes as $type) {
            $covered = in_array($type, $forbiddenTypes, strict: true) || ($type === 'integer' && in_array('number', $forbiddenTypes, strict: true));
            if (!$covered) {
                return;
            }
        }

        throw UnsupportedGeneration::forSchema('not excludes every value of the declared type');
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
            if ($this->valueType($value) === $type || ($type === 'number' && is_int($value))) {
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
        // allOf is an intersection, so null survives only where every branch
        // admits it; OAS 3.0 spells a branch's silence as `nullable: false`.
        // Branch lists reach here non-empty — schemaBranches() rejects `[]`.
        $nullable = true;
        foreach ($branches as $branch) {
            if (array_key_exists('type', $branch) && array_key_exists('type', $merged) && $branch['type'] !== $merged['type']) {
                throw UnsupportedGeneration::forSchema('allOf branches have conflicting types');
            }
            if (($branch['nullable'] ?? false) !== true) {
                $nullable = false;
            }
            foreach (array_keys($branch) as $key) {
                if (in_array($key, ['required', 'properties', 'nullable'], strict: true)) {
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
        if ($nullable) {
            $merged['nullable'] = true;
        }
        if ($properties !== []) {
            $merged['properties'] = $properties;
        }
        if ($required !== []) {
            $merged['required'] = array_keys($required);
        }
        $this->assertAdditionalPropertiesAdmitSiblings($branches, array_keys($properties));

        return $merged;
    }

    /**
     * The validator checks every branch on its own, so a branch that bounds
     * additional properties would reject the properties its siblings add.
     *
     * @param list<array<string, mixed>> $branches
     * @param list<string> $names
     */
    private function assertAdditionalPropertiesAdmitSiblings(array $branches, array $names): void
    {
        foreach ($branches as $branch) {
            if (!array_key_exists('additionalProperties', $branch) || $branch['additionalProperties'] === true) {
                continue;
            }
            $declared = $this->facts->schemaObject($branch['properties'] ?? [], 'allOf properties must be an object');
            foreach ($names as $name) {
                if (!array_key_exists($name, $declared)) {
                    throw UnsupportedGeneration::forSchema(sprintf('allOf branch bounding additionalProperties cannot admit sibling property "%s"', $name));
                }
            }
        }
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

    /**
     * Whether no value can satisfy two of the branches, knowable from their
     * declared types alone. `integer` and `number` are one class here: every
     * integer is also a number, so a branch of each is not a disjoint pair
     * but an overlap the checked `oneOf` path has to resolve (#121).
     *
     * @param list<array<string, mixed>> $branches
     */
    private function areDisjoint(array $branches): bool
    {
        $seen = [];
        foreach ($branches as $branch) {
            $types = $this->facts->types($branch['type'] ?? null);
            if ($types === null || count($types) !== 1) {
                return false;
            }
            $class = $types[0] === 'integer' ? 'number' : $types[0];
            if (isset($seen[$class])) {
                return false;
            }
            $seen[$class] = true;
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
