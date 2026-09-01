<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\Support;

use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;

/**
 * Multi-host server fixtures shared between property bodies and their
 * generator providers.
 */
final class ServerContracts
{
    public static function multiHost(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'servers' => [['url' => 'https://a.example.com/v{v}', 'variables' => ['v' => ['default' => '1']]], ['url' => 'https://b.example.com/v1']],
            'paths' => [
                '/pets/{id}' => [
                    'get' => [
                        'operationId' => 'pets.get',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 999]],
                            ['name' => 'verbose', 'in' => 'query', 'schema' => ['type' => 'boolean']],
                        ],
                        'responses' => ['200' => []],
                    ],
                ],
            ],
        ]);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function multiHostCase(): array
    {
        return ['case' => (new RequestCaseArbitrary())->forOperation(self::multiHost()->operation('pets.get'))];
    }
}
