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
     * How long an alternative value may get before the search gives up. A
     * schema whose every short value is enumerated is a schema no short
     * witness contradicts, and a long one says nothing clearer.
     */
    private const int MAX_ALTERNATIVE_LENGTH = 24;

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

    /**
     * The values just outside each declared bound, typed as the schema
     * declares them.
     *
     * Both sides are offered rather than only the first that exists: a bound
     * the schema shares with another keyword may yield no discriminating
     * witness while the other does.
     *
     * @param array<string, mixed> $schema
     * @return list<int|float>
     */
    public function outOfRangeValues(array $schema): array
    {
        $types = $this->declaredTypes($schema);
        $integer = in_array('integer', $types, strict: true);
        if (!$integer && !in_array('number', $types, strict: true)) {
            return [];
        }
        $candidates = [];

        $minimum = $this->numericBound($schema['minimum'] ?? null);
        if ($minimum !== null) {
            if (($schema['exclusiveMinimum'] ?? false) === true) {
                $candidates[] = $minimum;
            } else {
                $below = is_int($minimum) ? ($minimum > PHP_INT_MIN ? $minimum - 1 : null) : $minimum - 1.0;
                if ($below !== null && $below < $minimum) {
                    $candidates[] = $below;
                }
            }
        }

        $maximum = $this->numericBound($schema['maximum'] ?? null);
        if ($maximum !== null) {
            if (($schema['exclusiveMaximum'] ?? false) === true) {
                $candidates[] = $maximum;
            } else {
                $above = is_int($maximum) ? ($maximum < PHP_INT_MAX ? $maximum + 1 : null) : $maximum + 1.0;
                if ($above !== null && $above > $maximum) {
                    $candidates[] = $above;
                }
            }
        }

        $typed = [];
        foreach ($candidates as $candidate) {
            // An integer-typed value must stay an integer on the wire, so a
            // float bound yields no candidate for one.
            if ($integer && !is_int($candidate)) {
                continue;
            }
            $typed[] = $integer ? $candidate : (float) $candidate;
        }

        return $typed;
    }

    /**
     * Strings just outside each declared length bound.
     *
     * A string below `minLength: 1` would materialize as an empty component
     * and change route matching rather than fail the bound, so it is not
     * offered. No keyword is excluded: whether such a string trips something
     * other than the length is decided by {@see WitnessCheck}, not guessed
     * from the schema's other keywords (#102).
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    public function outOfLengthValues(array $schema): array
    {
        if (!in_array('string', $this->declaredTypes($schema), strict: true)) {
            return [];
        }
        $candidates = [];

        $minLength = $this->intBound($schema['minLength'] ?? null);
        if ($minLength !== null && $minLength >= 2 && $minLength <= self::MAX_CONSTRUCTED_LENGTH) {
            $candidates[] = str_repeat('a', $minLength - 1);
        }

        $maxLength = $this->intBound($schema['maxLength'] ?? null);
        if ($maxLength !== null && $maxLength >= 0 && $maxLength < self::MAX_CONSTRUCTED_LENGTH) {
            $candidates[] = str_repeat('a', $maxLength + 1);
        }

        return $candidates;
    }

    /**
     * The fixed wire value that contradicts the declared `format`, if this
     * probe knows one.
     *
     * No keyword is excluded. The witnesses are short fixed strings — the
     * longest, `'not-a-date-time'`, is fifteen characters — so under a
     * realistic `maxLength` one provably violates the format alone, and the
     * old refusal on `minLength`/`maxLength` cost every `format: email` with
     * a length bound its case (#100). Whether the witness trips anything else
     * is decided by {@see WitnessCheck}.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    public function formatWitnesses(array $schema): array
    {
        if (!in_array('string', $this->declaredTypes($schema), strict: true)) {
            return [];
        }
        if (!isset($schema['format']) || !is_string($schema['format'])) {
            return [];
        }
        $witness = self::FORMAT_WITNESSES[$schema['format']] ?? null;

        return $witness === null ? [] : [$witness];
    }

    /**
     * Values of the schema's declared type that are none of the forbidden
     * ones, shortest first, for the categories that contradict a finite set
     * (`enum`, `const`).
     *
     * A fixed marker string cannot contradict an `enum` of integers without
     * also contradicting the type, and one longer than `maxLength` cannot
     * contradict a string enum without also breaking the length. Offering
     * typed alternatives is what keeps those two categories constructible
     * once the witness has to earn its name (#102).
     *
     * @param array<string, mixed> $schema
     * @param list<mixed> $forbidden
     * @return list<int|float|bool|string>
     */
    public function alternatives(array $schema, array $forbidden): array
    {
        $types = $this->declaredTypes($schema);
        $candidates = [];

        if (in_array('integer', $types, strict: true) || in_array('number', $types, strict: true)) {
            $integer = in_array('integer', $types, strict: true);
            foreach ([0, 1, -1, 2, 7, 42, -99] as $number) {
                $candidates[] = $integer ? $number : (float) $number;
            }
        } elseif (in_array('boolean', $types, strict: true)) {
            $candidates = [false, true];
        } else {
            $minLength = max($this->intBound($schema['minLength'] ?? null) ?? 0, 0);
            $maxLength = $this->intBound($schema['maxLength'] ?? null) ?? self::MAX_ALTERNATIVE_LENGTH;
            if ($maxLength === 0) {
                $candidates[] = '';
            }
            for ($length = max($minLength, 1); $length <= min($maxLength, self::MAX_ALTERNATIVE_LENGTH); ++$length) {
                $candidates[] = str_repeat('a', $length);
                $candidates[] = str_repeat('z', $length);
            }
        }

        return array_values(array_filter($candidates, static fn(mixed $candidate): bool => !in_array($candidate, $forbidden, strict: false)));
    }

    /**
     * The pattern to contradict and the length window a witness for it must
     * stay inside, so the witness fails the pattern rather than a length
     * bound. Whether it also trips `enum`, `const` or `format` is decided by
     * {@see WitnessCheck} rather than refused here (#102).
     *
     * @param array<string, mixed> $schema
     * @return array{pattern: non-empty-string, minLength: int<0, max>, maxLength: int<0, max>}|null
     */
    public function patternConstraints(array $schema): ?array
    {
        if (!in_array('string', $this->declaredTypes($schema), strict: true)) {
            return null;
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
}
