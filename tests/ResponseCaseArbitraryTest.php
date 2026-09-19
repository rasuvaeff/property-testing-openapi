<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Contract;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\OpenApiContract\ValidationResultFormatter;
use Rasuvaeff\PropertyTesting\Classify;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\ResponseSchemas;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseCaseArbitrary;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\ResponseContracts;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Rasuvaeff\PropertyTesting\Property;
use Rasuvaeff\PropertyTesting\Random;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ResponseCaseArbitrary::class)]
#[Covers(ResponseSchemas::class)]
final class ResponseCaseArbitraryTest
{
    #[Property(runs: 120, generators: [ResponseContracts::class, 'petCase'])]
    public function generatedResponsesAreAcceptedByTheContract(array $case): void
    {
        /** @var array{operationKey: string, status: int, headers: array<string, string|list<string>>, body: array{mediaType: string, encoding: 'json', value: array<string, mixed>}, misuse: null} $case */
        $this->assertPetCaseConforms($case);

        Classify::cover(array_key_exists('X-Trace', $case['headers']), 'optional header present', 10.0);
        Classify::cover(!array_key_exists('X-Trace', $case['headers']), 'optional header absent', 10.0);
        Classify::cover(array_key_exists('owner', $case['body']['value']), 'nested object present', 10.0);
        Classify::cover(array_key_exists('slug', $case['body']['value']), 'pattern property present', 10.0);
        Classify::cover(is_array($case['headers']['X-Tags'] ?? null) && count($case['headers']['X-Tags']) > 1, 'list header with several items', 5.0);
    }

    public function generationConformsAcrossSeeds(): void
    {
        $arbitrary = (new ResponseCaseArbitrary())->forOperation(ResponseContracts::pets()->operation('pets.get'), 200);
        $seen = ['trace' => 0, 'no trace' => 0, 'owner' => 0, 'slug' => 0, 'list header' => 0];
        foreach (range(1, 80) as $seed) {
            /** @var array{operationKey: string, status: int, headers: array<string, string|list<string>>, body: array{mediaType: string, encoding: 'json', value: array<string, mixed>}, misuse: null} $case */
            $case = $arbitrary->generate(new Random($seed))->value;
            $this->assertPetCaseConforms($case);
            $seen['trace'] += array_key_exists('X-Trace', $case['headers']) ? 1 : 0;
            $seen['no trace'] += array_key_exists('X-Trace', $case['headers']) ? 0 : 1;
            $seen['owner'] += array_key_exists('owner', $case['body']['value']) ? 1 : 0;
            $seen['slug'] += array_key_exists('slug', $case['body']['value']) ? 1 : 0;
            $seen['list header'] += is_array($case['headers']['X-Tags'] ?? null) && count($case['headers']['X-Tags']) > 1 ? 1 : 0;
        }

        foreach ($seen as $label => $count) {
            Assert::true($count > 0, $label . ' never generated');
        }
    }

    /** @param array{operationKey: string, status: int, headers: array<string, string|list<string>>, body: array{mediaType: string, encoding: 'json', value: array<string, mixed>}, misuse: null} $case */
    private function assertPetCaseConforms(array $case): void
    {
        $contract = ResponseContracts::pets();
        $operation = $contract->operation('pets.get');
        $factory = new Psr17Factory();
        $response = (new ResponseMaterializer($factory, $factory))->materialize($operation, $case);
        $result = $contract->validateResponse('pets.get', $response);

        Assert::true($result->isValid(), (new ValidationResultFormatter())->format($result));
        Assert::same($case['operationKey'], 'pets.get');
        Assert::same($case['status'], 200);
        Assert::same($case['misuse'], null);
        Assert::same(preg_match('/^(?:[0-9]|[1-9][0-9]{1,2}|1000)\z/', (string) ($case['headers']['X-Rate-Limit'] ?? '')), 1);
        Assert::true(in_array($case['headers']['X-Flag'] ?? null, ['true', 'false'], strict: true));
        $ids = $case['headers']['X-Ids'] ?? null;
        Assert::true(is_array($ids) && $ids !== [] && count($ids) <= 3);
        foreach (is_array($ids) ? $ids : [] as $id) {
            Assert::same(preg_match('/^[0-9]\z/', is_string($id) ? $id : gettype($id)), 1);
        }
        Assert::true(!array_key_exists('X-Doc', $case['headers']));
        Assert::same($case['body']['mediaType'], 'application/json');
        Assert::same($case['body']['encoding'], 'json');
        Assert::true(!array_key_exists('secret', $case['body']['value']));
        Assert::same(array_diff(['id', 'name', 'status', 'kind', 'tags'], array_keys($case['body']['value'])), []);
        Assert::same($case['body']['value']['kind'], 'pet');
        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');
        if (isset($case['headers']['X-Tags']) && is_array($case['headers']['X-Tags'])) {
            // Read as sent on both sides now: the comma still separates, and
            // nothing else is touched (openapi-contract#66).
            Assert::same($response->getHeaderLine('X-Tags'), implode(',', $case['headers']['X-Tags']));
        }
    }

