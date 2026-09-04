<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\JsonBodyEncoder;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MediaType;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSerializer;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\SchemaShape;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\WireValue;

/**
 * Materializes a data-only case as a PSR-7 request immediately before transport.
 *
 * The request target is built against the operation's first effective server:
 * a relative server yields a path-only URI (host-agnostic, so in-process
 * transports keep working), an absolute server yields an absolute URI. An
 * explicit base URI replaces the declared server for a consumer environment;
 * the contract still has the last word, so an absolute override that
 * contradicts every declared server fails closed before transport.
 *
 * @api
 */
final readonly class RequestMaterializer
{
    private ParameterSerializer $parameters;

    private JsonBodyEncoder $json;

    public function __construct(
        private RequestFactoryInterface $requests,
        private StreamFactoryInterface $streams,
        private ?string $baseUri = null,
    ) {
        if ($baseUri !== null) {
            $this->assertBaseUri($baseUri);
        }
        $this->parameters = new ParameterSerializer();
        $this->json = new JsonBodyEncoder();
    }

    /**
     * Base URI used instead of the declared server: either an absolute
     * `scheme://host[:port][/base]` or a root-relative `/base` path, without
     * userinfo, query, or fragment.
     */
    public function withBaseUri(string $baseUri): self
    {
        return new self($this->requests, $this->streams, $baseUri);
    }

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
            if (!array_key_exists($parameter['name'], $values) || $values[$parameter['name']] === []) {
                continue;
            }
            $wire = $this->parameters->serialize(
                name: $parameter['name'],
                value: $values[$parameter['name']],
                style: $parameter['style'],
                explode: $parameter['explode'],
                // The specification defines `allowReserved` for `in: query`
                // only; honouring it on a header or a cookie applied a rule
                // the document never stated there.
                allowReserved: $parameter['in'] === 'query' && $parameter['allowReserved'],
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

        $request = $this->requests->createRequest($operation->method, $this->requestTarget($operation, $path));
        foreach ($headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }
        if ($cookies !== []) {
            $request = $request->withHeader('Cookie', implode('; ', $cookies));
        }
        if ($credentials instanceof Credentials) {
            $this->assertCredentialsAreDistinguishable($operation, $credentials);
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
            $payload = $this->json->encode($body['value'] ?? null, $schema);
        }

        $request = $request
            ->withHeader('Content-Type', $body['mediaType'])
            ->withBody($this->streams->createStream($payload));

        return $credentials?->apply($request) ?? $request;
    }

    /**
     * A query credential and an exploded object query parameter that admits
     * undeclared members cannot share a query string: the parameter's style
     * claims every pair a sibling parameter does not, so the credential
     * becomes one of its members. The operation is then exercised with a value
     * other than the one the case recorded — and where the object is
     * constrained, the case this package called valid is reported invalid.
     *
     * OpenAPI itself leaves that ambiguity to the style, so there is nothing to
     * resolve here and the honest answer is to say so rather than send a
     * request that means something else.
     */
    private function assertCredentialsAreDistinguishable(Operation $operation, Credentials $credentials): void
    {
        if ($credentials->query === []) {
            return;
        }
        foreach ($operation->parameters as $parameter) {
            if ($parameter['in'] !== 'query' || !$parameter['explode'] || $parameter['style'] !== 'form') {
                continue;
            }
            if (($parameter['schema']['type'] ?? null) !== 'object' && !array_key_exists('properties', $parameter['schema'])) {
                continue;
            }
            if (($parameter['schema']['additionalProperties'] ?? true) === false) {
                continue;
            }

            throw new UnsupportedGeneration(sprintf(
                'Query credentials (%s) cannot be told apart from the exploded object query parameter "%s", which admits undeclared members',
                implode(', ', array_keys($credentials->query)),
                $parameter['name'],
            ));
        }
    }

    private function requestTarget(Operation $operation, string $path): string
    {
        if ($this->baseUri !== null) {
            return $this->joinBase($this->baseUri, $path);
        }
        $server = $operation->servers[0] ?? null;
        if ($server === null) {
            return $this->joinBase($operation->serverBases[0] ?? '/', $path);
        }
        $authority = $server['host'] === null
            ? ''
            : sprintf('%s://%s%s', $server['scheme'] ?? '', $server['host'], $server['port'] === null ? '' : ':' . $server['port']);

        return $authority . $this->joinBase($server['base'], $path);
    }

    private function joinBase(string $base, string $path): string
    {
        $base = rtrim($base, '/');

        return $base === '' ? $path : $base . $path;
    }

    private function assertBaseUri(string $baseUri): void
    {
        if ($baseUri === '' || ($baseUri !== '/' && str_ends_with($baseUri, '/')) || preg_match('/[?#@\\s]/', $baseUri) === 1) {
            throw new \InvalidArgumentException(sprintf('Base URI "%s" must be an absolute URI or a root-relative path without a trailing slash, userinfo, query, or fragment', $baseUri));
        }
        if (str_starts_with($baseUri, '/')) {
            if (str_starts_with($baseUri, '//')) {
                throw new \InvalidArgumentException(sprintf('Base URI "%s" must not be protocol-relative', $baseUri));
            }

            return;
        }
        if (preg_match('~^(?:https?)://[A-Za-z0-9.\-]+(?::[1-9][0-9]{0,4})?(?:/[^/?#][^?#]*)?\z~', $baseUri) !== 1) {
            throw new \InvalidArgumentException(sprintf('Base URI "%s" must be http(s)://host[:port][/base] or a root-relative path', $baseUri));
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<array-key, mixed> $encoding
     */
    private function formBody(mixed $value, array $schema, array $encoding): string
    {
        if (!is_array($value) || !SchemaShape::isObject($schema)) {
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
            if ($value[$name] === []) {
                // RFC 6570: an empty list or map is undefined and expands to nothing.
                continue;
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
        if (SchemaShape::isArray($schema)) {
            if (!is_array($value) || !array_is_list($value)) {
                throw new UnsupportedGeneration('Form array value must be a list');
            }
            $items = $this->schemaObject($schema['items'] ?? null, 'Form array items must be a schema object');

            return array_map($this->scalarValue(...), $value);
        }
        if (SchemaShape::isObject($schema)) {
            if (!is_array($value) || ($value !== [] && array_is_list($value))) {
                throw new UnsupportedGeneration('Form object value must be an object');
            }
            /** @var array<array-key, mixed> $value */
            $properties = $this->memberMap($schema['properties'] ?? [], 'Form object properties must be an object');
            /** @var array<array-key, string> $result */
            $result = [];
            foreach (array_keys($value) as $key) {
                $property = $this->schemaObject($properties[$key] ?? [], 'Form object property must be a schema object');
                $result = array_replace($result, [$key => $this->scalarValue($value[$key])]);
            }

            return $result;
        }

        return $this->scalarValue($value);
    }

    private function scalarValue(mixed $value): string
    {
        return WireValue::of($value)
            ?? throw new UnsupportedGeneration('Form scalar value has an unsupported type');
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
            $payload .= 'Content-Disposition: form-data; name="' . $this->quoteHeader($name) . '"'
                . ($part['encoding'] === 'base64' ? '; filename="' . $this->quoteHeader($name) . '"' : '') . "\r\n";
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
            if (MediaType::isJson($mediaType)) {
                return $definition;
            }
        }

        return null;
    }


    /**
     * A Schema Object: keyed by JSON Schema keywords, which are never numeric.
     *
     * @return array<string, mixed>
     */
    private function schemaObject(mixed $value, string $message): array
    {
        /** @var array<string, mixed> $map */
        $map = $this->memberMap($value, $message);

        return $map;
    }

    /**
     * A map keyed by member name. The key type is `array-key` because PHP
     * normalizes a numeric-string key to an integer — see
     * {@see JsonBodyEncoder} for the same split.
     *
     * `array_replace`, not `array_merge`: both quiet the psalm
     * `MixedAssignment` that a direct `$result[$key] =` raises, but only one
     * of them keeps an integer-like key where it was put.
     *
     * @return array<array-key, mixed>
     */
    private function memberMap(mixed $value, string $message): array
    {
        if (!is_array($value)) {
            throw new UnsupportedGeneration($message);
        }
        if ($value !== [] && array_is_list($value)) {
            throw new UnsupportedGeneration($message);
        }

        /** @var array<array-key, mixed> $result */
        $result = [];
        foreach (array_keys($value) as $key) {
            $result = array_replace($result, [$key => $value[$key]]);
        }

        return $result;
    }
}
