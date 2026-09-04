<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\BodyTargets;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\ParameterTargets;

/**
 * Produces invalid request cases through constructive, corpus-safe mutations.
 *
 * The generated value remains corpus-safe; `misuse` identifies the deliberate
 * invalidation and is never interpreted as a secret or a PSR-7 object.
 *
 * @psalm-import-type RequestCaseData from RequestCaseArbitrary
 * @psalm-type NegativeRequestCaseData = array{
 *     operationKey: string,
 *     path: array<string, string|list<string>|array<string, string>>,
 *     query: array<string, string|list<string>|array<string, string>>,
 *     headers: array<string, string|list<string>|array<string, string>>,
 *     cookies: array<string, string|list<string>|array<string, string>>,
 *     body: null|array{boundary?: string, encoding: 'form'|'json'|'multipart'|'raw', mediaType: string, parts?: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>, value?: mixed},
 *     misuse: array{kind: 'missing-required'|'type'|'enum'|'const'|'boundary'|'length'|'format'|'pattern'|'additional-properties'|'media-type'|'json-syntax', location: 'path'|'query'|'header'|'cookie'|'body', name: string},
 * }
 *
 * @api
 */
final readonly class NegativeRequestCaseArbitrary
{
    /**
     * The case key each parameter location writes to. Every misuse that
     * targets a parameter goes through this map, so a location can only be
     * mishandled in one place.
     */
    private const array CASE_KEYS = [
        'path' => 'path',
        'query' => 'query',
        'header' => 'headers',
        'cookie' => 'cookies',
    ];

    private ParameterTargets $parameterTargets;

    private BodyTargets $bodyTargets;

    public function __construct(
        private RequestCaseArbitrary $valid = new RequestCaseArbitrary(),
    ) {
        $this->parameterTargets = new ParameterTargets();
        $this->bodyTargets = new BodyTargets();
    }

    /**
     * Drops one required parameter, or the whole required body.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function forOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->parameterTargets->missingRequired($operation);
        $location = $target['location'];
        $name = $target['name'];

        return $this->mutate($operation, static function (array $case) use ($location, $name): array {
            if ($location === 'body') {
                $case['body'] = null;
            } else {
                unset($case[self::CASE_KEYS[$location]][$name]);
            }
            $case['misuse'] = ['kind' => 'missing-required', 'location' => $location, 'name' => $name];

            return $case;
        });
    }

    /**
     * Replaces one required scalar parameter with a wire value that cannot
     * satisfy its integer, number, boolean, or null schema type.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function typeMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('type', $operation, $this->parameterTargets->typeMismatch($operation));
    }

    /**
     * Replaces one required scalar parameter with a value absent from its
     * finite enum.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function enumMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('enum', $operation, $this->parameterTargets->enumMismatch($operation));
    }

    /**
     * Replaces one required scalar parameter with a value other than the
     * single one its `const` admits.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function constMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('const', $operation, $this->parameterTargets->constMismatch($operation));
    }

    /**
     * Replaces one required numeric parameter with a wire value just outside
     * its `minimum`/`maximum` bound, honouring boolean exclusive bounds.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function boundaryMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('boundary', $operation, $this->parameterTargets->boundaryMismatch($operation));
    }

    /**
     * Replaces one required string parameter with a wire value whose length
     * falls just outside its `minLength`/`maxLength` bound.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function lengthMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('length', $operation, $this->parameterTargets->lengthMismatch($operation));
    }

    /**
     * Replaces one required string parameter with a wire value that provably
     * violates its asserted `format`.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function formatMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('format', $operation, $this->parameterTargets->formatMismatch($operation));
    }

    /**
     * Replaces one required string parameter with a searched wire value that
     * provably fails its `pattern`; the pattern itself is the oracle, and an
     * exhausted search budget fails closed.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function patternMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        return $this->parameter('pattern', $operation, $this->parameterTargets->patternMismatch($operation));
    }

    /**
     * Adds one undeclared property to a required JSON object body whose schema
     * sets `additionalProperties: false`.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function additionalPropertyForOperation(Operation $operation): ArbitraryInterface
    {
        $name = $this->bodyTargets->additionalProperty($operation)['name'];

        return $this->mutate($operation, static function (array $case) use ($name): array {
            $body = $case['body'];
            $value = $body['value'] ?? null;
            if ($body === null || !is_array($value)) {
                throw new \LogicException('Required JSON object body expected for an additional property misuse');
            }
            $value[$name] = true;
            $case['body'] = ['mediaType' => $body['mediaType'], 'encoding' => 'json', 'value' => $value];
            $case['misuse'] = ['kind' => 'additional-properties', 'location' => 'body', 'name' => $name];

            return $case;
        });
    }

    /**
     * Keeps the schema-valid JSON body but sends it under an undeclared
     * Content-Type, so the media type is the only deviation.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function mediaTypeMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $mediaType = $this->bodyTargets->mediaTypeMismatch($operation)['invalid'];

        return $this->mutate($operation, static function (array $case) use ($mediaType): array {
            $body = $case['body'];
            if ($body === null) {
                throw new \LogicException('Required JSON body expected for a media type misuse');
            }
            $case['body'] = ['mediaType' => $mediaType, 'encoding' => 'json', 'value' => $body['value'] ?? null];
            $case['misuse'] = ['kind' => 'media-type', 'location' => 'body', 'name' => 'body'];

            return $case;
        });
    }

    /**
     * Replaces the required JSON body with a deliberately malformed raw JSON
     * payload under the declared media type.
     *
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    public function malformedJsonForOperation(Operation $operation): ArbitraryInterface
    {
        $body = $this->bodyTargets->jsonBody($operation);
        if ($body === null) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no required JSON body for a malformed JSON case', $operation->key));
        }
        $mediaType = $body['mediaType'];

        return $this->mutate($operation, static function (array $case) use ($mediaType): array {
            $case['body'] = ['mediaType' => $mediaType, 'encoding' => 'raw', 'value' => '{"malformed":'];
            $case['misuse'] = ['kind' => 'json-syntax', 'location' => 'body', 'name' => 'body'];

            return $case;
        });
    }

    /**
     * Writes one target's invalid wire value over the parameter it names.
     * Every parameter misuse differs only in the `kind` it records, so they
     * all come through here rather than restating the location handling.
     *
     * @param 'type'|'enum'|'const'|'boundary'|'length'|'format'|'pattern' $kind
     * @param array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string} $target
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    private function parameter(string $kind, Operation $operation, array $target): ArbitraryInterface
    {
        return $this->mutate($operation, static function (array $case) use ($kind, $target): array {
            $case[self::CASE_KEYS[$target['location']]][$target['name']] = $target['invalid'];
            $case['misuse'] = ['kind' => $kind, 'location' => $target['location'], 'name' => $target['name']];

            return $case;
        });
    }

    /**
     * @param \Closure(RequestCaseData): NegativeRequestCaseData $mutation
     * @return ArbitraryInterface<NegativeRequestCaseData>
     */
    private function mutate(Operation $operation, \Closure $mutation): ArbitraryInterface
    {
        /** @var ArbitraryInterface<NegativeRequestCaseData> $mutated */
        $mutated = Gen::map($this->valid->forOperation($operation), $mutation);

        return $mutated;
    }
}
