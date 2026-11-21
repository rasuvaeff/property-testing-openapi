<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\SchemaDialect;
use Rasuvaeff\OpenApiContract\SchemaDirection;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\FormBodies;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MediaType;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MultipartBodies;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Presence;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Probe;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\RawBodies;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\RequestSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\SchemaShape;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\WireValue;

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
    private SchemaArbitraryCompiler $schemas;

    /** The body compiler: a request never carries a `readOnly` member. */
    private SchemaArbitraryCompiler $bodySchemas;

    private ParameterSchemas $parameterSchemas;

    private RequestSchemas $requestSchemas;

    private FormBodies $forms;

    private MultipartBodies $multiparts;

    public function __construct()
    {
        $this->forms = new FormBodies();
        $this->multiparts = new MultipartBodies();
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
                $compiled = $this->compilerFor($separator)->compile($parameter['required'] ? SchemaShape::nonEmpty($schema) : $schema);
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
                ? Presence::included($value)
                : Presence::optional($value);
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
            return $this->forms->arbitrary($mediaType, $schema, $definition);
        }
        if (str_starts_with($normalized, 'multipart/')) {
            return $this->multiparts->arbitrary($mediaType, $schema, $definition, $dialect);
        }

        return null;
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
