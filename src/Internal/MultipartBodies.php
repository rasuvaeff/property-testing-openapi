<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Internal;

use Rasuvaeff\OpenApiContract\InvalidContract;
use Rasuvaeff\OpenApiContract\SchemaCheck;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\InvalidCase;
use Rasuvaeff\PropertyTesting\OpenApi\SchemaArbitraryCompiler;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;

/**
 * Valid `multipart/*` request bodies: the values of an object schema written
 * as parts, each with the content type and the required headers its
 * `encoding` entry declares. Parts are scalar or binary; a nested object or
 * an array of containers is refused.
 *
 * Every refusal is raised while the operation is compiled, not on the draws
 * that happen to include a part (#156): the content type and the headers of a
 * part are document facts.
 *
 * @internal
 *
 * @psalm-import-type Schema from SchemaShape
 */
final readonly class MultipartBodies
{
    /** The body compiler: a request never carries a `readOnly` member. */
    private SchemaArbitraryCompiler $bodySchemas;

    private SchemaArbitraryCompiler $schemas;

    private ParameterSchemas $parameterSchemas;

    private SchemaCheck $check;

    public function __construct()
    {
        $this->check = new SchemaCheck();
        $this->schemas = new SchemaArbitraryCompiler();
        $this->bodySchemas = new SchemaArbitraryCompiler(direction: SchemaDirection::Request);
        $this->parameterSchemas = new ParameterSchemas();
    }

    /**
     * @param Schema $schema the media type's schema, already narrowed to the request
     * @param array<string, mixed> $definition the Media Type Object
     * @return ArbitraryInterface<mixed> a `BodyData` of `encoding: 'multipart'`
     */
    public function arbitrary(string $mediaType, array $schema, array $definition, SchemaDialect $dialect): ArbitraryInterface
    {
        if (!SchemaShape::isObject($schema)) {
            throw UnsupportedGeneration::forSchema('Multipart request body schema must be an object');
        }
        $this->assertEncoding($definition['encoding'] ?? []);
        $values = $this->values($schema);
        $plans = $this->partPlans($schema, $definition, $dialect);
        /** @var ArbitraryInterface<mixed> $multipart */
        $multipart = Gen::map($values, fn(array $value): array => $this->body($mediaType, $plans, $value));

        return $multipart;
    }

    /** @param Schema $schema */
    private function values(array $schema): ArbitraryInterface
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
            $value = $this->property($required ? SchemaShape::nonEmpty($property) : $property);
            $shape[$name] = $required ? $value : Presence::optional($value);
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

    /** @param Schema $schema */
    private function property(array $schema): ArbitraryInterface
    {
        if ($this->isBinaryPart($schema)) {
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
            $item = $this->property($items);
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
     * @param Schema $schema
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
                : $this->defaultContentType($partSchema);

            try {
                ParameterSerializer::assertTransmittableHeader('Content-Type', $contentType);
            } catch (InvalidCase $defect) {
                throw UnsupportedGeneration::forSchema(sprintf('multipart property "%s": %s', (string) $name, $defect->getMessage()));
            }
            if (!$this->isBinaryPart($partSchema) && !$this->isWritablePartType($contentType)) {
                throw UnsupportedGeneration::forSchema(sprintf('Multipart property "%s" declares content type "%s", which this generator can write neither as text nor as JSON', (string) $name, $contentType));
            }
            $plans[$name] = ['contentType' => $contentType, 'headers' => $this->headers((string) $name, $configuration['headers'] ?? [], $dialect)];
        }

        return $plans;
    }

    /**
     * @param array<array-key, array{contentType: string, headers: array<string, string>}> $plans
     * @param array<array-key, mixed> $value
     */
    private function body(string $mediaType, array $plans, array $value): array
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
     * The same reading {@see property()} generates by: a binary part carries
     * bytes whatever its declared type says.
     *
     * @param Schema $schema
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
     * @param Schema $schema
     */
    private function defaultContentType(array $schema): string
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
    private function headers(string $property, mixed $headers, SchemaDialect $dialect): array
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
            $value = $this->headerValue($definition, $dialect)
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
    private function headerValue(array $definition, SchemaDialect $dialect): ?string
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
                if ($this->headerAccepts($list ? [$typed] : $typed, $schema, $dialect)) {
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

    /** @param Schema $schema */
    private function headerAccepts(mixed $value, array $schema, SchemaDialect $dialect): bool
    {
        try {
            return $this->check->accepts($value, $schema, $dialect);
        } catch (InvalidContract) {
            return false;
        }
    }

    /**
     * A value drawn from a scalar header schema with the header rules a
     * request header parameter is generated by, at the probe's fixed seed:
     * the header of every case is the same, as it was when it was always the
     * literal.
     *
     * @param Schema $schema
     */
    private function drawnHeaderValue(array $schema): ?string
    {
        $separator = ParameterSchemas::separatorOf('header', 'simple', $schema);

        try {
            $compiler = $separator === null ? $this->schemas : new SchemaArbitraryCompiler($separator);
            $compiled = $compiler->compile($this->parameterSchemas->forLocation($schema, 'header', 'simple'));
        } catch (UnsupportedGeneration) {
            return null;
        }
        $drawn = Probe::first(Gen::filter($compiled, fn(mixed $value): bool => $this->parameterSchemas->isHeaderSafe($value)));

        return $drawn === null ? null : WireValue::of($drawn['value']);
    }

    private function assertEncoding(mixed $encoding): void
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

    private function scalar(mixed $value): string
    {
        return WireValue::of($value)
            ?? throw UnsupportedGeneration::forSchema('Parameter values must be scalar, arrays, or objects with scalar properties');
    }
}
