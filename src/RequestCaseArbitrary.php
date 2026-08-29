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
        $arbitrary = Gen::map(Gen::record([
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

        /** @var ArbitraryInterface<array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * }> $arbitrary */
        return $arbitrary;
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
            if (!is_string($mediaType) || !is_array($definition)) {
                continue;
            }
            $schema = $definition['schema'] ?? [];
            if (!is_array($schema) || array_is_list($schema)) {
                throw new UnsupportedGeneration('JSON request body schema must be an object');
            }
            /** @var array<string, mixed> $schema */
            $normalized = strtolower(trim(explode(';', $mediaType, 2)[0]));
            if ($this->isJsonMediaType($mediaType)) {
                $body = Gen::map($this->schemas->compile($schema), static fn(mixed $value): array => [
                    'mediaType' => $mediaType,
                    'encoding' => 'json',
                    'value' => $value,
                ]);
            } elseif ($normalized === 'application/x-www-form-urlencoded') {
                $this->assertObjectSchema($schema, 'Form request body schema must be an object');
                $this->assertFormEncoding($definition['encoding'] ?? []);
                $body = Gen::map($this->schemas->compile($schema), static fn(mixed $value): array => [
                    'mediaType' => $mediaType,
                    'encoding' => 'form',
                    'value' => $value,
                ]);
            } elseif (str_starts_with($normalized, 'multipart/')) {
                $this->assertObjectSchema($schema, 'Multipart request body schema must be an object');
                $this->assertMultipartEncoding($definition['encoding'] ?? []);
                /** @var array<string, mixed> $definition */
                $body = Gen::map($this->multipartValues($schema), fn(array $value): array => $this->multipartBody($mediaType, $schema, $definition, $value));
            } else {
                continue;
            }

            if (($operation->requestBody['required'] ?? false) === true) {
                return $body;
            }

            return Gen::map($this->optional($body), fn(array $choice): ?array => $this->bodyChoice($choice));
        }

        throw new UnsupportedGeneration('Request body has no supported media type');
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
     * @return null|array{mediaType: string, encoding: 'json'|'form', value: mixed}|array{mediaType: string, encoding: 'multipart', boundary: string, parts: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>}
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
        $bodyEncoding = $body['encoding'] ?? null;
        if (!is_string($mediaType) || !is_string($bodyEncoding) || !in_array($bodyEncoding, ['json', 'form'], strict: true) || !array_key_exists('value', $body)) {
            throw new \LogicException('Included request body has an invalid shape');
        }

        return [
            'mediaType' => $mediaType,
            'encoding' => $bodyEncoding,
            'value' => $body['value'],
        ];
    }

    /** @param array<string, mixed> $schema */
    private function multipartValues(array $schema): ArbitraryInterface
    {
        $properties = is_array($schema['properties'] ?? null) ? (array) $schema['properties'] : [];
        if ($properties !== [] && array_is_list($properties)) {
            throw new UnsupportedGeneration('Multipart properties must be an object');
        }
        $required = $schema['required'] ?? [];
        if (!is_array($required) || !array_is_list($required)) {
            throw new UnsupportedGeneration('Multipart required must be a list');
        }
        $requiredNames = [];
        foreach ($required as $name) {
            if (!is_string($name)) {
                throw new UnsupportedGeneration('Multipart required must contain property names');
            }
            $requiredNames[$name] = true;
        }
        $shape = [];
        foreach ($properties as $name => $property) {
            if (!is_string($name) || !is_array($property) || array_is_list($property)) {
                throw new UnsupportedGeneration('Multipart properties must contain named schema objects');
            }
            /** @var array<string, mixed> $property */
            $value = $this->multipartProperty($property);
            $shape[$name] = isset($requiredNames[$name]) ? $value : $this->optional($value);
        }
        if ($shape === []) {
            return Gen::constant([]);
        }

        return Gen::map(Gen::record($shape), /** @param array<array-key, mixed> $values */ static function (array $values): array {
            $result = [];
            foreach (array_keys($values) as $name) {
                if (is_array($values[$name]) && ($values[$name]['included'] ?? null) === false) {
                    continue;
                }
                $result = array_merge($result, [$name => is_array($values[$name]) && array_key_exists('included', $values[$name]) ? ($values[$name]['value'] ?? null) : $values[$name]]);
            }

            return $result;
        });
    }

    /** @param array<string, mixed> $schema */
    private function multipartProperty(array $schema): ArbitraryInterface
    {
        if (($schema['format'] ?? null) === 'binary' && (($schema['type'] ?? null) === 'string' || !array_key_exists('type', $schema))) {
            return Gen::map(Gen::bytes(0, 64), static fn(string $bytes): array => [
                '__openapi_encoding' => 'base64',
                'value' => base64_encode($bytes),
            ]);
        }
        if ($this->isArraySchema($schema)) {
            $items = $schema['items'] ?? null;
            if (!is_array($items) || array_is_list($items)) {
                throw new UnsupportedGeneration('Multipart array items must be a schema object');
            }
            /** @var array<string, mixed> $items */
            $item = $this->multipartProperty($items);
            $min = is_int($schema['minItems'] ?? null) ? (int) $schema['minItems'] : 0;
            $max = min(is_int($schema['maxItems'] ?? null) ? (int) $schema['maxItems'] : 16, 16);

            return ($schema['uniqueItems'] ?? false) === true
                ? Gen::uniqueArrayOf($item, $min, $max)
                : Gen::arrayOf($item, $min, $max);
        }
        if ($this->isObjectSchema($schema)) {
            throw new UnsupportedGeneration('Nested multipart object properties are not supported');
        }

        return $this->schemas->compile($schema);
    }

    /** @param array<string, mixed> $definition
     * @param array<array-key, mixed> $value
     */
    private function multipartBody(string $mediaType, array $schema, array $definition, array $value): array
    {
        $boundary = 'openapi-' . substr(hash('sha256', $mediaType . serialize($value)), 0, 16);
        $parts = [];
        $properties = is_array($schema['properties'] ?? null) ? (array) $schema['properties'] : [];
        $encoding = is_array($definition['encoding'] ?? null) ? (array) $definition['encoding'] : [];
        foreach (array_keys($value) as $name) {
            $property = is_array($properties[$name] ?? null) ? (array) $properties[$name] : [];
            /** @var array<string, mixed> $property */
            $configuration = is_array($encoding[$name] ?? null) ? (array) $encoding[$name] : [];
            /** @var array<string, mixed> $configuration */
            $configuredType = is_string($configuration['contentType'] ?? null) ? (string) $configuration['contentType'] : null;
            $contentType = is_string($configuredType) && $configuredType !== ''
                ? $configuredType
                : $this->multipartContentType($property, $value[$name]);
            $headers = $this->multipartHeaders($configuration['headers'] ?? []);
            $items = $this->isArraySchema($property) && is_array($value[$name]) && array_is_list($value[$name]) ? $value[$name] : [$value[$name]];
            $parts = array_merge($parts, array_map(function (mixed $partValue) use ($name, $property, $contentType, $headers): array {
                $binary = is_array($partValue) && ($partValue['__openapi_encoding'] ?? null) === 'base64';

                return [
                    'name' => $name,
                    'value' => $binary ? (string) ($partValue['value'] ?? '') : $this->multipartText($partValue, $property),
                    'encoding' => $binary ? 'base64' : 'text',
                    'contentType' => $contentType,
                    'headers' => $headers,
                ];
            }, $items));
        }

        return ['mediaType' => $mediaType, 'encoding' => 'multipart', 'boundary' => $boundary, 'parts' => $parts];
    }

    /** @param array<string, mixed> $schema */
    private function multipartText(mixed $value, array $schema): string
    {
        if ($this->isObjectSchema($schema) || ($this->isArraySchema($schema) && is_array($value) && $value !== [] && is_array($value[0] ?? null))) {
            return json_encode($value, JSON_THROW_ON_ERROR);
        }

        return $this->scalar($value);
    }

    /** @param array<string, mixed> $schema */
    private function multipartContentType(array $schema, mixed $value): string
    {
        if ($this->isObjectSchema($schema) || ($this->isArraySchema($schema) && is_array($value) && $value !== [] && is_array($value[0] ?? null))) {
            return 'application/json';
        }
        if (($schema['format'] ?? null) === 'binary') {
            return 'application/octet-stream';
        }

        return 'text/plain';
    }

    /** @return array<string, string> */
    private function multipartHeaders(mixed $headers): array
    {
        if (!is_array($headers) || array_is_list($headers)) {
            return [];
        }
        $result = [];
        foreach ($headers as $name => $definition) {
            if (!is_string($name) || !is_array($definition)) {
                continue;
            }
            if (($definition['required'] ?? false) !== true) {
                continue;
            }
            /** @var mixed $headerValue */
            $headerValue = is_scalar($definition['example'] ?? null) ? $definition['example'] : (is_scalar($definition['default'] ?? null) ? $definition['default'] : 'x-openapi');
            $result[$name] = $this->scalar($headerValue);
        }

        return $result;
    }

    private function assertFormEncoding(mixed $encoding): void
    {
        if ($encoding === null) {
            return;
        }
        if (!is_array($encoding) || array_is_list($encoding)) {
            throw new UnsupportedGeneration('Form encoding must be an object');
        }
        foreach ($encoding as $name => $configuration) {
            if (!is_string($name) || !is_array($configuration) || array_is_list($configuration)) {
                throw new UnsupportedGeneration('Form encoding entries must be objects');
            }
            if (($configuration['style'] ?? 'form') !== 'form' || (isset($configuration['explode']) && !is_bool($configuration['explode']))) {
                throw new UnsupportedGeneration('Form encoding supports only form style and boolean explode');
            }
        }
    }

    private function assertMultipartEncoding(mixed $encoding): void
    {
        if ($encoding === null) {
            return;
        }
        if (!is_array($encoding) || array_is_list($encoding)) {
            throw new UnsupportedGeneration('Multipart encoding must be an object');
        }
        foreach ($encoding as $name => $configuration) {
            if (!is_string($name) || !is_array($configuration) || array_is_list($configuration) || ($configuration['style'] ?? 'form') !== 'form') {
                throw new UnsupportedGeneration('Multipart encoding supports only form style');
            }
        }
    }

    /** @param array<string, mixed> $schema */
    private function assertObjectSchema(array $schema, string $message): void
    {
        if (!$this->isObjectSchema($schema)) {
            throw new UnsupportedGeneration($message);
        }
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
