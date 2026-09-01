<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\ArbitraryInterface;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\Negative\ResponseTargets;
use Rasuvaeff\PropertyTesting\OpenApi\NegativeResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\ResponseContracts;
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
#[Covers(NegativeResponseCaseArbitrary::class)]
#[Covers(ResponseTargets::class)]
final class NegativeResponseCaseArbitraryTest
{
    #[DataProvider('categoryProvider')]
    public function everyCategoryIsProvenInvalidByTheContract(string $method, string $operationKey, int $status, array $misuse, string $code): void
    {
        $contract = ResponseContracts::pets();
        $operation = $contract->operation($operationKey);
        $factory = new Psr17Factory();
        $materializer = new ResponseMaterializer($factory, $factory);
        /** @var ArbitraryInterface<array{operationKey: string, status: int, headers: array<string, string|list<string>>, body: null|array{mediaType: string, encoding: 'json'|'raw', value: mixed}, misuse: null|array{kind: non-empty-string, location: 'status'|'header'|'body', name: string}}> $arbitrary */
        $arbitrary = (new NegativeResponseCaseArbitrary())->{$method}($operation, $status);

        foreach (range(1, 25) as $seed) {
            $case = $arbitrary->generate(new Random($seed))->value;
            $result = $contract->validateResponse($operationKey, $materializer->materialize($operation, $case));

            Assert::same($case['misuse'], $misuse);
            Assert::false($result->isValid());
            Assert::same($result->violations[0]->code, $code);
        }
    }

    public static function categoryProvider(): iterable
    {
        yield 'undeclared status' => ['undeclaredStatusForOperation', 'pets.get', 200, ['kind' => 'undeclared-status', 'location' => 'status', 'name' => '599'], 'response.status.mismatch'];
        yield 'missing required header' => ['missingRequiredHeaderForOperation', 'pets.get', 200, ['kind' => 'missing-required', 'location' => 'header', 'name' => 'X-Rate-Limit'], 'response.header.missing'];
        yield 'missing required property' => ['missingRequiredForOperation', 'pets.get', 200, ['kind' => 'missing-required', 'location' => 'body', 'name' => 'id'], 'response.body.schema'];
        yield 'type' => ['typeMismatchForOperation', 'pets.get', 200, ['kind' => 'type', 'location' => 'body', 'name' => 'id'], 'response.body.schema'];
        yield 'enum' => ['enumMismatchForOperation', 'pets.get', 200, ['kind' => 'enum', 'location' => 'body', 'name' => 'status'], 'response.body.schema'];
        yield 'const' => ['constMismatchForOperation', 'pets.get', 200, ['kind' => 'const', 'location' => 'body', 'name' => 'kind'], 'response.body.schema'];
        yield 'boundary' => ['boundaryMismatchForOperation', 'pets.get', 200, ['kind' => 'boundary', 'location' => 'body', 'name' => 'id'], 'response.body.schema'];
        yield 'length' => ['lengthMismatchForOperation', 'pets.get', 200, ['kind' => 'length', 'location' => 'body', 'name' => 'name'], 'response.body.schema'];
        yield 'pattern' => ['patternMismatchForOperation', 'pets.get', 200, ['kind' => 'pattern', 'location' => 'body', 'name' => 'slug'], 'response.body.schema'];
        yield 'additional property' => ['additionalPropertyForOperation', 'pets.get', 200, ['kind' => 'additional-properties', 'location' => 'body', 'name' => '__openapi_extra_property__'], 'response.body.schema'];
        yield 'media type' => ['mediaTypeMismatchForOperation', 'pets.get', 200, ['kind' => 'media-type', 'location' => 'body', 'name' => 'body'], 'response.body.media_type'];
        yield 'malformed JSON' => ['malformedJsonForOperation', 'pets.get', 200, ['kind' => 'json-syntax', 'location' => 'body', 'name' => 'body'], 'response.body.json'];
        yield 'scalar root boundary' => ['boundaryMismatchForOperation', 'pets.count', 200, ['kind' => 'boundary', 'location' => 'body', 'name' => '$'], 'response.body.schema'];
        yield 'scalar root type' => ['typeMismatchForOperation', 'pets.count', 200, ['kind' => 'type', 'location' => 'body', 'name' => '$'], 'response.body.schema'];
        yield 'list root length' => ['lengthMismatchForOperation', 'pets.list', 200, ['kind' => 'length', 'location' => 'body', 'name' => '$'], 'response.body.schema'];
        yield 'range response missing property' => ['missingRequiredForOperation', 'pets.get', 404, ['kind' => 'missing-required', 'location' => 'body', 'name' => 'title'], 'response.body.schema'];
    }

