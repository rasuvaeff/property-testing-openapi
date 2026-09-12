<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

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
 * @psalm-type Witness = int|float|string|list<null>
 */
final readonly class JsonBodyWitness
{
    public const string ROOT = '$';

    public function __construct(
        private SchemaProbe $probe = new SchemaProbe(),
        private PatternWitness $patterns = new PatternWitness(),
    ) {}

    /**
     * The first top-level property (or the scalar root) with a constructible
     * witness for the kind, in declaration order; `null` when none has one.
     *
     * @param array<string, mixed> $schema
     * @param Kind $kind
     * @return array{name: string, invalid: Witness}|null
     */
    public function find(array $schema, string $kind): ?array
    {
        foreach ($this->candidates($schema) as $name => $candidate) {
            $invalid = $this->witness($candidate, $kind);
            if ($invalid !== null) {
                // The name is cast back: PHP stores a decimal-integer
                // property name (`"12"`) as an `int` key, and the misuse
                // records what the document declares (#98).
                return ['name' => (string) $name, 'invalid' => $invalid['value']];
            }
        }

        return null;
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
     * @param array<string, mixed> $schema
     * @param Kind $kind
     * @return null|array{value: Witness}
     */
    private function witness(array $schema, string $kind): ?array
    {
        if (($schema['nullable'] ?? false) === true || array_key_exists('not', $schema)) {
            return null;
        }
        $types = array_values($this->probe->declaredTypes($schema));

        return match ($kind) {
            'type' => $this->typeWitness($schema, $types),
            'enum' => $this->enumWitness($schema),
            'const' => $this->constWitness($schema),
            'boundary' => $this->numericWitness($this->probe->outOfRangeValue($schema), $types),
            'length' => $this->lengthWitness($schema, $types),
            'format' => $this->stringWitness($this->probe->formatWitness($schema)),
            'pattern' => $this->patternWitness($schema),
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<mixed> $types
     * @return null|array{value: string|int}
     */
    private function typeWitness(array $schema, array $types): ?array
    {
        if (count($types) !== 1 || array_key_exists('enum', $schema) || array_key_exists('const', $schema)) {
            return null;
        }

        return match ($types[0]) {
            'integer', 'number', 'boolean', 'null', 'array', 'object' => ['value' => 'not-a-' . $types[0]],
            'string' => ['value' => 4096],
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $schema
     * @return null|array{value: string}
     */
    private function enumWitness(array $schema): ?array
    {
        $enum = $schema['enum'] ?? null;
        if (!is_array($enum) || $enum === [] || !$this->probe->isScalarEnum($enum)) {
            return null;
        }
        $invalid = '__openapi_misuse__';
        while (in_array($invalid, $enum, strict: true)) {
            $invalid .= '_';
        }

        return ['value' => $invalid];
    }

    /**
     * @param array<string, mixed> $schema
     * @return null|array{value: string}
     */
    private function constWitness(array $schema): ?array
    {
        if (!array_key_exists('const', $schema)) {
            return null;
        }
        $const = $schema['const'];
        if (!is_scalar($const) && $const !== null) {
            return null;
        }

        return ['value' => is_string($const) ? $const . '__openapi_misuse__' : '__openapi_misuse__'];
    }

    /**
     * @param list<mixed> $types
     * @return null|array{value: int|float}
     */
    private function numericWitness(?string $wire, array $types): ?array
    {
        if ($wire === null) {
            return null;
        }

        return ['value' => in_array('integer', $types, strict: true) ? (int) $wire : (float) $wire];
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<mixed> $types
     * @return null|array{value: string|list<null>}
     */
    private function lengthWitness(array $schema, array $types): ?array
    {
        $string = $this->probe->outOfLengthValue($schema);
        if ($string !== null) {
            return ['value' => $string];
        }
        if (!in_array('array', $types, strict: true)) {
            return null;
        }
        $minItems = is_int($schema['minItems'] ?? null) ? (int) $schema['minItems'] : 0;
        if ($minItems >= 1) {
            return ['value' => []];
        }
        $maxItems = is_int($schema['maxItems'] ?? null) ? (int) $schema['maxItems'] : 64;
        if ($maxItems >= 0 && $maxItems < 64) {
            return ['value' => array_fill(0, $maxItems + 1, null)];
        }

        return null;
    }

    /** @return null|array{value: string} */
    private function stringWitness(?string $witness): ?array
    {
        return $witness === null ? null : ['value' => $witness];
    }

    /**
     * @param array<string, mixed> $schema
     * @return null|array{value: string}
     */
    private function patternWitness(array $schema): ?array
    {
        $constraints = $this->probe->patternConstraints($schema);
        if ($constraints === null) {
            return null;
        }
        /** @var non-empty-string $pattern */
        $pattern = $constraints['pattern'];
        /** @var int<0, max> $minLength */
        $minLength = $constraints['minLength'];
        /** @var int<0, max> $maxLength */
        $maxLength = $constraints['maxLength'];

        return $this->stringWitness($this->patterns->search($pattern, $minLength, $maxLength));
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
