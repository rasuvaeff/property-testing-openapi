<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * Produces valid, corpus-safe request cases for one compiled operation.
 *
 * @api
 */
final readonly class RequestCaseArbitrary
{
    public function __construct(
        private SchemaArbitraryCompiler $schemas = new SchemaArbitraryCompiler(),
    ) {}

    /**
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: null,
     * }>
     */
    public function forOperation(Operation $operation): ArbitraryInterface
    {
        return Gen::map(Gen::record([
            'path' => $this->location($operation, 'path'),
            'query' => $this->location($operation, 'query'),
            'headers' => $this->location($operation, 'header'),
            'cookies' => $this->location($operation, 'cookie'),
            'body' => $this->body($operation),
        ]), static fn(array $parts): array => [
            'operationKey' => $operation->key,
            'path' => $parts['path'],
            'query' => $parts['query'],
            'headers' => $parts['headers'],
            'cookies' => $parts['cookies'],
            'body' => $parts['body'],
            'misuse' => null,
        ]);
    }

    /**
     * @param 'path'|'query'|'header'|'cookie' $location
     * @return ArbitraryInterface<array<string, string|list<string>|array<string, string>>>
     */
    private function location(Operation $operation, string $location): ArbitraryInterface
    {
        $shape = [];
        foreach ($operation->parameters as $parameter) {
            if ($parameter['in'] !== $location) {
                continue;
            }
            $value = Gen::map(
                $this->schemas->compile($parameter['schema']),
                fn(mixed $value): string|array => $this->wireValue($value, $parameter['schema']),
            );
            $shape[$parameter['name']] = $parameter['required']
                ? $this->included($value)
                : $this->optional($value);
        }
        if ($shape === []) {
            return Gen::constant([]);
        }

        return Gen::map(Gen::record($shape), fn(array $values): array => $this->includedValues($values));
    }

    private function body(Operation $operation): ArbitraryInterface
    {
        if ($operation->requestBody === []) {
            return Gen::constant(null);
        }
        $content = $operation->requestBody['content'] ?? null;
        if (!is_array($content)) {
            throw new UnsupportedGeneration('Request body content must be an object');
        }
        foreach ($content as $mediaType => $definition) {
            if (!is_string($mediaType) || !is_array($definition) || !$this->isJsonMediaType($mediaType)) {
                continue;
            }
            $schema = $definition['schema'] ?? [];
            if (!is_array($schema) || array_is_list($schema)) {
                throw new UnsupportedGeneration('JSON request body schema must be an object');
            }
            /** @var array<string, mixed> $schema */

            $body = Gen::map($this->schemas->compile($schema), static fn(mixed $value): array => [
                'mediaType' => $mediaType,
                'encoding' => 'json',
                'value' => $value,
            ]);

            if (($operation->requestBody['required'] ?? false) === true) {
                return $body;
            }

            return Gen::map($this->optional($body), fn(array $choice): ?array => $this->bodyChoice($choice));
        }

        throw new UnsupportedGeneration('Request body has no supported JSON media type');
    }

    private function included(ArbitraryInterface $value): ArbitraryInterface
    {
        return Gen::map($value, static fn(mixed $value): array => ['included' => true, 'value' => $value]);
    }

    private function optional(ArbitraryInterface $value): ArbitraryInterface
    {
        return Gen::frequency([
            [1, Gen::constant(['included' => false])],
            [1, $this->included($value)],
        ]);
    }

    /**
     * @param array<array-key, mixed> $values
     * @return array<string, string|list<string>|array<string, string>>
     */
    private function includedValues(array $values): array
    {
        $result = [];
        foreach ($values as $name => $choice) {
            if (!is_string($name) || !is_array($choice) || !array_key_exists('included', $choice) || !is_bool($choice['included'])) {
                throw new \LogicException('Generated parameter choice has an invalid shape');
            }
            if (!$choice['included']) {
                continue;
            }
            if (!array_key_exists('value', $choice)) {
                throw new \LogicException('Included parameter value is missing');
            }
            $result[$name] = $this->parameterValue($choice['value']);
        }

        return $result;
    }

    /** @return string|list<string>|array<string, string> */
    private function parameterValue(mixed $value): string|array
    {
        if (is_string($value)) {
            return $value;
        }
        if (!is_array($value)) {
            throw new \LogicException('Included parameter value has an invalid type');
        }
        if (array_is_list($value)) {
            $list = [];
            foreach ($value as $item) {
                if (!is_string($item)) {
                    throw new \LogicException('Included parameter list has an invalid shape');
                }
                $list[] = $item;
            }

            return $list;
        }
        $object = [];
        foreach (array_keys($value) as $key) {
            if (!is_string($key) || !is_string($value[$key])) {
                throw new \LogicException('Included parameter object has an invalid shape');
            }
            $object[$key] = $value[$key];
        }

        return $object;
    }

    /**
     * @param array<array-key, mixed> $choice
     * @return null|array{mediaType: string, encoding: 'json', value: mixed}
     */
    private function bodyChoice(array $choice): ?array
    {
        $included = $choice['included'] ?? null;
        if (!is_bool($included)) {
            throw new \LogicException('Generated body choice has an invalid shape');
        }
        if (!$included) {
            return null;
        }
        $body = $choice['value'] ?? null;
        if (!is_array($body)) {
            throw new \LogicException('Included request body has an invalid shape');
        }
        $mediaType = $body['mediaType'] ?? null;
        if (!is_string($mediaType) || ($body['encoding'] ?? null) !== 'json' || !array_key_exists('value', $body)) {
            throw new \LogicException('Included request body has an invalid shape');
        }

        return [
            'mediaType' => $mediaType,
            'encoding' => 'json',
            'value' => $body['value'],
        ];
    }

    /** @param array<string, mixed> $schema */
    private function wireValue(mixed $value, array $schema): string|array
    {
        if ($this->isArraySchema($schema)) {
            if (!is_array($value) || !array_is_list($value)) {
                throw new \LogicException('Array schema arbitrary must produce a list');
            }

            return array_map($this->scalar(...), $value);
        }
        if ($this->isObjectSchema($schema)) {
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new \LogicException('Object schema arbitrary must produce an object map');
            }
            /** @var array<array-key, mixed> $value */
            $result = [];
            foreach (array_keys($value) as $key) {
                $result[(string) $key] = $this->scalar($value[$key]);
            }

            return $result;
        }

        return $this->scalar($value);
    }

    private function scalar(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            default => throw new UnsupportedGeneration('Parameter values must be scalar, arrays, or objects with scalar properties'),
        };
    }

    /** @param array<string, mixed> $schema */
    private function isArraySchema(array $schema): bool
    {
        return ($schema['type'] ?? null) === 'array' || array_key_exists('items', $schema);
    }

    /** @param array<string, mixed> $schema */
    private function isObjectSchema(array $schema): bool
    {
        return ($schema['type'] ?? null) === 'object' || array_key_exists('properties', $schema);
    }

    private function isJsonMediaType(string $mediaType): bool
    {
        $mediaType = strtolower(trim(explode(';', $mediaType, 2)[0]));

        return $mediaType === 'application/json' || str_ends_with($mediaType, '+json');
    }
}
