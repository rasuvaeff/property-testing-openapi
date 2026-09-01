<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\RequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\CallableTransport;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\OperationProperty;
use Rasuvaeff\PropertyTesting\OpenApi\OperationPropertyFailed;

// The document describes the one id the server chokes on: a point fault that
// random generation over 1..1000 would need hundreds of runs to hit.
$contract = Contract::fromArray([
    'openapi' => '3.1.0',
    'paths' => [
        '/pets/{id}' => [
            'get' => [
                'operationId' => 'pets.get',
                'parameters' => [
                    [
                        'name' => 'id',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000],
                        'examples' => ['first' => ['value' => 1], 'legacy' => ['value' => 7]],
                    ],
                    ['name' => 'verbose', 'in' => 'query', 'schema' => ['type' => 'boolean'], 'example' => true],
                ],
                'responses' => ['204' => []],
            ],
        ],
    ],
]);
$factory = new Psr17Factory();
$suite = ContractSuite::fromContract($contract, $factory, $factory)
    ->operations(['pets.get'])
    ->transport(new CallableTransport(
        static fn(RequestInterface $request): Response => $request->getUri()->getPath() === '/pets/7' ? new Response(500) : new Response(204),
    ));

echo 'document examples: ' . implode(', ', array_keys($suite->exampleCases('pets.get'))) . PHP_EOL;

try {
    OperationProperty::check($suite, 'pets.get', runs: 5, seed: 42);
    echo 'unexpected: the point fault was not found' . PHP_EOL;
    exit(1);
} catch (OperationPropertyFailed $failure) {
    echo 'found by example "' . $failure->example . '" after ' . $failure->counterExample->runsBeforeFailure . ' random run(s)' . PHP_EOL;
    echo strtok($failure->getMessage(), "\n") . PHP_EOL;
}
