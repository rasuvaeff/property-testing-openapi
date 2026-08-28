<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSerializer;

/**
 * Materializes a data-only case as a PSR-7 request immediately before transport.
 *
 * @api
 */
final readonly class RequestMaterializer
{
    public function __construct(
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
        private ParameterSerializer $parameters = new ParameterSerializer(),
    ) {}

    /**
     * @param array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: null|array{kind: 'missing-required'|'type', location: 'path'|'query'|'header'|'cookie'|'body', name: string},
     * } $case
     */
    public function materialize(Operation $operation, array $case, ?Credentials $credentials = null): RequestInterface
    {
        if ($case['operationKey'] !== $operation->key) {
            throw new \InvalidArgumentException(sprintf('Request case targets "%s", not "%s"', $case['operationKey'], $operation->key));
        }
        $path = $operation->path;
        $query = [];
        $headers = [];
        $cookies = [];
        foreach ($operation->parameters as $parameter) {
            $values = match ($parameter['in']) {
                'path' => $case['path'],
                'query' => $case['query'],
                'header' => $case['headers'],
                'cookie' => $case['cookies'],
            };
            if (!array_key_exists($parameter['name'], $values)) {
                continue;
            }
            $wire = $this->parameters->serialize(
                name: $parameter['name'],
                value: $values[$parameter['name']],
                style: $parameter['style'],
                explode: $parameter['explode'],
                allowReserved: $parameter['allowReserved'],
            );
            match ($parameter['in']) {
                'path' => $path = str_replace('{' . $parameter['name'] . '}', $wire, $path),
                'query' => $query[] = $wire,
                'header' => $headers[$parameter['name']] = $wire,
                'cookie' => $cookies[] = $wire,
            };
        }
        if ($query !== []) {
            $path .= '?' . implode('&', $query);
        }

        $request = $this->requests->createRequest($operation->method, $path);
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($cookies !== []) {
            $request = $request->withHeader('Cookie', implode('; ', $cookies));
        }
        if ($case['body'] === null) {
            return $credentials?->apply($request) ?? $request;
        }
        $schema = $this->bodySchema($operation, $case['body']['mediaType']);
        $json = json_encode($this->jsonValue($case['body']['value'], $schema), JSON_THROW_ON_ERROR);

        $request = $request
            ->withHeader('Content-Type', $case['body']['mediaType'])
            ->withBody($this->streams->createStream($json));

        return $credentials?->apply($request) ?? $request;
    }

    /** @return array<string, mixed> */
    private function bodySchema(Operation $operation, string $mediaType): array
    {
        $content = $operation->requestBody['content'] ?? null;
        if (!is_array($content)) {
            throw new UnsupportedGeneration('Request body content must be an object');
        }
        $definition = $content[$mediaType] ?? null;
        if (!is_array($definition)) {
            throw new UnsupportedGeneration(sprintf('Request body media type "%s" is not declared', $mediaType));
        }
        $schema = $definition['schema'] ?? [];
        if (!is_array($schema) || array_is_list($schema)) {
            throw new UnsupportedGeneration('JSON request body schema must be an object');
        }

        /** @var array<string, mixed> $schema */
        return $schema;
    }

    /** @param array<string, mixed> $schema */
    private function jsonValue(mixed $value, array $schema): mixed
    {
        if ($this->isArraySchema($schema) && is_array($value) && array_is_list($value)) {
            $items = $this->schemaObject($schema['items'] ?? null, 'Array items must be a schema object');

            return array_map(fn(mixed $item): mixed => $this->jsonValue($item, $items), $value);
        }
        if ($this->isObjectSchema($schema) && is_array($value) && ($value === [] || !array_is_list($value))) {
            $properties = $this->schemaObject($schema['properties'] ?? [], 'Object properties must be an object');
            /** @var array<string, mixed> $result */
            $result = [];
            foreach (array_keys($value) as $name) {
                if (!is_string($name)) {
                    throw new UnsupportedGeneration('JSON object keys must be strings');
                }
                $property = $this->schemaObject($properties[$name] ?? [], 'Object property must be a schema object');
                $result = $this->withJsonValue($result, $name, $value[$name], $property);
            }

            return (object) $result;
        }

        return $value;
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

    /** @return array<string, mixed> */
    private function schemaObject(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new UnsupportedGeneration($message);
        }
        if ($value !== [] && array_is_list($value)) {
            throw new UnsupportedGeneration($message);
        }

        /** @var array<string, mixed> $result */
        $result = [];
        foreach (array_keys($value) as $key) {
            if (!is_string($key)) {
                throw new UnsupportedGeneration($message);
            }
            $result = $this->withValue($result, $key, $value[$key]);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function withJsonValue(array $result, string $name, mixed $value, array $schema): array
    {
        return $this->withValue($result, $name, $this->jsonValue($value, $schema));
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function withValue(array $result, string $key, mixed $value): array
    {
        return array_merge($result, [$key => $value]);
    }
}
