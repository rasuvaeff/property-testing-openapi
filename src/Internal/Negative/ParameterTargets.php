<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Finds the parameter (or body) each misuse category invalidates.
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
     * @return array{location: 'path'|'query'|'header'|'cookie'|'body', name: string}
     */
    public function missingRequired(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if ($parameter['required']) {
                return ['location' => $parameter['in'], 'name' => $parameter['name']];
            }
        }
        if (($operation->requestBody['required'] ?? false) === true) {
            return ['location' => 'body', 'name' => 'body'];
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required request component to invalidate', $operation->key));
    }

    /**
     * @return array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}
     */
    public function typeMismatch(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required']) {
                continue;
            }
            $types = $this->probe->declaredTypes($parameter['schema']);
            if (in_array('integer', $types, strict: true)) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => 'not-an-integer'];
            }
            if (in_array('number', $types, strict: true)) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => 'not-a-number'];
            }
            if (in_array('boolean', $types, strict: true)) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => 'not-a-boolean'];
            }
            if (in_array('null', $types, strict: true)) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => 'not-null'];
            }
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required scalar parameter with a constructible type mismatch', $operation->key));
    }

    /**
     * @return array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}
     */
    public function enumMismatch(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required'] || !array_key_exists('enum', $parameter['schema'])) {
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

            return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required scalar parameter with a constructible enum mismatch', $operation->key));
    }

    /** @return array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string} */
    public function constMismatch(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required'] || !array_key_exists('const', $parameter['schema']) || !is_scalar($parameter['schema']['const'])) {
                continue;
            }
            $invalid = '__openapi_invalid_const__';
            if (is_int($parameter['schema']['const']) || is_float($parameter['schema']['const'])) {
                $invalid = 'not-a-const-number';
            } elseif (is_bool($parameter['schema']['const'])) {
                $invalid = 'not-a-const-boolean';
            }

            return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required scalar parameter with a constructible const mismatch', $operation->key));
    }

    /** @return array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string} */
    public function boundaryMismatch(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required']) {
                continue;
            }
            $invalid = $this->probe->outOfRangeValue($parameter['schema']);
            if ($invalid !== null) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
            }
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required numeric parameter with a constructible boundary mismatch', $operation->key));
    }

    /** @return array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string} */
    public function lengthMismatch(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required']) {
                continue;
            }
            $invalid = $this->probe->outOfLengthValue($parameter['schema']);
            if ($invalid !== null) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
            }
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required string parameter with a constructible length mismatch', $operation->key));
    }

    /**
     * An empty witness is excluded for a path parameter: it would materialize
     * as an empty template segment and change route matching instead of
     * failing the pattern.
     *
     * @return array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}
     */
    public function patternMismatch(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required']) {
                continue;
            }
            $constraints = $this->probe->patternConstraints($parameter['schema']);
            if ($constraints === null) {
                continue;
            }
            $minLength = $parameter['in'] === 'path' ? max($constraints['minLength'], 1) : $constraints['minLength'];
            $invalid = $this->witness->search($constraints['pattern'], $minLength, $constraints['maxLength']);
            if ($invalid !== null) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
            }
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required string parameter with a provable pattern counter-witness', $operation->key));
    }

    /** @return array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string} */
    public function formatMismatch(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required']) {
                continue;
            }
            $invalid = $this->probe->formatWitness($parameter['schema']);
            if ($invalid !== null) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
            }
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required string parameter with a constructible format mismatch', $operation->key));
    }
}
