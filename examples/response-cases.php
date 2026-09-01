<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseMaterializer;
use Rasuvaeff\PropertyTesting\Random;

$contract = Contract::fromArray([
    'openapi' => '3.1.0',
    'paths' => ['/payments/{id}' => ['get' => [
        'operationId' => 'payments.get',
        'parameters' => [['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']]],
        'responses' => ['200' => [
            'description' => 'payment',
            'content' => ['application/json' => ['schema' => [
                'type' => 'object',
                'required' => ['id', 'status', 'amount'],
                'additionalProperties' => false,
                'properties' => [
                    'id' => ['type' => 'string', 'minLength' => 1],
                    'status' => ['type' => 'string', 'enum' => ['requires_action', 'processing', 'succeeded']],
                    'amount' => ['type' => 'integer', 'minimum' => 1],
                    'client_secret' => ['type' => 'string'],
                ],
            ]]],
        ]],
    ]]],
]);
$operation = $contract->operation('payments.get');
$factory = new Psr17Factory();
$materializer = new ResponseMaterializer($factory, $factory);

// A synthetic but contract-valid provider response for the client under test.
$valid = (new ResponseCaseArbitrary())->forOperation($operation, 200)->generate(new Random(42))->value;
$response = $materializer->materialize($operation, $valid);
$contract->validateResponse('payments.get', $response)->assertValid();
echo 'valid: HTTP ', $response->getStatusCode(), ' ', (string) $response->getBody(), PHP_EOL;

// A provably invalid one: the client must fail closed instead of mapping it.
$invalid = (new NegativeResponseCaseArbitrary())->enumMismatchForOperation($operation, 200)->generate(new Random(7))->value;
$response = $materializer->materialize($operation, $invalid);
$result = $contract->validateResponse('payments.get', $response);
echo 'invalid (', $invalid['misuse']['kind'], ' on "', $invalid['misuse']['name'], '"): rejected by the contract: ', $result->isValid() ? 'NO' : 'yes', PHP_EOL;
