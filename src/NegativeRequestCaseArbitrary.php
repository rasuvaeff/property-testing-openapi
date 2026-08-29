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
 * @api
 */
final readonly class NegativeRequestCaseArbitrary
{
    private ParameterTargets $parameterTargets;

    private BodyTargets $bodyTargets;

    public function __construct(
        private RequestCaseArbitrary $valid = new RequestCaseArbitrary(),
    ) {
        $this->parameterTargets = new ParameterTargets();
        $this->bodyTargets = new BodyTargets();
    }

    /**
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'missing-required'|'type'|'enum'|'const'|'boundary'|'length'|'format'|'additional-properties'|'media-type'|'json-syntax', location: 'path'|'query'|'header'|'cookie'|'body', name: string},
     * }>
     */
    public function forOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->parameterTargets->missingRequired($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'missing-required', location: 'path'|'query'|'header'|'cookie'|'body', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'body') {
                $case['body'] = null;
            } elseif ($target['location'] === 'path') {
                unset($case['path'][$target['name']]);
            } elseif ($target['location'] === 'query') {
                unset($case['query'][$target['name']]);
            } elseif ($target['location'] === 'header') {
                unset($case['headers'][$target['name']]);
            } else {
                unset($case['cookies'][$target['name']]);
            }
            $case['misuse'] = [
                'kind' => 'missing-required',
                'location' => $target['location'],
                'name' => $target['name'],
            ];

            return $case;
        });
    }

    /**
     * Replaces one required scalar parameter with a wire value that cannot
     * satisfy its integer, number, boolean, or null schema type.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'type', location: 'path'|'query'|'header'|'cookie', name: string},
     * }>
     */
    public function typeMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->parameterTargets->typeMismatch($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'type', location: 'path'|'query'|'header'|'cookie', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'path') {
                $case['path'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'query') {
                $case['query'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'header') {
                $case['headers'][$target['name']] = $target['invalid'];
            } else {
                $case['cookies'][$target['name']] = $target['invalid'];
            }
            $case['misuse'] = [
                'kind' => 'type',
                'location' => $target['location'],
                'name' => $target['name'],
            ];

            return $case;
        });
    }

    /**
     * Replaces one required scalar parameter with a value absent from its
     * finite enum.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'enum', location: 'path'|'query'|'header'|'cookie', name: string},
     * }>
     */
    public function enumMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->parameterTargets->enumMismatch($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'enum', location: 'path'|'query'|'header'|'cookie', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'path') {
                $case['path'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'query') {
                $case['query'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'header') {
                $case['headers'][$target['name']] = $target['invalid'];
            } else {
                $case['cookies'][$target['name']] = $target['invalid'];
            }
            $case['misuse'] = [
                'kind' => 'enum',
                'location' => $target['location'],
                'name' => $target['name'],
            ];

            return $case;
        });
    }

    /**
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'const', location: 'path'|'query'|'header'|'cookie', name: string},
     * }>
     */
    public function constMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->parameterTargets->constMismatch($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'const', location: 'path'|'query'|'header'|'cookie', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'path') {
                $case['path'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'query') {
                $case['query'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'header') {
                $case['headers'][$target['name']] = $target['invalid'];
            } else {
                $case['cookies'][$target['name']] = $target['invalid'];
            }
            $case['misuse'] = ['kind' => 'const', 'location' => $target['location'], 'name' => $target['name']];

            return $case;
        });
    }

    /**
     * Replaces one required numeric parameter with a wire value just outside
     * its `minimum`/`maximum` bound, honouring boolean exclusive bounds.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'boundary', location: 'path'|'query'|'header'|'cookie', name: string},
     * }>
     */
    public function boundaryMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->parameterTargets->boundaryMismatch($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'boundary', location: 'path'|'query'|'header'|'cookie', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'path') {
                $case['path'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'query') {
                $case['query'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'header') {
                $case['headers'][$target['name']] = $target['invalid'];
            } else {
                $case['cookies'][$target['name']] = $target['invalid'];
            }
            $case['misuse'] = ['kind' => 'boundary', 'location' => $target['location'], 'name' => $target['name']];

            return $case;
        });
    }






    /**
     * Replaces one required string parameter with a wire value whose length
     * falls just outside its `minLength`/`maxLength` bound.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'length', location: 'path'|'query'|'header'|'cookie', name: string},
     * }>
     */
    public function lengthMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->parameterTargets->lengthMismatch($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'length', location: 'path'|'query'|'header'|'cookie', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'path') {
                $case['path'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'query') {
                $case['query'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'header') {
                $case['headers'][$target['name']] = $target['invalid'];
            } else {
                $case['cookies'][$target['name']] = $target['invalid'];
            }
            $case['misuse'] = ['kind' => 'length', 'location' => $target['location'], 'name' => $target['name']];

            return $case;
        });
    }

    /**
     * Replaces one required string parameter with a wire value that provably
     * violates its asserted `format`.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'format', location: 'path'|'query'|'header'|'cookie', name: string},
     * }>
     */
    public function formatMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->parameterTargets->formatMismatch($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'format', location: 'path'|'query'|'header'|'cookie', name: string},
         * }
         */ static function (array $case) use ($target): array {
            if ($target['location'] === 'path') {
                $case['path'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'query') {
                $case['query'][$target['name']] = $target['invalid'];
            } elseif ($target['location'] === 'header') {
                $case['headers'][$target['name']] = $target['invalid'];
            } else {
                $case['cookies'][$target['name']] = $target['invalid'];
            }
            $case['misuse'] = ['kind' => 'format', 'location' => $target['location'], 'name' => $target['name']];

            return $case;
        });
    }





    /**
     * Adds one undeclared property to a required JSON object body whose schema
     * sets `additionalProperties: false`.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'additional-properties', location: 'body', name: string},
     * }>
     */
    public function additionalPropertyForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->bodyTargets->additionalProperty($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'additional-properties', location: 'body', name: string},
         * }
         */ static function (array $case) use ($target): array {
            $body = $case['body'];
            if ($body === null || !is_array($body['value'])) {
                throw new \LogicException('Required JSON object body expected for an additional property misuse');
            }
            $body['value'][$target['name']] = true;
            $case['body'] = $body;
            $case['misuse'] = ['kind' => 'additional-properties', 'location' => 'body', 'name' => $target['name']];

            return $case;
        });
    }

    /**
     * Keeps the schema-valid JSON body but sends it under an undeclared
     * Content-Type, so the media type is the only deviation.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'media-type', location: 'body', name: string},
     * }>
     */
    public function mediaTypeMismatchForOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->bodyTargets->mediaTypeMismatch($operation);

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: array{kind: 'media-type', location: 'body', name: string},
         * }
         */ static function (array $case) use ($target): array {
            $body = $case['body'];
            if ($body === null) {
                throw new \LogicException('Required JSON body expected for a media type misuse');
            }
            $body['mediaType'] = $target['invalid'];
            $case['body'] = $body;
            $case['misuse'] = ['kind' => 'media-type', 'location' => 'body', 'name' => 'body'];

            return $case;
        });
    }

    /**
     * Replaces the required JSON body with a deliberately malformed raw JSON
     * payload under the declared media type.
     *
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: array{mediaType: string, encoding: 'raw', value: string},
     *     misuse: array{kind: 'json-syntax', location: 'body', name: string},
     * }>
     */
    public function malformedJsonForOperation(Operation $operation): ArbitraryInterface
    {
        $body = $this->bodyTargets->jsonBody($operation);
        if ($body === null) {
            throw new UnsupportedGeneration(sprintf('Operation "%s" has no required JSON body for a malformed JSON case', $operation->key));
        }
        $mediaType = $body['mediaType'];

        return Gen::map($this->valid->forOperation($operation), /**
         * @param array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
         *     misuse: null,
         * } $case
         * @return array{
         *     operationKey: string,
         *     path: array<string, string|list<string>|array<string, string>>,
         *     query: array<string, string|list<string>|array<string, string>>,
         *     headers: array<string, string|list<string>|array<string, string>>,
         *     cookies: array<string, string|list<string>|array<string, string>>,
         *     body: array{mediaType: string, encoding: 'raw', value: string},
         *     misuse: array{kind: 'json-syntax', location: 'body', name: string},
         * }
         */ static function (array $case) use ($mediaType): array {
            $case['body'] = ['mediaType' => $mediaType, 'encoding' => 'raw', 'value' => '{"malformed":'];
            $case['misuse'] = ['kind' => 'json-syntax', 'location' => 'body', 'name' => 'body'];

            return $case;
        });
    }










}
