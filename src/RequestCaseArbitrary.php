<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\SchemaCheck;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\GenerationExhaustedException;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MediaType;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSerializer;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Probe;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\RawBodies;
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

    private SchemaCheck $check;

    public function __construct()
    {
        $this->check = new SchemaCheck();
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
                $this->assertFlatParameter($schema);
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
            if (($location === 'path' || $location === 'header') && $this->mentionsPattern($schema) && !Probe::yields($compiled)) {
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
            throw UnsupportedGeneration::forSchema('Request body content must be an object')->inOperation($operation->key, 'request body');
        }
        /** @var list<array{int, ArbitraryInterface<mixed>}> $bodies */
        $bodies = [];
        /** @var array<string, array<string, mixed>> $raw */
        $raw = [];
        foreach ($content as $mediaType => $definition) {
            // A media type without a schema, or with the `true` schema, admits
            // any value — the contract reads both as unconstrained. Only the
            // `false` schema admits nothing, and nothing can be generated for it.
            $schema = $definition['schema'] ?? [];
            if ($schema === true) {
                $schema = [];
            }
            if ($schema === false) {
                throw UnsupportedGeneration::forSchema('the false schema admits no value')->inOperation($operation->key, sprintf('request body "%s"', $mediaType));
            }
            $normalized = MediaType::normalize($mediaType);
            $schema = $this->requestSchemas->effective($schema);

            /** @var array<string, mixed> $definition */
            if (RawBodies::carries($mediaType)) {
                $raw[$mediaType] = $schema;

                continue;
            }

            try {
                $body = $this->bodyArbitrary($mediaType, $normalized, $schema, $definition, $operation->dialect);
            } catch (UnsupportedGeneration $refusal) {
                throw $refusal->inOperation($operation->key, sprintf('request body "%s"', $mediaType));
            }
            if ($body instanceof ArbitraryInterface) {
                $bodies[] = [1, $body];
            }
        }
        // A body sent as it is (`text/plain`, `application/octet-stream`) is
        // generated where it is the only kind the operation offers (#170):
        // beside a JSON, form or multipart media type it is left out, so an
        // operation that generated before draws the cases it always drew.
        if ($bodies === []) {
            foreach ($raw as $mediaType => $schema) {
                try {
                    $bodies[] = [1, RawBodies::arbitrary($mediaType, $schema, $this->bodySchemas)];
                } catch (UnsupportedGeneration $refusal) {
                    throw $refusal->inOperation($operation->key, sprintf('request body "%s"', $mediaType));
                }
            }
        }
        if ($bodies === []) {
            throw UnsupportedGeneration::forSchema('Request body has no supported media type')->inOperation($operation->key, 'request body');
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
    private function bodyArbitrary(string $mediaType, string $normalized, array $schema, array $definition, SchemaDialect $dialect): ?ArbitraryInterface
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
            $values = $this->multipartValues($schema);
            $plans = $this->partPlans($schema, $definition, $dialect);
            /** @var ArbitraryInterface<mixed> $multipart */
            $multipart = Gen::map($values, fn(array $value): array => $this->multipartBody($mediaType, $plans, $value));

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
            throw UnsupportedGeneration::forSchema('Multipart properties must be an object');
        }
        $required = $schema['required'] ?? [];
        if (!is_array($required) || !array_is_list($required)) {
            throw UnsupportedGeneration::forSchema('Multipart required must be a list');
        }
        $requiredNames = [];
        foreach ($required as $name) {
            if (!is_string($name)) {
                throw UnsupportedGeneration::forSchema('Multipart required must contain property names');
            }
            $requiredNames[$name] = true;
        }
        $shape = [];
        foreach ($properties as $name => $property) {
            if (!is_string($name) || !is_array($property) || array_is_list($property)) {
                throw UnsupportedGeneration::forSchema('Multipart properties must contain named schema objects');
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
                throw UnsupportedGeneration::forSchema('Multipart array items must be a schema object');
            }
            /** @var array<string, mixed> $items */
            if (SchemaShape::isArray($items) || SchemaShape::isObject($items)) {
                throw UnsupportedGeneration::forSchema('Nested multipart array items are not supported');
            }
            $item = $this->multipartProperty($items);
            $min = is_int($schema['minItems'] ?? null) ? (int) $schema['minItems'] : 0;
            // The budget yields to a declared minimum above it (#169).
            $budget = max(16, $min);
            $max = min(is_int($schema['maxItems'] ?? null) ? (int) $schema['maxItems'] : $budget, $budget);

            return ($schema['uniqueItems'] ?? false) === true
                ? Gen::uniqueArrayOf($item, $min, $max)
                : Gen::arrayOf($item, $min, $max);
        }
        if (SchemaShape::isObject($schema)) {
            throw UnsupportedGeneration::forSchema('Nested multipart object properties are not supported');
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

    /**
     * What every part of a property is written with, settled when the
     * operation is compiled rather than when a case is drawn: the content
     * type a part declares and the headers it requires are document facts,
     * and a document this package cannot write is refused from
     * `forOperation()`, by name, not on the draws that happen to include the
     * part (#156).
     *
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $definition
     * @return array<array-key, array{contentType: string, headers: array<string, string>}>
     */
    private function partPlans(array $schema, array $definition, SchemaDialect $dialect): array
    {
        $properties = is_array($schema['properties'] ?? null) ? (array) $schema['properties'] : [];
        $encoding = is_array($definition['encoding'] ?? null) ? (array) $definition['encoding'] : [];
        $plans = [];
        foreach ($properties as $name => $property) {
            if (!is_array($property) || ($property['readOnly'] ?? false) === true) {
                continue;
            }
            $partSchema = is_array($property['items'] ?? null) ? (array) $property['items'] : $property;
            /** @var array<string, mixed> $partSchema */
            $configuration = is_array($encoding[$name] ?? null) ? (array) $encoding[$name] : [];
            /** @var array<string, mixed> $configuration */
            $configuredType = is_string($configuration['contentType'] ?? null) ? (string) $configuration['contentType'] : null;
            $contentType = is_string($configuredType) && $configuredType !== ''
                ? $configuredType
                : $this->multipartContentType($partSchema);

            try {
                ParameterSerializer::assertTransmittableHeader('Content-Type', $contentType);
            } catch (InvalidCase $defect) {
                throw UnsupportedGeneration::forSchema(sprintf('multipart property "%s": %s', (string) $name, $defect->getMessage()));
            }
            if (!$this->isBinaryPart($partSchema) && !$this->isWritablePartType($contentType)) {
                throw UnsupportedGeneration::forSchema(sprintf('Multipart property "%s" declares content type "%s", which this generator can write neither as text nor as JSON', (string) $name, $contentType));
            }
            $plans[$name] = ['contentType' => $contentType, 'headers' => $this->multipartHeaders((string) $name, $configuration['headers'] ?? [], $dialect)];
        }

        return $plans;
    }

    /**
     * @param array<array-key, array{contentType: string, headers: array<string, string>}> $plans
     * @param array<array-key, mixed> $value
     */
    private function multipartBody(string $mediaType, array $plans, array $value): array
    {
        $boundary = 'openapi-' . substr(hash('sha256', $mediaType . serialize($value)), 0, 16);
        $parts = [];
        foreach (array_keys($value) as $name) {
            $plan = $plans[$name] ?? throw new \LogicException('Generated multipart property has no plan');
            $contentType = $plan['contentType'];
            $headers = $plan['headers'];
            $items = is_array($value[$name]) && array_is_list($value[$name]) ? $value[$name] : [$value[$name]];
            $parts = array_merge($parts, array_map(function (mixed $partValue) use ($name, $contentType, $headers): array {
                $binary = is_array($partValue) && ($partValue['__openapi_encoding'] ?? null) === 'base64';

                return [
                    'name' => $name,
                    'value' => $binary ? (string) ($partValue['value'] ?? '') : $this->partText($partValue, $contentType),
                    'encoding' => $binary ? 'base64' : 'text',
                    'contentType' => $contentType,
                    'headers' => $headers,
                ];
            }, $items));
        }

        return ['mediaType' => $mediaType, 'encoding' => 'multipart', 'boundary' => $boundary, 'parts' => $parts];
    }

    /**
     * The same reading {@see multipartProperty()} generates by: a binary
     * part carries bytes whatever its declared type says.
     *
     * @param array<string, mixed> $schema
     */
    private function isBinaryPart(array $schema): bool
    {
        return ($schema['format'] ?? null) === 'binary' && (($schema['type'] ?? null) === 'string' || !array_key_exists('type', $schema));
    }

    private function isWritablePartType(string $contentType): bool
    {
        $normalized = MediaType::normalize($contentType);

        return MediaType::isJson($normalized) || str_starts_with($normalized, 'text/') || $normalized === 'application/octet-stream';
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
     * not a string (#122). Any other media type was refused when the
     * operation was compiled ({@see partPlans()}).
     */
    private function partText(mixed $value, string $contentType): string
    {
        if (MediaType::isJson(MediaType::normalize($contentType))) {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return $this->scalar($value);
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

    /**
     * The value of every header a part requires. The contract checks a
     * present part header against its schema (openapi-contract reads it as a
     * `simple` header parameter), so the value is one the schema admits: the
     * document's `example`, then its `default`, then the literal `x-openapi`,
     * whichever the contract accepts first; past those, a value drawn from
     * the schema itself at compile time. A header with no value either way
     * is refused (#157). A header without a schema is not checked, and keeps
     * the first candidate as before.
     *
     * @return array<string, string>
     */
    private function multipartHeaders(string $property, mixed $headers, SchemaDialect $dialect): array
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
            $value = $this->partHeaderValue($definition, $dialect)
                ?? throw UnsupportedGeneration::forSchema(sprintf('multipart property "%s" requires header "%s", and no value its schema admits can be generated', $property, $name));

            try {
                ParameterSerializer::assertTransmittableHeader($name, $value);
            } catch (InvalidCase $defect) {
                throw UnsupportedGeneration::forSchema(sprintf('multipart property "%s": %s', $property, $defect->getMessage()));
            }
            $result[$name] = $value;
        }

        return $result;
    }

    /** @param array<array-key, mixed> $definition */
    private function partHeaderValue(array $definition, SchemaDialect $dialect): ?string
    {
        $candidates = [...$this->scalarOf($definition['example'] ?? null), ...$this->scalarOf($definition['default'] ?? null), 'x-openapi'];
        $schema = $definition['schema'] ?? null;
        if (!is_array($schema) || ($schema !== [] && array_is_list($schema))) {
            return $this->scalar($candidates[0]);
        }
        /** @var array<string, mixed> $schema */
        $list = SchemaShape::isArray($schema);
        foreach ($candidates as $candidate) {
            $wire = $this->scalar($candidate);
            if (!$this->parameterSchemas->isHeaderSafe($wire, $list)) {
                continue;
            }
            foreach ([$candidate, $wire] as $typed) {
                if ($this->partHeaderAccepts($list ? [$typed] : $typed, $schema, $dialect)) {
                    return $wire;
                }
            }
        }
        if ($list || SchemaShape::isObject($schema)) {
            return null;
        }

        return $this->drawnHeaderValue($schema);
    }

    /** @return list<scalar> the value, when it is a scalar */
    private function scalarOf(mixed $value): array
    {
        return is_scalar($value) ? [$value] : [];
    }

    /** @param array<string, mixed> $schema */
    private function partHeaderAccepts(mixed $value, array $schema, SchemaDialect $dialect): bool
    {
        try {
            return $this->check->accepts($value, $schema, $dialect);
        } catch (InvalidContract) {
            return false;
        }
    }

    /**
     * A value drawn from a scalar header schema with the header rules a
     * request header parameter is generated by, at a fixed seed: the header
     * of every case is the same, as it was when it was always the literal.
     *
     * @param array<string, mixed> $schema
     */
    private function drawnHeaderValue(array $schema): ?string
    {
        $separator = ParameterSchemas::separatorOf('header', 'simple', $schema);

        try {
            $compiled = $this->compilerFor($separator)->compile($this->parameterSchemas->forLocation($schema, 'header', 'simple'));
        } catch (UnsupportedGeneration) {
            return null;
        }
        $compiled = Gen::filter($compiled, fn(mixed $value): bool => $this->parameterSchemas->isHeaderSafe($value));
        $random = new Random(self::PROBE_SEED);
        for ($probe = 0; $probe < self::PROBES; ++$probe) {
            try {
                return WireValue::of($compiled->generate($random)->value);
            } catch (GenerationExhaustedException) {
                continue;
            }
        }

        return null;
    }

    private function assertFormEncoding(mixed $encoding): void
    {
        if ($encoding === null || $encoding === []) {
            return;
        }
        if (!is_array($encoding) || array_is_list($encoding)) {
            throw UnsupportedGeneration::forSchema('Form encoding must be an object');
        }
        foreach ($encoding as $name => $configuration) {
            if (!is_string($name) || !is_array($configuration) || array_is_list($configuration)) {
                throw UnsupportedGeneration::forSchema('Form encoding entries must be objects');
            }
            if (($configuration['style'] ?? 'form') !== 'form' || (isset($configuration['explode']) && !is_bool($configuration['explode']))) {
                throw UnsupportedGeneration::forSchema('Form encoding supports only form style and boolean explode');
            }
        }
    }

    private function assertMultipartEncoding(mixed $encoding): void
    {
        if ($encoding === null || $encoding === []) {
            return;
        }
        if (!is_array($encoding) || array_is_list($encoding)) {
            throw UnsupportedGeneration::forSchema('Multipart encoding must be an object');
        }
        foreach ($encoding as $name => $configuration) {
            if (!is_string($name) || !is_array($configuration) || array_is_list($configuration) || ($configuration['style'] ?? 'form') !== 'form') {
                throw UnsupportedGeneration::forSchema('Multipart encoding supports only form style');
            }
        }
    }

    /**
     * A parameter value travels as text: a list of scalars or an object of
     * scalars at most. Whether a schema nests deeper is a fact of the
     * document, so it is refused when the operation is compiled, not on the
     * draw that first produces a nested value (#156).
     *
     * @param array<string, mixed> $schema
     */
    private function assertFlatParameter(array $schema): void
    {
        $nested = match (true) {
            SchemaShape::isArray($schema) => $this->isNested($schema['items'] ?? null) || $this->anyNested($schema['prefixItems'] ?? null),
            SchemaShape::isObject($schema) => $this->anyNested($schema['properties'] ?? null)
                || $this->isNested($schema['additionalProperties'] ?? null)
                || $this->anyNested($schema['patternProperties'] ?? null),
            default => false,
        };
        if ($nested) {
            throw UnsupportedGeneration::forSchema('Parameter values must be scalar, arrays, or objects with scalar properties');
        }
    }

    private function anyNested(mixed $members): bool
    {
        if (!is_array($members)) {
            return false;
        }
        foreach (array_keys($members) as $key) {
            if ($this->isNested($members[$key])) {
                return true;
            }
        }

        return false;
    }

    private function isNested(mixed $member): bool
    {
        if (!is_array($member) || ($member !== [] && array_is_list($member))) {
            return false;
        }
        /** @var array<string, mixed> $member */

        return SchemaShape::isArray($member) || SchemaShape::isObject($member);
    }

    /** @param array<string, mixed> $schema */
    private function assertObjectSchema(array $schema, string $message): void
    {
        if (!SchemaShape::isObject($schema)) {
            throw UnsupportedGeneration::forSchema($message);
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
            ?? throw UnsupportedGeneration::forSchema('Parameter values must be scalar, arrays, or objects with scalar properties');
    }


}
