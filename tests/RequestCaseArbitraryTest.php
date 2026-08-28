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

    public function typeMismatchIsInvalidBeforeTransport(): void
    {
        $contract = self::contract();
        $operation = $contract->operation('pets.update');
        $case = (new NegativeRequestCaseArbitrary())->typeMismatchForOperation($operation)->generate(new Random(23))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'type', 'location' => 'path', 'name' => 'id']);
        Assert::string($request->getUri()->getPath())->contains('not-an-integer');
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

    public function rejectsOperationsWithoutAConstructibleTypeMismatch(): void
    {
        Expect::exception(UnsupportedGeneration::class);
        $operation = new Operation(
            key: 'string-only',
            operationId: 'string-only',
            method: 'GET',
            path: '/string-only',
            parameters: [[
                'name' => 'name',
                'in' => 'query',
                'required' => true,
                'style' => 'form',
                'explode' => true,
                'allowReserved' => false,
                'schema' => ['type' => 'string'],
            ]],
        );

        (new NegativeRequestCaseArbitrary())->typeMismatchForOperation($operation);
    }

    public function enumMismatchIsInvalidBeforeTransport(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/state' => ['get' => [
                'operationId' => 'state.get',
                'parameters' => [[
                    'name' => 'state',
                    'in' => 'query',
                    'required' => true,
                    'schema' => ['type' => 'string', 'enum' => ['ready', 'busy']],
                ]],
                'responses' => ['204' => []],
            ]]],
        ]);
        $operation = $contract->operation('state.get');
        $case = (new NegativeRequestCaseArbitrary())->enumMismatchForOperation($operation)->generate(new Random(29))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'enum', 'location' => 'query', 'name' => 'state']);
        Assert::string($request->getUri()->getQuery())->contains('__openapi_invalid_enum__');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function constMismatchIsInvalidBeforeTransport(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/version' => ['get' => [
                'operationId' => 'version.get',
                'parameters' => [[
                    'name' => 'version', 'in' => 'header', 'required' => true,
                    'schema' => ['type' => 'string', 'const' => 'v1'],
                ]],
                'responses' => ['204' => []],
            ]]],
        ]);
        $operation = $contract->operation('version.get');
        $case = (new NegativeRequestCaseArbitrary())->constMismatchForOperation($operation)->generate(new Random(31))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'const', 'location' => 'header', 'name' => 'version']);
        Assert::same($request->getHeaderLine('version'), '__openapi_invalid_const__');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function boundaryMismatchIsInvalidBeforeTransport(): void
    {
        $contract = self::contract();
        $operation = $contract->operation('pets.update');
        $case = (new NegativeRequestCaseArbitrary())->boundaryMismatchForOperation($operation)->generate(new Random(37))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'boundary', 'location' => 'path', 'name' => 'id']);
        Assert::same($case['path']['id'], '0');
        Assert::string($request->getUri()->getPath())->contains('/pets/0');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function boundaryMismatchHonoursBooleanExclusiveMinimum(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.0.3',
            'info' => ['title' => 'limits', 'version' => '1.0.0'],
            'paths' => ['/items' => ['get' => [
                'operationId' => 'items.list',
                'parameters' => [[
                    'name' => 'limit', 'in' => 'query', 'required' => true,
                    'schema' => ['type' => 'integer', 'minimum' => 5, 'exclusiveMinimum' => true],
                ]],
                'responses' => ['204' => []],
            ]]],
        ]);
        $operation = $contract->operation('items.list');
        $case = (new NegativeRequestCaseArbitrary())->boundaryMismatchForOperation($operation)->generate(new Random(41))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'boundary', 'location' => 'query', 'name' => 'limit']);
        Assert::same($case['query']['limit'], '5');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function boundaryMismatchExceedsMaximum(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/items' => ['get' => [
                'operationId' => 'items.list',
                'parameters' => [[
                    'name' => 'limit', 'in' => 'query', 'required' => true,
                    'schema' => ['type' => 'integer', 'maximum' => 10],
                ]],
                'responses' => ['204' => []],
            ]]],
        ]);
        $operation = $contract->operation('items.list');
        $case = (new NegativeRequestCaseArbitrary())->boundaryMismatchForOperation($operation)->generate(new Random(43))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'boundary', 'location' => 'query', 'name' => 'limit']);
        Assert::same($case['query']['limit'], '11');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function lengthMismatchIsInvalidBeforeTransport(): void
    {
        $contract = self::contract();
        $operation = $contract->operation('pets.update');
        $case = (new NegativeRequestCaseArbitrary())->lengthMismatchForOperation($operation)->generate(new Random(47))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'length', 'location' => 'header', 'name' => 'X-Tenant']);
        Assert::same($case['headers']['X-Tenant'], 'aaaaaa');
        Assert::same($request->getHeaderLine('X-Tenant'), 'aaaaaa');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function lengthMismatchPrefersAStringBelowMinimumLength(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/codes' => ['get' => [
                'operationId' => 'codes.get',
                'parameters' => [[
                    'name' => 'code', 'in' => 'query', 'required' => true,
                    'schema' => ['type' => 'string', 'minLength' => 3, 'maxLength' => 8],
                ]],
                'responses' => ['204' => []],
            ]]],
        ]);
        $operation = $contract->operation('codes.get');
        $case = (new NegativeRequestCaseArbitrary())->lengthMismatchForOperation($operation)->generate(new Random(53))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'length', 'location' => 'query', 'name' => 'code']);
        Assert::same($case['query']['code'], 'aa');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function rejectsLengthMismatchWhenPurityCannotBePromised(): void
    {
        Expect::exception(UnsupportedGeneration::class);
        $operation = new Operation(
            key: 'patterned',
            operationId: 'patterned',
            method: 'GET',
            path: '/patterned',
            parameters: [[
                'name' => 'code',
                'in' => 'query',
                'required' => true,
                'style' => 'form',
                'explode' => true,
                'allowReserved' => false,
                'schema' => ['type' => 'string', 'minLength' => 3, 'pattern' => '^a+$'],
            ]],
        );

        (new NegativeRequestCaseArbitrary())->lengthMismatchForOperation($operation);
    }

    public function rejectsOperationsWithoutAConstructibleBoundaryMismatch(): void
    {
        Expect::exception(UnsupportedGeneration::class);
        $operation = new Operation(
            key: 'string-only',
            operationId: 'string-only',
            method: 'GET',
            path: '/string-only',
            parameters: [[
                'name' => 'name',
                'in' => 'query',
                'required' => true,
                'style' => 'form',
                'explode' => true,
                'allowReserved' => false,
                'schema' => ['type' => 'string'],
            ]],
        );

        (new NegativeRequestCaseArbitrary())->boundaryMismatchForOperation($operation);
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
