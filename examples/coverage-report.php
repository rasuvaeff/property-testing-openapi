<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\CallableTransport;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\CoverageIncomplete;
use Rasuvaeff\PropertyTesting\OpenApi\OperationCoverage;
use Rasuvaeff\PropertyTesting\Random;

$contract = Contract::fromArray([
    'openapi' => '3.1.0',
    'paths' => [
        '/pets' => [
            'get' => ['operationId' => 'pets.list', 'responses' => ['204' => []]],
        ],
        '/pets/{id}' => [
            'get' => [
                'operationId' => 'pets.get',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]],
                ],
                'responses' => ['204' => []],
            ],
        ],
    ],
]);
$factory = new Psr17Factory();
$coverage = new OperationCoverage();

$suite = ContractSuite::fromContract($contract, $factory, $factory)
    ->allSafeOperations()
    ->coverage($coverage)
    ->transport(new CallableTransport(static fn(): Response => new Response(204)));

foreach ([1, 2, 3] as $seed) {
    $suite->checkValid('pets.get', $suite->validCases('pets.get')->generate(new Random($seed))->value);
}

$report = $suite->coverageReport();
echo $report->toJson() . PHP_EOL;

try {
    $report->assertComplete();
} catch (CoverageIncomplete $incomplete) {
    echo $incomplete->getMessage() . PHP_EOL;
}
