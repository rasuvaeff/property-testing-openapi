<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\Psr15Transport;
use Rasuvaeff\PropertyTesting\Random;

$contract = Contract::fromArray([
    'openapi' => '3.1.0',
    'paths' => [
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
$handler = new class ($contract) implements RequestHandlerInterface {
    public function __construct(
        private readonly Contract $contract,
    ) {}

    #[\Override]
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        return $this->contract->validateRequest($request)->isValid() ? new Response(204) : new Response(400);
    }
};
$resets = 0;

$suite = ContractSuite::fromContract($contract, $factory, $factory)
    ->operations(['pets.get'])
    ->transport(new Psr15Transport(
        $handler,
        $factory,
        afterRequest: static function () use (&$resets): void {
            ++$resets;
        },
    ));

foreach ([1, 2, 3] as $seed) {
    $suite->checkValid('pets.get', $suite->validCases('pets.get')->generate(new Random($seed))->value);
}

$negative = $suite->negativeCases('pets.get')->generate(new Random(5))->value;
$suite->checkNegative('pets.get', $negative);

echo 'valid trials conformed; negative case (' . $negative['misuse']['kind'] . ') was rejected without a 5xx; state resets: ' . $resets . PHP_EOL;
