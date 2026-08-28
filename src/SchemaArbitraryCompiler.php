<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use DateTimeImmutable;
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
        $combinator = $this->combinator($schema);
        if ($combinator instanceof ArbitraryInterface) {
            return $combinator;
        }
        if (array_key_exists('not', $schema)) {
            return $this->not($schema);
        }
        $this->assertSupported($schema);
        if ($schema === []) {
            return $this->additionalValue();
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

        $types = $this->types($schema['type'] ?? null);
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

    /**
     * Compile the constructive `not` subset. A validator-backed arbitrary is
     * deliberately out of scope here: only predicates that can be evaluated
     * without changing the generated JSON value are accepted.
     *
     * @param array<string, mixed> $schema
     */
    private function not(array $schema): ArbitraryInterface
    {
        /** @var mixed $forbiddenValue */
        $forbiddenValue = $schema['not'];
        if (!is_array($forbiddenValue) || ($forbiddenValue !== [] && array_is_list($forbiddenValue))) {
            throw UnsupportedGeneration::forSchema('not must be a schema object');
        }
        /** @var array<string, mixed> $forbidden */
        $forbidden = $forbiddenValue;
        $this->assertNotSchema($forbidden);
        unset($schema['not']);

        if (array_key_exists('const', $schema)
            && array_key_exists('const', $forbidden)
            && $schema['const'] === $forbidden['const']
        ) {
            throw UnsupportedGeneration::forSchema('not excludes the only const value');
        }
        if (array_key_exists('enum', $schema) && is_array($schema['enum'])
            && array_key_exists('enum', $forbidden) && is_array($forbidden['enum'])
        ) {
            /** @var list<mixed> $allowedEnum */
            $allowedEnum = array_values($schema['enum']);
            /** @var list<mixed> $forbiddenEnum */
            $forbiddenEnum = array_values($forbidden['enum']);
            if ($this->enumIsFullyExcluded($allowedEnum, $forbiddenEnum)) {
                throw UnsupportedGeneration::forSchema('not excludes every enum value');
            }
        }
        if ($this->hasOnlyForbiddenType($schema, $forbidden)) {
            throw UnsupportedGeneration::forSchema('not excludes every generated type');
        }

        $source = $this->compile($schema);

        return Gen::filter($source, fn(mixed $value): bool => !$this->matchesNot($value, $forbidden));
    }

    /** @param array<string, mixed> $schema */
    private function assertNotSchema(array $schema): void
    {
        $allowed = [
            '$comment' => true,
            'const' => true,
            'deprecated' => true,
            'description' => true,
            'enum' => true,
            'examples' => true,
            'title' => true,
            'type' => true,
        ];
        foreach (array_keys($schema) as $keyword) {
            if (!isset($allowed[$keyword])) {
                throw UnsupportedGeneration::forSchema(sprintf('not keyword "%s" is outside the supported subset', $keyword));
            }
        }
        if (array_key_exists('const', $schema) && array_key_exists('enum', $schema)) {
            throw UnsupportedGeneration::forSchema('not cannot combine const and enum');
        }
        if (array_key_exists('enum', $schema)
            && (!is_array($schema['enum']) || $schema['enum'] === [] || !array_is_list($schema['enum']))
        ) {
            throw UnsupportedGeneration::forSchema('not enum must be a non-empty list');
        }
        if (array_key_exists('type', $schema) && (($types = $this->types($schema['type'])) === null || $types === [])) {
            throw UnsupportedGeneration::forSchema('not type must be a string or list of strings');
        }
        if (!array_key_exists('const', $schema) && !array_key_exists('enum', $schema) && !array_key_exists('type', $schema)) {
            throw UnsupportedGeneration::forSchema('not must contain const, enum, or type');
        }
        if (array_key_exists('type', $schema)) {
            /** @var list<string> $types */
            $types = $this->types($schema['type']) ?? [];
            foreach ($types as $type) {
                if (!in_array($type, ['array', 'boolean', 'integer', 'null', 'number', 'object', 'string'], true)) {
                    throw UnsupportedGeneration::forSchema(sprintf('not type "%s" is not supported', $type));
                }
            }
        }
    }

    /** @param array<string, mixed> $schema @param array<string, mixed> $forbidden */
    private function hasOnlyForbiddenType(array $schema, array $forbidden): bool
    {
        $sourceTypes = $this->types($schema['type'] ?? null);
        $forbiddenTypes = $this->types($forbidden['type'] ?? null);
        if ($sourceTypes === null || $forbiddenTypes === null || $sourceTypes === [] || $forbiddenTypes === []) {
            return false;
        }
        foreach ($sourceTypes as $sourceType) {
            $matched = false;
            foreach ($forbiddenTypes as $forbiddenType) {
                if ($sourceType === $forbiddenType || $sourceType === 'integer' && $forbiddenType === 'number') {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                return false;
            }
        }

        return true;
    }

    /** @param list<mixed> $allowed @param list<mixed> $forbidden */
    private function enumIsFullyExcluded(array $allowed, array $forbidden): bool
    {
        foreach (array_keys($allowed) as $index) {
            /** @var mixed $candidate */
            $candidate = $allowed[$index];
            $excluded = false;
            foreach (array_keys($forbidden) as $blockedIndex) {
                /** @var mixed $blocked */
                $blocked = $forbidden[$blockedIndex];
                if ($candidate === $blocked) {
                    $excluded = true;
                    break;
                }
            }
            if (!$excluded) {
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
            /** @var mixed $enumValue */
            $enumValue = $schema['enum'];
            if (!is_array($enumValue)) {
                throw new \LogicException('not enum predicate has an invalid shape');
            }
            /** @var list<mixed> $enum */
            $enum = array_values($enumValue);
            foreach (array_keys($enum) as $index) {
                /** @var mixed $candidate */
                $candidate = $enum[$index];
                if ($value === $candidate) {
                    return true;
                }
            }

            return false;
        }
        $types = $this->types($schema['type'] ?? null);
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

    /**
     * Compile the supported, constructive subset of composition keywords.
     *
     * @param array<string, mixed> $schema
     */
    private function combinator(array $schema): ?ArbitraryInterface
    {
        foreach (['anyOf', 'oneOf', 'allOf'] as $keyword) {
            if (!array_key_exists($keyword, $schema)) {
                continue;
            }
            $this->assertNoCombinatorSiblings($schema, $keyword);
            $schemas = $this->schemaBranches($schema[$keyword], $keyword);

            if ($keyword === 'allOf') {
                return $this->compile($this->mergeAllOf($schemas));
            }
            if ($keyword === 'oneOf' && !$this->areDisjoint($schemas)) {
                throw UnsupportedGeneration::forSchema('oneOf branches must be provably disjoint');
            }

            $pairs = [];
            foreach ($schemas as $branch) {
                $pairs[] = [1, $this->compile($branch)];
            }

            return Gen::frequency($pairs);
        }

        return null;
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
                $propertyMap = $this->schemaObject($branch['properties'], 'allOf properties must be an object');
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
            $types = $this->types($branch['type'] ?? null);
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

        $format = $schema['format'] ?? null;
        if ($format !== null && !is_string($format)) {
            throw UnsupportedGeneration::forSchema('format must be a string');
        }
        /** @var ArbitraryInterface<string> $arbitrary */
        $arbitrary = match ($format) {
            null => Gen::stringOf($min, $max),
            'uuid' => Gen::uuid(),
            'email' => Gen::email(),
            'ipv4' => Gen::ipv4(),
            'uri', 'uri-reference', 'url' => Gen::url(),
            'date-time' => Gen::map(Gen::datetime(), static function (mixed $value): string {
                if (!$value instanceof DateTimeImmutable) {
                    throw new \LogicException('Datetime arbitrary produced an invalid value');
                }

                return $value->format(DATE_RFC3339_EXTENDED);
            }),
            'date' => Gen::map(Gen::datetime(), static function (mixed $value): string {
                if (!$value instanceof DateTimeImmutable) {
                    throw new \LogicException('Datetime arbitrary produced an invalid value');
                }

                return $value->format('Y-m-d');
            }),
            default => throw UnsupportedGeneration::forSchema(sprintf('format "%s" is outside the supported format subset', $format)),
        };
        $pattern = $schema['pattern'] ?? null;
        if ($pattern !== null) {
            if (!is_string($pattern)) {
                throw UnsupportedGeneration::forSchema('pattern must be a string');
            }

            try {
                $arbitrary = Gen::stringMatching($pattern);
            } catch (\InvalidArgumentException $exception) {
                throw UnsupportedGeneration::forSchema(sprintf('pattern is not supported: %s', $exception->getMessage()));
            }
        }

        if ($format !== null || $pattern !== null) {
            return Gen::filter($arbitrary, static fn(mixed $value): bool => is_string($value)
                && mb_strlen($value) >= $min
                && mb_strlen($value) <= $max);
        }

        return $arbitrary;
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

        $multiple = $schema['multipleOf'] ?? null;
        if ($multiple === null) {
            return Gen::intBetween($min, $max);
        }
        if (!is_int($multiple) || $multiple <= 0) {
            throw UnsupportedGeneration::forSchema('integer multipleOf must be a positive integer');
        }
        $multipleValue = (float) $multiple;
        $first = (int) ceil((float) $min / $multipleValue);
        $last = (int) floor((float) $max / $multipleValue);
        if ($first > $last) {
            throw UnsupportedGeneration::forSchema('integer multipleOf leaves no value');
        }

        return Gen::map(Gen::intBetween($first, $last), static fn(mixed $value): int => (int) $value * $multiple);
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

        $multiple = $schema['multipleOf'] ?? null;
        if ($multiple === null) {
            return Gen::floatBetween($min, $max);
        }
        if (!is_int($multiple) && !is_float($multiple)) {
            throw UnsupportedGeneration::forSchema('number multipleOf must be numeric');
        }
        if ($multiple <= 0 || !is_finite((float) $multiple)) {
            throw UnsupportedGeneration::forSchema('number multipleOf must be positive and finite');
        }
        $first = (int) ceil($min / (float) $multiple);
        $last = (int) floor($max / (float) $multiple);
        if ($first > $last) {
            throw UnsupportedGeneration::forSchema('number multipleOf leaves no value');
        }

        return Gen::map(Gen::intBetween($first, $last), static fn(mixed $value): float => (float) $value * (float) $multiple);
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
        $properties = $this->schemaObject($schema['properties'] ?? [], 'object properties must be an object');
        $required = $schema['required'] ?? [];
        if (!is_array($required) || !array_is_list($required)) {
            throw UnsupportedGeneration::forSchema('required must be a list of property names');
        }
        $shape = [];
        $minProperties = $this->nonNegativeInt($schema, 'minProperties', 0);
        $maxProperties = min($this->nonNegativeInt($schema, 'maxProperties', self::MAX_COLLECTION_SIZE), self::MAX_COLLECTION_SIZE);
        if ($minProperties > $maxProperties) {
            throw UnsupportedGeneration::forSchema('minProperties exceeds maxProperties or the generation budget');
        }
        /** @var array<string, true> $requiredNames */
        $requiredNames = [];
        foreach ($required as $name) {
            if (!is_string($name)) {
                throw UnsupportedGeneration::forSchema('required must contain property names');
            }
            $requiredNames[$name] = true;
        }
        foreach ($properties as $name => $property) {
            if (!is_array($property) || array_is_list($property)) {
                throw UnsupportedGeneration::forSchema('object properties must contain named schema objects');
            }
            /** @var array<string, mixed> $property */
            $compiled = $this->compile($property);
            $shape[$name] = isset($requiredNames[$name]) ? $compiled : $this->optionalProperty($compiled);
        }
        foreach (array_keys($required) as $index) {
            if (!is_string($required[$index]) || !array_key_exists($required[$index], $shape)) {
                throw UnsupportedGeneration::forSchema('required properties without a schema are not supported');
            }
        }
        $requiredCount = count($requiredNames);
        if ($requiredCount > $maxProperties) {
            throw UnsupportedGeneration::forSchema('required properties exceed maxProperties');
        }

        /** @var ArbitraryInterface<array<string, mixed>> $base */
        $base = $shape === []
            ? Gen::constant(value: [])
            : Gen::map(Gen::record($shape), static function (array $values) use ($requiredNames): array {
                /** @var array<string, mixed> $typed */
                $typed = [];
                foreach (array_keys($values) as $name) {
                    if (is_string($name)) {
                        $typed = array_merge($typed, [$name => $values[$name]]);
                    }
                }

                return self::objectValues($typed, $requiredNames);
            });

        // Keep optional-property branches within maxProperties. Additional
        // properties are materialized only when minProperties requires them;
        // this keeps generated objects small while still honoring cardinality.
        $base = Gen::filter($base, static fn(array $values): bool => count($values) <= $maxProperties);
        $additional = $this->additionalPropertiesSchema($schema);
        if ($additional === false && $minProperties > count($shape)) {
            throw UnsupportedGeneration::forSchema('minProperties requires additional properties, but additionalProperties is false');
        }
        if ($additional === false || $minProperties <= 0 && $shape !== []) {
            return Gen::filter($base, static fn(array $values): bool => count($values) >= $minProperties);
        }

        if ($shape === [] && $minProperties === 0 && $maxProperties === 0) {
            return Gen::constant([]);
        }

        $keyAlphabet = 'abcdefghijklmnopqrstuvwxyz';
        /** @var ArbitraryInterface<array-key> $key */
        $key = Gen::map(
            Gen::filter(
                Gen::stringFrom($keyAlphabet, minLength: 1, maxLength: 8),
                static fn(string $name): bool => !array_key_exists($name, $shape),
            ),
            static fn(string $name): string => $name,
        );
        /** @var ArbitraryInterface<mixed> $value */
        $value = is_array($additional) && $additional !== []
            ? $this->compile($additional)
            : $this->additionalValue();

        return Gen::flatMap($base, fn(array $values): ArbitraryInterface => $this->additionalProperties(
            $values,
            $minProperties,
            $maxProperties,
            $key,
            $value,
        ));
    }

    /**
     * @param array<array-key, mixed> $values
     * @param ArbitraryInterface<array-key> $key
     * @param ArbitraryInterface<mixed> $value
     * @return ArbitraryInterface<array<array-key, mixed>>
     */
    private function additionalProperties(
        array $values,
        int $minProperties,
        int $maxProperties,
        ArbitraryInterface $key,
        ArbitraryInterface $value,
    ): ArbitraryInterface {
        $missing = max(0, $minProperties - count($values));
        $room = max(0, $maxProperties - count($values));
        if ($room === 0) {
            /** @var ArbitraryInterface<array<array-key, mixed>> $result */
            $result = Gen::map(Gen::constant(value: $values), static fn(array $value): array => $value);

            return $result;
        }

        $extras = Gen::dictOf($key, $value, minSize: $missing, maxSize: $room);

        /** @var ArbitraryInterface<array<array-key, mixed>> $result */
        $result = Gen::map($extras, static fn(array $extra): array => array_merge($values, $extra));

        return $result;
    }

    /** @return ArbitraryInterface<mixed> */
    private function additionalValue(): ArbitraryInterface
    {
        /** @var ArbitraryInterface<mixed> $string */
        $string = Gen::map(Gen::stringOf(0, 8), static fn(string $value): mixed => $value);
        /** @var ArbitraryInterface<mixed> $integer */
        $integer = Gen::map(Gen::intBetween(-1000, 1000), static fn(int $value): mixed => $value);
        /** @var ArbitraryInterface<mixed> $boolean */
        $boolean = Gen::map(Gen::bool(), static fn(bool $value): mixed => $value);
        /** @var ArbitraryInterface<mixed> $null */
        $null = Gen::map(Gen::constant(value: null), static fn(null $value): mixed => $value);

        return Gen::frequency([
            [3, $string],
            [2, $integer],
            [1, $boolean],
            [1, $null],
        ]);
    }

    private function optionalProperty(ArbitraryInterface $compiled): ArbitraryInterface
    {
        /** @var ArbitraryInterface<array{present: bool, value: mixed}> $absent */
        $absent = Gen::map(Gen::constant(value: false), static fn(bool $present): array => ['present' => $present, 'value' => null]);
        /** @var ArbitraryInterface<array{present: bool, value: mixed}> $present */
        $present = Gen::map($compiled, static fn(mixed $value): array => ['present' => true, 'value' => $value]);

        return Gen::frequency([[1, $absent], [1, $present]]);
    }

    /** @param array<array-key, mixed> $values
     * @param array<string, true> $requiredNames
     * @return array<string, mixed>
     */
    private static function objectValues(array $values, array $requiredNames): array
    {
        $result = [];
        foreach (array_keys($values) as $name) {
            if (!is_string($name)) {
                continue;
            }
            if (isset($requiredNames[$name])) {
                $result = array_merge($result, [$name => $values[$name]]);
                continue;
            }
            if (is_array($values[$name]) && ($values[$name]['present'] ?? false) === true && array_key_exists('value', $values[$name])) {
                $result = array_merge($result, [$name => $values[$name]['value']]);
            }
        }

        return $result;
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
            '$ref', 'allOf', 'anyOf', 'oneOf', 'if', 'then', 'else',
            'contains', 'prefixItems', 'patternProperties',
            'propertyNames', 'unevaluatedProperties',
        ] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                throw UnsupportedGeneration::forSchema(sprintf('keyword "%s" is outside the initial support matrix', $keyword));
            }
        }
        if (array_key_exists('additionalProperties', $schema)) {
            $this->additionalPropertiesSchema($schema);
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
     * @param array<string, mixed> $schema
     * @return bool|array<string, mixed>
     */
    private function additionalPropertiesSchema(array $schema): bool|array
    {
        if (!array_key_exists('additionalProperties', $schema)) {
            return true;
        }

        return $this->booleanOrSchema($schema['additionalProperties']);
    }

    /** @return bool|array<string, mixed> */
    private function booleanOrSchema(mixed $value): bool|array
    {
        if (is_bool($value)) {
            return $value;
        }

        return $this->schemaObject($value, 'additionalProperties must be a boolean or schema object');
    }

    /** @return array<string, mixed> */
    private function schemaObject(mixed $value, string $error): array
    {
        if (!is_array($value) || $value !== [] && array_is_list($value)) {
            throw UnsupportedGeneration::forSchema($error);
        }

        /** @var array<string, mixed> $value */
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
