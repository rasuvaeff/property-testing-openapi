<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Support;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseCaseArbitrary;

/**
 * Response fixtures shared between property bodies and their generator
 * providers.
 */
final class ResponseContracts
{
    public static function pets(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/pets/{id}' => ['get' => [
                    'operationId' => 'pets.get',
                    'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer']]],
                    'responses' => [
                        '200' => [
                            'description' => 'pet',
                            'headers' => [
                                'X-Doc' => ['description' => 'no schema, optional'],
                                'X-Trace' => ['schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8]],
                                'X-Rate-Limit' => ['required' => true, 'schema' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000]],
                                'X-Flag' => ['required' => true, 'schema' => ['type' => 'boolean']],
                                'X-Ids' => ['required' => true, 'schema' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 9]]],
                                'X-Tags' => ['schema' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 3], 'maxItems' => 3]],
                            ],
                            'content' => ['application/json' => ['schema' => [
                                'type' => 'object',
                                'required' => ['id', 'name', 'status', 'kind', 'tags'],
                                'additionalProperties' => false,
                                'properties' => [
                                    'id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                                    'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 20],
                                    'slug' => ['type' => 'string', 'pattern' => '^[a-z]{2,6}$'],
                                    'status' => ['type' => 'string', 'enum' => ['active', 'archived']],
                                    'kind' => ['type' => 'string', 'const' => 'pet'],
                                    'tags' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5]],
                                    'secret' => ['type' => 'string', 'writeOnly' => true],
                                    'owner' => ['type' => 'object', 'required' => ['name'], 'properties' => ['name' => ['type' => 'string', 'maxLength' => 10]]],
                                ],
                            ]]],
                        ],
                        '4XX' => ['description' => 'client error', 'content' => ['application/problem+json' => ['schema' => ['type' => 'object', 'required' => ['title'], 'properties' => ['title' => ['type' => 'string']]]]]],
                    ],
                ]],
                '/pets' => ['get' => [
                    'operationId' => 'pets.list',
                    'responses' => [
                        '200' => ['description' => 'list', 'content' => ['application/json' => ['schema' => ['type' => 'array', 'maxItems' => 4, 'items' => ['type' => 'object', 'required' => ['id'], 'properties' => ['id' => ['type' => 'integer', 'minimum' => 1]]]]]]],
                        'default' => ['description' => 'anything'],
                    ],
                ]],
                '/pets/count' => ['get' => [
                    'operationId' => 'pets.count',
                    'responses' => ['200' => ['description' => 'count', 'content' => ['application/json' => ['schema' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99]]]]],
                ]],
                '/ping' => ['get' => [
                    'operationId' => 'ping',
                    'responses' => ['204' => ['description' => 'empty'], '503' => ['description' => 'text', 'content' => ['text/plain' => ['schema' => ['type' => 'string']]]]],
                ]],
            ],
        ]);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function petCase(): array
    {
        return ['case' => (new ResponseCaseArbitrary())->forOperation(self::pets()->operation('pets.get'), 200)];
    }
}
