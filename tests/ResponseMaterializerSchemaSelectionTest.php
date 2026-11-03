<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\OpenApi\Tests;

use Nyholm\Psr7\Factory\Psr17Factory;
use Rasuvaeff\OpenApiContract\Operation;
use Rasuvaeff\PropertyTesting\OpenApi\ResponseMaterializer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Test;

/**
 * Which declared schema encodes a response body. An object member under an
 * object schema is written `{}`, under no schema `[]`, so the body says which
 * declaration was picked.
 */
#[Test]
#[Covers(ResponseMaterializer::class)]
final class ResponseMaterializerSchemaSelectionTest
{
    private const array META = ['schema' => ['type' => 'object', 'properties' => ['meta' => ['type' => 'object']]]];

    public function anObjectHeaderIsWrittenUnexploded(): void
    {
        $operation = $this->operation(['200' => ['description' => 'ok']]);
        $response = $this->materializer()->materialize($operation, [
            'operationKey' => 'op', 'status' => 200, 'headers' => ['X-Obj' => ['a' => '1', 'b' => '2']], 'body' => null, 'misuse' => null,
        ]);

        Assert::same($response->getHeaderLine('X-Obj'), 'a,1,b,2');
    }

    public function aDeclaredMediaTypeKeepsItsOwnSchemaUnderAMediaTypeMisuse(): void
    {
        $body = $this->body($this->operation(['200' => ['content' => [
            'application/json' => ['schema' => []],
            'application/vnd.x+json' => self::META,
        ]]]), 'application/vnd.x+json', 'media-type');

        Assert::same($body, '{"meta":{}}');
    }

    public function aMisuseSkipsAMalformedJsonEntryOfItsOwnStatus(): void
    {
        $body = $this->body($this->operation(['200' => ['content' => [
            'application/json' => 'oops',
            'application/vnd.x+json' => self::META,
        ]]]), 'application/json', 'media-type');

        Assert::same($body, '{"meta":{}}');
    }

    public function aMisusePrefersItsOwnStatusOverTheFirstDeclaredResponse(): void
    {
        $body = $this->body($this->operation([
            '400' => ['content' => ['application/json' => ['schema' => []]]],
            '200' => ['content' => ['application/json' => self::META]],
        ]), 'application/x-misuse', 'media-type');

        Assert::same($body, '{"meta":{}}');
    }

    public function aMisuseFallsBackPastContentThatIsNotAnObject(): void
    {
        $body = $this->body($this->operation([
            '200' => ['content' => 'oops'],
            '400' => ['content' => ['application/json' => self::META]],
        ]), 'application/json', 'media-type');

        Assert::same($body, '{"meta":{}}');
    }

    /** @param non-empty-string $kind */
    private function body(Operation $operation, string $mediaType, string $kind): string
    {
        return (string) $this->materializer()->materialize($operation, [
            'operationKey' => 'op', 'status' => 200, 'headers' => [],
            'body' => ['mediaType' => $mediaType, 'encoding' => 'json', 'value' => ['meta' => []]],
            'misuse' => ['kind' => $kind, 'location' => 'body', 'name' => 'body'],
        ])->getBody();
    }

    /** @param array<array-key, mixed> $responses */
    private function operation(array $responses): Operation
    {
        return new Operation(key: 'op', operationId: 'op', method: 'GET', path: '/op', responses: $responses);
    }

    private function materializer(): ResponseMaterializer
    {
        $factory = new Psr17Factory();

        return new ResponseMaterializer($factory, $factory);
    }
}
