<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile;

use Rasuvaeff\OpenApiContract\SchemaDirection;
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
        private ?SchemaDirection $direction = null,
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

        try {
            $element = $this->compiler->compile($items);
        } catch (Unproducible $leaf) {
            // The leaf of a recursive def: the array that holds no more of
            // it ends the recursion — where the schema lets it be empty.
            if ($min > 0) {
                throw $leaf;
            }

            return Gen::constant(value: []);
        }
        if (($schema['uniqueItems'] ?? false) !== true) {
            return Gen::arrayOf($element, $min, $max);
        }
        $domain = $this->finiteDomain($items);
        if ($domain !== null && $domain < $min) {
            throw UnsupportedGeneration::forSchema('uniqueItems cannot fill minItems from the finite item domain');
        }

        return Gen::uniqueArrayOf($element, $min, $max);
    }

    /**
     * The number of distinct values an item schema can take when that number
     * is knowable from its shape alone.
     *
     * @param array<string, mixed> $items
     */
    private function finiteDomain(array $items): ?int
    {
        if (array_key_exists('const', $items)) {
            return 1;
        }
        if (is_array($items['enum'] ?? null)) {
            return count(array_unique(array_map(serialize(...), (array) $items['enum'])));
        }

        if (($items['type'] ?? null) === 'integer' && is_int($items['minimum'] ?? null) && is_int($items['maximum'] ?? null)) {
            // An upper bound on the domain, not its size: a multipleOf would
            // thin it further. Enough to refuse `minItems: 3` over `0..1`
            // before a run-time exhaustion does (#123).
            $minimum = (int) $items['minimum'] + ((($items['exclusiveMinimum'] ?? false) === true) ? 1 : 0);
            $maximum = (int) $items['maximum'] - ((($items['exclusiveMaximum'] ?? false) === true) ? 1 : 0);

            return max(0, $maximum - $minimum + 1);
        }

        return match ($items['type'] ?? null) {
            'boolean' => 2,
            'null' => 1,
            default => null,
        };
    }

    /** @param array<string, mixed> $schema */
    public function object(array $schema): ArbitraryInterface
    {
        $properties = $this->facts->schemaObject($schema['properties'] ?? [], 'object properties must be an object');
        $patterns = $this->facts->schemaObject($schema['patternProperties'] ?? [], 'patternProperties must be an object of schemas');
        /** @var mixed $propertyNamesValue */
        $propertyNamesValue = $schema['propertyNames'] ?? null;
        if ($propertyNamesValue !== null && (!is_array($propertyNamesValue) || array_is_list($propertyNamesValue))) {
            throw UnsupportedGeneration::forSchema('propertyNames must be a schema object');
        }
        /** @var array<string, mixed>|null $propertyNames */
        $propertyNames = $propertyNamesValue;
        if ($propertyNames !== null) {
            foreach (array_keys($propertyNames) as $keyword) {
                if ($keyword !== 'pattern') {
                    throw UnsupportedGeneration::forSchema(sprintf('propertyNames keyword "%s" is outside the supported generator subset', $keyword));
                }
            }
        }
        $patternArbitraries = [];
        /** @var array<string, array<string, mixed>> $typedPatterns */
        $typedPatterns = [];
        foreach ($patterns as $pattern => $patternSchema) {
            if (!is_array($patternSchema) || array_is_list($patternSchema)) {
                throw UnsupportedGeneration::forSchema('patternProperties must contain named schema objects');
            }
            /** @var array<string, mixed> $patternSchema */
            $typedPatterns[$pattern] = $patternSchema;

            try {
                $key = Gen::stringMatching($pattern);
            } catch (\InvalidArgumentException $exception) {
                throw UnsupportedGeneration::forSchema(sprintf('patternProperties key is not supported: %s', $exception->getMessage()));
            }
            $patternArbitraries[] = [$pattern, $key];
        }
        $patterns = $typedPatterns;
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
        $omitted = $this->direction?->foreignFlag();
        /** @var array<array-key, true> $reserved */
        $reserved = [];
        /** @var array<string, true> $requiredNames */
        foreach ($properties as $name => $property) {
            if ($propertyNames !== null && !$this->matchesSchema($name, $propertyNames)) {
                throw UnsupportedGeneration::forSchema('propertyNames excludes a declared property');
            }
            if (!is_array($property) || array_is_list($property)) {
                throw UnsupportedGeneration::forSchema('object properties must contain named schema objects');
            }
            /** @var array<string, mixed> $property */
            if ($omitted !== null && ($property[$omitted] ?? false) === true) {
                // Owned by the other direction: never sent, still declared.
                $reserved[$name] = true;
                unset($requiredNames[$name]);

                continue;
            }

            $applicable = [$property];
            foreach ($patterns as $pattern => $patternSchema) {
                if (preg_match('~' . $pattern . '~', $name) === 1) {
                    /** @var array<string, mixed> $patternSchema */
                    $applicable[] = $patternSchema;
                }
            }
            $compiled = $this->producible(count($applicable) === 1 ? $property : ['allOf' => $applicable]);
            if (!$compiled instanceof \Rasuvaeff\PropertyTesting\ArbitraryInterface) {
                // The leaf of a recursive def: an optional member that would
                // hold more of it is left out, a required one gives it up.
                if (isset($requiredNames[$name])) {
                    throw new Unproducible($name);
                }
                $reserved[$name] = true;

                continue;
            }
            $shape[$name] = isset($requiredNames[$name]) ? $compiled : $this->optionalProperty($compiled);
        }
        foreach (array_keys($requiredNames) as $name) {
            if (!array_key_exists($name, $shape)) {
                throw UnsupportedGeneration::forSchema('required properties without a schema are not supported');
            }
        }
        $requiredCount = count($requiredNames);
        if ($requiredCount > $maxProperties) {
            throw UnsupportedGeneration::forSchema('required properties exceed maxProperties');
        }

        $additional = $this->facts->additionalPropertiesSchema($schema);
        if ($additional === false && $patternArbitraries === [] && $minProperties > count($shape)) {
            throw UnsupportedGeneration::forSchema('minProperties requires additional properties, but additionalProperties is false');
        }
        // The declared optionals meet the cardinality by construction: an
        // optional past `maxProperties` is left out, an optional needed for
        // `minProperties` is brought in — declared ones first, extras only for
        // what they cannot cover. A filter here drew twelve independent
        // presence choices against `maxProperties: 1` and exhausted three
        // runs in four (#123).
        $declaredFloor = $additional === false ? $minProperties : min($minProperties, count($shape));
        /** @var ArbitraryInterface<array<string, mixed>> $base */
        $base = $shape === []
            ? Gen::constant(value: [])
            : Gen::map(Gen::record($shape), static function (array $values) use ($requiredNames, $declaredFloor, $maxProperties): array {
                /** @var array<string, mixed> $typed */
                $typed = [];
                foreach (array_keys($values) as $name) {
                    // `array_replace`, not `array_merge`: the latter renumbers
                    // an integer-like key, which is exactly the name a numeric
                    // property carries.
                    $typed = array_replace($typed, [$name => $values[$name]]);
                }

                return self::objectValues($typed, $requiredNames, $declaredFloor, $maxProperties);
            });

        if (($additional === false && $patternArbitraries === []) || ($minProperties <= 0 && $shape !== [] && $patternArbitraries === [])) {
            return $base;
        }

        $keyAlphabet = 'abcdefghijklmnopqrstuvwxyz';
        /** @var ArbitraryInterface<array-key> $key */
        if ($patternArbitraries !== []) {
            $key = Gen::map(Gen::frequency(array_map(
                function (array $entry) use ($shape, $reserved, $propertyNames, $patterns): array {
                    /** @var string $pattern */
                    [$pattern, $source] = $entry;
                    $filtered = Gen::filter($source, function (mixed $name) use ($pattern, $shape, $reserved, $propertyNames, $patterns): bool {
                        if (!is_string($name) || array_key_exists($name, $shape) || isset($reserved[$name])) {
                            return false;
                        }
                        if ($propertyNames !== null && !$this->matchesSchema($name, $propertyNames)) {
                            return false;
                        }
                        $matches = [];
                        foreach (array_keys($patterns) as $candidate) {
                            if (preg_match('~' . $candidate . '~', $name) === 1) {
                                $matches[] = $candidate;
                            }
                        }

                        return in_array($pattern, $matches, strict: true);
                    });

                    return [1, $filtered];
                },
                $patternArbitraries,
            )), static fn(string $name): int|string => $name);
            /** @var ArbitraryInterface<array-key> $key */
            $key = $key;
        } else {
            $key = Gen::map(
                Gen::filter(
                    Gen::stringFrom($keyAlphabet, minLength: 1, maxLength: 8),
                    function (string $name) use ($shape, $reserved, $propertyNames): bool {
                        return !array_key_exists($name, $shape) && !isset($reserved[$name])
                            && ($propertyNames === null || $this->matchesSchema($name, $propertyNames));
                    },
                ),
                static fn(string $name): int|string => $name,
            );
            /** @var ArbitraryInterface<array-key> $key */
            $key = $key;
        }
        /** @var ArbitraryInterface<mixed> $value */
        if ($patternArbitraries !== []) {
            $value = Gen::flatMap($key, function (mixed $name) use ($patterns, $additional): ArbitraryInterface {
                if (is_string($name)) {
                    $matching = [];
                    /** @var list<array<string, mixed>> $matching */
                    foreach ($patterns as $pattern => $patternSchema) {
                        if (preg_match('~' . $pattern . '~', $name) === 1) {
                            /** @var array<string, mixed> $patternSchema */
                            $matching[] = $patternSchema;
                        }
                    }
                    if ($matching !== []) {
                        return $this->compiler->compile(['allOf' => $matching]);
                    }
                }

                return is_array($additional) && $additional !== []
                    ? $this->compiler->compile($additional)
                    : $this->additionalValue();
            });
        } else {
            $value = is_array($additional) && $additional !== []
                ? $this->compiler->compile($additional)
                : $this->additionalValue();
        }

        return Gen::flatMap($base, fn(array $values): ArbitraryInterface => $this->additionalProperties(
            $values,
            $minProperties,
            $maxProperties,
            $key,
            $value,
        ));
    }

    /** @param array<string, mixed> $schema */
    private function matchesSchema(string $value, array $schema): bool
    {
        $pattern = $schema['pattern'] ?? null;

        if ($pattern === null) {
            return true;
        }
        if (!is_string($pattern)) {
            throw UnsupportedGeneration::forSchema('propertyNames pattern must be a string');
        }

        $result = preg_match('~' . $pattern . '~', $value);
        if ($result === false) {
            throw UnsupportedGeneration::forSchema('propertyNames pattern is not supported');
        }

        return $result === 1;
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
        $result = Gen::map($extras, static fn(array $extra): array => array_replace($values, $extra));

        return $result;
    }

    /**
     * An optional property carries a value whether or not it is present, so
     * the cardinality pass can bring an absent one in without a second draw.
     */
    /**
     * The member's arbitrary, or `null` where the member is the leaf of a
     * recursive def and has no value at this depth.
     *
     * @param array<string, mixed> $schema
     */
    private function producible(array $schema): ?ArbitraryInterface
    {
        try {
            return $this->compiler->compile($schema);
        } catch (Unproducible) {
            return null;
        }
    }

    private function optionalProperty(ArbitraryInterface $compiled): ArbitraryInterface
    {
        return Gen::record(['present' => Gen::bool(), 'value' => $compiled]);
    }

    /**
     * @param array<array-key, mixed> $values
     * @param array<array-key, true> $requiredNames
     * @param int $declaredFloor the member count the declared optionals have
     *        to reach, absent ones brought in in declaration order
     * @param int $maxProperties the member count past which a present
     *        optional is left out, last declared first
     * @return array<array-key, mixed> keyed by member name; a numeric name is
     *         an integer key, because that is the only way PHP can hold it
     */
    private static function objectValues(array $values, array $requiredNames, int $declaredFloor, int $maxProperties): array
    {
        $result = [];
        $absent = [];
        foreach (array_keys($values) as $name) {
            // A numeric property name arrives as an integer key and is kept as
            // one: it normalizes back the moment it is used as an array key,
            // and dropping such a name — which this used to do — took a
            // required property out of the generated object entirely.
            if (isset($requiredNames[$name])) {
                $result = array_replace($result, [$name => $values[$name]]);
                continue;
            }
            $optional = $values[$name];
            if (!is_array($optional) || !array_key_exists('value', $optional)) {
                throw new \LogicException('Generated optional property has an invalid shape');
            }
            if (($optional['present'] ?? false) === true && count($result) < $maxProperties) {
                $result = array_replace($result, [$name => $optional['value']]);
            } else {
                $absent = array_replace($absent, [$name => $optional['value']]);
            }
        }
        foreach (array_keys($absent) as $name) {
            if (count($result) >= $declaredFloor) {
                break;
            }
            $result = array_replace($result, [$name => $absent[$name]]);
        }

        return $result;
    }
}
