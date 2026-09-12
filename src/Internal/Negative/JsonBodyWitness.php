<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Random;

/**
 * Builds, for one JSON body, the value a misuse category writes over a
 * top-level property (or over the scalar root, named `$`) so that the schema
 * provably rejects it.
 *
 * Shared by the request and the response side: a body is judged by the same
 * schema keywords in either direction, so the witness that contradicts them
 * is the same too. Only what surrounds it differs — which body is required,
 * which media type it travels under — and that stays with each side's
 * targets.
 *
 * @internal
 *
 * @psalm-type Kind = 'type'|'enum'|'const'|'boundary'|'length'|'format'|'pattern'
 * @psalm-type Witness = int|float|bool|string|array<array-key, mixed>
 */
final readonly class JsonBodyWitness
{
    public const string ROOT = '$';

    public function __construct(
        private SchemaProbe $probe = new SchemaProbe(),
        private PatternWitness $patterns = new PatternWitness(),
        private WitnessCheck $check = new WitnessCheck(),
        private SchemaArbitraryCompiler $items = new SchemaArbitraryCompiler(),
    ) {}

    /**
     * Every top-level property (or the scalar root) with a constructible
     * witness for the kind, in declaration order; empty when none has one.
     *
     * All of them, not the first: returning one made the choice deterministic
     * and position-based, so a body declaring two properties constrained the
     * same way had one absorb every case of that category while the other was
     * never exercised (#99).
     *
     * Each value is offered to {@see WitnessCheck}, which keeps it only if
     * the property's schema rejects it and the schema without the category's
     * keywords accepts it — so the witness earns the category it is recorded
     * under rather than being assumed to (#102).
     *
     * @param array<string, mixed> $schema
     * @param Kind $kind
     * @return list<array{name: string, invalid: Witness}>
     */
    public function findAll(array $schema, string $kind, SchemaDialect $dialect, SchemaDirection $direction): array
    {
        $targets = [];
        foreach ($this->candidates($schema) as $name => $property) {
            $invalid = $this->check->firstDiscriminating($this->witnesses($property, $kind), $property, $kind, $dialect, $direction);
            if ($invalid !== null) {
                /** @var Witness $invalid */
                // The name is cast back: PHP stores a decimal-integer
                // property name (`"12"`) as an `int` key, and the misuse
                // records what the document declares (#98).
                $targets[] = ['name' => (string) $name, 'invalid' => $invalid];
            }
        }

        return $targets;
    }

    /**
     * A decimal-integer property name is an `int` key here: that is the only
     * spelling PHP has for one, not a malformed map, and the generator, the
     * encoder and the contract all handle it. Rejecting it left an operation
     * whose only constrained property is numeric with no constructible body
     * value category at all (#98).
     *
     * @param array<string, mixed> $schema
     * @return array<array-key, array<string, mixed>> keyed by property name, or `$` for a scalar root
     */
    private function candidates(array $schema): array
    {
        if (!$this->isObject($schema)) {
            return [self::ROOT => $schema];
        }
        $candidates = [];
        $properties = $this->mapOf($schema['properties'] ?? null);
        /** @var mixed $property */
        foreach ($properties as $name => $property) {
            if ((is_int($name) || $name !== '') && is_array($property) && !array_is_list($property)) {
                /** @var array<string, mixed> $property */
                $candidates[$name] = $property;
            }
        }

        return $candidates;
    }

    /**
     * The values worth offering for one property and category, in preference
     * order.
     *
     * @param array<string, mixed> $schema
     * @param Kind $kind
     * @return list<Witness>
     */
    private function witnesses(array $schema, string $kind): array
    {
        if (($schema['nullable'] ?? false) === true || array_key_exists('not', $schema)) {
            return [];
        }
        $types = array_values($this->probe->declaredTypes($schema));

        return match ($kind) {
            'type' => $this->typeWitness($types),
            'enum' => $this->finiteWitnesses($schema, 'enum'),
            'const' => $this->finiteWitnesses($schema, 'const'),
            'boundary' => $this->probe->outOfRangeValues($schema),
            'length' => $this->lengthWitness($schema, $types),
            'format' => $this->probe->formatWitnesses($schema),
            'pattern' => $this->patternWitness($schema),
        };
    }

    /**
     * A union admits every type it lists, so a witness built for one member
     * stays valid under the others.
     *
     * @param list<mixed> $types
     * @return list<string|int>
     */
    private function typeWitness(array $types): array
    {
        if (count($types) !== 1) {
            return [];
        }

        return match ($types[0]) {
            'integer', 'number', 'boolean', 'null', 'array', 'object' => ['not-a-' . $types[0]],
            'string' => [4096],
            default => [],
        };
    }

    /**
     * The marker the category has always produced first, then typed
     * alternatives: a marker string cannot contradict an `enum` of integers
     * without also contradicting the type, and one longer than `maxLength`
     * cannot contradict a string enum without also breaking the length
     * (#102).
     *
     * @param array<string, mixed> $schema
     * @param 'enum'|'const' $keyword
     * @return list<int|float|bool|string>
     */
    private function finiteWitnesses(array $schema, string $keyword): array
    {
        if (!array_key_exists($keyword, $schema)) {
            return [];
        }
        if ($keyword === 'enum') {
            $enum = $schema['enum'];
            if (!is_array($enum) || $enum === [] || !$this->probe->isScalarEnum($enum)) {
                return [];
            }
            $forbidden = array_values($enum);
            $marker = '__openapi_misuse__';
        } else {
            if (!is_scalar($schema['const']) && $schema['const'] !== null) {
                return [];
            }
            $forbidden = [$schema['const']];
            $marker = is_string($schema['const']) ? $schema['const'] . '__openapi_misuse__' : '__openapi_misuse__';
        }
        while (in_array($marker, $forbidden, strict: true)) {
            $marker .= '_';
        }

        return [$marker, ...$this->probe->alternatives($schema, $forbidden)];
    }

    /**
     * An over-long array is offered under several fillers. A list of `null`s
     * is the shortest thing to build, but it contradicts `items` as well as
     * `maxItems` whenever the items are typed — so it was never the pure
     * length mismatch the category claims, and the check rejects it. One of
     * the other fillers satisfies `items` for the common item types, and the
     * check keeps that one (#102).
     *
     * @param array<string, mixed> $schema
     * @param list<mixed> $types
     * @return list<string|array<array-key, mixed>>
     */
    private function lengthWitness(array $schema, array $types): array
    {
        $candidates = $this->probe->outOfLengthValues($schema);
        if (!in_array('array', $types, strict: true)) {
            return $candidates;
        }
        $minItems = is_int($schema['minItems'] ?? null) ? (int) $schema['minItems'] : 0;
        if ($minItems >= 1) {
            $candidates[] = [];
        }
        $maxItems = is_int($schema['maxItems'] ?? null) ? (int) $schema['maxItems'] : 64;
        if ($maxItems >= 0 && $maxItems < 64) {
            /** @var mixed $filler */
            foreach ([null, 0, '', false, $this->validItem($schema)] as $filler) {
                $candidates[] = array_fill(0, $maxItems + 1, $filler);
            }
        }

        return $candidates;
    }

    /**
     * One value the `items` schema admits, drawn at a fixed seed.
     *
     * A list of `null`s contradicts typed items, and the fixed fillers cover
     * only scalars — so the most common list body there is, an array of
     * objects, would have no provable length witness at all. Generating one
     * item the same way the valid cases are generated keeps the category, and
     * the check still decides whether the result is a pure length mismatch.
     *
     * @param array<string, mixed> $schema
     */
    private function validItem(array $schema): mixed
    {
        $items = $schema['items'] ?? null;
        if (!is_array($items) || array_is_list($items)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $items */
            return $this->items->compile($items)->generate(new Random(1))->value;
        } catch (UnsupportedGeneration) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private function patternWitness(array $schema): array
    {
        $constraints = $this->probe->patternConstraints($schema);
        if ($constraints === null) {
            return [];
        }
        $witness = $this->patterns->search($constraints['pattern'], $constraints['minLength'], $constraints['maxLength']);

        return $witness === null ? [] : [$witness];
    }

    /** @param array<string, mixed> $schema */
    private function isObject(array $schema): bool
    {
        return ($schema['type'] ?? null) === 'object' || array_key_exists('properties', $schema);
    }

    /** @return array<array-key, mixed> */
    private function mapOf(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }
}
