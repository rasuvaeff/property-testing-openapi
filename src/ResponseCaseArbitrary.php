<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\MediaType;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ResponseSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\WireValue;

/**
 * Produces valid, corpus-safe response cases for one compiled operation and
 * one concrete status: the Response Object is the one the contract resolves
 * the status to (exact code, then `NXX`, then `default`), required response
 * headers are always present, optional ones take both branches, and the body
 * is generated from the first JSON media type with `writeOnly` properties
 * left out. A status the document does not declare, a required header
 * without a schema, or a body without a JSON media type fail closed.
 *
 * @psalm-type ResponseCaseData = array{
 *     operationKey: string,
 *     status: int,
 *     headers: array<string, string|list<string>>,
 *     body: null|array{mediaType: string, encoding: 'json'|'raw', value: mixed},
 *     misuse: null|array{kind: non-empty-string, location: 'status'|'header'|'body', name: string},
 * }
 *
 * @api
 */
final readonly class ResponseCaseArbitrary
{
    private SchemaArbitraryCompiler $schemas;

    private ResponseSchemas $responseSchemas;

    private ParameterSchemas $parameterSchemas;

    public function __construct()
    {
        $this->schemas = new SchemaArbitraryCompiler();
        $this->responseSchemas = new ResponseSchemas();
        $this->parameterSchemas = new ParameterSchemas();
    }

    /**
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     status: int,
     *     headers: array<string, string|list<string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: null,
     * }>
     */
    public function forOperation(Operation $operation, int $status): ArbitraryInterface
    {
        $definition = $this->definition($operation, $status);
        $arbitrary = Gen::map(Gen::record([
            'headers' => $this->headers($definition),
            'body' => $this->body($definition),
        ]), static fn(array $parts): array => [
            'operationKey' => $operation->key,
            'status' => $status,
            'headers' => $parts['headers'],
            'body' => $parts['body'],
            'misuse' => null,
        ]);

        /** @var ArbitraryInterface<array{
         *     operationKey: string,
         *     status: int,
         *     headers: array<string, string|list<string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * }> $arbitrary */
        return $arbitrary;
    }

    /**
     * The JSON media type and response-direction schema the body of a status
     * is generated from, or `null` when the Response Object declares no
     * content.
     *
     * @return null|array{mediaType: non-empty-string, schema: array<string, mixed>}
     */
    public function jsonBody(Operation $operation, int $status): ?array
    {
        return $this->jsonMedia(
            $this->definition($operation, $status),
            sprintf('Response for status %d of operation "%s" declares no JSON media type', $status, $operation->key),
        );
    }

    /** @return array<string, mixed> */
    private function definition(Operation $operation, int $status): array
    {
        $selected = $operation->responseFor($status);
        if ($selected === null) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" declares no response for status %d', $operation->key, $status));
        }

        return $selected['definition'];
    }

    /** @param array<string, mixed> $definition */
    private function headers(array $definition): ArbitraryInterface
    {
        $headers = $definition['headers'] ?? [];
        if (!is_array($headers)) {
            throw new UnsupportedGeneration('Response headers must be an object');
        }
        $shape = [];
        foreach ($headers as $name => $header) {
            if (!is_string($name) || !is_array($header) || array_is_list($header)) {
                continue;
            }
            $required = ($header['required'] ?? false) === true;
            $schema = $header['schema'] ?? null;
            if (!is_array($schema) || array_is_list($schema)) {
                if ($required) {
                    throw new UnsupportedGeneration(sprintf('Required response header "%s" has no schema object', $name));
                }

                continue;
            }
            /** @var array<string, mixed> $schema */
            // Same wire, same rules as a request header: the rewrite narrows
            // the alphabet to what a field value may carry, and the filter
            // refuses what a `pattern` or a `format` can still put outside it.
            $separator = ParameterSchemas::separatorOf('header', 'simple', $schema);
            $compiled = (new SchemaArbitraryCompiler($separator ?? ''))
                ->compile($this->parameterSchemas->forLocation($schema, 'header', 'simple'));
            $compiled = Gen::filter($compiled, fn(mixed $value): bool => $this->parameterSchemas->isHeaderSafe($value));
            $value = Gen::map($compiled, fn(mixed $value): string|array => $this->headerValue($value, $name));
            // An optional header takes both branches; `null` stands for "absent"
            // because a present header always carries a string value.
            $shape[$name] = $required ? $value : Gen::nullable($value);
        }
        if ($shape === []) {
            return Gen::constant([]);
        }

        return Gen::map(Gen::record($shape), /** @param array<array-key, mixed> $values */ static function (array $values): array {
            $result = [];
            /** @var mixed $value */
            foreach ($values as $name => $value) {
                if (!is_string($name) || $value === null) {
                    continue;
                }
                if (!is_string($value) && !is_array($value)) {
                    throw new \LogicException('Generated response header has an invalid shape');
                }
                /** @var string|list<string> $value */
                $result[$name] = $value;
            }

            return $result;
        });
    }

    /** @return string|list<string> */
    private function headerValue(mixed $value, string $name): string|array
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                throw new UnsupportedGeneration(sprintf('Response header "%s" cannot carry an object value', $name));
            }

            return array_map(fn(mixed $item): string => $this->scalar($item, $name), $value);
        }

        return $this->scalar($value, $name);
    }

    private function scalar(mixed $value, string $name): string
    {
        return WireValue::of($value)
            ?? throw new UnsupportedGeneration(sprintf('Response header "%s" must carry scalar values', $name));
    }

    /** @param array<string, mixed> $definition */
    private function body(array $definition): ArbitraryInterface
    {
        $media = $this->jsonMedia($definition, 'Response content declares no JSON media type');
        if ($media === null) {
            return Gen::constant(null);
        }
        $mediaType = $media['mediaType'];

        return Gen::map($this->schemas->compile($media['schema']), static fn(mixed $value): array => [
            'mediaType' => $mediaType,
            'encoding' => 'json',
            'value' => $value,
        ]);
    }

    /**
     * The first JSON media type a Response Object declares, with its schema
     * already narrowed to the response direction; `null` when the Response
     * Object declares no content at all.
     *
     * Content that declares no JSON media type is a fail-closed error, and
     * the caller words it: one of them knows the operation and status, the
     * other has only the Response Object in hand.
     *
     * @param array<string, mixed> $definition
     * @param non-empty-string $missing
     * @return null|array{mediaType: non-empty-string, schema: array<string, mixed>}
     */
    private function jsonMedia(array $definition, string $missing): ?array
    {
        $content = $definition['content'] ?? null;
        if (!is_array($content) || $content === []) {
            return null;
        }
        foreach ($content as $mediaType => $mediaDefinition) {
            if (!is_string($mediaType) || $mediaType === '' || !is_array($mediaDefinition) || !MediaType::isJson($mediaType)) {
                continue;
            }
            $schema = $mediaDefinition['schema'] ?? [];
            if (!is_array($schema) || array_is_list($schema)) {
                throw new UnsupportedGeneration(sprintf('Response "%s" JSON schema must be an object', $mediaType));
            }
            /** @var array<string, mixed> $schema */

            return ['mediaType' => $mediaType, 'schema' => $this->responseSchemas->effective($schema)];
        }

        throw new UnsupportedGeneration($missing);
    }
}
