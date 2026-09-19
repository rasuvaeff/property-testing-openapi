<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MediaType;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSerializer;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\RequestSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\SchemaShape;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\WireValue;
use Rasuvaeff\PropertyTesting\Random;

/**
 * Produces valid, corpus-safe request cases for one compiled operation.
 *
 * Parameters are generated for the wire: OAS 3.0 `nullable` is dropped
 * (an optional parameter's "null" is its absent branch), and a path
 * parameter never carries an empty string or a `/`/`\` that would leave its
 * template segment after percent-decoding. Request bodies are generated
 * from the request direction of their schema, without `readOnly` members.
 *
 * @psalm-import-type CaseData from ContractSuite
 *
 * @api
 */
final readonly class RequestCaseArbitrary
{
    private const int PROBES = 8;

    private const int PROBE_SEED = 11;

    private SchemaArbitraryCompiler $schemas;

    /** The body compiler: a request never carries a `readOnly` member. */
    private SchemaArbitraryCompiler $bodySchemas;

    private ParameterSchemas $parameterSchemas;

    private RequestSchemas $requestSchemas;

    public function __construct()
    {
        $this->schemas = new SchemaArbitraryCompiler();
        $this->bodySchemas = new SchemaArbitraryCompiler(direction: SchemaDirection::Request);
        $this->parameterSchemas = new ParameterSchemas();
        $this->requestSchemas = new RequestSchemas();
    }

    /** @return ArbitraryInterface<CaseData> */
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

        /** @var ArbitraryInterface<CaseData> $arbitrary */
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
            $separator = ParameterSchemas::separatorOf($location, $parameter['style'], $parameter['schema']);

            try {
                $schema = $this->parameterSchemas->forLocation($parameter['schema'], $location, $parameter['style']);
                $compiled = $this->compilerFor($separator)->compile($parameter['required'] ? $this->nonEmptyContainer($schema) : $schema);
            } catch (UnsupportedGeneration $refusal) {
                throw $refusal->inOperation($operation->key, sprintf('%s parameter "%s"', $location, $parameter['name']));
            }
            if ($location === 'path') {
                $compiled = Gen::filter($compiled, fn(mixed $value): bool => $this->parameterSchemas->isPathSafe($value));
            }
            if ($location === 'header') {
                // Same division of labour as the path: the rewrite narrows the
                // alphabet, this refuses what a `pattern` or a `format` can
                // still put outside an HTTP field value — or, for a list or
                // an object, on its separating comma.
                $delimited = $separator === ', ';
                $compiled = Gen::filter($compiled, fn(mixed $value): bool => $this->parameterSchemas->isHeaderSafe($value, $delimited));
            } elseif ($separator !== null) {
                // The rewrite and the narrowed alphabet construct values
                // without those characters; this only guards what neither can
                // see, a `pattern`, whose alphabet is the pattern's own.
                $compiled = Gen::filter($compiled, fn(mixed $value): bool => $this->parameterSchemas->isSeparatorSafe($value, $separator));
            }
            if (($location === 'path' || $location === 'header') && $this->mentionsPattern($schema) && !$this->yieldsSomething($compiled)) {
                // The rewrite cannot see inside a pattern; the filter above
                // can, and a pattern none of whose strings survives the wire
                // is refused here, by name, instead of exhausting mid-run.
                throw UnsupportedGeneration::forSchema(sprintf('no value the pattern admits can be carried by a %s', $location === 'path' ? 'template segment' : 'field value'))
                    ->inOperation($operation->key, sprintf('%s parameter "%s"', $location, $parameter['name']));
            }
            $value = Gen::map(
                $compiled,
                fn(mixed $value): string|array => $this->wireValue($value, $schema),
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

    /** @param array<string, mixed> $schema */
    private function mentionsPattern(array $schema): bool
    {
        return str_contains(json_encode($schema, JSON_THROW_ON_ERROR), '"pattern"');
    }

    /**
     * Whether a filtered arbitrary produces anything, judged the way the
     * compiler's pattern probe does: deterministic draws, each with the
     * filter's own retry budget, so an arbitrary that fails here is one that
     * would have exhausted mid-run.
     */
    private function yieldsSomething(ArbitraryInterface $arbitrary): bool
    {
        $random = new Random(self::PROBE_SEED);
        for ($probe = 0; $probe < self::PROBES; ++$probe) {
            try {
                $arbitrary->generate($random);

                return true;
            } catch (GenerationExhaustedException) {
                continue;
            }
        }

        return false;
    }

    /**
     * A delimited style cannot escape its own separator, so no generated
     * string may carry one — the compiler is built with that character out of
     * its alphabet rather than the value being filtered afterwards. Built per
     * parameter: `location()` runs once while the generator is assembled, not
     * once per case.
     */
    private function compilerFor(?string $separator): SchemaArbitraryCompiler
    {
        return $separator === null ? $this->schemas : new SchemaArbitraryCompiler($separator);
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
        /** @var list<array{int, ArbitraryInterface<mixed>}> $bodies */
        $bodies = [];
        foreach ($content as $mediaType => $definition) {
            // A media type without a schema, or with the `true` schema, admits
            // any value — the contract reads both as unconstrained. Only the
            // `false` schema admits nothing, and nothing can be generated for it.
            $schema = $definition['schema'] ?? [];
            if ($schema === true) {
                $schema = [];
            }
            if ($schema === false) {
                throw new UnsupportedGeneration(sprintf('Request body "%s" declares the false schema, which admits no value', $mediaType));
            }
            $normalized = MediaType::normalize($mediaType);
            $schema = $this->requestSchemas->effective($schema);

            /** @var array<string, mixed> $definition */
            try {
                $body = $this->bodyArbitrary($mediaType, $normalized, $schema, $definition);
            } catch (UnsupportedGeneration $refusal) {
                throw $refusal->inOperation($operation->key, sprintf('request body "%s"', $mediaType));
            }
            if ($body instanceof ArbitraryInterface) {
                $bodies[] = [1, $body];
            }
        }
        if ($bodies === []) {
            throw new UnsupportedGeneration('Request body has no supported media type');
        }

        // Every declared media type this package can generate, not the first
        // one it recognises: a body offering `application/json` beside anything
        // else was only ever exercised through one of them.
        $body = count($bodies) === 1 ? $bodies[0][1] : Gen::frequency($bodies);

        if (($operation->requestBody['required'] ?? false) === true) {
            return $body;
        }

        // The body arbitrary already carries the exact case shape, so the
        // optional form picks between it and no body at all rather than
        // wrapping it and reading the shape back — which is what dropped
        // multipart, the one encoding that carries parts instead of a value.
        return Gen::nullable($body);
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $definition
     * @return null|ArbitraryInterface<mixed> `null` for a media type this
     *         package does not generate
     */
    private function bodyArbitrary(string $mediaType, string $normalized, array $schema, array $definition): ?ArbitraryInterface
    {
        if (MediaType::isJson($mediaType)) {
            /** @var ArbitraryInterface<mixed> $json */
            $json = Gen::map($this->bodySchemas->compile($schema), static fn(mixed $value): array => [
                'mediaType' => $mediaType,
                'encoding' => 'json',
                'value' => $value,
            ]);

            return $json;
        }
        if ($normalized === 'application/x-www-form-urlencoded') {
            $this->assertObjectSchema($schema, 'Form request body schema must be an object');
            $this->assertFormEncoding($definition['encoding'] ?? []);
            /** @var ArbitraryInterface<mixed> $form */
            $form = Gen::map($this->bodySchemas->compile($this->nonEmptyRequiredProperties($this->explodedObjectsWithoutExtras($schema, $definition['encoding'] ?? []))), static fn(mixed $value): array => [
                'mediaType' => $mediaType,
                'encoding' => 'form',
                'value' => $value,
            ]);

            return $form;
        }
        if (str_starts_with($normalized, 'multipart/')) {
            $this->assertObjectSchema($schema, 'Multipart request body schema must be an object');
            $this->assertMultipartEncoding($definition['encoding'] ?? []);
            /** @var ArbitraryInterface<mixed> $multipart */
            $multipart = Gen::map($this->multipartValues($schema), fn(array $value): array => $this->multipartBody($mediaType, $schema, $definition, $value));

            return $multipart;
        }

        return null;
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
            if (!is_string($value[$key])) {
                throw new \LogicException('Included parameter object has an invalid shape');
            }
            // A numeric member name arrives as an integer array key and stays
            // one: it normalizes back wherever it is used as a key, and the
            // serializer casts it where it becomes a string.
            $object = array_replace($object, [$key => $value[$key]]);
        }

        return $object;
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
            if (($property['readOnly'] ?? false) === true) {
                // Owned by the response: declared, typed, never sent.
                continue;
            }
            $required = isset($requiredNames[$name]);
            // A required container has to be generated non-empty here as well
            // as for a form body: an empty array becomes zero parts, and a
            // multipart entity with no parts is not one — RFC 2046 §5.1.1
            // requires at least one, and the contract rejects the payload this
            // generator just called valid.
            $value = $this->multipartProperty($required ? $this->nonEmptyContainer($property) : $property);
            $shape[$name] = $required ? $value : $this->optional($value);
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
        if (SchemaShape::isArray($schema)) {
            $items = $schema['items'] ?? null;
            if (!is_array($items) || array_is_list($items)) {
                throw new UnsupportedGeneration('Multipart array items must be a schema object');
            }
            /** @var array<string, mixed> $items */
            if (SchemaShape::isArray($items) || SchemaShape::isObject($items)) {
                throw new UnsupportedGeneration('Nested multipart array items are not supported');
            }
            $item = $this->multipartProperty($items);
            $min = is_int($schema['minItems'] ?? null) ? (int) $schema['minItems'] : 0;
            $max = min(is_int($schema['maxItems'] ?? null) ? (int) $schema['maxItems'] : 16, 16);

            return ($schema['uniqueItems'] ?? false) === true
                ? Gen::uniqueArrayOf($item, $min, $max)
                : Gen::arrayOf($item, $min, $max);
        }
        if (SchemaShape::isObject($schema)) {
            throw new UnsupportedGeneration('Nested multipart object properties are not supported');
        }

        // A text part is written verbatim into a CRLF-delimited body, and the
        // multipart parsers in the wild trim what they read back: riverline,
        // the one `league/openapi-psr7-validator` uses, turns a part whose
        // value is only whitespace into an empty string and silently drops the
        // padding of any other. The value the handler receives is then not the
        // value the case recorded — the same failure as the query `+`. The
        // shape removed here is one no client sends on purpose, and refusing
        // it costs a percent of draws.
        return Gen::filter(
            $this->bodySchemas->compile($schema),
            static fn(mixed $value): bool => !is_string($value) || trim($value) === $value,
        );
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
            $partSchema = is_array($property['items'] ?? null) ? (array) $property['items'] : $property;
            /** @var array<string, mixed> $partSchema */
            $configuration = is_array($encoding[$name] ?? null) ? (array) $encoding[$name] : [];
            /** @var array<string, mixed> $configuration */
            $configuredType = is_string($configuration['contentType'] ?? null) ? (string) $configuration['contentType'] : null;
            $contentType = is_string($configuredType) && $configuredType !== ''
                ? $configuredType
                : $this->multipartContentType($partSchema);
            ParameterSerializer::assertTransmittableHeader('Content-Type', $contentType);
            $headers = $this->multipartHeaders($configuration['headers'] ?? []);
            $items = is_array($value[$name]) && array_is_list($value[$name]) ? $value[$name] : [$value[$name]];
            $parts = array_merge($parts, array_map(function (mixed $partValue) use ($name, $contentType, $headers): array {
                $binary = is_array($partValue) && ($partValue['__openapi_encoding'] ?? null) === 'base64';

                return [
                    'name' => $name,
                    'value' => $binary ? (string) ($partValue['value'] ?? '') : $this->partText((string) $name, $partValue, $contentType),
                    'encoding' => $binary ? 'base64' : 'text',
                    'contentType' => $contentType,
                    'headers' => $headers,
                ];
            }, $items));
        }

        return ['mediaType' => $mediaType, 'encoding' => 'multipart', 'boundary' => $boundary, 'parts' => $parts];
    }

    /**
     * Parts are scalar or binary only — nested objects and arrays fail closed
     * at generation — so a part travels as text with the OAS default content
     * type of its item schema.
     *
     * @param array<string, mixed> $schema
     */
    private function multipartContentType(array $schema): string
    {
        return ($schema['format'] ?? null) === 'binary' ? 'application/octet-stream' : 'text/plain';
    }

    /**
     * The text of a non-binary part, as its media type reads it. A `text/*`
     * or `application/octet-stream` part is the value verbatim; a JSON part
     * carries the JSON encoding of the value — the validator decodes it as
     * JSON, so a bare `abc` under `application/json` is a decoding failure,
     * not a string (#122). A media type this package can write neither way
     * fails closed: a body it cannot vouch for is not a valid case.
     */
    private function partText(string $name, mixed $value, string $contentType): string
    {
        $normalized = MediaType::normalize($contentType);
        if (MediaType::isJson($normalized)) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        if (str_starts_with($normalized, 'text/') || $normalized === 'application/octet-stream') {
            return $this->scalar($value);
        }

        throw new UnsupportedGeneration(sprintf('Multipart property "%s" declares content type "%s", which this generator can write neither as text nor as JSON', $name, $contentType));
    }

    /**
     * RFC 6570 treats an empty list or map as undefined, so the materializer
     * omits it; a required container therefore has to be generated non-empty.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function nonEmptyContainer(array $schema): array
    {
        if (SchemaShape::isArray($schema)) {
            $min = is_int($schema['minItems'] ?? null) ? (int) $schema['minItems'] : 0;

            return array_merge($schema, ['minItems' => max(1, $min)]);
        }
        if (SchemaShape::isObject($schema)) {
            $min = is_int($schema['minProperties'] ?? null) ? (int) $schema['minProperties'] : 0;

            return array_merge($schema, ['minProperties' => max(1, $min)]);
        }

        return $schema;
    }

    /**
     * A form property with an object schema and `explode: true` (the form
     * default) is written as flat `member=value` pairs, so its wire form can
     * carry only the members the document declares: an undeclared member the
     * generator added would land as a top-level member of the body, where it
     * collides with a declared property or violates `additionalProperties:
     * false`. Such an object is generated without extras, and one whose
     * `minProperties` its declared properties cannot meet fails closed here
     * rather than as a run-time exhaustion (#120).
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function explodedObjectsWithoutExtras(array $schema, mixed $encoding): array
    {
        $properties = is_array($schema['properties'] ?? null) ? (array) $schema['properties'] : [];
        foreach (array_keys($properties) as $name) {
            $property = $properties[$name];
            if (!is_array($property) || array_is_list($property)) {
                continue;
            }
            /** @var array<string, mixed> $property */
            if (!SchemaShape::isObject($property)) {
                continue;
            }
            $configuration = is_array($encoding) && is_array($encoding[$name] ?? null) ? (array) $encoding[$name] : [];
            if (($configuration['explode'] ?? true) !== true) {
                continue;
            }
            $declared = is_array($property['properties'] ?? null) ? count((array) $property['properties']) : 0;
            $minimum = is_int($property['minProperties'] ?? null) ? (int) $property['minProperties'] : 0;
            if ($minimum > $declared) {
                throw UnsupportedGeneration::forSchema(sprintf('form property "%s" is an exploded object whose minProperties %d cannot be met by its %d declared properties, and its wire form carries no undeclared member', (string) $name, $minimum, $declared));
            }
            $property['additionalProperties'] = false;
            $properties[$name] = $property;
        }

        return array_merge($schema, ['properties' => $properties]);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private function nonEmptyRequiredProperties(array $schema): array
    {
        $required = is_array($schema['required'] ?? null) ? (array) $schema['required'] : [];
        $properties = is_array($schema['properties'] ?? null) ? (array) $schema['properties'] : [];
        foreach ($required as $name) {
            if (!is_string($name)) {
                continue;
            }
            $property = $properties[$name] ?? null;
            if (!is_array($property) || array_is_list($property)) {
                continue;
            }
            /** @var array<string, mixed> $property */
            $properties[$name] = $this->nonEmptyContainer($property);
        }

        return array_merge($schema, ['properties' => $properties]);
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
            $value = $this->scalar($headerValue);
            ParameterSerializer::assertTransmittableHeader($name, $value);
            $result[$name] = $value;
        }

        return $result;
    }

    private function assertFormEncoding(mixed $encoding): void
    {
        if ($encoding === null || $encoding === []) {
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
        if ($encoding === null || $encoding === []) {
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
        if (!SchemaShape::isObject($schema)) {
            throw new UnsupportedGeneration($message);
        }
    }

    /** @param array<string, mixed> $schema */
    private function wireValue(mixed $value, array $schema): string|array
    {
        if (SchemaShape::isArray($schema)) {
            if (!is_array($value) || !array_is_list($value)) {
                throw new \LogicException('Array schema arbitrary must produce a list');
            }

            return array_map($this->scalar(...), $value);
        }
        if (SchemaShape::isObject($schema)) {
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
        return WireValue::of($value)
            ?? throw new UnsupportedGeneration('Parameter values must be scalar, arrays, or objects with scalar properties');
    }


}
