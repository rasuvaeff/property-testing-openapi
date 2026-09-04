<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\Internal\JsonBodyEncoder;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseMaterializer;
use Rasuvaeff\PropertyTesting\OpenApi\Tests\Support\ResponseContracts;
use Rasuvaeff\PropertyTesting\OpenApi\UnsupportedGeneration;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Expect;
use Testo\Test;

#[Test]
#[Covers(ResponseMaterializer::class)]
#[Covers(JsonBodyEncoder::class)]
final class ResponseMaterializerTest
{
    public function materializesStatusHeadersAndASchemaAwareJsonBody(): void
    {
        $contract = ResponseContracts::pets();
        $operation = $contract->operation('pets.get');
        $response = $this->materializer()->materialize($operation, [
            'operationKey' => 'pets.get',
            'status' => 200,
            'headers' => ['X-Rate-Limit' => '5', 'X-Flag' => 'true', 'X-Ids' => ['1', '2'], 'X-Tags' => ['a', 'b']],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['id' => 1, 'name' => 'Rex', 'status' => 'active', 'kind' => 'pet', 'tags' => ['x'], 'owner' => ['name' => 'Ann']]],
            'misuse' => null,
        ]);

        Assert::same($response->getStatusCode(), 200);
        Assert::same($response->getHeaderLine('X-Rate-Limit'), '5');
        Assert::same($response->getHeaderLine('X-Tags'), 'a,b');
        Assert::same($response->getHeaderLine('X-Ids'), '1,2');
        Assert::same($response->getHeaderLine('Content-Type'), 'application/json');
        Assert::same((string) $response->getBody(), '{"id":1,"name":"Rex","status":"active","kind":"pet","tags":["x"],"owner":{"name":"Ann"}}');
        Assert::true($response->getBody()->isSeekable());
        Assert::true($contract->validateResponse('pets.get', $response)->isValid());
    }

    public function serializesHeadersWithTheSimpleStyle(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['204' => []]);

        $response = $this->materializer()->materialize($operation, [
            'operationKey' => 'op',
            'status' => 204,
            'headers' => ['X-Trace' => "a\r\nb, c", 'X-Tags' => ['a,b', 'c d', 'é'], 'X-Plain' => '42'],
            'body' => null,
            'misuse' => null,
        ]);

        Assert::same($response->getHeaderLine('X-Trace'), 'a%0D%0Ab%2C%20c');
        Assert::same($response->getHeaderLine('X-Tags'), 'a%2Cb,c%20d,%C3%A9');
        Assert::same($response->getHeader('X-Tags'), ['a%2Cb,c%20d,%C3%A9']);
        Assert::same($response->getHeaderLine('X-Plain'), '42');
    }

    public function writesARawBodyVerbatim(): void
    {
        $response = $this->materializer()->materialize(ResponseContracts::pets()->operation('pets.get'), [
            'operationKey' => 'pets.get', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'raw', 'value' => '{"malformed":'],
            'misuse' => ['kind' => 'json-syntax', 'location' => 'body', 'name' => 'body'],
        ]);

        Assert::same((string) $response->getBody(), '{"malformed":');
    }

    public function rejectsANonStringRawBody(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('Raw response body value must be a string');

        $this->materializer()->materialize(ResponseContracts::pets()->operation('pets.get'), [
            'operationKey' => 'pets.get', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'raw', 'value' => ['x']],
            'misuse' => null,
        ]);
    }

    public function rejectsACaseForAnotherOperation(): void
    {
        Expect::exception(\InvalidArgumentException::class)->withMessage('Response case targets "pets.list", not "pets.get"');

        $this->materializer()->materialize(ResponseContracts::pets()->operation('pets.get'), [
            'operationKey' => 'pets.list', 'status' => 200, 'headers' => [], 'body' => null, 'misuse' => null,
        ]);
    }

    public function encodesAMediaTypeMisuseWithTheDeclaredSchema(): void
    {
        $response = $this->materializer()->materialize(ResponseContracts::pets()->operation('pets.get'), [
            'operationKey' => 'pets.get', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/x-openapi-misuse', 'encoding' => 'json', 'value' => ['id' => 1, 'owner' => []]],
            'misuse' => ['kind' => 'media-type', 'location' => 'body', 'name' => 'body'],
        ]);

        Assert::same($response->getHeaderLine('Content-Type'), 'application/x-openapi-misuse');
        Assert::same((string) $response->getBody(), '{"id":1,"owner":{}}');
    }

    public function encodesAnUndeclaredStatusMisuseWithAnyDeclaredJsonSchema(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: [
            '204' => ['description' => 'none'],
            '200' => ['content' => ['text/html' => ['schema' => ['type' => 'string']], 'Application/VND.X+JSON ; v=1' => ['schema' => ['type' => 'object', 'properties' => ['meta' => ['type' => 'object']]]]]],
        ]);
        $response = $this->materializer()->materialize($operation, [
            'operationKey' => 'op', 'status' => 599, 'headers' => [],
            'body' => ['mediaType' => 'Application/VND.X+JSON ; v=1', 'encoding' => 'json', 'value' => ['meta' => []]],
            'misuse' => ['kind' => 'undeclared-status', 'location' => 'status', 'name' => '599'],
        ]);

        Assert::same((string) $response->getBody(), '{"meta":{}}');
    }

    public function encodesWithoutASchemaWhenNoneIsDeclared(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => ['application/json' => []]]]);
        $response = $this->materializer()->materialize($operation, [
            'operationKey' => 'op', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => ['meta' => []]],
            'misuse' => null,
        ]);

        Assert::same((string) $response->getBody(), '{"meta":[]}');

        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['description' => 'no content']]);
        $response = $this->materializer()->materialize($operation, [
            'operationKey' => 'op', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => 1],
            'misuse' => null,
        ]);

        Assert::same((string) $response->getBody(), '1');
    }

    public function rejectsAListSchemaWhileEncoding(): void
    {
        $operation = new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: ['200' => ['content' => ['application/json' => ['schema' => ['a']]]]]);

        Expect::exception(UnsupportedGeneration::class)->withMessage('Response JSON schema must be an object');

        $this->materializer()->materialize($operation, [
            'operationKey' => 'op', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => 'application/json', 'encoding' => 'json', 'value' => 1],
            'misuse' => null,
        ]);
    }

    public function jsonEncoderKeepsEmptyObjectsInsideLists(): void
    {
        Assert::same((new JsonBodyEncoder())->encode([[]], ['type' => 'array', 'items' => ['type' => 'object']]), '[{}]');
        Assert::same((new JsonBodyEncoder())->encode([[]], ['items' => ['type' => 'object']]), '[{}]');
        Assert::same((new JsonBodyEncoder())->encode(['a', 'b'], ['type' => 'object']), '["a","b"]');
        Assert::same((new JsonBodyEncoder())->encode(['a' => [], 'b' => []], ['type' => 'object', 'properties' => ['a' => ['type' => 'object'], 'b' => ['type' => 'object']]]), '{"a":{},"b":{}}');
    }

    /**
     * A key outside `properties` is declared by `additionalProperties`.
     * Dropping that schema encoded a nested empty object as `[]`, which the
     * contract then rejected as a generated-request failure.
     */
    public function jsonEncoderReadsTheAdditionalPropertiesSchema(): void
    {
        $encoder = new JsonBodyEncoder();
        $objectValued = ['type' => 'object', 'additionalProperties' => ['type' => 'object']];

        Assert::same($encoder->encode(['k' => []], $objectValued), '{"k":{}}');
        Assert::same($encoder->encode(['k' => ['n' => []]], ['type' => 'object', 'additionalProperties' => ['type' => 'object', 'properties' => ['n' => ['type' => 'object']]]]), '{"k":{"n":{}}}');
        // A boolean carries no shape, so an empty value stays a list.
        Assert::same($encoder->encode(['k' => []], ['type' => 'object', 'additionalProperties' => true]), '{"k":[]}');
        // A declared property still wins over the additional schema.
        Assert::same(
            $encoder->encode(['k' => []], ['type' => 'object', 'properties' => ['k' => []], 'additionalProperties' => ['type' => 'object']]),
            '{"k":[]}',
        );
    }

    public function jsonEncoderRejectsNonStringObjectKeys(): void
    {
        Expect::exception(UnsupportedGeneration::class)->withMessage('JSON object keys must be strings');

        (new JsonBodyEncoder())->encode([1 => 'x'], ['type' => 'object']);
    }

    private function materializer(): ResponseMaterializer
    {
        $factory = new Psr17Factory();

        return new ResponseMaterializer($factory, $factory);
    }
}
