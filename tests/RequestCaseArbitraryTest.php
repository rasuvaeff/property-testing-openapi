<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RequestCaseArbitrary::class)]
#[Covers(NegativeRequestCaseArbitrary::class)]
#[Covers(RequestMaterializer::class)]
final class RequestCaseArbitraryTest
{
    #[Property(runs: 100)]
    public function generatedCaseMaterializesToAValidRequest(array $case): void
    {
        $contract = self::contract();
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($contract->operation('pets.update'), $case);

        Classify::cover(condition: array_key_exists('tags', $case['query']), label: 'optional query present', minPercent: 20.0);
        Classify::cover(condition: !array_key_exists('tags', $case['query']), label: 'optional query absent', minPercent: 20.0);
        Classify::cover(condition: $case['body'] !== null, label: 'optional body present', minPercent: 20.0);
        Classify::cover(condition: $case['body'] === null, label: 'optional body absent', minPercent: 20.0);
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
                            'required' => false,
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

    public function missingRequiredComponentIsInvalidBeforeTransport(): void
    {
        $contract = self::contract();
        $operation = $contract->operation('pets.update');
        $case = (new NegativeRequestCaseArbitrary())->forOperation($operation)->generate(new Random(11))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'missing-required', 'location' => 'path', 'name' => 'id']);
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function rejectsOperationsWithoutARequiredComponent(): void
    {
        Expect::exception(UnsupportedGeneration::class);
        $operation = new Operation(
            key: 'optional',
            operationId: 'optional',
            method: 'GET',
            path: '/optional',
        );

        (new NegativeRequestCaseArbitrary())->forOperation($operation);
    }

    public function removesARequiredBodyWhenNoParameterExists(): void
    {
        $operation = new Operation(
            key: 'body.required',
            operationId: 'body.required',
            method: 'POST',
            path: '/body',
            requestBody: [
                'required' => true,
                'content' => ['application/json' => ['schema' => ['type' => 'string']]],
            ],
        );
        $case = (new NegativeRequestCaseArbitrary())->forOperation($operation)->generate(new Random(19))->value;

        Assert::same($case['misuse'], ['kind' => 'missing-required', 'location' => 'body', 'name' => 'body']);
        Assert::null($case['body']);
    }
}
