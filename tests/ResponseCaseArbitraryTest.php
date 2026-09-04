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
            Assert::same($response->getHeaderLine('X-Tags'), implode(',', array_map(rawurlencode(...), $case['headers']['X-Tags'])));
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
        Assert::true(!array_key_exists('secret', $body['schema']['properties'] ?? ['secret' => true]));
        Assert::same($body['schema']['required'] ?? null, ['id', 'name', 'status', 'kind', 'tags']);
        Assert::same((new ResponseCaseArbitrary())->jsonBody(ResponseContracts::pets()->operation('ping'), 204), null);
    }

    public function writeOnlyPropertiesLeaveNestedSchemasToo(): void
    {
        $schema = (new ResponseSchemas())->effective([
            'type' => 'object',
            'required' => ['w', 'a'],
            'properties' => [
                'w' => ['type' => 'string', 'writeOnly' => true],
                'a' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['w', 'x'], 'properties' => ['x' => ['type' => 'string'], 'w' => ['type' => 'string', 'writeOnly' => true]]]],
                'c' => ['allOf' => [['type' => 'object', 'properties' => ['w' => ['writeOnly' => true, 'type' => 'string'], 'k' => ['type' => 'string']]], 'not-a-schema']],
                'd' => ['oneOf' => [['type' => 'object', 'properties' => ['w' => ['writeOnly' => true, 'type' => 'string']]]]],
            ],
        ]);

        Assert::same($schema['required'], ['a']);
        Assert::same(array_keys($schema['properties']), ['a', 'c', 'd']);
        Assert::same($schema['properties']['a']['items']['required'], ['x']);
        Assert::same(array_keys($schema['properties']['a']['items']['properties']), ['x']);
        Assert::same(array_keys($schema['properties']['c']['allOf'][0]['properties']), ['k']);
        Assert::same($schema['properties']['c']['allOf'][1], 'not-a-schema');
        // Dropping the last property drops `properties` itself — the same
        // reading `openapi-contract` applies, so an empty map never forbids
        // what the document left open.
        Assert::false(array_key_exists('properties', $schema['properties']['d']['oneOf'][0]));
    }

    public function malformedSchemaShapesPassThroughTheResponseView(): void
    {
        $schemas = new ResponseSchemas();

        Assert::same($schemas->effective(['properties' => 'x']), ['properties' => 'x']);
        Assert::same($schemas->effective(['items' => 'x']), ['items' => 'x']);
        Assert::same($schemas->effective(['properties' => ['bad' => ['x'], 'w' => ['type' => 'string', 'writeOnly' => true], 'k' => ['type' => 'string']], 'required' => ['w', 'k', 7]]), ['properties' => ['bad' => ['x'], 'k' => ['type' => 'string']], 'required' => ['k', 7]]);
        Assert::same($schemas->effective(['properties' => ['a' => ['type' => 'string']], 'required' => 'x']), ['properties' => ['a' => ['type' => 'string']], 'required' => 'x']);
        Assert::same($schemas->effective(['items' => ['a', 'b']]), ['items' => ['a', 'b']]);
        Assert::same($schemas->effective(['allOf' => 'x']), ['allOf' => 'x']);
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
