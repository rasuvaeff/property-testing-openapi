<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Compile;

use Rasuvaeff\PropertyTesting\OpenApi\Internal\SchemaShape;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Shared schema-shape probes for the compiler sections.
 *
 * @internal
 *
 * @psalm-import-type Schema from SchemaShape
 * @psalm-import-type Subschema from SchemaShape
 */
final readonly class SchemaFacts
{
    /**
     * @return list<string>|null
     */
    public function types(mixed $value): ?array
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

    /**
     * @param array<string, mixed> $schema
     * @return int<0, max>
     */
    public function nonNegativeInt(array $schema, string $keyword, int $default): int
    {
        $value = $schema[$keyword] ?? $default;
        if (!is_int($value) || $value < 0) {
            throw UnsupportedGeneration::forSchema(sprintf('%s must be a non-negative integer', $keyword));
        }

        return $value;
    }

    /**
     * The upper bound a generator draws up to: the declared maximum, capped
     * by the generation budget — unless the declared minimum is above the
     * budget, in which case the minimum is the budget. `minLength: 100` is a
     * fact of the document, not a schema the generator cannot reach (#169);
     * under the budget the cap is what it always was, so nothing that
     * generated before draws differently.
     *
     * @param Schema $schema
     * @param int<0, max> $minimum
     * @param int<0, max> $budget
     * @return int<0, max>
     */
    public function budgeted(array $schema, string $keyword, int $minimum, int $budget): int
    {
        $ceiling = max($budget, $minimum);

        return min($this->nonNegativeInt($schema, $keyword, $ceiling), $ceiling);
    }

    /**
     * The OAS 3.1 (JSON Schema 2020-12) spelling of an exclusive bound — a
     * number of its own, `exclusiveMinimum: 0` — in the OAS 3.0 spelling the
     * compiler reads: the bound in `minimum`/`maximum` and a `true` flag
     * beside it (#166). Where both a numeric exclusive bound and an inclusive
     * one are declared, the tighter one is kept, as the validator applies
     * both. An integer bound past a fraction is already exclusive — `> 0.5`
     * is `>= 1` — so it is kept inclusive and rounded inward by
     * {@see integerBound()}. A schema without a numeric exclusive bound comes
     * back unchanged.
     *
     * @param Schema $schema
     * @return Schema
     */
    public static function exclusiveBounds(array $schema, bool $integer): array
    {
        foreach (['exclusiveMinimum' => 'minimum', 'exclusiveMaximum' => 'maximum'] as $exclusive => $inclusive) {
            $bound = $schema[$exclusive] ?? null;
            if (!is_int($bound) && !is_float($bound)) {
                continue;
            }
            $declared = $schema[$inclusive] ?? null;
            $tighter = (!is_int($declared) && !is_float($declared))
                || ($inclusive === 'minimum' ? $bound >= $declared : $bound <= $declared);
            unset($schema[$exclusive]);
            if (!$tighter) {
                continue;
            }
            $schema[$inclusive] = $bound;
            if (!$integer || floor((float) $bound) === (float) $bound) {
                $schema[$exclusive] = true;
            }
        }

        return $schema;
    }

    /**
     * A numeric bound of an integer schema; a fractional bound rounds inward
     * (`minimum: 0.5` admits 1, `maximum: 2.5` admits 2).
     *
     * @param array<string, mixed> $schema
     */
    public function integerBound(array $schema, string $keyword, int $default): int
    {
        $value = $schema[$keyword] ?? $default;
        if (is_float($value) && is_finite($value) && abs($value) < PHP_INT_MAX) {
            $value = $keyword === 'minimum' ? (int) ceil($value) : (int) floor($value);
        }
        if (!is_int($value)) {
            throw UnsupportedGeneration::forSchema(sprintf('%s for an integer must be numeric', $keyword));
        }

        return $value;
    }

    /** @param array<string, mixed> $schema */
    public function numberBound(array $schema, string $keyword, float $default): float
    {
        $value = $schema[$keyword] ?? $default;
        if (!is_int($value) && !is_float($value)) {
            throw UnsupportedGeneration::forSchema(sprintf('%s for a number must be numeric', $keyword));
        }

        return (float) $value;
    }

    /**
     * @param Schema $schema
     * @return Subschema
     */
    public function additionalPropertiesSchema(array $schema): bool|array
    {
        if (!array_key_exists('additionalProperties', $schema)) {
            return true;
        }

        return $this->booleanOrSchema($schema['additionalProperties']);
    }

    /**
     * A JSON object as PHP decodes it.
     *
     * The key type says `string` because that is what every caller needs and
     * what psalm can work with, but PHP normalizes a numeric-string member
     * name to an integer key — `{"2020": …}` really does arrive as `int 2020`.
     * Anything that puts such a name back on the wire casts it there, where
     * the value has passed through a generator and the cast is not redundant.
     *
     * @return Schema
     */
    public function schemaObject(mixed $value, string $error): array
    {
        if (!is_array($value) || $value !== [] && array_is_list($value)) {
            throw UnsupportedGeneration::forSchema($error);
        }

        /** @var Schema $value */
        return $value;
    }

    /** @return Subschema */
    private function booleanOrSchema(mixed $value): bool|array
    {
        if (is_bool($value)) {
            return $value;
        }

        return $this->schemaObject($value, 'additionalProperties must be a boolean or schema object');
    }
}
