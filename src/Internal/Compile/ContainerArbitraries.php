<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile;

use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Compiles the array and object schema sections.
 *
 * @internal
 */
final readonly class ContainerArbitraries
{
    private const int MAX_COLLECTION_SIZE = 16;

    public function __construct(
        private SchemaArbitraryCompiler $compiler,
        private SchemaFacts $facts,
    ) {}

    /** @param array<string, mixed> $schema */
    public function array(array $schema): ArbitraryInterface
    {
        $items = $schema['items'] ?? null;
        if (!is_array($items) || array_is_list($items)) {
            throw UnsupportedGeneration::forSchema('array items must be a schema object');
        }
        /** @var array<string, mixed> $items */
        $min = $this->facts->nonNegativeInt($schema, 'minItems', 0);
        $max = min($this->facts->nonNegativeInt($schema, 'maxItems', self::MAX_COLLECTION_SIZE), self::MAX_COLLECTION_SIZE);
        if ($min > $max) {
            throw UnsupportedGeneration::forSchema('minItems exceeds maxItems or the generation budget');
        }

        $element = $this->compiler->compile($items);

        return ($schema['uniqueItems'] ?? false) === true
            ? Gen::uniqueArrayOf($element, $min, $max)
            : Gen::arrayOf($element, $min, $max);
    }

    /** @param array<string, mixed> $schema */
    public function object(array $schema): ArbitraryInterface
    {
        $properties = $this->facts->schemaObject($schema['properties'] ?? [], 'object properties must be an object');
        $required = $schema['required'] ?? [];
        if (!is_array($required) || !array_is_list($required)) {
            throw UnsupportedGeneration::forSchema('required must be a list of property names');
        }
        $shape = [];
        $minProperties = $this->facts->nonNegativeInt($schema, 'minProperties', 0);
        $maxProperties = min($this->facts->nonNegativeInt($schema, 'maxProperties', self::MAX_COLLECTION_SIZE), self::MAX_COLLECTION_SIZE);
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
            $compiled = $this->compiler->compile($property);
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
        $additional = $this->facts->additionalPropertiesSchema($schema);
        if ($additional === false && $minProperties > count($shape)) {
            throw UnsupportedGeneration::forSchema('minProperties requires additional properties, but additionalProperties is false');
        }
        if ($additional === false || $minProperties <= 0 && $shape !== []) {
            return Gen::filter($base, static fn(array $values): bool => count($values) >= $minProperties);
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
            ? $this->compiler->compile($additional)
            : $this->additionalValue();

        return Gen::flatMap($base, fn(array $values): ArbitraryInterface => $this->additionalProperties(
            $values,
            $minProperties,
            $maxProperties,
            $key,
            $value,
        ));
    }

    /** @return ArbitraryInterface<mixed> */
    public function additionalValue(): ArbitraryInterface
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
}
