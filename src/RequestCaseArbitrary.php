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
            $shape[$parameter['name']] = Gen::map(
                $this->schemas->compile($parameter['schema']),
                fn(mixed $value): string|array => $this->wireValue($value, $parameter['schema']),
            );
        }
        if ($shape === []) {
            return Gen::constant([]);
        }

        return Gen::record($shape);
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

            return Gen::map($this->schemas->compile($schema), static fn(mixed $value): array => [
                'mediaType' => $mediaType,
                'encoding' => 'json',
                'value' => $value,
            ]);
        }

        throw new UnsupportedGeneration('Request body has no supported JSON media type');
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
            if (!is_array($value) || array_is_list($value)) {
                throw new \LogicException('Object schema arbitrary must produce an object map');
            }
            $result = [];
            foreach (array_keys($value) as $key) {
                if (!is_string($key)) {
                    throw new \LogicException('Object schema arbitrary must produce string keys');
                }
                $result[$key] = $this->scalar($value[$key]);
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
