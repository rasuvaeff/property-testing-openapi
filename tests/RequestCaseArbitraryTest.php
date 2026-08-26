<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\Property;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RequestCaseArbitrary::class)]
#[Covers(RequestMaterializer::class)]
final class RequestCaseArbitraryTest
{
    #[Property(runs: 100)]
    public function generatedCaseMaterializesToAValidRequest(array $case): void
    {
        $contract = self::contract();
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($contract->operation('pets.update'), $case);

        Assert::true($contract->validateRequest($request)->isValid());
    }

    /** @return array<string, ArbitraryInterface> */
    public static function generatedCaseMaterializesToAValidRequestGenerators(): array
    {
        $contract = self::contract();

        return ['case' => (new RequestCaseArbitrary())->forOperation($contract->operation('pets.update'))];
    }

    private static function contract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/pets/{id}' => [
                    'post' => [
                        'operationId' => 'pets.update',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                            ['name' => 'tags', 'in' => 'query', 'style' => 'form', 'explode' => true, 'schema' => ['type' => 'array', 'minItems' => 1, 'maxItems' => 3, 'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 6]]],
                            ['name' => 'filter', 'in' => 'query', 'style' => 'deepObject', 'schema' => ['type' => 'object', 'properties' => ['state' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5]]]],
                            ['name' => 'X-Tenant', 'in' => 'header', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5]],
                            ['name' => 'session', 'in' => 'cookie', 'required' => true, 'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 5]],
                        ],
                        'requestBody' => [
                            'required' => true,
                            'content' => [
                                'application/json' => [
                                    'schema' => [
                                        'type' => 'object',
                                        'required' => ['name', 'active'],
                                        'properties' => [
                                            'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 12],
                                            'active' => ['type' => 'boolean'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        'responses' => ['204' => []],
                    ],
                ],
            ],
        ]);
    }
}
