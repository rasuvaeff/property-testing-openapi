<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Closure;
use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\BodyTargets;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\ParameterTargets;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\PatternWitness;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\SchemaProbe;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeRequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\RequestMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\Random;
use Rasuvaeff\PropertyTesting\Shrinkable;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(RequestCaseArbitrary::class)]
#[Covers(NegativeRequestCaseArbitrary::class)]
#[Covers(ParameterTargets::class)]
#[Covers(PatternWitness::class)]
#[Covers(BodyTargets::class)]
#[Covers(SchemaProbe::class)]
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
        $contract = $this->enumContract();
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
        $contract = $this->constContract();
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

    public function formatMismatchIsInvalidBeforeTransport(): void
    {
        $contract = $this->formatContract();
        $operation = $contract->operation('items.get');
        $case = (new NegativeRequestCaseArbitrary())->formatMismatchForOperation($operation)->generate(new Random(59))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'format', 'location' => 'query', 'name' => 'id']);
        Assert::same($case['query']['id'], 'not-a-uuid');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function rejectsFormatMismatchForAFormatTheBackendDoesNotAssert(): void
    {
        Expect::exception(UnsupportedGeneration::class);
        $operation = new Operation(
            key: 'url-only',
            operationId: 'url-only',
            method: 'GET',
            path: '/url-only',
            parameters: [[
                'name' => 'link',
                'in' => 'query',
                'required' => true,
                'style' => 'form',
                'explode' => true,
                'allowReserved' => false,
                'schema' => ['type' => 'string', 'format' => 'url'],
            ]],
        );

        (new NegativeRequestCaseArbitrary())->formatMismatchForOperation($operation);
    }

    public function patternMismatchIsInvalidBeforeTransport(): void
    {
        $contract = $this->patternContract();
        $operation = $contract->operation('codes.get');
        $case = (new NegativeRequestCaseArbitrary())->patternMismatchForOperation($operation)->generate(new Random(67))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'pattern', 'location' => 'query', 'name' => 'code']);
        Assert::same($case['query']['code'], '');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function patternMismatchSkipsParametersWithoutAProvableWitness(): void
    {
        $operation = new Operation(
            key: 'q.get',
            operationId: 'q.get',
            method: 'GET',
            path: '/q',
            parameters: [
                [
                    'name' => 'plain',
                    'in' => 'query',
                    'required' => true,
                    'style' => 'form',
                    'explode' => true,
                    'allowReserved' => false,
                    'schema' => ['type' => 'string'],
                ],
                [
                    'name' => 'code',
                    'in' => 'query',
                    'required' => true,
                    'style' => 'form',
                    'explode' => true,
                    'allowReserved' => false,
                    'schema' => ['type' => 'string', 'pattern' => '^[a-z]{3}$'],
                ],
            ],
        );
        $case = (new NegativeRequestCaseArbitrary())->patternMismatchForOperation($operation)->generate(new Random(67))->value;

        Assert::same($case['misuse'], ['kind' => 'pattern', 'location' => 'query', 'name' => 'code']);
    }

    public function patternMismatchUsesASingleCharacterPathWitness(): void
    {
        $operation = new Operation(
            key: 'codes.show',
            operationId: 'codes.show',
            method: 'GET',
            path: '/codes/{code}',
            parameters: [[
                'name' => 'code',
                'in' => 'path',
                'required' => true,
                'style' => 'simple',
                'explode' => false,
                'allowReserved' => false,
                'schema' => ['type' => 'string', 'pattern' => '^[a-z]+$'],
            ]],
        );
        $case = (new NegativeRequestCaseArbitrary())->patternMismatchForOperation($operation)->generate(new Random(67))->value;

        Assert::same($case['misuse'], ['kind' => 'pattern', 'location' => 'path', 'name' => 'code']);
        Assert::same($case['path']['code'], 'A');
    }

    public function patternWitnessStaysInsideTheLengthWindow(): void
    {
        $negative = new NegativeRequestCaseArbitrary();
        $case = $negative->patternMismatchForOperation(
            $this->queryParamOperation(['type' => 'string', 'pattern' => '^a+$', 'minLength' => 2, 'maxLength' => 3]),
        )->generate(new Random(67))->value;
        $witness = (string) $case['query']['q'];

        Assert::same($case['misuse'], ['kind' => 'pattern', 'location' => 'query', 'name' => 'q']);
        Assert::same(preg_match("\x07^a+\$\x07uD", $witness), 0);
        Assert::true(mb_strlen($witness) >= 2 && mb_strlen($witness) <= 3);
    }

    public function patternMismatchExcludesAnEmptyPathWitness(): void
    {
        $negative = new NegativeRequestCaseArbitrary();
        $operation = new Operation(
            key: 'codes.show',
            operationId: 'codes.show',
            method: 'GET',
            path: '/codes/{code}',
            parameters: [[
                'name' => 'code',
                'in' => 'path',
                'required' => true,
                'style' => 'simple',
                'explode' => false,
                'allowReserved' => false,
                'schema' => ['type' => 'string', 'pattern' => '^.+$'],
            ]],
        );

        Expect::exception(UnsupportedGeneration::class);
        $negative->patternMismatchForOperation($operation);
    }

    public function patternMismatchGuardsPurityAndSearchBudget(): void
    {
        $negative = new NegativeRequestCaseArbitrary();
        foreach ([
            ['type' => 'string', 'pattern' => '^[a-z]+$', 'enum' => ['abc']],
            ['type' => 'string', 'pattern' => '^[a-z]+$', 'const' => 'abc'],
            ['type' => 'string', 'pattern' => '^[a-z]+$', 'format' => 'uuid'],
            ['type' => 'integer', 'pattern' => '^[0-9]+$'],
            ['type' => 'string'],
            ['type' => 'string', 'pattern' => ''],
            ['type' => 'string', 'pattern' => '('],
            ['type' => 'string', 'pattern' => 'a\Z'],
            ['type' => 'string', 'pattern' => 'a*'],
            ['type' => 'string', 'pattern' => '^a{5}$', 'minLength' => 5, 'maxLength' => 4],
            ['type' => 'string', 'pattern' => '^[a-z]{3}$', 'minLength' => -1],
        ] as $schema) {
            try {
                $negative->patternMismatchForOperation($this->queryParamOperation($schema));
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }
    }

    public function additionalPropertyIsInvalidBeforeTransport(): void
    {
        $contract = $this->sealedBodyContract(additionalProperties: false);
        $operation = $contract->operation('pets.create');
        $case = (new NegativeRequestCaseArbitrary())->additionalPropertyForOperation($operation)->generate(new Random(61))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'additional-properties', 'location' => 'body', 'name' => '__openapi_extra_property__']);
        Assert::true(is_array($case['body']['value']) && array_key_exists('__openapi_extra_property__', $case['body']['value']));
        Assert::string((string) $request->getBody())->contains('__openapi_extra_property__');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function rejectsAdditionalPropertyWhenTheBodyAcceptsExtras(): void
    {
        Expect::exception(UnsupportedGeneration::class);
        $operation = $this->sealedBodyContract(additionalProperties: null)->operation('pets.create');

        (new NegativeRequestCaseArbitrary())->additionalPropertyForOperation($operation);
    }

    private function sealedBodyContract(?bool $additionalProperties): Contract
    {
        $schema = [
            'type' => 'object',
            'required' => ['name'],
            'properties' => ['name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 8]],
        ];
        if ($additionalProperties !== null) {
            $schema['additionalProperties'] = $additionalProperties;
        }

        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets' => ['post' => [
                'operationId' => 'pets.create',
                'requestBody' => [
                    'required' => true,
                    'content' => ['application/json' => ['schema' => $schema]],
                ],
                'responses' => ['201' => []],
            ]]],
        ]);
    }

    public function mediaTypeMismatchIsInvalidBeforeTransport(): void
    {
        $contract = $this->sealedBodyContract(additionalProperties: null);
        $operation = $contract->operation('pets.create');
        $case = (new NegativeRequestCaseArbitrary())->mediaTypeMismatchForOperation($operation)->generate(new Random(67))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'media-type', 'location' => 'body', 'name' => 'body']);
        Assert::same($request->getHeaderLine('Content-Type'), 'application/x-openapi-misuse');
        Assert::json((string) $request->getBody())->isObject()->hasKeys('name');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function rejectsMediaTypeMismatchWhenAWildcardIsDeclared(): void
    {
        Expect::exception(UnsupportedGeneration::class);
        $operation = new Operation(
            key: 'wildcard',
            operationId: 'wildcard',
            method: 'POST',
            path: '/wildcard',
            requestBody: [
                'required' => true,
                'content' => [
                    'application/json' => ['schema' => ['type' => 'object']],
                    'application/*' => ['schema' => ['type' => 'object']],
                ],
            ],
        );

        (new NegativeRequestCaseArbitrary())->mediaTypeMismatchForOperation($operation);
    }

    public function malformedJsonBodyIsInvalidBeforeTransport(): void
    {
        $contract = $this->sealedBodyContract(additionalProperties: null);
        $operation = $contract->operation('pets.create');
        $case = (new NegativeRequestCaseArbitrary())->malformedJsonForOperation($operation)->generate(new Random(71))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'json-syntax', 'location' => 'body', 'name' => 'body']);
        Assert::same($case['body'], ['mediaType' => 'application/json', 'encoding' => 'raw', 'value' => '{"malformed":']);
        Assert::same((string) $request->getBody(), '{"malformed":');
        Assert::same($request->getHeaderLine('Content-Type'), 'application/json');
        Assert::false($contract->validateRequest($request)->isValid());
    }

    public function rejectsMalformedJsonWithoutARequiredJsonBody(): void
    {
        Expect::exception(UnsupportedGeneration::class);
        $operation = new Operation(
            key: 'no-body',
            operationId: 'no-body',
            method: 'GET',
            path: '/no-body',
        );

        (new NegativeRequestCaseArbitrary())->malformedJsonForOperation($operation);
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

    #[Property(runs: 20)]
    public function shrinkPreservesMisuseCategoryAndInvalidity(int $seed): void
    {
        $factory = new Psr17Factory();
        $materializer = new RequestMaterializer($factory, $factory);
        $negative = new NegativeRequestCaseArbitrary();
        $observed = 0;
        foreach ($this->negativeCategories() as [$contract, $operationKey, $arbitrary, $expectedMisuse]) {
            $operation = $contract->operation($operationKey);
            $root = $arbitrary($negative, $operation)->generate(new Random($seed));

            Assert::same($root->value['misuse'], $expectedMisuse);
            Assert::false($contract->validateRequest($materializer->materialize($operation, $root->value))->isValid());

            foreach ($this->shrinkCandidates($root, budget: 10) as $candidate) {
                ++$observed;
                Assert::same($candidate['misuse'], $expectedMisuse);
                Assert::false($contract->validateRequest($materializer->materialize($operation, $candidate))->isValid());
            }
        }

        Classify::cover(condition: $observed > 0, label: 'shrink candidates observed', minPercent: 90.0);
    }

    /** @return array<string, ArbitraryInterface> */
    public static function shrinkPreservesMisuseCategoryAndInvalidityGenerators(): array
    {
        return ['seed' => Gen::intBetween(0, 1_000_000)];
    }

    public static function shrinkPreservesMisuseCategoryAndInvalidityExamples(): iterable
    {
        yield 'smallest seed' => [0];
    }

    /**
     * Every constructive negative category paired with the misuse metadata its
     * shrink candidates must keep.
     *
     * @return iterable<string, array{Contract, non-empty-string, Closure(NegativeRequestCaseArbitrary, Operation): ArbitraryInterface, array{kind: string, location: string, name: string}}>
     */
    private function negativeCategories(): iterable
    {
        $main = self::contract();

        yield 'missing-required' => [
            $main,
            'pets.update',
            static fn(NegativeRequestCaseArbitrary $negative, Operation $operation): ArbitraryInterface => $negative->forOperation($operation),
            ['kind' => 'missing-required', 'location' => 'path', 'name' => 'id'],
        ];
        yield 'type' => [
            $main,
            'pets.update',
            static fn(NegativeRequestCaseArbitrary $negative, Operation $operation): ArbitraryInterface => $negative->typeMismatchForOperation($operation),
            ['kind' => 'type', 'location' => 'path', 'name' => 'id'],
        ];
        yield 'enum' => [
            $this->enumContract(),
            'state.get',
            static fn(NegativeRequestCaseArbitrary $negative, Operation $operation): ArbitraryInterface => $negative->enumMismatchForOperation($operation),
            ['kind' => 'enum', 'location' => 'query', 'name' => 'state'],
        ];
        yield 'const' => [
            $this->constContract(),
            'version.get',
            static fn(NegativeRequestCaseArbitrary $negative, Operation $operation): ArbitraryInterface => $negative->constMismatchForOperation($operation),
            ['kind' => 'const', 'location' => 'header', 'name' => 'version'],
        ];
        yield 'boundary' => [
            $main,
            'pets.update',
            static fn(NegativeRequestCaseArbitrary $negative, Operation $operation): ArbitraryInterface => $negative->boundaryMismatchForOperation($operation),
            ['kind' => 'boundary', 'location' => 'path', 'name' => 'id'],
        ];
        yield 'length' => [
            $main,
            'pets.update',
            static fn(NegativeRequestCaseArbitrary $negative, Operation $operation): ArbitraryInterface => $negative->lengthMismatchForOperation($operation),
            ['kind' => 'length', 'location' => 'header', 'name' => 'X-Tenant'],
        ];
        yield 'format' => [
            $this->formatContract(),
            'items.get',
            static fn(NegativeRequestCaseArbitrary $negative, Operation $operation): ArbitraryInterface => $negative->formatMismatchForOperation($operation),
            ['kind' => 'format', 'location' => 'query', 'name' => 'id'],
        ];
        yield 'pattern' => [
            $this->patternContract(),
            'codes.get',
            static fn(NegativeRequestCaseArbitrary $negative, Operation $operation): ArbitraryInterface => $negative->patternMismatchForOperation($operation),
            ['kind' => 'pattern', 'location' => 'query', 'name' => 'code'],
        ];
        yield 'additional-properties' => [
            $this->sealedBodyContract(additionalProperties: false),
            'pets.create',
            static fn(NegativeRequestCaseArbitrary $negative, Operation $operation): ArbitraryInterface => $negative->additionalPropertyForOperation($operation),
            ['kind' => 'additional-properties', 'location' => 'body', 'name' => '__openapi_extra_property__'],
        ];
        yield 'media-type' => [
            $this->sealedBodyContract(additionalProperties: null),
            'pets.create',
            static fn(NegativeRequestCaseArbitrary $negative, Operation $operation): ArbitraryInterface => $negative->mediaTypeMismatchForOperation($operation),
            ['kind' => 'media-type', 'location' => 'body', 'name' => 'body'],
        ];
        yield 'json-syntax' => [
            $this->sealedBodyContract(additionalProperties: null),
            'pets.create',
            static fn(NegativeRequestCaseArbitrary $negative, Operation $operation): ArbitraryInterface => $negative->malformedJsonForOperation($operation),
            ['kind' => 'json-syntax', 'location' => 'body', 'name' => 'body'],
        ];
    }

    /**
     * Breadth-first shrink candidates up to a budget; the tree is finite per
     * branch but the budget keeps a trial bounded.
     *
     * @template T
     *
     * @param Shrinkable<T> $root
     *
     * @return list<T>
     */
    private function shrinkCandidates(Shrinkable $root, int $budget): array
    {
        $candidates = [];
        $queue = [$root];
        while ($queue !== [] && count($candidates) < $budget) {
            $node = array_shift($queue);
            foreach ($node->shrinks() as $child) {
                $candidates[] = $child->value;
                $queue[] = $child;
                if (count($candidates) >= $budget) {
                    break;
                }
            }
        }

        return $candidates;
    }

    private function enumContract(): Contract
    {
        return Contract::fromArray([
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
    }

    private function constContract(): Contract
    {
        return Contract::fromArray([
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
    }

    /** @param array<string, mixed> $schema */
    private function queryParamOperation(array $schema, bool $required = true): Operation
    {
        return new Operation(
            key: 'q.get',
            operationId: 'q.get',
            method: 'GET',
            path: '/q',
            parameters: [[
                'name' => 'q',
                'in' => 'query',
                'required' => $required,
                'style' => 'form',
                'explode' => true,
                'allowReserved' => false,
                'schema' => $schema,
            ]],
        );
    }

    /** @param array<array-key, mixed> $content */
    private function bodyOperation(array $content, bool $required = true): Operation
    {
        return new Operation(
            key: 'b.post',
            operationId: 'b.post',
            method: 'POST',
            path: '/b',
            requestBody: ['required' => $required, 'content' => $content],
        );
    }

    public function enumMismatchTargetsOnlyRequiredScalarEnums(): void
    {
        $negative = new NegativeRequestCaseArbitrary();
        foreach ([
            $this->queryParamOperation(['type' => 'string', 'enum' => ['a']], required: false),
            $this->queryParamOperation(['type' => 'string', 'enum' => []]),
            $this->queryParamOperation(['type' => 'string', 'enum' => [['a']]]),
        ] as $operation) {
            try {
                $negative->enumMismatchForOperation($operation);
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }

        $nullable = $this->queryParamOperation(['type' => 'string', 'enum' => [null, 'x']]);
        $case = $negative->enumMismatchForOperation($nullable)->generate(new Random(29))->value;
        Assert::same($case['query']['q'], '__openapi_invalid_enum__');

        $colliding = $this->queryParamOperation(['type' => 'string', 'enum' => ['__openapi_invalid_enum__']]);
        $case = $negative->enumMismatchForOperation($colliding)->generate(new Random(29))->value;
        Assert::same($case['query']['q'], '__openapi_invalid_enum___');
    }

    public function constMismatchTargetsOnlyRequiredScalarConsts(): void
    {
        $negative = new NegativeRequestCaseArbitrary();
        foreach ([
            $this->queryParamOperation(['type' => 'string', 'const' => 'v1'], required: false),
            $this->queryParamOperation(['type' => 'object', 'const' => ['a']]),
        ] as $operation) {
            try {
                $negative->constMismatchForOperation($operation);
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }

        $numeric = $this->queryParamOperation(['type' => 'integer', 'const' => 5]);
        $case = $negative->constMismatchForOperation($numeric)->generate(new Random(31))->value;
        Assert::same($case['query']['q'], 'not-a-const-number');
    }

    public function boundaryMismatchHandlesNumberSchemasAndFloatBounds(): void
    {
        $negative = new NegativeRequestCaseArbitrary();

        $below = $negative->boundaryMismatchForOperation($this->queryParamOperation(['type' => 'number', 'minimum' => 1.5]))->generate(new Random(37))->value;
        Assert::same($below['query']['q'], '0.5');

        $above = $negative->boundaryMismatchForOperation($this->queryParamOperation(['type' => 'number', 'maximum' => 2.5]))->generate(new Random(37))->value;
        Assert::same($above['query']['q'], '3.5');

        $exclusive = $negative->boundaryMismatchForOperation($this->queryParamOperation(['type' => 'integer', 'maximum' => 10, 'exclusiveMaximum' => true]))->generate(new Random(37))->value;
        Assert::same($exclusive['query']['q'], '10');

        $intBoundForNumber = $negative->boundaryMismatchForOperation($this->queryParamOperation(['type' => 'number', 'minimum' => 2]))->generate(new Random(37))->value;
        Assert::same($intBoundForNumber['query']['q'], '1');

        $union = $negative->boundaryMismatchForOperation($this->queryParamOperation(['type' => ['integer', 'number'], 'minimum' => 5]))->generate(new Random(37))->value;
        Assert::same($union['query']['q'], '4');
    }

    public function boundaryMismatchFailsClosedAtTheNumericLimits(): void
    {
        $negative = new NegativeRequestCaseArbitrary();
        foreach ([
            ['type' => 'integer', 'minimum' => PHP_INT_MIN],
            ['type' => 'integer', 'maximum' => PHP_INT_MAX],
            ['type' => 'number', 'minimum' => 1.0E308],
            ['type' => 'number', 'maximum' => 1.0E308],
            ['type' => 'string', 'minimum' => 5],
            ['type' => 'integer', 'minimum' => '5'],
        ] as $schema) {
            try {
                $negative->boundaryMismatchForOperation($this->queryParamOperation($schema));
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }
    }

    public function lengthMismatchHonorsTheConstructedLengthBudget(): void
    {
        $negative = new NegativeRequestCaseArbitrary();

        $atMinimum = $negative->lengthMismatchForOperation($this->queryParamOperation(['type' => 'string', 'minLength' => 2]))->generate(new Random(47))->value;
        Assert::same($atMinimum['query']['q'], 'a');

        $zeroMax = $negative->lengthMismatchForOperation($this->queryParamOperation(['type' => 'string', 'maxLength' => 0]))->generate(new Random(47))->value;
        Assert::same($zeroMax['query']['q'], 'a');

        foreach ([
            ['type' => 'integer', 'minLength' => 3],
            ['type' => 'string', 'minLength' => 3, 'enum' => ['abc']],
            ['type' => 'string'],
            ['type' => 'string', 'maxLength' => 4096],
        ] as $schema) {
            try {
                $negative->lengthMismatchForOperation($this->queryParamOperation($schema));
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }
    }

    public function formatMismatchGuardsEveryConflictingKeyword(): void
    {
        $negative = new NegativeRequestCaseArbitrary();
        foreach ([
            ['type' => 'string', 'format' => 'uuid', 'enum' => ['x']],
            ['type' => 'string', 'format' => 'uuid', 'minLength' => 3],
        ] as $schema) {
            try {
                $negative->formatMismatchForOperation($this->queryParamOperation($schema));
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }
    }

    public function additionalPropertyInjectionAvoidsDeclaredNames(): void
    {
        $negative = new NegativeRequestCaseArbitrary();
        $operation = $this->bodyOperation(['application/json' => ['schema' => [
            'type' => 'object',
            'properties' => ['__openapi_extra_property__' => ['type' => 'boolean']],
            'additionalProperties' => false,
        ]]]);

        $case = $negative->additionalPropertyForOperation($operation)->generate(new Random(61))->value;

        Assert::same($case['misuse']['name'] ?? null, '__openapi_extra_property___');
        Assert::true(is_array($case['body']) && ($case['body']['value']['__openapi_extra_property___'] ?? null) === true);

        Expect::exception(UnsupportedGeneration::class);
        $negative->additionalPropertyForOperation(new Operation(key: 'no-body', operationId: 'no-body', method: 'GET', path: '/x'));
    }

    public function mediaTypeMismatchAvoidsDeclaredMisuseTypes(): void
    {
        $operation = $this->bodyOperation([
            'application/json' => ['schema' => ['type' => 'object']],
            'application/x-openapi-misuse' => ['schema' => ['type' => 'object']],
        ]);

        $case = (new NegativeRequestCaseArbitrary())->mediaTypeMismatchForOperation($operation)->generate(new Random(67))->value;

        Assert::true(is_array($case['body']) && $case['body']['mediaType'] === 'application/x-openapi-misuse-x');
    }

    public function jsonBodyDetectionNormalizesDeclaredMediaTypes(): void
    {
        $negative = new NegativeRequestCaseArbitrary();

        $upper = $this->bodyOperation([0 => ['schema' => ['type' => 'object']], 'Application/JSON ; charset=utf-8' => ['schema' => ['type' => 'object']]]);
        $case = $negative->malformedJsonForOperation($upper)->generate(new Random(71))->value;
        Assert::same($case['misuse']['kind'] ?? null, 'json-syntax');

        $suffixed = $this->bodyOperation(['application/hal+json' => ['schema' => ['type' => 'object']]]);
        $case = $negative->malformedJsonForOperation($suffixed)->generate(new Random(71))->value;
        Assert::same($case['misuse']['kind'] ?? null, 'json-syntax');

        foreach ([
            $this->bodyOperation(['application/json' => ['schema' => ['type' => 'object']]], required: false),
            $this->bodyOperation(['text/plain' => ['schema' => ['type' => 'object']]]),
            $this->bodyOperation(['application/json' => ['schema' => [['type' => 'object']]]]),
        ] as $operation) {
            try {
                $negative->malformedJsonForOperation($operation);
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }
    }

    public function targetSearchScansEveryParameter(): void
    {
        $negative = new NegativeRequestCaseArbitrary();
        $plain = ['name' => 'first', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => ['type' => 'string']];
        $operation = static fn(array $schema): Operation => new Operation(
            key: 'multi',
            operationId: 'multi',
            method: 'GET',
            path: '/multi',
            parameters: [$plain, ['name' => 'second', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => $schema]],
        );

        $case = $negative->enumMismatchForOperation($operation(['type' => 'string', 'enum' => ['a']]))->generate(new Random(29))->value;
        Assert::same($case['misuse']['name'] ?? null, 'second');

        $case = $negative->constMismatchForOperation($operation(['type' => 'string', 'const' => 'v1']))->generate(new Random(31))->value;
        Assert::same($case['misuse']['name'] ?? null, 'second');
    }

    public function enumTargetSkipsMalformedEnumsBeforeAValidOne(): void
    {
        $operation = new Operation(
            key: 'mixed',
            operationId: 'mixed',
            method: 'GET',
            path: '/mixed',
            parameters: [
                ['name' => 'broken', 'in' => 'query', 'required' => false, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => ['type' => 'string', 'enum' => ['skip']]],
                ['name' => 'ok', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => ['type' => 'string', 'enum' => ['a']]],
            ],
        );

        $case = (new NegativeRequestCaseArbitrary())->enumMismatchForOperation($operation)->generate(new Random(29))->value;

        Assert::same($case['misuse']['name'] ?? null, 'ok');
    }

    public function enumTargetRejectsScalarEnumDeclarations(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new NegativeRequestCaseArbitrary())->enumMismatchForOperation($this->queryParamOperation(['type' => 'string', 'enum' => 'x']));
    }

    public function boundaryMismatchSkipsBoundsThatCollapseUnderFloatPrecision(): void
    {
        $negative = new NegativeRequestCaseArbitrary();
        foreach ([
            ['type' => 'number', 'minimum' => 1.0E308, 'maximum' => 1.0E308],
            ['type' => 'number', 'minimum' => -1.0E308, 'maximum' => -1.0E308],
        ] as $schema) {
            try {
                $negative->boundaryMismatchForOperation($this->queryParamOperation($schema));
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }
    }

    public function jsonBodyRequiresAnExplicitRequiredFlag(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new NegativeRequestCaseArbitrary())->malformedJsonForOperation(new Operation(
            key: 'implicit',
            operationId: 'implicit',
            method: 'POST',
            path: '/implicit',
            requestBody: ['content' => ['application/json' => ['schema' => ['type' => 'object']]]],
        ));
    }

    public function jsonBodyDetectionScansPastNonJsonMediaTypes(): void
    {
        $operation = $this->bodyOperation([
            'text/plain' => ['schema' => ['type' => 'string']],
            'application/json' => ['schema' => ['type' => 'object']],
        ]);

        $case = (new NegativeRequestCaseArbitrary())->malformedJsonForOperation($operation)->generate(new Random(71))->value;

        Assert::same($case['misuse']['kind'] ?? null, 'json-syntax');
    }

    public function jsonBodyRejectsScalarSchemas(): void
    {
        Expect::exception(UnsupportedGeneration::class);

        (new NegativeRequestCaseArbitrary())->malformedJsonForOperation($this->bodyOperation(['application/json' => ['schema' => 'invalid']]));
    }

    public function placesParametersInTheirDeclaredLocations(): void
    {
        $param = static fn(string $name, string $in, array $schema, bool $required = true): array => [
            'name' => $name, 'in' => $in, 'required' => $required,
            'style' => $in === 'query' || $in === 'cookie' ? 'form' : 'simple',
            'explode' => $in === 'query', 'allowReserved' => false, 'schema' => $schema,
        ];
        $operation = new Operation(
            key: 'multi',
            operationId: 'multi',
            method: 'GET',
            path: '/multi/{p}',
            parameters: [
                $param('h', 'header', ['const' => 'hv']),
                $param('p', 'path', ['const' => 'pv']),
                $param('c', 'cookie', ['const' => 'cv']),
                $param('int', 'query', ['const' => 5]),
                $param('bool', 'query', ['const' => true]),
                $param('null', 'query', ['enum' => [null]]),
            ],
        );

        $case = (new RequestCaseArbitrary())->forOperation($operation)->generate(new Random(3))->value;

        Assert::same($case['path'], ['p' => 'pv']);
        Assert::same($case['headers'], ['h' => 'hv']);
        Assert::same($case['cookies'], ['c' => 'cv']);
        Assert::same($case['query'], ['int' => '5', 'bool' => 'true', 'null' => 'null']);
    }

    public function convertsListAndObjectValuesToWireShapes(): void
    {
        $arbitrary = new RequestCaseArbitrary();

        $list = $arbitrary->forOperation($this->queryParamOperation(['items' => ['const' => 1], 'minItems' => 1, 'maxItems' => 1]))->generate(new Random(3))->value;
        Assert::same($list['query'], ['q' => ['1']]);

        $object = $arbitrary->forOperation($this->queryParamOperation(['properties' => ['a' => ['const' => 1]], 'required' => ['a'], 'additionalProperties' => false]))->generate(new Random(3))->value;
        Assert::same($object['query'], ['q' => ['a' => '1']]);
    }

    public function requiredParametersAppearInEverySample(): void
    {
        $operation = new Operation(
            key: 'pair',
            operationId: 'pair',
            method: 'GET',
            path: '/pair',
            parameters: [
                ['name' => 'opt', 'in' => 'query', 'required' => false, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => ['const' => 'o']],
                ['name' => 'req', 'in' => 'query', 'required' => true, 'style' => 'form', 'explode' => true, 'allowReserved' => false, 'schema' => ['const' => 'r']],
            ],
        );

        $sawAbsentOptional = false;
        foreach (Gen::sample((new RequestCaseArbitrary())->forOperation($operation), count: 30, seed: 7) as $case) {
            Assert::same($case['query']['req'] ?? null, 'r');
            $sawAbsentOptional = $sawAbsentOptional || !array_key_exists('opt', $case['query']);
        }
        Assert::true($sawAbsentOptional);
    }

    public function bodyPresenceFollowsTheRequiredFlag(): void
    {
        $arbitrary = new RequestCaseArbitrary();
        $content = ['application/json' => ['schema' => ['type' => 'object']]];

        $required = Gen::sample($arbitrary->forOperation($this->bodyOperation($content)), count: 20, seed: 11);
        foreach ($required as $case) {
            Assert::true(is_array($case['body']));
        }

        $implicit = new Operation(key: 'i', operationId: 'i', method: 'POST', path: '/i', requestBody: ['content' => $content]);
        $optional = Gen::sample($arbitrary->forOperation($implicit), count: 20, seed: 11);
        Assert::true(in_array(null, array_column($optional, 'body'), strict: true));
    }

    public function bodyContentScanningFailsClosed(): void
    {
        $arbitrary = new RequestCaseArbitrary();

        $case = $arbitrary->forOperation($this->bodyOperation([0 => 'junk', 'application/json' => ['schema' => ['type' => 'object']]]))->generate(new Random(13))->value;
        Assert::true(is_array($case['body']) && $case['body']['mediaType'] === 'application/json');

        foreach ([
            ['application/json' => ['schema' => 'invalid']],
            ['text/plain' => ['schema' => ['type' => 'object']]],
        ] as $content) {
            try {
                $arbitrary->forOperation($this->bodyOperation($content));
                Assert::true(actual: false, message: 'Expected unsupported generation exception');
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }
    }

    private function patternContract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/codes' => ['get' => [
                'operationId' => 'codes.get',
                'parameters' => [[
                    'name' => 'code', 'in' => 'query', 'required' => true,
                    'schema' => ['type' => 'string', 'pattern' => '^[a-z]{3}$'],
                ]],
                'responses' => ['204' => []],
            ]]],
        ]);
    }

    private function formatContract(): Contract
    {
        return Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/items' => ['get' => [
                'operationId' => 'items.get',
                'parameters' => [[
                    'name' => 'id', 'in' => 'query', 'required' => true,
                    'schema' => ['type' => 'string', 'format' => 'uuid'],
                ]],
                'responses' => ['204' => []],
            ]]],
        ]);
    }

    #[DataProvider('bodyWithoutEncodingObjectProvider')]
    public function generatesFormAndMultipartBodiesWithoutAnEncodingObject(string $mediaType, string $encoding): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/login' => ['post' => [
                'operationId' => 'login',
                'requestBody' => ['required' => true, 'content' => [$mediaType => ['schema' => ['type' => 'object', 'required' => ['user'], 'properties' => ['user' => ['type' => 'string']]]]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        $case = (new RequestCaseArbitrary())->forOperation($contract->operation('login'))->generate(new Random(1))->value;

        Assert::same($case['body']['mediaType'] ?? null, $mediaType);
        Assert::same($case['body']['encoding'] ?? null, $encoding);
    }

    public static function bodyWithoutEncodingObjectProvider(): iterable
    {
        yield 'form' => ['application/x-www-form-urlencoded', 'form'];
        yield 'multipart' => ['multipart/form-data', 'multipart'];
    }

    #[DataProvider('unsupportedEncodingObjectProvider')]
    public function rejectsAnUnsupportedEncodingObjectStyle(string $mediaType, string $message): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/login' => ['post' => [
                'operationId' => 'login',
                'requestBody' => ['required' => true, 'content' => [$mediaType => [
                    'schema' => ['type' => 'object', 'properties' => ['user' => ['type' => 'string']]],
                    'encoding' => ['user' => ['style' => 'spaceDelimited']],
                ]]],
                'responses' => ['204' => []],
            ]]],
        ]);

        Expect::exception(UnsupportedGeneration::class)->withMessage($message);

        (new RequestCaseArbitrary())->forOperation($contract->operation('login'));
    }

    public static function unsupportedEncodingObjectProvider(): iterable
    {
        yield 'form' => ['application/x-www-form-urlencoded', 'Form encoding supports only form style and boolean explode'];
        yield 'multipart' => ['multipart/form-data', 'Multipart encoding supports only form style'];
    }
}
