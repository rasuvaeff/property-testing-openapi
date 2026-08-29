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
     *     body: null|array{mediaType: string, encoding: 'json'|'raw'|'form', value: mixed}|array{mediaType: string, encoding: 'multipart', boundary: string, parts: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>},
     *     misuse: null|array{kind: non-empty-string, location: non-empty-string, name: string},
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
                allowReserved: $parameter['in'] !== 'path' && $parameter['allowReserved'],
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
        $body = $case['body'];
        if ($body['encoding'] === 'raw') {
            $rawValue = $body['value'] ?? null;
            if (!is_string($rawValue)) {
                throw new UnsupportedGeneration('Raw request body value must be a string');
            }
            $payload = $rawValue;
        } elseif ($body['encoding'] === 'form') {
            $schema = $this->bodySchema($operation, $body['mediaType'], $case['misuse']);
            $payload = $this->formBody($body['value'] ?? null, $schema, $this->bodyEncoding($operation, $body['mediaType']));
        } elseif ($body['encoding'] === 'multipart') {
            $parts = $body['parts'] ?? null;
            $boundary = $body['boundary'] ?? null;
            if (!is_array($parts) || !is_string($boundary)) {
                throw new UnsupportedGeneration('Multipart request body has an invalid shape');
            }
            $payload = $this->multipartBody($parts, $boundary);
            $body['mediaType'] .= '; boundary=' . $boundary;
        } else {
            $schema = $this->bodySchema($operation, $body['mediaType'], $case['misuse']);
            $payload = json_encode($this->jsonValue($body['value'] ?? null, $schema), JSON_THROW_ON_ERROR);
        }

        $request = $request
            ->withHeader('Content-Type', $body['mediaType'])
            ->withBody($this->streams->createStream($payload));

        return $credentials?->apply($request) ?? $request;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $encoding
     */
    private function formBody(mixed $value, array $schema, array $encoding): string
    {
        if (!is_array($value) || !$this->isObjectSchema($schema)) {
            throw new UnsupportedGeneration('Form request body value must be an object');
        }
        if ($value !== [] && array_is_list($value)) {
            throw new UnsupportedGeneration('Form request body value must be an object');
        }
        /** @var array<array-key, mixed> $value */
        $properties = $this->schemaObject($schema['properties'] ?? [], 'Form object properties must be an object');
        $parts = [];
        foreach (array_keys($value) as $name) {
            if (!is_string($name)) {
                throw new UnsupportedGeneration('Form object keys must be strings');
            }
            $property = $this->schemaObject($properties[$name] ?? [], 'Form object property must be a schema object');
            $configuration = $this->formConfiguration($encoding[$name] ?? null);
            /** @var array<string, mixed> $configuration */
            $style = $configuration['style'] ?? 'form';
            $explode = $configuration['explode'] ?? true;
            if ($style !== 'form' || !is_bool($explode)) {
                throw new UnsupportedGeneration(sprintf('Unsupported form encoding for property "%s"', $name));
            }
            $wire = $this->formValue($value[$name], $property, $name, $explode);
            if ($wire !== '') {
                $parts[] = $wire;
            }
        }

        return implode('&', $parts);
    }

    /** @return array<array-key, mixed> */
    private function formConfiguration(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    /** @param array<string, mixed> $schema */
    private function formValue(mixed $value, array $schema, string $name, bool $explode): string
    {
        $wire = $this->formWireValue($value, $schema);

        return $this->parameters->serialize(name: $name, value: $wire, style: 'form', explode: $explode);
    }

    /** @param array<string, mixed> $schema
     * @return string|list<string>|array<string, string>
     */
    private function formWireValue(mixed $value, array $schema): string|array
    {
        if ($this->isArraySchema($schema)) {
            if (!is_array($value) || !array_is_list($value)) {
                throw new UnsupportedGeneration('Form array value must be a list');
            }
            $items = $this->schemaObject($schema['items'] ?? null, 'Form array items must be a schema object');

            return array_map($this->scalarValue(...), $value);
        }
        if ($this->isObjectSchema($schema)) {
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new UnsupportedGeneration('Form object value must be an object');
            }
            /** @var array<array-key, mixed> $value */
            $properties = $this->schemaObject($schema['properties'] ?? [], 'Form object properties must be an object');
            $result = [];
            foreach (array_keys($value) as $key) {
                $name = (string) $key;
                $property = $this->schemaObject($properties[$name] ?? [], 'Form object property must be a schema object');
                $result = array_merge($result, [$name => $this->scalarValue($value[$key])]);
            }

            return $result;
        }

        return $this->scalarValue($value);
    }

    private function scalarValue(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if ($value === null) {
            return 'null';
        }

        throw new UnsupportedGeneration('Form scalar value has an unsupported type');
    }

    /** @return array<array-key, mixed> */
    private function bodyEncoding(Operation $operation, string $mediaType): array
    {
        $content = $operation->requestBody['content'] ?? null;
        if (!is_array($content) || !is_array($content[$mediaType] ?? null)) {
            return [];
        }
        $definition = $content[$mediaType] ?? null;
        if (!is_array($definition)) {
            return [];
        }

        return (array) ($definition['encoding'] ?? []);
    }

    /** @param list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}> $parts */
    private function multipartBody(array $parts, string $boundary): string
    {
        if ($boundary === '' || strlen($boundary) > 70 || preg_match("/^[0-9A-Za-z'()+_,.\/:=? -]+\\z/", $boundary) !== 1) {
            throw new UnsupportedGeneration('Multipart boundary is invalid');
        }
        $payload = '';
        foreach ($parts as $part) {
            $name = $part['name'];
            $headers = $part['headers'];
            $contentType = $part['contentType'];
            $value = $part['encoding'] === 'base64' ? base64_decode($part['value'], strict: true) : $part['value'];
            if ($value === false) {
                throw new UnsupportedGeneration('Multipart base64 value is invalid');
            }
            $payload .= '--' . $boundary . "\r\n";
            $payload .= 'Content-Disposition: form-data; name="' . $this->quoteHeader($name) . "\"\r\n";
            $payload .= 'Content-Type: ' . $contentType . "\r\n";
            foreach ($headers as $header => $headerValue) {
                $payload .= $header . ': ' . $headerValue . "\r\n";
            }
            $payload .= "\r\n" . $value . "\r\n";
        }

        return $payload . '--' . $boundary . "--\r\n";
    }

    private function quoteHeader(string $value): string
    {
        return str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '', ''], $value);
    }

    /**
     * A media-type misuse deliberately carries an undeclared Content-Type; the
     * body is still encoded with the declared JSON schema so the media type is
     * the only deviation.
     *
     * @param null|array{kind: non-empty-string, location: non-empty-string, name: string} $misuse
     * @return array<string, mixed>
     */
    private function bodySchema(Operation $operation, string $mediaType, ?array $misuse): array
    {
        $content = $operation->requestBody['content'] ?? null;
        if (!is_array($content)) {
            throw new UnsupportedGeneration('Request body content must be an object');
        }
        $definition = $content[$mediaType] ?? null;
        if (!is_array($definition) && $misuse !== null && $misuse['kind'] === 'media-type' && $misuse['location'] === 'body') {
            $definition = $this->declaredJsonDefinition($content);
        }
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

    /**
     * @param array<array-key, mixed> $content
     * @return array<array-key, mixed>|null
     */
    private function declaredJsonDefinition(array $content): ?array
    {
        foreach ($content as $mediaType => $definition) {
            if (!is_string($mediaType) || !is_array($definition)) {
                continue;
            }
            $name = strtolower(trim(explode(';', $mediaType, 2)[0]));
            if ($name === 'application/json' || str_ends_with($name, '+json')) {
                return $definition;
            }
        }

        return null;
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
