<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

/**
 * Pure schema probes shared by the negative target finders.
 *
 * @internal
 */
final readonly class SchemaProbe
{
    public const int MAX_CONSTRUCTED_LENGTH = 4096;

    /**
     * Fixed wire values that provably violate their format under the core
     * validator. `url` is absent deliberately: the backend accepts any string
     * for it, so a format mismatch cannot be promised.
     */
    private const array FORMAT_WITNESSES = [
        'uuid' => 'not-a-uuid',
        'email' => 'not-an-email',
        'ipv4' => 'not-an-ipv4',
        'uri' => ':',
        'uri-reference' => '%',
        'date' => 'not-a-date',
        'date-time' => 'not-a-date-time',
    ];

    /**
     * @param array<string, mixed> $schema
     * @return array<array-key, mixed>
     */
    public function declaredTypes(array $schema): array
    {
        if (!array_key_exists('type', $schema)) {
            return [];
        }
        if (is_array($schema['type'])) {
            return $schema['type'];
        }

        return [$schema['type']];
    }

    /** @param array<array-key, mixed> $enum */
    public function isScalarEnum(array $enum): bool
    {
        foreach ($enum as $value) {
            if ($value !== null && !is_scalar($value)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $schema */
    public function outOfRangeValue(array $schema): ?string
    {
        $types = $this->declaredTypes($schema);
        $integer = in_array('integer', $types, strict: true);
        if (!$integer && !in_array('number', $types, strict: true)) {
            return null;
        }

        $minimum = $this->numericBound($schema['minimum'] ?? null);
        if ($minimum !== null) {
            if (($schema['exclusiveMinimum'] ?? false) === true) {
                return $this->numericWire($minimum, $integer);
            }
            $below = is_int($minimum) ? ($minimum > PHP_INT_MIN ? $minimum - 1 : null) : $minimum - 1.0;
            if ($below !== null && $below < $minimum) {
                return $this->numericWire($below, $integer);
            }
        }

        $maximum = $this->numericBound($schema['maximum'] ?? null);
        if ($maximum !== null) {
            if (($schema['exclusiveMaximum'] ?? false) === true) {
                return $this->numericWire($maximum, $integer);
            }
            $above = is_int($maximum) ? ($maximum < PHP_INT_MAX ? $maximum + 1 : null) : $maximum + 1.0;
            if ($above !== null && $above > $maximum) {
                return $this->numericWire($above, $integer);
            }
        }

        return null;
    }

    /**
     * A schema with enum, const, pattern, or format cannot promise a pure
     * length mismatch, and a string below `minLength: 1` would materialize as
     * an empty component, so those parameters are skipped.
     *
     * @param array<string, mixed> $schema
     */
    public function outOfLengthValue(array $schema): ?string
    {
        if (!in_array('string', $this->declaredTypes($schema), strict: true)) {
            return null;
        }
        foreach (['enum', 'const', 'pattern', 'format'] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return null;
            }
        }

        $minLength = $this->intBound($schema['minLength'] ?? null);
        if ($minLength !== null && $minLength >= 2 && $minLength <= self::MAX_CONSTRUCTED_LENGTH) {
            return str_repeat('a', $minLength - 1);
        }

        $maxLength = $this->intBound($schema['maxLength'] ?? null);
        if ($maxLength !== null && $maxLength >= 0 && $maxLength < self::MAX_CONSTRUCTED_LENGTH) {
            return str_repeat('a', $maxLength + 1);
        }

        return null;
    }

    /**
     * Other constraining keywords are excluded so the witness cannot trip an
     * unrelated assertion instead of the format.
     *
     * @param array<string, mixed> $schema
     */
    public function formatWitness(array $schema): ?string
    {
        if (!in_array('string', $this->declaredTypes($schema), strict: true)) {
            return null;
        }
        foreach (['enum', 'const', 'pattern', 'minLength', 'maxLength'] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return null;
            }
        }
        if (!isset($schema['format']) || !is_string($schema['format'])) {
            return null;
        }

        return self::FORMAT_WITNESSES[$schema['format']] ?? null;
    }

    /**
     * A pattern witness can be promised only when the pattern is the sole
     * content assertion: enum, const, and format could reject the witness for
     * an unrelated reason. The returned length window keeps the witness from
     * tripping `minLength`/`maxLength` instead of the pattern.
     *
     * @param array<string, mixed> $schema
     * @return array{pattern: non-empty-string, minLength: int<0, max>, maxLength: int<0, max>}|null
     */
    public function patternConstraints(array $schema): ?array
    {
        if (!in_array('string', $this->declaredTypes($schema), strict: true)) {
            return null;
        }
        foreach (['enum', 'const', 'format'] as $keyword) {
            if (array_key_exists($keyword, $schema)) {
                return null;
            }
        }
        $pattern = $schema['pattern'] ?? null;
        if (!is_string($pattern) || $pattern === '') {
            return null;
        }
        $minLength = $this->intBound($schema['minLength'] ?? null) ?? 0;
        $maxLength = $this->intBound($schema['maxLength'] ?? null) ?? self::MAX_CONSTRUCTED_LENGTH;
        if ($minLength < 0 || $maxLength < 0 || $maxLength < $minLength || $minLength > self::MAX_CONSTRUCTED_LENGTH) {
            return null;
        }
        if ($maxLength > self::MAX_CONSTRUCTED_LENGTH) {
            $maxLength = self::MAX_CONSTRUCTED_LENGTH;
        }

        return ['pattern' => $pattern, 'minLength' => $minLength, 'maxLength' => $maxLength];
    }

    private function intBound(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    private function numericBound(mixed $value): int|float|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        return null;
    }

    /**
     * An integer-typed parameter must stay an integer on the wire, so a float
     * bound cannot produce a pure boundary mismatch for it.
     */
    private function numericWire(int|float $value, bool $integer): ?string
    {
        if ($integer && !is_int($value)) {
            return null;
        }

        return (string) $value;
    }
}
