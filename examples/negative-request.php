<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\Random;

$contract = Contract::fromArray([
    'openapi' => '3.1.0',
    'paths' => [
        '/pets/{id}' => [
            'post' => [
                'operationId' => 'pets.update',
                'parameters' => [
                    ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                    ['name' => 'trace', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string', 'format' => 'uuid']],
                ],
                'requestBody' => [
                    'required' => true,
                    'content' => [
                        'application/json' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['name'],
                                'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8]],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ],
                'responses' => ['204' => []],
            ],
        ],
    ],
]);
$operation = $contract->operation('pets.update');
$factory = new Psr17Factory();
$materializer = new RequestMaterializer($factory, $factory);
$negative = new NegativeRequestCaseArbitrary();

$failures = 0;
foreach ([
    $negative->forOperation($operation),
    $negative->typeMismatchForOperation($operation),
    $negative->boundaryMismatchForOperation($operation),
    $negative->formatMismatchForOperation($operation),
    $negative->additionalPropertyForOperation($operation),
    $negative->mediaTypeMismatchForOperation($operation),
    $negative->malformedJsonForOperation($operation),
] as $arbitrary) {
    $case = $arbitrary->generate(new Random(7))->value;
    $request = $materializer->materialize($operation, $case);
    $rejected = !$contract->validateRequest($request)->isValid();
    $misuse = $case['misuse'];

    echo sprintf(
        "%-21s at %s %s: %s\n",
        $misuse['kind'],
        $misuse['location'],
        $misuse['name'],
        $rejected ? 'rejected before transport' : 'UNEXPECTEDLY ACCEPTED',
    );
    if (!$rejected) {
        ++$failures;
    }
}

exit($failures === 0 ? 0 : 1);
