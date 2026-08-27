<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ParameterSerializer;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

#[Test]
#[Covers(RequestMaterializer::class)]
#[Covers(ParameterSerializer::class)]
final class RequestMaterializerTest
{
    public function serializesSupportedLocationsIntoAContractValidRequest(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/pets/{id}/{labels}' => [
                    'get' => [
                        'operationId' => 'pets.get',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'style' => 'matrix', 'explode' => false, 'schema' => ['type' => 'integer']],
                            ['name' => 'labels', 'in' => 'path', 'required' => true, 'style' => 'label', 'explode' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            ['name' => 'tags', 'in' => 'query', 'style' => 'pipeDelimited', 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            ['name' => 'filter', 'in' => 'query', 'style' => 'deepObject', 'schema' => ['type' => 'object', 'properties' => ['state' => ['type' => 'string']]]],
                            ['name' => 'X-Flags', 'in' => 'header', 'style' => 'simple', 'explode' => false, 'schema' => ['type' => 'array', 'items' => ['type' => 'string']]],
                            ['name' => 'session', 'in' => 'cookie', 'style' => 'form', 'schema' => ['type' => 'string']],
                        ],
                        'responses' => ['200' => []],
                    ],
                ],
            ],
        ]);
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($contract->operation('pets.get'), [
            'operationKey' => 'pets.get',
            'path' => ['id' => '42', 'labels' => ['small', 'friendly']],
            'query' => ['tags' => ['small', 'friendly'], 'filter' => ['state' => 'active']],
            'headers' => ['X-Flags' => ['a', 'b']],
            'cookies' => ['session' => 'abc'],
            'body' => null,
            'misuse' => null,
        ]);

        Assert::same($request->getUri()->getPath(), '/pets/;id=42/.small.friendly');
        Assert::same($request->getUri()->getQuery(), 'tags=small%7Cfriendly&filter%5Bstate%5D=active');
        Assert::same($request->getHeaderLine('X-Flags'), 'a,b');
        Assert::same($request->getHeaderLine('Cookie'), 'session=abc');
        Assert::true($contract->validateRequest($request)->isValid());
    }

    public function acceptsAnEmptyDeepObject(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets' => ['get' => [
                'operationId' => 'pets.list',
                'parameters' => [[
                    'name' => 'filter',
                    'in' => 'query',
                    'style' => 'deepObject',
                    'schema' => ['type' => 'object', 'properties' => ['state' => ['type' => 'string']]],
                ]],
                'responses' => ['200' => []],
            ]]],
        ]);
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($contract->operation('pets.list'), [
            'operationKey' => 'pets.list',
            'path' => [],
            'query' => ['filter' => []],
            'headers' => [],
            'cookies' => [],
            'body' => null,
            'misuse' => null,
        ]);

        Assert::same($request->getUri()->getQuery(), '');
        Assert::true($contract->validateRequest($request)->isValid());
    }
}