    public function theUnionCoversEveryConstructibleCategory(): void
    {
        $contract = ResponseContracts::pets();
        $operation = $contract->operation('pets.get');
        $factory = new Psr17Factory();
        $materializer = new ResponseMaterializer($factory, $factory);
        $arbitrary = (new NegativeResponseCaseArbitrary())->forOperation($operation, 200);
        $kinds = [];
        foreach (range(1, 120) as $seed) {
            $case = $arbitrary->generate(new Random($seed))->value;
            $kinds[$case['misuse']['kind'] ?? 'none'] = true;

            Assert::false($contract->validateResponse('pets.get', $materializer->materialize($operation, $case))->isValid());
        }
        ksort($kinds);

        Assert::same(array_keys($kinds), ['additional-properties', 'boundary', 'const', 'enum', 'json-syntax', 'length', 'media-type', 'missing-required', 'pattern', 'type', 'undeclared-status']);
    }

    #[Property(runs: 20)]
    public function shrinkPreservesMisuseCategoryAndInvalidity(int $seed): void
    {
        $contract = ResponseContracts::pets();
        $operation = $contract->operation('pets.get');
        $factory = new Psr17Factory();
        $materializer = new ResponseMaterializer($factory, $factory);
        $negative = new NegativeResponseCaseArbitrary();
        $observed = 0;
        foreach (['typeMismatchForOperation', 'lengthMismatchForOperation', 'missingRequiredHeaderForOperation', 'undeclaredStatusForOperation', 'additionalPropertyForOperation'] as $method) {
            /** @var Shrinkable<array{operationKey: string, status: int, headers: array<string, string|list<string>>, body: null|array{mediaType: string, encoding: 'json'|'raw', value: mixed}, misuse: null|array{kind: non-empty-string, location: 'status'|'header'|'body', name: string}}> $root */
            $root = $negative->{$method}($operation, 200)->generate(new Random($seed));
            $expected = $root->value['misuse'];

            Assert::false($contract->validateResponse('pets.get', $materializer->materialize($operation, $root->value))->isValid());

            foreach ($this->shrinkCandidates($root, budget: 8) as $candidate) {
                ++$observed;
                Assert::same($candidate['misuse'], $expected);
                Assert::false($contract->validateResponse('pets.get', $materializer->materialize($operation, $candidate))->isValid());
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

    #[DataProvider('unsupportedProvider')]
    public function failsClosedWithoutAConstructibleWitness(string $method, string $operationKey, int $status, string $message): void
    {
        $operation = ResponseContracts::pets()->operation($operationKey);

        Expect::exception(UnsupportedGeneration::class)->withMessage($message);

        (new NegativeResponseCaseArbitrary())->{$method}($operation, $status);
    }

    public static function unsupportedProvider(): iterable
    {
        yield 'default response declares every status' => ['undeclaredStatusForOperation', 'pets.list', 200, 'Operation "pets.list" declares a default response; every status is declared'];
        yield 'no required header' => ['missingRequiredHeaderForOperation', 'pets.list', 200, 'Response for status 200 of operation "pets.list" declares no required header'];
        yield 'no JSON body' => ['malformedJsonForOperation', 'ping', 204, 'Response for status 204 of operation "ping" has no JSON body for a malformed JSON case'];
        yield 'no required property on a list root' => ['missingRequiredForOperation', 'pets.list', 200, 'Response for status 200 of operation "pets.list" has no required body property'];
        yield 'additional properties allowed' => ['additionalPropertyForOperation', 'pets.list', 200, 'Response for status 200 of operation "pets.list" does not reject additional properties'];
        yield 'no enum' => ['enumMismatchForOperation', 'pets.count', 200, 'Response for status 200 of operation "pets.count" has no body value with a constructible enum mismatch'];
        yield 'no const' => ['constMismatchForOperation', 'pets.list', 200, 'Response for status 200 of operation "pets.list" has no body value with a constructible const mismatch'];
        yield 'no pattern' => ['patternMismatchForOperation', 'pets.count', 200, 'Response for status 200 of operation "pets.count" has no body value with a constructible pattern mismatch'];
        yield 'no length constraint' => ['lengthMismatchForOperation', 'pets.count', 200, 'Response for status 200 of operation "pets.count" has no body value with a constructible length mismatch'];
    }

    public function aDefaultOnlyResponseSupportsNoCategory(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['default' => ['description' => 'anything']]);

        Expect::exception(UnsupportedGeneration::class)->withMessage('Response for status 204 of operation "op" supports no constructible negative case category');

        (new NegativeResponseCaseArbitrary())->forOperation($operation, 204);
    }

    #[DataProvider('typeWitnessProvider')]
    public function typeWitnessesCoverEveryDeclaredType(string $type): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/op' => ['get' => ['operationId' => 'op', 'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['p'], 'properties' => ['p' => $type === 'array' ? ['type' => 'array', 'items' => ['type' => 'integer']] : ['type' => $type]]]]]]]]]],
        ]);
        $operation = $contract->operation('op');
        $factory = new Psr17Factory();

        $case = (new NegativeResponseCaseArbitrary())->typeMismatchForOperation($operation, 200)->generate(new Random(1))->value;

        Assert::same($case['misuse'], ['kind' => 'type', 'location' => 'body', 'name' => 'p']);
        Assert::false($contract->validateResponse('op', (new ResponseMaterializer($factory, $factory))->materialize($operation, $case))->isValid());
    }

    public static function typeWitnessProvider(): iterable
    {
        foreach (['string', 'integer', 'number', 'boolean', 'null', 'array', 'object'] as $type) {
            yield $type => [$type];
        }
    }

    public function optionalOnlyHeadersSupportNoMissingHeaderCategory(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['headers' => ['X-Opt' => ['required' => false, 'schema' => ['type' => 'string']]], 'content' => ['application/json' => ['schema' => ['type' => 'object']]]]]);

        Expect::exception(UnsupportedGeneration::class)->withMessage('Response for status 200 of operation "op" declares no required header');

        (new NegativeResponseCaseArbitrary())->missingRequiredHeaderForOperation($operation, 200);
    }

    public function anObjectBodyWithoutClosedAdditionalPropertiesSupportsNoAdditionalPropertyCategory(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Response for status 404 of operation "pets.get" does not reject additional properties');

        (new NegativeResponseCaseArbitrary())->additionalPropertyForOperation(ResponseContracts::pets()->operation('pets.get'), 404);
    }

    public function anObjectWithoutPropertiesSupportsNoTypeWitness(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object']]]]]);

        Expect::exception(UnsupportedGeneration::class)->withMessage('Response for status 200 of operation "op" has no body value with a constructible type mismatch');

        (new NegativeResponseCaseArbitrary())->typeMismatchForOperation($operation, 200);
    }

    public function aBodylessResponseNamesTheCategoryInItsDiagnostics(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Response for status 204 of operation "ping" has no JSON body for a type mismatch');

        (new NegativeResponseCaseArbitrary())->typeMismatchForOperation(ResponseContracts::pets()->operation('ping'), 204);
    }

    public function witnessesAvoidCollidingWithDeclaredValues(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => [
            'application/x-openapi-misuse' => ['schema' => ['type' => 'object']],
            'application/json' => ['schema' => ['type' => 'object', 'required' => ['e'], 'properties' => ['e' => ['type' => 'string', 'enum' => ['__openapi_misuse__', 'a']]]]],
        ]]]);
        $negative = new NegativeResponseCaseArbitrary();

        $enum = $negative->enumMismatchForOperation($operation, 200)->generate(new Random(1))->value;
        $media = $negative->mediaTypeMismatchForOperation($operation, 200)->generate(new Random(1))->value;

        Assert::same($enum['body']['value']['e'] ?? null, '__openapi_misuse___');
        Assert::same($media['body']['mediaType'] ?? null, 'application/x-openapi-misuse-x');
    }

    #[DataProvider('lengthBoundaryProvider')]
    public function lengthWitnessBoundaries(array $schema, ?int $expectedCount): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => $schema]]]]]]);
        $negative = new NegativeResponseCaseArbitrary();

        if ($expectedCount === null) {
            Expect::exception(UnsupportedGeneration::class)->withMessage('Response for status 200 of operation "op" has no body value with a constructible length mismatch');
            $negative->lengthMismatchForOperation($operation, 200);

            return;
        }

        $case = $negative->lengthMismatchForOperation($operation, 200)->generate(new Random(1))->value;

        Assert::same(count((array) ($case['body']['value']['a'] ?? null)), $expectedCount);
    }

    public static function lengthBoundaryProvider(): iterable
    {
        yield 'minItems only' => [['type' => 'array', 'minItems' => 2, 'items' => ['type' => 'integer']], 0];
        yield 'minItems of one' => [['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'integer']], 0];
        yield 'maxItems at the construction budget' => [['type' => 'array', 'maxItems' => 64, 'items' => ['type' => 'integer']], null];
        yield 'unconstrained array' => [['type' => 'array', 'items' => ['type' => 'integer']], null];
    }

    public function wildcardMediaTypesCannotPromiseAMismatch(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object']], '*/*' => ['schema' => ['type' => 'string']]]]]);

        Expect::exception(UnsupportedGeneration::class)->withMessage('Operation "op" declares wildcard media type "*/*"; an undeclared media type cannot be promised');

        (new NegativeResponseCaseArbitrary())->mediaTypeMismatchForOperation($operation, 200);
    }

    public function everyCandidateStatusDeclaredFailsClosed(): void
    {
        $responses = [];
        foreach ([599, 499, 399, 299, 199, 598, 498, 398, 298, 198] as $status) {
            $responses[(string) $status] = ['description' => 'declared'];
        }
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: $responses);

        Expect::exception(UnsupportedGeneration::class)->withMessage('Operation "op" declares every candidate status');

        (new NegativeResponseCaseArbitrary())->undeclaredStatusForOperation($operation, 599);
    }

    public function skipsNullableAndNegatedSchemasAndTypeUnions(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => [
            'n' => ['type' => 'integer', 'nullable' => true, 'minimum' => 1],
            'u' => ['type' => ['integer', 'string']],
            'x' => ['type' => 'string', 'not' => ['const' => 'a']],
            'e' => ['type' => 'string', 'enum' => [['nested']]],
            'c' => ['type' => 'object', 'const' => ['a' => 1]],
            'k' => ['type' => 'integer', 'enum' => [1, 2]],
        ]]]]]]);
        $negative = new NegativeResponseCaseArbitrary();

        foreach (['typeMismatchForOperation', 'boundaryMismatchForOperation', 'constMismatchForOperation'] as $method) {
            try {
                $negative->{$method}($operation, 200);
                Assert::true(actual: false, message: $method . ' should fail closed');
            } catch (UnsupportedGeneration) {
                Assert::true(actual: true);
            }
        }

        $case = $negative->enumMismatchForOperation($operation, 200)->generate(new Random(1))->value;

        Assert::same($case['misuse'], ['kind' => 'enum', 'location' => 'body', 'name' => 'k']);
        Assert::same($case['body']['value']['k'] ?? null, '__openapi_misuse__');
    }

    public function lengthWitnessesCoverStringsAndArrays(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['a', 'b'], 'properties' => [
            'a' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'integer']],
            'b' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ]]]]]]);
        $factory = new Psr17Factory();
        $contract = Contract::fromArray(['openapi' => '3.1.0', 'paths' => ['/op' => ['get' => ['operationId' => 'op', 'responses' => ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['a', 'b'], 'properties' => [
            'a' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'integer']],
            'b' => ['type' => 'array', 'items' => ['type' => 'integer']],
        ]]]]]]]]]]);

        $case = (new NegativeResponseCaseArbitrary())->lengthMismatchForOperation($operation, 200)->generate(new Random(1))->value;

        Assert::same($case['misuse'], ['kind' => 'length', 'location' => 'body', 'name' => 'a']);
        Assert::same(count((array) ($case['body']['value']['a'] ?? [])), 3);
        Assert::false($contract->validateResponse('op', (new ResponseMaterializer($factory, $factory))->materialize($contract->operation('op'), $case))->isValid());
    }

    public function constWitnessAppendsToStringsAndReplacesOtherScalars(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['n'], 'properties' => ['n' => ['type' => 'integer', 'const' => 7]]]]]]]);

        $case = (new NegativeResponseCaseArbitrary())->constMismatchForOperation($operation, 200)->generate(new Random(1))->value;

        Assert::same($case['body']['value']['n'] ?? null, '__openapi_misuse__');

        $case = (new NegativeResponseCaseArbitrary())->constMismatchForOperation(ResponseContracts::pets()->operation('pets.get'), 200)->generate(new Random(1))->value;

        Assert::same($case['body']['value']['kind'] ?? null, 'pet__openapi_misuse__');
    }

    /**
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
}
