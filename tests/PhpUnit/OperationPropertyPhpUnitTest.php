<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests\PhpUnit;

use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\PropertyTesting\OpenApi\CallableTransport;
use Rasuvaeff\PropertyTesting\OpenApi\ContractSuite;
use Rasuvaeff\PropertyTesting\OpenApi\OpenApiOperations;
use Rasuvaeff\PropertyTesting\OpenApi\OperationProperty;
use Rasuvaeff\PropertyTesting\OpenApi\OperationPropertyFailed;

/**
 * PHPUnit-parity fixture: the same framework-neutral runner surface that the
 * Testo suite exercises must work unchanged under PHPUnit — a plain static
 * data provider enumerates the operations and a plain call runs the property.
 */
#[CoversNothing]
final class OperationPropertyPhpUnitTest extends TestCase
{
    #[DataProvider('operations')]
    public function testOperationConforms(string $operationKey): void
    {
        OperationProperty::check(self::suite(conforming: true), $operationKey, runs: 10, seed: 17);

        $this->addToAssertionCount(1);
    }

    public function testAFalsifiedOperationSurfacesAsATestFailure(): void
    {
        $this->expectException(OperationPropertyFailed::class);
        $this->expectExceptionMessage('failed the valid phase');

        OperationProperty::check(self::suite(conforming: false), 'pets.get', runs: 5, seed: 17);
    }

    /** @return iterable<string, array{string}> */
    public static function operations(): iterable
    {
        return OpenApiOperations::keys(self::suite(conforming: true));
    }

    private static function suite(bool $conforming): ContractSuite
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => [
                '/pets/{id}' => [
                    'get' => [
                        'operationId' => 'pets.get',
                        'parameters' => [
                            ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10]],
                        ],
                        'responses' => ['204' => [], '400' => []],
                    ],
                ],
                '/pets' => [
                    'get' => [
                        'operationId' => 'pets.list',
                        'parameters' => [
                            ['name' => 'limit', 'in' => 'query', 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100]],
                        ],
                        'responses' => ['204' => [], '400' => []],
                    ],
                ],
            ],
        ]);
        $factory = new Psr17Factory();
        $transport = new CallableTransport(static function (RequestInterface $request) use ($contract, $conforming): Response {
            if (!$conforming) {
                return new Response(500);
            }

            return $contract->validateRequest($request)->isValid() ? new Response(204) : new Response(400);
        });

        return ContractSuite::fromContract($contract, $factory, $factory)
            ->allSafeOperations()
            ->transport($transport);
    }
}