    public function generatesTheBodyOfARangeResponseWithAStructuredJsonSuffix(): void
    {
        $contract = ResponseContracts::pets();
        $operation = $contract->operation('pets.get');
        $factory = new Psr17Factory();
        $materializer = new ResponseMaterializer($factory, $factory);

        $case = (new ResponseCaseArbitrary())->forOperation($operation, 404)->generate(new Random(3))->value;

        Assert::same($case['status'], 404);
        Assert::same($case['headers'], []);
        Assert::same($case['body']['mediaType'] ?? null, 'application/problem+json');
        Assert::true($contract->validateResponse('pets.get', $materializer->materialize($operation, $case))->isValid());
    }

    public function generatesNoBodyForAResponseWithoutContent(): void
    {
        $contract = ResponseContracts::pets();
        $operation = $contract->operation('ping');
        $factory = new Psr17Factory();

        $case = (new ResponseCaseArbitrary())->forOperation($operation, 204)->generate(new Random(1))->value;
        $response = (new ResponseMaterializer($factory, $factory))->materialize($operation, $case);

        Assert::same($case, ['operationKey' => 'ping', 'status' => 204, 'headers' => [], 'body' => null, 'misuse' => null]);
        Assert::same($response->getHeaderLine('Content-Type'), '');
        Assert::same((string) $response->getBody(), '');
        Assert::true($contract->validateResponse('ping', $response)->isValid());
    }

    public function generatesScalarAndListRoots(): void
    {
        $contract = ResponseContracts::pets();
        $factory = new Psr17Factory();
        $materializer = new ResponseMaterializer($factory, $factory);
        foreach (['pets.count', 'pets.list'] as $key) {
            $operation = $contract->operation($key);
            foreach (range(1, 20) as $seed) {
                $case = (new ResponseCaseArbitrary())->forOperation($operation, 200)->generate(new Random($seed))->value;
                $response = $materializer->materialize($operation, $case);

                Assert::true($contract->validateResponse($key, $response)->isValid());
            }
        }
    }

    public function jsonBodyExposesTheResponseDirectionSchema(): void
    {
        $operation = ResponseContracts::pets()->operation('pets.get');

        $body = (new ResponseCaseArbitrary())->jsonBody($operation, 200);

        Assert::same($body['mediaType'] ?? null, 'application/json');
        // The `writeOnly` property stays declared — the contract still types
        // it — and only stops being required; the generator never emits it.
        Assert::true(array_key_exists('secret', $body['schema']['properties'] ?? []));
        Assert::same($body['schema']['required'] ?? null, ['id', 'name', 'status', 'kind', 'tags']);
        Assert::same((new ResponseCaseArbitrary())->jsonBody(ResponseContracts::pets()->operation('ping'), 204), null);
    }

    public function writeOnlyPropertiesAreNeverGeneratedAndTheirNamesStayReserved(): void
    {
        $operation = ResponseContracts::pets()->operation('pets.get');

        foreach (range(1, 40) as $seed) {
            $case = (new ResponseCaseArbitrary())->forOperation($operation, 200)->generate(new Random($seed))->value;

            Assert::false(array_key_exists('secret', (array) ($case['body']['value'] ?? [])));
        }
    }

    #[DataProvider('unsupportedProvider')]
    public function failsClosedOnUnsupportedResponses(string $operationKey, int $status, string $message): void
    {
        $operation = ResponseContracts::pets()->operation($operationKey);

        Expect::exception(UnsupportedGeneration::class)->withMessage($message);

        (new ResponseCaseArbitrary())->forOperation($operation, $status);
    }

    public static function unsupportedProvider(): iterable
    {
        yield 'undeclared status' => ['ping', 500, 'Operation "ping" declares no response for status 500'];
        yield 'no JSON media type' => ['ping', 503, 'Response content declares no JSON media type'];
    }

