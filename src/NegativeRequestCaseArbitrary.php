<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi;

use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Gen;

/**
 * Produces invalid request cases by removing one required request component.
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
     *     misuse: array{kind: 'missing-required', location: 'path'|'query'|'header'|'cookie'|'body', name: string},
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
}
