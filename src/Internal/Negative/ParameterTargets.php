<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Finds the parameter (or body) each misuse category invalidates.
 *
 * Only {@see missingRequired()} asks whether a parameter is required: an
 * optional one cannot be omitted into invalidity. Every other category
 * writes an invalid value over a parameter the case then carries, and a
 * present optional parameter is validated against its schema exactly as a
 * required one is (#93) — so those categories consider every parameter, in
 * declaration order.
 *
 * Each method answers with *every* eligible target rather than the first, and
 * the arbitrary draws among them. Returning the first made the choice
 * deterministic and position-based: an operation declaring `per_page` and
 * `page`, both bounded, had all of its `boundary` cases land on `per_page`,
 * and swapping the two entries in the document swapped which bound was ever
 * checked. Drawing more did not help, because it re-drew the same target
 * (#99). The list is in declaration order, so shrinking converges on the
 * first eligible target and the minimal counterexample is what it used to
 * be.
 *
 * @internal
 */
final readonly class ParameterTargets
{
    public function __construct(
        private SchemaProbe $probe = new SchemaProbe(),
        private PatternWitness $witness = new PatternWitness(),
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

    /**
     * @return non-empty-list<array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}>
     */
    public function typeMismatch(Operation $operation): array
    {
        $targets = [];
        foreach ($operation->parameters as $parameter) {
            // A union admits every type it lists, so a witness built for one
            // member stays valid under the others: ["string", "null"] accepts
            // the string "not-null". Only a single declared type can be
            // contradicted, which is also what ResponseTargets requires.
            $types = $this->probe->declaredTypes($parameter['schema']);
            if (count($types) !== 1) {
                continue;
            }
            $invalid = match (reset($types)) {
                'integer' => 'not-an-integer',
                'number' => 'not-a-number',
                'boolean' => 'not-a-boolean',
                'null' => 'not-null',
                default => null,
            };
            if ($invalid === null) {
                continue;
            }

            $targets[] = ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
        }
        if ($targets === []) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no scalar parameter with a constructible type mismatch', $operation->key));
        }

        return $targets;
    }

    /**
     * @return non-empty-list<array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}>
     */
    public function enumMismatch(Operation $operation): array
    {
        $targets = [];
        foreach ($operation->parameters as $parameter) {
            if (!array_key_exists('enum', $parameter['schema'])) {
                continue;
            }
            $enum = $parameter['schema']['enum'];
            if (!is_array($enum) || $enum === [] || !$this->probe->isScalarEnum($enum)) {
                continue;
            }
            $invalid = '__openapi_invalid_enum__';
            while (in_array($invalid, $enum, strict: true)) {
                $invalid .= '_';
            }

            $targets[] = ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
        }
        if ($targets === []) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no scalar parameter with a constructible enum mismatch', $operation->key));
        }

        return $targets;
    }

    /** @return non-empty-list<array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}> */
    public function constMismatch(Operation $operation): array
    {
        $targets = [];
        foreach ($operation->parameters as $parameter) {
            if (!array_key_exists('const', $parameter['schema']) || !is_scalar($parameter['schema']['const'])) {
                continue;
            }
            $invalid = '__openapi_invalid_const__';
            if (is_int($parameter['schema']['const']) || is_float($parameter['schema']['const'])) {
                $invalid = 'not-a-const-number';
            } elseif (is_bool($parameter['schema']['const'])) {
                $invalid = 'not-a-const-boolean';
            }
            // A const whose value is the witness itself would make the
            // "invalid" case valid; enumMismatch already walks away from that
            // collision the same way.
            while ($parameter['schema']['const'] === $invalid) {
                $invalid .= '_';
            }

            $targets[] = ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
        }
        if ($targets === []) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no scalar parameter with a constructible const mismatch', $operation->key));
        }

        return $targets;
    }

    /** @return non-empty-list<array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}> */
    public function boundaryMismatch(Operation $operation): array
    {
        $targets = [];
        foreach ($operation->parameters as $parameter) {
            $invalid = $this->probe->outOfRangeValue($parameter['schema']);
            if ($invalid !== null) {
                $targets[] = ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
            }
        }
        if ($targets === []) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no numeric parameter with a constructible boundary mismatch', $operation->key));
        }

        return $targets;
    }

    /** @return non-empty-list<array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}> */
    public function lengthMismatch(Operation $operation): array
    {
        $targets = [];
        foreach ($operation->parameters as $parameter) {
            $invalid = $this->probe->outOfLengthValue($parameter['schema']);
            if ($invalid !== null) {
                $targets[] = ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
            }
        }
        if ($targets === []) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no string parameter with a constructible length mismatch', $operation->key));
        }

        return $targets;
    }

    /**
     * An empty witness is excluded for a path parameter: it would materialize
     * as an empty template segment and change route matching instead of
     * failing the pattern.
     *
     * @return non-empty-list<array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}>
     */
    public function patternMismatch(Operation $operation): array
    {
        $targets = [];
        foreach ($operation->parameters as $parameter) {
            $constraints = $this->probe->patternConstraints($parameter['schema']);
            if ($constraints === null) {
                continue;
            }
            $minLength = $parameter['in'] === 'path' ? max($constraints['minLength'], 1) : $constraints['minLength'];
            $invalid = $this->witness->search($constraints['pattern'], $minLength, $constraints['maxLength']);
            if ($invalid !== null) {
                $targets[] = ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
            }
        }
        if ($targets === []) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no string parameter with a provable pattern counter-witness', $operation->key));
        }

        return $targets;
    }

    /** @return non-empty-list<array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}> */
    public function formatMismatch(Operation $operation): array
    {
        $targets = [];
        foreach ($operation->parameters as $parameter) {
            $invalid = $this->probe->formatWitness($parameter['schema']);
            if ($invalid !== null) {
                $targets[] = ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
            }
        }
        if ($targets === []) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no string parameter with a constructible format mismatch', $operation->key));
        }

        return $targets;
    }
}
