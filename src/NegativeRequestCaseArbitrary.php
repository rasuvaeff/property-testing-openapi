<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

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
    public function __construct(
        private RequestCaseArbitrary $valid = new RequestCaseArbitrary(),
    ) {}

    /**
     * @return ArbitraryInterface<array{
     *     operationKey: string,
     *     path: array<string, string|list<string>|array<string, string>>,
     *     query: array<string, string|list<string>|array<string, string>>,
     *     headers: array<string, string|list<string>|array<string, string>>,
     *     cookies: array<string, string|list<string>|array<string, string>>,
     *     body: null|array{mediaType: string, encoding: 'json', value: mixed},
     *     misuse: array{kind: 'missing-required'|'type', location: 'path'|'query'|'header'|'cookie'|'body', name: string},
     * }>
     */
    public function forOperation(Operation $operation): ArbitraryInterface
    {
        $target = $this->target($operation);

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
        $target = $this->typeTarget($operation);

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
     * @return array{location: 'path'|'query'|'header'|'cookie'|'body', name: string}
     */
    private function target(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if ($parameter['required']) {
                return ['location' => $parameter['in'], 'name' => $parameter['name']];
            }
        }
        if (($operation->requestBody['required'] ?? false) === true) {
            return ['location' => 'body', 'name' => 'body'];
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required request component to invalidate', $operation->key));
    }

    /**
     * @return array{location: 'path'|'query'|'header'|'cookie', name: string, invalid: string}
     */
    private function typeTarget(Operation $operation): array
    {
        foreach ($operation->parameters as $parameter) {
            if (!$parameter['required']) {
                continue;
            }
            /** @var mixed $type */
            $type = $parameter['schema']['type'] ?? null;
            $types = is_array($type) ? $type : [$type];
            if (in_array('integer', $types, true)) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => 'not-an-integer'];
            }
            if (in_array('number', $types, true)) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => 'not-a-number'];
            }
            if (in_array('boolean', $types, true)) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => 'not-a-boolean'];
            }
            if (in_array('null', $types, true)) {
                return ['location' => $parameter['in'], 'name' => $parameter['name'], 'invalid' => 'not-null'];
            }
        }

        throw new UnsupportedGeneration(sprintf('Operation "%s" has no required scalar parameter with a constructible type mismatch', $operation->key));
    }
}