    #[DataProvider('handBuiltUnsupportedProvider')]
    public function failsClosedOnUnsupportedHandBuiltResponses(array $responses, string $message): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: $responses);

        Expect::exception(UnsupportedGeneration::class)->withMessage($message);

        (new ResponseCaseArbitrary())->forOperation($operation, 200)->generate(new Random(1));
    }

    public static function handBuiltUnsupportedProvider(): iterable
    {
        yield 'required header without schema' => [['200' => ['headers' => ['X-Req' => ['required' => true]]]], 'Required response header "X-Req" has no schema object'];
        yield 'list of objects header' => [['200' => ['headers' => ['X-List' => ['required' => true, 'schema' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]]]]]]], 'Response header "X-List" must carry scalar values'];
        yield 'object header value' => [['200' => ['headers' => ['X-Obj' => ['required' => true, 'schema' => ['type' => 'object', 'required' => ['a'], 'properties' => ['a' => ['type' => 'string']]]]]]], 'Response header "X-Obj" cannot carry an object value'];
        yield 'headers not an object' => [['200' => ['headers' => 'oops']], 'Response headers must be an object'];
        yield 'JSON schema is a list' => [['200' => ['content' => ['application/json' => ['schema' => ['a']]]]], 'Response "application/json" JSON schema must be an object'];
    }

    public function skipsMalformedContentEntriesAndUsesTheFirstJsonOne(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => [
            'text/html' => ['schema' => ['type' => 'string']],
            'application/xml' => 'oops',
            'Application/VND.API+JSON ; charset=utf-8' => ['schema' => ['type' => 'object', 'properties' => ['a' => ['type' => 'boolean']]]],
            'application/json' => ['schema' => ['type' => 'integer']],
        ]]]);

        $case = (new ResponseCaseArbitrary())->forOperation($operation, 200)->generate(new Random(1))->value;

        Assert::same($case['body']['mediaType'] ?? null, 'Application/VND.API+JSON ; charset=utf-8');
        Assert::same((new ResponseCaseArbitrary())->jsonBody($operation, 200)['mediaType'] ?? null, 'Application/VND.API+JSON ; charset=utf-8');
    }

    public function jsonBodyFailsClosedWhenContentDeclaresNoJson(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Response for status 503 of operation "ping" declares no JSON media type');

        (new ResponseCaseArbitrary())->jsonBody(ResponseContracts::pets()->operation('ping'), 503);
    }

    public function headerValuesRenderEveryScalarKind(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['headers' => [
            'X-F' => ['required' => true, 'schema' => ['type' => 'number', 'minimum' => 0.5, 'maximum' => 0.5]],
            'X-N' => ['required' => true, 'schema' => ['type' => 'null']],
            'X-B' => ['required' => true, 'schema' => ['type' => 'boolean', 'enum' => [true]]],
        ]]]);

        $case = (new ResponseCaseArbitrary())->forOperation($operation, 200)->generate(new Random(3))->value;

        Assert::same($case['headers'], ['X-F' => '0.5', 'X-N' => 'null', 'X-B' => 'true']);
    }

    /**
     * A comma separates only the members of a list header; a scalar response
     * header carries it as sent, and a list member carrying one is dropped
     * from its enum (#129).
     */
    public function aCommaSeparatesOnlyTheMembersOfAListHeader(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['headers' => [
            'X-Expr' => ['required' => true, 'schema' => ['type' => 'string', 'enum' => ['a,b']]],
            'X-Kinds' => ['required' => true, 'schema' => ['type' => 'array', 'minItems' => 1, 'items' => ['type' => 'string', 'enum' => ['x,y', 'z']]]],
        ]]]);

        foreach (range(1, 10) as $seed) {
            $case = (new ResponseCaseArbitrary())->forOperation($operation, 200)->generate(new Random($seed))->value;

            Assert::same($case['headers']['X-Expr'], 'a,b');
            Assert::same(array_unique((array) $case['headers']['X-Kinds']), ['z']);
        }
    }

    public function rejectsAStatusOutsideTheHttpRange(): void
    {
        Expect::exception(\InvalidArgumentException::class);

        (new ResponseCaseArbitrary())->forOperation(ResponseContracts::pets()->operation('ping'), 42);
    }

    public function contractLevelSelectionMatchesValidation(): void
    {
        $contract = Contract::fromArray([
            'openapi' => '3.1.0',
            'paths' => ['/x' => ['get' => ['operationId' => 'x', 'responses' => ['2XX' => ['content' => ['application/json' => ['schema' => ['type' => 'boolean']]]], 'default' => ['content' => ['application/json' => ['schema' => ['type' => 'string']]]]]]]],
        ]);
        $operation = $contract->operation('x');

        Assert::true(is_bool((new ResponseCaseArbitrary())->forOperation($operation, 299)->generate(new Random(1))->value['body']['value'] ?? null));
        Assert::true(is_string((new ResponseCaseArbitrary())->forOperation($operation, 500)->generate(new Random(1))->value['body']['value'] ?? null));
    }
}
