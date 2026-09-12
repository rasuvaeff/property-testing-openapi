<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\WireValue;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Finds the parameter (or body) each misuse category invalidates.
 *
 * Only {@see missingRequired()} asks whether a parameter is required: an
 * optional one cannot be omitted into invalidity. Every other category writes
 * an invalid value over a parameter the case then carries, and a present
 * optional parameter is validated against its schema exactly as a required
 * one is (#93) — so those categories consider every parameter, in declaration
 * order.
 *
 * Each method answers with *every* eligible target rather than the first, and
 * the arbitrary draws among them. Returning the first made the choice
 * deterministic and position-based: an operation declaring `per_page` and
 * `page`, both bounded, had all of its `boundary` cases land on `per_page`,
 * and swapping the two entries in the document swapped which bound was ever
 * checked (#99). The list is in declaration order, so shrinking converges on
 * the first eligible target.
 *
 * A witness is offered, not asserted: every candidate is put to
 * {@see WitnessCheck}, which keeps it only if the schema rejects it and the
 * schema without the category's keywords accepts it. The witness therefore
 * earns the category it is recorded under instead of being assumed to (#102).
 *
 * @internal
 *
 * @psalm-import-type Kind from JsonBodyWitness
 * @psalm-type ValueTarget = array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}
 */
final readonly class ParameterTargets
{
    public function __construct(
        private SchemaProbe $probe = new SchemaProbe(),
        private PatternWitness $witness = new PatternWitness(),
        private WitnessCheck $check = new WitnessCheck(),
    ) {}

    /**
     * @return non-empty-list<array{location: 'path'|'query'|'header'|'cookie'|'body', name: string}>
     */
    public function missingRequired(Operation $operation): array
    {
        $targets = [];
        foreach ($operation->parameters as $parameter) {
            if ($parameter['required']) {
                $targets[] = ['location' => $parameter['in'], 'name' => $parameter['name']];
            }
        }
        if (($operation->requestBody['required'] ?? false) === true) {
            $targets[] = ['location' => 'body', 'name' => 'body'];
        }
        if ($targets === []) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no required request component to invalidate', $operation->key));
        }

        return $targets;
    }

    /** @return non-empty-list<ValueTarget> */
    public function typeMismatch(Operation $operation): array
    {
        return $this->valueTargets($operation, 'type', 'has no scalar parameter with a constructible type mismatch');
    }

    /** @return non-empty-list<ValueTarget> */
    public function enumMismatch(Operation $operation): array
    {
        return $this->valueTargets($operation, 'enum', 'has no scalar parameter with a constructible enum mismatch');
    }

    /** @return non-empty-list<ValueTarget> */
    public function constMismatch(Operation $operation): array
    {
        return $this->valueTargets($operation, 'const', 'has no scalar parameter with a constructible const mismatch');
    }

    /** @return non-empty-list<ValueTarget> */
    public function boundaryMismatch(Operation $operation): array
    {
        return $this->valueTargets($operation, 'boundary', 'has no numeric parameter with a constructible boundary mismatch');
    }

    /** @return non-empty-list<ValueTarget> */
    public function lengthMismatch(Operation $operation): array
    {
        return $this->valueTargets($operation, 'length', 'has no string parameter with a constructible length mismatch');
    }

    /** @return non-empty-list<ValueTarget> */
    public function patternMismatch(Operation $operation): array
    {
        return $this->valueTargets($operation, 'pattern', 'has no string parameter with a provable pattern counter-witness');
    }

    /** @return non-empty-list<ValueTarget> */
    public function formatMismatch(Operation $operation): array
    {
        return $this->valueTargets($operation, 'format', 'has no string parameter with a constructible format mismatch');
    }

    /**
     * @param Kind $kind
     * @param non-empty-string $missing
     * @return non-empty-list<ValueTarget>
     */
    private function valueTargets(Operation $operation, string $kind, string $missing): array
    {
        $targets = [];
        foreach ($operation->parameters as $parameter) {
            $schema = $parameter['schema'];
            $invalid = $this->check->firstDiscriminating(
                $this->candidates($parameter, $kind),
                $schema,
                $kind,
                $operation->dialect,
                SchemaDirection::Request,
            );
            $wire = $invalid === null ? null : WireValue::of($invalid);
            if ($wire !== null) {
                $targets[] = ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $wire];
            }
        }
        if ($targets === []) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" %s%s', $operation->key, $missing, $this->unsupportedFormats($operation, $kind)));
        }

        return $targets;
    }

    /**
     * Names the declared formats this package holds no witness for, when the
     * `format` category found nothing.
     *
     * A refusal used to read the same whether the schemas declared no format
     * at all or declared one that cannot be disproved here — and those call
     * for opposite responses: the second is a gap in this package, and the
     * right answer to it is an issue rather than an edit to the document
     * (#103).
     *
     * @param Kind $kind
     */
    private function unsupportedFormats(Operation $operation, string $kind): string
    {
        if ($kind !== 'format') {
            return '';
        }
        $unsupported = [];
        foreach ($operation->parameters as $parameter) {
            $format = $this->probe->unsupportedFormat($parameter['schema']);
            if ($format !== null) {
                $unsupported[$format] = true;
            }
        }
        if ($unsupported === []) {
            return '';
        }

        return sprintf('; no witness is held for format %s', implode(', ', array_map(
            static fn(string $format): string => sprintf('"%s"', $format),
            array_keys($unsupported),
        )));
    }

    /**
     * The values worth offering for one parameter and category, in preference
     * order: the one the category has always produced first, then the typed
     * alternatives that keep `enum` and `const` constructible where it cannot
     * discriminate.
     *
     * @param array{name: non-empty-string, in: 'path'|'query'|'header'|'cookie', required: bool, style: string, explode: bool, allowReserved: bool, schema: array<string, mixed>, specPointer: non-empty-string, example?: mixed, examples?: array<string, mixed>} $parameter
     * @param Kind $kind
     * @return list<int|float|bool|string>
     */
    private function candidates(array $parameter, string $kind): array
    {
        $schema = $parameter['schema'];

        return match ($kind) {
            'type' => $this->typeCandidates($schema),
            'enum' => $this->finiteCandidates($schema, 'enum'),
            'const' => $this->finiteCandidates($schema, 'const'),
            'boundary' => $this->probe->outOfRangeValues($schema),
            'length' => $this->probe->outOfLengthValues($schema),
            'format' => $this->probe->formatWitnesses($schema),
            'pattern' => $this->patternCandidates($schema, $parameter['in'] === 'path'),
        };
    }

    /**
     * A union admits every type it lists, so a witness built for one member
     * stays valid under the others: `["string", "null"]` accepts the string
     * `"not-null"`. Only a single declared type can be contradicted.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private function typeCandidates(array $schema): array
    {
        $types = $this->probe->declaredTypes($schema);
        if (count($types) !== 1) {
            return [];
        }
        $witness = match (reset($types)) {
            'integer' => 'not-an-integer',
            'number' => 'not-a-number',
            'boolean' => 'not-a-boolean',
            'null' => 'not-null',
            default => null,
        };

        return $witness === null ? [] : [$witness];
    }

    /**
     * @param array<string, mixed> $schema
     * @param 'enum'|'const' $keyword
     * @return list<int|float|bool|string>
     */
    private function finiteCandidates(array $schema, string $keyword): array
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
            $marker = '__openapi_invalid_enum__';
        } else {
            if (!is_scalar($schema['const'])) {
                return [];
            }
            $forbidden = [$schema['const']];
            $marker = is_bool($schema['const']) ? 'not-a-const-boolean' : (is_string($schema['const']) ? '__openapi_invalid_const__' : 'not-a-const-number');
        }
        while (in_array($marker, $forbidden, strict: true)) {
            $marker .= '_';
        }

        return [$marker, ...$this->probe->alternatives($schema, $forbidden)];
    }

    /**
     * An empty witness is excluded for a path parameter: it would materialize
     * as an empty template segment and change route matching instead of
     * failing the pattern.
     *
     * @param array<string, mixed> $schema
     * @return list<string>
     */
    private function patternCandidates(array $schema, bool $inPath): array
    {
        $constraints = $this->probe->patternConstraints($schema);
        if ($constraints === null) {
            return [];
        }
        $minLength = $inPath ? max($constraints['minLength'], 1) : $constraints['minLength'];
        $witness = $this->witness->search($constraints['pattern'], $minLength, $constraints['maxLength']);

        return $witness === null ? [] : [$witness];
    }
}
