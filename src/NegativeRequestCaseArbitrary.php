<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * Produces invalid request cases through constructive, corpus-safe mutations.
 *
 * The generated value remains corpus-safe; `misuse` identifies the deliberate
 * invalidation and is never interpreted as a secret or a PSR-7 object.
 *
 * @api
 */
final readonly class NegativeRequestCaseArbitrary
{
    private const int MAX_CONSTRUCTED_LENGTH = 4096;

    public function __construct(
        private RequestCaseArbitrary $valid = new RequestCaseArbitrary(),
    ) {}

    /**
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'missing-required'|'type'|'enum'|'const'|'boundary'|'length', location: 'path'|'query'|'header'|'cookie'|'body', name: string},
     * }>
     */
    public function forOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->target($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'missing-required', location: 'path'|'query'|'header'|'cookie'|'body', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'body') {
                $case['body'] = null;
            } elseif ($target['location'] === 'path') {
                unset($case['path'][$target['name']]);
            } elseif ($target['location'] === 'query') {
                unset($case['query'][$target['name']]);
            } elseif ($target['location'] === 'header') {
                unset($case['headers'][$target['name']]);
            } else {
                unset($case['cookies'][$target['name']]);
            }
            $case['misuse'] = [
                'kind' => 'missing-required',
                'location' => $target['location'],
                'name' => $target['name'],
            ];

            return $case;
        });
    }

    /**
     * Replaces one required scalar parameter with a wire value that cannot
     * satisfy its integer, number, boolean, or null schema type.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'type', location: 'path'|'query'|'header'|'cookie', name: string},
     * }>
     */
    public function typeMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->typeTarget($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'type', location: 'path'|'query'|'header'|'cookie', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'path') {
                $case['path'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'query') {
                $case['query'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'header') {
                $case['headers'][$target['name']] = $target['invalid'];
            } else {
                $case['cookies'][$target['name']] = $target['invalid'];
            }
            $case['misuse'] = [
                'kind' => 'type',
                'location' => $target['location'],
                'name' => $target['name'],
            ];

            return $case;
        });
    }

    /**
     * Replaces one required scalar parameter with a value absent from its
     * finite enum.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'enum', location: 'path'|'query'|'header'|'cookie', name: string},
     * }>
     */
    public function enumMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->enumTarget($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'enum', location: 'path'|'query'|'header'|'cookie', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'path') {
                $case['path'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'query') {
                $case['query'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'header') {
                $case['headers'][$target['name']] = $target['invalid'];
            } else {
                $case['cookies'][$target['name']] = $target['invalid'];
            }
            $case['misuse'] = [
                'kind' => 'enum',
                'location' => $target['location'],
                'name' => $target['name'],
            ];

            return $case;
        });
    }

    /**
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'const', location: 'path'|'query'|'header'|'cookie', name: string},
     * }>
     */
    public function constMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->constTarget($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'const', location: 'path'|'query'|'header'|'cookie', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'path') {
                $case['path'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'query') {
                $case['query'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'header') {
                $case['headers'][$target['name']] = $target['invalid'];
            } else {
                $case['cookies'][$target['name']] = $target['invalid'];
            }
            $case['misuse'] = ['kind' => 'const', 'location' => $target['location'], 'name' => $target['name']];

            return $case;
        });
    }

    /**
     * Replaces one required numeric parameter with a wire value just outside
     * its `minimum`/`maximum` bound, honouring boolean exclusive bounds.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'boundary', location: 'path'|'query'|'header'|'cookie', name: string},
     * }>
     */
    public function boundaryMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->boundaryTarget($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'boundary', location: 'path'|'query'|'header'|'cookie', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'path') {
                $case['path'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'query') {
                $case['query'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'header') {
                $case['headers'][$target['name']] = $target['invalid'];
            } else {
                $case['cookies'][$target['name']] = $target['invalid'];
            }
            $case['misuse'] = ['kind' => 'boundary', 'location' => $target['location'], 'name' => $target['name']];

            return $case;
        });
    }

    /**
     * @return array{location: 'path'|'query'|'header'|'cookie'|'body', name: string}
     */
    private function target(Operation $operation): array
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
    private function typeTarget(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required']) {
                continue;
            }
            $types = $this->declaredTypes($parameter['schema']);
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
    private function enumTarget(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required'] || !array_key_exists('enum', $parameter['schema'])) {
                continue;
            }
            $enum = $parameter['schema']['enum'];
            if (!is_array($enum) || $enum === [] || !$this->isScalarEnum($enum)) {
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

    /** @param array<array-key, mixed> $enum */
    private function isScalarEnum(array $enum): bool
    {
        foreach ($enum as $value) {
            if ($value !== null && !is_scalar($value)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string} */
    private function constTarget(Operation $operation): array
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

    /**
     * Replaces one required string parameter with a wire value whose length
     * falls just outside its `minLength`/`maxLength` bound.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'length', location: 'path'|'query'|'header'|'cookie', name: string},
     * }>
     */
    public function lengthMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->lengthTarget($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'length', location: 'path'|'query'|'header'|'cookie', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'path') {
                $case['path'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'query') {
                $case['query'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'header') {
                $case['headers'][$target['name']] = $target['invalid'];
            } else {
                $case['cookies'][$target['name']] = $target['invalid'];
            }
            $case['misuse'] = ['kind' => 'length', 'location' => $target['location'], 'name' => $target['name']];

            return $case;
        });
    }

    /** @return array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string} */
    private function boundaryTarget(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required']) {
                continue;
            }
            $invalid = $this->outOfRangeValue($parameter['schema']);
            if ($invalid !== null) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
            }
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required numeric parameter with a constructible boundary mismatch', $operation->key));
    }

    /** @param array<string, mixed> $schema */
    private function outOfRangeValue(array $schema): ?string
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

    /** @return array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string} */
    private function lengthTarget(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required']) {
                continue;
            }
            $invalid = $this->outOfLengthValue($parameter['schema']);
            if ($invalid !== null) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => $invalid];
            }
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required string parameter with a constructible length mismatch', $operation->key));
    }

    /**
     * A schema with enum, const, pattern, or format cannot promise a pure
     * length mismatch, and a string below `minLength: 1` would materialize as
     * an empty component, so those parameters are skipped.
     *
     * @param array<string, mixed> $schema
     */
    private function outOfLengthValue(array $schema): ?string
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
     * @param array<string, mixed> $schema
     * @return array<array-key, mixed>
     */
    private function declaredTypes(array $schema): array
    {
        if (!array_key_exists('type', $schema)) {
            return [];
        }
        if (is_array($schema['type'])) {
            return $schema['type'];
        }

        return [$schema['type']];
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
