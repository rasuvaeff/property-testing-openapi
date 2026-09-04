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
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\BodyContracts;
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

    /**
     * The "body present" branch of an optional body is the only path that
     * reads a generated body back; it accepted JSON and form encodings only,
     * so an optional multipart body threw out of the generator.
     */
    #[Property(runs: 120, generators: [BodyContracts::class, 'optionalMultipartCase'])]
    public function optionalMultipartBodiesGenerateInBothForms(array $case): void
    {
        /** @var array{operationKey: string, path: array<string, string>, query: array<string, string>, headers: array<string, string>, cookies: array<string, string>, body: null|array{mediaType: string, encoding: 'multipart', boundary: string, parts: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>}, misuse: null} $case */
        $contract = BodyContracts::optionalMultipart();
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($contract->operation('upload.maybe'), $case);

        Classify::cover($case['body'] === null, 'body absent', 15.0);
        Classify::cover($case['body'] !== null, 'body present', 15.0);
        Assert::true($contract->validateRequest($request)->isValid());
    }

    /**
     * Every contradictable type gets its own witness, and the witness has to
     * reach the wire — a missing arm silently falls through to the next
     * parameter, or to "no constructible mismatch".
     */
    #[DataProvider('typeWitnessProvider')]
    public function buildsATypeWitnessForEveryContradictableType(string $type, string $witness): void
    {
        $operation = new Operation(
            key: 'typed',
            operationId: 'typed',
            method: 'GET',
            path: '/typed',
            parameters: [$this->queryParameter('v', ['type' => $type])],
        );
        $case = (new NegativeRequestCaseArbitrary())->typeMismatchForOperation($operation)->generate(new Random(3))->value;
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case['misuse'], ['kind' => 'type', 'location' => 'query', 'name' => 'v']);
        Assert::string($request->getUri()->getQuery())->contains($witness);
    }

    /** @return iterable<string, array{string, string}> */
    public static function typeWitnessProvider(): iterable
    {
        yield 'integer' => ['integer', 'not-an-integer'];
        yield 'number' => ['number', 'not-a-number'];
        yield 'boolean' => ['boolean', 'not-a-boolean'];
        yield 'null' => ['null', 'not-null'];
    }

    /**
     * A union admits every type it lists, so `not-null` is a valid value for
     * `["string", "null"]`; `string` and `array` have no witness at all. Either
     * way the search must move on to a parameter that can be contradicted
     * rather than give up at the first candidate.
     *
     * @param array<string, mixed> $schema
     */
    #[DataProvider('uncontradictableTypeProvider')]
    public function skipsParametersWhoseDeclaredTypeCannotBeContradicted(array $schema): void
    {
        $operation = new Operation(
            key: 'mixed',
            operationId: 'mixed',
            method: 'GET',
            path: '/mixed',
            parameters: [
                $this->queryParameter('skipped', $schema),
                $this->queryParameter('count', ['type' => 'integer']),
            ],
        );

        $case = (new NegativeRequestCaseArbitrary())->typeMismatchForOperation($operation)->generate(new Random(11))->value;

        Assert::same($case['misuse'], ['kind' => 'type', 'location' => 'query', 'name' => 'count']);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function uncontradictableTypeProvider(): iterable
    {
        yield 'union with null' => [['type' => ['string', 'null']]];
        yield 'union of scalars' => [['type' => ['string', 'integer']]];
        yield 'string' => [['type' => 'string']];
        yield 'array' => [['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 2]]];
    }

    public function rejectsOperationsWhoseOnlyTypedParameterIsAUnion(): void
    {
        Expect::exception(UnsupportedGeneration::class);
        $operation = new Operation(
            key: 'union-only',
            operationId: 'union-only',
            method: 'GET',
            path: '/union-only',
            parameters: [$this->queryParameter('maybe', ['type' => ['string', 'null']])],
        );

        (new NegativeRequestCaseArbitrary())->typeMismatchForOperation($operation);
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{name: string, in: 'query', required: true, style: string, explode: bool, allowReserved: bool, schema: array<string, mixed>}
     */
    private function queryParameter(string $name, array $schema): array
    {
        return [
            'name' => $name,
            'in' => 'query',
            'required' => true,
            'style' => 'form',
            'explode' => true,
            'allowReserved' => false,
            'schema' => $schema,
        ];
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
                $param('null', 'query', ['enum' => [null, 'nv']]),
            ],
        );

        $case = (new RequestCaseArbitrary())->forOperation($operation)->generate(new Random(3))->value;

        Assert::same($case['path'], ['p' => 'pv']);
        Assert::same($case['headers'], ['h' => 'hv']);
        Assert::same($case['cookies'], ['c' => 'cv']);
        Assert::same($case['query'], ['int' => '5', 'bool' => 'true', 'null' => 'nv']);
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

    #[Property(runs: 120, generators: [BodyContracts::class, 'multipartCase'])]
    public function generatedMultipartCasesMaterializeIntoContractValidRequests(array $case): void
    {
        /** @var array{operationKey: string, path: array<string, string>, query: array<string, string>, headers: array<string, string>, cookies: array<string, string>, body: array{mediaType: string, encoding: 'multipart', boundary: string, parts: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>}, misuse: null} $case */
        $names = $this->assertMultipartCaseConforms($case);

        Classify::cover(isset($names['tags']), 'tags present', 10.0);
        Classify::cover(!isset($names['tags']), 'tags absent', 10.0);
        Classify::cover(isset($names['ids']), 'ids present', 10.0);
        Classify::cover(($names['many'] ?? 0) > 1, 'repeated boolean parts', 10.0);
        Classify::cover(isset($names['count']) && isset($names['flag']), 'count and flag present', 10.0);
    }

    /**
     * The generator is compiled inside the test body here, so mutants in the
     * compile-time path (`forOperation` and its helpers) are attributed to
     * this test — the property above compiles it in its provider.
     */
    public function multipartGenerationConformsAcrossSeeds(): void
    {
        $arbitrary = (new RequestCaseArbitrary())->forOperation(BodyContracts::multipart()->operation('upload.create'));
        $seen = ['tags' => 0, 'no tags' => 0, 'ids' => 0, 'repeated many' => 0, 'many with duplicates' => 0, 'short file' => 0, 'non-empty file' => 0, 'count and flag' => 0];
        foreach (range(1, 80) as $seed) {
            /** @var array{operationKey: string, path: array<string, string>, query: array<string, string>, headers: array<string, string>, cookies: array<string, string>, body: array{mediaType: string, encoding: 'multipart', boundary: string, parts: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>}, misuse: null} $case */
            $case = $arbitrary->generate(new Random($seed))->value;
            $names = $this->assertMultipartCaseConforms($case);
            $seen['tags'] += isset($names['tags']) ? 1 : 0;
            $seen['no tags'] += isset($names['tags']) ? 0 : 1;
            $seen['ids'] += isset($names['ids']) ? 1 : 0;
            $seen['repeated many'] += ($names['many'] ?? 0) > 1 ? 1 : 0;
            $seen['many with duplicates'] += ($names['many'] ?? 0) > 2 ? 1 : 0;
            $seen['count and flag'] += isset($names['count'], $names['flag']) ? 1 : 0;
            foreach ($case['body']['parts'] as $part) {
                $seen['short file'] += $part['name'] === 'file' && strlen($part['value']) < 12 ? 1 : 0;
                $seen['non-empty file'] += $part['name'] === 'file' && $part['value'] !== '' ? 1 : 0;
            }
        }

        foreach ($seen as $label => $count) {
            Assert::true($count > 0, $label . ' never generated');
        }
    }

    /**
     * @param array{operationKey: string, path: array<string, string>, query: array<string, string>, headers: array<string, string>, cookies: array<string, string>, body: array{mediaType: string, encoding: 'multipart', boundary: string, parts: list<array{name: string, value: string, encoding: 'text'|'base64', contentType: string, headers: array<string, string>}>}, misuse: null} $case
     * @return array<string, int> part counts by name
     */
    private function assertMultipartCaseConforms(array $case): array
    {
        $contract = BodyContracts::multipart();
        $operation = $contract->operation('upload.create');
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);
        $result = $contract->validateRequest($request);

        Assert::true($result->isValid(), (new \Rasuvaeff\OpenApiContract\ValidationResultFormatter())->format($result));

        $body = $case['body'];
        Assert::same($body['mediaType'], 'multipart/form-data');
        Assert::same(preg_match('/^openapi-[0-9a-f]{16}\z/', $body['boundary']), 1);
        Assert::same($request->getHeaderLine('Content-Type'), 'multipart/form-data; boundary=' . $body['boundary']);
        $names = [];
        foreach ($body['parts'] as $part) {
            $names[$part['name']] = ($names[$part['name']] ?? 0) + 1;
            $decoded = $part['encoding'] === 'base64' ? base64_decode($part['value'], strict: true) : $part['value'];
            Assert::true(is_string($decoded) && !str_contains($decoded, $body['boundary']));
            match ($part['name']) {
                'title' => Assert::same([$part['encoding'], $part['contentType'], $part['headers'], mb_strlen($part['value']) >= 1 && mb_strlen($part['value']) <= 12], ['text', 'text/markdown', ['X-Example' => 'yes', 'X-Default' => 'd', 'X-Bare' => 'x-openapi'], true]),
                'file' => Assert::same([$part['encoding'], $part['contentType'], $part['headers'], is_string($decoded) && strlen($decoded) <= 64], ['base64', 'application/octet-stream', [], true]),
                'tags' => Assert::same([$part['encoding'], $part['contentType'], $part['headers']], ['text', 'text/plain', []]),
                'ids' => Assert::same([$part['encoding'], $part['contentType'], preg_match('/^[0-9]\z/', $part['value'])], ['text', 'text/plain', 1]),
                'many', 'flag' => Assert::same([$part['encoding'], $part['contentType'], in_array($part['value'], ['true', 'false'], strict: true)], ['text', 'text/plain', true]),
                'count' => Assert::same([$part['encoding'], $part['contentType'], preg_match('/^(?:[0-9]|[1-9][0-9]|100)\z/', $part['value'])], ['text', 'text/plain', 1]),
            };
        }
        Assert::same($names['title'] ?? 0, 1);
        Assert::same($names['file'] ?? 0, 1);
        Assert::true(($names['tags'] ?? 0) <= 4);
        Assert::true(($names['many'] ?? 0) <= 16);
        Assert::true(!isset($names['ids']) || ($names['ids'] >= 2 && $names['ids'] <= 3));
        if (isset($names['ids'])) {
            $ids = array_map(static fn(array $part): string => $part['value'], array_values(array_filter($body['parts'], static fn(array $part): bool => $part['name'] === 'ids')));
            Assert::same(count(array_unique($ids)), count($ids));
        }

        return $names;
    }

    public function multipartBoundaryIsDeterministicForTheSameValue(): void
    {
        $arbitrary = (new RequestCaseArbitrary())->forOperation(BodyContracts::multipart()->operation('upload.create'));

        $first = $arbitrary->generate(new Random(11))->value;
        $second = $arbitrary->generate(new Random(11))->value;
        $other = $arbitrary->generate(new Random(12))->value;

        Assert::same($first, $second);
        Assert::true(($first['body']['boundary'] ?? null) !== ($other['body']['boundary'] ?? null));
    }

    #[Property(runs: 120, generators: [BodyContracts::class, 'formCase'])]
    public function generatedFormCasesMaterializeIntoContractValidRequests(array $case): void
    {
        /** @var array{operationKey: string, path: array<string, string>, query: array<string, string>, headers: array<string, string>, cookies: array<string, string>, body: array{mediaType: string, encoding: 'form', value: array<string, mixed>}, misuse: null} $case */
        $value = $this->assertFormCaseConforms($case);

        Classify::cover(array_key_exists('tags', $value) && count((array) $value['tags']) > 1, 'tags with several items', 10.0);
        Classify::cover(array_key_exists('meta', $value), 'meta present', 10.0);
        Classify::cover(!array_key_exists('meta', $value), 'meta absent', 10.0);
        Classify::cover(array_key_exists('active', $value), 'boolean present', 10.0);
    }

    public function formGenerationConformsAcrossSeeds(): void
    {
        $arbitrary = (new RequestCaseArbitrary())->forOperation(BodyContracts::form()->operation('login'));
        $seen = ['several tags' => 0, 'empty tags' => 0, 'meta' => 0, 'no meta' => 0, 'boolean' => 0, 'ratio' => 0];
        foreach (range(1, 80) as $seed) {
            /** @var array{operationKey: string, path: array<string, string>, query: array<string, string>, headers: array<string, string>, cookies: array<string, string>, body: array{mediaType: string, encoding: 'form', value: array<string, mixed>}, misuse: null} $case */
            $case = $arbitrary->generate(new Random($seed))->value;
            $value = $this->assertFormCaseConforms($case);
            $seen['several tags'] += array_key_exists('tags', $value) && count((array) $value['tags']) > 1 ? 1 : 0;
            $seen['empty tags'] += array_key_exists('tags', $value) && $value['tags'] === [] ? 1 : 0;
            $seen['meta'] += array_key_exists('meta', $value) ? 1 : 0;
            $seen['no meta'] += array_key_exists('meta', $value) ? 0 : 1;
            $seen['boolean'] += array_key_exists('active', $value) ? 1 : 0;
            $seen['ratio'] += array_key_exists('ratio', $value) ? 1 : 0;
        }

        foreach ($seen as $label => $count) {
            Assert::true($count > 0, $label . ' never generated');
        }
    }

    /**
     * @param array{operationKey: string, path: array<string, string>, query: array<string, string>, headers: array<string, string>, cookies: array<string, string>, body: array{mediaType: string, encoding: 'form', value: array<string, mixed>}, misuse: null} $case
     * @return array<string, mixed> the logical form value
     */
    private function assertFormCaseConforms(array $case): array
    {
        $contract = BodyContracts::form();
        $operation = $contract->operation('login');
        $factory = new Psr17Factory();
        $request = (new RequestMaterializer($factory, $factory))->materialize($operation, $case);
        $result = $contract->validateRequest($request);

        Assert::true($result->isValid(), (new \Rasuvaeff\OpenApiContract\ValidationResultFormatter())->format($result));
        Assert::same($case['body']['encoding'], 'form');
        Assert::same($case['body']['mediaType'], 'application/x-www-form-urlencoded');
        Assert::same($request->getHeaderLine('Content-Type'), 'application/x-www-form-urlencoded');
        $value = $case['body']['value'];
        Assert::true(array_key_exists('user', $value) && is_string($value['user']) && $value['user'] !== '');
        Assert::same(array_diff(array_keys($value), ['user', 'age', 'ratio', 'active', 'tags', 'meta']), []);
        $wire = (string) $request->getBody();
        Assert::true(!str_contains($wire, 'meta='));
        Assert::true(str_contains($wire, 'user='));
        Assert::same(str_contains($wire, 'tags='), array_key_exists('tags', $value) && $value['tags'] !== []);

        return $value;
    }

    #[DataProvider('failClosedBodyProvider')]
    public function bodyGenerationFailsClosedOnUnsupportedShapes(array $requestBody, string $message): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'POST', path: '/op', requestBody: $requestBody);

        Expect::exception(UnsupportedGeneration::class)->withMessage($message);

        (new RequestCaseArbitrary())->forOperation($operation);
    }

    public static function failClosedBodyProvider(): iterable
    {
        $form = 'application/x-www-form-urlencoded';
        $multipart = 'multipart/form-data';
        $object = ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]];
        yield 'form schema not an object' => [['content' => [$form => ['schema' => ['type' => 'string']]]], 'Form request body schema must be an object'];
        yield 'form encoding is a list' => [['content' => [$form => ['schema' => $object, 'encoding' => [['style' => 'form']]]]], 'Form encoding must be an object'];
        yield 'form encoding entry is a list' => [['content' => [$form => ['schema' => $object, 'encoding' => ['a' => ['form']]]]], 'Form encoding entries must be objects'];
        yield 'form encoding style' => [['content' => [$form => ['schema' => $object, 'encoding' => ['a' => ['style' => 'deepObject']]]]], 'Form encoding supports only form style and boolean explode'];
        yield 'form encoding explode' => [['content' => [$form => ['schema' => $object, 'encoding' => ['a' => ['explode' => 'yes']]]]], 'Form encoding supports only form style and boolean explode'];
        yield 'multipart schema not an object' => [['content' => [$multipart => ['schema' => ['type' => 'array']]]], 'Multipart request body schema must be an object'];
        yield 'multipart encoding is a list' => [['content' => [$multipart => ['schema' => $object, 'encoding' => [['style' => 'form']]]]], 'Multipart encoding must be an object'];
        yield 'multipart encoding with a non-string key' => [['content' => [$multipart => ['schema' => $object, 'encoding' => [7 => ['style' => 'form']]]]], 'Multipart encoding supports only form style'];
        yield 'form encoding with a non-string key' => [['content' => [$form => ['schema' => $object, 'encoding' => [7 => ['style' => 'form']]]]], 'Form encoding entries must be objects'];
        yield 'multipart encoding entry not an object' => [['content' => [$multipart => ['schema' => $object, 'encoding' => ['a' => 'form']]]], 'Multipart encoding supports only form style'];
        yield 'multipart encoding style' => [['content' => [$multipart => ['schema' => $object, 'encoding' => ['a' => ['style' => 'spaceDelimited']]]]], 'Multipart encoding supports only form style'];
        yield 'multipart properties is a list' => [['content' => [$multipart => ['schema' => ['type' => 'object', 'properties' => [['type' => 'string']]]]]], 'Multipart properties must be an object'];
        yield 'multipart required not a list' => [['content' => [$multipart => ['schema' => $object + ['required' => 'a']]]], 'Multipart required must be a list'];
        yield 'multipart required with non-names' => [['content' => [$multipart => ['schema' => $object + ['required' => [1]]]]], 'Multipart required must contain property names'];
        yield 'multipart property not a schema' => [['content' => [$multipart => ['schema' => ['type' => 'object', 'properties' => ['a' => 'string']]]]], 'Multipart properties must contain named schema objects'];
        yield 'multipart nested object' => [['content' => [$multipart => ['schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'properties' => []]]]]]], 'Nested multipart object properties are not supported'];
        yield 'multipart nested array items' => [['content' => [$multipart => ['schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ['type' => 'integer']]]]]]]], 'Nested multipart array items are not supported'];
        yield 'multipart array of objects' => [['content' => [$multipart => ['schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => []]]]]]]], 'Nested multipart array items are not supported'];
        yield 'multipart array items not a schema' => [['content' => [$multipart => ['schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'array', 'items' => ['x']]]]]]], 'Multipart array items must be a schema object'];
        yield 'request body content not an object' => [['content' => 'oops'], 'Request body content must be an object'];
        yield 'no supported media type' => [['content' => ['text/csv' => ['schema' => ['type' => 'string']]]], 'Request body has no supported media type'];
        yield 'json schema is a list' => [['content' => ['application/json' => ['schema' => ['a']]]], 'JSON request body schema must be an object'];
    }

    public function formRequiredPropertyThatIsNotASchemaFailsClosed(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'POST', path: '/op', requestBody: ['required' => true, 'content' => ['application/x-www-form-urlencoded' => ['schema' => ['type' => 'object', 'required' => ['a', 'b'], 'properties' => ['a' => 'string', 'b' => ['type' => 'string']]]]]]);

        Expect::exception(UnsupportedGeneration::class);

        (new RequestCaseArbitrary())->forOperation($operation);
    }

    public function requiredContainersAreGeneratedNonEmptyWhileOptionalOnesMayBeEmpty(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/search' => ['post' => [
                'operationId' => 'search',
                'parameters' => [
                    ['name' => 'req', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]]],
                    ['name' => 'opt', 'in' => 'query', 'schema' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]]],
                    ['name' => 'filter', 'in' => 'query', 'required' => true, 'style' => 'deepObject', 'schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string', 'minLength' => 1], 'b' => ['type' => 'string', 'minLength' => 1]]]],
                ],
                'requestBody' => ['required' => true, 'content' => ['application/x-www-form-urlencoded' => [
                    'schema' => ['type' => 'object', 'required' => ['tags'], 'properties' => ['tags' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]], 'extra' => ['type' => 'array', 'items' => ['type' => 'string', 'minLength' => 1]]]],
                ]]],
                'responses' => ['204' => []],
            ]]],
        ]);
        $operation = $contract->operation('search');
        $arbitrary = (new RequestCaseArbitrary())->forOperation($operation);
        $factory = new Psr17Factory();
        $materializer = new RequestMaterializer($factory, $factory);
        $emptyOptional = 0;
        $emptyExtra = 0;
        foreach (range(1, 80) as $seed) {
            $case = $arbitrary->generate(new Random($seed))->value;
            $result = $contract->validateRequest($materializer->materialize($operation, $case));

            Assert::true($result->isValid(), (new \Rasuvaeff\OpenApiContract\ValidationResultFormatter())->format($result));
            Assert::true(is_array($case['query']['req'] ?? null) && $case['query']['req'] !== []);
            Assert::true(is_array($case['query']['filter'] ?? null) && $case['query']['filter'] !== []);
            Assert::true(is_array($case['body']['value']['tags'] ?? null) && $case['body']['value']['tags'] !== []);
            $emptyOptional += ($case['query']['opt'] ?? null) === [] ? 1 : 0;
            $emptyExtra += ($case['body']['value']['extra'] ?? null) === [] ? 1 : 0;
        }

        Assert::true($emptyOptional > 0, 'optional empty array never generated');
        Assert::true($emptyExtra > 0, 'optional empty form array never generated');
    }

    #[DataProvider('mediaTypeSpellingProvider')]
    public function normalizesFormAndMultipartMediaTypeSpellings(string $mediaType, string $encoding): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'POST', path: '/op', requestBody: ['required' => true, 'content' => [$mediaType => ['schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]]]]);

        $case = (new RequestCaseArbitrary())->forOperation($operation)->generate(new Random(2))->value;

        Assert::same($case['body']['encoding'] ?? null, $encoding);
        Assert::same($case['body']['mediaType'] ?? null, $mediaType);
    }

    public static function mediaTypeSpellingProvider(): iterable
    {
        yield 'form with parameters and case' => ['Application/X-WWW-Form-Urlencoded ; charset=utf-8', 'form'];
        yield 'multipart with parameters and case' => ['Multipart/Form-Data ; boundary=x', 'multipart'];
    }

    public function binaryPartsFollowTheFormatWhenTheTypeIsOmittedButNotWhenItIsNotAString(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'POST', path: '/op', requestBody: ['required' => true, 'content' => ['multipart/form-data' => ['schema' => ['type' => 'object', 'required' => ['blob', 'code'], 'properties' => [
            'blob' => ['format' => 'binary'],
            'code' => ['type' => 'integer', 'format' => 'binary', 'minimum' => 1, 'maximum' => 9],
        ]]]]]);

        $case = (new RequestCaseArbitrary())->forOperation($operation)->generate(new Random(4))->value;
        $parts = [];
        foreach ($case['body']['parts'] ?? [] as $part) {
            $parts[$part['name']] = [$part['encoding'], $part['contentType']];
        }

        Assert::same($parts, ['blob' => ['base64', 'application/octet-stream'], 'code' => ['text', 'application/octet-stream']]);
    }

    public function parameterValuesWithNestedObjectsFailClosed(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/pets' => ['get' => [
                'operationId' => 'pets.list',
                'parameters' => [['name' => 'filter', 'in' => 'query', 'required' => true, 'style' => 'deepObject', 'schema' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'object', 'required' => ['b'], 'properties' => ['b' => ['type' => 'string']]]]]]],
                'responses' => ['200' => []],
            ]]],
        ]);

        Expect::exception(UnsupportedGeneration::class)->withMessage('Parameter values must be scalar, arrays, or objects with scalar properties');

        (new RequestCaseArbitrary())->forOperation($contract->operation('pets.list'))->generate(new Random(1));
    }

    public function multipartWithoutPropertiesProducesAnEmptyPartList(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'POST', path: '/op', requestBody: ['required' => true, 'content' => ['multipart/form-data' => ['schema' => ['type' => 'object']]]]);

        $case = (new RequestCaseArbitrary())->forOperation($operation)->generate(new Random(3))->value;

        Assert::same($case['body']['parts'] ?? null, []);
        Assert::same($case['body']['encoding'] ?? null, 'multipart');
    }
}
