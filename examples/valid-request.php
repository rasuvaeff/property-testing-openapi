<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\Random;

$contract = Contract::fromArray([
    'openapi' => '3.1.0',
    'paths' => [
        '/pets/{id}' => [
            'get' => [
                'operationId' => 'pets.get',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]],
                    ['name' => 'tag', 'in' => 'query', 'schema' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8]],
                ],
                'responses' => ['200' => []],
            ],
        ],
    ],
]);
$operation = $contract->operation('pets.get');
$factory = new Psr17Factory();
$case = (new RequestCaseArbitrary())->forOperation($operation)->generate(new Random(42))->value;
$request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

$contract->validateRequest($request)->assertValid();

echo $request->getMethod() . ' ' . (string) $request->getUri() . PHP_EOL;
